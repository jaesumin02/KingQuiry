-- Additive coordinate-column migration for older KingQuiry databases.
-- Back up the database before applying schema changes.

SET @latitude_column_exists = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'properties'
      AND COLUMN_NAME = 'latitude'
);
SET @latitude_column_ddl = IF(
    @latitude_column_exists = 0,
    'ALTER TABLE properties ADD COLUMN latitude DECIMAL(10, 7) DEFAULT NULL AFTER locationID',
    'SELECT 1'
);
PREPARE latitude_column_statement FROM @latitude_column_ddl;
EXECUTE latitude_column_statement;
DEALLOCATE PREPARE latitude_column_statement;

SET @longitude_column_exists = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'properties'
      AND COLUMN_NAME = 'longitude'
);
SET @longitude_column_ddl = IF(
    @longitude_column_exists = 0,
    'ALTER TABLE properties ADD COLUMN longitude DECIMAL(10, 7) DEFAULT NULL AFTER latitude',
    'SELECT 1'
);
PREPARE longitude_column_statement FROM @longitude_column_ddl;
EXECUTE longitude_column_statement;
DEALLOCATE PREPARE longitude_column_statement;
