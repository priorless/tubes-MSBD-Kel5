<?php

namespace Tests\Feature\Ceo;

use App\Models\Role;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\MemakaiDatabaseSji;
use Tests\TestCase;

/**
 * Log aktivitas (Tahap 8): trigger mencatat perubahan penting, id_user dari @app_user_id
 * (middleware SetAppUserIdDatabase), dan halaman Log Aktivitas untuk CEO.
 */
class LogAktivitasTest extends TestCase
{
    use MemakaiDatabaseSji;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpDatabaseSji();
    }

    protected function tearDown(): void
    {
        $this->tearDownDatabaseSji();
        parent::tearDown();
    }

    /**
     * @return list<object>
     */
    private function log(string $tabel, int $idData): array
    {
        return DB::table('tbl_log_aktivitas')
            ->where('nama_tabel', $tabel)->where('id_data', $idData)
            ->orderBy('id')->get()->all();
    }

    private function setPelaku(?int $idUser): void
    {
        DB::statement('SET @app_user_id = ?', [$idUser]);
    }

    public function test_aksi_dari_aplikasi_mencatat_pengguna_yang_login(): void
    {
        $operasional = $this->loginSebagai(Role::Operasional);

        $this->post(route('operasional.klien.store'), [
            'kode_klien' => 'TSTXLOG1',
            'nama_perusahaan' => 'Klien Log Uji',
            'alamat' => 'Jl. Uji',
            'pic_nama' => 'PIC',
            'pic_telp' => '08123456789',
            'status' => 'Aktif',
        ])->assertRedirect(route('operasional.klien.show', (int) DB::table('tbl_klien')->where('kode_klien', 'TSTXLOG1')->value('id')));

        $idKlien = (int) DB::table('tbl_klien')->where('kode_klien', 'TSTXLOG1')->value('id');
        $log = $this->log('tbl_klien', $idKlien);

        $this->assertCount(1, $log);
        $this->assertSame('INSERT', $log[0]->aksi);
        $this->assertSame($operasional->id, (int) $log[0]->id_user);
        $this->assertStringContainsString('Klien baru TSTXLOG1 - Klien Log Uji', $log[0]->keterangan);
    }

    public function test_karyawan_tambah_ubah_status_dan_hapus_tercatat(): void
    {
        $this->setPelaku(1);
        $id = $this->buatKaryawan('Lapangan');
        $idAkun = (int) DB::table('tbl_users')->where('id_karyawan', $id)->value('id');

        DB::table('tbl_karyawan')->where('id', $id)->update(['nama' => 'Ganti Nama Saja']);
        DB::table('tbl_karyawan')->where('id', $id)->update(['status' => 'Nonaktif']);

        $log = $this->log('tbl_karyawan', $id);
        $this->assertSame(['INSERT', 'UPDATE'], array_column($log, 'aksi'), 'Perubahan selain status tidak dicatat');
        $this->assertStringContainsString('Status karyawan', $log[1]->keterangan);
        $this->assertStringContainsString('Tersedia -> Nonaktif', $log[1]->keterangan);
        $this->assertSame(1, (int) $log[1]->id_user);

        // Akun dibuat trigger & dinonaktifkan sinkron status: keduanya tercatat di tbl_users.
        $logAkun = $this->log('tbl_users', $idAkun);
        $this->assertSame(['INSERT', 'UPDATE'], array_column($logAkun, 'aksi'));
        $this->assertStringContainsString('status Aktif -> Nonaktif', $logAkun[1]->keterangan);

        DB::table('tbl_users')->where('id', $idAkun)->delete();
        DB::table('tbl_karyawan')->where('id', $id)->delete();
        $this->assertSame('DELETE', $this->log('tbl_karyawan', $id)[2]->aksi);
        $this->assertSame('DELETE', $this->log('tbl_users', $idAkun)[2]->aksi);
    }

    public function test_penempatan_dan_klien_status_tercatat(): void
    {
        $idKlien = $this->buatKlien();
        $idPenempatan = $this->buatPenempatan($this->buatKaryawan(), $idKlien, $this->hariIniDb(-5));

        DB::table('tbl_penempatan')->where('id', $idPenempatan)->update(['status' => 'Selesai', 'tgl_selesai' => $this->hariIniDb()]);
        DB::table('tbl_klien')->where('id', $idKlien)->update(['status' => 'Nonaktif']);

        $logPenempatan = $this->log('tbl_penempatan', $idPenempatan);
        $this->assertSame(['INSERT', 'UPDATE'], array_column($logPenempatan, 'aksi'));
        $this->assertStringContainsString('Aktif -> Selesai', $logPenempatan[1]->keterangan);

        $logKlien = $this->log('tbl_klien', $idKlien);
        $this->assertSame(['INSERT', 'UPDATE'], array_column($logKlien, 'aksi'));
        $this->assertStringContainsString('Aktif -> Nonaktif', $logKlien[1]->keterangan);
    }

    public function test_status_bayar_payroll_dan_invoice_tercatat_tanpa_menulis_mutasi_kas(): void
    {
        $idKaryawan = $this->buatKaryawan('Internal');
        $idPayroll = DB::table('tbl_payroll')->insertGetId([
            'id_karyawan' => $idKaryawan, 'periode_bulan' => 1, 'periode_tahun' => 2099,
            'gaji_pokok' => 1000000, 'total_lembur' => 0, 'potongan_absen' => 0, 'total_gaji' => 1000000,
        ]);
        $idInvoice = DB::table('tbl_invoice')->insertGetId([
            'nomor_invoice' => 'INV-TSTX-LOG', 'id_klien' => $this->buatKlien(), 'periode_bulan' => 1, 'periode_tahun' => 2099,
            'jumlah_tenaga' => 1, 'total_tagihan' => 2000000,
        ]);

        DB::table('tbl_payroll')->where('id', $idPayroll)->update(['status_bayar' => 'Sudah Lunas', 'tgl_bayar' => $this->hariIniDb()]);
        DB::table('tbl_invoice')->where('id', $idInvoice)->update(['status' => 'Lunas', 'tgl_pembayaran' => $this->hariIniDb()]);

        $this->assertStringContainsString('Belum Dibayar -> Sudah Lunas', $this->log('tbl_payroll', $idPayroll)[0]->keterangan);
        $this->assertStringContainsString('INV-TSTX-LOG: Belum Lunas -> Lunas', $this->log('tbl_invoice', $idInvoice)[0]->keterangan);
        // Pencairan payroll & pelunasan invoice tidak lagi dicatat ke Mutasi Kas (sql/Komponen/16).
        $this->assertFalse(DB::table('tbl_mutasi_keuangan')->where('referensi', 'PAYROLL-'.$idPayroll)->exists());
        $this->assertFalse(DB::table('tbl_mutasi_keuangan')->where('referensi', 'INV-TSTX-LOG')->exists());
    }

    public function test_perubahan_role_akun_tercatat(): void
    {
        $user = $this->loginSebagai(Role::Keuangan);
        $idRoleHrd = (int) DB::table('tbl_roles')->where('nama_role', Role::Hrd)->value('id');

        DB::table('tbl_users')->where('id', $user->id)->update(['id_role' => $idRoleHrd]);

        $log = $this->log('tbl_users', $user->id);
        $this->assertSame('UPDATE', end($log)->aksi);
        $this->assertStringContainsString('role Keuangan -> HRD', end($log)->keterangan);
    }

    public function test_halaman_log_hanya_untuk_ceo_dan_bisa_difilter(): void
    {
        $this->setPelaku(null);
        $idKlien = $this->buatKlien(['nama_perusahaan' => 'Klien Filter Uji']);
        $this->buatKaryawan('Internal');

        $this->loginSebagai(Role::Ceo);
        $this->get(route('ceo.log-aktivitas', ['tabel' => 'tbl_klien', 'dari' => $this->hariIniDb(), 'sampai' => $this->hariIniDb()]))
            ->assertOk()
            ->assertSee('Log Aktivitas Sistem')
            ->assertSee('Klien Filter Uji')
            ->assertDontSee('Karyawan Uji');

        $this->loginSebagai(Role::Hrd);
        $this->get(route('ceo.log-aktivitas'))->assertForbidden();
        $this->assertGreaterThan(0, $idKlien);
    }

    public function test_tidak_ada_role_yang_boleh_mengubah_atau_menghapus_log(): void
    {
        $hak = DB::table('mysql.tables_priv')
            ->where('Db', DB::getDatabaseName())
            ->where('Table_name', 'tbl_log_aktivitas')
            ->get(['User', 'Table_priv']);

        $this->assertContains('sji_role_ceo', $hak->pluck('User')->all());
        foreach ($hak as $baris) {
            $this->assertSame('Select', (string) $baris->Table_priv, "{$baris->User} hanya boleh SELECT pada log");
        }
    }
}
