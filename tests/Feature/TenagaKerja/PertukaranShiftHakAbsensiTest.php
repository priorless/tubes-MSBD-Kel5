<?php

namespace Tests\Feature\TenagaKerja;

use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\MemakaiAbsensiTenagaKerja;
use Tests\TestCase;

/**
 * Roker (tukar shift): pengajuan langsung diputuskan Operasional, dan hak absensi hanya
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

    public function test_pengajuan_langsung_masuk_daftar_operasional_tanpa_persetujuan_rekan(): void
    {
        $idPengajuan = $this->ajukanTukar();

        $this->actingAs($this->operasional())
            ->get(route('operasional.jadwal-kerja.pertukaran-shift.index'))
            ->assertOk()
            ->assertSee(route('operasional.jadwal-kerja.pertukaran-shift.approve', $idPengajuan), false);

        $this->assertDatabaseHas('tbl_pertukaran_shift', ['id' => $idPengajuan, 'status' => 'Menunggu']);
        $this->assertDatabaseHas('tbl_jadwal_kerja', ['id' => $this->idJadwalAndi, 'jam_masuk' => '07:00:00']);
    }

    public function test_roker_yang_belum_disetujui_tidak_mengubah_hak_absensi(): void
    {
        $this->ajukanTukar();

        $this->waktuServer('06:40:00', self::SELISIH_HARI);
        $this->kirimCheckIn($this->idBudi)
            ->assertStatus(422)
            ->assertJson(['pesan' => 'Check-in belum dibuka. Shift Anda hari ini Siang · 15:00–23:00; check-in dibuka pukul 14:30 (30 menit sebelum shift dimulai).']);
    }

    public function test_roker_yang_disetujui_memperbarui_jadwal_dan_hak_absensi_kedua_karyawan(): void
    {
        $idPengajuan = $this->ajukanTukar();

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

    public function test_operasional_menolak_maka_jadwal_dan_hak_absensi_tetap(): void
    {
        $idPengajuan = $this->ajukanTukar();

        $this->actingAs($this->operasional())
            ->patch(route('operasional.jadwal-kerja.pertukaran-shift.reject', $idPengajuan), ['catatan_keputusan' => 'Kuota shift pagi penuh.'])
            ->assertSessionHas('success', 'Pengajuan ditolak. Jadwal kedua karyawan tetap seperti semula.');

        $this->assertDatabaseHas('tbl_pertukaran_shift', ['id' => $idPengajuan, 'status' => 'Ditolak', 'catatan_keputusan' => 'Kuota shift pagi penuh.']);
        $this->assertDatabaseHas('tbl_jadwal_kerja', ['id' => $this->idJadwalBudi, 'jam_masuk' => '15:00:00']);
    }

    public function test_rekan_tidak_diminta_menerima_atau_menolak(): void
    {
        $this->ajukanTukar();

        $this->actingAs($this->akunKaryawan($this->idBudi))
            ->get(route('tenaga-kerja.tukar-shift.index'))
            ->assertOk()
            ->assertSee('Menunggu Persetujuan Operasional')
            ->assertDontSee('Terima</button>', false)
            ->assertDontSee('Tolak</button>', false);
    }

    private function ajukanTukar(): int
    {
        $this->actingAs($this->akunKaryawan($this->idAndi))
            ->post(route('tenaga-kerja.tukar-shift.store'), [
                'tanggal_kerja' => $this->hariIniDb(self::SELISIH_HARI),
                'id_karyawan_rekan' => $this->idBudi,
                'alasan' => 'Ada keperluan keluarga pada pagi hari.',
            ])
            ->assertSessionHas('sukses', 'Pengajuan tukar shift terkirim dan menunggu persetujuan Operasional.');

        return (int) DB::table('tbl_pertukaran_shift')->where('id_karyawan_pengaju', $this->idAndi)->value('id');
    }

    private function operasional(): User
    {
        return User::factory()->withRole(Role::Operasional)->create();
    }
}
