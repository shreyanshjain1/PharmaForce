-- Signature geotagging migration for reports.
-- Import this in phpMyAdmin before using the geotag capture feature.

DELIMITER $$

DROP PROCEDURE IF EXISTS add_report_signature_geotag_columns $$
CREATE PROCEDURE add_report_signature_geotag_columns()
BEGIN
  IF NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'reports' AND COLUMN_NAME = 'signature_latitude') THEN
    ALTER TABLE reports ADD COLUMN signature_latitude DECIMAL(10,7) DEFAULT NULL;
  END IF;

  IF NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'reports' AND COLUMN_NAME = 'signature_longitude') THEN
    ALTER TABLE reports ADD COLUMN signature_longitude DECIMAL(10,7) DEFAULT NULL;
  END IF;

  IF NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'reports' AND COLUMN_NAME = 'signature_accuracy') THEN
    ALTER TABLE reports ADD COLUMN signature_accuracy DECIMAL(10,2) DEFAULT NULL;
  END IF;

  IF NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'reports' AND COLUMN_NAME = 'signature_captured_at') THEN
    ALTER TABLE reports ADD COLUMN signature_captured_at DATETIME DEFAULT NULL;
  END IF;

  IF NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'reports' AND COLUMN_NAME = 'signature_location_status') THEN
    ALTER TABLE reports ADD COLUMN signature_location_status VARCHAR(30) DEFAULT NULL;
  END IF;
END $$

CALL add_report_signature_geotag_columns() $$
DROP PROCEDURE IF EXISTS add_report_signature_geotag_columns $$

DROP PROCEDURE IF EXISTS add_report_signature_geotag_indexes $$
CREATE PROCEDURE add_report_signature_geotag_indexes()
BEGIN
  IF NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'reports' AND INDEX_NAME = 'idx_reports_signature_location') THEN
    ALTER TABLE reports ADD INDEX idx_reports_signature_location (signature_latitude, signature_longitude);
  END IF;

  IF NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'reports' AND INDEX_NAME = 'idx_reports_signature_captured_at') THEN
    ALTER TABLE reports ADD INDEX idx_reports_signature_captured_at (signature_captured_at);
  END IF;
END $$

CALL add_report_signature_geotag_indexes() $$
DROP PROCEDURE IF EXISTS add_report_signature_geotag_indexes $$

DELIMITER ;
