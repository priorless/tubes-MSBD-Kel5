<?php

namespace Tests\Feature\TenagaKerja;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\MemakaiAbsensiTenagaKerja;
use Tests\TestCase;

/**
 * Driver: P2H + foto kendaraan wajib saat check-in, laporan perjalanan dinamis (tanpa periode per jam)
 * dengan batas unggah per laporan, dan dokumentasi kendaraan + perjalanan selesai saat check-out.
 */
class MonitoringDriverTest extends TestCase
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

    public function test_check_in_driver_tanpa_p2h_ditolak(): void
    {
        [$idKaryawan] = $this->karyawanLayanan('Driver');
        $this->waktuServer('07:45:00');

        $this->kirimCheckIn($idKaryawan, ['foto_kendaraan' => $this->fotoUji('kendaraan.jpg')])
            ->assertStatus(422)
            ->assertJson(['pesan' => 'Checklist P2H wajib diisi sebelum check-in.']);

        $this->assertNull($this->absensiKaryawan($idKaryawan));
    }

    public function test_check_in_driver_dengan_p2h_tidak_lengkap_ditolak(): void
    {
        [$idKaryawan] = $this->karyawanLayanan('Driver');
        $this->waktuServer('07:45:00');
        $p2h = $this->p2hLengkap();
        unset($p2h['ban']);

        $this->kirimCheckIn($idKaryawan, ['p2h' => $p2h, 'foto_kendaraan' => $this->fotoUji('kendaraan.jpg')])
            ->assertStatus(422)
            ->assertJson(['pesan' => 'P2H belum lengkap: pilih kondisi "Tekanan dan kondisi fisik ban".']);

        $this->assertNull($this->absensiKaryawan($idKaryawan));
    }

    public function test_komponen_tidak_baik_wajib_keterangan(): void
    {
        [$idKaryawan] = $this->karyawanLayanan('Driver');
        $this->waktuServer('07:45:00');

        $this->kirimCheckIn($idKaryawan, [
            'p2h' => $this->p2hLengkap(['aki' => ['kondisi' => 'Rusak']]),
            'foto_kendaraan' => $this->fotoUji('kendaraan.jpg'),
        ])->assertStatus(422)->assertJson(['pesan' => 'Isi keterangan untuk "Kondisi aki" karena kondisinya tidak Baik.']);
    }

    public function test_check_in_driver_tanpa_foto_kendaraan_ditolak(): void
    {
        [$idKaryawan] = $this->karyawanLayanan('Driver');
        $this->waktuServer('07:45:00');

        $this->kirimCheckIn($idKaryawan, ['p2h' => $this->p2hLengkap()])
            ->assertStatus(422)
            ->assertJson(['pesan' => 'Foto kondisi kendaraan wajib diunggah sebelum check-in.']);

        $this->assertNull($this->absensiKaryawan($idKaryawan));
    }

    public function test_check_in_driver_menyimpan_p2h_lengkap_dan_foto_kendaraan(): void
    {
        $idKaryawan = $this->driverSudahCheckIn(['aki' => ['kondisi' => 'Perlu Perhatian', 'keterangan' => 'Terminal sedikit berkarat']]);

        $absensi = $this->absensiKaryawan($idKaryawan);
        $p2h = DB::table('tbl_p2h')->where('id_absensi', $absensi->id)->first();
        $this->assertSame($idKaryawan, (int) $p2h->id_karyawan);
        $this->assertSame($this->hariIniDb().' 07:45:00', $p2h->waktu_pemeriksaan);
        Storage::disk('local')->assertExists($p2h->foto_kendaraan_awal);
        $this->assertNotSame($absensi->foto_masuk, $p2h->foto_kendaraan_awal);
        $this->assertSame(9, DB::table('tbl_p2h_item')->where('id_p2h', $p2h->id)->count());
        $this->assertDatabaseHas('tbl_p2h_item', [
            'id_p2h' => $p2h->id, 'komponen' => 'aki', 'kondisi' => 'Perlu Perhatian', 'keterangan' => 'Terminal sedikit berkarat',
        ]);
    }

    public function test_laporan_perjalanan_cukup_keterangan_tanpa_tahapan_dan_tanpa_periode(): void
    {
        $idKaryawan = $this->driverSudahCheckIn();

        $this->waktuServer('08:03:00');
        $this->kirimLaporan($idKaryawan, ['keterangan' => 'Berangkat ke PT ABC'])->assertSessionHas('sukses');
        $this->waktuServer('08:10:00');
        $this->kirimLaporan($idKaryawan, ['keterangan' => 'Tiba di PT ABC'])->assertSessionHas('sukses');

        $laporan = DB::table('tbl_laporan_aktivitas')->where('id_karyawan', $idKaryawan)->orderBy('id')->get();
        $this->assertSame(['Berangkat ke PT ABC', 'Tiba di PT ABC'], $laporan->pluck('keterangan')->all());
        $this->assertSame([null, null], $laporan->pluck('tahapan')->all());
        $this->assertSame([null, null], $laporan->pluck('periode_ke')->all());
        $this->assertSame($this->hariIniDb().' 08:10:00', $laporan[1]->waktu_lapor);
    }

    public function test_setiap_laporan_driver_memiliki_batas_unggah_sendiri(): void
    {
        $idKaryawan = $this->driverSudahCheckIn();
        $this->waktuServer('08:03:00');
        $this->kirimLaporan($idKaryawan, ['keterangan' => 'Berangkat ke PT ABC']);
        $this->kirimLaporan($idKaryawan, ['keterangan' => 'Tiba di PT ABC']);
        [$pertama, $kedua] = DB::table('tbl_laporan_aktivitas')->where('id_karyawan', $idKaryawan)->orderBy('id')->pluck('id')->all();

        $this->kirimKoreksi($idKaryawan, $pertama)->assertSessionHas('sukses');
        $this->kirimKoreksi($idKaryawan, $pertama)->assertSessionHas('gagal', 'Foto laporan ini sudah dikoreksi (maksimal 2 unggahan). Kirim laporan baru bila perlu.');
        $this->kirimKoreksi($idKaryawan, $kedua)->assertSessionHas('sukses');

        $this->assertDatabaseHas('tbl_laporan_aktivitas', ['id' => $pertama, 'jumlah_upload' => 2]);
        $this->assertDatabaseHas('tbl_laporan_aktivitas', ['id' => $kedua, 'jumlah_upload' => 2]);
    }

    public function test_check_out_driver_wajib_dokumentasi_kendaraan(): void
    {
        $idKaryawan = $this->driverSudahCheckIn();
        $this->waktuServer('16:00:00');

        $this->kirimCheckOut($idKaryawan, ['foto_kendaraan' => $this->fotoUji('kendaraan.jpg')])
            ->assertStatus(422)->assertJson(['pesan' => 'Pilih kondisi akhir kendaraan sebelum check-out.']);
        $this->kirimCheckOut($idKaryawan, ['kondisi_akhir' => 'Baik'])
            ->assertStatus(422)->assertJson(['pesan' => 'Foto kondisi kendaraan setelah digunakan wajib diunggah.']);

        $this->assertNull($this->absensiKaryawan($idKaryawan)->jam_keluar);
    }

    public function test_check_out_driver_tidak_diblokir_oleh_catatan_perjalanan(): void
    {
        $idKaryawan = $this->driverSudahCheckIn();
        $this->waktuServer('08:03:00');
        $this->kirimLaporan($idKaryawan, ['keterangan' => 'Berangkat ke PT ABC']);

        $this->waktuServer('16:00:00');
        $this->kirimCheckOut($idKaryawan, ['kondisi_akhir' => 'Baik', 'foto_kendaraan' => $this->fotoUji('kendaraan.jpg')])
            ->assertOk();

        $this->assertSame('16:00:00', $this->absensiKaryawan($idKaryawan)->jam_keluar);
    }

    public function test_laporan_terakhir_tidak_otomatis_check_out_dan_check_out_menyimpan_kondisi_akhir(): void
    {
        $idKaryawan = $this->driverSudahCheckIn();
        $this->waktuServer('08:03:00');
        $this->kirimLaporan($idKaryawan, ['keterangan' => 'Berangkat ke PT ABC']);
        $this->waktuServer('15:30:00');
        $this->kirimLaporan($idKaryawan, ['keterangan' => 'Kembali ke kantor']);
        $this->assertNull($this->absensiKaryawan($idKaryawan)->jam_keluar);

        $this->waktuServer('16:00:00');
        $this->kirimCheckOut($idKaryawan, [
            'kondisi_akhir' => 'Perlu Perhatian',
            'catatan_akhir' => 'Lampu sein kiri redup',
            'foto_kendaraan' => $this->fotoUji('kendaraan-akhir.jpg'),
        ])->assertOk()->assertJson(['status' => 'selesai']);

        $absensi = $this->absensiKaryawan($idKaryawan);
        $p2h = DB::table('tbl_p2h')->where('id_absensi', $absensi->id)->first();
        $this->assertSame('16:00:00', $absensi->jam_keluar);
        $this->assertSame('Perlu Perhatian', $p2h->kondisi_akhir);
        $this->assertSame('Lampu sein kiri redup', $p2h->catatan_akhir);
        $this->assertSame($this->hariIniDb().' 16:00:00', $p2h->waktu_selesai);
        Storage::disk('local')->assertExists($p2h->foto_kendaraan_akhir);
        $this->assertNotSame($absensi->foto_keluar, $p2h->foto_kendaraan_akhir);
    }

    public function test_halaman_absensi_driver_menampilkan_checklist_p2h_sebelum_check_in(): void
    {
        [$idKaryawan] = $this->karyawanLayanan('Driver');
        $this->waktuServer('07:45:00');

        $this->actingAs($this->akunKaryawan($idKaryawan))
            ->get(route('tenaga-kerja.absensi'))
            ->assertOk()
            ->assertSee('P2H Kendaraan')
            ->assertSeeInOrder(['Kondisi dan volume oli mesin', 'Kondisi bahan bakar', 'Foto kondisi kendaraan'], false)
            ->assertDontSee('Monitoring Aktivitas per Jam');
    }

    /**
     * @param  array<string, array{kondisi: string, keterangan?: string}>  $timpaP2h
     */
    private function driverSudahCheckIn(array $timpaP2h = []): int
    {
        [$idKaryawan] = $this->karyawanLayanan('Driver');
        $this->waktuServer('07:45:00');
        $this->kirimCheckIn($idKaryawan, [
            'p2h' => $this->p2hLengkap($timpaP2h),
            'foto_kendaraan' => $this->fotoUji('kendaraan.jpg'),
        ])->assertOk();

        return $idKaryawan;
    }
}
