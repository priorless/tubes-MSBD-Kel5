<?php

namespace Tests\Feature;

use App\Models\Role;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\MemakaiDatabaseSji;
use Tests\TestCase;

/**
 * Finalisasi Tahap 7 pada db_sji: view JSON + kolom generated, partisi tbl_log_aktivitas, dan pencarian FULLTEXT.
 */
class TeknologiLanjutanDatabaseTest extends TestCase
{
    use MemakaiDatabaseSji;

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

    public function test_view_riwayat_jadwal_membaca_json_sebelum_dan_sesudah_serta_kolom_generated(): void
    {
        $penempatan = $this->buatPenempatan($this->buatKaryawan(), $this->buatKlien(), '2099-05-01');
        $idJadwal = (int) DB::table('tbl_jadwal_kerja')->insertGetId(['id_penempatan' => $penempatan, 'tanggal_kerja' => '2099-05-10', 'status' => 'Libur']);
        DB::table('tbl_jadwal_kerja_riwayat')->insert([
            'id_jadwal_kerja' => $idJadwal,
            'aksi' => 'Diubah',
            'data_sebelum' => json_encode(['status' => 'Kerja', 'jam_masuk' => '08:00:00', 'jam_pulang' => '16:00:00', 'nama_shift_snapshot' => 'Pagi']),
            'data_sesudah' => json_encode(['status' => 'Libur', 'jam_masuk' => null, 'jam_pulang' => null, 'nama_shift_snapshot' => null]),
            'created_at' => now(),
        ]);

        $riwayat = DB::table('v_riwayat_jadwal_kerja')->where('id_jadwal_kerja', $idJadwal)->sole();

        $this->assertSame(['Kerja', '08:00:00', '16:00:00', 'Pagi'], [$riwayat->status_sebelum, $riwayat->jam_masuk_sebelum, $riwayat->jam_pulang_sebelum, $riwayat->shift_sebelum]);
        $this->assertSame(['Libur', null, null], [$riwayat->status_sesudah, $riwayat->jam_masuk_sesudah, $riwayat->shift_sesudah], 'JSON null dibaca sebagai NULL, bukan teks "null"');
        $this->assertSame('Libur', DB::table('tbl_jadwal_kerja_riwayat')->where('id_jadwal_kerja', $idJadwal)->value('status_sesudah'));
        $this->assertSame('idx_riwayat_status_sesudah', DB::selectOne(
            "EXPLAIN SELECT id FROM tbl_jadwal_kerja_riwayat WHERE status_sesudah = 'Libur'"
        )->key, 'Pencarian berdasarkan isi JSON memakai indeks kolom generated');
    }

    public function test_log_aktivitas_berpartisi_per_tahun_dan_trigger_log_tetap_menulis(): void
    {
        $this->loginSebagai(Role::Operasional);
        $idKlien = $this->buatKlien();

        $log = DB::table('tbl_log_aktivitas')->where('nama_tabel', 'tbl_klien')->where('id_data', $idKlien)->sole();
        $tahun = (int) date('Y', strtotime($log->waktu));
        $partisi = $tahun >= 2029 ? 'pmax' : 'p'.max(2025, $tahun);

        $this->assertSame(['p2025', 'p2026', 'p2027', 'p2028', 'pmax'], DB::table('information_schema.PARTITIONS')
            ->where('TABLE_SCHEMA', DB::getDatabaseName())->where('TABLE_NAME', 'tbl_log_aktivitas')
            ->orderBy('PARTITION_ORDINAL_POSITION')->pluck('PARTITION_NAME')->all());
        $this->assertSame(1, DB::table(DB::raw("tbl_log_aktivitas PARTITION ({$partisi})"))->where('id', $log->id)->count());
        $this->assertStringContainsString($partisi, (string) DB::selectOne(
            'EXPLAIN SELECT id FROM tbl_log_aktivitas WHERE waktu = ?', [$log->waktu]
        )->partitions, 'Partition pruning: hanya partisi tahun itu yang dibaca');
    }

    public function test_fulltext_index_mencocokkan_awal_kata_sedangkan_like_juga_tengah_kata(): void
    {
        $karyawan = $this->buatKaryawan('Lapangan', ['nama' => 'Zulkarnain Fulltextuji']);
        $klien = $this->buatKlien(['nama_perusahaan' => 'PT Samudra Fulltextuji']);
        // FULLTEXT InnoDB hanya melihat baris yang sudah di-commit; data uji dihapus lagi di tearDown.
        DB::commit();
        $cocokKaryawan = fn (string $kata): array => DB::table('tbl_karyawan')
            ->whereRaw('MATCH (nama) AGAINST (? IN BOOLEAN MODE)', [$kata])->pluck('id')->all();

        $this->assertSame([$karyawan], $cocokKaryawan('+zulkar* +fulltext*'));
        $this->assertSame([], $cocokKaryawan('karnain*'), 'FULLTEXT tidak mencocokkan potongan di tengah kata');
        $this->assertSame([$klien], DB::table('tbl_klien')
            ->whereRaw('MATCH (nama_perusahaan) AGAINST (? IN BOOLEAN MODE)', ['+samudra* +fulltextuji'])->pluck('id')->all());
        $rencana = DB::selectOne("EXPLAIN SELECT id FROM tbl_karyawan WHERE MATCH (nama) AGAINST ('+zulkar*' IN BOOLEAN MODE)");
        $this->assertSame(['fulltext', 'ft_karyawan_nama'], [$rencana->type, $rencana->key]);

        // Pencarian di aplikasi tetap LIKE: potongan di tengah kata tetap ketemu.
        $this->loginSebagai(Role::Hrd);
        $this->get(route('hrd.karyawan.index', ['q' => 'karnain']))->assertOk()->assertSeeText('Zulkarnain Fulltextuji');
        $this->get(route('hrd.klien.index', ['q' => 'amudra']))->assertOk()->assertSeeText('PT Samudra Fulltextuji');
    }
}
