-- Additive notification migration for an existing KingQuiry database.
-- Back up the database before applying schema changes.

CREATE TABLE IF NOT EXISTS user_notifications (
    notificationID BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    userID INT NOT NULL,
    title VARCHAR(150) NOT NULL,
    message TEXT NOT NULL,
    link VARCHAR(255) DEFAULT NULL,
    type VARCHAR(50) NOT NULL DEFAULT 'general',
    isRead TINYINT(1) NOT NULL DEFAULT 0,
    createdAt TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (notificationID),
    KEY idx_user_notifications_user_read (userID, isRead, createdAt),
    KEY idx_user_notifications_created (createdAt),
    CONSTRAINT fk_user_notifications_user
        FOREIGN KEY (userID) REFERENCES users (userID) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
