-- Apply once to an existing database after backing it up.
-- Fresh installations should import database/schema.sql instead.

ALTER TABLE `users`
    ADD COLUMN `phoneVerifiedAt` DATETIME DEFAULT NULL AFTER `phone`;

CREATE TABLE IF NOT EXISTS `phone_verification_otps` (
    `userID` INT NOT NULL,
    `codeHash` VARCHAR(255) NOT NULL,
    `attempts` TINYINT UNSIGNED NOT NULL DEFAULT 0,
    `expiresAt` DATETIME NOT NULL,
    `createdAt` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`userID`),
    KEY `idx_phone_verification_otp_expiry` (`expiresAt`),
    CONSTRAINT `fk_phone_verification_otp_user`
        FOREIGN KEY (`userID`) REFERENCES `users` (`userID`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
