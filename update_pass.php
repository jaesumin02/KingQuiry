<?php
include 'config/db.php';

$plainPassword = 'kingquiry2026';
$hashedPassword = password_hash($plainPassword, PASSWORD_DEFAULT);

$sql = "UPDATE users SET passwordHash = '$hashedPassword' WHERE email = 'admin@kingquiry.com'";

if ($conn->query($sql) === TRUE) {
    echo "<h2 style='color: green; text-align: center; margin-top: 50px;'>Success! Ang password ng admin ay pinalitan na ng 'kingquiry2026'.</h2>";
    echo "<p style='text-align: center;'><a href='login.php'>Mag-click dito para mag-log in</a></p>";
} else {
    echo "Error updating record: " . $conn->error;
}
?>