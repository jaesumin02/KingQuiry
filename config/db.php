<?php
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// Lightweight environment loader: loads .env if present in project root
$envPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.env';
if (is_file($envPath) && is_readable($envPath)) {
    $envLines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (is_array($envLines)) {
        foreach ($envLines as $envLine) {
            $envLine = trim($envLine);
            if ($envLine === '' || str_starts_with($envLine, '#')) {
                continue;
            }
            if (str_contains($envLine, '=')) {
                [$envKey, $envVal] = explode('=', $envLine, 2);
                $envKey = trim($envKey);
                $envVal = trim($envVal);
                if ((str_starts_with($envVal, '"') && str_ends_with($envVal, '"')) ||
                    (str_starts_with($envVal, "'") && str_ends_with($envVal, "'"))) {
                    $envVal = substr($envVal, 1, -1);
                }
                if (getenv($envKey) === false) {
                    putenv("{$envKey}={$envVal}");
                    $_ENV[$envKey] = $envVal;
                    $_SERVER[$envKey] = $envVal;
                }
            }
        }
    }
}

$host = getenv('KINGQUIRY_DB_HOST') ?: 'localhost';
$username = getenv('KINGQUIRY_DB_USER') ?: 'root';
$password = getenv('KINGQUIRY_DB_PASSWORD') ?: '';
$dbname = getenv('KINGQUIRY_DB_NAME') ?: 'kingquiry_db';
$portValue = getenv('KINGQUIRY_DB_PORT');
$port = filter_var($portValue !== false ? $portValue : '3306', FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1, 'max_range' => 65535],
]);
if ($port === false) {
    error_log('KingQuiry database port is invalid.');
    http_response_code(503);
    exit('The service is temporarily unavailable. Please try again later.');
}
$appEnvironment = strtolower((string) (getenv('KINGQUIRY_ENV') ?: 'development'));

if (!in_array($appEnvironment, ['development', 'test', 'production'], true)) {
    error_log('KingQuiry environment must be development, test, or production.');
    http_response_code(503);
    exit('The service is temporarily unavailable. Please try again later.');
}

if ($appEnvironment === 'production' && ($username === 'root' || $password === '')) {
    error_log('KingQuiry production database credentials are not configured safely.');
    http_response_code(503);
    exit('The service is temporarily unavailable. Please try again later.');
}

if (
    $appEnvironment === 'production'
    && strlen((string) (getenv('KINGQUIRY_RATE_LIMIT_SECRET') ?: '')) < 32
) {
    error_log('KingQuiry production rate-limit secret is missing or too short.');
    http_response_code(503);
    exit('The service is temporarily unavailable. Please try again later.');
}

if (!function_exists('flush_multi_query_results')) {
    function flush_multi_query_results(mysqli $connection): void
    {
        do {
            if ($result = $connection->store_result()) {
                $result->free();
            }
        } while ($connection->more_results() && $connection->next_result());
    }
}

if (!function_exists('initialize_kingquiry_schema')) {
    function initialize_kingquiry_schema(mysqli $connection): void
    {
        $schemaPath = dirname(__DIR__) . '/database/schema.sql';
        if (!is_file($schemaPath)) {
            throw new RuntimeException('Missing database schema file at ' . $schemaPath);
        }

        $schemaSql = file_get_contents($schemaPath);
        if ($schemaSql === false) {
            throw new RuntimeException('Unable to read database schema file at ' . $schemaPath);
        }

        if (!$connection->multi_query($schemaSql)) {
            throw new RuntimeException('Unable to initialize the KingQuiry database schema: ' . $connection->error);
        }
        flush_multi_query_results($connection);
    }
}

function ensure_required_database_tables(mysqli $connection, bool $allowSchemaChanges): void
{
    $requiredTables = [
        'users',
        'property_locations',
        'properties',
        'appointments',
        'feedbackinquiries',
        'auditlogs',
        'chat_conversations',
        'chat_messages',
        'chat_admin_presence',
        'property_transactions',
        'property_transaction_events',
        'property_transaction_payments',
        'agent_reviews',
        'user_notifications',
        'payments',
        'security_rate_limits',
        'agent_inquiry_assignments',
        'agent_dismissed_appointments',
        'direct_messages',
    ];

    $existingTables = $connection->query('SHOW TABLES');
    $tableNames = [];
    while ($tableRow = $existingTables->fetch_row()) {
        $tableNames[(string) $tableRow[0]] = true;
    }

    if ($tableNames === []) {
        if (!$allowSchemaChanges) {
            throw new RuntimeException('The production database is empty. Apply the reviewed schema before deploying the application.');
        }
        initialize_kingquiry_schema($connection);
        return;
    }

    $missingTables = [];
    foreach ($requiredTables as $table) {
        if (!isset($tableNames[$table])) {
            $missingTables[] = $table;
        }
    }

    if ($missingTables !== []) {
        if (!$allowSchemaChanges) {
            throw new RuntimeException("Required database table `" . implode('`, `', $missingTables) . "` is missing. Apply the documented database migration; no data was changed.");
        }

        // In non-production environments, automatically create missing tables
        try {
            initialize_kingquiry_schema($connection);
        } catch (Throwable $e) {
            error_log('KingQuiry auto-schema import warning: ' . $e->getMessage());
        }

        // Verify and apply individual DDL fallbacks for any still-missing tables
        $existingTables = $connection->query('SHOW TABLES');
        $tableNames = [];
        while ($tableRow = $existingTables->fetch_row()) {
            $tableNames[(string) $tableRow[0]] = true;
        }

        $fallbacks = [
            'security_rate_limits' => "CREATE TABLE IF NOT EXISTS `security_rate_limits` (
                `key_hash` BINARY(32) NOT NULL,
                `window_started_at` DATETIME NOT NULL,
                `attempts` INT UNSIGNED NOT NULL DEFAULT 0,
                `expires_at` DATETIME NOT NULL,
                PRIMARY KEY (`key_hash`),
                KEY `idx_security_rate_limits_expiry` (`expires_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            'agent_dismissed_appointments' => "CREATE TABLE IF NOT EXISTS `agent_dismissed_appointments` (
                `agentID` INT NOT NULL,
                `appointmentID` INT NOT NULL,
                `dismissedAt` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`agentID`, `appointmentID`),
                KEY `idx_dismissed_appointment` (`appointmentID`),
                CONSTRAINT `fk_dismissed_agent` FOREIGN KEY (`agentID`) REFERENCES `users` (`userID`) ON DELETE CASCADE,
                CONSTRAINT `fk_dismissed_appointment` FOREIGN KEY (`appointmentID`) REFERENCES `appointments` (`appointmentID`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            'agent_inquiry_assignments' => "CREATE TABLE IF NOT EXISTS `agent_inquiry_assignments` (
                `inquiryID` INT NOT NULL,
                `agentID` INT NOT NULL,
                `assignedAt` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`inquiryID`),
                KEY `idx_agent_inquiry_assignments_agent` (`agentID`, `assignedAt`),
                CONSTRAINT `fk_agent_inquiry_assignments_inquiry` FOREIGN KEY (`inquiryID`) REFERENCES `feedbackinquiries` (`inquiryID`) ON DELETE CASCADE,
                CONSTRAINT `fk_agent_inquiry_assignments_agent` FOREIGN KEY (`agentID`) REFERENCES `users` (`userID`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            'user_notifications' => "CREATE TABLE IF NOT EXISTS `user_notifications` (
                `notificationID` INT NOT NULL AUTO_INCREMENT,
                `userID` INT NOT NULL,
                `title` VARCHAR(150) NOT NULL,
                `message` TEXT NOT NULL,
                `link` VARCHAR(255) DEFAULT NULL,
                `type` VARCHAR(50) DEFAULT 'general',
                `isRead` TINYINT(1) NOT NULL DEFAULT 0,
                `createdAt` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`notificationID`),
                KEY `idx_user_notifications_user_unread` (`userID`, `isRead`),
                KEY `idx_user_notifications_created` (`createdAt`),
                CONSTRAINT `fk_user_notifications_user` FOREIGN KEY (`userID`) REFERENCES `users` (`userID`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            'agent_reviews' => "CREATE TABLE IF NOT EXISTS `agent_reviews` (
                `reviewID` INT NOT NULL AUTO_INCREMENT,
                `transactionID` INT NOT NULL,
                `clientID` INT NOT NULL,
                `agentID` INT NOT NULL,
                `rating` TINYINT NOT NULL,
                `comment` TEXT DEFAULT NULL,
                `createdAt` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`reviewID`),
                UNIQUE KEY `uq_review_transaction` (`transactionID`),
                KEY `idx_agent_reviews_agent` (`agentID`),
                KEY `idx_agent_reviews_client` (`clientID`),
                CONSTRAINT `fk_agent_reviews_transaction` FOREIGN KEY (`transactionID`) REFERENCES `property_transactions` (`transactionID`) ON DELETE CASCADE,
                CONSTRAINT `fk_agent_reviews_client` FOREIGN KEY (`clientID`) REFERENCES `users` (`userID`) ON DELETE CASCADE,
                CONSTRAINT `fk_agent_reviews_agent` FOREIGN KEY (`agentID`) REFERENCES `users` (`userID`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            'payments' => "CREATE TABLE IF NOT EXISTS `payments` (
                `paymentID` INT NOT NULL AUTO_INCREMENT,
                `transactionID` INT NOT NULL,
                `clientID` INT NOT NULL,
                `amount` DECIMAL(12,2) NOT NULL,
                `paymentType` VARCHAR(50) NOT NULL,
                `paymentMethod` VARCHAR(50) NOT NULL,
                `referenceNo` VARCHAR(100) DEFAULT NULL,
                `status` ENUM('Pending', 'Verified', 'Rejected') NOT NULL DEFAULT 'Pending',
                `paidAt` DATETIME NOT NULL,
                `note` TEXT DEFAULT NULL,
                `recordedBy` INT DEFAULT NULL,
                `createdAt` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updatedAt` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`paymentID`),
                KEY `idx_payments_transaction` (`transactionID`),
                KEY `idx_payments_client` (`clientID`),
                KEY `idx_payments_status` (`status`),
                KEY `idx_payments_paid_at` (`paidAt`),
                CONSTRAINT `fk_payments_transaction` FOREIGN KEY (`transactionID`) REFERENCES `property_transactions` (`transactionID`) ON DELETE CASCADE,
                CONSTRAINT `fk_payments_client` FOREIGN KEY (`clientID`) REFERENCES `users` (`userID`) ON DELETE CASCADE,
                CONSTRAINT `fk_payments_recorded_by` FOREIGN KEY (`recordedBy`) REFERENCES `users` (`userID`) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            'direct_messages' => "CREATE TABLE IF NOT EXISTS `direct_messages` (
                `messageID` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `transactionID` BIGINT UNSIGNED NOT NULL,
                `senderID` INT NOT NULL,
                `receiverID` INT DEFAULT NULL,
                `message` TEXT NOT NULL,
                `isRead` TINYINT(1) NOT NULL DEFAULT 0,
                `isAutomated` TINYINT(1) NOT NULL DEFAULT 0,
                `createdAt` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`messageID`),
                KEY `idx_dm_transaction` (`transactionID`, `createdAt`),
                KEY `idx_dm_users` (`senderID`, `receiverID`),
                CONSTRAINT `fk_dm_transaction` FOREIGN KEY (`transactionID`) REFERENCES `property_transactions` (`transactionID`) ON DELETE CASCADE,
                CONSTRAINT `fk_dm_sender` FOREIGN KEY (`senderID`) REFERENCES `users` (`userID`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        ];

        foreach ($fallbacks as $tbl => $sql) {
            if (!isset($tableNames[$tbl])) {
                try {
                    $connection->query($sql);
                } catch (Throwable $e) {
                    error_log("KingQuiry fallback table creation for $tbl failed: " . $e->getMessage());
                }
            }
        }
    }

    if (!$allowSchemaChanges) {
        ensure_required_database_columns($connection, [
            'agent_dismissed_appointments' => ['agentID', 'appointmentID', 'dismissedAt'],
            'agent_inquiry_assignments' => ['inquiryID', 'agentID', 'assignedAt'],
            'agent_reviews' => ['reviewID', 'transactionID', 'clientID', 'agentID', 'rating', 'comment', 'createdAt'],
            'properties' => ['latitude', 'longitude'],
            'security_rate_limits' => ['key_hash', 'window_started_at', 'attempts', 'expires_at'],
            'user_notifications' => ['notificationID', 'userID', 'title', 'message', 'link', 'type', 'isRead', 'createdAt'],
        ]);
    }

    ensure_appointment_agent_column($connection, $allowSchemaChanges);
    ensure_property_coordinates_columns($connection, $allowSchemaChanges);
    ensure_property_trashbin_columns($connection, $allowSchemaChanges);
    ensure_user_management_columns($connection, $allowSchemaChanges);
    ensure_transaction_extended_columns($connection, $allowSchemaChanges);
}

function ensure_property_trashbin_columns(mysqli $connection, bool $allowSchemaChanges): void
{
    static $ensured = false;
    if ($ensured) {
        return;
    }
    try {
        $check = $connection->query("SHOW COLUMNS FROM `properties` LIKE 'isDeleted'");
        if ($check && $check->num_rows === 0) {
            if (!$allowSchemaChanges) {
                throw new RuntimeException("The production database is missing properties.isDeleted. Apply the reviewed migration.");
            }
            $connection->query("ALTER TABLE `properties` ADD COLUMN `isDeleted` TINYINT(1) NOT NULL DEFAULT 0 AFTER `image`, ADD COLUMN `deletedAt` TIMESTAMP NULL DEFAULT NULL AFTER `isDeleted`");
        }
        $checkLoc = $connection->query("SHOW COLUMNS FROM `property_locations` LIKE 'isDeleted'");
        if ($checkLoc && $checkLoc->num_rows === 0) {
            if (!$allowSchemaChanges) {
                throw new RuntimeException("The production database is missing property_locations.isDeleted. Apply the reviewed migration.");
            }
            $connection->query("ALTER TABLE `property_locations` ADD COLUMN `isDeleted` TINYINT(1) NOT NULL DEFAULT 0 AFTER `isActive`, ADD COLUMN `deletedAt` TIMESTAMP NULL DEFAULT NULL AFTER `isDeleted`");
        }
        $ensured = true;
    } catch (mysqli_sql_exception $exception) {
        error_log('KingQuiry trashbin column check failed: ' . $exception->getMessage());
        if (!$allowSchemaChanges) {
            throw new RuntimeException('Unable to verify the trashbin schema.', 0, $exception);
        }
    }
}

function ensure_user_management_columns(mysqli $connection, bool $allowSchemaChanges): void
{
    static $ensured = false;
    if ($ensured) {
        return;
    }
    try {
        $cols = [
            'phone' => 'VARCHAR(30) DEFAULT NULL',
            'address' => 'VARCHAR(255) DEFAULT NULL',
            'accountStatus' => "ENUM('Active', 'Inactive') NOT NULL DEFAULT 'Active'",
        ];
        foreach ($cols as $col => $def) {
            $check = $connection->query("SHOW COLUMNS FROM `users` LIKE '{$col}'");
            if ($check && $check->num_rows === 0) {
                if (!$allowSchemaChanges) {
                    throw new RuntimeException("The production database is missing users.{$col}. Apply the reviewed migration.");
                }
                $connection->query("ALTER TABLE `users` ADD COLUMN `{$col}` {$def}");
            }
        }
        $ensured = true;
    } catch (mysqli_sql_exception $exception) {
        error_log('KingQuiry user management column check failed: ' . $exception->getMessage());
        if (!$allowSchemaChanges) {
            throw new RuntimeException('Unable to verify user management schema.', 0, $exception);
        }
    }
}

function ensure_transaction_extended_columns(mysqli $connection, bool $allowSchemaChanges): void
{
    static $ensured = false;
    if ($ensured) {
        return;
    }
    try {
        $check = $connection->query("SHOW COLUMNS FROM `property_transactions` LIKE 'paymentMethod'");
        if ($check && $check->num_rows === 0) {
            if (!$allowSchemaChanges) {
                throw new RuntimeException("The production database is missing property_transactions.paymentMethod. Apply the reviewed migration.");
            }
            $connection->query("ALTER TABLE `property_transactions` ADD COLUMN `paymentMethod` VARCHAR(50) DEFAULT NULL AFTER `currentStage`");
        }
        $ensured = true;
    } catch (mysqli_sql_exception $exception) {
        error_log('KingQuiry transaction extended column check failed: ' . $exception->getMessage());
        if (!$allowSchemaChanges) {
            throw new RuntimeException('Unable to verify transaction extended schema.', 0, $exception);
        }
    }
}

function ensure_required_database_columns(mysqli $connection, array $requiredColumns): void
{
    $tableNames = array_keys($requiredColumns);
    $placeholders = implode(', ', array_fill(0, count($tableNames), '?'));
    $stmt = $connection->prepare(
        'SELECT TABLE_NAME, COLUMN_NAME
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME IN (' . $placeholders . ')'
    );
    $parameters = [str_repeat('s', count($tableNames))];
    foreach (array_keys($tableNames) as $index) {
        $parameters[] = &$tableNames[$index];
    }
    call_user_func_array([$stmt, 'bind_param'], $parameters);
    $stmt->execute();

    $presentColumns = [];
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $presentColumns[$row['TABLE_NAME']][$row['COLUMN_NAME']] = true;
    }

    foreach ($requiredColumns as $tableName => $columns) {
        foreach ($columns as $columnName) {
            if (!isset($presentColumns[$tableName][$columnName])) {
                throw new RuntimeException("The production database is missing `$tableName`.`$columnName`. Apply the documented migration.");
            }
        }
    }
}

function ensure_appointment_agent_column(mysqli $connection, bool $allowSchemaChanges): void
{
    $result = $connection->query("SHOW COLUMNS FROM `appointments` LIKE 'agentID'");
    if ($result->num_rows > 0) {
        return;
    }

    if (!$allowSchemaChanges) {
        throw new RuntimeException('The production appointments table is missing agentID. Apply the documented migration.');
    }

    try {
        $connection->query("ALTER TABLE `appointments` ADD COLUMN `agentID` INT DEFAULT NULL AFTER `clientID`, ADD KEY `idx_appointments_agent` (`agentID`)");
    } catch (mysqli_sql_exception $exception) {
        error_log('KingQuiry appointment-assignment schema update failed: ' . $exception->getMessage());
        throw new RuntimeException('Unable to verify the appointment assignment schema.', 0, $exception);
    }
}

function ensure_property_coordinates_columns(mysqli $connection, bool $allowSchemaChanges): void
{
    static $ensured = false;
    if ($ensured) {
        return;
    }
    try {
        $columns = [
            'latitude' => 'DECIMAL(10, 7) DEFAULT NULL AFTER `locationID`',
            'longitude' => 'DECIMAL(10, 7) DEFAULT NULL AFTER `latitude`',
        ];
        foreach ($columns as $column => $definition) {
            $check = $connection->query("SHOW COLUMNS FROM `properties` LIKE '{$column}'");
            if ($check->num_rows === 0) {
                if (!$allowSchemaChanges) {
                    throw new RuntimeException("The production database is missing the `$column` column. Apply its reviewed migration.");
                }
                $connection->query("ALTER TABLE `properties` ADD COLUMN `{$column}` {$definition}");
            }
        }
        $ensured = true;
    } catch (mysqli_sql_exception $exception) {
        error_log('KingQuiry property-coordinate schema check failed: ' . $exception->getMessage());
        throw new RuntimeException('Unable to verify the property coordinate schema.', 0, $exception);
    }
}

try {
    $conn = new mysqli($host, $username, $password, $dbname, $port);
    $conn->set_charset('utf8mb4');
    ensure_required_database_tables($conn, $appEnvironment !== 'production');
    ensure_property_coordinates_columns($conn, $appEnvironment !== 'production');
} catch (mysqli_sql_exception $exception) {
    $message = strtolower($exception->getMessage());
    $missingDatabase = str_contains($message, 'unknown database') || (str_contains($message, 'database') && str_contains($message, 'does not exist'));

    if ($missingDatabase) {
        if ($appEnvironment === 'production') {
            error_log('KingQuiry production database is missing; automatic database creation is disabled.');
            http_response_code(503);
            exit('The service is temporarily unavailable. Please try again later.');
        }

        $adminConn = new mysqli($host, $username, $password, '', $port);
        $adminConn->query('CREATE DATABASE IF NOT EXISTS `' . str_replace('`', '``', $dbname) . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $adminConn->select_db($dbname);
        ensure_required_database_tables($adminConn, true);
        $adminConn->close();

        $conn = new mysqli($host, $username, $password, $dbname, $port);
        $conn->set_charset('utf8mb4');
    } else {
        error_log('KingQuiry database connection failed: ' . $exception->getMessage());
        http_response_code(503);
        exit('The service is temporarily unavailable. Please try again later.');
    }
} catch (RuntimeException $exception) {
    error_log('KingQuiry database initialization failed: ' . $exception->getMessage());
    http_response_code(503);
    exit('The service is temporarily unavailable. Please try again later.');
}
?>