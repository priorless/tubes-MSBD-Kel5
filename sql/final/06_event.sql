-- ============================================================
-- sql/final/06_event.sql — Event (butuh event_scheduler=ON agar berjalan)
-- Diekspor dari database db_sji (MySQL 8) setelah migration terakhir (sql/Komponen/24). DEFINER dihapus agar bisa
-- diimpor di komputer lain. Urutan impor: lihat sql/README.md.
-- ============================================================

-- Charset koneksi saat impor: klien mysql di Windows memakai cp850 bila tidak diatur.
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Event menyimpan zona waktu sesi saat dibuat; NOW() di dalamnya harus WIB seperti aplikasi (config database
-- timezone +07:00), bukan zona waktu sistem server.
SET time_zone = '+07:00';

DELIMITER //

DROP EVENT IF EXISTS `ev_tandai_alpha_otomatis` //
CREATE EVENT `ev_tandai_alpha_otomatis` ON SCHEDULE EVERY 15 MINUTE STARTS '2026-11-27 22:15:36' ON COMPLETION PRESERVE ENABLE COMMENT 'Catat Alpha untuk shift Kerja yang berakhir tanpa check-in (sp_tandai_alpha_otomatis)' DO CALL sp_tandai_alpha_otomatis(NOW()) //

DELIMITER ;
