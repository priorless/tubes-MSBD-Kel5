<?php

namespace Tests\Feature\Hrd;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Concerns\CreatesSjiAuthTables;
use Tests\TestCase;

class MonitoringTenagaKerjaTest extends TestCase
{
    use CreatesSjiAuthTables;

    private int $idAndi;

    private int $idBudi;

    private int $laporanAndi;

    private int $laporanAndiKedua;

    private int $laporanBudi;

    protected function setUp(): void
    {
        parent::setUp();

        config(['filesystems.disks.local.root' => sys_get_temp_dir().DIRECTORY_SEPARATOR.'sim-outsource-monitoring-'.bin2hex(random_bytes(6))]);
        Storage::fake('local');
        $this->travelTo('2026-11-25 10:00:00');
        $this->createTables();

        $idSatpam = (int) DB::table('tbl_jenis_layanan')->insertGetId(['nama_layanan' => 'Satpam']);
        DB::table('tbl_klien')->insert(['id' => 1, 'nama_perusahaan' => 'PT Demo']);
        $this->idAndi = (int) DB::table('tbl_karyawan')->insertGetId(['nip' => 'SJI001', 'nama' => 'Andi Satpam', 'id_jenis_layanan' => $idSatpam]);
        $this->idBudi = (int) DB::table('tbl_karyawan')->insertGetId(['nip' => 'SJI002', 'nama' => 'Budi Satpam', 'id_jenis_layanan' => $idSatpam]);
        DB::table('tbl_penempatan')->insert([
            ['id' => 101, 'id_karyawan' => $this->idAndi, 'id_klien' => 1],
            ['id' => 102, 'id_karyawan' => $this->idBudi, 'id_klien' => 1],
        ]);
        DB::table('tbl_absensi')->insert([['id' => 11, 'id_penempatan' => 101], ['id' => 12, 'id_penempatan' => 102]]);

        Storage::disk('local')->put('absensi/2026-11/laporan-andi.jpg', 'FOTO-ANDI');
        Storage::disk('local')->put('absensi/2026-11/laporan-andi-kedua.jpg', 'FOTO-ANDI-KEDUA');
        Storage::disk('local')->put('absensi/2026-11/laporan-budi-awal.jpg', 'FOTO-BUDI-AWAL');
        Storage::disk('local')->put('absensi/2026-11/laporan-budi-koreksi.jpg', 'FOTO-BUDI-KOREKSI');

        $this->laporanAndi = $this->laporan([
            'id_absensi' => 11, 'id_karyawan' => $this->idAndi, 'periode_ke' => 3,
            'periode_mulai' => '2026-11-24 10:00:00', 'periode_selesai' => '2026-11-24 11:00:00',
            'keterangan' => 'Menjaga pintu utama', 'foto_utama' => 'absensi/2026-11/laporan-andi.jpg', 'waktu_lapor' => '2026-11-24 10:10:00',
        ]);
        $this->laporanAndiKedua = $this->laporan([
            'id_absensi' => 11, 'id_karyawan' => $this->idAndi, 'periode_ke' => 4,
            'keterangan' => 'Memeriksa area parkir', 'foto_utama' => 'absensi/2026-11/laporan-andi-kedua.jpg',
            'waktu_lapor' => '2026-11-25 07:30:00',
        ]);
        $this->laporanBudi = $this->laporan([
            'id_absensi' => 12, 'id_karyawan' => $this->idBudi, 'periode_ke' => 1,
            'keterangan' => 'Patroli area parkir', 'foto_utama' => 'absensi/2026-11/laporan-budi-awal.jpg',
            'foto_koreksi' => 'absensi/2026-11/laporan-budi-koreksi.jpg', 'jumlah_upload' => 2,
            'waktu_lapor' => '2026-11-25 08:05:00', 'waktu_koreksi' => '2026-11-25 08:20:00',
        ]);
    }

    public function test_hrd_sees_one_group_card_per_employee_with_filtered_report_count(): void
    {
        $html = $this->actingAs($this->hrd())->get(route('hrd.monitoring'))->assertOk()->getContent();

        $this->assertSame(2, substr_count($html, 'data-tenaga-kerja='));
        $this->assertStringContainsString('Andi Satpam', $html);
        $this->assertStringContainsString('SJI001 · Satpam', $html);
        $this->assertStringContainsString('Tanggal monitoring: 25/11/2026', $html);
        $this->assertStringContainsString('2 laporan', $html);
        $this->assertStringContainsString('Lihat Semua Laporan', $html);
        $this->assertStringNotContainsString('Menjaga pintu utama', $html);

        $filtered = $this->actingAs($this->hrd())
            ->get(route('hrd.monitoring', ['tanggal' => '2026-11-24']))
            ->assertOk()
            ->getContent();

        $this->assertSame(1, substr_count($filtered, 'data-tenaga-kerja='));
        $this->assertStringContainsString('1 laporan', $filtered);
        $this->assertStringNotContainsString('Budi Satpam', $filtered);
    }

    public function test_hrd_can_open_all_filtered_reports_for_one_employee_and_return_to_list(): void
    {
        $this->actingAs($this->hrd())
            ->get(route('hrd.monitoring', ['karyawan' => $this->idAndi]))
            ->assertOk()
            ->assertSeeText('Andi Satpam')
            ->assertSeeText('2 laporan sesuai filter')
            ->assertSeeText('Menjaga pintu utama')
            ->assertSeeText('Memeriksa area parkir')
            ->assertSee('src="'.route('absensi.laporan.foto', ['laporan' => $this->laporanAndi, 'versi' => 'utama']).'"', false)
            ->assertSee('src="'.route('absensi.laporan.foto', ['laporan' => $this->laporanAndiKedua, 'versi' => 'utama']).'"', false)
            ->assertSee('id="laporanModal"', false)
            ->assertSee('href="'.route('hrd.monitoring').'" class="btn-secondary">Kembali ke daftar tenaga kerja</a>', false);

        $this->get(route('hrd.monitoring', ['karyawan' => $this->idAndi, 'tanggal' => '2026-11-24']))
            ->assertOk()
            ->assertSeeText('1 laporan sesuai filter')
            ->assertSeeText('Menjaga pintu utama')
            ->assertDontSeeText('Memeriksa area parkir');
    }

    public function test_report_photos_still_open_for_hrd(): void
    {
        $this->actingAs($this->hrd());

        $this->assertSame('FOTO-ANDI', $this->get(route('absensi.laporan.foto', ['laporan' => $this->laporanAndi, 'versi' => 'utama']))->assertOk()->streamedContent());
        $this->assertSame('FOTO-BUDI-KOREKSI', $this->get(route('absensi.laporan.foto', ['laporan' => $this->laporanBudi, 'versi' => 'koreksi']))->assertOk()->streamedContent());
        $this->assertSame('FOTO-BUDI-AWAL', $this->get(route('absensi.laporan.foto', ['laporan' => $this->laporanBudi, 'versi' => 'utama']))->assertOk()->streamedContent());
    }

    public function test_filters_empty_state_and_hrd_sidebar_are_preserved(): void
    {
        $this->actingAs($this->hrd())
            ->get(route('hrd.monitoring', ['q' => 'sji002']))
            ->assertOk()
            ->assertSeeText('Budi Satpam')
            ->assertDontSeeText('Andi Satpam');

        $html = $this->get(route('hrd.monitoring', ['tanggal' => '2026-11-24', 'q' => 'Budi']))
            ->assertOk()
            ->assertSeeText('Tidak ada laporan yang cocok dengan filter ini.')
            ->getContent();

        $this->assertStringNotContainsString('Hapus filter', $html);
        preg_match('/<nav[^>]*aria-label="Menu utama"[^>]*>(.*?)<\/nav>/s', $html, $nav);
        $this->assertNotEmpty($nav);
        $this->assertStringContainsString('Monitoring Tenaga Kerja', $nav[1]);

        DB::table('tbl_laporan_aktivitas')->delete();
        $this->get(route('hrd.monitoring'))
            ->assertOk()
            ->assertSeeText('Belum ada laporan aktivitas.');
    }

    public function test_new_report_appears_in_the_employee_group_and_empty_state_is_preserved(): void
    {
        DB::table('tbl_laporan_aktivitas')->delete();
        $this->actingAs($this->hrd())
            ->get(route('hrd.monitoring'))
            ->assertOk()
            ->assertSeeText('Belum ada laporan aktivitas.');

        $this->laporan([
            'id_absensi' => 11, 'id_karyawan' => $this->idAndi, 'periode_ke' => 5,
            'keterangan' => 'Laporan terbaru', 'foto_utama' => 'absensi/2026-11/laporan-andi.jpg',
            'waktu_lapor' => '2026-11-25 09:55:00',
        ]);

        $html = $this->get(route('hrd.monitoring'))->assertOk()->getContent();
        $this->assertSame(1, substr_count($html, 'data-tenaga-kerja='));
        $this->assertStringContainsString('Andi Satpam', $html);
        $this->assertStringContainsString('1 laporan', $html);
    }

    public function test_invalid_date_filter_is_rejected(): void
    {
        $this->actingAs($this->hrd())
            ->from(route('hrd.monitoring'))
            ->get(route('hrd.monitoring', ['tanggal' => '24-11-2026']))
            ->assertRedirect(route('hrd.monitoring'))
            ->assertSessionHasErrors(['tanggal' => 'Tanggal laporan tidak valid.']);
    }

    #[TestWith([Role::Operasional])]
    #[TestWith([Role::Keuangan])]
    #[TestWith([Role::Ceo])]
    #[TestWith([Role::KaryawanLapangan])]
    public function test_only_hrd_can_open_the_monitoring_page(string $role): void
    {
        $this->actingAs(User::factory()->withRole($role)->create())
            ->get(route('hrd.monitoring'))
            ->assertForbidden();
    }
    private function laporan(array $data): int
    {
        return (int) DB::table('tbl_laporan_aktivitas')->insertGetId([
            'jenis' => 'Berkala', 'jumlah_upload' => 1, ...$data,
        ]);
    }

    private function hrd(): User
    {
        return User::factory()->withRole(Role::Hrd)->create();
    }

    private function createTables(): void
    {

        Schema::create('tbl_jenis_layanan', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('nama_layanan');
        });
        Schema::create('tbl_klien', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('nama_perusahaan');
        });
        Schema::create('tbl_karyawan', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('nip');
            $table->string('nama');
            $table->unsignedInteger('id_jenis_layanan')->nullable();
        });
        Schema::create('tbl_penempatan', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('id_karyawan');
            $table->unsignedInteger('id_klien');
            $table->string('lokasi_kerja')->nullable();
        });
        Schema::create('tbl_absensi', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('id_penempatan')->nullable();
        });
        Schema::create('tbl_p2h', fn (Blueprint $table) => $table->increments('id'));
        Schema::create('tbl_laporan_aktivitas', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('id_absensi');
            $table->unsignedInteger('id_karyawan');
            $table->string('jenis');
            $table->unsignedSmallInteger('periode_ke')->nullable();
            $table->dateTime('periode_mulai')->nullable();
            $table->dateTime('periode_selesai')->nullable();
            $table->string('tahapan')->nullable();
            $table->string('keterangan', 500);
            $table->string('foto_utama');
            $table->string('foto_koreksi')->nullable();
            $table->unsignedTinyInteger('jumlah_upload');
            $table->dateTime('waktu_lapor');
            $table->dateTime('waktu_koreksi')->nullable();
            $table->timestamps();
        });
    }
}
