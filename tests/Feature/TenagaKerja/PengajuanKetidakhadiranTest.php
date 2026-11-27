<?php

namespace Tests\Feature\TenagaKerja;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\MemakaiDatabaseSji;
use Tests\TestCase;

/**
 * Pengajuan Izin/Sakit/Cuti oleh karyawan pada db_sji: trigger trg_pengajuan_ketidakhadiran_insert/_update,
 * procedure sp_setujui_pengajuan_ketidakhadiran (absensi per hari kerja, hari Libur dilewati), dan payroll Cuti.
 */
class PengajuanKetidakhadiranTest extends TestCase
{
    use MemakaiDatabaseSji;

    private int $idKaryawan;

    private int $idPenempatan;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpDatabaseSji();

        $this->idKaryawan = $this->buatKaryawan('Lapangan');
        $this->idPenempatan = $this->buatPenempatan($this->idKaryawan, $this->buatKlien(), $this->hariIniDb(-30));
    }

    protected function tearDown(): void
    {
        $this->tearDownDatabaseSji();
        parent::tearDown();
    }

    public function test_karyawan_mengajukan_dan_tanggal_yang_bertabrakan_ditolak_trigger(): void
    {
        $karyawan = $this->akunKaryawan($this->idKaryawan);
        $data = ['jenis' => 'Cuti', 'tgl_mulai' => $this->hariIniDb(10), 'tgl_selesai' => $this->hariIniDb(12), 'alasan' => 'Acara keluarga'];

        $this->actingAs($karyawan)->post(route('tenaga-kerja.ketidakhadiran.store'), $data)
            ->assertRedirect(route('tenaga-kerja.ketidakhadiran.index'));
        $this->assertDatabaseHas('tbl_pengajuan_ketidakhadiran', ['id_karyawan' => $this->idKaryawan, 'jenis' => 'Cuti', 'status' => 'Menunggu']);

        $this->actingAs($karyawan)->post(route('tenaga-kerja.ketidakhadiran.store'), [...$data, 'tgl_mulai' => $this->hariIniDb(12), 'tgl_selesai' => $this->hariIniDb(13)])
            ->assertSessionHasErrors(['tgl_mulai' => 'Rentang tanggal bertabrakan dengan pengajuan lain yang mulai '.date('d-m-Y', strtotime($this->hariIniDb(10))).' (Menunggu/Disetujui).']);
        $this->assertSame(1, DB::table('tbl_pengajuan_ketidakhadiran')->where('id_karyawan', $this->idKaryawan)->count());
    }

    public function test_hrd_menyetujui_mencatat_absensi_hari_kerja_dan_melewati_libur(): void
    {
        $this->jadwal($this->hariIniDb(10), 'Kerja');
        $this->jadwal($this->hariIniDb(11), 'Libur');
        $this->jadwal($this->hariIniDb(12), 'Kerja');
        $id = $this->pengajuan('Cuti', $this->hariIniDb(10), $this->hariIniDb(12));
        $hrd = $this->loginSebagai(Role::Hrd);

        $this->patch(route('hrd.ketidakhadiran.update', $id), ['keputusan' => 'Disetujui'])
            ->assertRedirect(route('hrd.ketidakhadiran.index'));

        $this->assertSame(
            [$this->hariIniDb(10) => 'Cuti', $this->hariIniDb(12) => 'Cuti'],
            DB::table('tbl_absensi')->where('id_karyawan', $this->idKaryawan)->orderBy('tgl')->pluck('status_kehadiran', 'tgl')->all()
        );
        $this->assertDatabaseHas('tbl_absensi', ['id_karyawan' => $this->idKaryawan, 'id_penempatan' => $this->idPenempatan, 'tgl' => $this->hariIniDb(10), 'jam_masuk' => null]);
        $this->assertDatabaseHas('tbl_pengajuan_ketidakhadiran', ['id' => $id, 'status' => 'Disetujui', 'diproses_oleh' => $hrd->id]);
    }

    public function test_persetujuan_ditolak_tanpa_menulis_apa_pun_bila_satu_tanggal_sudah_ada_absensi(): void
    {
        $id = $this->pengajuan('Sakit', $this->hariIniDb(-3), $this->hariIniDb(-1));
        $this->buatAbsensi($this->idKaryawan, $this->hariIniDb(-2), 'Hadir', ['id_penempatan' => $this->idPenempatan, 'jam_masuk' => '08:00:00']);
        $this->loginSebagai(Role::Hrd);

        $this->patch(route('hrd.ketidakhadiran.update', $id), ['keputusan' => 'Disetujui'])
            ->assertSessionHasErrors('keputusan');

        $this->assertSame(1, DB::table('tbl_absensi')->where('id_karyawan', $this->idKaryawan)->count(), 'Tanggal lain tidak ikut tercatat');
        $this->assertDatabaseHas('tbl_pengajuan_ketidakhadiran', ['id' => $id, 'status' => 'Menunggu']);
    }

    public function test_status_disetujui_tidak_bisa_diubah_langsung_tanpa_procedure(): void
    {
        $id = $this->pengajuan('Izin', $this->hariIniDb(5), $this->hariIniDb(5));

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/hanya dapat dilakukan melalui sp_setujui_pengajuan_ketidakhadiran/');

        DB::table('tbl_pengajuan_ketidakhadiran')->where('id', $id)->update([
            'status' => 'Disetujui', 'diproses_oleh' => $this->loginSebagai(Role::Hrd)->id, 'diproses_pada' => now(),
        ]);
    }

    public function test_hrd_menolak_wajib_dengan_alasan(): void
    {
        $id = $this->pengajuan('Izin', $this->hariIniDb(5), $this->hariIniDb(5));
        $this->loginSebagai(Role::Hrd);

        $this->patch(route('hrd.ketidakhadiran.update', $id), ['keputusan' => 'Ditolak'])->assertSessionHasErrors('catatan_keputusan');
        $this->patch(route('hrd.ketidakhadiran.update', $id), ['keputusan' => 'Ditolak', 'catatan_keputusan' => 'Jadwal padat']);

        $this->assertDatabaseHas('tbl_pengajuan_ketidakhadiran', ['id' => $id, 'status' => 'Ditolak', 'catatan_keputusan' => 'Jadwal padat']);
        $this->assertSame(0, DB::table('tbl_absensi')->where('id_karyawan', $this->idKaryawan)->count());
    }

    public function test_karyawan_hanya_dapat_membatalkan_pengajuan_miliknya(): void
    {
        $id = $this->pengajuan('Izin', $this->hariIniDb(5), $this->hariIniDb(5));
        $orangLain = $this->akunKaryawan($this->buatKaryawan('Lapangan'));

        $this->actingAs($orangLain)->patch(route('tenaga-kerja.ketidakhadiran.batalkan', $id))->assertSessionHasErrors('pengajuan');
        $this->assertDatabaseHas('tbl_pengajuan_ketidakhadiran', ['id' => $id, 'status' => 'Menunggu']);

        $this->actingAs($this->akunKaryawan($this->idKaryawan))->patch(route('tenaga-kerja.ketidakhadiran.batalkan', $id))
            ->assertRedirect(route('tenaga-kerja.ketidakhadiran.index'));
        $this->assertDatabaseHas('tbl_pengajuan_ketidakhadiran', ['id' => $id, 'status' => 'Dibatalkan', 'diproses_oleh' => null]);
    }

    public function test_daftar_pengajuan_tampil_untuk_pemilik_dan_hrd_saja(): void
    {
        $this->pengajuan('Sakit', $this->hariIniDb(-1), $this->hariIniDb(-1));
        $nama = DB::table('tbl_karyawan')->where('id', $this->idKaryawan)->value('nama');

        $this->actingAs($this->akunKaryawan($this->idKaryawan))->get(route('tenaga-kerja.ketidakhadiran.index'))
            ->assertOk()->assertSee('Uji Sakit')->assertSee('Batalkan');
        $this->actingAs($this->akunKaryawan($this->buatKaryawan('Lapangan')))->get(route('tenaga-kerja.ketidakhadiran.index'))
            ->assertOk()->assertDontSee('Uji Sakit');

        $this->loginSebagai(Role::Hrd);
        $this->get(route('hrd.ketidakhadiran.index', ['q' => $nama]))->assertOk()->assertSee('Uji Sakit')->assertSee('Setujui');
    }

    public function test_halaman_absensi_menampilkan_cuti_hari_ini_bukan_sedang_bekerja(): void
    {
        $this->buatAbsensi($this->idKaryawan, $this->hariIniDb(), 'Cuti', ['id_penempatan' => $this->idPenempatan]);

        $this->actingAs($this->akunKaryawan($this->idKaryawan))
            ->get(route('tenaga-kerja.absensi'))
            ->assertOk()
            ->assertSee('data-status="selesai"', false)
            ->assertSee('Hari ini Anda tercatat Cuti');
    }

    public function test_payroll_menghitung_cuti_sebagai_hari_masuk_dan_izin_tidak(): void
    {
        $this->catatPeriodeUji(3, 2099);
        $idIzin = $this->buatKaryawan('Lapangan');
        $penempatanCuti = $this->buatPenempatan($this->idKaryawan, $this->buatKlien(), '2099-03-01');
        $penempatanIzin = $this->buatPenempatan($idIzin, $this->buatKlien(), '2099-03-01');
        foreach ([$penempatanCuti => 'Cuti', $penempatanIzin => 'Izin'] as $penempatan => $status) {
            $idKaryawan = (int) DB::table('tbl_penempatan')->where('id', $penempatan)->value('id_karyawan');
            foreach (['2099-03-02', '2099-03-03'] as $tgl) {
                $this->jadwal($tgl, 'Kerja', $penempatan);
                $this->buatAbsensi($idKaryawan, $tgl, $status, ['id_penempatan' => $penempatan]);
            }
        }

        DB::statement('CALL sp_generate_payroll_bulanan(3, 2099)');

        $hariMasuk = DB::table('tbl_payroll')->where('periode_bulan', 3)->where('periode_tahun', 2099)
            ->whereIn('id_karyawan', [$this->idKaryawan, $idIzin])->pluck('hari_masuk', 'id_karyawan');
        $this->assertSame(2, (int) $hariMasuk[$this->idKaryawan], 'Cuti dibayar seperti hari masuk');
        $this->assertSame(0, (int) $hariMasuk[$idIzin], 'Izin tetap dihitung tidak masuk');
    }

    private function pengajuan(string $jenis, string $mulai, string $selesai): int
    {
        return (int) DB::table('tbl_pengajuan_ketidakhadiran')->insertGetId([
            'id_karyawan' => $this->idKaryawan, 'jenis' => $jenis, 'tgl_mulai' => $mulai, 'tgl_selesai' => $selesai,
            'alasan' => 'Uji '.$jenis, 'status' => 'Menunggu', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function jadwal(string $tanggal, string $status, ?int $idPenempatan = null): void
    {
        DB::table('tbl_jadwal_kerja')->insert([
            'id_penempatan' => $idPenempatan ?? $this->idPenempatan,
            'tanggal_kerja' => $tanggal,
            'status' => $status,
            'jam_masuk' => $status === 'Kerja' ? '08:00:00' : null,
            'jam_pulang' => $status === 'Kerja' ? '16:00:00' : null,
            'durasi_menit' => $status === 'Kerja' ? 480 : null,
        ]);
    }

    /** Akun login karyawan lapangan (dibuat trigger trg_auto_akun_karyawan) yang sudah ganti password. */
    private function akunKaryawan(int $idKaryawan): User
    {
        $user = User::query()->where('id_karyawan', $idKaryawan)->sole();
        $user->forceFill(['must_change_password' => false])->save();

        return $user;
    }
}
