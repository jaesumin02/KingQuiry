<?php
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$host = getenv('KINGQUIRY_DB_HOST') ?: 'localhost';
$username = getenv('KINGQUIRY_DB_USER') ?: 'root';
$password = getenv('KINGQUIRY_DB_PASSWORD') ?: '';
$dbname = getenv('KINGQUIRY_DB_NAME') ?: 'kingquiry_db';

function flush_multi_query_results(mysqli $connection): void
{
    do {
        if ($result = $connection->store_result()) {
            $result->free();
        }
    } while ($connection->more_results() && $connection->next_result());
}

function table_is_usable(mysqli $connection, string $table): bool
{
    try {
        $result = $connection->query('SELECT 1 FROM `' . str_replace('`', '``', $table) . '` LIMIT 1');
        if ($result !== false) {
            $result->free();
            return true;
        }
    } catch (mysqli_sql_exception $exception) {
        return false;
    }

    return false;
}

function rebuild_kingquiry_schema(mysqli $connection, string $databaseName, array $requiredTables): void
{
    $schemaPath = dirname(__DIR__) . '/database/schema.sql';
    if (!is_file($schemaPath)) {
        throw new RuntimeException('Missing database schema file at ' . $schemaPath);
    }

    $schemaSql = file_get_contents($schemaPath);
    if ($schemaSql === false) {
        throw new RuntimeException('Unable to read database schema file at ' . $schemaPath);
    }

    $dropSql = 'SET FOREIGN_KEY_CHECKS = 0;';
    foreach (array_reverse($requiredTables) as $table) {
        $dropSql .= ' DROP TABLE IF EXISTS `' . str_replace('`', '``', $table) . '`;';
    }
    $dropSql .= ' SET FOREIGN_KEY_CHECKS = 1;';

    try {
        if (!$connection->multi_query($dropSql)) {
            throw new RuntimeException('Unable to reset table set: ' . $connection->error);
        }
        flush_multi_query_results($connection);

        if (!$connection->multi_query($schemaSql)) {
            throw new RuntimeException('Unable to initialize the KingQuiry database schema: ' . $connection->error);
        }
        flush_multi_query_results($connection);
    } catch (mysqli_sql_exception | RuntimeException $exception) {
        $connection->query('DROP DATABASE IF EXISTS `' . str_replace('`', '``', $databaseName) . '`');
        $connection->query('CREATE DATABASE IF NOT EXISTS `' . str_replace('`', '``', $databaseName) . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $connection->select_db($databaseName);

        if (!$connection->multi_query($schemaSql)) {
            throw new RuntimeException('Unable to initialize the KingQuiry database schema after a full reset: ' . $connection->error);
        }
        flush_multi_query_results($connection);
    }
}

function ensure_required_database_tables(mysqli $connection, string $databaseName): void
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
    ];

    foreach ($requiredTables as $table) {
        $showTables = $connection->query("SHOW TABLES LIKE '" . str_replace("'", "\\'", $table) . "'");
        $isMissing = $showTables === false || $showTables->num_rows === 0;
        $isBroken = !$isMissing && !table_is_usable($connection, $table);

        if ($isMissing || $isBroken) {
            rebuild_kingquiry_schema($connection, $databaseName, $requiredTables);
            return;
        }
    }
}

try {
    $conn = new mysqli($host, $username, $password, $dbname);
    $conn->set_charset('utf8mb4');
    ensure_required_database_tables($conn, $dbname);
} catch (mysqli_sql_exception $exception) {
    $message = strtolower($exception->getMessage());
    $missingDatabase = str_contains($message, 'unknown database') || (str_contains($message, 'database') && str_contains($message, 'does not exist'));

    if ($missingDatabase) {
        $adminConn = new mysqli($host, $username, $password);
        $adminConn->query('CREATE DATABASE IF NOT EXISTS `' . str_replace('`', '``', $dbname) . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $adminConn->select_db($dbname);
        ensure_required_database_tables($adminConn, $dbname);
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