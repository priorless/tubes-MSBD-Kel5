<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\MemakaiDatabaseSji;
use Tests\TestCase;

/**
 * tbl_log_aktivitas append-only (sql/Komponen/19_log_append_only.sql): INSERT diterima, UPDATE/DELETE ditolak
 * trigger dengan SIGNAL 45000. Berjalan di dalam transaksi MemakaiDatabaseSji sehingga baris uji di-rollback.
 */
class LogAppendOnlyTest extends TestCase
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

    private function tulisLog(): int
    {
        return (int) DB::table('tbl_log_aktivitas')->insertGetId([
            'id_user' => null,
            'nama_tabel' => 'tbl_klien',
            'aksi' => 'INSERT',
            'id_data' => 0,
            'keterangan' => 'Uji append-only',
        ]);
    }

    private function assertDitolakDatabase(string $pesan, callable $operasi): void
    {
        try {
            $operasi();
            $this->fail('Operasi pada log seharusnya ditolak trigger.');
        } catch (QueryException $galat) {
            $this->assertSame('45000', $galat->errorInfo[0]);
            $this->assertStringContainsString($pesan, $galat->getMessage());
        }
    }

    public function test_insert_log_tetap_berhasil(): void
    {
        $id = $this->tulisLog();

        $this->assertSame('Uji append-only', DB::table('tbl_log_aktivitas')->where('id', $id)->value('keterangan'));
    }

    public function test_update_log_ditolak(): void
    {
        $id = $this->tulisLog();

        $this->assertDitolakDatabase('Log aktivitas tidak boleh diubah', fn () => DB::table('tbl_log_aktivitas')
            ->where('id', $id)->update(['keterangan' => 'Diubah']));

        $this->assertSame('Uji append-only', DB::table('tbl_log_aktivitas')->where('id', $id)->value('keterangan'));
    }

    public function test_delete_log_ditolak(): void
    {
        $id = $this->tulisLog();

        $this->assertDitolakDatabase('Log aktivitas tidak boleh dihapus', fn () => DB::table('tbl_log_aktivitas')
            ->where('id', $id)->delete());

        $this->assertSame(1, DB::table('tbl_log_aktivitas')->where('id', $id)->count());
    }
}
