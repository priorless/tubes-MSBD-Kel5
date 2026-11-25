<?php

namespace Tests\Feature\Hrd;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesSjiAuthTables;
use Tests\TestCase;

/**
 * Foto check-in/check-out unggahan tenaga kerja terlihat oleh HRD di Rekap Absensi dan dibuka dari disk privat
 * lewat route absensi.foto, tanpa tertukar antar karyawan. Skema minimal SQLite; v_absensi_harian dibuat sebagai view.
 */
class FotoAbsensiHrdTest extends TestCase
{
    use CreatesSjiAuthTables;

    private const string HARI_INI = '2026-11-25';

    private int $idAndi;

    private int $idBudi;

    private int $absensiAndi;

    private int $absensiBudi;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        DB::connection()->getPdo()->sqliteCreateFunction('CURDATE', static fn (): string => self::HARI_INI, 0);
        $this->createTables();

        DB::table('tbl_klien')->insert(['id' => 1, 'nama_perusahaan' => 'PT Demo']);
        $this->idAndi = (int) DB::table('tbl_karyawan')->insertGetId(['nip' => 'SJI001', 'nama' => 'Andi Lapangan']);
        $this->idBudi = (int) DB::table('tbl_karyawan')->insertGetId(['nip' => 'SJI002', 'nama' => 'Budi Lapangan']);
        DB::table('tbl_penempatan')->insert([
            ['id' => 101, 'id_karyawan' => $this->idAndi, 'id_klien' => 1, 'tgl_mulai' => '2026-11-01'],
            ['id' => 102, 'id_karyawan' => $this->idBudi, 'id_klien' => 1, 'tgl_mulai' => '2026-11-01'],
        ]);

        Storage::disk('local')->put('absensi/2026-11/masuk-andi.jpg', 'FOTO-MASUK-ANDI');
        Storage::disk('local')->put('absensi/2026-11/keluar-andi.jpg', 'FOTO-KELUAR-ANDI');
        Storage::disk('local')->put('absensi/2026-11/masuk-budi.jpg', 'FOTO-MASUK-BUDI');
        $this->absensiAndi = $this->createAttendance($this->idAndi, 101, 'absensi/2026-11/masuk-andi.jpg', 'absensi/2026-11/keluar-andi.jpg');
        $this->absensiBudi = $this->createAttendance($this->idBudi, 102, 'absensi/2026-11/masuk-budi.jpg', null);
    }

    public function test_rekap_shows_the_selected_employee_photos_and_not_another_employee(): void
    {
        $this->actingAs($this->hrd())
            ->get(route('hrd.absensi', ['tab' => 'rekap', 'tanggal_awal' => '2026-11-01', 'tanggal_akhir' => self::HARI_INI, 'id_karyawan' => $this->idAndi]))
            ->assertOk()
            ->assertSeeText('Andi Lapangan')
            ->assertSee('src="'.$this->photoUrl($this->absensiAndi, 'masuk').'"', false)
            ->assertSee('src="'.$this->photoUrl($this->absensiAndi, 'keluar').'"', false)
            ->assertSee('alt="Foto check-in Andi Lapangan 20/11/2026"', false)
            ->assertDontSeeText('Budi Lapangan')
            ->assertDontSee($this->photoUrl($this->absensiBudi, 'masuk'), false);
    }

    public function test_rekap_without_employee_filter_pairs_each_photo_with_its_own_employee(): void
    {
        $this->actingAs($this->hrd())
            ->get(route('hrd.absensi', ['tab' => 'rekap', 'tanggal_awal' => '2026-11-01', 'tanggal_akhir' => self::HARI_INI]))
            ->assertOk()
            ->assertSeeInOrder(['Andi Lapangan', $this->photoUrl($this->absensiAndi, 'masuk'), 'Budi Lapangan', $this->photoUrl($this->absensiBudi, 'masuk')], false)
            ->assertDontSee($this->photoUrl($this->absensiBudi, 'keluar'), false);
    }

    public function test_hrd_opens_the_original_uploaded_file_for_each_employee(): void
    {
        $this->actingAs($this->hrd());

        $andi = $this->get($this->photoUrl($this->absensiAndi, 'masuk'))->assertOk()->assertHeader('Content-Type', 'image/jpeg');
        $budi = $this->get($this->photoUrl($this->absensiBudi, 'masuk'))->assertOk();

        $this->assertSame('FOTO-MASUK-ANDI', $andi->streamedContent());
        $this->assertSame('FOTO-MASUK-BUDI', $budi->streamedContent());
        $this->get($this->photoUrl($this->absensiBudi, 'keluar'))->assertNotFound();
    }

    public function test_an_employee_cannot_open_another_employee_photo(): void
    {
        $budi = User::factory()->withRole(Role::KaryawanLapangan)->create(['id_karyawan' => $this->idBudi]);

        $this->actingAs($budi)->get($this->photoUrl($this->absensiAndi, 'masuk'))->assertForbidden();
        $this->assertSame('FOTO-MASUK-BUDI', $this->actingAs($budi)->get($this->photoUrl($this->absensiBudi, 'masuk'))->assertOk()->streamedContent());
    }

    private function photoUrl(int $idAbsensi, string $jenis): string
    {
        return route('absensi.foto', ['absensi' => $idAbsensi, 'jenis' => $jenis]);
    }

    private function createAttendance(int $idKaryawan, int $idPenempatan, ?string $fotoMasuk, ?string $fotoKeluar): int
    {
        return (int) DB::table('tbl_absensi')->insertGetId([
            'id_karyawan' => $idKaryawan, 'id_penempatan' => $idPenempatan, 'tgl' => '2026-11-20',
            'jam_masuk' => '07:05:00', 'jam_keluar' => $fotoKeluar === null ? null : '15:02:00', 'status_kehadiran' => 'Hadir',
            'foto_masuk' => $fotoMasuk, 'foto_keluar' => $fotoKeluar,
        ]);
    }

    private function hrd(): User
    {
        return User::factory()->withRole(Role::Hrd)->create();
    }

    private function createTables(): void
    {
        Schema::create('tbl_klien', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('nama_perusahaan');
        });
        Schema::create('tbl_karyawan', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('nip');
            $table->string('nama');
        });
        Schema::create('tbl_penempatan', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('id_karyawan');
            $table->unsignedInteger('id_klien');
            $table->string('lokasi_kerja')->nullable();
            $table->date('tgl_mulai');
            $table->date('tgl_selesai')->nullable();
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
            $table->string('foto_masuk')->nullable();
            $table->string('foto_keluar')->nullable();
        });
        DB::statement(<<<'SQL'
            CREATE VIEW v_absensi_harian AS
            SELECT a.id AS id_absensi, a.id_karyawan, k.nip, k.nama, a.tgl, a.jam_masuk, a.jam_keluar, a.status_kehadiran,
                   a.menit_terlambat, a.jam_lembur, a.id_penempatan, kl.nama_perusahaan AS nama_klien, p.lokasi_kerja,
                   a.foto_masuk, a.foto_keluar
            FROM tbl_absensi a
            JOIN tbl_karyawan k ON k.id = a.id_karyawan
            LEFT JOIN tbl_penempatan p ON p.id = a.id_penempatan
            LEFT JOIN tbl_klien kl ON kl.id = p.id_klien
            SQL);
    }
}
