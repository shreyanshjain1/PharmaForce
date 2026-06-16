-- Visit Verification Manual Review Support
-- Import this into the PharmaForce database before managers save manual visit verification decisions.

DELIMITER $$

DROP PROCEDURE IF EXISTS AddReportVerificationColumn $$
CREATE PROCEDURE AddReportVerificationColumn(
    IN columnName VARCHAR(64),
    IN columnDefinition TEXT
)
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'reports'
          AND COLUMN_NAME = columnName
    ) THEN
        SET @ddl = CONCAT('ALTER TABLE reports ADD COLUMN ', columnName, ' ', columnDefinition);
        PREPARE stmt FROM @ddl;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;
END $$

CALL AddReportVerificationColumn('visit_verification_status', 'VARCHAR(40) NULL AFTER signature_location_status') $$
CALL AddReportVerificationColumn('visit_verification_method', 'VARCHAR(30) NULL AFTER visit_verification_status') $$
CALL AddReportVerificationColumn('visit_verification_distance_m', 'DECIMAL(10,2) NULL AFTER visit_verification_method') $$
CALL AddReportVerificationColumn('visit_verification_comment', 'TEXT NULL AFTER visit_verification_distance_m') $$
CALL AddReportVerificationColumn('visit_verification_reviewed_by', 'INT NULL AFTER visit_verification_comment') $$
CALL AddReportVerificationColumn('visit_verification_reviewed_at', 'DATETIME NULL AFTER visit_verification_reviewed_by') $$

DROP PROCEDURE IF EXISTS AddReportVerificationIndex $$
CREATE PROCEDURE AddReportVerificationIndex()
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM INFORMATION_SCHEMA.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'reports'
          AND INDEX_NAME = 'idx_report_visit_verification_status'
    ) THEN
        ALTER TABLE reports ADD INDEX idx_report_visit_verification_status (visit_verification_status);
    END IF;
END $$

CALL AddReportVerificationIndex() $$

DROP PROCEDURE IF EXISTS AddReportVerificationColumn $$
DROP PROCEDURE IF EXISTS AddReportVerificationIndex $$

DELIMITER ;
