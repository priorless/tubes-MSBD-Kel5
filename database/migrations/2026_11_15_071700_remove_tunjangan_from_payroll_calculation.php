<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('tbl_payroll') || ! $this->supportsStoredRoutines()) {
            return;
        }

        DB::table('tbl_payroll')
            ->where('status_bayar', 'Belum Dibayar')
            ->update(['total_gaji' => DB::raw('gaji_pokok + total_lembur - potongan_absen')]);

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE VIEW v_slip_gaji_detail AS
            SELECT p.id, k.nip, k.nama, k.kategori,
                   p.periode_bulan, p.periode_tahun,
                   p.gaji_pokok, p.total_lembur, p.potongan_absen,
                   p.total_gaji, p.status_bayar, p.tgl_bayar
            FROM tbl_payroll p
            JOIN tbl_karyawan k ON p.id_karyawan = k.id
            SQL);

        DB::unprepared('DROP PROCEDURE IF EXISTS sp_generate_payroll_bulanan');
        DB::unprepared(<<<'SQL'
            CREATE PROCEDURE sp_generate_payroll_bulanan(IN p_bulan INT, IN p_tahun INT)
            BEGIN
              DECLARE v_done INT DEFAULT 0;
              DECLARE v_id INT;
              DECLARE v_kategori VARCHAR(10);
              DECLARE v_gaji DECIMAL(12,2);
              DECLARE v_hari_kerja INT;
              DECLARE v_jumlah_absen INT;
              DECLARE v_jam_lembur INT;
              DECLARE v_lembur DECIMAL(12,2);
              DECLARE v_potongan DECIMAL(12,2);
              DECLARE v_total DECIMAL(12,2);
              DECLARE v_ada INT;
              DECLARE cur CURSOR FOR
                SELECT id, kategori FROM tbl_karyawan
                WHERE (kategori = 'Internal' AND status = 'Aktif')
                   OR (kategori = 'Lapangan' AND status = 'Ditempatkan');
              DECLARE CONTINUE HANDLER FOR NOT FOUND SET v_done = 1;
              DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN ROLLBACK; RESIGNAL; END;

              START TRANSACTION;
              OPEN cur;
              loop_karyawan: LOOP
                FETCH cur INTO v_id, v_kategori;
                IF v_done THEN LEAVE loop_karyawan; END IF;

                SELECT COUNT(*) INTO v_ada FROM tbl_payroll
                WHERE id_karyawan = v_id AND periode_bulan = p_bulan AND periode_tahun = p_tahun;
                IF v_ada = 0 THEN
                  IF v_kategori = 'Lapangan' THEN
                    SELECT c.gaji_pokok, c.hari_kerja_per_bulan
                    INTO v_gaji, v_hari_kerja
                    FROM tbl_kontrak c JOIN tbl_penempatan p ON c.id_penempatan = p.id
                    WHERE p.id_karyawan = v_id AND p.status = 'Aktif' LIMIT 1;
                  ELSE
                    SELECT gaji_pokok, 25
                    INTO v_gaji, v_hari_kerja
                    FROM tbl_karyawan WHERE id = v_id;
                  END IF;

                  SELECT COUNT(*) INTO v_jumlah_absen FROM tbl_absensi
                  WHERE id_karyawan = v_id AND MONTH(tgl) = p_bulan AND YEAR(tgl) = p_tahun
                    AND status_kehadiran IN ('Alpha','Izin');
                  SELECT COALESCE(SUM(jam_lembur), 0) INTO v_jam_lembur FROM tbl_absensi
                  WHERE id_karyawan = v_id AND MONTH(tgl) = p_bulan AND YEAR(tgl) = p_tahun;

                  SET v_lembur = fn_hitung_lembur(v_jam_lembur);
                  SET v_potongan = fn_hitung_potongan_absen(v_gaji, v_hari_kerja, v_jumlah_absen);
                  SET v_total = v_gaji + v_lembur - v_potongan;

                  INSERT INTO tbl_payroll
                    (id_karyawan, periode_bulan, periode_tahun, gaji_pokok,
                     total_lembur, potongan_absen, total_gaji)
                  VALUES
                    (v_id, p_bulan, p_tahun, v_gaji, v_lembur, v_potongan, v_total);
                END IF;
              END LOOP;
              CLOSE cur;
              COMMIT;
            END
            SQL);
    }

    public function down(): void
    {
        if (! Schema::hasTable('tbl_payroll') || ! $this->supportsStoredRoutines()) {
            return;
        }

        DB::table('tbl_payroll')
            ->where('status_bayar', 'Belum Dibayar')
            ->update(['total_gaji' => DB::raw('gaji_pokok + tunjangan + total_lembur - potongan_absen')]);

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE VIEW v_slip_gaji_detail AS
            SELECT p.id, k.nip, k.nama, k.kategori,
                   p.periode_bulan, p.periode_tahun,
                   p.gaji_pokok, p.tunjangan, p.total_lembur, p.potongan_absen,
                   p.total_gaji, p.status_bayar, p.tgl_bayar
            FROM tbl_payroll p
            JOIN tbl_karyawan k ON p.id_karyawan = k.id
            SQL);

        DB::unprepared('DROP PROCEDURE IF EXISTS sp_generate_payroll_bulanan');
        DB::unprepared(<<<'SQL'
            CREATE PROCEDURE sp_generate_payroll_bulanan(IN p_bulan INT, IN p_tahun INT)
            BEGIN
              DECLARE v_done INT DEFAULT 0;
              DECLARE v_id INT;
              DECLARE v_kategori VARCHAR(10);
              DECLARE v_gaji DECIMAL(12,2);
              DECLARE v_tunjangan DECIMAL(12,2);
              DECLARE v_hari_kerja INT;
              DECLARE v_jumlah_absen INT;
              DECLARE v_jam_lembur INT;
              DECLARE v_lembur DECIMAL(12,2);
              DECLARE v_potongan DECIMAL(12,2);
              DECLARE v_total DECIMAL(12,2);
              DECLARE v_ada INT;
              DECLARE cur CURSOR FOR
                SELECT id, kategori FROM tbl_karyawan
                WHERE (kategori = 'Internal' AND status = 'Aktif')
                   OR (kategori = 'Lapangan' AND status = 'Ditempatkan');
              DECLARE CONTINUE HANDLER FOR NOT FOUND SET v_done = 1;
              DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN ROLLBACK; RESIGNAL; END;

              START TRANSACTION;
              OPEN cur;
              loop_karyawan: LOOP
                FETCH cur INTO v_id, v_kategori;
                IF v_done THEN LEAVE loop_karyawan; END IF;

                SELECT COUNT(*) INTO v_ada FROM tbl_payroll
                WHERE id_karyawan = v_id AND periode_bulan = p_bulan AND periode_tahun = p_tahun;
                IF v_ada = 0 THEN
                  IF v_kategori = 'Lapangan' THEN
                    SELECT c.gaji_pokok, c.tunjangan, c.hari_kerja_per_bulan
                    INTO v_gaji, v_tunjangan, v_hari_kerja
                    FROM tbl_kontrak c JOIN tbl_penempatan p ON c.id_penempatan = p.id
                    WHERE p.id_karyawan = v_id AND p.status = 'Aktif' LIMIT 1;
                  ELSE
                    SELECT gaji_pokok, IFNULL(tunjangan, 0), 25
                    INTO v_gaji, v_tunjangan, v_hari_kerja
                    FROM tbl_karyawan WHERE id = v_id;
                  END IF;

                  SELECT COUNT(*) INTO v_jumlah_absen FROM tbl_absensi
                  WHERE id_karyawan = v_id AND MONTH(tgl) = p_bulan AND YEAR(tgl) = p_tahun
                    AND status_kehadiran IN ('Alpha','Izin');
                  SELECT COALESCE(SUM(jam_lembur), 0) INTO v_jam_lembur FROM tbl_absensi
                  WHERE id_karyawan = v_id AND MONTH(tgl) = p_bulan AND YEAR(tgl) = p_tahun;

                  SET v_lembur = fn_hitung_lembur(v_jam_lembur);
                  SET v_potongan = fn_hitung_potongan_absen(v_gaji, v_hari_kerja, v_jumlah_absen);
                  SET v_total = v_gaji + v_tunjangan + v_lembur - v_potongan;

                  INSERT INTO tbl_payroll
                    (id_karyawan, periode_bulan, periode_tahun, gaji_pokok, tunjangan,
                     total_lembur, potongan_absen, total_gaji)
                  VALUES
                    (v_id, p_bulan, p_tahun, v_gaji, v_tunjangan, v_lembur, v_potongan, v_total);
                END IF;
              END LOOP;
              CLOSE cur;
              COMMIT;
            END
            SQL);
    }

    private function supportsStoredRoutines(): bool
    {
        return in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true);
    }
};
