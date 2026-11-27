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

    public function test_filter_options_come_from_placed_clients_and_service_types(): void
    {
        $this->tambahKlienDanLayananLain();

        $this->actingAs($this->hrd())
            ->get(route('hrd.monitoring'))
            ->assertOk()
            ->assertSeeInOrder(['<option value="">Semua Klien</option>', '>PT Demo</option>', '>PT Lain</option>'], false)
            ->assertDontSee('PT Tanpa Penempatan')
            ->assertSeeInOrder(['<option value="">Semua Jenis Layanan</option>', '>Driver</option>', '>Satpam</option>'], false);
    }

    public function test_client_filter_uses_the_placement_of_each_report_including_backup_shifts(): void
    {
        $data = $this->tambahKlienDanLayananLain();
        $this->actingAs($this->hrd());

        $demo = $this->get(route('hrd.monitoring', ['klien' => 1]))->assertOk()->getContent();
        $this->assertSame([$this->idAndi, $this->idBudi], $this->kartuTenagaKerja($demo));
        $this->assertMatchesRegularExpression('/data-tenaga-kerja="'.$this->idAndi.'".*?2 laporan/s', $demo);

        $lain = $this->get(route('hrd.monitoring', ['klien' => $data['klien']]))->assertOk()->getContent();
        $this->assertSame([$this->idAndi, $data['citra'], $data['dodi']], $this->kartuTenagaKerja($lain));
        $this->assertMatchesRegularExpression('/data-tenaga-kerja="'.$this->idAndi.'".*?1 laporan/s', $lain);
    }

    public function test_service_and_client_filters_work_together_with_date_and_name(): void
    {
        $data = $this->tambahKlienDanLayananLain();
        $this->actingAs($this->hrd());

        $this->assertSame([$data['citra']], $this->kartuTenagaKerja(
            $this->get(route('hrd.monitoring', ['layanan' => $data['driver']]))->assertOk()->getContent()));

        $this->assertSame([$this->idAndi, $data['dodi']], $this->kartuTenagaKerja(
            $this->get(route('hrd.monitoring', ['klien' => $data['klien'], 'layanan' => $data['satpam']]))->assertOk()->getContent()));

        $this->assertSame([$data['dodi']], $this->kartuTenagaKerja(
            $this->get(route('hrd.monitoring', ['klien' => $data['klien'], 'layanan' => $data['satpam'], 'q' => 'Dodi', 'tanggal' => '2026-11-25']))->assertOk()->getContent()));

        $this->get(route('hrd.monitoring', ['klien' => 1, 'layanan' => $data['driver']]))
            ->assertOk()
            ->assertSeeText('Tidak ada laporan yang cocok dengan filter ini.');
    }

    public function test_detail_page_keeps_the_filters_and_shows_only_matching_reports(): void
    {
        $data = $this->tambahKlienDanLayananLain();
        $this->actingAs($this->hrd());
        $filter = ['klien' => $data['klien'], 'layanan' => $data['satpam']];

        $this->get(route('hrd.monitoring', $filter))
            ->assertOk()
            ->assertSee('href="'.e(route('hrd.monitoring', [...$filter, 'karyawan' => $this->idAndi])).'"', false);

        $this->get(route('hrd.monitoring', [...$filter, 'karyawan' => $this->idAndi]))
            ->assertOk()
            ->assertSeeText('Menggantikan shift Dodi')
            ->assertDontSeeText('Menjaga pintu utama')
            ->assertDontSeeText('Memeriksa area parkir')
            ->assertSee('href="'.e(route('hrd.monitoring', $filter)).'" class="btn-secondary">Kembali ke daftar tenaga kerja</a>', false)
            ->assertSee('<option value="'.$data['klien'].'" selected>PT Lain</option>', false);
    }

    public function test_unknown_client_or_service_is_rejected(): void
    {
        $this->actingAs($this->hrd())
            ->from(route('hrd.monitoring'))
            ->get(route('hrd.monitoring', ['klien' => 999, 'layanan' => 999]))
            ->assertRedirect(route('hrd.monitoring'))
            ->assertSessionHasErrors([
                'klien' => 'Klien yang dipilih tidak valid.',
                'layanan' => 'Jenis layanan yang dipilih tidak valid.',
            ]);
    }

    /**
     * PT Lain (klien kedua): Citra (Driver) dan Dodi (Satpam) ditempatkan di sana, dan Andi menggantikan shift
     * Dodi (absensi pada penempatan Dodi). PT Tanpa Penempatan tidak pernah punya penempatan.
     *
     * @return array{klien: int, driver: int, satpam: int, citra: int, dodi: int}
     */
    private function tambahKlienDanLayananLain(): array
    {
        $idKlien = (int) DB::table('tbl_klien')->insertGetId(['nama_perusahaan' => 'PT Lain']);
        DB::table('tbl_klien')->insert(['nama_perusahaan' => 'PT Tanpa Penempatan']);
        $idDriver = (int) DB::table('tbl_jenis_layanan')->insertGetId(['nama_layanan' => 'Driver']);
        $idSatpam = (int) DB::table('tbl_jenis_layanan')->where('nama_layanan', 'Satpam')->value('id');
        $idCitra = (int) DB::table('tbl_karyawan')->insertGetId(['nip' => 'SJI003', 'nama' => 'Citra Driver', 'id_jenis_layanan' => $idDriver]);
        $idDodi = (int) DB::table('tbl_karyawan')->insertGetId(['nip' => 'SJI004', 'nama' => 'Dodi Satpam', 'id_jenis_layanan' => $idSatpam]);
        DB::table('tbl_penempatan')->insert([
            ['id' => 103, 'id_karyawan' => $idCitra, 'id_klien' => $idKlien],
            ['id' => 104, 'id_karyawan' => $idDodi, 'id_klien' => $idKlien],
        ]);
        DB::table('tbl_absensi')->insert([['id' => 13, 'id_penempatan' => 103], ['id' => 14, 'id_penempatan' => 104], ['id' => 15, 'id_penempatan' => 104]]);

        $this->laporan(['id_absensi' => 13, 'id_karyawan' => $idCitra, 'jenis' => 'Perjalanan', 'keterangan' => 'Antar direksi', 'foto_utama' => 'x.jpg', 'waktu_lapor' => '2026-11-25 07:00:00']);
        $this->laporan(['id_absensi' => 14, 'id_karyawan' => $idDodi, 'periode_ke' => 1, 'keterangan' => 'Jaga gerbang', 'foto_utama' => 'y.jpg', 'waktu_lapor' => '2026-11-25 06:00:00']);
        $this->laporan(['id_absensi' => 15, 'id_karyawan' => $this->idAndi, 'periode_ke' => 1, 'keterangan' => 'Menggantikan shift Dodi', 'foto_utama' => 'z.jpg', 'waktu_lapor' => '2026-11-23 20:00:00']);

        return ['klien' => $idKlien, 'driver' => $idDriver, 'satpam' => $idSatpam, 'citra' => $idCitra, 'dodi' => $idDodi];
    }

    /**
     * @return list<int> id karyawan pada kartu tenaga kerja, diurutkan
     */
    private function kartuTenagaKerja(string $html): array
    {
        preg_match_all('/data-tenaga-kerja="(\d+)"/', $html, $cocok);
        $id = array_map('intval', $cocok[1]);
        sort($id);

        return $id;
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
