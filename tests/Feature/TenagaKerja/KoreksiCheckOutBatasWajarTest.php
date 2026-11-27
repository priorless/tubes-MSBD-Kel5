<?php

namespace Tests\Feature\TenagaKerja;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\MemakaiDatabaseSji;
use Tests\TestCase;

/**
 * Batas wajar jam keluar koreksi lupa check-out (sp_cek_koreksi_check_out lewat trigger tbl_koreksi_check_out):
 * minimal 30 menit setelah check-in, maksimal 4 jam setelah jadwal pulang. Tanpa batas ini, check-in 08:00 dengan
 * koreksi 07:00 dihitung hampir 23 jam kerja oleh putaran 24 jam trigger lembur.
 */
class KoreksiCheckOutBatasWajarTest extends TestCase
{
    use MemakaiDatabaseSji;

    private int $idKaryawan;

    private int $idPenempatan;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpDatabaseSji();

        $this->idKaryawan = $this->buatKaryawan('Lapangan');
        // Jadwal penempatan 08:00-16:00 (helper buatPenempatan).
        $this->idPenempatan = $this->buatPenempatan($this->idKaryawan, $this->buatKlien(), $this->hariIniDb(-30));
    }

    protected function tearDown(): void
    {
        $this->tearDownDatabaseSji();
        parent::tearDown();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function jamTidakWajar(): array
    {
        return [
            'sebelum jam masuk (dihitung hampir 23 jam)' => ['07:00'],
            'kurang dari 30 menit setelah check-in' => ['08:10'],
            'lebih dari 4 jam setelah jadwal pulang' => ['20:30'],
        ];
    }

    #[DataProvider('jamTidakWajar')]
    public function test_jam_keluar_tidak_wajar_ditolak_dengan_rentang_yang_diperbolehkan(string $jamKeluar): void
    {
        $idAbsensi = $this->absensiLupaCheckOut('08:00:00');

        $this->actingAs($this->akunKaryawan())
            ->post(route('tenaga-kerja.koreksi-check-out.store'), ['id_absensi' => $idAbsensi, 'jam_keluar' => $jamKeluar, 'alasan' => 'Lupa'])
            ->assertSessionHasErrors(['jam_keluar' => 'Jam keluar harus antara 08:30 dan 20:00 (minimal 30 menit setelah check-in, maksimal 4 jam setelah jadwal pulang 16:00).']);

        $this->assertSame(0, DB::table('tbl_koreksi_check_out')->where('id_absensi', $idAbsensi)->count());
    }

    public function test_jam_keluar_wajar_diterima_dan_lembur_dihitung_saat_disetujui(): void
    {
        $idAbsensi = $this->absensiLupaCheckOut('08:00:00');

        $this->actingAs($this->akunKaryawan())
            ->post(route('tenaga-kerja.koreksi-check-out.store'), ['id_absensi' => $idAbsensi, 'jam_keluar' => '17:15', 'alasan' => 'Lupa'])
            ->assertSessionHasNoErrors();

        $this->loginSebagai(Role::Hrd);
        $this->patch(route('hrd.koreksi-check-out.update', DB::table('tbl_koreksi_check_out')->where('id_absensi', $idAbsensi)->value('id')), ['keputusan' => 'Disetujui'])
            ->assertSessionHasNoErrors();

        $absensi = DB::table('tbl_absensi')->where('id', $idAbsensi)->first(['jam_keluar', 'jam_lembur']);
        $this->assertSame(['17:15:00', 1], [$absensi->jam_keluar, (int) $absensi->jam_lembur]);
    }

    public function test_lupa_check_out_diajukan_dari_halaman_absensi_tanpa_menu_terpisah(): void
    {
        $lupa = $this->absensiLupaCheckOut('08:00:00');
        $selesai = $this->buatAbsensi($this->idKaryawan, $this->hariIniDb(-2), 'Hadir', ['id_penempatan' => $this->idPenempatan, 'jam_masuk' => '08:00:00', 'jam_keluar' => '16:00:00']);
        $orangLain = $this->buatKaryawan('Lapangan');
        $milikOrangLain = $this->buatAbsensi($orangLain, $this->hariIniDb(-1), 'Hadir', [
            'id_penempatan' => $this->buatPenempatan($orangLain, $this->buatKlien(), $this->hariIniDb(-30)), 'jam_masuk' => '08:00:00',
        ]);
        $karyawan = $this->akunKaryawan();

        $this->actingAs($karyawan)->get(route('tenaga-kerja.absensi'))
            ->assertOk()
            ->assertSee('id="lupa-check-out"', false)
            ->assertSee('name="id_absensi" value="'.$lupa.'"', false)
            ->assertDontSee('name="id_absensi" value="'.$selesai.'"', false)
            ->assertDontSee('name="id_absensi" value="'.$milikOrangLain.'"', false)
            ->assertDontSee('>Koreksi Check-out<', false);

        $this->actingAs($karyawan)
            ->post(route('tenaga-kerja.koreksi-check-out.store'), ['id_absensi' => $lupa, 'jam_keluar' => '17:00', 'alasan' => 'Baterai habis'])
            ->assertRedirect(route('tenaga-kerja.absensi'));

        $this->actingAs($karyawan)->get(route('tenaga-kerja.absensi'))
            ->assertOk()
            ->assertDontSee('name="id_absensi" value="'.$lupa.'"', false)
            ->assertSee('Baterai habis')
            ->assertSee('badge-warning">Menunggu', false);
    }

    public function test_tanpa_lupa_check_out_bagian_tidak_tampil(): void
    {
        $this->actingAs($this->akunKaryawan())->get(route('tenaga-kerja.absensi'))
            ->assertOk()
            ->assertDontSee('id="lupa-check-out"', false);
    }

    public function test_shift_malam_boleh_keluar_keesokan_pagi_dalam_batas_lembur(): void
    {
        $tgl = $this->hariIniDb(-1);
        DB::table('tbl_jadwal_kerja')->insert([
            'id_penempatan' => $this->idPenempatan, 'tanggal_kerja' => $tgl, 'status' => 'Kerja',
            'jam_masuk' => '22:00:00', 'jam_pulang' => '06:00:00', 'durasi_menit' => 480,
        ]);
        $idAbsensi = $this->absensiLupaCheckOut('21:45:00', $tgl);

        $this->insertKoreksi($idAbsensi, '09:00:00');
        $this->assertSame(1, DB::table('tbl_koreksi_check_out')->where('id_absensi', $idAbsensi)->count());

        DB::table('tbl_koreksi_check_out')->where('id_absensi', $idAbsensi)->delete();
        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/antara 22:15 dan 10:00/');
        $this->insertKoreksi($idAbsensi, '11:00:00');
    }

    public function test_jam_keluar_tidak_bisa_diubah_menjadi_tidak_wajar_setelah_diajukan(): void
    {
        $idAbsensi = $this->absensiLupaCheckOut('08:00:00');
        $this->insertKoreksi($idAbsensi, '17:00:00');

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/Jam keluar harus antara 08:30 dan 20:00/');

        DB::table('tbl_koreksi_check_out')->where('id_absensi', $idAbsensi)->update(['jam_keluar' => '07:00:00']);
    }

    private function absensiLupaCheckOut(string $jamMasuk, ?string $tgl = null): int
    {
        return $this->buatAbsensi($this->idKaryawan, $tgl ?? $this->hariIniDb(-1), 'Hadir', [
            'id_penempatan' => $this->idPenempatan,
            'jam_masuk' => $jamMasuk,
        ]);
    }

    private function insertKoreksi(int $idAbsensi, string $jamKeluar): void
    {
        DB::table('tbl_koreksi_check_out')->insert([
            'id_absensi' => $idAbsensi, 'jam_keluar' => $jamKeluar, 'alasan' => 'Lupa', 'status' => 'Menunggu',
            'diajukan_oleh' => $this->akunKaryawan()->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** Akun login karyawan (dibuat trigger trg_auto_akun_karyawan) yang sudah ganti password. */
    private function akunKaryawan(): User
    {
        $user = User::query()->where('id_karyawan', $this->idKaryawan)->sole();
        $user->forceFill(['must_change_password' => false])->save();

        return $user;
    }
}
