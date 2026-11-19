<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\CreatesSjiAuthTables;
use Tests\TestCase;

class KeuanganPayrollTest extends TestCase
{
    use CreatesSjiAuthTables, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('tbl_karyawan', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('nip');
            $table->string('nama');
            $table->string('kategori');
        });

        Schema::create('tbl_payroll', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('id_karyawan');
            $table->unsignedInteger('periode_bulan');
            $table->unsignedInteger('periode_tahun');
            $table->decimal('gaji_pokok', 12, 2);
            $table->decimal('total_lembur', 12, 2);
            $table->decimal('potongan_absen', 12, 2);
            $table->decimal('komponen_tunjangan', 12, 2)->default(0);
            $table->decimal('upah_backup', 12, 2)->default(0);
            $table->decimal('potongan_pinjaman', 12, 2)->default(0);
            $table->decimal('pph21', 12, 2)->default(0);
            $table->unsignedSmallInteger('hari_wajib')->nullable();
            $table->unsignedSmallInteger('hari_masuk')->nullable();
            $table->unsignedSmallInteger('hari_absen')->nullable();
            $table->unsignedInteger('id_klien_bpjs')->nullable();
            $table->string('bpjs_metode')->nullable();
            $table->decimal('bpjs_nilai', 12, 2)->nullable();
            $table->decimal('potongan_bpjs', 12, 2)->default(0);
            $table->decimal('total_gaji', 12, 2);
            $table->string('status_bayar');
            $table->date('tgl_bayar')->nullable();
        });

        DB::statement(<<<'SQL'
            CREATE VIEW v_slip_gaji_detail AS
            SELECT p.id, k.nip, k.nama, k.kategori,
                   p.periode_bulan, p.periode_tahun, p.gaji_pokok,
                   p.total_lembur, p.potongan_absen,
                   p.komponen_tunjangan, p.potongan_pinjaman, p.total_gaji,
                   p.status_bayar, p.tgl_bayar, p.hari_wajib, p.hari_masuk, p.hari_absen,
                   p.potongan_bpjs, p.bpjs_metode, p.bpjs_nilai, p.id_klien_bpjs, NULL AS nama_klien_bpjs,
                   p.upah_backup, p.pph21, NULL AS jenis_pekerjaan, 'TK/0' AS status_ptkp
            FROM tbl_payroll p
            JOIN tbl_karyawan k ON p.id_karyawan = k.id
            SQL);
    }

    public function test_payroll_lists_salary_components_and_provides_a_receipt(): void
    {
        $user = User::factory()->withRole(Role::Keuangan)->create();
        $employeeId = DB::table('tbl_karyawan')->insertGetId([
            'nip' => 'TST-PAY-001',
            'nama' => 'Karyawan Payroll',
            'kategori' => 'Internal',
        ]);
        $payrollId = DB::table('tbl_payroll')->insertGetId([
            'id_karyawan' => $employeeId,
            'periode_bulan' => 10,
            'periode_tahun' => 2026,
            'gaji_pokok' => 1200000,
            'total_lembur' => 50000,
            'potongan_absen' => 80000,
            'pph21' => 12345,
            'total_gaji' => 1157655,
            'status_bayar' => 'Belum Dibayar',
        ]);

        $this->actingAs($user)
            ->get(route('keuangan.payroll.index', ['bulan' => 10, 'tahun' => 2026]))
            ->assertOk()
            ->assertSeeText('Selamat datang, '.$user->namaTampilan())
            ->assertSee('Gaji Pokok')
            ->assertSeeInOrder(['NIP', 'Nama Karyawan', 'Jenis Pekerjaan', 'Hari (Wajib / Hadir / Absen)', 'Gaji Pokok', 'Tunjangan', 'Upah Back-up', 'Upah Lembur', 'Potongan Absensi', 'Potongan BPJS', 'Potongan Pinjaman', 'PPh 21', 'Total Gaji', 'Status', 'Aksi'])
            ->assertSee('Rp 12.345')
            ->assertSee('Rincian Gaji')
            ->assertDontSee('Lihat Struk')
            ->assertDontSee('Unduh PDF')
            ->assertDontSee('data-struk-cetak', false)
            ->assertSee('>Cair</button>', false)
            ->assertSee('Belum Dibayar')
            ->assertDontSee('Rp 300.000');

        $this->actingAs($user)
            ->withHeader('X-Requested-With', 'XMLHttpRequest')
            ->get(route('keuangan.payroll.struk', $payrollId))
            ->assertOk()
            ->assertSee('RINCIAN GAJI')
            ->assertDontSee('Dicetak')
            ->assertSee('Rp 1.200.000')
            ->assertSee('Rp 50.000')
            ->assertSee('Rp 80.000')
            ->assertSee('Rp 1.157.655')
            ->assertSee('Rp 12.345')
            ->assertSee('Tunjangan')
            ->assertSee('Upah Back-up')
            ->assertSee('Potongan BPJS')
            ->assertSee('PPh 21')
            ->assertSee('Total Gaji Bersih')
            ->assertDontSee('Rp 300.000');
    }
}
