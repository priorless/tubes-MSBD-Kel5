<?php

namespace Tests\Feature\Hrd;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesSjiAuthTables;
use Tests\TestCase;

/**
 * Form "Catat Ketidakhadiran": karyawan diketik (nama/NIP) lalu dicocokkan persis ke satu karyawan sebelum
 * sp_input_ketidakhadiran dipanggil. Procedure MySQL tidak ada di SQLite, jadi panggilannya diperiksa lewat mock.
 */
class KetidakhadiranInputKaryawanTest extends TestCase
{
    use CreatesSjiAuthTables;

    private const string PANGGIL_SP = 'CALL sp_input_ketidakhadiran(?, ?, ?, ?)';

    private int $idAndi;

    private int $idBudi;

    protected function setUp(): void
    {
        parent::setUp();

        DB::connection()->getPdo()->sqliteCreateFunction('CURDATE', static fn (): string => '2026-11-25', 0);
        Schema::create('tbl_jenis_layanan', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('nama_layanan');
        });
        Schema::create('tbl_karyawan', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('nip');
            $table->string('nama');
            $table->string('kategori');
            $table->string('status')->default('Aktif');
        });

        $this->idAndi = (int) DB::table('tbl_karyawan')->insertGetId(['nip' => 'SJI001', 'nama' => 'Andi Lapangan', 'kategori' => 'Lapangan']);
        $this->idBudi = (int) DB::table('tbl_karyawan')->insertGetId(['nip' => 'SJI002', 'nama' => 'Budi Lapangan', 'kategori' => 'Lapangan']);
        DB::table('tbl_karyawan')->insert([
            ['nip' => 'SJI003', 'nama' => 'Citra Kembar', 'kategori' => 'Internal'],
            ['nip' => 'SJI004', 'nama' => 'Citra Kembar', 'kategori' => 'Lapangan'],
        ]);

        $this->actingAs(User::factory()->withRole(Role::Hrd)->create());
    }

    public function test_employee_field_is_a_text_input_with_name_and_nip_suggestions(): void
    {
        $this->get(route('hrd.absensi'))
            ->assertOk()
            ->assertSee('<input id="karyawan" name="karyawan" type="text" list="saran-karyawan-ketidakhadiran"', false)
            ->assertSee('<option value="Andi Lapangan (SJI001)">Lapangan</option>', false)
            ->assertSee('<option value="Budi Lapangan (SJI002)">Lapangan</option>', false)
            ->assertDontSee('name="id_karyawan"', false);
    }

    public function test_catat_link_prefills_the_employee_text(): void
    {
        $this->get(route('hrd.absensi', ['karyawan' => $this->idBudi]))
            ->assertOk()
            ->assertSee('value="Budi Lapangan (SJI002)"', false);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function matchingInputs(): array
    {
        return [
            'saran nama (NIP)' => ['Budi Lapangan (SJI002)', 'idBudi'],
            'NIP saja, huruf kecil' => ['  sji001 ', 'idAndi'],
            'nama lengkap unik' => ['andi   lapangan', 'idAndi'],
        ];
    }

    /**
     * SQLite tidak punya procedure: CALL gagal dengan QueryException yang membawa SQL dan binding persis
     * seperti yang dikirim ke sp_input_ketidakhadiran di MySQL. Penyimpanan nyata diuji InputKetidakhadiranTest.
     */
    #[DataProvider('matchingInputs')]
    public function test_typed_name_or_nip_is_sent_to_the_procedure_for_the_matching_employee(string $isian, string $properti): void
    {
        $this->withoutExceptionHandling();

        try {
            $this->post(route('hrd.absensi.ketidakhadiran'), $this->payload($isian));
            $this->fail('sp_input_ketidakhadiran tidak dipanggil.');
        } catch (QueryException $exception) {
            $this->assertSame(self::PANGGIL_SP, $exception->getSql());
            $this->assertSame([$this->{$properti}, '2026-11-24', 'Sakit', 'Surat dokter'], $exception->getBindings());
        }
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function rejectedInputs(): array
    {
        return [
            'tidak terdaftar' => ['Dodi Tidak Ada', 'Karyawan "Dodi Tidak Ada" tidak ditemukan. Ketik nama lengkap atau NIP yang terdaftar, atau pilih dari saran.'],
            'sebagian nama' => ['Andi', 'Karyawan "Andi" tidak ditemukan. Ketik nama lengkap atau NIP yang terdaftar, atau pilih dari saran.'],
            'NIP dalam kurung tidak terdaftar' => ['Andi Lapangan (SJI999)', 'Karyawan "Andi Lapangan (SJI999)" tidak ditemukan. Ketik nama lengkap atau NIP yang terdaftar, atau pilih dari saran.'],
            'nama dan NIP milik orang berbeda' => ['Andi Lapangan (SJI002)', 'Nama dan NIP tidak cocok: NIP SJI002 terdaftar atas nama Budi Lapangan.'],
            'nama dimiliki lebih dari satu karyawan' => ['Citra Kembar', 'Ada lebih dari satu karyawan bernama "Citra Kembar". Ketik NIP-nya atau pilih dari saran agar tidak tertukar.'],
            'kosong' => ['', 'Karyawan wajib diisi.'],
        ];
    }

    #[DataProvider('rejectedInputs')]
    public function test_unmatched_employee_text_is_rejected_without_calling_the_procedure(string $isian, string $pesan): void
    {
        // Bila procedure sempat dipanggil, SQLite melempar QueryException dan respons bukan redirect validasi.
        $this->from(route('hrd.absensi'))
            ->post(route('hrd.absensi.ketidakhadiran'), $this->payload($isian))
            ->assertRedirect(route('hrd.absensi'))
            ->assertSessionHasErrors(['karyawan' => $pesan])
            ->assertSessionHasInput('karyawan', $isian);
    }

    public function test_status_and_date_rules_are_unchanged(): void
    {
        $this->from(route('hrd.absensi'))
            ->post(route('hrd.absensi.ketidakhadiran'), [...$this->payload('SJI001'), 'status_kehadiran' => 'Hadir', 'tgl' => '24-11-2026'])
            ->assertSessionHasErrors([
                'status_kehadiran' => 'Status ketidakhadiran hanya boleh Izin, Sakit, atau Alpha.',
                'tgl' => 'Tanggal bukan tanggal yang valid.',
            ]);
    }

    /**
     * @return array<string, string>
     */
    private function payload(string $karyawan): array
    {
        return ['karyawan' => $karyawan, 'tgl' => '2026-11-24', 'status_kehadiran' => 'Sakit', 'keterangan' => 'Surat dokter'];
    }
}
