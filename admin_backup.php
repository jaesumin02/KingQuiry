<?php
/**
 * KingQuiry Database Backup & System Maintenance Controller
 * Academic Alignment: Systems Administration and Maintenance (Sir Sadd)
 *
 * Generates an on-demand SQL dump archive of the KingQuiry database for disaster
 * recovery and system maintenance. Restricted strictly to System Administrators.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/admin_management.php';

// Strict Role-Based Access Control (RBAC) check
if (normalized_user_role($_SESSION['user'] ?? null) !== 'Admin') {
    http_response_code(403);
    die('Forbidden: Administrator privileges required for database maintenance.');
}

$adminId = (int) ($_SESSION['user']['userID'] ?? 0);

// Log audit trail
kq_log_admin_action($conn, $adminId, 'Executed database backup export');

$filename = 'kingquiry_backup_' . date('Y-m-d_His') . '.sql';

header('Content-Type: application/sql');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

echo "-- ============================================================\n";
echo "-- KingQuiry Database System Backup & Disaster Recovery Export\n";
echo "-- Kings Land Properties &bull; Generated: " . date('Y-m-d H:i:s') . "\n";
echo "-- Admin User ID: $adminId\n";
echo "-- ============================================================\n\n";
echo "SET FOREIGN_KEY_CHECKS = 0;\n\n";

$tablesResult = $conn->query("SHOW FULL TABLES WHERE Table_Type = 'BASE TABLE'");
while ($tRow = $tablesResult->fetch_row()) {
    $table = $tRow[0];

    // Export CREATE TABLE
    $createResult = $conn->query("SHOW CREATE TABLE `$table`")->fetch_row();
    echo "-- Structure for table `$table`\n";
    echo "DROP TABLE IF EXISTS `$table`;\n";
    echo $createResult[1] . ";\n\n";

    // Export Data (skip large binary/temp if any, safely escape values)
    $rowsResult = $conn->query("SELECT * FROM `$table`");
    if ($rowsResult && $rowsResult->num_rows > 0) {
        echo "-- Dumping data for table `$table` (" . $rowsResult->num_rows . " records)\n";
        while ($row = $rowsResult->fetch_assoc()) {
            $columns = array_map(static fn($c) => "`$c`", array_keys($row));
            $escapedValues = array_map(static function ($val) use ($conn) {
                if ($val === null) {
                    return 'NULL';
                }
                return "'" . $conn->real_escape_string((string) $val) . "'";
            }, array_values($row));

            echo "INSERT INTO `$table` (" . implode(', ', $columns) . ") VALUES (" . implode(', ', $escapedValues) . ");\n";
        }
        echo "\n";
    }
}

echo "SET FOREIGN_KEY_CHECKS = 1;\n";
echo "-- Backup completed successfully.\n";
exit();
