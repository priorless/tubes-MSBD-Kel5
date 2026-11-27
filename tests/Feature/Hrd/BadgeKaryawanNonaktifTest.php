<?php

namespace Tests\Feature\Hrd;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\CreatesSjiAuthTables;
use Tests\TestCase;

/**
 * Pengajuan Menunggu milik karyawan yang sudah Nonaktif tetap tampil dan bisa diproses, dengan badge
 * "Karyawan Nonaktif" di daftar persetujuan HRD (izin/cuti dan koreksi check-out). Skema minimal SQLite.
 */
class BadgeKaryawanNonaktifTest extends TestCase
{
    use CreatesSjiAuthTables;

    private const string BADGE = '>Karyawan Nonaktif</span>';

    private int $idAktif;

    private int $idNonaktif;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createTables();
        $this->idAktif = (int) DB::table('tbl_karyawan')->insertGetId(['nip' => 'SJI001', 'nama' => 'Andi Aktif', 'status' => 'Ditempatkan']);
        $this->idNonaktif = (int) DB::table('tbl_karyawan')->insertGetId(['nip' => 'SJI002', 'nama' => 'Budi Keluar', 'status' => 'Nonaktif']);
        $this->actingAs(User::factory()->withRole(Role::Hrd)->create());
    }

    public function test_leave_requests_list_marks_only_the_inactive_employee(): void
    {
        foreach ([$this->idAktif, $this->idNonaktif] as $idKaryawan) {
            DB::table('tbl_pengajuan_ketidakhadiran')->insert([
                'id_karyawan' => $idKaryawan, 'jenis' => 'Sakit', 'tgl_mulai' => '2026-11-20', 'tgl_selesai' => '2026-11-21',
                'alasan' => 'Demam', 'status' => 'Menunggu', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $html = $this->get(route('hrd.ketidakhadiran.index'))->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, self::BADGE));
        $this->assertBadgeBelongsTo($html, 'Budi Keluar', 'Andi Aktif');
    }

    public function test_check_out_corrections_list_marks_only_the_inactive_employee(): void
    {
        foreach ([[1, $this->idAktif], [2, $this->idNonaktif]] as [$idAbsensi, $idKaryawan]) {
            DB::table('tbl_absensi')->insert(['id' => $idAbsensi, 'id_karyawan' => $idKaryawan, 'tgl' => '2026-11-20', 'jam_masuk' => '08:00:00']);
            DB::table('tbl_koreksi_check_out')->insert([
                'id_absensi' => $idAbsensi, 'jam_keluar' => '17:00:00', 'alasan' => 'Lupa absen', 'status' => 'Menunggu',
                'diajukan_oleh' => 1, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $html = $this->get(route('hrd.koreksi-check-out.index'))->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, self::BADGE));
        $this->assertBadgeBelongsTo($html, 'Budi Keluar', 'Andi Aktif');
    }

    /** Badge berada di baris karyawan Nonaktif, bukan di baris karyawan yang masih aktif. */
    private function assertBadgeBelongsTo(string $html, string $namaNonaktif, string $namaAktif): void
    {
        preg_match_all('/<tr\b[^>]*>.*?<\/tr>/s', $html, $baris);
        $barisNonaktif = collect($baris[0])->first(fn (string $tr): bool => str_contains($tr, $namaNonaktif));
        $barisAktif = collect($baris[0])->first(fn (string $tr): bool => str_contains($tr, $namaAktif));

        $this->assertNotNull($barisNonaktif);
        $this->assertNotNull($barisAktif);
        $this->assertStringContainsString(self::BADGE, $barisNonaktif);
        $this->assertStringNotContainsString(self::BADGE, $barisAktif);
    }

    private function createTables(): void
    {
        Schema::create('tbl_karyawan', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('nip');
            $table->string('nama');
            $table->string('status');
        });
        Schema::create('tbl_absensi', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('id_karyawan');
            $table->date('tgl');
            $table->time('jam_masuk')->nullable();
            $table->time('jam_keluar')->nullable();
        });
        Schema::create('tbl_pengajuan_ketidakhadiran', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('id_karyawan');
            $table->string('jenis');
            $table->date('tgl_mulai');
            $table->date('tgl_selesai');
            $table->string('alasan', 500);
            $table->string('file_bukti')->nullable();
            $table->string('status')->default('Menunggu');
            $table->string('catatan_keputusan', 500)->nullable();
            $table->unsignedInteger('diproses_oleh')->nullable();
            $table->timestamp('diproses_pada')->nullable();
            $table->timestamps();
        });
        Schema::create('tbl_koreksi_check_out', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('id_absensi');
            $table->time('jam_keluar');
            $table->string('alasan', 500);
            $table->string('status')->default('Menunggu');
            $table->string('catatan_keputusan', 500)->nullable();
            $table->unsignedInteger('diajukan_oleh');
            $table->unsignedInteger('diproses_oleh')->nullable();
            $table->timestamp('diproses_pada')->nullable();
            $table->timestamps();
        });
    }
}
