-- Apply once to an existing database after backing it up.
-- Fresh installations should import database/schema.sql instead.

ALTER TABLE `users`
    ADD COLUMN `twoFactorEnabled` TINYINT(1) NOT NULL DEFAULT 0;

ALTER TABLE `properties`
    ADD COLUMN `availableDocuments` TEXT DEFAULT NULL AFTER `description`;

CREATE TABLE IF NOT EXISTS `login_2fa_challenges` (
    `userID` INT NOT NULL,
    `codeHash` VARCHAR(255) NOT NULL,
    `attempts` TINYINT UNSIGNED NOT NULL DEFAULT 0,
    `expiresAt` DATETIME NOT NULL,
    `createdAt` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`userID`),
    KEY `idx_login_2fa_expiry` (`expiresAt`),
    CONSTRAINT `fk_login_2fa_user`
        FOREIGN KEY (`userID`) REFERENCES `users` (`userID`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `password_reset_tokens` (
    `tokenHash` CHAR(64) NOT NULL,
    `userID` INT NOT NULL,
    `expiresAt` DATETIME NOT NULL,
    `createdAt` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`tokenHash`),
    KEY `idx_password_reset_user` (`userID`),
    KEY `idx_password_reset_expiry` (`expiresAt`),
    CONSTRAINT `fk_password_reset_user`
        FOREIGN KEY (`userID`) REFERENCES `users` (`userID`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
