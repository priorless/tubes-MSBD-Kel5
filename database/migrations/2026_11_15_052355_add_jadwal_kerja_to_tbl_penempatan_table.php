<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Menambahkan jadwal kerja (jam datang & jam pulang) ke tbl_penempatan
 * (skema db_sji dibuat dari sql/schema_sji.sql).
 *
 * - jam_datang / jam_pulang : TIME NULL, agar penempatan lama tetap valid tanpa jadwal.
 * - sp_assign_penempatan    : menerima jadwal kerja penempatan baru.
 * - sp_rotasi_karyawan      : menerima jadwal kerja penempatan baru; bila kosong,
 *                             mewarisi jadwal penempatan lama. Baris lama tidak diubah jadwalnya.
 * - v_monitoring_sebaran_operasional : menampilkan jadwal dari penempatan aktif.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasTable('tbl_penempatan')) {
            return;
        }

        Schema::table('tbl_penempatan', function (Blueprint $table) {
            if (! Schema::hasColumn('tbl_penempatan', 'jam_datang')) {
                $table->time('jam_datang')->nullable()->after('lokasi_kerja');
            }

            if (! Schema::hasColumn('tbl_penempatan', 'jam_pulang')) {
                $table->time('jam_pulang')->nullable()->after('jam_datang');
            }
        });

        if (! $this->supportsStoredRoutines()) {
            return;
        }

        DB::unprepared('DROP PROCEDURE IF EXISTS sp_assign_penempatan');
        DB::unprepared(<<<'SQL'
            CREATE PROCEDURE sp_assign_penempatan(
              IN p_id_karyawan INT, IN p_id_klien INT, IN p_tgl_mulai DATE,
              IN p_tgl_selesai DATE, IN p_lokasi VARCHAR(255),
              IN p_jam_datang TIME, IN p_jam_pulang TIME, IN p_dibuat_oleh INT)
            BEGIN
              DECLARE v_kategori VARCHAR(10);
              DECLARE v_status VARCHAR(20);
              DECLARE v_tarif DECIMAL(12,2);
              DECLARE v_pen_id INT;
              DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN ROLLBACK; RESIGNAL; END;

              SELECT kategori, status INTO v_kategori, v_status
              FROM tbl_karyawan WHERE id = p_id_karyawan;

              IF v_kategori = 'Internal' THEN
                SIGNAL SQLSTATE '45000'
                  SET MESSAGE_TEXT = 'Staf internal tidak dapat ditempatkan ke klien';
              END IF;
              IF v_status <> 'Tersedia' THEN
                SIGNAL SQLSTATE '45000'
                  SET MESSAGE_TEXT = 'Karyawan tidak berstatus Tersedia';
              END IF;

              SELECT jl.tarif_dasar INTO v_tarif
              FROM tbl_karyawan k JOIN tbl_jenis_layanan jl ON k.id_jenis_layanan = jl.id
              WHERE k.id = p_id_karyawan;

              START TRANSACTION;
                INSERT INTO tbl_penempatan
                  (id_karyawan, id_klien, lokasi_kerja, jam_datang, jam_pulang, tgl_mulai, tgl_selesai, status, dibuat_oleh)
                VALUES
                  (p_id_karyawan, p_id_klien, p_lokasi, p_jam_datang, p_jam_pulang, p_tgl_mulai, p_tgl_selesai, 'Aktif', p_dibuat_oleh);
                SET v_pen_id = LAST_INSERT_ID();
                INSERT INTO tbl_kontrak (id_penempatan, gaji_pokok, tunjangan)
                VALUES (v_pen_id, v_tarif, 0);
                UPDATE tbl_karyawan SET status = 'Ditempatkan' WHERE id = p_id_karyawan;
              COMMIT;
            END
            SQL);

        DB::unprepared('DROP PROCEDURE IF EXISTS sp_rotasi_karyawan');
        DB::unprepared(<<<'SQL'
            CREATE PROCEDURE sp_rotasi_karyawan(
              IN p_id_penempatan_lama INT, IN p_id_klien_baru INT,
              IN p_tgl_mulai_baru DATE, IN p_lokasi_baru VARCHAR(255),
              IN p_jam_datang_baru TIME, IN p_jam_pulang_baru TIME, IN p_dibuat_oleh INT)
            BEGIN
              DECLARE v_karyawan INT;
              DECLARE v_gaji DECIMAL(12,2);
              DECLARE v_tunjangan DECIMAL(12,2);
              DECLARE v_jam_datang TIME;
              DECLARE v_jam_pulang TIME;
              DECLARE v_pen_id INT;
              DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN ROLLBACK; RESIGNAL; END;

              SELECT id_karyawan, jam_datang, jam_pulang
              INTO v_karyawan, v_jam_datang, v_jam_pulang
              FROM tbl_penempatan WHERE id = p_id_penempatan_lama;
              SELECT gaji_pokok, tunjangan INTO v_gaji, v_tunjangan
              FROM tbl_kontrak WHERE id_penempatan = p_id_penempatan_lama;

              START TRANSACTION;
                UPDATE tbl_penempatan SET status = 'Rotasi', tgl_selesai = CURDATE()
                WHERE id = p_id_penempatan_lama;
                UPDATE tbl_kontrak SET status = 'Selesai'
                WHERE id_penempatan = p_id_penempatan_lama;
                INSERT INTO tbl_penempatan
                  (id_karyawan, id_klien, lokasi_kerja, jam_datang, jam_pulang, tgl_mulai, tgl_selesai, status, dibuat_oleh)
                VALUES
                  (v_karyawan, p_id_klien_baru, p_lokasi_baru,
                   IFNULL(p_jam_datang_baru, v_jam_datang), IFNULL(p_jam_pulang_baru, v_jam_pulang),
                   p_tgl_mulai_baru, NULL, 'Aktif', p_dibuat_oleh);
                SET v_pen_id = LAST_INSERT_ID();
                INSERT INTO tbl_kontrak (id_penempatan, gaji_pokok, tunjangan)
                VALUES (v_pen_id, v_gaji, v_tunjangan);
              COMMIT;
            END
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE VIEW v_monitoring_sebaran_operasional AS
            SELECT p.id AS id_penempatan, k.nip, k.nama, jl.nama_layanan,
                   kl.nama_perusahaan, p.lokasi_kerja, p.jam_datang, p.jam_pulang,
                   p.tgl_mulai, p.tgl_selesai,
                   IFNULL(DATEDIFF(p.tgl_selesai, CURDATE()), 9999) AS sisa_hari_kontrak
            FROM tbl_penempatan p
            JOIN tbl_karyawan k ON p.id_karyawan = k.id
            JOIN tbl_jenis_layanan jl ON k.id_jenis_layanan = jl.id
            JOIN tbl_klien kl ON p.id_klien = kl.id
            WHERE p.status = 'Aktif'
            SQL);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasTable('tbl_penempatan')) {
            return;
        }

        if ($this->supportsStoredRoutines()) {
            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE VIEW v_monitoring_sebaran_operasional AS
                SELECT p.id AS id_penempatan, k.nip, k.nama, jl.nama_layanan,
                       kl.nama_perusahaan, p.lokasi_kerja, p.tgl_mulai, p.tgl_selesai,
                       IFNULL(DATEDIFF(p.tgl_selesai, CURDATE()), 9999) AS sisa_hari_kontrak
                FROM tbl_penempatan p
                JOIN tbl_karyawan k ON p.id_karyawan = k.id
                JOIN tbl_jenis_layanan jl ON k.id_jenis_layanan = jl.id
                JOIN tbl_klien kl ON p.id_klien = kl.id
                WHERE p.status = 'Aktif'
                SQL);

            DB::unprepared('DROP PROCEDURE IF EXISTS sp_assign_penempatan');
            DB::unprepared(<<<'SQL'
                CREATE PROCEDURE sp_assign_penempatan(
                  IN p_id_karyawan INT, IN p_id_klien INT, IN p_tgl_mulai DATE,
                  IN p_tgl_selesai DATE, IN p_lokasi VARCHAR(255), IN p_dibuat_oleh INT)
                BEGIN
                  DECLARE v_kategori VARCHAR(10);
                  DECLARE v_status VARCHAR(20);
                  DECLARE v_tarif DECIMAL(12,2);
                  DECLARE v_pen_id INT;
                  DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN ROLLBACK; RESIGNAL; END;

                  SELECT kategori, status INTO v_kategori, v_status
                  FROM tbl_karyawan WHERE id = p_id_karyawan;

                  IF v_kategori = 'Internal' THEN
                    SIGNAL SQLSTATE '45000'
                      SET MESSAGE_TEXT = 'Staf internal tidak dapat ditempatkan ke klien';
                  END IF;
                  IF v_status <> 'Tersedia' THEN
                    SIGNAL SQLSTATE '45000'
                      SET MESSAGE_TEXT = 'Karyawan tidak berstatus Tersedia';
                  END IF;

                  SELECT jl.tarif_dasar INTO v_tarif
                  FROM tbl_karyawan k JOIN tbl_jenis_layanan jl ON k.id_jenis_layanan = jl.id
                  WHERE k.id = p_id_karyawan;

                  START TRANSACTION;
                    INSERT INTO tbl_penempatan
                      (id_karyawan, id_klien, lokasi_kerja, tgl_mulai, tgl_selesai, status, dibuat_oleh)
                    VALUES
                      (p_id_karyawan, p_id_klien, p_lokasi, p_tgl_mulai, p_tgl_selesai, 'Aktif', p_dibuat_oleh);
                    SET v_pen_id = LAST_INSERT_ID();
                    INSERT INTO tbl_kontrak (id_penempatan, gaji_pokok, tunjangan)
                    VALUES (v_pen_id, v_tarif, 0);
                    UPDATE tbl_karyawan SET status = 'Ditempatkan' WHERE id = p_id_karyawan;
                  COMMIT;
                END
                SQL);

            DB::unprepared('DROP PROCEDURE IF EXISTS sp_rotasi_karyawan');
            DB::unprepared(<<<'SQL'
                CREATE PROCEDURE sp_rotasi_karyawan(
                  IN p_id_penempatan_lama INT, IN p_id_klien_baru INT,
                  IN p_tgl_mulai_baru DATE, IN p_lokasi_baru VARCHAR(255), IN p_dibuat_oleh INT)
                BEGIN
                  DECLARE v_karyawan INT;
                  DECLARE v_gaji DECIMAL(12,2);
                  DECLARE v_tunjangan DECIMAL(12,2);
                  DECLARE v_pen_id INT;
                  DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN ROLLBACK; RESIGNAL; END;

                  SELECT id_karyawan INTO v_karyawan FROM tbl_penempatan WHERE id = p_id_penempatan_lama;
                  SELECT gaji_pokok, tunjangan INTO v_gaji, v_tunjangan
                  FROM tbl_kontrak WHERE id_penempatan = p_id_penempatan_lama;

                  START TRANSACTION;
                    UPDATE tbl_penempatan SET status = 'Rotasi', tgl_selesai = CURDATE()
                    WHERE id = p_id_penempatan_lama;
                    UPDATE tbl_kontrak SET status = 'Selesai'
                    WHERE id_penempatan = p_id_penempatan_lama;
                    INSERT INTO tbl_penempatan
                      (id_karyawan, id_klien, lokasi_kerja, tgl_mulai, tgl_selesai, status, dibuat_oleh)
                    VALUES
                      (v_karyawan, p_id_klien_baru, p_lokasi_baru, p_tgl_mulai_baru, NULL, 'Aktif', p_dibuat_oleh);
                    SET v_pen_id = LAST_INSERT_ID();
                    INSERT INTO tbl_kontrak (id_penempatan, gaji_pokok, tunjangan)
                    VALUES (v_pen_id, v_gaji, v_tunjangan);
                  COMMIT;
                END
                SQL);
        }

        Schema::table('tbl_penempatan', function (Blueprint $table) {
            $table->dropColumn(['jam_datang', 'jam_pulang']);
        });
    }

    /**
     * Stored procedure & view hanya dibuat ulang pada MySQL/MariaDB (bukan SQLite saat testing).
     */
    private function supportsStoredRoutines(): bool
    {
        return in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true);
    }
};
