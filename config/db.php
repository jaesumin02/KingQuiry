<?php
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$host = getenv('KINGQUIRY_DB_HOST') ?: 'localhost';
$username = getenv('KINGQUIRY_DB_USER') ?: 'root';
$password = getenv('KINGQUIRY_DB_PASSWORD') ?: '';
$dbname = getenv('KINGQUIRY_DB_NAME') ?: 'kingquiry_db';
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

function flush_multi_query_results(mysqli $connection): void
{
    do {
        if ($result = $connection->store_result()) {
            $result->free();
        }
    } while ($connection->more_results() && $connection->next_result());
}

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

    foreach ($requiredTables as $table) {
        if (!isset($tableNames[$table])) {
            throw new RuntimeException("Required database table `$table` is missing. Apply the documented database migration; no data was changed.");
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
    $conn = new mysqli($host, $username, $password, $dbname);
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

        $adminConn = new mysqli($host, $username, $password);
        $adminConn->query('CREATE DATABASE IF NOT EXISTS `' . str_replace('`', '``', $dbname) . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $adminConn->select_db($dbname);
        ensure_required_database_tables($adminConn, true);
        $adminConn->close();

        $conn = new mysqli($host, $username, $password, $dbname);
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