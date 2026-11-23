-- ============================================================
-- 20_perbaikan_jadwal_event_sertifikat_rollback.sql — Kebalikan 20_perbaikan_jadwal_event_sertifikat.sql.
-- ============================================================

-- [TAHAP 1]
DELIMITER //

ALTER EVENT ev_update_status_sertifikat
  ON SCHEDULE EVERY 1 DAY
  STARTS TIMESTAMP(CURRENT_DATE + INTERVAL 1 DAY, '06:05:00') //

DELIMITER ;
