-- Additive security rate-limit migration for an existing KingQuiry database.
-- Back up the database before applying schema changes.

CREATE TABLE IF NOT EXISTS security_rate_limits (
    key_hash BINARY(32) NOT NULL,
    window_started_at DATETIME NOT NULL,
    attempts INT UNSIGNED NOT NULL DEFAULT 0,
    expires_at DATETIME NOT NULL,
    PRIMARY KEY (key_hash),
    KEY idx_security_rate_limits_expiry (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
