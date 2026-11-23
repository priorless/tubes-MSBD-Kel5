<?php

namespace Tests\Concerns;

use App\Models\Role;
use App\Models\User;
use App\Services\LaporanAktivitasService;
use App\Support\JamServer;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

/**
 * Helper absensi Tenaga Kerja di atas MemakaiDatabaseSji: karyawan per jenis layanan, waktu server beku
 * (JamServer) pada tanggal database hari ini, jadwal harian, dan permintaan check-in/check-out/laporan.
 * Jam penempatan uji = 08:00–16:00 (MemakaiDatabaseSji::buatPenempatan).
 */
trait MemakaiAbsensiTenagaKerja
{
    use MemakaiDatabaseSji;

    private int $idKlienUji;

    private ?JamServer $jamServerUji = null;

    protected function setUpAbsensiTenagaKerja(): void
    {
        $this->setUpDatabaseSji();
        $this->idKlienUji = $this->buatKlien();
    }

    /**
     * Karyawan Lapangan dengan jenis layanan tertentu dan penempatan aktif sejak 30 hari lalu.
     *
     * @return array{0: int, 1: int} [id_karyawan, id_penempatan]
     */
    protected function karyawanLayanan(string $namaLayanan): array
    {
        $idKaryawan = $this->buatKaryawan('Lapangan', [
            'id_jenis_layanan' => DB::table('tbl_jenis_layanan')->where('nama_layanan', $namaLayanan)->value('id'),
        ]);

        return [$idKaryawan, $this->buatPenempatan($idKaryawan, $this->idKlienUji, $this->hariIniDb(-30))];
    }

    /** Akun Karyawan Lapangan milik karyawan (dibuat trigger trg_auto_akun_karyawan atau factory). */
    protected function akunKaryawan(int $idKaryawan): User
    {
        $user = User::query()->where('id_karyawan', $idKaryawan)->first()
            ?? User::factory()->withRole(Role::KaryawanLapangan)->create(['id_karyawan' => $idKaryawan]);
        $user->forceFill(['must_change_password' => false, 'status' => User::StatusAktif])->save();

        return $user;
    }

    /** Bekukan waktu server absensi pada tanggal database (hari ini + selisih) jam tertentu. */
    protected function waktuServer(string $jam, int $selisihHari = 0): void
    {
        // Satu instance untuk seluruh test: controller yang sudah dibuat router tetap memegang instance ini.
        $this->jamServerUji ??= $this->app->instance(JamServer::class, new JamServer);
        $this->jamServerUji->tetapkan(CarbonImmutable::parse($this->hariIniDb($selisihHari).' '.$jam));
    }

    protected function buatJadwalHarian(int $idPenempatan, string $tanggal, string $status, ?string $masuk = null, ?string $pulang = null, ?string $namaShift = null): int
    {
        return (int) DB::table('tbl_jadwal_kerja')->insertGetId([
            'id_penempatan' => $idPenempatan,
            'tanggal_kerja' => $tanggal,
            'status' => $status,
            'nama_shift_snapshot' => $namaShift,
            'jam_masuk' => $masuk === null ? null : $masuk.':00',
            'jam_pulang' => $pulang === null ? null : $pulang.':00',
            'durasi_menit' => $masuk === null ? null : 480,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    protected function fotoUji(string $nama = 'foto.jpg'): UploadedFile
    {
        return UploadedFile::fake()->image($nama, 640, 480);
    }

    /**
     * @param  array<string, mixed>  $tambahan
     */
    protected function kirimCheckIn(int $idKaryawan, array $tambahan = []): TestResponse
    {
        return $this->actingAs($this->akunKaryawan($idKaryawan))
            ->postJson(route('tenaga-kerja.absensi.check-in'), ['foto' => $this->fotoUji(), ...$tambahan]);
    }

    /**
     * @param  array<string, mixed>  $tambahan
     */
    protected function kirimCheckOut(int $idKaryawan, array $tambahan = []): TestResponse
    {
        return $this->actingAs($this->akunKaryawan($idKaryawan))
            ->postJson(route('tenaga-kerja.absensi.check-out'), ['foto' => $this->fotoUji(), ...$tambahan]);
    }

    /**
     * @param  array<string, mixed>  $tambahan
     */
    protected function kirimLaporan(int $idKaryawan, array $tambahan = []): TestResponse
    {
        return $this->actingAs($this->akunKaryawan($idKaryawan))
            ->post(route('tenaga-kerja.laporan.store'), ['foto' => $this->fotoUji(), 'keterangan' => 'Area lobi aman dan bersih', ...$tambahan]);
    }

    protected function kirimKoreksi(int $idKaryawan, int $idLaporan): TestResponse
    {
        return $this->actingAs($this->akunKaryawan($idKaryawan))
            ->post(route('tenaga-kerja.laporan.koreksi', $idLaporan), ['foto' => $this->fotoUji('koreksi.jpg')]);
    }

    /**
     * Checklist P2H lengkap (semua komponen Baik), dapat ditimpa per komponen.
     *
     * @param  array<string, array{kondisi: string, keterangan?: string}>  $timpa
     * @return array<string, array{kondisi: string, keterangan?: string}>
     */
    protected function p2hLengkap(array $timpa = []): array
    {
        return array_merge(
            array_map(fn (): array => ['kondisi' => 'Baik'], LaporanAktivitasService::KOMPONEN_P2H),
            $timpa,
        );
    }

    protected function absensiKaryawan(int $idKaryawan): ?object
    {
        return DB::table('tbl_absensi')->where('id_karyawan', $idKaryawan)->first();
    }
}
