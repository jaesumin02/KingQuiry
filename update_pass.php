<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Not found.');
}

if ($argc !== 3 || $argv[1] === '' || strlen($argv[1]) > 100 || strlen($argv[2]) < 8 || strlen($argv[2]) > 72) {
    fwrite(STDERR, "Usage: php update_pass.php <account-email> <new-password (8-72 characters)>\n");
    exit(1);
}

include 'config/db.php';
$passwordHash = password_hash($argv[2], PASSWORD_DEFAULT);
if ($passwordHash === false) {
    fwrite(STDERR, "Could not hash the supplied password.\n");
    exit(1);
}

$stmt = $conn->prepare('UPDATE users SET passwordHash = ? WHERE email = ? AND role = ?');
$role = 'Admin';
$stmt->bind_param('sss', $passwordHash, $argv[1], $role);
$stmt->execute();
if ($stmt->affected_rows !== 1) {
    fwrite(STDERR, "No administrator password was changed. Check the email address and try again.\n");
    exit(1);
}

fwrite(STDOUT, "Administrator password updated.\n");
?>