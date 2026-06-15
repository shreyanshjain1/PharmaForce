-- Doctor Map Pin Location Setup
-- Import this into the PharmaForce database before using the doctor map pin feature.

DELIMITER $$

DROP PROCEDURE IF EXISTS AddDoctorLocationColumn $$
CREATE PROCEDURE AddDoctorLocationColumn(
    IN columnName VARCHAR(64),
    IN columnDefinition TEXT
)
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'doctors_masterlist'
          AND COLUMN_NAME = columnName
    ) THEN
        SET @ddl = CONCAT('ALTER TABLE doctors_masterlist ADD COLUMN ', columnName, ' ', columnDefinition);
        PREPARE stmt FROM @ddl;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;
END $$

CALL AddDoctorLocationColumn('clinic_latitude', 'DECIMAL(10,7) NULL AFTER place') $$
CALL AddDoctorLocationColumn('clinic_longitude', 'DECIMAL(10,7) NULL AFTER clinic_latitude') $$
CALL AddDoctorLocationColumn('allowed_visit_radius_m', 'INT NULL DEFAULT 200 AFTER clinic_longitude') $$
CALL AddDoctorLocationColumn('location_updated_by', 'INT NULL AFTER allowed_visit_radius_m') $$
CALL AddDoctorLocationColumn('location_updated_at', 'DATETIME NULL AFTER location_updated_by') $$

DROP PROCEDURE IF EXISTS AddDoctorLocationIndex $$
CREATE PROCEDURE AddDoctorLocationIndex()
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM INFORMATION_SCHEMA.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'doctors_masterlist'
          AND INDEX_NAME = 'idx_doctor_clinic_location'
    ) THEN
        ALTER TABLE doctors_masterlist ADD INDEX idx_doctor_clinic_location (clinic_latitude, clinic_longitude);
    END IF;
END $$

CALL AddDoctorLocationIndex() $$

DROP PROCEDURE IF EXISTS AddDoctorLocationColumn $$
DROP PROCEDURE IF EXISTS AddDoctorLocationIndex $$

DELIMITER ;
