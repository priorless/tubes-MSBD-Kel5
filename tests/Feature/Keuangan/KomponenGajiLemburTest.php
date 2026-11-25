<?php

namespace Tests\Feature\Keuangan;

use App\Models\Role;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\MemakaiDatabaseSji;
use Tests\TestCase;

/**
 * Upah lembur & BPJS halaman Komponen Gaji pada db_sji: jam lembur berasal dari trigger check-out
 * (trg_auto_hitung_lembur, jam pulang dari fn_jadwal_absensi).
 * Periode uji Maret 2099 agar tidak bersinggungan dengan data asli.
 *
 * Kontrak uji: gaji 4.000.000 / 25 hari kerja, penempatan sebulan penuh -> gaji pokok 4.000.000,
 * upah per jam = 4.000.000 / 173.
 */
class KomponenGajiLemburTest extends TestCase
{
    use MemakaiDatabaseSji;

    private const int BULAN = 3;

    private const int TAHUN = 2099;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpDatabaseSji();
    }

    protected function tearDown(): void
    {
        $this->tearDownDatabaseSji();
        parent::tearDown();
    }

    public function test_check_out_59_menit_setelah_shift_tidak_lembur_dan_60_menit_dihitung_satu_jam(): void
    {
        $klien = $this->buatKlien(['bpjs_metode' => 'Persentase', 'bpjs_nilai' => 4]);
        $karyawan = $this->buatKaryawan();
        $this->buatPenempatan($karyawan, $klien, '2099-03-01', null, ['jam_datang' => '08:00:00', 'jam_pulang' => '16:00:00'], 4000000, 25);

        $absen59 = $this->checkInCheckOut($karyawan, '2099-03-02', '08:00:00', '16:59:00');
        $absen60 = $this->checkInCheckOut($karyawan, '2099-03-03', '08:00:00', '17:00:00');
        $absen2Jam = $this->checkInCheckOut($karyawan, '2099-03-04', '08:00:00', '18:30:00');

        $this->assertSame(0, $this->jamLembur($absen59), 'Lewat 59 menit belum 1 jam penuh.');
        $this->assertSame(1, $this->jamLembur($absen60), 'Lewat tepat 60 menit = 1 jam lembur.');
        $this->assertSame(2, $this->jamLembur($absen2Jam), 'Lewat 150 menit = 2 jam penuh (tidak dibulatkan ke atas).');

        // 4.000.000 / 173 x (1,5 + (1,5 + 2)) = 115.606,94; BPJS 4% x 4.000.000 = 160.000
        $this->halaman($klien)->assertOk()
            ->assertSee('Rp 115.607')
            ->assertSee('3 jam lembur')
            ->assertSee('Rp 160.000')
            ->assertSee('4% dari gaji');

        // Generate Payroll menghasilkan angka yang sama dengan halaman Komponen Gaji.
        $this->catatPeriodeUji(self::BULAN, self::TAHUN);
        DB::statement('CALL sp_generate_payroll_bulanan(?, ?)', [self::BULAN, self::TAHUN]);
        $payroll = DB::table('tbl_payroll')->where('id_karyawan', $karyawan)
            ->where('periode_bulan', self::BULAN)->where('periode_tahun', self::TAHUN)->sole();
        $this->assertEquals(4000000, (float) $payroll->gaji_pokok);
        $this->assertEquals(115606.94, (float) $payroll->total_lembur);
        $this->assertEquals(160000, (float) $payroll->potongan_bpjs);
        $this->assertSame('Persentase', $payroll->bpjs_metode);
    }

    public function test_shift_lintas_tengah_malam_memakai_jam_selesai_shift_keesokan_hari(): void
    {
        $klien = $this->buatKlien();
        $karyawan = $this->buatKaryawan();
        $this->buatPenempatan($karyawan, $klien, '2099-03-01', null, ['jam_datang' => '22:00:00', 'jam_pulang' => '06:00:00'], 4000000, 25);

        $tepatWaktu = $this->checkInCheckOut($karyawan, '2099-03-05', '22:00:00', '06:40:00');
        $lembur = $this->checkInCheckOut($karyawan, '2099-03-06', '22:00:00', '07:05:00');

        $this->assertSame(0, $this->jamLembur($tepatWaktu));
        $this->assertSame(1, $this->jamLembur($lembur));

        // 4.000.000 / 173 x 1,5 = 34.682,08; klien tanpa pengaturan BPJS -> tidak dikarang.
        $this->halaman($klien)->assertOk()
            ->assertSee('Rp 34.682')
            ->assertSee('1 jam lembur')
            ->assertSee('BPJS belum diatur pada data klien');
    }

    /** Check-in lalu check-out (UPDATE) agar trigger lembur berjalan seperti aplikasi. */
    private function checkInCheckOut(int $idKaryawan, string $tanggal, string $masuk, string $keluar): int
    {
        $id = $this->buatAbsensi($idKaryawan, $tanggal, 'Hadir', ['jam_masuk' => $masuk]);
        DB::table('tbl_absensi')->where('id', $id)->update(['jam_keluar' => $keluar]);

        return $id;
    }

    private function jamLembur(int $idAbsensi): int
    {
        return (int) DB::table('tbl_absensi')->where('id', $idAbsensi)->value('jam_lembur');
    }

    private function halaman(int $idKlien)
    {
        $this->loginSebagai(Role::Keuangan);

        return $this->get(route('keuangan.komponen-gaji.index', [
            'bulan' => self::BULAN, 'tahun' => self::TAHUN, 'penempatan' => 'klien:'.$idKlien,
        ]));
    }
}
