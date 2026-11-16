<?php

namespace Tests\Feature\Hrd;

use App\Models\Role;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\MemakaiDatabaseSji;
use Tests\TestCase;

/**
 * Status sertifikat otomatis (Tahap 7): EVENT ev_update_status_sertifikat, dan tampilan yang tetap
 * menghitung status dari tanggal walau event belum berjalan.
 */
class StatusSertifikatTest extends TestCase
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

    private function buatSertifikat(int $idKaryawan, string $nama, string $tglKadaluarsa): int
    {
        return DB::table('tbl_sertifikat')->insertGetId([
            'id_karyawan' => $idKaryawan,
            'nama_dokumen' => $nama,
            'tgl_terbit' => '2025-01-01',
            'tgl_kadaluarsa' => $tglKadaluarsa,
            'status' => 'Aktif',
        ]);
    }

    public function test_event_harian_terpasang_dan_aktif(): void
    {
        $event = DB::table('information_schema.EVENTS')
            ->where('EVENT_SCHEMA', DB::getDatabaseName())
            ->where('EVENT_NAME', 'ev_update_status_sertifikat')
            ->first();

        $this->assertNotNull($event);
        $this->assertSame('ENABLED', $event->STATUS);
        $this->assertSame('1', (string) $event->INTERVAL_VALUE);
        $this->assertSame('DAY', $event->INTERVAL_FIELD);
        $this->assertStringEndsWith('00:05:00', (string) $event->STARTS);
    }

    public function test_isi_event_menandai_sertifikat_yang_lewat_tanggal(): void
    {
        $idKaryawan = $this->buatKaryawan('Lapangan');
        $lewat = $this->buatSertifikat($idKaryawan, 'Dokumen Lewat TST', $this->hariIniDb(-1));
        $hariIni = $this->buatSertifikat($idKaryawan, 'Dokumen Hari Ini TST', $this->hariIniDb());

        $isiEvent = DB::table('information_schema.EVENTS')
            ->where('EVENT_SCHEMA', DB::getDatabaseName())
            ->where('EVENT_NAME', 'ev_update_status_sertifikat')
            ->value('EVENT_DEFINITION');
        DB::unprepared($isiEvent);

        $this->assertSame('Kadaluarsa', DB::table('tbl_sertifikat')->where('id', $lewat)->value('status'));
        $this->assertSame('Aktif', DB::table('tbl_sertifikat')->where('id', $hariIni)->value('status'), 'Kedaluwarsa hari ini belum lewat');
    }

    public function test_tampilan_menghitung_status_dari_tanggal_walau_event_belum_jalan(): void
    {
        $this->loginSebagai(Role::Hrd);
        $idKaryawan = $this->buatKaryawan('Lapangan');
        $this->buatSertifikat($idKaryawan, 'Dokumen Lewat TST', $this->hariIniDb(-3));

        $this->get(route('hrd.sertifikat.index', ['q' => 'Dokumen Lewat TST']))
            ->assertOk()
            ->assertSee('<span class="badge badge-danger">Kedaluwarsa</span>', false);
    }
}
