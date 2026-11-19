<?php

namespace Tests\Feature\TenagaKerja;

use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\MemakaiAbsensiTenagaKerja;
use Tests\TestCase;

/**
 * Roker (tukar shift): rekan menerima/menolak, Operasional memutuskan, dan hak absensi hanya
 * mengikuti jadwal yang sudah disetujui. Andi: Pagi 07:00–15:00, Budi: Siang 15:00–23:00 pada H+2.
 */
class PertukaranShiftHakAbsensiTest extends TestCase
{
    use MemakaiAbsensiTenagaKerja;

    private const int SELISIH_HARI = 2;

    private int $idAndi;

    private int $idBudi;

    private int $idJadwalAndi;

    private int $idJadwalBudi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAbsensiTenagaKerja();
        Storage::fake('local');

        [$this->idAndi, $penempatanAndi] = $this->karyawanLayanan('Satpam');
        [$this->idBudi, $penempatanBudi] = $this->karyawanLayanan('Satpam');
        $tanggal = $this->hariIniDb(self::SELISIH_HARI);
        $this->idJadwalAndi = $this->buatJadwalHarian($penempatanAndi, $tanggal, 'Kerja', '07:00', '15:00', 'Pagi');
        $this->idJadwalBudi = $this->buatJadwalHarian($penempatanBudi, $tanggal, 'Kerja', '15:00', '23:00', 'Siang');
    }

    protected function tearDown(): void
    {
        $this->tearDownDatabaseSji();
        parent::tearDown();
    }

    public function test_pengajuan_menunggu_persetujuan_rekan_dan_operasional_belum_dapat_menyetujui(): void
    {
        $idPengajuan = $this->ajukanTukar();

        $this->actingAs($this->operasional())
            ->patch(route('operasional.jadwal-kerja.pertukaran-shift.approve', $idPengajuan))
            ->assertSessionHas('error', 'Rekan belum menerima pengajuan ini. Persetujuan Operasional baru dapat diberikan setelah rekan menerima.');

        $this->assertDatabaseHas('tbl_pertukaran_shift', ['id' => $idPengajuan, 'status' => 'Menunggu', 'status_rekan' => 'Menunggu']);
        $this->assertDatabaseHas('tbl_jadwal_kerja', ['id' => $this->idJadwalAndi, 'jam_masuk' => '07:00:00']);
    }

    public function test_roker_yang_belum_disetujui_tidak_mengubah_hak_absensi(): void
    {
        $idPengajuan = $this->ajukanTukar();
        $this->tanggapi($this->idBudi, $idPengajuan, 'terima')
            ->assertSessionHas('sukses', 'Anda menerima pengajuan tukar shift. Pengajuan diteruskan ke Operasional untuk persetujuan akhir.');

        $this->waktuServer('06:40:00', self::SELISIH_HARI);
        $this->kirimCheckIn($this->idBudi)
            ->assertStatus(422)
            ->assertJson(['pesan' => 'Check-in belum dibuka. Shift Anda hari ini Siang · 15:00–23:00; check-in dibuka pukul 14:30 (30 menit sebelum shift dimulai).']);
    }

    public function test_roker_yang_disetujui_memperbarui_jadwal_dan_hak_absensi_kedua_karyawan(): void
    {
        $idPengajuan = $this->ajukanTukar();
        $this->tanggapi($this->idBudi, $idPengajuan, 'terima');

        $this->actingAs($this->operasional())
            ->patch(route('operasional.jadwal-kerja.pertukaran-shift.approve', $idPengajuan))
            ->assertSessionHas('success', 'Pengajuan disetujui. Jadwal kedua karyawan sudah ditukar.');

        $this->assertDatabaseHas('tbl_jadwal_kerja', ['id' => $this->idJadwalAndi, 'jam_masuk' => '15:00:00', 'nama_shift_snapshot' => 'Siang']);
        $this->assertDatabaseHas('tbl_jadwal_kerja', ['id' => $this->idJadwalBudi, 'jam_masuk' => '07:00:00', 'nama_shift_snapshot' => 'Pagi']);

        $this->waktuServer('06:40:00', self::SELISIH_HARI);
        $this->kirimCheckIn($this->idBudi)->assertOk();
        $this->kirimCheckIn($this->idAndi)
            ->assertStatus(422)
            ->assertJson(['pesan' => 'Check-in belum dibuka. Shift Anda hari ini Siang · 15:00–23:00; check-in dibuka pukul 14:30 (30 menit sebelum shift dimulai).']);
    }

    public function test_rekan_menolak_maka_pengajuan_ditolak_dan_jadwal_tetap(): void
    {
        $idPengajuan = $this->ajukanTukar();

        $this->tanggapi($this->idBudi, $idPengajuan, 'tolak', 'Ada acara keluarga')
            ->assertSessionHas('sukses', 'Anda menolak pengajuan tukar shift. Jadwal Anda tetap seperti semula.');

        $this->assertDatabaseHas('tbl_pertukaran_shift', [
            'id' => $idPengajuan, 'status' => 'Ditolak', 'status_rekan' => 'Ditolak', 'catatan_keputusan' => 'Ditolak oleh rekan: Ada acara keluarga',
        ]);
        $this->assertDatabaseHas('tbl_jadwal_kerja', ['id' => $this->idJadwalBudi, 'jam_masuk' => '15:00:00']);
    }

    public function test_hanya_rekan_yang_dituju_dapat_menanggapi(): void
    {
        $idPengajuan = $this->ajukanTukar();

        $this->tanggapi($this->idAndi, $idPengajuan, 'terima')->assertSessionHas('gagal', 'Pengajuan pertukaran shift tidak ditemukan.');

        $this->assertDatabaseHas('tbl_pertukaran_shift', ['id' => $idPengajuan, 'status_rekan' => 'Menunggu']);
    }

    private function ajukanTukar(): int
    {
        $this->actingAs($this->akunKaryawan($this->idAndi))
            ->post(route('tenaga-kerja.tukar-shift.store'), [
                'tanggal_kerja' => $this->hariIniDb(self::SELISIH_HARI),
                'id_karyawan_rekan' => $this->idBudi,
                'alasan' => 'Ada keperluan keluarga pada pagi hari.',
            ])
            ->assertSessionHas('sukses', 'Pengajuan tukar shift terkirim. Menunggu persetujuan rekan, lalu persetujuan akhir Operasional.');

        return (int) DB::table('tbl_pertukaran_shift')->where('id_karyawan_pengaju', $this->idAndi)->value('id');
    }

    private function tanggapi(int $idKaryawan, int $idPengajuan, string $tanggapan, ?string $catatan = null): TestResponse
    {
        return $this->actingAs($this->akunKaryawan($idKaryawan))
            ->patch(route('tenaga-kerja.tukar-shift.tanggapi', $idPengajuan), array_filter(['tanggapan' => $tanggapan, 'catatan_rekan' => $catatan]));
    }

    private function operasional(): User
    {
        return User::factory()->withRole(Role::Operasional)->create();
    }
}
