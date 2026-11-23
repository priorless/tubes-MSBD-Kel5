-- ============================================================
-- 19_log_append_only_rollback.sql — Kebalikan tiap blok di 19_log_append_only.sql.
-- Dijalankan oleh down() migration yang bersesuaian.
-- ============================================================

-- [TAHAP 1]
-- Melepas trigger append-only; log kembali bisa diubah/dihapus.
DELIMITER //

DROP TRIGGER IF EXISTS trg_log_aktivitas_tolak_update //
DROP TRIGGER IF EXISTS trg_log_aktivitas_tolak_delete //

DELIMITER ;
