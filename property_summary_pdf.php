<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/location_directory.php';
require_once __DIR__ . '/includes/pdf_export.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!user_has_role($_SESSION['user'] ?? null, 'Client')) {
    http_response_code(403);
    echo 'Access denied.';
    exit();
}

$propertyId = filter_var($_GET['property_id'] ?? null, FILTER_VALIDATE_INT);
$propertyId = $propertyId === false ? 0 : max(0, $propertyId);
$appointmentId = filter_var($_GET['appointment_id'] ?? null, FILTER_VALIDATE_INT);
$appointmentId = $appointmentId === false ? null : max(0, $appointmentId);

$stmt = $conn->prepare(
    "SELECT p.*, l.municipalityName, l.provinceName, l.regionName
     FROM properties p
     LEFT JOIN property_locations l ON l.locationID = p.locationID
     WHERE p.propertyID = ?"
);
$stmt->bind_param('i', $propertyId);
$stmt->execute();
$property = $stmt->get_result()->fetch_assoc();

if (!$property) {
    http_response_code(404);
    echo 'Property summary not found.';
    exit();
}

$client = $_SESSION['user'];
$appointment = null;
if ($appointmentId !== null && $appointmentId > 0) {
    $appointmentStmt = $conn->prepare(
        "SELECT appointmentDate, appointmentTime, status
         FROM appointments
         WHERE appointmentID = ? AND clientID = ?"
    );
    $appointmentStmt->bind_param('ii', $appointmentId, (int) $client['userID']);
    $appointmentStmt->execute();
    $appointment = $appointmentStmt->get_result()->fetch_assoc();
}

$lines = [
    'KingQuiry Property Summary',
    'Generated: ' . date('Y-m-d H:i:s'),
    '',
    'Client: ' . ($client['fullname'] ?? 'Customer'),
    'Email: ' . ($client['email'] ?? 'Not available'),
    '',
    'Property: ' . $property['title'],
    'Category: ' . $property['propertyType'],
    'Location: ' . property_location_label($property),
    'Price: ₱' . number_format((float) $property['price'], 2),
    'Area: ' . number_format((float) ($property['areaSqm'] ?? 0), 2) . ' sq.m.',
    '',
    'Status: ' . ($property['status'] ?? 'Available'),
    'Reference: #KQ-' . (string) (int) $property['propertyID'],
    'Viewing request: ' . ($appointment['appointmentDate'] ?? 'Not scheduled'),
    'Viewing time: ' . ($appointment['appointmentTime'] ?? 'Not assigned'),
    'Request status: ' . ($appointment['status'] ?? 'No record'),
    '',
    'Thank you for choosing KingQuiry.',
    'This summary is for documentation and reference only.',
];

$pdf = pdf_build_document($lines);
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="kingquiry-property-summary.pdf"');
header('Content-Length: ' . strlen($pdf));
header('Cache-Control: no-store, no-cache, must-revalidate, private');
header('Pragma: no-cache');
header('Expires: 0');
echo $pdf;
exit();
