<?php

namespace Tests\Feature\Keuangan;

use App\Models\Role;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\MemakaiDatabaseSji;
use Tests\TestCase;

/**
 * Generate Payroll, PPh 21, dan pencairan (sql/Komponen/16_payroll_pph21_pencairan.sql) pada db_sji.
 * Periode uji tahun 2099 agar tidak bersinggungan dengan data asli; payroll periode itu dihapus di tearDown.
 *
 * Lembur: gaji kontrak 3.460.000 / 25 hari, penempatan sebulan penuh -> gaji pokok 3.460.000,
 * upah per jam = 3.460.000 / 173 = 20.000.
 */
class PayrollPph21PencairanTest extends TestCase
{
    use MemakaiDatabaseSji;

    private const int TAHUN = 2099;

    /** @var list<int> karyawan yang punya data turunan (komponen gaji / jadwal) untuk dibersihkan. */
    private array $karyawanTurunan = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpDatabaseSji();

        foreach ([3, 4, 12] as $bulan) {
            $this->catatPeriodeUji($bulan, self::TAHUN);
        }
    }

    protected function tearDown(): void
    {
        // Procedure payroll meng-commit transaksi test: hapus data turunan sebelum karyawan/penempatan dihapus.
        if ($this->karyawanTurunan !== []) {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            $penempatan = DB::table('tbl_penempatan')->whereIn('id_karyawan', $this->karyawanTurunan)->pluck('id');
            DB::table('tbl_pertukaran_shift')->whereIn('id_karyawan_pengaju', $this->karyawanTurunan)->delete();
            DB::table('tbl_jadwal_kerja')->whereIn('id_penempatan', $penempatan)->delete();
            DB::table('tbl_komponen_gaji')->whereIn('id_karyawan', $this->karyawanTurunan)->delete();
        }

        $this->tearDownDatabaseSji();
        parent::tearDown();
    }

    public function test_lembur_dihitung_jam_penuh_setelah_60_menit_termasuk_shift_lintas_tengah_malam(): void
    {
        $klien = $this->buatKlien();
        $pagi = $this->buatKaryawan();
        $this->buatPenempatan($pagi, $klien, '2099-03-01', null, ['jam_datang' => '08:00:00', 'jam_pulang' => '16:00:00'], 3460000, 25);
        $malam = $this->buatKaryawan();
        $this->buatPenempatan($malam, $klien, '2099-03-01', null, ['jam_datang' => '22:00:00', 'jam_pulang' => '06:00:00'], 3460000, 25);

        $this->checkInOut($pagi, '2099-03-02', '08:00:00', '16:45:00'); // 45 menit  -> 0 jam
        $this->checkInOut($pagi, '2099-03-03', '08:00:00', '17:00:00'); // tepat 1 jam -> 1 jam (1,5x)
        $this->checkInOut($pagi, '2099-03-04', '08:00:00', '17:20:00'); // 1 jam 20   -> 1 jam (1,5x)
        $this->checkInOut($pagi, '2099-03-05', '08:00:00', '18:10:00'); // 2 jam 10   -> 2 jam (1,5x + 2x)
        $this->checkInOut($pagi, '2099-03-06', '08:00:00', '19:59:00'); // 3 jam 59   -> 3 jam (1,5x + 2x + 2x)
        $this->buatAbsensi($pagi, '2099-03-07', 'Hadir', ['jam_masuk' => '08:00:00', 'jam_lembur' => 3]); // tanpa check-out
        $this->checkInOut($malam, '2099-03-09', '22:00:00', '07:30:00'); // shift 22-06, keluar 07:30 besok -> 1 jam

        $this->generate(3);

        // (1,5 + 1,5 + 3,5 + 5,5) x 20.000 = 240.000
        $this->assertEquals(240000, (float) $this->payroll($pagi, 3)->total_lembur);
        $this->assertEquals(30000, (float) $this->payroll($malam, 3)->total_lembur);
        $this->assertEquals(0, (float) $this->payroll($pagi, 3)->upah_backup);
    }

    public function test_tukar_shift_barter_yang_disetujui_tidak_menghasilkan_upah_backup(): void
    {
        $klien = $this->buatKlien();
        $pengaju = $this->buatKaryawan();
        $rekan = $this->buatKaryawan();
        $penempatanPengaju = $this->buatPenempatan($pengaju, $klien, '2099-03-01', null, [], 3460000, 25);
        $penempatanRekan = $this->buatPenempatan($rekan, $klien, '2099-03-01', null, [], 3460000, 25);
        $this->karyawanTurunan = [$pengaju, $rekan];

        $jadwalPengaju = $this->jadwal($penempatanPengaju, '2099-03-10', '16:00:00', '00:00:00');
        $jadwalRekan = $this->jadwal($penempatanRekan, '2099-03-10', '08:00:00', '16:00:00');
        DB::table('tbl_pertukaran_shift')->insert([
            'id_karyawan_pengaju' => $pengaju, 'id_karyawan_rekan' => $rekan,
            'id_jadwal_pengaju' => $jadwalPengaju, 'id_jadwal_rekan' => $jadwalRekan,
            'tanggal_kerja' => '2099-03-10', 'tanggal_kerja_rekan' => '2099-03-10',
            'snapshot_pengaju' => '{}', 'snapshot_rekan' => '{}', 'alasan' => 'Uji barter',
            'status' => 'Disetujui', 'status_rekan' => 'Diterima',
            'diproses_oleh' => $this->loginSebagai(Role::Operasional)->id, 'diproses_pada' => now(),
        ]);
        $this->checkInOut($pengaju, '2099-03-10', '08:00:00', '16:00:00');
        $this->checkInOut($rekan, '2099-03-10', '16:00:00', '23:55:00');

        $this->generate(3);

        $this->assertEquals(0, (float) $this->payroll($pengaju, 3)->upah_backup);
        $this->assertEquals(0, (float) $this->payroll($rekan, 3)->upah_backup);
    }

    public function test_pph21_ter_kategori_a_tunjangan_dan_pinjaman_dari_komponen_gaji(): void
    {
        $karyawan = $this->buatKaryawan('Internal', ['gaji_pokok' => 10000000, 'status' => 'Aktif']);
        $dibawahPtkp = $this->buatKaryawan('Internal', ['gaji_pokok' => 5000000, 'status' => 'Aktif']);
        $this->karyawanTurunan = [$karyawan];
        DB::table('tbl_komponen_gaji')->insert([
            'id_karyawan' => $karyawan, 'periode_bulan' => 4, 'periode_tahun' => self::TAHUN,
            'tunjangan' => 500000, 'potongan_pinjaman' => 250000, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->generate(4);

        $payroll = $this->payroll($karyawan, 4);
        // Bruto 10.500.000 -> TER A 2,5% = 262.500
        $this->assertEquals(500000, (float) $payroll->komponen_tunjangan);
        $this->assertEquals(250000, (float) $payroll->potongan_pinjaman);
        $this->assertEquals(262500, (float) $payroll->pph21);
        $this->assertEquals(10000000 + 500000 - 250000 - 262500, (float) $payroll->total_gaji);
        // Bruto 5.000.000 (<= 5.400.000) -> TER 0%
        $this->assertEquals(0, (float) $this->payroll($dibawahPtkp, 4)->pph21);
    }

    public function test_pph21_desember_dihitung_ulang_setahun_dengan_ptkp_k0_dan_tk0(): void
    {
        $laki = $this->buatKaryawan('Internal', ['gaji_pokok' => 10000000, 'status' => 'Aktif', 'status_pernikahan' => 'Menikah', 'jenis_kelamin' => 'L']);
        $perempuan = $this->buatKaryawan('Internal', ['gaji_pokok' => 10000000, 'status' => 'Aktif', 'status_pernikahan' => 'Menikah', 'jenis_kelamin' => 'P']);

        foreach ([$laki, $perempuan] as $id) {
            for ($bulan = 1; $bulan <= 11; $bulan++) {
                DB::table('tbl_payroll')->insert([
                    'id_karyawan' => $id, 'periode_bulan' => $bulan, 'periode_tahun' => self::TAHUN,
                    'gaji_pokok' => 10000000, 'total_lembur' => 0, 'potongan_absen' => 0,
                    'pph21' => 200000, 'total_gaji' => 9800000,
                ]);
            }
        }

        $this->generate(12);

        // Bruto setahun 120.000.000 - biaya jabatan 6.000.000 = neto 114.000.000.
        // K/0  : PKP 55.500.000 -> 5% = 2.775.000 - 2.200.000 (Jan-Nov) = 575.000
        // TK/0 : PKP 60.000.000 -> 5% = 3.000.000 - 2.200.000 (Jan-Nov) = 800.000
        $this->assertEquals(575000, (float) $this->payroll($laki, 12)->pph21);
        $this->assertEquals(800000, (float) $this->payroll($perempuan, 12)->pph21);
        $this->assertEquals(10000000 - 575000, (float) $this->payroll($laki, 12)->total_gaji);
    }

    public function test_generate_berulang_tidak_menggandakan_atau_menimpa_payroll_dan_menampilkan_jumlah(): void
    {
        $karyawan = $this->buatKaryawan('Internal', ['gaji_pokok' => 10000000, 'status' => 'Aktif']);
        $this->loginSebagai(Role::Keuangan);
        // Periode uji April 2099 baru boleh di-generate setelah periode itu berjalan (pindah waktu setelah akun uji
        // dibuat, karena tbl_users.created_at bertipe TIMESTAMP).
        $this->travelTo(now()->setDate(self::TAHUN, 5, 1));

        $this->post(route('keuangan.payroll.generate'), ['bulan' => 4, 'tahun' => self::TAHUN])
            ->assertSessionHas('success', fn (string $pesan): bool => str_contains($pesan, 'dilewati karena sudah ada') && ! str_contains($pesan, ': 0 payroll baru'));
        $payroll = $this->payroll($karyawan, 4);
        $this->patch(route('keuangan.payroll.cair', $payroll->id))->assertSessionHas('success');
        DB::table('tbl_komponen_gaji')->insert([
            'id_karyawan' => $karyawan, 'periode_bulan' => 4, 'periode_tahun' => self::TAHUN,
            'tunjangan' => 999999, 'potongan_pinjaman' => 0, 'created_at' => '2038-01-01 00:00:00', 'updated_at' => '2038-01-01 00:00:00',
        ]);
        $this->karyawanTurunan = [$karyawan];
        $jumlah = DB::table('tbl_payroll')->where('periode_bulan', 4)->where('periode_tahun', self::TAHUN)->count();

        $this->post(route('keuangan.payroll.generate'), ['bulan' => 4, 'tahun' => self::TAHUN])
            ->assertSessionHas('success', 'Generate payroll April 2099 selesai: 0 payroll baru dibuat, '.$jumlah.' dilewati karena sudah ada.');

        $setelah = $this->payroll($karyawan, 4);
        $this->assertSame(1, DB::table('tbl_payroll')->where('id_karyawan', $karyawan)->where('periode_bulan', 4)->where('periode_tahun', self::TAHUN)->count());
        $this->assertSame(['Sudah Lunas', 0.0, (float) $payroll->total_gaji], [$setelah->status_bayar, (float) $setelah->komponen_tunjangan, (float) $setelah->total_gaji]);

        $this->post(route('keuangan.payroll.generate'), ['bulan' => 13, 'tahun' => self::TAHUN])->assertSessionHasErrors('bulan');
    }

    public function test_payroll_dan_invoice_periode_yang_belum_berjalan_ditolak(): void
    {
        $klien = $this->buatKlien(['management_fee' => 5]);
        $this->loginSebagai(Role::Keuangan);
        $this->travelTo(now()->setDate(self::TAHUN, 3, 15));

        $this->post(route('keuangan.payroll.generate'), ['bulan' => 4, 'tahun' => self::TAHUN])
            ->assertSessionHasErrors(['bulan' => 'Periode April 2099 belum berjalan. Payroll hanya dapat dibuat sampai periode Maret 2099.']);
        $this->post(route('keuangan.invoice.generate'), ['id_klien' => $klien, 'bulan' => 1, 'tahun' => self::TAHUN + 1])
            ->assertSessionHasErrors(['bulan' => 'Periode Januari 2100 belum berjalan. Invoice hanya dapat dibuat sampai periode Maret 2099.']);

        $this->assertSame(0, DB::table('tbl_payroll')->where('periode_bulan', 4)->where('periode_tahun', self::TAHUN)->count());
        $this->assertFalse(DB::table('tbl_invoice')->where('id_klien', $klien)->exists());
        $this->get(route('keuangan.payroll.index', ['bulan' => 4, 'tahun' => self::TAHUN]))
            ->assertOk()->assertSee('Periode April 2099 belum berjalan.');
    }

    public function test_cair_mengubah_status_sudah_lunas_sekali_tanpa_mutasi_kas(): void
    {
        $karyawan = $this->buatKaryawan('Internal', ['gaji_pokok' => 10000000, 'status' => 'Aktif']);
        $this->generate(4);
        $payroll = $this->payroll($karyawan, 4);
        $this->assertSame('Belum Dibayar', $payroll->status_bayar);
        $this->loginSebagai(Role::Keuangan);

        $this->get(route('keuangan.payroll.index', ['bulan' => 4, 'tahun' => self::TAHUN]))
            ->assertOk()->assertSee('>Cair</button>', false);

        $this->patch(route('keuangan.payroll.cair', $payroll->id))
            ->assertSessionHas('success', 'Gaji berhasil dicairkan. Status payroll: Sudah Lunas.');

        $dicairkan = $this->payroll($karyawan, 4);
        $this->assertSame(['Sudah Lunas', $this->hariIniDb()], [$dicairkan->status_bayar, $dicairkan->tgl_bayar]);
        $this->assertFalse(DB::table('tbl_mutasi_keuangan')->where('referensi', 'PAYROLL-'.$payroll->id)->exists());

        DB::table('tbl_payroll')->where('id', $payroll->id)->update(['tgl_bayar' => '2099-04-30']);
        $this->patch(route('keuangan.payroll.cair', $payroll->id))
            ->assertSessionHas('error', 'Operasi ditolak oleh aturan database: Payroll ini sudah dicairkan sebelumnya');
        $this->assertSame('2099-04-30', $this->payroll($karyawan, 4)->tgl_bayar, 'Pencairan kedua tidak mengubah tanggal.');

        $halaman = $this->get(route('keuangan.payroll.index', ['bulan' => 4, 'tahun' => self::TAHUN]))->assertOk();
        $this->assertStringContainsString('badge badge-success">Sudah Lunas', $halaman->getContent());
    }

    public function test_rincian_gaji_dapat_dilihat_dari_data_tersimpan_tetapi_tidak_dapat_dicetak(): void
    {
        $karyawan = $this->buatKaryawan('Internal', ['gaji_pokok' => 10000000, 'status' => 'Aktif']);
        $this->generate(4);
        $payroll = $this->payroll($karyawan, 4);
        $this->loginSebagai(Role::Keuangan);

        $this->get(route('keuangan.payroll.index', ['bulan' => 4, 'tahun' => self::TAHUN]))
            ->assertOk()
            ->assertSee('Rincian Gaji')
            ->assertDontSee('Unduh PDF')
            ->assertDontSee('data-struk-cetak', false);

        // Isi rincian = nilai tersimpan (bruto 10.000.000 -> TER A 2% = 200.000), tanpa hitung ulang.
        $this->withHeader('X-Requested-With', 'XMLHttpRequest')
            ->get(route('keuangan.payroll.struk', $payroll->id))
            ->assertOk()
            ->assertSeeInOrder(['RINCIAN GAJI', 'Gaji Pokok', 'Rp 10.000.000', 'Tunjangan', 'Upah Back-up', 'Upah Lembur',
                'Potongan Absensi', 'Potongan BPJS', 'Potongan Pinjaman', 'PPh 21', 'Rp 200.000', 'Total Gaji Bersih', 'Rp 9.800.000'], false)
            ->assertDontSee('Dicetak');
        $this->assertEquals(9800000, (float) $payroll->total_gaji);

        // Tidak ada lagi slip PDF: permintaan PDF hanya diarahkan kembali ke halaman payroll.
        $this->flushHeaders()->get(route('keuangan.payroll.struk', $payroll->id).'?format=pdf')
            ->assertRedirect(route('keuangan.payroll.index', ['struk' => $payroll->id]));
    }

    private function generate(int $bulan): void
    {
        DB::statement('CALL sp_generate_payroll_bulanan(?, ?)', [$bulan, self::TAHUN]);
    }

    private function payroll(int $idKaryawan, int $bulan): object
    {
        return DB::table('tbl_payroll')->where('id_karyawan', $idKaryawan)
            ->where('periode_bulan', $bulan)->where('periode_tahun', self::TAHUN)->sole();
    }

    /** Check-in lalu check-out (UPDATE) agar trigger lembur berjalan seperti aplikasi. */
    private function checkInOut(int $idKaryawan, string $tanggal, string $masuk, string $keluar): void
    {
        $id = $this->buatAbsensi($idKaryawan, $tanggal, 'Hadir', ['jam_masuk' => $masuk]);
        DB::table('tbl_absensi')->where('id', $id)->update(['jam_keluar' => $keluar]);
    }

    private function jadwal(int $idPenempatan, string $tanggal, string $masuk, string $pulang): int
    {
        return DB::table('tbl_jadwal_kerja')->insertGetId([
            'id_penempatan' => $idPenempatan, 'tanggal_kerja' => $tanggal, 'status' => 'Kerja',
            'jam_masuk' => $masuk, 'jam_pulang' => $pulang, 'durasi_menit' => 480,
        ]);
    }
}
