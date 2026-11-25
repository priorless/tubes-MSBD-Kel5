<?php

namespace Tests\Feature\Hrd;

use App\Models\Role;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\MemakaiDatabaseSji;
use Tests\TestCase;

/**
 * Input ketidakhadiran oleh HRD lewat sp_input_ketidakhadiran (db_sji, transaksi di-rollback).
 */
class InputKetidakhadiranTest extends TestCase
{
    use MemakaiDatabaseSji;

    private const string AWALAN_TOLAK = 'Operasi ditolak oleh aturan database: ';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpDatabaseSji();
        $this->loginSebagai(Role::Hrd);
    }

    protected function tearDown(): void
    {
        $this->tearDownDatabaseSji();
        parent::tearDown();
    }

    /**
     * @param  array<string, mixed>  $timpa
     */
    private function kirim(array $timpa): TestResponse
    {
        // Form kini menerima nama/NIP yang diketik; karyawan uji dikirim lewat NIP-nya.
        if (isset($timpa['id_karyawan'])) {
            $timpa['karyawan'] = DB::table('tbl_karyawan')->where('id', $timpa['id_karyawan'])->value('nip');
            unset($timpa['id_karyawan']);
        }

        return $this->from(route('hrd.absensi'))->post(route('hrd.absensi.ketidakhadiran'), [
            'tgl' => $this->hariIniDb(-1),
            'status_kehadiran' => 'Izin',
            'keterangan' => 'Keperluan keluarga',
            ...$timpa,
        ]);
    }

    public function test_halaman_absensi_menampilkan_form_ketidakhadiran(): void
    {
        $this->get(route('hrd.absensi'))
            ->assertOk()
            ->assertSee('Catat Ketidakhadiran')
            ->assertSee(route('hrd.absensi.ketidakhadiran'), false);
    }

    public function test_staf_internal_dicatat_tanpa_penempatan(): void
    {
        $idKaryawan = $this->buatKaryawan('Internal');
        $tgl = $this->hariIniDb(-1);

        $this->kirim(['id_karyawan' => $idKaryawan, 'status_kehadiran' => 'Sakit'])
            ->assertRedirect(route('hrd.absensi', ['tanggal' => $tgl]))
            ->assertSessionHas('success');

        $absensi = DB::table('tbl_absensi')->where('id_karyawan', $idKaryawan)->where('tgl', $tgl)->first();
        $this->assertSame('Sakit', $absensi->status_kehadiran);
        $this->assertNull($absensi->id_penempatan);
        $this->assertNull($absensi->jam_masuk);
        $this->assertSame('Keperluan keluarga', $absensi->keterangan);
    }

    public function test_karyawan_lapangan_terhubung_ke_penempatan_pada_tanggal_itu(): void
    {
        $idKaryawan = $this->buatKaryawan('Lapangan');
        $idPenempatan = $this->buatPenempatan($idKaryawan, $this->buatKlien(), $this->hariIniDb(-10));

        $this->kirim(['id_karyawan' => $idKaryawan, 'status_kehadiran' => 'Alpha'])->assertSessionHas('success');

        $this->assertSame($idPenempatan, DB::table('tbl_absensi')->where('id_karyawan', $idKaryawan)->value('id_penempatan'));
    }

    public function test_tanggal_masa_depan_ditolak(): void
    {
        $this->kirim(['id_karyawan' => $this->buatKaryawan('Internal'), 'tgl' => $this->hariIniDb(1)])
            ->assertRedirect(route('hrd.absensi'))
            ->assertSessionHas('error', self::AWALAN_TOLAK.'Tanggal ketidakhadiran tidak boleh di masa depan');
    }

    public function test_tanggal_yang_sudah_ada_absensi_ditolak(): void
    {
        $idKaryawan = $this->buatKaryawan('Internal');
        $this->kirim(['id_karyawan' => $idKaryawan])->assertSessionHas('success');

        $this->kirim(['id_karyawan' => $idKaryawan, 'status_kehadiran' => 'Alpha'])
            ->assertSessionHas('error', self::AWALAN_TOLAK.'Karyawan sudah memiliki catatan absensi pada tanggal tersebut');
    }

    public function test_karyawan_lapangan_tanpa_penempatan_ditolak(): void
    {
        $this->kirim(['id_karyawan' => $this->buatKaryawan('Lapangan')])
            ->assertSessionHas('error', self::AWALAN_TOLAK.'Karyawan Lapangan tidak memiliki penempatan pada tanggal tersebut');
    }

    public function test_karyawan_nonaktif_ditolak(): void
    {
        $idKaryawan = $this->buatKaryawan('Internal');
        DB::table('tbl_karyawan')->where('id', $idKaryawan)->update(['status' => 'Nonaktif']);

        $this->kirim(['id_karyawan' => $idKaryawan])
            ->assertSessionHas('error', self::AWALAN_TOLAK.'Karyawan berstatus Nonaktif tidak dapat dicatat ketidakhadirannya');
    }

    public function test_status_selain_izin_sakit_alpha_ditolak_validasi(): void
    {
        $this->kirim(['id_karyawan' => $this->buatKaryawan('Internal'), 'status_kehadiran' => 'Hadir'])
            ->assertSessionHasErrors(['status_kehadiran' => 'Status ketidakhadiran hanya boleh Izin, Sakit, atau Alpha.']);
    }
}
