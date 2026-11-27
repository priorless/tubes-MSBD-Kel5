<?php

namespace Tests\Feature\TenagaKerja;

use App\Models\Role;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\MemakaiDatabaseSji;
use Tests\TestCase;

/**
 * Alpha otomatis (sql/Komponen/24): sp_tandai_alpha_otomatis mencatat Alpha untuk shift Kerja yang berakhir tanpa
 * check-in, dijalankan event ev_tandai_alpha_otomatis. Waktu acuan dikirim sebagai parameter agar bisa diuji.
 */
class AlphaOtomatisTest extends TestCase
{
    use MemakaiDatabaseSji;

    private const string TANGGAL = '2099-03-10';

    private int $idKaryawan;

    private int $idPenempatan;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpDatabaseSji();

        $this->idKaryawan = $this->buatKaryawan('Lapangan');
        $this->idPenempatan = $this->buatPenempatan($this->idKaryawan, $this->buatKlien(), '2099-03-01');
    }

    protected function tearDown(): void
    {
        $this->tearDownDatabaseSji();
        parent::tearDown();
    }

    public function test_event_terpasang_setiap_15_menit(): void
    {
        $event = DB::table('information_schema.EVENTS')
            ->where('EVENT_SCHEMA', DB::getDatabaseName())
            ->where('EVENT_NAME', 'ev_tandai_alpha_otomatis')
            ->sole();

        $this->assertSame(['ENABLED', '15', 'MINUTE'], [$event->STATUS, (string) $event->INTERVAL_VALUE, $event->INTERVAL_FIELD]);
        $this->assertStringContainsString('sp_tandai_alpha_otomatis', $event->EVENT_DEFINITION);
    }

    public function test_tidak_check_in_sampai_shift_berakhir_tercatat_alpha(): void
    {
        $this->jadwal(self::TANGGAL, '08:00:00', '16:00:00');

        $this->tandai(self::TANGGAL.' 15:59:59');
        $this->assertNull($this->absensi($this->idKaryawan), 'Shift belum berakhir, karyawan masih bisa check-in');

        $this->tandai(self::TANGGAL.' 16:00:00');
        $absensi = $this->absensi($this->idKaryawan);
        $this->assertSame(['Alpha', $this->idPenempatan, null], [$absensi->status_kehadiran, (int) $absensi->id_penempatan, $absensi->jam_masuk]);
        $this->assertSame('Alpha otomatis: tidak check-in sampai shift berakhir pukul 16:00.', $absensi->keterangan);

        $this->tandai(self::TANGGAL.' 16:15:00');
        $this->assertSame(1, DB::table('tbl_absensi')->where('id_karyawan', $this->idKaryawan)->count(), 'Tidak dicatat dua kali');
    }

    public function test_shift_malam_alpha_setelah_berakhir_keesokan_pagi(): void
    {
        $this->jadwal(self::TANGGAL, '22:00:00', '06:00:00');

        $this->tandai('2099-03-11 05:59:00');
        $this->assertNull($this->absensi($this->idKaryawan));

        $this->tandai('2099-03-11 06:00:00');
        $this->assertSame('Alpha', $this->absensi($this->idKaryawan)->status_kehadiran, 'Dicatat pada tanggal shift, bukan tanggal berakhirnya');
    }

    public function test_yang_sudah_check_in_libur_atau_payroll_sudah_dibuat_tidak_ditandai(): void
    {
        $libur = $this->buatKaryawan('Lapangan');
        $sudahPayroll = $this->buatKaryawan('Lapangan');
        $this->jadwal(self::TANGGAL, '08:00:00', '16:00:00');
        $this->buatAbsensi($this->idKaryawan, self::TANGGAL, 'Hadir', ['id_penempatan' => $this->idPenempatan, 'jam_masuk' => '08:05:00']);
        DB::table('tbl_jadwal_kerja')->insert(['id_penempatan' => $this->buatPenempatan($libur, $this->buatKlien(), '2099-03-01'),
            'tanggal_kerja' => self::TANGGAL, 'status' => 'Libur']);
        $this->jadwal(self::TANGGAL, '08:00:00', '16:00:00', $this->buatPenempatan($sudahPayroll, $this->buatKlien(), '2099-03-01'));
        DB::table('tbl_payroll')->insert(['id_karyawan' => $sudahPayroll, 'periode_bulan' => 3, 'periode_tahun' => 2099, 'gaji_pokok' => 0, 'total_gaji' => 0]);

        $this->tandai(self::TANGGAL.' 23:00:00');

        $this->assertSame('Hadir', $this->absensi($this->idKaryawan)->status_kehadiran);
        $this->assertNull($this->absensi($libur));
        $this->assertNull($this->absensi($sudahPayroll));
    }

    public function test_shift_yang_berakhir_lebih_dari_tujuh_hari_lalu_tidak_diisi_mundur(): void
    {
        $this->jadwal('2099-03-02', '08:00:00', '16:00:00');

        $this->tandai('2099-03-09 16:00:01');

        $this->assertNull($this->absensi($this->idKaryawan, '2099-03-02'));
    }

    public function test_dua_shift_sehari_alpha_dicatat_setelah_shift_terakhir_berakhir(): void
    {
        $penempatanKedua = $this->buatPenempatan($this->idKaryawan, $this->buatKlien(), '2099-03-01');
        $this->jadwal(self::TANGGAL, '06:00:00', '10:00:00');
        $this->jadwal(self::TANGGAL, '14:00:00', '18:00:00', $penempatanKedua);

        $this->tandai(self::TANGGAL.' 12:00:00');
        $this->assertNull($this->absensi($this->idKaryawan), 'Masih bisa check-in untuk shift kedua');

        $this->tandai(self::TANGGAL.' 18:00:00');
        $absensi = $this->absensi($this->idKaryawan);
        $this->assertSame([$this->idPenempatan, 'Alpha otomatis: tidak check-in sampai shift berakhir pukul 18:00.'], [(int) $absensi->id_penempatan, $absensi->keterangan]);
    }

    public function test_shift_yang_di_backup_menandai_rekan_pengganti_bukan_pemilik(): void
    {
        $rekan = $this->buatKaryawan('Lapangan');
        $this->buatPenempatan($rekan, $this->buatKlien(), '2099-03-01');
        $idJadwal = $this->jadwal(self::TANGGAL, '08:00:00', '16:00:00');
        $this->setujuiBackup($idJadwal, $rekan);

        $this->tandai(self::TANGGAL.' 16:00:00');

        $this->assertNull($this->absensi($this->idKaryawan), 'Pemilik jadwal sudah digantikan');
        $absensi = $this->absensi($rekan);
        $this->assertSame(['Alpha', $this->idPenempatan], [$absensi->status_kehadiran, (int) $absensi->id_penempatan]);
    }

    public function test_alpha_diganti_saat_pengajuan_sakit_disetujui(): void
    {
        $this->jadwal(self::TANGGAL, '08:00:00', '16:00:00');
        $this->tandai(self::TANGGAL.' 16:00:00');
        $idPengajuan = (int) DB::table('tbl_pengajuan_ketidakhadiran')->insertGetId([
            'id_karyawan' => $this->idKaryawan, 'jenis' => 'Sakit', 'tgl_mulai' => self::TANGGAL, 'tgl_selesai' => self::TANGGAL,
            'alasan' => 'Demam', 'status' => 'Menunggu', 'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::statement('CALL sp_setujui_pengajuan_ketidakhadiran(?, ?)', [$idPengajuan, $this->loginSebagai(Role::Hrd)->id]);

        $absensi = $this->absensi($this->idKaryawan);
        $this->assertSame(['Sakit', 'Pengajuan #'.$idPengajuan.': Demam'], [$absensi->status_kehadiran, $absensi->keterangan]);
        $this->assertSame(1, DB::table('tbl_absensi')->where('id_karyawan', $this->idKaryawan)->count());
    }

    public function test_hrd_bisa_mengganti_alpha_dengan_izin_lewat_input_ketidakhadiran(): void
    {
        $kemarin = $this->hariIniDb(-1);
        $karyawan = $this->buatKaryawan('Lapangan');
        $this->jadwal($kemarin, '08:00:00', '09:00:00', $this->buatPenempatan($karyawan, $this->buatKlien(), $this->hariIniDb(-30)));
        $this->tandai($kemarin.' 09:00:00');
        $this->assertSame('Alpha', $this->absensi($karyawan, $kemarin)->status_kehadiran);

        DB::statement('CALL sp_input_ketidakhadiran(?, ?, ?, ?)', [$karyawan, $kemarin, 'Izin', 'Urusan keluarga']);

        $this->assertSame(['Izin', 'Urusan keluarga'], [$this->absensi($karyawan, $kemarin)->status_kehadiran, $this->absensi($karyawan, $kemarin)->keterangan]);
    }

    private function tandai(string $waktu): void
    {
        DB::statement('CALL sp_tandai_alpha_otomatis(?)', [$waktu]);
    }

    private function absensi(int $idKaryawan, string $tanggal = self::TANGGAL): ?object
    {
        return DB::table('tbl_absensi')->where('id_karyawan', $idKaryawan)->where('tgl', $tanggal)->first();
    }

    private function jadwal(string $tanggal, string $masuk, string $pulang, ?int $idPenempatan = null): int
    {
        return (int) DB::table('tbl_jadwal_kerja')->insertGetId([
            'id_penempatan' => $idPenempatan ?? $this->idPenempatan,
            'tanggal_kerja' => $tanggal,
            'status' => 'Kerja',
            'jam_masuk' => $masuk,
            'jam_pulang' => $pulang,
            'durasi_menit' => 60,
        ]);
    }

    /** Backup shift yang sudah diterima rekan dan disetujui Operasional (alur trigger tbl_pertukaran_shift). */
    private function setujuiBackup(int $idJadwal, int $idRekan): void
    {
        $idPengajuan = (int) DB::table('tbl_pertukaran_shift')->insertGetId([
            'id_karyawan_pengaju' => $this->idKaryawan, 'id_karyawan_rekan' => $idRekan, 'id_jadwal_pengaju' => $idJadwal,
            'tanggal_kerja' => self::TANGGAL, 'snapshot_pengaju' => '{}', 'alasan' => 'Uji backup',
        ]);
        DB::table('tbl_pertukaran_shift')->where('id', $idPengajuan)->update(['status_rekan' => 'Diterima', 'ditanggapi_rekan_pada' => now()]);
        DB::table('tbl_pertukaran_shift')->where('id', $idPengajuan)->update([
            'status' => 'Disetujui', 'diproses_oleh' => $this->loginSebagai(Role::Operasional)->id, 'diproses_pada' => now(),
        ]);
        DB::table('tbl_jadwal_kerja')->where('id', $idJadwal)->update(['id_karyawan_pengganti' => $idRekan]);
    }
}
