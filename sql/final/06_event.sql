-- ============================================================
-- sql/final/06_event.sql — Event (butuh event_scheduler=ON agar berjalan)
-- Diekspor dari database db_sji (MySQL 8) setelah Finalisasi Tahap 1–10. DEFINER dihapus agar bisa diimpor
-- di komputer lain. Urutan impor: lihat sql/README.md.
-- ============================================================

DELIMITER //

DROP EVENT IF EXISTS `ev_update_status_sertifikat` //
CREATE EVENT `ev_update_status_sertifikat` ON SCHEDULE EVERY 1 DAY STARTS '2026-11-17 06:05:00' ON COMPLETION PRESERVE ENABLE COMMENT 'Tandai sertifikat Kadaluarsa bila tgl_kadaluarsa < hari ini' DO UPDATE tbl_sertifikat
  SET status = 'Kadaluarsa'
  WHERE status <> 'Kadaluarsa' AND tgl_kadaluarsa < CURDATE() //

DELIMITER ;
