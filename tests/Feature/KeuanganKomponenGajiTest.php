<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Support\SqlKomponen;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\CreatesSjiAuthTables;
use Tests\TestCase;

class KeuanganKomponenGajiTest extends TestCase
{
    use CreatesSjiAuthTables, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
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
            $table->string('kategori');
            $table->string('status');
            $table->decimal('gaji_pokok', 12, 2)->nullable();
            $table->integer('id_jenis_layanan')->nullable();
        });
        Schema::create('tbl_penempatan', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('id_karyawan');
            $table->integer('id_klien');
            $table->date('tgl_mulai');
            $table->date('tgl_selesai')->nullable();
        });
        Schema::create('tbl_absensi', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('id_karyawan');
            $table->date('tgl');
            $table->string('status_kehadiran');
            $table->integer('jam_lembur')->default(0);
            $table->string('status_lembur')->default('Tidak Ada');
        });
        Schema::create('tbl_kontrak', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('id_penempatan');
            $table->decimal('gaji_pokok', 12, 2);
            $table->integer('hari_kerja_per_bulan')->default(25);
        });
        Schema::create('tbl_komponen_gaji', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('id_karyawan');
            $table->unsignedTinyInteger('periode_bulan');
            $table->unsignedSmallInteger('periode_tahun');
            $table->decimal('tunjangan', 12, 2)->default(0);
            $table->decimal('potongan_bpjs', 12, 2)->default(0);
            $table->decimal('potongan_pinjaman', 12, 2)->default(0);
            $table->timestamps();
            $table->unique(['id_karyawan', 'periode_bulan', 'periode_tahun']);
        });
        Schema::create('tbl_payroll', fn (Blueprint $table) => $table->increments('id'));
    }

    public function test_keuangan_can_save_and_update_manual_components_for_employee_period(): void
    {
        $user = User::factory()->withRole(Role::Keuangan)->create();
        $karyawan = $this->buatKaryawanLapangan('TST-KOMP-001', 'Karyawan Komponen');
        $idKaryawan = $karyawan['id'];
        $url = route('keuangan.komponen-gaji.store');
        $payload = [
            'bulan' => 11, 'tahun' => 2026, 'penempatan' => 'klien:'.$karyawan['id_klien'],
            'jenis_pekerjaan' => $karyawan['id_jenis_layanan'],
            'komponen' => [$idKaryawan => ['tunjangan' => 250000, 'potongan_bpjs' => 0, 'potongan_pinjaman' => 100000]],
        ];

        $this->actingAs($user)->put($url, $payload)->assertRedirect(route('keuangan.komponen-gaji.index', [
            'bulan' => 11, 'tahun' => 2026, 'penempatan' => 'klien:'.$karyawan['id_klien'],
            'jenis_pekerjaan' => $karyawan['id_jenis_layanan'],
        ]));
        $this->actingAs($user)->put($url, [...$payload, 'komponen' => [$idKaryawan => [
            'tunjangan' => 300000, 'potongan_bpjs' => 50000, 'potongan_pinjaman' => 75000,
        ]]]);

        $this->assertDatabaseCount('tbl_komponen_gaji', 1);
        $this->assertDatabaseHas('tbl_komponen_gaji', [
            'id_karyawan' => $idKaryawan, 'periode_bulan' => 11, 'periode_tahun' => 2026,
            'tunjangan' => 300000, 'potongan_bpjs' => 50000, 'potongan_pinjaman' => 75000,
        ]);
        $this->assertDatabaseCount('tbl_payroll', 0);
    }

    public function test_negative_amount_is_rejected(): void
    {
        $user = User::factory()->withRole(Role::Keuangan)->create();
        $karyawan = $this->buatKaryawanLapangan('TST-KOMP-002', 'Karyawan Validasi');
        $idKaryawan = $karyawan['id'];
        $filter = ['bulan' => 11, 'tahun' => 2026, 'penempatan' => 'klien:'.$karyawan['id_klien']];

        $this->actingAs($user)->from(route('beranda'))->put(route('keuangan.komponen-gaji.store'), [
            ...$filter,
            'komponen' => [$idKaryawan => ['tunjangan' => -1, 'potongan_bpjs' => 0, 'potongan_pinjaman' => 0]],
        ])->assertRedirect(route('beranda'))->assertSessionHasErrors('komponen.'.$idKaryawan.'.tunjangan');
        $this->actingAs($user)->from(route('beranda'))->put(route('keuangan.komponen-gaji.store'), [
            ...$filter,
            'komponen' => [$idKaryawan => ['tunjangan' => 'bukan angka', 'potongan_bpjs' => 0, 'potongan_pinjaman' => 0]],
        ])->assertRedirect(route('beranda'))->assertSessionHasErrors('komponen.'.$idKaryawan.'.tunjangan');

        $this->assertDatabaseCount('tbl_komponen_gaji', 0);
    }

    public function test_role_other_than_finance_cannot_open_or_save_components(): void
    {
        $user = User::factory()->withRole(Role::Operasional)->create();

        $this->actingAs($user)->get(route('keuangan.komponen-gaji.index'))->assertForbidden();
        $this->actingAs($user)->put(route('keuangan.komponen-gaji.store'), [])->assertForbidden();
    }

    public function test_page_shows_period_filter_and_automatic_values_as_read_only_for_finance_role(): void
    {
        DB::connection()->getPdo()->sqliteCreateFunction('fn_hitung_potongan_absen', fn ($gaji, $hari, $absen) => $hari > 0 ? round(($gaji / $hari) * $absen, 2) : 0, 3);
        DB::connection()->getPdo()->sqliteCreateFunction('fn_hitung_lembur', fn ($jam) => $jam * 25000, 1);
        DB::connection()->getPdo()->sqliteCreateFunction('fn_hari_penempatan', fn () => 25, 4);
        $user = User::factory()->withRole(Role::Keuangan)->create();
        $karyawan = $this->buatKaryawanLapangan('TST-KOMP-003', 'Karyawan Otomatis');
        $idKaryawan = $karyawan['id'];
        DB::table('tbl_kontrak')->insert([
            'id_penempatan' => $karyawan['id_penempatan'], 'gaji_pokok' => 5000000, 'hari_kerja_per_bulan' => 25,
        ]);
        DB::table('tbl_komponen_gaji')->insert([
            'id_karyawan' => $idKaryawan, 'periode_bulan' => 11, 'periode_tahun' => 2026,
            'tunjangan' => 100000, 'potongan_bpjs' => 25000, 'potongan_pinjaman' => 50000,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $response = $this->actingAs($user)->withSession(['success' => 'Selamat datang, Admin Keuangan!'])
            ->get(route('keuangan.komponen-gaji.index', [
                'bulan' => 11, 'tahun' => 2026, 'penempatan' => 'klien:'.$karyawan['id_klien'],
                'jenis_pekerjaan' => $karyawan['id_jenis_layanan'],
            ]));

        $response->assertOk()
            ->assertSee('Periode Bulan')
            ->assertSee('Periode Tahun')
            ->assertSee('Penempatan')
            ->assertSee('Eksternal (Semua Klien)')
            ->assertSee('Jenis Pekerjaan')
            ->assertSee('Satpam')
            ->assertSee('Karyawan Otomatis')
            ->assertSee('Rp 4.200.000')
            ->assertSee('Aturan nominal belum tersedia')
            ->assertSee('konfigurasi persentase belum tersedia')
            ->assertSee('komponen['.$idKaryawan.'][tunjangan]', false)
            ->assertSee('value="100000"', false)
            ->assertSee('value="25000"', false)
            ->assertSee('value="50000"', false)
            ->assertDontSee('name="gaji_pokok"', false)
            ->assertDontSee('>Internal</option>', false)
            ->assertSeeTextInOrder(['NIP', 'Nama Karyawan', 'Jenis Pekerjaan', 'Gaji Pokok', 'Tunjangan', 'Upah Back-up', 'Upah Lembur', 'Potongan Absensi', 'Potongan BPJS', 'Potongan Pinjaman / Koperasi']);

        $this->assertLessThan(
            strpos($response->getContent(), 'Payroll'),
            strpos($response->getContent(), 'Komponen Gaji'),
        );
        $this->assertSame(1, substr_count($response->getContent(), 'Selamat datang, Admin Keuangan!'));
    }

    public function test_payroll_sql_migration_has_component_lookup_and_payroll_snapshot_fields(): void
    {
        $statements = SqlKomponen::blokTahap('sql/Komponen/14_komponen_gaji.sql', 1);

        $this->assertCount(3, $statements);
        $this->assertStringContainsString('FROM tbl_komponen_gaji', $statements[1]);
        $this->assertStringContainsString('komponen_tunjangan, potongan_bpjs, potongan_pinjaman', $statements[1]);
        $this->assertStringContainsString('CREATE OR REPLACE VIEW v_slip_gaji_detail', $statements[2]);
    }

    public function test_client_filter_uses_period_overlap_and_does_not_duplicate_employees(): void
    {
        DB::connection()->getPdo()->sqliteCreateFunction('fn_hari_penempatan', fn () => 25, 4);
        DB::connection()->getPdo()->sqliteCreateFunction('fn_hitung_potongan_absen', fn ($gaji, $hari, $absen) => $hari > 0 ? round(($gaji / $hari) * $absen, 2) : 0, 3);
        DB::connection()->getPdo()->sqliteCreateFunction('fn_hitung_lembur', fn ($jam) => $jam * 25000, 1);
        $user = User::factory()->withRole(Role::Keuangan)->create();
        $idKlien = DB::table('tbl_klien')->insertGetId(['nama_perusahaan' => 'Klien Terpilih']);
        $idKlienLain = DB::table('tbl_klien')->insertGetId(['nama_perusahaan' => 'Klien Lain']);
        $serviceId = DB::table('tbl_jenis_layanan')->insertGetId(['nama_layanan' => 'Driver']);
        $idKaryawan = DB::table('tbl_karyawan')->insertGetId([
            'nip' => 'TST-KLIEN-001', 'nama' => 'Karyawan Multi Penempatan', 'kategori' => 'Lapangan',
            'status' => 'Ditempatkan', 'id_jenis_layanan' => $serviceId,
        ]);
        $idPenempatanPertama = DB::table('tbl_penempatan')->insertGetId([
            'id_karyawan' => $idKaryawan, 'id_klien' => $idKlien, 'tgl_mulai' => '2026-11-01', 'tgl_selesai' => null,
        ]);
        DB::table('tbl_penempatan')->insert([
            'id_karyawan' => $idKaryawan, 'id_klien' => $idKlien, 'tgl_mulai' => '2026-11-10', 'tgl_selesai' => null,
        ]);
        DB::table('tbl_kontrak')->insert(['id_penempatan' => $idPenempatanPertama, 'gaji_pokok' => 4000000, 'hari_kerja_per_bulan' => 25]);
        $idKaryawanLain = DB::table('tbl_karyawan')->insertGetId([
            'nip' => 'TST-KLIEN-002', 'nama' => 'Karyawan Klien Lain', 'kategori' => 'Lapangan',
            'status' => 'Ditempatkan', 'id_jenis_layanan' => $serviceId,
        ]);
        DB::table('tbl_penempatan')->insert([
            'id_karyawan' => $idKaryawanLain, 'id_klien' => $idKlienLain, 'tgl_mulai' => '2026-11-01', 'tgl_selesai' => null,
        ]);

        $response = $this->actingAs($user)->get(route('keuangan.komponen-gaji.index', [
            'bulan' => 11, 'tahun' => 2026, 'penempatan' => 'klien:'.$idKlien,
        ]));

        $response->assertOk()->assertSee('Karyawan Multi Penempatan')->assertDontSee('Karyawan Klien Lain');
        $this->assertSame(1, substr_count($response->getContent(), 'TST-KLIEN-001'));

        $responseEksternal = $this->actingAs($user)->get(route('keuangan.komponen-gaji.index', [
            'bulan' => 11, 'tahun' => 2026, 'penempatan' => 'eksternal',
        ]));

        $responseEksternal->assertOk()->assertSee('Karyawan Multi Penempatan')->assertSee('Karyawan Klien Lain');
        $this->assertSame(1, substr_count($responseEksternal->getContent(), 'TST-KLIEN-001'));
    }

    /** @return array{id: int, id_klien: int, id_jenis_layanan: int, id_penempatan: int} */
    private function buatKaryawanLapangan(string $nip, string $nama): array
    {
        $idJenis = DB::table('tbl_jenis_layanan')->where('nama_layanan', 'Satpam')->value('id');
        $idJenis ??= DB::table('tbl_jenis_layanan')->insertGetId(['nama_layanan' => 'Satpam']);
        $idKlien = DB::table('tbl_klien')->insertGetId(['nama_perusahaan' => 'PT Contoh']);
        $idKaryawan = DB::table('tbl_karyawan')->insertGetId([
            'nip' => $nip, 'nama' => $nama, 'kategori' => 'Lapangan', 'status' => 'Ditempatkan', 'id_jenis_layanan' => $idJenis,
        ]);
        $idPenempatan = DB::table('tbl_penempatan')->insertGetId([
            'id_karyawan' => $idKaryawan, 'id_klien' => $idKlien, 'tgl_mulai' => '2026-11-01', 'tgl_selesai' => null,
        ]);

        return ['id' => $idKaryawan, 'id_klien' => $idKlien, 'id_jenis_layanan' => $idJenis, 'id_penempatan' => $idPenempatan];
    }
}
