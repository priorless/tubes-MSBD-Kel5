<?php

namespace Tests\Feature\TenagaKerja;

use App\Models\Role;
use App\Services\PertukaranShiftService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\MemakaiDatabaseSji;
use Tests\TestCase;

/**
 * Keterlambatan & indikasi lembur memakai jadwal harian (sql/Komponen/13_jadwal_absensi_dari_roster.sql):
 * jadwal Kerja harian (termasuk hasil tukar shift) -> Libur (tidak dinilai) -> jam penempatan bila belum diatur.
 * db_sji dalam transaksi yang di-rollback. Jam penempatan uji = 08:00–16:00.
 */
class JadwalAbsensiHarianTest extends TestCase
{
    use MemakaiDatabaseSji;

    private int $idKlien;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpDatabaseSji();
        $this->idKlien = $this->buatKlien();
    }

    protected function tearDown(): void
    {
        $this->tearDownDatabaseSji();
        parent::tearDown();
    }

    public function test_check_in_tepat_waktu_menurut_jadwal_harian_tidak_terlambat_walau_jauh_dari_jam_penempatan(): void
    {
        [$idKaryawan, $idPenempatan] = $this->karyawanDitempatkan();
        $tanggal = $this->hariIniDb(-1);
        $this->buatJadwal($idPenempatan, $tanggal, 'Kerja', '15:00', '23:00');

        $absensi = $this->checkIn($idKaryawan, $tanggal, '15:10:00');

        $this->assertSame('Hadir', $absensi->status_kehadiran);
        $this->assertSame(0, (int) $absensi->menit_terlambat);
    }

    public function test_keterlambatan_dihitung_dari_jam_masuk_jadwal_harian(): void
    {
        [$idKaryawan, $idPenempatan] = $this->karyawanDitempatkan();
        $tanggal = $this->hariIniDb(-1);
        $this->buatJadwal($idPenempatan, $tanggal, 'Kerja', '15:00', '23:00');

        $absensi = $this->checkIn($idKaryawan, $tanggal, '15:45:00');

        $this->assertSame('Terlambat', $absensi->status_kehadiran);
        $this->assertSame(45, (int) $absensi->menit_terlambat);
    }

    public function test_hari_belum_diatur_tetap_memakai_jam_penempatan(): void
    {
        [$idKaryawan] = $this->karyawanDitempatkan();

        $absensi = $this->checkIn($idKaryawan, $this->hariIniDb(-1), '08:45:00');

        $this->assertSame('Terlambat', $absensi->status_kehadiran);
        $this->assertSame(45, (int) $absensi->menit_terlambat);
    }

    public function test_hari_libur_tidak_dinilai_terlambat_dan_tanpa_indikasi_lembur(): void
    {
        [$idKaryawan, $idPenempatan] = $this->karyawanDitempatkan();
        $tanggal = $this->hariIniDb(-1);
        $this->buatJadwal($idPenempatan, $tanggal, 'Libur');

        $absensi = $this->checkIn($idKaryawan, $tanggal, '13:00:00');
        $absensi = $this->checkOut($absensi->id, '23:00:00');

        $this->assertSame('Hadir', $absensi->status_kehadiran);
        $this->assertSame(0, (int) $absensi->menit_terlambat);
        $this->assertSame(0, (int) $absensi->jam_lembur);
    }

    public function test_indikasi_lembur_dihitung_dari_jam_pulang_jadwal_harian_shift_malam(): void
    {
        [$idKaryawan, $idPenempatan] = $this->karyawanDitempatkan();
        $tanggal = $this->hariIniDb(-1);
        $this->buatJadwal($idPenempatan, $tanggal, 'Kerja', '15:00', '23:00');

        $absensi = $this->checkIn($idKaryawan, $tanggal, '15:00:00');
        $absensi = $this->checkOut($absensi->id, '01:10:00');

        $this->assertSame(2, (int) $absensi->jam_lembur);
    }

    public function test_backup_shift_yang_disetujui_dikerjakan_rekan_pada_penempatan_pengaju(): void
    {
        [$idPengaju, $idPenempatanPengaju] = $this->karyawanDitempatkan();
        [$idRekan, $idPenempatanRekan] = $this->karyawanDitempatkan();
        $tanggal = $this->hariIniDb(2);
        $idJadwal = $this->buatJadwal($idPenempatanPengaju, $tanggal, 'Kerja', '07:00', '15:00');
        $this->buatJadwal($idPenempatanRekan, $tanggal, 'Libur');

        $layanan = app(PertukaranShiftService::class);
        $idPengajuan = $layanan->ajukan($idPengaju, $idJadwal, $idRekan, 'Ada keperluan keluarga pada pagi hari.');
        $layanan->tanggapiRekan($idPengajuan, $idRekan, true, null);
        $layanan->setujui($idPengajuan, $this->loginSebagai(Role::Operasional)->id);

        $idAbsensiRekan = $this->buatAbsensi($idRekan, $tanggal, 'Hadir', ['jam_masuk' => '07:40:00', 'id_penempatan' => $idPenempatanPengaju]);
        $rekan = DB::table('tbl_absensi')->where('id', $idAbsensiRekan)->first();

        $this->assertSame('Terlambat', $rekan->status_kehadiran);
        $this->assertSame(40, (int) $rekan->menit_terlambat);

        try {
            $this->checkIn($idPengaju, $tanggal, '07:00:00');
            $this->fail('Pengaju yang sudah digantikan seharusnya tidak dapat absen.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('sudah digantikan rekan', $e->getMessage());
        }
    }

    public function test_view_absensi_harian_menampilkan_jadwal_efektif_dan_sumbernya(): void
    {
        [$idKaryawan, $idPenempatan] = $this->karyawanDitempatkan();
        $tanggalHarian = $this->hariIniDb(-3);
        $tanggalLibur = $this->hariIniDb(-2);
        $tanggalKosong = $this->hariIniDb(-1);
        $this->buatJadwal($idPenempatan, $tanggalHarian, 'Kerja', '15:00', '23:00');
        $this->buatJadwal($idPenempatan, $tanggalLibur, 'Libur');
        foreach ([$tanggalHarian, $tanggalLibur, $tanggalKosong] as $tanggal) {
            $this->checkIn($idKaryawan, $tanggal, '09:00:00');
        }

        $baris = DB::table('v_absensi_harian')->where('id_karyawan', $idKaryawan)->get()->keyBy('tgl');

        $this->assertSame(['15:00:00', '23:00:00', 'Jadwal Harian'], $this->jadwalView($baris[$tanggalHarian]));
        $this->assertSame([null, null, 'Libur'], $this->jadwalView($baris[$tanggalLibur]));
        $this->assertSame(['08:00:00', '16:00:00', 'Penempatan'], $this->jadwalView($baris[$tanggalKosong]));
    }

    public function test_view_penugasan_aktif_menampilkan_jadwal_harian_hari_ini(): void
    {
        [$idKaryawan, $idPenempatan] = $this->karyawanDitempatkan();
        $this->buatJadwal($idPenempatan, $this->hariIniDb(), 'Kerja', '22:00', '06:00');

        $penugasan = DB::table('v_penugasan_aktif')->where('id_karyawan', $idKaryawan)->first();

        $this->assertSame(['22:00:00', '06:00:00', 'Jadwal Harian'], $this->jadwalView($penugasan));
    }

    /**
     * @return array{0: int, 1: int} [id_karyawan, id_penempatan]
     */
    private function karyawanDitempatkan(): array
    {
        $idKaryawan = $this->buatKaryawan('Lapangan');

        return [$idKaryawan, $this->buatPenempatan($idKaryawan, $this->idKlien, $this->hariIniDb(-30))];
    }

    private function buatJadwal(int $idPenempatan, string $tanggal, string $status, ?string $masuk = null, ?string $pulang = null): int
    {
        return (int) DB::table('tbl_jadwal_kerja')->insertGetId([
            'id_penempatan' => $idPenempatan,
            'tanggal_kerja' => $tanggal,
            'status' => $status,
            'jam_masuk' => $masuk === null ? null : $masuk.':00',
            'jam_pulang' => $pulang === null ? null : $pulang.':00',
            'durasi_menit' => $masuk === null ? null : 480,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function checkIn(int $idKaryawan, string $tanggal, string $jamMasuk): object
    {
        $id = $this->buatAbsensi($idKaryawan, $tanggal, 'Hadir', ['jam_masuk' => $jamMasuk]);

        return DB::table('tbl_absensi')->where('id', $id)->first();
    }

    private function checkOut(int $idAbsensi, string $jamKeluar): object
    {
        DB::table('tbl_absensi')->where('id', $idAbsensi)->update(['jam_keluar' => $jamKeluar]);

        return DB::table('tbl_absensi')->where('id', $idAbsensi)->first();
    }

    /**
     * @return array{0: string|null, 1: string|null, 2: string|null}
     */
    private function jadwalView(object $baris): array
    {
        return [$baris->jadwal_datang, $baris->jadwal_pulang, $baris->sumber_jadwal];
    }
}
