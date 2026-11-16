<?php
require_once __DIR__ . '/../config/db.php';
$res = $conn->query('SELECT clientID, currentStage, transactionID, propertyID FROM property_transactions');
while($r = $res->fetch_assoc()) {
    print_r($r);
}

$rev = $conn->query('SELECT * FROM agent_reviews');
echo "REVIEWS:\n";
while($r = $rev->fetch_assoc()) {
    print_r($r);
}
