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

    public function test_periode_dihitung_dari_jam_mulai_shift_bukan_jam_check_in(): void
    {
        $idKaryawan = $this->satpamSudahCheckIn('08:20:00');

        $this->waktuServer('09:10:00');
        $this->kirimLaporan($idKaryawan)->assertSessionHas('sukses', 'Laporan aktivitas berhasil dikirim.');

        $this->assertDatabaseHas('tbl_laporan_aktivitas', [
            'id_karyawan' => $idKaryawan,
            'jenis' => 'Berkala',
            'periode_ke' => 1,
            'periode_mulai' => $this->hariIniDb().' 09:00:00',
            'periode_selesai' => $this->hariIniDb().' 10:00:00',
            'jumlah_upload' => 1,
        ]);
    }

    public function test_unggah_di_tengah_periode_tetap_masuk_periode_tersebut(): void
    {
        $idKaryawan = $this->satpamSudahCheckIn();

        $this->waktuServer('10:35:00');
        $this->kirimLaporan($idKaryawan)->assertSessionHas('sukses');

        $laporan = DB::table('tbl_laporan_aktivitas')->where('id_karyawan', $idKaryawan)->first();
        $this->assertSame(2, (int) $laporan->periode_ke);
        $this->assertSame($this->hariIniDb().' 10:35:00', $laporan->waktu_lapor);
    }

    public function test_sebelum_periode_pertama_dibuka_laporan_ditolak(): void
    {
        $idKaryawan = $this->satpamSudahCheckIn();

        $this->waktuServer('08:59:59');
        $this->kirimLaporan($idKaryawan)->assertSessionHas('gagal', 'Belum ada periode laporan yang dibuka. Laporan 1 dibuka pukul 09:00.');

        $this->assertDatabaseMissing('tbl_laporan_aktivitas', ['id_karyawan' => $idKaryawan]);
    }

    public function test_laporan_kedua_pada_periode_sama_bukan_laporan_baru(): void
    {
        $idKaryawan = $this->satpamSudahCheckIn();
        $this->waktuServer('09:05:00');
        $this->kirimLaporan($idKaryawan);

        $this->waktuServer('09:20:00');
        $this->kirimLaporan($idKaryawan)->assertSessionHas('gagal', 'Laporan 1 sudah dikirim. Gunakan tombol Koreksi bila foto perlu diganti.');

        $this->assertSame(1, DB::table('tbl_laporan_aktivitas')->where('id_karyawan', $idKaryawan)->count());
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
            ->assertSessionHas('gagal', 'Batas unggahan laporan ini sudah habis (maksimal 2 kali). Foto tidak dapat diganti lagi.');

        $this->assertDatabaseHas('tbl_laporan_aktivitas', ['id' => $idLaporan, 'jumlah_upload' => 2, 'foto_koreksi' => $fotoKoreksi]);
        Storage::disk('local')->assertExists($fotoKoreksi);
    }

    public function test_periode_yang_berakhir_tidak_menerima_unggahan(): void
    {
        $idKaryawan = $this->satpamSudahCheckIn();
        $this->waktuServer('09:05:00');
        $this->kirimLaporan($idKaryawan);
        $idLaporan = (int) DB::table('tbl_laporan_aktivitas')->where('id_karyawan', $idKaryawan)->value('id');

        $this->waktuServer('10:00:00');
        $this->kirimKoreksi($idKaryawan, $idLaporan)->assertSessionHas('gagal', 'Periode laporan 1 sudah ditutup. Laporan tidak dapat dikoreksi.');

        $this->assertDatabaseHas('tbl_laporan_aktivitas', ['id' => $idLaporan, 'jumlah_upload' => 1, 'foto_koreksi' => null]);
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

    public function test_halaman_absensi_menampilkan_periode_status_dan_sisa_unggah(): void
    {
        $idKaryawan = $this->satpamSudahCheckIn();
        $this->waktuServer('09:05:00');
        $this->kirimLaporan($idKaryawan);

        $this->waktuServer('10:15:00');
        $this->actingAs($this->akunKaryawan($idKaryawan))
            ->get(route('tenaga-kerja.absensi'))
            ->assertOk()
            ->assertSeeInOrder(['Laporan 1', '09:00', 'Ditutup', 'Area lobi aman dan bersih', 'Laporan 2', '10:00', 'Dibuka', 'Kirim Laporan 2', '2 dari 2', 'Laporan 3', 'Akan Datang'], false);
    }

    private function satpamSudahCheckIn(string $jam = '07:50:00'): int
    {
        [$idKaryawan] = $this->karyawanLayanan('Satpam');
        $this->waktuServer($jam);
        $this->kirimCheckIn($idKaryawan)->assertOk();

        return $idKaryawan;
    }
}
