-- ============================================================
-- 20_perbaikan_jadwal_event_sertifikat.sql — Mengembalikan jam mulai ev_update_status_sertifikat ke 00:05
-- (sesuai 11_lengkapi_fitur.sql). Ekspor sql/final/06_event.sql sempat tercatat 06:05.
-- ============================================================

-- [TAHAP 1]
DELIMITER //

ALTER EVENT ev_update_status_sertifikat
  ON SCHEDULE EVERY 1 DAY
  STARTS TIMESTAMP(CURRENT_DATE + INTERVAL 1 DAY, '00:05:00') //

DELIMITER ;
