<?php

namespace Tests\Feature\Hrd;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Concerns\CreatesSjiAuthTables;
use Tests\TestCase;

/**
 * Gaji Pokok di form Data Karyawan HRD. Lapangan: satu sumber dengan Komponen Gaji Keuangan dan payroll
 * (tbl_komponen_gaji.gaji_pokok, berlaku sampai diubah). Internal lama: tetap tbl_karyawan.gaji_pokok.
 * Skema minimal SQLite; pemakaian oleh sp_generate_payroll_bulanan diuji Keuangan\GajiPokokKomponenTest (MySQL).
 */
class GajiPokokKaryawanHrdTest extends TestCase
{
    use CreatesSjiAuthTables;

    private int $idSatpam;

    private User $hrd;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-11-25 10:00:00');
        $this->createTables();
        $this->idSatpam = (int) DB::table('tbl_jenis_layanan')->insertGetId(['nama_layanan' => 'Satpam']);
        $this->hrd = User::factory()->withRole(Role::Hrd)->create();
        $this->actingAs($this->hrd);
    }

    public function test_add_form_shows_rupiah_salary_input(): void
    {
        $this->get(route('hrd.karyawan.create'))
            ->assertOk()
            ->assertSeeInOrder(['Gaji Pokok', '<span class="text-sm text-gray-600">Rp</span>', 'name="gaji_pokok"', 'data-rupiah-pratinjau'], false)
            ->assertSeeText('berlaku mulai bulan tanggal masuk');
    }

    public function test_new_employee_salary_is_stored_once_in_komponen_gaji_from_the_join_month(): void
    {
        $this->post(route('hrd.karyawan.store'), [...$this->dataKaryawan('TST-001', '1111111111111111'), 'tgl_masuk' => '2026-10-15', 'gaji_pokok' => '4500000'])
            ->assertRedirect(route('hrd.karyawan.index'))
            ->assertSessionHasNoErrors();

        $idKaryawan = (int) DB::table('tbl_karyawan')->where('nip', 'TST-001')->value('id');
        $this->assertDatabaseHas('tbl_karyawan', ['id' => $idKaryawan, 'kategori' => 'Lapangan', 'gaji_pokok' => null]);
        $this->assertSame(
            [['periode_bulan' => 10, 'periode_tahun' => 2026, 'gaji_pokok' => 4500000.0, 'tunjangan' => 0.0, 'potongan_pinjaman' => 0.0]],
            $this->komponen($idKaryawan),
        );
    }

    public function test_new_employee_without_salary_creates_no_komponen_row(): void
    {
        $this->post(route('hrd.karyawan.store'), $this->dataKaryawan('TST-001', '1111111111111111'))
            ->assertRedirect(route('hrd.karyawan.index'));

        $this->assertDatabaseCount('tbl_komponen_gaji', 0);
    }

    #[TestWith(['0', 'Gaji pokok harus lebih dari Rp0.'])]
    #[TestWith(['-1000', 'Gaji pokok harus lebih dari Rp0.'])]
    #[TestWith(['empat juta', 'Gaji pokok harus berupa angka.'])]
    #[TestWith(['4500000.555', 'Gaji pokok maksimal dua angka di belakang koma.'])]
    public function test_invalid_salary_is_rejected_without_saving(string $gaji, string $pesan): void
    {
        $this->from(route('hrd.karyawan.create'))
            ->post(route('hrd.karyawan.store'), [...$this->dataKaryawan('TST-001', '1111111111111111'), 'gaji_pokok' => $gaji])
            ->assertRedirect(route('hrd.karyawan.create'))
            ->assertSessionHasErrors(['gaji_pokok' => $pesan]);

        $this->assertDatabaseCount('tbl_karyawan', 0);
        $this->assertDatabaseCount('tbl_komponen_gaji', 0);
    }

    public function test_edit_shows_the_salary_currently_in_force_and_saves_a_change_from_this_month(): void
    {
        $idAndi = $this->buatLapangan('TST-001', '1111111111111111');
        $idBudi = $this->buatLapangan('TST-002', '2222222222222222');
        $this->simpanKomponen($idAndi, 9, 2026, 4000000);
        $this->simpanKomponen($idBudi, 9, 2026, 3900000);

        $this->get(route('hrd.karyawan.edit', $idAndi))
            ->assertOk()
            ->assertSee('value="4000000"', false)
            ->assertSeeText('Berlaku sejak September 2026.')
            ->assertSeeText('Perubahan berlaku mulai periode November 2026');

        $this->put(route('hrd.karyawan.update', $idAndi), [...$this->dataKaryawan('TST-001', '1111111111111111'), 'gaji_pokok' => '4750000'])
            ->assertRedirect(route('hrd.karyawan.index'))
            ->assertSessionHas('success', 'Data karyawan berhasil diperbarui.');

        $this->assertSame([
            ['periode_bulan' => 9, 'periode_tahun' => 2026, 'gaji_pokok' => 4000000.0, 'tunjangan' => 0.0, 'potongan_pinjaman' => 0.0],
            ['periode_bulan' => 11, 'periode_tahun' => 2026, 'gaji_pokok' => 4750000.0, 'tunjangan' => 0.0, 'potongan_pinjaman' => 0.0],
        ], $this->komponen($idAndi));
        $this->assertCount(1, $this->komponen($idBudi), 'Gaji karyawan lain tidak boleh ikut berubah.');
        $this->assertDatabaseHas('tbl_karyawan', ['id' => $idAndi, 'gaji_pokok' => null]);

        $this->get(route('hrd.karyawan.edit', $idAndi))->assertSee('value="4750000"', false)->assertSeeText('Berlaku sejak November 2026.');
    }

    public function test_unchanged_or_empty_salary_on_edit_writes_nothing(): void
    {
        $idAndi = $this->buatLapangan('TST-001', '1111111111111111');
        $this->simpanKomponen($idAndi, 9, 2026, 4000000);

        foreach (['4000000.00', ''] as $gaji) {
            $this->put(route('hrd.karyawan.update', $idAndi), [...$this->dataKaryawan('TST-001', '1111111111111111'), 'gaji_pokok' => $gaji])
                ->assertSessionHasNoErrors();
        }

        $this->assertCount(1, $this->komponen($idAndi));
    }

    public function test_edit_keeps_keuangan_allowances_and_returning_to_the_previous_salary_clears_this_month(): void
    {
        $idAndi = $this->buatLapangan('TST-001', '1111111111111111');
        $this->simpanKomponen($idAndi, 9, 2026, 4000000);
        DB::table('tbl_komponen_gaji')->insert([
            'id_karyawan' => $idAndi, 'periode_bulan' => 11, 'periode_tahun' => 2026,
            'gaji_pokok' => 4500000, 'tunjangan' => 200000, 'potongan_pinjaman' => 50000,
        ]);

        $this->put(route('hrd.karyawan.update', $idAndi), [...$this->dataKaryawan('TST-001', '1111111111111111'), 'gaji_pokok' => '4000000'])
            ->assertSessionHasNoErrors();

        // Sama dengan gaji sebelumnya -> NULL (ikut September), seperti aturan Komponen Gaji Keuangan.
        $this->assertSame(
            ['periode_bulan' => 11, 'periode_tahun' => 2026, 'gaji_pokok' => null, 'tunjangan' => 200000.0, 'potongan_pinjaman' => 50000.0],
            $this->komponen($idAndi)[1],
        );
    }

    public function test_salary_set_by_hrd_is_the_one_keuangan_sees_in_komponen_gaji(): void
    {
        DB::connection()->getPdo()->sqliteCreateFunction('fn_hari_penempatan', fn (): int => 30, 4);
        DB::connection()->getPdo()->sqliteCreateFunction('fn_hitung_potongan_absen', fn ($gaji, $hari, $absen) => $hari > 0 ? round(($gaji / $hari) * $absen, 2) : 0, 3);
        $idAndi = $this->buatLapangan('TST-001', '1111111111111111');
        $idKlien = (int) DB::table('tbl_klien')->insertGetId(['nama_perusahaan' => 'PT Contoh']);
        $idPenempatan = (int) DB::table('tbl_penempatan')->insertGetId([
            'id_karyawan' => $idAndi, 'id_klien' => $idKlien, 'tgl_mulai' => '2026-11-01', 'status' => 'Aktif',
        ]);
        DB::table('tbl_kontrak')->insert(['id_penempatan' => $idPenempatan, 'gaji_pokok' => 4000000, 'hari_kerja_per_bulan' => 25]);

        $this->put(route('hrd.karyawan.update', $idAndi), [...$this->dataKaryawan('TST-001', '1111111111111111'), 'gaji_pokok' => '4800000'])
            ->assertSessionHasNoErrors();

        $this->actingAs(User::factory()->withRole(Role::Keuangan)->create())
            ->get(route('keuangan.komponen-gaji.index', ['bulan' => 11, 'tahun' => 2026, 'penempatan' => 'klien:'.$idKlien]))
            ->assertOk()
            ->assertSee('name="komponen['.$idAndi.'][gaji_pokok]"', false)
            ->assertSee('value="4800000"', false)
            ->assertSeeText('Diubah mulai November 2026');
    }

    public function test_internal_employee_salary_stays_on_the_employee_record(): void
    {
        $idInternal = (int) DB::table('tbl_karyawan')->insertGetId([
            ...$this->dataKaryawan('TST-INT', '3333333333333333'), 'kategori' => 'Internal', 'id_jenis_layanan' => null,
            'gaji_pokok' => 5000000, 'status' => 'Aktif',
        ]);

        $this->get(route('hrd.karyawan.edit', $idInternal))->assertOk()->assertSee('value="5000000"', false)->assertSeeText('Wajib untuk karyawan Internal.');

        $this->put(route('hrd.karyawan.update', $idInternal), [...$this->dataKaryawan('TST-INT', '3333333333333333'), 'gaji_pokok' => '5500000'])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('tbl_karyawan', ['id' => $idInternal, 'kategori' => 'Internal', 'gaji_pokok' => 5500000]);
        $this->assertDatabaseCount('tbl_komponen_gaji', 0);
    }

    public function test_field_salary_input_is_disabled_and_ignored_until_the_komponen_column_is_migrated(): void
    {
        Schema::table('tbl_komponen_gaji', fn (Blueprint $table) => $table->dropColumn('gaji_pokok'));

        $this->get(route('hrd.karyawan.create'))
            ->assertOk()
            ->assertSee('database belum dimigrasi', false)
            ->assertDontSee('name="gaji_pokok"', false);

        $this->post(route('hrd.karyawan.store'), [...$this->dataKaryawan('TST-001', '1111111111111111'), 'gaji_pokok' => '4500000'])
            ->assertRedirect(route('hrd.karyawan.index'));

        $this->assertDatabaseHas('tbl_karyawan', ['nip' => 'TST-001', 'gaji_pokok' => null]);
        $this->assertDatabaseCount('tbl_komponen_gaji', 0);
    }

    /**
     * @return list<array{periode_bulan: int, periode_tahun: int, gaji_pokok: float|null, tunjangan: float, potongan_pinjaman: float}>
     */
    private function komponen(int $idKaryawan): array
    {
        return DB::table('tbl_komponen_gaji')->where('id_karyawan', $idKaryawan)
            ->orderBy('periode_tahun')->orderBy('periode_bulan')
            ->get()
            ->map(static fn (object $baris): array => [
                'periode_bulan' => (int) $baris->periode_bulan,
                'periode_tahun' => (int) $baris->periode_tahun,
                'gaji_pokok' => $baris->gaji_pokok === null ? null : (float) $baris->gaji_pokok,
                'tunjangan' => (float) $baris->tunjangan,
                'potongan_pinjaman' => (float) $baris->potongan_pinjaman,
            ])
            ->all();
    }

    private function simpanKomponen(int $idKaryawan, int $bulan, int $tahun, float $gaji): void
    {
        DB::table('tbl_komponen_gaji')->insert([
            'id_karyawan' => $idKaryawan, 'periode_bulan' => $bulan, 'periode_tahun' => $tahun, 'gaji_pokok' => $gaji,
        ]);
    }

    private function buatLapangan(string $nip, string $noKtp): int
    {
        return (int) DB::table('tbl_karyawan')->insertGetId([...$this->dataKaryawan($nip, $noKtp), 'kategori' => 'Lapangan', 'status' => 'Tersedia']);
    }

    /**
     * @return array<string, mixed>
     */
    private function dataKaryawan(string $nip, string $noKtp): array
    {
        return [
            'nip' => $nip, 'nama' => 'Karyawan '.$nip, 'id_jenis_layanan' => $this->idSatpam, 'no_ktp' => $noKtp,
            'tgl_lahir' => '1995-05-05', 'jenis_kelamin' => 'L', 'no_telp' => '081234567890', 'tgl_masuk' => '2026-11-01',
        ];
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
            $table->string('bpjs_metode')->nullable();
            $table->decimal('bpjs_nilai', 12, 2)->nullable();
        });
        Schema::create('tbl_karyawan', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('nip')->unique();
            $table->string('nama');
            $table->string('kategori');
            $table->unsignedInteger('id_jenis_layanan')->nullable();
            $table->decimal('gaji_pokok', 12, 2)->nullable();
            $table->string('no_ktp')->unique();
            $table->string('tempat_lahir')->nullable();
            $table->date('tgl_lahir');
            $table->string('jenis_kelamin');
            $table->string('status_pernikahan')->nullable();
            $table->string('nama_ibu_kandung')->nullable();
            $table->string('jenis_jaminan_kesehatan')->nullable();
            $table->text('alamat')->nullable();
            $table->string('no_telp');
            $table->string('email')->nullable()->unique();
            $table->string('foto')->nullable();
            $table->date('tgl_masuk');
            $table->string('status')->default('Tersedia');
        });
        Schema::create('tbl_penempatan', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('id_karyawan');
            $table->unsignedInteger('id_klien')->nullable();
            $table->date('tgl_mulai')->nullable();
            $table->date('tgl_selesai')->nullable();
            $table->string('status');
        });
        Schema::create('tbl_absensi', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('id_karyawan');
            $table->date('tgl');
            $table->time('jam_masuk')->nullable();
            $table->time('jam_keluar')->nullable();
            $table->string('status_kehadiran');
            $table->integer('jam_lembur')->default(0);
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
            $table->decimal('gaji_pokok', 12, 2)->nullable();
            $table->decimal('tunjangan', 12, 2)->default(0);
            $table->decimal('potongan_pinjaman', 12, 2)->default(0);
            $table->timestamps();
            $table->unique(['id_karyawan', 'periode_bulan', 'periode_tahun']);
        });
    }
}
