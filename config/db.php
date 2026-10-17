<?php
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$host = getenv('KINGQUIRY_DB_HOST') ?: 'localhost';
$username = getenv('KINGQUIRY_DB_USER') ?: 'root';
$password = getenv('KINGQUIRY_DB_PASSWORD') ?: '';
$dbname = getenv('KINGQUIRY_DB_NAME') ?: 'kingquiry_db';

try {
    $conn = new mysqli($host, $username, $password, $dbname);
    $conn->set_charset('utf8mb4');
} catch (mysqli_sql_exception $exception) {
    error_log('KingQuiry database connection failed: ' . $exception->getMessage());
    http_response_code(503);
    exit('The service is temporarily unavailable. Please try again later.');
}
?>