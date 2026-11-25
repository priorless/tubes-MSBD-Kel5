<?php

namespace Tests\Feature\Hrd;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesSjiAuthTables;
use Tests\TestCase;

class AbsensiJamLemburHrdTest extends TestCase
{
    use CreatesSjiAuthTables;

    private const string TANGGAL_ABSENSI = '2026-11-20';

    private int $idAbsensi;

    private int $idKaryawan;

    private int $idHrd;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        DB::connection()->getPdo()->sqliteCreateFunction('CURDATE', static fn (): string => '2026-11-25', 0);
        $this->createTables();

        $idLayanan = (int) DB::table('tbl_jenis_layanan')->insertGetId(['nama_layanan' => 'Satpam']);
        $idKlien = (int) DB::table('tbl_klien')->insertGetId(['nama_perusahaan' => 'PT Demo']);
        $this->idKaryawan = (int) DB::table('tbl_karyawan')->insertGetId([
            'nip' => 'SJI001', 'nama' => 'Andi Lapangan', 'kategori' => 'Lapangan',
            'id_jenis_layanan' => $idLayanan, 'status' => 'Ditempatkan',
        ]);
        $idPenempatan = (int) DB::table('tbl_penempatan')->insertGetId([
            'id_karyawan' => $this->idKaryawan, 'id_klien' => $idKlien, 'lokasi_kerja' => 'Pos utama',
            'tgl_mulai' => '2026-11-01', 'status' => 'Aktif',
        ]);
        Storage::disk('local')->put('absensi/2026-11/masuk-andi.jpg', 'FOTO-MASUK-ANDI');
        Storage::disk('local')->put('absensi/2026-11/keluar-andi.jpg', 'FOTO-KELUAR-ANDI');
        $this->idAbsensi = (int) DB::table('tbl_absensi')->insertGetId([
            'id_karyawan' => $this->idKaryawan, 'id_penempatan' => $idPenempatan, 'tgl' => self::TANGGAL_ABSENSI,
            'jam_masuk' => '07:05:00', 'jam_keluar' => '18:45:00', 'status_kehadiran' => 'Hadir',
            'jam_lembur' => 2, 'foto_masuk' => 'absensi/2026-11/masuk-andi.jpg',
            'foto_keluar' => 'absensi/2026-11/keluar-andi.jpg',
        ]);
        $this->idHrd = (int) User::factory()->withRole(Role::Hrd)->create()->getKey();
    }

    public function test_harian_shows_absence_details_and_checkout_photo_can_be_opened(): void
    {
        $urlFoto = route('absensi.foto', ['absensi' => $this->idAbsensi, 'jenis' => 'keluar']);

        $this->actingAs(User::findOrFail($this->idHrd))
            ->get(route('hrd.absensi', ['tanggal' => self::TANGGAL_ABSENSI]))
            ->assertOk()
            ->assertSeeText('Andi Lapangan')
            ->assertSeeText('20/11/2026')
            ->assertSeeText('07:05')
            ->assertSeeText('18:45')
            ->assertSeeText('Lembur 2 jam')
            ->assertSee('href="'.$urlFoto.'" target="_blank"', false)
            ->assertSee('Lihat Detail Absensi')
            ->assertDontSee('Edit Jam Lembur');

        $this->actingAs(User::findOrFail($this->idHrd))
            ->get(route('hrd.absensi.detail', ['absensi' => $this->idAbsensi, 'tanggal' => self::TANGGAL_ABSENSI]))
            ->assertOk()
            ->assertSeeText('Andi Lapangan')
            ->assertSeeText('Jam masuk')
            ->assertSeeText('Jam checkout')
            ->assertSeeText('2 jam penuh')
            ->assertSee('href="'.$urlFoto.'" target="_blank"', false)
            ->assertSee('Edit Jam Lembur')
            ->assertSee('name="jam_lembur" min="0" step="1"', false);

        $this->actingAs(User::findOrFail($this->idHrd))
            ->get($urlFoto)
            ->assertOk()
            ->assertHeader('Content-Type', 'image/jpeg')
            ->assertStreamedContent('FOTO-KELUAR-ANDI');
    }

    public function test_hrd_correction_is_audited_survives_reload_and_updates_recap_value(): void
    {
        $this->actingAs(User::findOrFail($this->idHrd))
            ->patch(route('hrd.absensi.jam-lembur', [
                'absensi' => $this->idAbsensi, 'tanggal' => self::TANGGAL_ABSENSI, 'q' => 'Andi',
            ]), [
                'id_absensi_lembur' => $this->idAbsensi,
                'jam_lembur' => '3',
            ])
            ->assertRedirect(route('hrd.absensi.detail', ['absensi' => $this->idAbsensi, 'tanggal' => self::TANGGAL_ABSENSI, 'q' => 'Andi']))
            ->assertSessionHas('success', 'Jam lembur absensi berhasil diperbarui.');

        $this->assertDatabaseHas('tbl_absensi', [
            'id' => $this->idAbsensi, 'jam_masuk' => '07:05:00', 'jam_keluar' => '18:45:00',
            'jam_lembur' => 3, 'foto_masuk' => 'absensi/2026-11/masuk-andi.jpg',
            'foto_keluar' => 'absensi/2026-11/keluar-andi.jpg',
        ]);
        $this->assertDatabaseHas('tbl_log_aktivitas', [
            'id_user' => $this->idHrd, 'nama_tabel' => 'tbl_absensi', 'aksi' => 'UPDATE',
            'id_data' => $this->idAbsensi,
            'keterangan' => 'Koreksi jam lembur absensi 2026-11-20: 2 -> 3 jam.',
        ]);

        $this->actingAs(User::findOrFail($this->idHrd))
            ->get(route('hrd.absensi.detail', ['absensi' => $this->idAbsensi, 'tanggal' => self::TANGGAL_ABSENSI]))
            ->assertOk()
            ->assertSeeText('3 jam penuh');

        $this->actingAs(User::findOrFail($this->idHrd))
            ->get(route('hrd.absensi', [
                'tab' => 'rekap', 'tanggal_awal' => self::TANGGAL_ABSENSI, 'tanggal_akhir' => '2026-11-25',
            ]))
            ->assertOk()
            ->assertSeeText('3 jam');
    }

    public function test_fractional_overtime_is_rejected_and_non_hrd_roles_cannot_edit(): void
    {
        $hrd = User::findOrFail($this->idHrd);
        $this->actingAs($hrd)
            ->from(route('hrd.absensi.detail', ['absensi' => $this->idAbsensi, 'tanggal' => self::TANGGAL_ABSENSI]))
            ->patch(route('hrd.absensi.jam-lembur', ['absensi' => $this->idAbsensi, 'tanggal' => self::TANGGAL_ABSENSI]), [
                'id_absensi_lembur' => $this->idAbsensi,
                'jam_lembur' => '1.5',
            ])
            ->assertSessionHasErrors('jam_lembur');

        $this->assertDatabaseHas('tbl_absensi', ['id' => $this->idAbsensi, 'jam_lembur' => 2]);
        $this->assertDatabaseCount('tbl_log_aktivitas', 0);

        foreach ([Role::Operasional, Role::Keuangan, Role::Ceo, Role::KaryawanLapangan] as $role) {
            $pengguna = User::factory()->withRole($role)->create();
            $this->actingAs($pengguna)
                ->patch(route('hrd.absensi.jam-lembur', ['absensi' => $this->idAbsensi]), [
                    'id_absensi_lembur' => $this->idAbsensi,
                    'jam_lembur' => '4',
                ])
                ->assertForbidden();
        }

        $this->assertDatabaseHas('tbl_absensi', ['id' => $this->idAbsensi, 'jam_lembur' => 2]);
        $this->assertDatabaseCount('tbl_log_aktivitas', 0);
    }

    public function test_correction_closes_when_payroll_for_the_attendance_period_exists(): void
    {
        DB::table('tbl_payroll')->insert([
            'id_karyawan' => $this->idKaryawan,
            'periode_bulan' => 11,
            'periode_tahun' => 2026,
            'status_bayar' => 'Belum Dibayar',
        ]);

        $this->actingAs(User::findOrFail($this->idHrd))
            ->get(route('hrd.absensi', ['tanggal' => self::TANGGAL_ABSENSI]))
            ->assertOk()
            ->assertSeeText('Payroll periode ini sudah dibuat; koreksi ditutup.')
            ->assertDontSee('Edit Jam Lembur');

        $this->actingAs(User::findOrFail($this->idHrd))
            ->from(route('hrd.absensi.detail', ['absensi' => $this->idAbsensi, 'tanggal' => self::TANGGAL_ABSENSI]))
            ->patch(route('hrd.absensi.jam-lembur', ['absensi' => $this->idAbsensi, 'tanggal' => self::TANGGAL_ABSENSI]), [
                'id_absensi_lembur' => $this->idAbsensi,
                'jam_lembur' => '4',
            ])
            ->assertSessionHasErrors('jam_lembur');

        $this->assertDatabaseHas('tbl_absensi', ['id' => $this->idAbsensi, 'jam_lembur' => 2]);
        $this->assertDatabaseCount('tbl_log_aktivitas', 0);
    }
    public function test_checkout_correction_workflow_is_removed_without_removing_normal_checkout(): void
    {
        $this->assertFalse(Route::has('hrd.absensi.koreksi-check-out.index'));
        $this->assertFalse(Route::has('tenaga-kerja.koreksi-check-out.store'));
        $this->assertTrue(Route::has('tenaga-kerja.absensi.check-out'));
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
            $table->string('kategori');
            $table->unsignedInteger('id_jenis_layanan')->nullable();
            $table->string('status');
        });
        Schema::create('tbl_penempatan', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('id_karyawan');
            $table->unsignedInteger('id_klien');
            $table->string('lokasi_kerja')->nullable();
            $table->date('tgl_mulai');
            $table->date('tgl_selesai')->nullable();
            $table->string('status');
        });
        Schema::create('tbl_absensi', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('id_karyawan');
            $table->unsignedInteger('id_penempatan')->nullable();
            $table->date('tgl');
            $table->time('jam_masuk')->nullable();
            $table->time('jam_keluar')->nullable();
            $table->string('status_kehadiran');
            $table->unsignedInteger('menit_terlambat')->default(0);
            $table->unsignedInteger('jam_lembur')->default(0);
            $table->string('keterangan')->nullable();
            $table->string('foto_masuk')->nullable();
            $table->string('foto_keluar')->nullable();
        });
        Schema::create('tbl_payroll', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('id_karyawan');
            $table->unsignedInteger('periode_bulan');
            $table->unsignedInteger('periode_tahun');
            $table->string('status_bayar');
            $table->unique(['id_karyawan', 'periode_bulan', 'periode_tahun']);
        });
        Schema::create('tbl_log_aktivitas', function (Blueprint $table): void {
            $table->increments('id');
            $table->timestamp('waktu')->useCurrent();
            $table->unsignedInteger('id_user')->nullable();
            $table->string('nama_tabel', 50);
            $table->string('aksi', 10);
            $table->unsignedInteger('id_data')->nullable();
            $table->string('keterangan')->nullable();
        });
        DB::statement(<<<'SQL'
            CREATE VIEW v_absensi_harian AS
            SELECT a.id AS id_absensi, a.id_karyawan, k.nip, k.nama, jl.nama_layanan, a.tgl,
                   a.jam_masuk, a.jam_keluar, a.status_kehadiran, a.menit_terlambat, a.jam_lembur,
                   a.id_penempatan, kl.nama_perusahaan AS nama_klien, p.lokasi_kerja,
                   NULL AS jadwal_datang, NULL AS jadwal_pulang, 'Penempatan' AS sumber_jadwal,
                   0 AS lupa_check_out, a.foto_masuk, a.foto_keluar, a.keterangan
            FROM tbl_absensi a
            JOIN tbl_karyawan k ON k.id = a.id_karyawan
            LEFT JOIN tbl_jenis_layanan jl ON jl.id = k.id_jenis_layanan
            LEFT JOIN tbl_penempatan p ON p.id = a.id_penempatan
            LEFT JOIN tbl_klien kl ON kl.id = p.id_klien
            SQL);
    }
}