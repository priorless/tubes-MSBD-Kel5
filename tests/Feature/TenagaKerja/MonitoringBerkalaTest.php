<?php

namespace Tests\Feature\TenagaKerja;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\MemakaiAbsensiTenagaKerja;
use Tests\TestCase;

/**
 * Monitoring per jam Satpam & Cleaning Service: periode dari JAM MULAI SHIFT (08:00), satu laporan per periode,
 * maksimal 2 unggahan (utama + koreksi), dan berhenti setelah check-out.
 */
class MonitoringBerkalaTest extends TestCase
{
    use MemakaiAbsensiTenagaKerja;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAbsensiTenagaKerja();
        Storage::fake('local');
    }

    protected function tearDown(): void
    {
        $this->tearDownDatabaseSji();
        parent::tearDown();
    }

    public function test_laporan_dapat_dikirim_kapan_saja_tanpa_batasan_interval(): void
    {
        $idKaryawan = $this->satpamSudahCheckIn('07:50:00');

        $this->waktuServer('07:55:00');
        $this->kirimLaporan($idKaryawan)->assertSessionHas('sukses', 'Laporan aktivitas berhasil dikirim.');
        $this->waktuServer('08:01:00');
        $this->kirimLaporan($idKaryawan)->assertSessionHas('sukses', 'Laporan aktivitas berhasil dikirim.');

        $laporan = DB::table('tbl_laporan_aktivitas')->where('id_karyawan', $idKaryawan)->orderBy('periode_ke')->get();
        $this->assertSame([1, 2], $laporan->pluck('periode_ke')->map(fn ($ke) => (int) $ke)->all());
        $this->assertSame([$this->hariIniDb().' 07:55:00', $this->hariIniDb().' 08:01:00'], $laporan->pluck('waktu_lapor')->all());
        $this->assertNull($laporan->first()->periode_mulai);
    }

    public function test_koreksi_diterima_sekali_dan_unggahan_ketiga_ditolak(): void
    {
        $idKaryawan = $this->satpamSudahCheckIn();
        $this->waktuServer('09:05:00');
        $this->kirimLaporan($idKaryawan);
        $idLaporan = (int) DB::table('tbl_laporan_aktivitas')->where('id_karyawan', $idKaryawan)->value('id');

        $this->waktuServer('09:30:00');
        $this->kirimKoreksi($idKaryawan, $idLaporan)->assertSessionHas('sukses');
        $fotoKoreksi = DB::table('tbl_laporan_aktivitas')->where('id', $idLaporan)->value('foto_koreksi');

        $this->waktuServer('09:40:00');
        $this->kirimKoreksi($idKaryawan, $idLaporan)
            ->assertSessionHas('gagal', 'Foto laporan ini sudah dikoreksi (maksimal 2 unggahan). Kirim laporan baru bila perlu.');

        $this->assertDatabaseHas('tbl_laporan_aktivitas', ['id' => $idLaporan, 'jumlah_upload' => 2, 'foto_koreksi' => $fotoKoreksi]);
        Storage::disk('local')->assertExists($fotoKoreksi);
    }

    public function test_koreksi_foto_tetap_diterima_lebih_dari_satu_jam_kemudian_selama_sesi_berjalan(): void
    {
        $idKaryawan = $this->satpamSudahCheckIn();
        $this->waktuServer('09:05:00');
        $this->kirimLaporan($idKaryawan);
        $idLaporan = (int) DB::table('tbl_laporan_aktivitas')->where('id_karyawan', $idKaryawan)->value('id');

        $this->waktuServer('11:00:00');
        $this->kirimKoreksi($idKaryawan, $idLaporan)->assertSessionHas('sukses', 'Koreksi foto berhasil diunggah.');

        $this->assertDatabaseHas('tbl_laporan_aktivitas', ['id' => $idLaporan, 'jumlah_upload' => 2]);
    }

    public function test_setiap_periode_memiliki_penghitung_unggah_sendiri(): void
    {
        $idKaryawan = $this->satpamSudahCheckIn();
        $this->waktuServer('09:05:00');
        $this->kirimLaporan($idKaryawan);

        $this->waktuServer('10:05:00');
        $this->kirimLaporan($idKaryawan)->assertSessionHas('sukses');

        $this->assertSame([1, 2], DB::table('tbl_laporan_aktivitas')->where('id_karyawan', $idKaryawan)
            ->orderBy('periode_ke')->pluck('periode_ke')->map(fn ($ke) => (int) $ke)->all());
    }

    public function test_monitoring_berhenti_setelah_check_out(): void
    {
        $idKaryawan = $this->satpamSudahCheckIn();
        $this->waktuServer('09:05:00');
        $this->kirimLaporan($idKaryawan);
        $idLaporan = (int) DB::table('tbl_laporan_aktivitas')->where('id_karyawan', $idKaryawan)->value('id');
        $this->waktuServer('09:10:00');
        $this->kirimCheckOut($idKaryawan)->assertOk();

        $this->waktuServer('09:20:00');
        $this->kirimLaporan($idKaryawan)->assertSessionHas('gagal', 'Anda sudah check-out. Monitoring aktivitas untuk sesi ini sudah berhenti.');
        $this->kirimKoreksi($idKaryawan, $idLaporan)->assertSessionHas('gagal', 'Anda sudah check-out. Monitoring aktivitas untuk sesi ini sudah berhenti.');

        $this->assertDatabaseHas('tbl_laporan_aktivitas', ['id' => $idLaporan, 'jumlah_upload' => 1]);
    }

    public function test_laporan_tanpa_check_in_ditolak(): void
    {
        [$idKaryawan] = $this->karyawanLayanan('Cleaning Service');
        $this->waktuServer('09:05:00');

        $this->kirimLaporan($idKaryawan)
            ->assertSessionHas('gagal', 'Anda belum check-in. Laporan aktivitas hanya dapat dikirim selama sesi absensi berlangsung.');
    }

    public function test_foto_wajib_pada_laporan(): void
    {
        $idKaryawan = $this->satpamSudahCheckIn();
        $this->waktuServer('09:05:00');

        $this->actingAs($this->akunKaryawan($idKaryawan))
            ->post(route('tenaga-kerja.laporan.store'), ['keterangan' => 'Area lobi aman'])
            ->assertSessionHasErrors(['foto' => 'Foto bukti aktivitas wajib diunggah.']);
    }

    public function test_karyawan_lain_tidak_dapat_mengoreksi_atau_melihat_foto_laporan(): void
    {
        $idPemilik = $this->satpamSudahCheckIn();
        $this->waktuServer('09:05:00');
        $this->kirimLaporan($idPemilik);
        $idLaporan = (int) DB::table('tbl_laporan_aktivitas')->where('id_karyawan', $idPemilik)->value('id');
        [$idLain] = $this->karyawanLayanan('Satpam');
        $this->waktuServer('07:45:00');
        $this->kirimCheckIn($idLain)->assertOk();

        $this->waktuServer('09:10:00');
        $this->kirimKoreksi($idLain, $idLaporan)->assertSessionHas('gagal', 'Laporan tidak ditemukan.');
        $this->actingAs($this->akunKaryawan($idLain))
            ->get(route('absensi.laporan.foto', ['laporan' => $idLaporan, 'versi' => 'utama']))
            ->assertForbidden();

        $this->assertDatabaseHas('tbl_laporan_aktivitas', ['id' => $idLaporan, 'jumlah_upload' => 1]);
    }

    public function test_halaman_absensi_menampilkan_form_laporan_dan_laporan_terkirim_tanpa_periode(): void
    {
        $idKaryawan = $this->satpamSudahCheckIn();
        $this->waktuServer('09:05:00');
        $this->kirimLaporan($idKaryawan);

        $this->waktuServer('10:15:00');
        $this->actingAs($this->akunKaryawan($idKaryawan))
            ->get(route('tenaga-kerja.absensi'))
            ->assertOk()
            ->assertSeeInOrder(['Monitoring Aktivitas', 'Kirim Laporan', 'Laporan 1', 'Area lobi aman dan bersih', 'Koreksi Foto'], false)
            ->assertDontSee('kesempatan terakhir')
            ->assertDontSee('Akan Datang');
    }

    private function satpamSudahCheckIn(string $jam = '07:50:00'): int
    {
        [$idKaryawan] = $this->karyawanLayanan('Satpam');
        $this->waktuServer($jam);
        $this->kirimCheckIn($idKaryawan)->assertOk();

        return $idKaryawan;
    }
}
