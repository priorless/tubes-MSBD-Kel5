<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PDOException;
use Tests\Concerns\CreatesSjiAuthTables;
use Tests\TestCase;

class OperasionalFeatureTest extends TestCase
{
    use CreatesSjiAuthTables;

    /**
     * Semua route Operasional wajib login sebagai role Operasional.
     */
    private function loginOperasional(int $idUser = 56): void
    {
        $this->actingAs(User::factory()->withRole(Role::Operasional)->create(['id' => $idUser]));
    }

    public function test_client_form_reports_required_fields_in_indonesian(): void
    {
        $this->loginOperasional();

        $response = $this->from(route('operasional.klien.create'))
            ->post(route('operasional.klien.store'), []);

        $response->assertRedirect(route('operasional.klien.create'));
        $response->assertSessionHasErrors([
            'kode_klien',
            'nama_perusahaan',
            'alamat',
            'pic_nama',
            'pic_telp',
            'status',
        ]);
        $response->assertSessionHas('errors', fn ($errors): bool => $errors
            ->getBag('default')
            ->first('kode_klien') === 'Kode klien wajib diisi.');
    }

    public function test_penempatan_calls_database_procedure_with_logged_in_user_id(): void
    {
        $this->createReferenceTables();
        $this->loginOperasional(56);
        DB::table('tbl_karyawan')->insert(['id' => 12]);
        DB::table('tbl_klien')->insert(['id' => 34]);
        $connection = DB::connection();

        DB::shouldReceive('connection')->andReturn($connection)->byDefault();
        DB::shouldReceive('statement')
            ->once()
            ->with('CALL sp_assign_penempatan(?, ?, ?, ?, ?, ?)', [12, 34, '2026-11-15', null, 'Bandung', 56])
            ->andReturn(true);

        $response = $this->post(route('operasional.penempatan.store'), [
            'id_karyawan' => 12,
            'id_klien' => 34,
            'tgl_mulai' => '2026-11-15',
            'tgl_selesai' => '',
            'lokasi' => 'Bandung',
        ]);

        $response->assertRedirect(route('operasional.monitoring'));
        $response->assertSessionHas('success', 'Penempatan karyawan berhasil dibuat.');
    }

    public function test_database_signal_message_is_shown_as_a_friendly_notification(): void
    {
        $this->createReferenceTables();
        $this->loginOperasional(56);
        DB::table('tbl_karyawan')->insert(['id' => 12]);
        DB::table('tbl_klien')->insert(['id' => 34]);
        $connection = DB::connection();

        $databaseError = new PDOException('Staf internal tidak dapat ditempatkan ke klien');
        $databaseError->errorInfo = ['45000', 1644, 'Staf internal tidak dapat ditempatkan ke klien'];
        $queryException = new QueryException(
            'mysql',
            'CALL sp_assign_penempatan(?, ?, ?, ?, ?, ?)',
            [],
            $databaseError,
        );

        DB::shouldReceive('connection')->andReturn($connection)->byDefault();
        DB::shouldReceive('statement')->once()->andThrow($queryException);

        $response = $this->from(route('operasional.penempatan.create'))
            ->post(route('operasional.penempatan.store'), [
                'id_karyawan' => 12,
                'id_klien' => 34,
                'tgl_mulai' => '2026-11-15',
                'lokasi' => 'Bandung',
            ]);

        $response->assertRedirect(route('operasional.penempatan.create'));
        $response->assertSessionHas('error', 'Operasi ditolak oleh aturan database: Staf internal tidak dapat ditempatkan ke klien');
    }

    public function test_monitoring_highlights_only_contracts_with_less_than_thirty_days_remaining(): void
    {
        $penempatan = new LengthAwarePaginator([
            (object) [
                'id_penempatan' => 1,
                'nip' => 'SJI001',
                'nama' => 'Karyawan Satu',
                'nama_layanan' => 'Keamanan',
                'nama_perusahaan' => 'Klien Satu',
                'lokasi_kerja' => 'Jakarta',
                'tgl_mulai' => '2026-01-01',
                'tgl_selesai' => '2026-11-29',
                'sisa_hari_kontrak' => 29,
            ],
            (object) [
                'id_penempatan' => 2,
                'nip' => 'SJI002',
                'nama' => 'Karyawan Dua',
                'nama_layanan' => 'Keamanan',
                'nama_perusahaan' => 'Klien Dua',
                'lokasi_kerja' => 'Surabaya',
                'tgl_mulai' => '2026-01-01',
                'tgl_selesai' => '2026-11-30',
                'sisa_hari_kontrak' => 30,
            ],
            (object) [
                'id_penempatan' => 3,
                'nip' => 'SJI003',
                'nama' => 'Karyawan Tiga',
                'nama_layanan' => 'Keamanan',
                'nama_perusahaan' => 'Klien Tiga',
                'lokasi_kerja' => 'Bandung',
                'tgl_mulai' => '2026-01-01',
                'tgl_selesai' => null,
                'sisa_hari_kontrak' => '9999',
            ],
        ], 3, 15);

        $html = view('operasional.monitoring.index', ['penempatan' => $penempatan])->render();

        $this->assertSame(1, substr_count($html, 'class="table-danger"'));
        $this->assertStringContainsString('<span class="badge badge-danger">29 hari</span>', $html);
        $this->assertStringContainsString('<span class="badge badge-success">30 hari</span>', $html);
        $this->assertStringContainsString('<span class="badge badge-secondary">Tanpa batas akhir</span>', $html);
    }

    private function createReferenceTables(): void
    {
        Schema::create('tbl_karyawan', function (Blueprint $table): void {
            $table->unsignedInteger('id')->primary();
        });

        Schema::create('tbl_klien', function (Blueprint $table): void {
            $table->unsignedInteger('id')->primary();
        });
    }
}
