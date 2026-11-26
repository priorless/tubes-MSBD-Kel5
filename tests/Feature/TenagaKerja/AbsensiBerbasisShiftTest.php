<?php

namespace Tests\Feature\TenagaKerja;

use Illuminate\Support\Facades\Storage;
use Tests\Concerns\MemakaiAbsensiTenagaKerja;
use Tests\TestCase;

/**
 * Hak check-in/check-out berdasarkan shift dari Operasional (JadwalAbsensiService + trigger database),
 * dengan waktu server dibekukan. db_sji dalam transaksi yang di-rollback.
 */
class AbsensiBerbasisShiftTest extends TestCase
{
    use MemakaiAbsensiTenagaKerja;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAbsensiTenagaKerja();
        Storage::fake('local');
    }

    protected function tearDown(): void
    {
        $this->tearDownDatabaseSji();
        parent::tearDown();
    }

    public function test_karyawan_tanpa_penempatan_tidak_dapat_check_in(): void
    {
        $idKaryawan = $this->buatKaryawan('Lapangan');
        $this->waktuServer('07:45:00');

        $this->kirimCheckIn($idKaryawan)
            ->assertStatus(422)
            ->assertJson(['ok' => false, 'pesan' => 'Anda belum memiliki penempatan aktif. Absensi dapat dilakukan setelah ditempatkan oleh bagian Operasional.']);

        $this->assertNull($this->absensiKaryawan($idKaryawan));
    }

    public function test_hari_libur_menurut_jadwal_tidak_dapat_check_in(): void
    {
        [$idKaryawan, $idPenempatan] = $this->karyawanLayanan('Satpam');
        $this->buatJadwalHarian($idPenempatan, $this->hariIniDb(), 'Libur');
        $this->waktuServer('07:45:00');

        $this->kirimCheckIn($idKaryawan)
            ->assertStatus(422)
            ->assertJson(['pesan' => 'Hari ini adalah hari libur Anda sesuai jadwal dari Operasional. Absensi tidak dapat dilakukan.']);

        $this->assertNull($this->absensiKaryawan($idKaryawan));
    }

    public function test_tanpa_jadwal_harian_maupun_jam_penempatan_tidak_dapat_check_in(): void
    {
        [$idKaryawan] = $this->karyawanLayanan('Satpam');
        \DB::table('tbl_penempatan')->where('id_karyawan', $idKaryawan)->update(['jam_datang' => null, 'jam_pulang' => null]);
        $this->waktuServer('07:45:00');

        $this->kirimCheckIn($idKaryawan)
            ->assertStatus(422)
            ->assertJson(['pesan' => 'Jadwal shift Anda hari ini belum ditentukan oleh Operasional. Absensi tidak dapat dilakukan tanpa jadwal.']);
    }

    public function test_check_in_ditolak_sebelum_30_menit_menjelang_shift(): void
    {
        [$idKaryawan] = $this->karyawanLayanan('Satpam');
        $this->waktuServer('07:29:59');

        $this->kirimCheckIn($idKaryawan)
            ->assertStatus(422)
            ->assertJson(['pesan' => 'Check-in belum dibuka. Shift Anda hari ini 08:00–16:00; check-in dibuka pukul 07:30 (30 menit sebelum shift dimulai).']);

        $this->assertNull($this->absensiKaryawan($idKaryawan));
    }

    public function test_check_in_dibuka_tepat_30_menit_sebelum_shift_dan_memakai_shift_milik_sendiri(): void
    {
        [$idKaryawan, $idPenempatan] = $this->karyawanLayanan('Satpam');
        $this->waktuServer('07:30:00');

        $this->kirimCheckIn($idKaryawan)->assertOk()->assertJson(['ok' => true, 'status' => 'masuk']);

        $absensi = $this->absensiKaryawan($idKaryawan);
        $this->assertSame($this->hariIniDb(), $absensi->tgl);
        $this->assertSame('07:30:00', $absensi->jam_masuk);
        $this->assertSame($idPenempatan, (int) $absensi->id_penempatan);
        $this->assertSame('Hadir', $absensi->status_kehadiran);
    }

    public function test_check_in_mengikuti_jadwal_harian_bukan_jam_penempatan(): void
    {
        [$idKaryawan, $idPenempatan] = $this->karyawanLayanan('Cleaning Service');
        $this->buatJadwalHarian($idPenempatan, $this->hariIniDb(), 'Kerja', '15:00', '23:00', 'Siang');
        $this->waktuServer('08:00:00');

        $this->kirimCheckIn($idKaryawan)
            ->assertStatus(422)
            ->assertJson(['pesan' => 'Check-in belum dibuka. Shift Anda hari ini Siang · 15:00–23:00; check-in dibuka pukul 14:30 (30 menit sebelum shift dimulai).']);
    }

    public function test_check_in_ditolak_setelah_shift_berakhir(): void
    {
        [$idKaryawan] = $this->karyawanLayanan('Satpam');
        $this->waktuServer('16:00:00');

        $this->kirimCheckIn($idKaryawan)
            ->assertStatus(422)
            ->assertJson(['pesan' => 'Shift Anda hari ini (08:00–16:00) sudah berakhir. Check-in tidak dapat dilakukan.']);
    }

    public function test_check_in_ganda_ditolak(): void
    {
        [$idKaryawan] = $this->karyawanLayanan('Satpam');
        $this->waktuServer('07:40:00');
        $this->kirimCheckIn($idKaryawan)->assertOk();

        $this->waktuServer('07:50:00');
        $this->kirimCheckIn($idKaryawan)->assertStatus(422)->assertJson(['ok' => false]);

        $this->assertSame(1, \DB::table('tbl_absensi')->where('id_karyawan', $idKaryawan)->count());
    }

    public function test_check_out_ditolak_sebelum_30_menit_lalu_diterima_setelahnya(): void
    {
        [$idKaryawan] = $this->karyawanLayanan('Satpam');
        $this->waktuServer('07:45:00');
        $this->kirimCheckIn($idKaryawan)->assertOk();

        $this->waktuServer('08:14:00');
        $this->kirimCheckOut($idKaryawan)
            ->assertStatus(422)
            ->assertJson(['pesan' => 'Check-out ditolak: minimal 30 menit setelah check-in (baru 29 menit).']);
        $this->assertNull($this->absensiKaryawan($idKaryawan)->jam_keluar);

        $this->waktuServer('08:15:00');
        $this->kirimCheckOut($idKaryawan)->assertOk()->assertJson(['status' => 'selesai']);
        $this->assertSame('08:15:00', $this->absensiKaryawan($idKaryawan)->jam_keluar);
    }

    public function test_check_out_ganda_ditolak(): void
    {
        [$idKaryawan] = $this->karyawanLayanan('Satpam');
        $this->waktuServer('07:45:00');
        $this->kirimCheckIn($idKaryawan)->assertOk();
        $this->waktuServer('16:00:00');
        $this->kirimCheckOut($idKaryawan)->assertOk();

        $this->waktuServer('16:05:00');
        $this->kirimCheckOut($idKaryawan)->assertStatus(422)->assertJson(['ok' => false]);

        $this->assertSame('16:00:00', $this->absensiKaryawan($idKaryawan)->jam_keluar);
    }

    public function test_halaman_absensi_menampilkan_shift_aktif_dan_jam_buka_check_in(): void
    {
        [$idKaryawan] = $this->karyawanLayanan('Satpam');
        $this->waktuServer('07:00:00');

        $this->actingAs($this->akunKaryawan($idKaryawan))
            ->get(route('tenaga-kerja.absensi'))
            ->assertOk()
            ->assertSee('Check-in belum dibuka. Shift Anda hari ini 08:00–16:00; check-in dibuka pukul 07:30', false)
            ->assertSee('Monitoring Aktivitas');
    }
}
