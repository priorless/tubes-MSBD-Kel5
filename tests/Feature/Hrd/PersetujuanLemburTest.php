<?php

namespace Tests\Feature\Hrd;

use App\Models\Role;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\MemakaiDatabaseSji;
use Tests\TestCase;

/**
 * Persetujuan lembur (Tahap 6): trigger mengisi status_lembur saat check-out, HRD memutuskan lewat
 * sp_putuskan_lembur. db_sji dalam transaksi yang di-rollback.
 */
class PersetujuanLemburTest extends TestCase
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
     * Absensi karyawan dengan penempatan jam pulang 16:00, check-in 08:00, lalu check-out pada $jamKeluar.
     */
    private function absensiDenganCheckOut(string $jamKeluar): int
    {
        $idKaryawan = $this->buatKaryawan('Lapangan');
        $idPenempatan = $this->buatPenempatan($idKaryawan, $this->buatKlien(), $this->hariIniDb(-30));
        $idAbsensi = $this->buatAbsensi($idKaryawan, $this->hariIniDb(-1), 'Hadir', [
            'id_penempatan' => $idPenempatan,
            'jam_masuk' => '08:00:00',
        ]);

        DB::table('tbl_absensi')->where('id', $idAbsensi)->update(['jam_keluar' => $jamKeluar]);

        return $idAbsensi;
    }

    private function absensi(int $id): object
    {
        return DB::table('tbl_absensi')->where('id', $id)->first();
    }

    public function test_check_out_melewati_jadwal_menjadi_menunggu(): void
    {
        $id = $this->absensiDenganCheckOut('19:10:00');

        $this->assertSame(3, (int) $this->absensi($id)->jam_lembur);
        $this->assertSame('Menunggu', $this->absensi($id)->status_lembur);
    }

    public function test_check_out_sesuai_jadwal_tidak_ada_lembur(): void
    {
        $id = $this->absensiDenganCheckOut('16:05:00');

        $this->assertSame(0, (int) $this->absensi($id)->jam_lembur);
        $this->assertSame('Tidak Ada', $this->absensi($id)->status_lembur);
    }

    public function test_halaman_absensi_menampilkan_lembur_menunggu(): void
    {
        $id = $this->absensiDenganCheckOut('19:10:00');

        $this->get(route('hrd.absensi'))
            ->assertOk()
            ->assertSee('Lembur Menunggu Persetujuan')
            ->assertSee(route('hrd.absensi.lembur', $id), false)
            ->assertSee('3 jam');
    }

    public function test_rekap_memisahkan_alpha_tercatat_dari_hari_tanpa_catatan_dan_mengikuti_filter(): void
    {
        $idKaryawan = $this->buatKaryawan();
        $idKlien = $this->buatKlien();
        $tanggalAwal = $this->hariIniDb(-3);
        $tanggalAkhir = $this->hariIniDb(-1);
        $idPenempatan = $this->buatPenempatan($idKaryawan, $idKlien, $tanggalAwal);
        $this->buatAbsensi($idKaryawan, $tanggalAwal, 'Alpha', ['id_penempatan' => $idPenempatan]);

        $this->get(route('hrd.absensi', [
            'tab' => 'rekap',
            'tanggal_awal' => $tanggalAwal,
            'tanggal_akhir' => $tanggalAkhir,
            'id_karyawan' => $idKaryawan,
            'id_klien' => $idKlien,
        ]))
            ->assertOk()
            ->assertSee('Rekap Absensi')
            ->assertSee('Alpha tercatat')
            ->assertSee('Tanpa catatan')
            ->assertSee('Karyawan Uji')
            ->assertSee('Alpha');
    }

    public function test_rekap_shift_malam_dikelompokkan_pada_tanggal_absensi(): void
    {
        $idKaryawan = $this->buatKaryawan();
        $idKlien = $this->buatKlien();
        $tanggalShift = $this->hariIniDb(-1);
        $idPenempatan = $this->buatPenempatan($idKaryawan, $idKlien, $this->hariIniDb(-10), null, [
            'jam_datang' => '22:00:00',
            'jam_pulang' => '06:00:00',
        ]);
        $idAbsensi = $this->buatAbsensi($idKaryawan, $tanggalShift, 'Hadir', [
            'id_penempatan' => $idPenempatan,
            'jam_masuk' => '22:15:00',
        ]);
        DB::table('tbl_absensi')->where('id', $idAbsensi)->update(['jam_keluar' => '06:00:00']);

        $this->get(route('hrd.absensi', [
            'tab' => 'rekap',
            'tanggal_awal' => $tanggalShift,
            'tanggal_akhir' => $tanggalShift,
            'id_karyawan' => $idKaryawan,
        ]))
            ->assertOk()
            ->assertSee('22:15')
            ->assertSee('06:00');
    }

    public function test_pdf_rekap_mengunduh_file_pdf(): void
    {
        $idKlien = $this->buatKlien();
        $idKaryawanSesuai = $this->buatKaryawan('Lapangan', ['nip' => 'TSTX-REKAP-1']);
        $idKaryawanLain = $this->buatKaryawan('Lapangan', ['nip' => 'TSTX-REKAP-2']);
        $tanggal = $this->hariIniDb(-1);
        $idPenempatan = $this->buatPenempatan($idKaryawanSesuai, $idKlien, $this->hariIniDb(-10));
        $this->buatPenempatan($idKaryawanLain, $idKlien, $this->hariIniDb(-10));
        $this->buatAbsensi($idKaryawanSesuai, $tanggal, 'Alpha', ['id_penempatan' => $idPenempatan]);
        $this->buatAbsensi($idKaryawanLain, $tanggal, 'Izin');

        $response = $this->get(route('hrd.absensi.rekap.pdf', [
            'tanggal_awal' => $tanggal,
            'tanggal_akhir' => $tanggal,
            'id_karyawan' => $idKaryawanSesuai,
            'id_klien' => $idKlien,
        ]));

        $response->assertDownload('rekap-absensi-'.$tanggal.'-'.$tanggal.'.pdf');
        $this->assertStringStartsWith('%PDF-', $response->getContent());
    }

    public function test_pdf_rekap_menerapkan_filter_karyawan_periode_dan_klien(): void
    {
        $idKlien = $this->buatKlien();
        $idKaryawanSesuai = $this->buatKaryawan('Lapangan', ['nip' => 'TSTX-REKAP-1']);
        $idKaryawanLain = $this->buatKaryawan('Lapangan', ['nip' => 'TSTX-REKAP-2']);
        $tanggal = $this->hariIniDb(-1);
        $idPenempatan = $this->buatPenempatan($idKaryawanSesuai, $idKlien, $this->hariIniDb(-10));
        $this->buatPenempatan($idKaryawanLain, $idKlien, $this->hariIniDb(-10));
        $this->buatAbsensi($idKaryawanSesuai, $tanggal, 'Alpha', ['id_penempatan' => $idPenempatan]);
        $this->buatAbsensi($idKaryawanLain, $tanggal, 'Izin');

        $pdf = \Mockery::mock(\Barryvdh\DomPDF\PDF::class);
        $pdf->shouldReceive('setPaper')->once()->with('a4', 'landscape')->andReturnSelf();
        $pdf->shouldReceive('setOption')->once()->with('defaultFont', 'DejaVu Sans')->andReturnSelf();
        $pdf->shouldReceive('download')->once()->with('rekap-absensi-'.$tanggal.'-'.$tanggal.'.pdf')->andReturn(response('mock-pdf'));
        Pdf::shouldReceive('loadView')
            ->once()
            ->with('hrd.absensi.pdf', \Mockery::on(fn (array $data): bool => $data['filter']['tanggal_awal'] === $tanggal
                && $data['filter']['tanggal_akhir'] === $tanggal
                && $data['filter']['id_karyawan'] === $idKaryawanSesuai
                && $data['filter']['id_klien'] === $idKlien
                && $data['rekap']->pluck('nip')->all() === ['TSTX-REKAP-1']
                && $data['karyawan']->nip === 'TSTX-REKAP-1'
                && $data['klien'] === 'Klien Uji'
            ))
            ->andReturn($pdf);

        $this->get(route('hrd.absensi.rekap.pdf', [
            'tanggal_awal' => $tanggal,
            'tanggal_akhir' => $tanggal,
            'id_karyawan' => $idKaryawanSesuai,
            'id_klien' => $idKlien,
        ]))->assertOk()->assertSee('mock-pdf');
    }

    public function test_role_lain_tidak_dapat_membuka_rekap_absensi(): void
    {
        $this->loginSebagai(Role::Keuangan);

        $this->get(route('hrd.absensi', ['tab' => 'rekap']))->assertForbidden();
        $this->get(route('hrd.absensi.rekap.pdf'))->assertForbidden();
    }

    public function test_hrd_menyetujui_dan_menolak_lembur(): void
    {
        $disetujui = $this->absensiDenganCheckOut('19:10:00');
        $ditolak = $this->absensiDenganCheckOut('18:30:00');

        $this->from(route('hrd.absensi'))
            ->post(route('hrd.absensi.lembur', $disetujui), ['keputusan' => 'Disetujui'])
            ->assertRedirect(route('hrd.absensi'))
            ->assertSessionHas('success');
        $this->post(route('hrd.absensi.lembur', $ditolak), ['keputusan' => 'Ditolak'])->assertSessionHas('success');

        $this->assertSame('Disetujui', $this->absensi($disetujui)->status_lembur);
        $this->assertSame('Ditolak', $this->absensi($ditolak)->status_lembur);
        $this->assertSame(3, (int) $this->absensi($disetujui)->jam_lembur, 'Keputusan tidak mengubah jam lembur');
    }

    public function test_lembur_yang_sudah_diputuskan_tidak_bisa_diputuskan_lagi(): void
    {
        $id = $this->absensiDenganCheckOut('19:10:00');
        $this->post(route('hrd.absensi.lembur', $id), ['keputusan' => 'Disetujui']);

        $this->from(route('hrd.absensi'))
            ->post(route('hrd.absensi.lembur', $id), ['keputusan' => 'Ditolak'])
            ->assertSessionHas('error', self::AWALAN_TOLAK.'Hanya lembur yang berstatus Menunggu yang dapat diputuskan');

        $this->assertSame('Disetujui', $this->absensi($id)->status_lembur);
    }

    public function test_absensi_tanpa_lembur_tidak_bisa_diputuskan(): void
    {
        $id = $this->absensiDenganCheckOut('16:05:00');

        $this->post(route('hrd.absensi.lembur', $id), ['keputusan' => 'Disetujui'])
            ->assertSessionHas('error', self::AWALAN_TOLAK.'Hanya lembur yang berstatus Menunggu yang dapat diputuskan');
    }

    public function test_keputusan_tidak_valid_ditolak_validasi(): void
    {
        $id = $this->absensiDenganCheckOut('19:10:00');

        $this->post(route('hrd.absensi.lembur', $id), ['keputusan' => 'Mungkin'])
            ->assertSessionHasErrors(['keputusan' => 'Keputusan lembur hanya boleh Disetujui atau Ditolak.']);
    }

    public function test_role_lain_tidak_boleh_memutuskan_lembur(): void
    {
        $id = $this->absensiDenganCheckOut('19:10:00');
        $this->loginSebagai(Role::Keuangan);

        $this->post(route('hrd.absensi.lembur', $id), ['keputusan' => 'Disetujui'])->assertForbidden();
    }
}
