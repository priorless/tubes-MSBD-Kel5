<?php

namespace Tests\Feature\TenagaKerja;

use App\Models\Role;
use App\Models\User;
use App\Services\JadwalKerjaService;
use App\Services\PertukaranShiftService;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\MemakaiAbsensiTenagaKerja;
use Tests\TestCase;

/**
 * Backup shift (roker 1 arah) pada database MySQL asli: pilihan rekan, persetujuan rekan lalu Operasional,
 * hak absensi berpindah ke rekan, kedaluwarsa, dan aturan integritas di trigger.
 * Andi (Satpam, klien uji) Pagi 07:00–15:00 pada H+2; Budi (Satpam, klien lain) Libur pada tanggal itu.
 */
class PertukaranShiftHakAbsensiTest extends TestCase
{
    use MemakaiAbsensiTenagaKerja;

    private const int SELISIH_HARI = 2;

    private int $idAndi;

    private int $idBudi;

    private int $idPenempatanAndi;

    private int $idJadwalAndi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAbsensiTenagaKerja();
        Storage::fake('local');

        [$this->idAndi, $this->idPenempatanAndi] = $this->karyawanLayanan('Satpam');
        $this->idBudi = $this->buatKaryawan('Lapangan', ['id_jenis_layanan' => $this->idLayanan('Satpam')]);
        $penempatanBudi = $this->buatPenempatan($this->idBudi, $this->buatKlien(), $this->hariIniDb(-30));

        $tanggal = $this->hariIniDb(self::SELISIH_HARI);
        $this->idJadwalAndi = $this->buatJadwalHarian($this->idPenempatanAndi, $tanggal, 'Kerja', '07:00', '15:00', 'Pagi');
        $this->buatJadwalHarian($penempatanBudi, $tanggal, 'Libur');
    }

    protected function tearDown(): void
    {
        $this->tearDownDatabaseSji();
        parent::tearDown();
    }

    public function test_calon_rekan_hanya_sejabatan_yang_tidak_berjadwal_kerja_termasuk_dari_klien_lain(): void
    {
        [$idSatpamBekerja, $penempatanSatpamBekerja] = $this->karyawanLayanan('Satpam');
        $this->buatJadwalHarian($penempatanSatpamBekerja, $this->hariIniDb(self::SELISIH_HARI), 'Kerja', '15:00', '23:00');
        [$idDriver, $penempatanDriver] = $this->karyawanLayanan('Driver');
        $this->buatJadwalHarian($penempatanDriver, $this->hariIniDb(self::SELISIH_HARI), 'Libur');

        $calon = collect(app(PertukaranShiftService::class)->calonRekan($this->idAndi, $this->idJadwalAndi))->pluck('id_karyawan');

        $this->assertTrue($calon->contains($this->idBudi));
        $this->assertFalse($calon->contains($idSatpamBekerja));
        $this->assertFalse($calon->contains($idDriver));
    }

    public function test_rekan_menerima_lalu_operasional_menyetujui_dan_hak_absensi_berpindah_ke_rekan(): void
    {
        $idPengajuan = $this->ajukanBackup();

        $this->actingAs($this->akunKaryawan($this->idBudi))
            ->patch(route('tenaga-kerja.tukar-shift.tanggapi', $idPengajuan), ['keputusan' => 'terima'])
            ->assertSessionHas('sukses', 'Anda menerima permintaan backup shift. Pengajuan diteruskan ke Operasional untuk disetujui.');
        $this->actingAs(User::factory()->withRole(Role::Operasional)->create())
            ->patch(route('operasional.jadwal-kerja.pertukaran-shift.approve', $idPengajuan))
            ->assertSessionHas('success', 'Pengajuan disetujui. Shift sudah ditugaskan kepada rekan pengganti.');

        $this->assertDatabaseHas('tbl_jadwal_kerja', ['id' => $this->idJadwalAndi, 'id_karyawan_pengganti' => $this->idBudi]);

        $this->waktuServer('06:40:00', self::SELISIH_HARI);
        $this->kirimCheckIn($this->idBudi)->assertOk();
        $this->assertSame($this->idPenempatanAndi, (int) $this->absensiKaryawan($this->idBudi)->id_penempatan);
        $this->kirimCheckIn($this->idAndi)
            ->assertStatus(422)
            ->assertJson(['pesan' => 'Shift Anda hari ini sudah digantikan rekan melalui backup shift yang disetujui Operasional. Absensi tidak dapat dilakukan.']);
    }

    public function test_rekan_menolak_maka_pengajuan_langsung_ditolak_tanpa_ke_operasional(): void
    {
        $idPengajuan = $this->ajukanBackup();

        $this->actingAs($this->akunKaryawan($this->idBudi))
            ->patch(route('tenaga-kerja.tukar-shift.tanggapi', $idPengajuan), ['keputusan' => 'tolak', 'catatan_rekan' => 'Ada acara keluarga.']);

        $this->assertDatabaseHas('tbl_pertukaran_shift', ['id' => $idPengajuan, 'status' => 'Ditolak', 'status_rekan' => 'Ditolak']);
        $this->assertSame(0, app(PertukaranShiftService::class)->jumlahMenunggu());
        $this->assertDatabaseHas('tbl_jadwal_kerja', ['id' => $this->idJadwalAndi, 'id_karyawan_pengganti' => null]);
    }

    public function test_jadwal_yang_sedang_diajukan_tidak_dapat_diubah_operasional(): void
    {
        $this->ajukanBackup();

        try {
            app(JadwalKerjaService::class)->ubahJadwal($this->idJadwalAndi, ['status' => 'Libur'], $this->akunKaryawan($this->idAndi)->id);
            $this->fail('Jadwal yang sedang diajukan backup seharusnya terkunci.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('sedang diajukan untuk backup shift', $e->validator->errors()->first());
        }

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('sedang diajukan untuk backup shift');
        DB::table('tbl_jadwal_kerja')->where('id', $this->idJadwalAndi)->update(['jam_masuk' => '08:00:00']);
    }

    public function test_pengajuan_kedaluwarsa_bila_rekan_tidak_menanggapi_hingga_lima_jam_sebelum_shift(): void
    {
        $idPengajuan = $this->ajukanBackup();
        $layanan = app(PertukaranShiftService::class);

        $this->travelTo(CarbonImmutable::parse($this->hariIniDb(self::SELISIH_HARI).' 01:59:00'));
        $this->assertSame(1, $layanan->jumlahPermintaanMasuk($this->idBudi));

        $this->travelTo(CarbonImmutable::parse($this->hariIniDb(self::SELISIH_HARI).' 02:00:00'));
        $this->assertSame(0, $layanan->jumlahPermintaanMasuk($this->idBudi));
        $this->assertDatabaseHas('tbl_pertukaran_shift', ['id' => $idPengajuan, 'status' => 'Kedaluwarsa', 'diproses_oleh' => null]);
    }

    public function test_database_menolak_pengajuan_untuk_jadwal_milik_karyawan_lain(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('Jadwal yang diajukan bukan milik karyawan pengaju.');

        $this->sisipkanPengajuan($this->idBudi, $this->idAndi);
    }

    public function test_database_menolak_dua_pengajuan_aktif_untuk_satu_jadwal(): void
    {
        $this->sisipkanPengajuan($this->idAndi, $this->idBudi);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('uq_pertukaran_jadwal_aktif');
        $this->sisipkanPengajuan($this->idAndi, $this->idBudi);
    }

    private function ajukanBackup(): int
    {
        $this->actingAs($this->akunKaryawan($this->idAndi))
            ->post(route('tenaga-kerja.tukar-shift.store'), [
                'id_jadwal' => $this->idJadwalAndi,
                'id_karyawan_rekan' => $this->idBudi,
                'alasan' => 'Ada keperluan keluarga pada pagi hari.',
            ])
            ->assertSessionHas('sukses', 'Permintaan backup shift terkirim. Menunggu tanggapan rekan, lalu persetujuan Operasional.');

        return (int) DB::table('tbl_pertukaran_shift')->where('id_karyawan_pengaju', $this->idAndi)->value('id');
    }

    private function sisipkanPengajuan(int $idPengaju, int $idRekan): void
    {
        DB::table('tbl_pertukaran_shift')->insert([
            'id_karyawan_pengaju' => $idPengaju,
            'id_karyawan_rekan' => $idRekan,
            'id_jadwal_pengaju' => $this->idJadwalAndi,
            'tanggal_kerja' => $this->hariIniDb(self::SELISIH_HARI),
            'snapshot_pengaju' => '{}',
            'alasan' => 'Uji integritas database.',
        ]);
    }

    private function idLayanan(string $nama): int
    {
        return (int) DB::table('tbl_jenis_layanan')->where('nama_layanan', $nama)->value('id');
    }
}
