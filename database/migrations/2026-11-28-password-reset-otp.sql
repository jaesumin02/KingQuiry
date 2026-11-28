-- Apply once to an existing database after backing it up.
-- Fresh installations should import database/schema.sql instead.

CREATE TABLE IF NOT EXISTS `password_reset_otps` (
    `userID` INT NOT NULL,
    `codeHash` VARCHAR(255) NOT NULL,
    `attempts` TINYINT UNSIGNED NOT NULL DEFAULT 0,
    `expiresAt` DATETIME NOT NULL,
    `createdAt` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`userID`),
    KEY `idx_password_reset_otp_expiry` (`expiresAt`),
    CONSTRAINT `fk_password_reset_otp_user`
        FOREIGN KEY (`userID`) REFERENCES `users` (`userID`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
