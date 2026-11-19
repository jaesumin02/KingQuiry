<?php
include 'config/db.php';
require_once 'includes/auth.php';
require_once 'includes/location_directory.php';
require_once 'includes/property_categories.php';
require_once 'includes/property_estimator.php';
require_once 'includes/property_images.php';
require_once 'includes/agent_feedback.php';
require_once 'includes/admin_management.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (normalized_user_role($_SESSION['user'] ?? null) !== 'Admin') {
    header('Location: admin_login.php');
    exit();
}

ensure_agent_reviews_table($conn);
ensure_admin_management_schema($conn);

$locations = get_property_locations($conn);
$formError = '';
$formSuccess = isset($_GET['property_added'])
    ? 'The property listing has been published.'
    : (isset($_GET['property_updated']) ? 'The property listing has been updated.' : '');
$estimate = null;
$estimateError = '';
$inputSqm = '';
$inputType = 'Residential';
$estimateLocationId = 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!has_valid_csrf_token()) {
        $formError = 'Your session expired. Refresh the page and try again.';
    } elseif (isset($_POST['add_property'])) {
        $title = is_string($_POST['title'] ?? null) ? trim($_POST['title']) : '';
        $description = is_string($_POST['description'] ?? null) ? trim($_POST['description']) : '';
        $price = filter_var($_POST['price'] ?? null, FILTER_VALIDATE_FLOAT);
        $areaSqm = filter_var($_POST['areaSqm'] ?? null, FILTER_VALIDATE_FLOAT);
        $locationId = filter_var($_POST['locationID'] ?? null, FILTER_VALIDATE_INT);
        $propertyType = is_string($_POST['propertyType'] ?? null) ? $_POST['propertyType'] : '';
        $landCharacteristics = is_string($_POST['landCharacteristics'] ?? null) ? trim($_POST['landCharacteristics']) : '';
        $barangay = is_string($_POST['barangay'] ?? null) ? trim($_POST['barangay']) : '';
        $location = $locationId === false ? null : find_property_location($locations, (int) $locationId);

        $latitude = filter_var($_POST['latitude'] ?? null, FILTER_VALIDATE_FLOAT);
        $longitude = filter_var($_POST['longitude'] ?? null, FILTER_VALIDATE_FLOAT);
        if ($latitude === false || $latitude < -90 || $latitude > 90) {
            $latitude = null;
        }
        if ($longitude === false || $longitude < -180 || $longitude > 180) {
            $longitude = null;
        }
        if ($latitude === null || $longitude === null) {
            $latitude = null;
            $longitude = null;
        }

        if (strlen($title) < 3 || strlen($title) > 150 || strlen($description) < 10 || strlen($description) > 10000) {
            $formError = 'Enter a title (3-150 characters) and a description (10-10,000 characters).';
        } elseif ($price === false || $price <= 0 || $price > 9999999999.99 || $areaSqm === false || $areaSqm < 0.01 || $areaSqm > 1000000) {
            $formError = 'Enter a valid total price and a land area between 0 and 1,000,000 sq.m.';
        } elseif (!in_array($propertyType, property_type_values(), true)) {
            $formError = 'Choose a valid property category.';
        } elseif (!$location || strlen($barangay) > 120 || strlen($landCharacteristics) > 2000) {
            $formError = 'Choose an active directory location and check the optional land details.';
        } else {
            $canonicalLocation = $location['municipalityName'] . ', ' . $location['provinceName'];
            $pricePerSqm = round((float) $price / (float) $areaSqm, 2);
            if ($pricePerSqm > 9999999999.99) {
                $formError = 'The calculated price per square meter is outside the supported price range.';
            } else {
            try {
                $imageName = store_property_image($_FILES['propertyImage'] ?? []);
                $stmt = $conn->prepare(
                    "INSERT INTO properties (title, description, price, pricePerSqm, location, propertyType, landCharacteristics, locationID, latitude, longitude, barangay, areaSqm, image)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
                );
                $stmt->bind_param('ssddsssiddsds', $title, $description, $price, $pricePerSqm, $canonicalLocation, $propertyType, $landCharacteristics, $locationId, $latitude, $longitude, $barangay, $areaSqm, $imageName);
                try {
                    $stmt->execute();
                    header('Location: admin_dashboard.php?property_added=1#properties');
                    exit();
                } catch (mysqli_sql_exception $exception) {
                    if ($imageName !== 'default.jpg') {
                        unlink(__DIR__ . '/assets/images/' . $imageName);
                    }
                    throw $exception;
                }
            } catch (InvalidArgumentException $exception) {
                $formError = $exception->getMessage();
            } catch (mysqli_sql_exception $exception) {
                error_log('KingQuiry property insert failed: ' . $exception->getMessage());
                $formError = 'The property could not be saved. Check the database connection and try again.';
            } catch (RuntimeException $exception) {
                $formError = $exception->getMessage();
            }
            }
        }
    } elseif (isset($_POST['update_property'])) {
        $editPropertyId = filter_var($_POST['propertyID'] ?? null, FILTER_VALIDATE_INT);
        $title = is_string($_POST['title'] ?? null) ? trim($_POST['title']) : '';
        $description = is_string($_POST['description'] ?? null) ? trim($_POST['description']) : '';
        $price = filter_var($_POST['price'] ?? null, FILTER_VALIDATE_FLOAT);
        $areaSqm = filter_var($_POST['areaSqm'] ?? null, FILTER_VALIDATE_FLOAT);
        $locationId = filter_var($_POST['locationID'] ?? null, FILTER_VALIDATE_INT);
        $propertyType = is_string($_POST['propertyType'] ?? null) ? $_POST['propertyType'] : '';
        $landCharacteristics = is_string($_POST['landCharacteristics'] ?? null) ? trim($_POST['landCharacteristics']) : '';
        $barangay = is_string($_POST['barangay'] ?? null) ? trim($_POST['barangay']) : '';
        $location = $locationId === false ? null : find_property_location($locations, (int) $locationId);

        $latitudeInput = is_string($_POST['latitude'] ?? null) ? trim($_POST['latitude']) : '';
        $longitudeInput = is_string($_POST['longitude'] ?? null) ? trim($_POST['longitude']) : '';
        $latitude = $latitudeInput === '' ? null : filter_var($latitudeInput, FILTER_VALIDATE_FLOAT);
        $longitude = $longitudeInput === '' ? null : filter_var($longitudeInput, FILTER_VALIDATE_FLOAT);
        $coordinatesValid = ($latitudeInput === '' && $longitudeInput === '')
            || (
                $latitudeInput !== ''
                && $longitudeInput !== ''
                && $latitude !== false
                && $latitude >= -90
                && $latitude <= 90
                && $longitude !== false
                && $longitude >= -180
                && $longitude <= 180
            );
        $imageUpload = $_FILES['propertyImage'] ?? [];
        $hasImageUpload = is_array($imageUpload) && ($imageUpload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;

        if ($editPropertyId === false || $editPropertyId <= 0) {
            $formError = 'Choose a valid property to edit.';
        } elseif (strlen($title) < 3 || strlen($title) > 150 || strlen($description) < 10 || strlen($description) > 10000) {
            $formError = 'Enter a title (3-150 characters) and a description (10-10,000 characters).';
        } elseif ($price === false || $price <= 0 || $price > 9999999999.99 || $areaSqm === false || $areaSqm < 0.01 || $areaSqm > 1000000) {
            $formError = 'Enter a valid total price and a land area between 0 and 1,000,000 sq.m.';
        } elseif (round((float) $price / (float) $areaSqm, 2) > 9999999999.99) {
            $formError = 'The calculated price per square meter is outside the supported price range.';
        } elseif (!in_array($propertyType, property_type_values(), true)) {
            $formError = 'Choose a valid property category.';
        } elseif (!$location || strlen($barangay) > 120 || strlen($landCharacteristics) > 2000) {
            $formError = 'Choose an active directory location and check the optional land details.';
        } elseif (!$coordinatesValid) {
            $formError = 'Enter both valid map coordinates or leave both blank to use the municipality location.';
        } else {
            $existingStmt = $conn->prepare('SELECT image FROM properties WHERE propertyID = ? AND isDeleted = 0');
            $existingStmt->bind_param('i', $editPropertyId);
            $existingStmt->execute();
            $existingProperty = $existingStmt->get_result()->fetch_assoc();

            if (!$existingProperty) {
                $formError = 'The active property listing could not be found.';
            } else {
                $newImageName = null;
                $transactionStarted = false;
                $databaseUpdated = false;
                $commitAttempted = false;
                $oldImagePath = '';
                $cleanupNewImage = static function () use (&$newImageName): void {
                    if ($newImageName !== null && $newImageName !== 'default.jpg') {
                        $newImagePath = __DIR__ . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'images' . DIRECTORY_SEPARATOR . basename($newImageName);
                        if (is_file($newImagePath) && !unlink($newImagePath)) {
                            error_log('KingQuiry property image cleanup failed: ' . $newImagePath);
                        }
                    }
                };
                try {
                    if ($hasImageUpload) {
                        $newImageName = store_property_image($imageUpload);
                    }

                    $canonicalLocation = $location['municipalityName'] . ', ' . $location['provinceName'];
                    $pricePerSqm = round((float) $price / (float) $areaSqm, 2);
                    $updateStmt = $conn->prepare(
                        'UPDATE properties
                         SET title = ?, description = ?, price = ?, pricePerSqm = ?, location = ?, propertyType = ?,
                             landCharacteristics = ?, locationID = ?, latitude = ?, longitude = ?, barangay = ?,
                             areaSqm = ?, image = COALESCE(?, image)
                         WHERE propertyID = ? AND isDeleted = 0'
                    );
                    $updateStmt->bind_param(
                        'ssddsssiddsdsi',
                        $title,
                        $description,
                        $price,
                        $pricePerSqm,
                        $canonicalLocation,
                        $propertyType,
                        $landCharacteristics,
                        $locationId,
                        $latitude,
                        $longitude,
                        $barangay,
                        $areaSqm,
                        $newImageName,
                        $editPropertyId
                    );
                    if (!$conn->begin_transaction()) {
                        throw new RuntimeException('The database transaction could not be started. Please try again.');
                    }
                    $transactionStarted = true;
                    $lockStmt = $conn->prepare('SELECT image FROM properties WHERE propertyID = ? AND isDeleted = 0 FOR UPDATE');
                    $lockStmt->bind_param('i', $editPropertyId);
                    $lockStmt->execute();
                    $lockedProperty = $lockStmt->get_result()->fetch_assoc();
                    if (!$lockedProperty) {
                        throw new RuntimeException('This property listing is no longer active and could not be updated.');
                    }
                    $existingProperty = $lockedProperty;
                    $updateStmt->execute();

                    $removeOldImage = false;
                    if ($newImageName !== null && $newImageName !== 'default.jpg') {
                        $oldImageValue = (string) ($existingProperty['image'] ?? '');
                        $oldImageName = basename($oldImageValue);
                        $oldImagePath = __DIR__ . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'images' . DIRECTORY_SEPARATOR . $oldImageName;
                        if ($oldImageName !== '' && $oldImageName !== 'default.jpg' && is_file($oldImagePath)) {
                            $imageReferenceStmt = $conn->prepare('SELECT COUNT(*) AS referencesCount FROM properties WHERE image = ? AND propertyID <> ?');
                            $imageReferenceStmt->bind_param('si', $oldImageValue, $editPropertyId);
                            $imageReferenceStmt->execute();
                            $otherImageReferences = (int) $imageReferenceStmt->get_result()->fetch_assoc()['referencesCount'];
                            $removeOldImage = $otherImageReferences === 0;
                        }
                    }

                    $commitAttempted = true;
                    if (!$conn->commit()) {
                        throw new RuntimeException('The property changes could not be committed. Please check the listing before retrying.');
                    }
                    $transactionStarted = false;
                    $databaseUpdated = true;
                    if ($removeOldImage && $oldImagePath !== '' && is_file($oldImagePath) && !unlink($oldImagePath)) {
                        error_log('KingQuiry replaced property image could not be removed: ' . $oldImagePath);
                    }

                    header('Location: admin_dashboard.php?property_updated=1#property-list');
                    exit();
                } catch (InvalidArgumentException $exception) {
                    if ($transactionStarted) {
                        $conn->rollback();
                    }
                    if (!$databaseUpdated && !$commitAttempted) {
                        $cleanupNewImage();
                    }
                    $formError = $exception->getMessage();
                } catch (mysqli_sql_exception $exception) {
                    if ($transactionStarted) {
                        $conn->rollback();
                    }
                    if (!$databaseUpdated && !$commitAttempted) {
                        $cleanupNewImage();
                    }
                    error_log('KingQuiry property update failed: ' . $exception->getMessage());
                    $formError = 'The property could not be updated. Check the database connection and try again.';
                } catch (RuntimeException $exception) {
                    if ($transactionStarted) {
                        $conn->rollback();
                    }
                    if (!$databaseUpdated && !$commitAttempted) {
                        $cleanupNewImage();
                    }
                    $formError = $exception->getMessage();
                }
            }
        }
    } elseif (isset($_POST['delete_property']) || isset($_POST['trash_property'])) {
        $propertyId = filter_var($_POST['propertyID'] ?? null, FILTER_VALIDATE_INT);
        if ($propertyId === false || $propertyId <= 0) {
            $formError = 'Choose a valid property to remove.';
        } else {
            $titleStmt = $conn->prepare('SELECT title FROM properties WHERE propertyID = ?');
            $titleStmt->bind_param('i', $propertyId);
            $titleStmt->execute();
            $pRow = $titleStmt->get_result()->fetch_assoc();
            $pTitle = $pRow ? (string) $pRow['title'] : 'Property';

            $trashStmt = $conn->prepare('UPDATE properties SET isDeleted = 1, deletedAt = CURRENT_TIMESTAMP WHERE propertyID = ?');
            $trashStmt->bind_param('i', $propertyId);
            $trashStmt->execute();
            header('Location: admin_dashboard.php?property_trashed=1&pname=' . urlencode($pTitle) . '#property-list');
            exit();
        }
    } elseif (isset($_POST['restore_property'])) {
        $propertyId = filter_var($_POST['propertyID'] ?? null, FILTER_VALIDATE_INT);
        if ($propertyId && $propertyId > 0) {
            $restStmt = $conn->prepare('UPDATE properties SET isDeleted = 0, deletedAt = NULL WHERE propertyID = ?');
            $restStmt->bind_param('i', $propertyId);
            $restStmt->execute();
            header('Location: admin_dashboard.php?property_restored=1#property-list');
            exit();
        }
    } elseif (isset($_POST['bulk_restore'])) {
        $selectedIds = $_POST['selectedPropertyIDs'] ?? [];
        if (is_array($selectedIds) && !empty($selectedIds)) {
            $validIds = array_filter(array_map('intval', $selectedIds), fn($id) => $id > 0);
            if (!empty($validIds)) {
                $inClause = implode(',', $validIds);
                $conn->query("UPDATE properties SET isDeleted = 0, deletedAt = NULL WHERE propertyID IN ($inClause)");
                header('Location: admin_dashboard.php?properties_restored=1&count=' . count($validIds) . '#property-list');
                exit();
            }
        }
        $formError = 'Select at least one property to restore.';
    } elseif (isset($_POST['permanent_delete_property'])) {
        $propertyId = filter_var($_POST['propertyID'] ?? null, FILTER_VALIDATE_INT);
        if ($propertyId === false || $propertyId <= 0) {
            $formError = 'Choose a valid property to delete permanently.';
        } else {
            $imgStmt = $conn->prepare('SELECT image FROM properties WHERE propertyID = ?');
            $imgStmt->bind_param('i', $propertyId);
            $imgStmt->execute();
            $imgRow = $imgStmt->get_result()->fetch_assoc();
            if ($imgRow && !empty($imgRow['image']) && $imgRow['image'] !== 'default.jpg') {
                $imgPath = __DIR__ . '/assets/images/' . $imgRow['image'];
                if (file_exists($imgPath)) {
                    @unlink($imgPath);
                }
            }

            $delStmt = $conn->prepare('DELETE FROM properties WHERE propertyID = ?');
            $delStmt->bind_param('i', $propertyId);
            try {
                $delStmt->execute();
                header('Location: admin_dashboard.php?property_permanently_deleted=1#property-list');
                exit();
            } catch (mysqli_sql_exception $exception) {
                error_log('KingQuiry property delete failed: ' . $exception->getMessage());
                $formError = 'The property could not be permanently removed. Related transaction records may prevent deletion.';
            }
        }
    } elseif (isset($_POST['bulk_permanent_delete'])) {
        $selectedIds = $_POST['selectedPropertyIDs'] ?? [];
        if (!is_array($selectedIds) || empty($selectedIds)) {
            $formError = 'Select at least one property to delete permanently.';
        } else {
            $validIds = array_filter(array_map('intval', $selectedIds), fn($id) => $id > 0);
            if (!empty($validIds)) {
                $inClause = implode(',', $validIds);
                $imgRes = $conn->query("SELECT image FROM properties WHERE propertyID IN ($inClause)");
                if ($imgRes) {
                    while ($imgRow = $imgRes->fetch_assoc()) {
                        if (!empty($imgRow['image']) && $imgRow['image'] !== 'default.jpg') {
                            $imgPath = __DIR__ . '/assets/images/' . $imgRow['image'];
                            if (file_exists($imgPath)) {
                                @unlink($imgPath);
                            }
                        }
                    }
                }
                try {
                    $conn->query("DELETE FROM properties WHERE propertyID IN ($inClause)");
                    header('Location: admin_dashboard.php?properties_permanently_deleted=1&count=' . count($validIds) . '#property-list');
                    exit();
                } catch (mysqli_sql_exception $e) {
                    error_log('KingQuiry bulk property delete failed: ' . $e->getMessage());
                    $formError = 'Some properties could not be deleted permanently due to existing transaction records.';
                }
            }
        }
    } elseif (isset($_POST['set_property_status'])) {
        $propertyId = filter_var($_POST['propertyID'] ?? null, FILTER_VALIDATE_INT);
        $propertyStatus = is_string($_POST['propertyStatus'] ?? null) ? $_POST['propertyStatus'] : '';
        $allowedPropertyStatuses = ['Available', 'Reserved', 'Sold'];
        if ($propertyId === false || $propertyId <= 0 || !in_array($propertyStatus, $allowedPropertyStatuses, true)) {
            $formError = 'Choose a valid property and listing status.';
        } else {
            $statusStmt = $conn->prepare('UPDATE properties SET status = ? WHERE propertyID = ?');
            $statusStmt->bind_param('si', $propertyStatus, $propertyId);
            $statusStmt->execute();
            if ($statusStmt->affected_rows > 0) {
                header('Location: admin_dashboard.php?property_status_updated=1#properties');
                exit();
            }

            $checkStmt = $conn->prepare('SELECT propertyID FROM properties WHERE propertyID = ?');
            $checkStmt->bind_param('i', $propertyId);
            $checkStmt->execute();
            if (!$checkStmt->get_result()->fetch_assoc()) {
                $formError = 'The selected property was not found.';
            } else {
                header('Location: admin_dashboard.php?property_status_updated=1#properties');
                exit();
            }
        }
    } elseif (isset($_POST['run_prediction'])) {
        $inputSqm = filter_var($_POST['sqm_input'] ?? null, FILTER_VALIDATE_FLOAT);
        $inputType = is_string($_POST['property_type'] ?? null) ? $_POST['property_type'] : '';
        $estimateLocationId = filter_var($_POST['locationID'] ?? null, FILTER_VALIDATE_INT);
        if ($inputSqm === false || $estimateLocationId === false) {
            $estimateError = 'Enter a valid land area and choose a location.';
        } else {
            try {
                $estimate = estimate_property_value($conn, (float) $inputSqm, $inputType, (int) $estimateLocationId);
            } catch (InvalidArgumentException | RuntimeException $exception) {
                $estimateError = $exception->getMessage();
            }
        }
    }
}

if (isset($_GET['property_deleted'])) {
    $formSuccess = 'The property listing has been removed.';
}
if (isset($_GET['property_trashed'])) {
    $pname = is_string($_GET['pname'] ?? null) ? htmlspecialchars($_GET['pname'], ENT_QUOTES, 'UTF-8') : 'The property';
    $formSuccess = "Property '$pname' was moved to the trashbin.";
}
if (isset($_GET['property_restored'])) {
    $formSuccess = 'Property was restored successfully from the trashbin.';
}
if (isset($_GET['properties_restored'])) {
    $count = (int) ($_GET['count'] ?? 1);
    $formSuccess = "$count properties were restored successfully from the trashbin.";
}
if (isset($_GET['property_permanently_deleted'])) {
    $formSuccess = 'Property was permanently deleted from the trashbin.';
}
if (isset($_GET['properties_permanently_deleted'])) {
    $count = (int) ($_GET['count'] ?? 1);
    $formSuccess = "$count properties were permanently deleted from the trashbin.";
}
if (isset($_GET['property_status_updated'])) {
    $formSuccess = 'The property listing status has been updated.';
}

include 'includes/header.php';
$totalProperties = $conn->query("SELECT COUNT(*) as count FROM properties WHERE isDeleted = 0")->fetch_assoc()['count'];
$totalTrashedProperties = $conn->query("SELECT COUNT(*) as count FROM properties WHERE isDeleted = 1")->fetch_assoc()['count'];
$totalUsers = $conn->query("SELECT COUNT(*) as count FROM users")->fetch_assoc()['count'];
$totalAppointments = $conn->query("SELECT COUNT(*) as count FROM appointments")->fetch_assoc()['count'];
$totalInquiries = $conn->query("SELECT COUNT(*) as count FROM feedbackinquiries")->fetch_assoc()['count'];

$standardPropertyCategories = property_type_values();
$propertyCategoryCounts = array_fill_keys($standardPropertyCategories, 0);
foreach ($conn->query('SELECT propertyType, COUNT(*) AS total FROM properties WHERE isDeleted = 0 GROUP BY propertyType') as $row) {
    $category = trim((string) $row['propertyType']);
    if ($category === '') {
        $category = 'Uncategorized';
    }
    if (!in_array($category, $standardPropertyCategories, true)) {
        $category = 'Other / legacy';
    }
    $propertyCategoryCounts[$category] = ($propertyCategoryCounts[$category] ?? 0) + (int) $row['total'];
}
$propertyCategoryTotal = array_sum($propertyCategoryCounts);
$propertyCategoryMaximum = max(1, ...array_values($propertyCategoryCounts));

$appointmentStatusCounts = [
    'Pending' => 0,
    'Approved' => 0,
    'Cancelled' => 0,
];
foreach ($conn->query('SELECT status, COUNT(*) AS total FROM appointments GROUP BY status') as $row) {
    $status = trim((string) $row['status']);
    if ($status === '') {
        $status = 'Unspecified';
    }
    $appointmentStatusCounts[$status] = ($appointmentStatusCounts[$status] ?? 0) + (int) $row['total'];
}
$appointmentStatusTotal = array_sum($appointmentStatusCounts);
$appointmentStatusMaximum = max(1, ...array_values($appointmentStatusCounts));

$salesSummary = $conn->query(
    "SELECT COUNT(*) AS verifiedSales, COALESCE(SUM(salePrice), 0) AS actualRevenue
     FROM property_transactions
     WHERE currentStage = 'completed' AND salePrice IS NOT NULL"
)->fetch_assoc();
$verifiedSalesCount = (int) $salesSummary['verifiedSales'];
$actualRevenue = (float) $salesSummary['actualRevenue'];
$activePipeline = $conn->query(
    "SELECT COUNT(*) AS activeCases, COALESCE(SUM(p.price), 0) AS askingPriceValue
     FROM property_transactions t
     JOIN properties p ON p.propertyID = t.propertyID
     WHERE t.currentStage NOT IN ('completed', 'cancelled')"
)->fetch_assoc();
$activePipelineCount = (int) $activePipeline['activeCases'];
$activePipelineAskingValue = (float) $activePipeline['askingPriceValue'];

$availablePropertiesRes = $conn->query("SELECT COUNT(*) AS total FROM properties WHERE status = 'Available' AND isDeleted = 0");
$availableListingsCount = $availablePropertiesRes ? (int) $availablePropertiesRes->fetch_assoc()['total'] : 0;

$recentInquiriesRes = $conn->query("SELECT COUNT(*) AS total FROM feedbackinquiries WHERE createdAt >= DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 30 DAY)");
$recentInquiriesCount = $recentInquiriesRes ? (int) $recentInquiriesRes->fetch_assoc()['total'] : 0;

$salesMonthStart = (new DateTimeImmutable('first day of this month'))->modify('-5 months');
$salesRangeStart = $salesMonthStart->format('Y-m-d');
$salesRangeEnd = $salesMonthStart->modify('+6 months')->format('Y-m-d');
$monthlySales = [];
for ($monthOffset = 0; $monthOffset < 6; $monthOffset++) {
    $month = $salesMonthStart->modify('+' . $monthOffset . ' months');
    $monthKey = $month->format('Y-m');
    $monthlySales[$monthKey] = [
        'label' => $month->format('M Y'),
        'sales' => 0,
        'revenue' => 0.0,
    ];
}
$monthlySalesStmt = $conn->prepare(
    "SELECT DATE_FORMAT(completions.completedAt, '%Y-%m') AS monthKey,
            COUNT(*) AS sales,
            COALESCE(SUM(t.salePrice), 0) AS revenue
     FROM property_transactions t
     JOIN (
         SELECT transactionID, MAX(createdAt) AS completedAt
         FROM property_transaction_events
         WHERE stage = 'completed'
         GROUP BY transactionID
     ) completions ON completions.transactionID = t.transactionID
     WHERE t.currentStage = 'completed'
       AND t.salePrice IS NOT NULL
       AND completions.completedAt >= ?
       AND completions.completedAt < ?
     GROUP BY monthKey"
);
$monthlySalesStmt->bind_param('ss', $salesRangeStart, $salesRangeEnd);
$monthlySalesStmt->execute();
foreach ($monthlySalesStmt->get_result() as $row) {
    $monthKey = (string) $row['monthKey'];
    if (isset($monthlySales[$monthKey])) {
        $monthlySales[$monthKey]['sales'] = (int) $row['sales'];
        $monthlySales[$monthKey]['revenue'] = (float) $row['revenue'];
    }
}
$monthlyRevenueMaximum = max(1.0, ...array_column($monthlySales, 'revenue'));
$hasMonthlySales = array_sum(array_column($monthlySales, 'sales')) > 0;
$monthlyRevenueBars = [];
$monthlyChartLeft = 76;
$monthlyChartWidth = 620;
$monthlyChartBottom = 218;
$monthlyChartHeight = 150;
$monthlyChartSlotWidth = $monthlyChartWidth / count($monthlySales);
foreach (array_values($monthlySales) as $index => $month) {
    $barHeight = ((float) $month['revenue'] / $monthlyRevenueMaximum) * $monthlyChartHeight;
    $monthlyRevenueBars[] = [
        'x' => $monthlyChartLeft + ($index * $monthlyChartSlotWidth) + 14,
        'y' => $monthlyChartBottom - $barHeight,
        'width' => $monthlyChartSlotWidth - 28,
        'height' => $barHeight,
        'center' => $monthlyChartLeft + ($index * $monthlyChartSlotWidth) + ($monthlyChartSlotWidth / 2),
        'label' => $month['label'],
        'revenue' => (float) $month['revenue'],
        'sales' => (int) $month['sales'],
    ];
}

$topSalesProperties = $conn->query(
    "SELECT p.title, COUNT(*) AS sales, SUM(t.salePrice) AS revenue
     FROM property_transactions t
     JOIN properties p ON p.propertyID = t.propertyID
     WHERE t.currentStage = 'completed' AND t.salePrice IS NOT NULL
     GROUP BY p.propertyID, p.title
     ORDER BY revenue DESC, sales DESC, p.title ASC
     LIMIT 5"
)->fetch_all(MYSQLI_ASSOC);
$topSalesMaximum = $topSalesProperties === []
    ? 1.0
    : max(1.0, ...array_map(static fn(array $row): float => (float) $row['revenue'], $topSalesProperties));
$agentFeedbackSummary = get_agent_feedback_summary($conn);
$topAgents = get_agent_feedback_top_performers($conn, 5);
$formatCompactPeso = static function (float $amount): string {
    if ($amount >= 1000000) {
        return '₱' . number_format($amount / 1000000, 1) . 'M';
    }
    if ($amount >= 1000) {
        return '₱' . number_format($amount / 1000, 1) . 'K';
    }
    return '₱' . number_format($amount, 0);
};

$properties = $conn->query(
    "SELECT p.*, l.municipalityName, l.provinceName
     FROM properties p
     LEFT JOIN property_locations l ON l.locationID = p.locationID
     WHERE p.isDeleted = 0
     ORDER BY p.createdAt DESC, p.propertyID DESC"
);
$editingProperty = null;
$editPropertyRequest = $_GET['edit_property'] ?? ($_POST['propertyID'] ?? null);
if (isset($_GET['edit_property']) || isset($_POST['update_property'])) {
    $requestedEditId = filter_var($editPropertyRequest, FILTER_VALIDATE_INT);
    if ($requestedEditId === false || $requestedEditId <= 0) {
        if ($formError === '') {
            $formError = 'Choose a valid property to edit.';
        }
    } else {
        $editPropertyStmt = $conn->prepare('SELECT * FROM properties WHERE propertyID = ? AND isDeleted = 0');
        $editPropertyStmt->bind_param('i', $requestedEditId);
        $editPropertyStmt->execute();
        $editingProperty = $editPropertyStmt->get_result()->fetch_assoc() ?: null;
        if ($editingProperty === null && $formError === '') {
            $formError = 'The active property listing could not be found.';
        }
    }
}
$propertyFormValues = $editingProperty ?? [];
if (isset($_POST['update_property'])) {
    foreach (['title', 'description', 'price', 'areaSqm', 'locationID', 'propertyType', 'landCharacteristics', 'barangay', 'latitude', 'longitude'] as $formField) {
        if (is_scalar($_POST[$formField] ?? null)) {
            $propertyFormValues[$formField] = (string) $_POST[$formField];
        }
    }
}
$trashedProperties = $conn->query(
    "SELECT p.*, l.municipalityName, l.provinceName
     FROM properties p
     LEFT JOIN property_locations l ON l.locationID = p.locationID
     WHERE p.isDeleted = 1
     ORDER BY p.deletedAt DESC, p.propertyID DESC"
);
$trashedList = $trashedProperties ? $trashedProperties->fetch_all(MYSQLI_ASSOC) : [];
$totalTrashed = count($trashedList);
$collectionOverview = $conn->query(
    "SELECT COALESCE(SUM(CASE WHEN status = 'Verified' THEN amount ELSE 0 END), 0) AS verifiedTotal,
            COALESCE(SUM(CASE WHEN status = 'Pending' THEN amount ELSE 0 END), 0) AS pendingTotal,
            COUNT(*) AS paymentCount
     FROM payments"
)->fetch_assoc();
$clientOverview = $conn->query(
    "SELECT COUNT(*) AS totalClients,
            COALESCE(SUM(accountStatus = 'Active'), 0) AS activeClients
     FROM users
     WHERE role = 'Client'"
)->fetch_assoc();

// ALGORITHM: Municipal inventory heuristic compares inquiries with available listings,
// then recommends an inventory or marketing review using fixed thresholds.
$demandPrescription = [];
$demandRes = $conn->query("
    SELECT l.municipalityName,
           COUNT(DISTINCT i.inquiryID) AS totalInquiries,
           COUNT(DISTINCT p.propertyID) AS availableListings
    FROM property_locations l
    LEFT JOIN properties p ON p.locationID = l.locationID AND p.status = 'Available' AND p.isDeleted = 0
    LEFT JOIN feedbackinquiries i
        ON i.propertyID = p.propertyID
       AND i.createdAt >= DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 30 DAY)
    WHERE l.isActive = 1
    GROUP BY l.locationID, l.municipalityName
    HAVING totalInquiries > 0 OR availableListings > 0
    ORDER BY totalInquiries DESC, availableListings DESC
    LIMIT 6
");
if ($demandRes) {
    $demandPrescription = $demandRes->fetch_all(MYSQLI_ASSOC);
}

$auditLogsRes = $conn->query("
    SELECT a.logID, a.userID, a.action, a.timestamp, u.fullname, u.email, u.role
    FROM auditlogs a
    LEFT JOIN users u ON u.userID = a.userID
    ORDER BY a.logID DESC
    LIMIT 50
");
$recentAuditLogs = $auditLogsRes ? $auditLogsRes->fetch_all(MYSQLI_ASSOC) : [];
?>
<style>
    body {
        background: var(--admin-canvas);
    }

    .page-eyebrow {
        letter-spacing: .15em;
        color: #d9b87d;
    }

    .page-title {
        color: var(--admin-title);
        letter-spacing: -.05em;
    }

    .text-muted {
        color: var(--admin-subtitle) !important;
    }

    .card {
        border-radius: 1.2rem !important;
        box-shadow: 0 18px 40px rgba(0, 0, 0, 0.12);
    }

    html[data-bs-theme="light"] .card {
        border: 1px solid rgba(20, 34, 41, 0.08) !important;
        background: rgba(255, 255, 255, 0.98);
        color: var(--kq-ink);
    }

    html[data-bs-theme="light"] .kq-admin-shell .card .text-muted,
    html[data-bs-theme="light"] .kq-admin-shell .kq-stat-card .text-muted {
        color: #536579 !important;
    }

    html[data-bs-theme="light"] .kq-admin-shell .card .page-eyebrow {
        color: #805a20;
    }

    .kq-panel-header {
        border-bottom: 1px solid rgba(19, 45, 66, 0.08);
        padding-bottom: .75rem;
    }

    .kq-admin-shell > section .kq-panel-header h2 {
        color: var(--admin-title);
    }

    html[data-bs-theme="light"] .kq-badge-soft {
        background: rgba(17, 53, 71, 0.08);
        color: #123b5a;
    }

    .kq-stat-card {
        border-radius: 1rem;
        box-shadow: 0 12px 26px rgba(17, 43, 69, 0.07);
    }

    html[data-bs-theme="light"] .kq-stat-card {
        background: linear-gradient(180deg, rgba(255,255,255,1), rgba(243,247,250,1));
        border: 1px solid rgba(19, 45, 66, 0.08);
    }

    html[data-bs-theme="light"] .btn-primary {
        background: linear-gradient(180deg, #173d56, #102334);
        border-color: #173d56;
    }

    html[data-bs-theme="light"] .btn-primary:hover,
    html[data-bs-theme="light"] .btn-primary:focus {
        background: linear-gradient(180deg, #1d486b, #132a3f);
        border-color: #1d486b;
    }

    html[data-bs-theme="light"] .btn-outline-primary {
        border-color: rgba(17, 53, 71, 0.3);
        color: #123b5a;
    }

    html[data-bs-theme="light"] .btn-outline-primary:hover,
    html[data-bs-theme="light"] .btn-outline-primary:focus {
        background: rgba(17, 53, 71, 0.05);
        color: #123b5a;
    }

    .table-responsive, .table {
        border-radius: 1rem;
    }
</style>

<section class="mb-4" aria-labelledby="business-analytics-title">
    <div class="kq-panel-header mb-3 d-flex flex-wrap justify-content-between align-items-center gap-2">
        <div>
            <div class="page-eyebrow">Live business analytics</div>
            <h2 class="h4 fw-bold mb-0" id="business-analytics-title">Sales performance</h2>
        </div>
    </div>
    <div class="row g-3 mb-4">
        <div class="col-6 col-lg-4 col-xl-2">
            <div class="card h-100 border-0 shadow-sm">
                <div class="card-body p-3">
                    <div class="page-eyebrow" style="font-size: 0.68rem;">Active Listings</div>
                    <h3 class="h3 mb-0"><?= (int) $availableListingsCount ?></h3>
                    <small class="text-muted" style="font-size: 0.72rem;">Available properties</small>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-4 col-xl-2">
            <div class="card h-100 border-0 shadow-sm">
                <div class="card-body p-3">
                    <div class="page-eyebrow text-warning" style="font-size: 0.68rem;">Pending Action</div>
                    <h3 class="h3 mb-0 text-warning"><?= (int) ($appointmentStatusCounts['Pending'] ?? 0) ?></h3>
                    <small class="text-muted" style="font-size: 0.72rem;">Viewings to review</small>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-4 col-xl-2">
            <div class="card h-100 border-0 shadow-sm">
                <div class="card-body p-3">
                    <div class="page-eyebrow text-info" style="font-size: 0.68rem;">Inquiries (30d)</div>
                    <h3 class="h3 mb-0 text-info"><?= (int) $recentInquiriesCount ?></h3>
                    <small class="text-muted" style="font-size: 0.72rem;">Client inquiries</small>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-4 col-xl-2">
            <div class="card h-100 border-0 shadow-sm">
                <div class="card-body p-3">
                    <div class="page-eyebrow text-primary" style="font-size: 0.68rem;">Active Pipeline</div>
                    <h3 class="h3 mb-0 text-primary"><?= (int) $activePipelineCount ?></h3>
                    <small class="text-muted" style="font-size: 0.72rem;"><a href="admin_sales.php" class="text-decoration-none">Transactions &rarr;</a></small>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-4 col-xl-2">
            <div class="card h-100 border-0 shadow-sm">
                <div class="card-body p-3">
                    <div class="page-eyebrow text-success" style="font-size: 0.68rem;">Completed Sales</div>
                    <h3 class="h3 mb-0 text-success"><?= (int) $verifiedSalesCount ?></h3>
                    <small class="text-muted" style="font-size: 0.72rem;">Closed acquisitions</small>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-4 col-xl-2">
            <div class="card h-100 border-0 shadow-sm">
                <div class="card-body p-3">
                    <div class="page-eyebrow text-success" style="font-size: 0.68rem;">Revenue Secured</div>
                    <h3 class="h3 mb-0 text-success"><?= $formatCompactPeso($actualRevenue) ?></h3>
                    <small class="text-muted" style="font-size: 0.72rem;">Verified actual sales</small>
                </div>
            </div>
        </div>
    </div>

    <section class="card p-3 p-lg-4 mb-3" aria-labelledby="client-feedback-title">
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2 mb-3">
            <div>
                <div class="page-eyebrow">Customer experience</div>
                <h3 class="h5 fw-bold mb-0" id="client-feedback-title">Agent satisfaction score</h3>
            </div>
            <div class="small text-muted"><?= number_format($agentFeedbackSummary['review_count']) ?> submitted review<?= $agentFeedbackSummary['review_count'] === 1 ? '' : 's' ?></div>
        </div>
        <div class="row g-3 align-items-center">
            <div class="col-md-4">
                <div class="display-5 fw-bold mb-1"><?= number_format($agentFeedbackSummary['average_rating'], 1) ?> / 5</div>
                <div class="text-warning fs-5" aria-label="Average rating of <?= number_format($agentFeedbackSummary['average_rating'], 1) ?> out of 5 stars">
                    <?php for ($starIndex = 1; $starIndex <= 5; $starIndex++): ?>
                        <?= $starIndex <= (int) round($agentFeedbackSummary['average_rating']) ? '★' : '☆' ?>
                    <?php endfor; ?>
                </div>
                <div class="small text-muted mt-2">Average rating across all agent review submissions.</div>
            </div>
            <div class="col-md-8">
                <?php if ($topAgents !== []): ?>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead><tr><th>Agent</th><th>Reviews</th><th>Average</th></tr></thead>
                            <tbody>
                                <?php foreach ($topAgents as $agent): ?>
                                    <tr>
                                        <td><?= htmlspecialchars((string) $agent['fullname'], ENT_QUOTES, 'UTF-8') ?></td>
                                        <td><?= (int) $agent['review_count'] ?></td>
                                        <td><?= number_format((float) $agent['average_rating'], 1) ?> / 5</td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="text-muted small">No ratings have been submitted yet. Once a completed transaction is reviewed, this summary will appear here.</div>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <div class="row g-3">
        <div class="col-lg-7">
            <section class="card h-100 p-3 p-lg-4" aria-labelledby="monthly-sales-title">
                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                    <div>
                        <h3 class="h5 fw-bold mb-1" id="monthly-sales-title">Monthly actual sales revenue</h3>
                        <p class="small text-muted mb-0">Verified completed sales, grouped by month</p>
                    </div>
                    <span class="badge bg-primary-subtle text-primary border border-primary-subtle small fw-bold">Verified sales data</span>
                </div>
                <div class="overflow-auto">
                    <svg class="w-100" style="min-width: 620px;" viewBox="0 0 720 260" role="img" aria-labelledby="monthly-sales-title monthly-sales-chart-description">
                        <desc id="monthly-sales-chart-description">Bar chart of verified actual sale revenue for the last six months.</desc>
                        <?php for ($gridLine = 0; $gridLine <= 4; $gridLine++):
                            $gridY = $monthlyChartBottom - ($gridLine * ($monthlyChartHeight / 4));
                            $gridValue = $monthlyRevenueMaximum * $gridLine / 4;
                        ?>
                            <line class="kq-chart-grid" x1="<?= $monthlyChartLeft ?>" y1="<?= $gridY ?>" x2="<?= $monthlyChartLeft + $monthlyChartWidth ?>" y2="<?= $gridY ?>" stroke-width="1"/>
                            <text class="kq-chart-text" x="<?= $monthlyChartLeft - 8 ?>" y="<?= $gridY + 4 ?>" text-anchor="end" font-size="11"><?= htmlspecialchars($formatCompactPeso($gridValue), ENT_QUOTES, 'UTF-8') ?></text>
                        <?php endfor; ?>
                        <line class="kq-chart-axis" x1="<?= $monthlyChartLeft ?>" y1="<?= $monthlyChartBottom - $monthlyChartHeight ?>" x2="<?= $monthlyChartLeft ?>" y2="<?= $monthlyChartBottom ?>" stroke-width="1"/>
                        <line class="kq-chart-axis" x1="<?= $monthlyChartLeft ?>" y1="<?= $monthlyChartBottom ?>" x2="<?= $monthlyChartLeft + $monthlyChartWidth ?>" y2="<?= $monthlyChartBottom ?>" stroke-width="1"/>
                        <?php foreach ($monthlyRevenueBars as $bar): ?>
                            <rect x="<?= number_format($bar['x'], 2, '.', '') ?>" y="<?= number_format($bar['y'], 2, '.', '') ?>" width="<?= number_format($bar['width'], 2, '.', '') ?>" height="<?= number_format($bar['height'], 2, '.', '') ?>" rx="6" fill="#17865b">
                                <title><?= htmlspecialchars($bar['label'] . ': ' . $formatCompactPeso($bar['revenue']) . ' (' . $bar['sales'] . ' completed sale(s))', ENT_QUOTES, 'UTF-8') ?></title>
                            </rect>
                            <text x="<?= number_format($bar['center'], 2, '.', '') ?>" y="<?= number_format($bar['y'] - 8, 2, '.', '') ?>" text-anchor="middle" fill="#17865b" font-size="11" font-weight="700"><?= $bar['revenue'] > 0 ? htmlspecialchars($formatCompactPeso($bar['revenue']), ENT_QUOTES, 'UTF-8') : '' ?></text>
                            <text class="kq-chart-text" x="<?= number_format($bar['center'], 2, '.', '') ?>" y="245" text-anchor="middle" font-size="11"><?= htmlspecialchars($bar['label'], ENT_QUOTES, 'UTF-8') ?></text>
                        <?php endforeach; ?>
                    </svg>
                </div>
                <?php if (!$hasMonthlySales): ?>
                    <p class="small text-muted mb-0 mt-2">No verified sales with a recorded sale price in this period yet. The chart will reflect completed transactions with a recorded sale price.</p>
                <?php endif; ?>
            </section>
        </div>
        <div class="col-lg-5">
            <section class="card h-100 p-3 p-lg-4" aria-labelledby="top-sales-title">
                <div class="mb-3">
                    <h3 class="h5 fw-bold mb-1" id="top-sales-title">Top-selling properties</h3>
                    <p class="small text-muted mb-0">Ranked by verified recorded sale revenue</p>
                </div>
                <?php if ($topSalesProperties === []): ?>
                    <div class="border rounded-3 p-3 bg-light" role="status">
                        <div class="d-flex justify-content-between small fw-semibold text-muted mb-2"><span>Verified sale revenue</span><span>₱0</span></div>
                        <div class="progress" role="progressbar" aria-label="Top-selling property sales revenue" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100" style="height: .65rem;">
                            <div class="progress-bar bg-success" style="width: 0%"></div>
                        </div>
                        <p class="small text-muted mb-0 mt-3">No verified sales have been recorded yet. Top-selling properties will appear here automatically after staff completes a tracked sale and records its actual price.</p>
                    </div>
                <?php else: ?>
                    <ol class="list-unstyled d-grid gap-3 mb-0" aria-label="Top-selling properties by actual sale revenue">
                        <?php foreach ($topSalesProperties as $rank => $propertySale):
                            $revenue = (float) $propertySale['revenue'];
                            $barPercent = (int) round(($revenue / $topSalesMaximum) * 100);
                        ?>
                            <li>
                                <div class="d-flex justify-content-between align-items-start gap-3 small mb-1">
                                    <span class="fw-semibold text-break"><?= ($rank + 1) ?>. <?= htmlspecialchars($propertySale['title'], ENT_QUOTES, 'UTF-8') ?></span>
                                    <span class="text-end text-nowrap">₱<?= number_format($revenue, 2) ?><span class="d-block text-muted"><?= number_format((int) $propertySale['sales']) ?> sale(s)</span></span>
                                </div>
                                <div class="progress" role="progressbar" aria-label="<?= htmlspecialchars($propertySale['title'], ENT_QUOTES, 'UTF-8') ?> sales revenue" aria-valuenow="<?= $barPercent ?>" aria-valuemin="0" aria-valuemax="100" style="height: .55rem;">
                                    <div class="progress-bar bg-success" style="width: <?= $barPercent ?>%"></div>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ol>
                <?php endif; ?>
            </section>
        </div>
    </div>

    <section class="card p-3 p-lg-4 mt-3" aria-labelledby="inventory-insights-title">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
            <div>
                <span class="badge bg-warning-subtle text-dark border border-warning fw-bold mb-1">Inventory insights</span>
                <h3 class="h5 fw-bold mb-0" id="inventory-insights-title">Listing activity by municipality</h3>
                <p class="small text-muted mb-0">Compare inquiries received in the last 30 days with currently available listings.</p>
            </div>
            <span class="badge text-bg-light border text-secondary small py-2 px-3">Municipal summary</span>
        </div>
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead>
                    <tr class="table-light">
                        <th>Municipality / Location</th>
                        <th class="text-center">Recent Inquiries (30d)</th>
                        <th class="text-center">Available Listings</th>
                        <th>Demand / Supply Status</th>
                        <th>Suggested next step</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($demandPrescription !== []): ?>
                        <?php foreach ($demandPrescription as $dem):
                            $inq = (int) $dem['totalInquiries'];
                            $avail = (int) $dem['availableListings'];
                            if ($inq > $avail) {
                                $statusBadge = '<span class="badge bg-danger-subtle text-danger border border-danger-subtle fw-bold">High inquiry activity</span>';
                                $prescription = '<strong>Review inventory:</strong> Compare available listings and verify pricing against similar properties.';
                            } elseif ($avail > 3 && $inq === 0) {
                                $statusBadge = '<span class="badge bg-warning-subtle text-dark border border-warning-subtle fw-semibold">No recent inquiries</span>';
                                $prescription = '<strong>Review marketing:</strong> Check listing details, visibility, and current buyer interest.';
                            } else {
                                $statusBadge = '<span class="badge bg-success-subtle text-success border border-success-subtle fw-semibold">Activity within range</span>';
                                $prescription = '<strong>Monitor activity:</strong> Continue reviewing inquiries and available inventory.';
                            }
                        ?>
                            <tr>
                                <td class="fw-bold text-dark"><?= htmlspecialchars($dem['municipalityName'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td class="text-center"><span class="badge text-bg-light border px-2 py-1"><?= $inq ?></span></td>
                                <td class="text-center"><span class="badge text-bg-light border px-2 py-1"><?= $avail ?></span></td>
                                <td><?= $statusBadge ?></td>
                                <td class="small text-muted"><?= $prescription ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="5" class="text-center text-muted py-3">These insights appear when listings and inquiry records are available.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</section>
</div>

<?php if ($formSuccess !== ''): ?><div class="alert alert-success" role="status"><?= htmlspecialchars($formSuccess, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
<?php if ($formError !== '' && !isset($_GET['edit_property']) && !isset($_POST['update_property'])): ?><div class="alert alert-danger" role="alert"><?= htmlspecialchars($formError, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>

<div class="row g-3 mb-4">
    <div class="col-6 col-xl-3">
        <div class="card h-100 p-3 p-lg-4">
            <div class="d-flex justify-content-between align-items-center">
                <div class="small text-muted fw-semibold">Property listings</div>
                <a href="#property-list" class="badge text-bg-primary text-decoration-none">View list &darr;</a>
            </div>
            <div class="display-6 fw-bold mt-2"><?= number_format((int) $totalProperties) ?></div>
            <a href="#property-list" class="small text-decoration-none mt-2 d-inline-block">Manage <?= number_format((int) $totalProperties) ?> active properties &rarr;</a>
        </div>
    </div>
    <div class="col-6 col-xl-3"><div class="card h-100 p-3 p-lg-4"><div class="small text-muted fw-semibold">Registered users</div><div class="display-6 fw-bold mt-2"><?= number_format((int) $totalUsers) ?></div></div></div>
    <div class="col-6 col-xl-3"><div class="card h-100 p-3 p-lg-4"><div class="small text-muted fw-semibold">Viewing requests</div><div class="display-6 fw-bold mt-2"><?= number_format((int) $totalAppointments) ?></div></div></div>
    <div class="col-6 col-xl-3"><div class="card h-100 p-3 p-lg-4"><div class="small text-muted fw-semibold">Property inquiries</div><div class="display-6 fw-bold mt-2"><?= number_format((int) $totalInquiries) ?></div></div></div>
</div>

<div class="row g-3 mb-4">
    <div class="col-md-4">
        <div class="card h-100 p-3 p-lg-4">
            <div class="d-flex justify-content-between align-items-start gap-2">
                <div class="small text-muted fw-semibold">Verified collections</div>
                <a href="admin_payments.php" class="small text-decoration-none">Open register</a>
            </div>
            <div class="h3 fw-bold mt-2 mb-1">₱<?= number_format((float) $collectionOverview['verifiedTotal'], 2) ?></div>
            <div class="small text-muted"><?= number_format((int) $collectionOverview['paymentCount']) ?> payment record(s), <?= htmlspecialchars(kq_format_peso((float) $collectionOverview['pendingTotal']), ENT_QUOTES, 'UTF-8') ?> awaiting verification.</div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card h-100 p-3 p-lg-4">
            <div class="d-flex justify-content-between align-items-start gap-2">
                <div class="small text-muted fw-semibold">Clients</div>
                <a href="admin_clients.php" class="small text-decoration-none">Manage clients</a>
            </div>
            <div class="h3 fw-bold mt-2 mb-1"><?= number_format((int) $clientOverview['totalClients']) ?></div>
            <div class="small text-muted"><?= number_format((int) $clientOverview['activeClients']) ?> active client account(s) in the directory.</div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card h-100 p-3 p-lg-4">
            <div class="d-flex justify-content-between align-items-start gap-2">
                <div class="small text-muted fw-semibold">Sales operations</div>
                <a href="admin_sales.php" class="small text-decoration-none">Open sales</a>
            </div>
            <div class="h3 fw-bold mt-2 mb-1"><?= number_format($activePipelineCount) ?> active</div>
            <div class="small text-muted"><?= htmlspecialchars(kq_format_peso($activePipelineAskingValue), ENT_QUOTES, 'UTF-8') ?> in asking-price pipeline value.</div>
        </div>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-lg-7">
        <section class="card p-3 p-lg-4 h-100" aria-labelledby="property-category-chart-title">
            <div class="mb-3">
                <h2 class="h5 fw-bold mb-1" id="property-category-chart-title">Listings by property type</h2>
                <p class="small text-muted mb-0"><?= number_format((int) $propertyCategoryTotal) ?> listing(s) across all categories</p>
            </div>
            <?php if ($propertyCategoryTotal === 0): ?>
                <p class="small text-muted mb-0 py-3">Category data will appear here when listings are added.</p>
            <?php else: ?>
                <div class="d-grid gap-3" role="list" aria-label="Property listing counts by category">
                    <?php foreach ($propertyCategoryCounts as $category => $count):
                        $percentage = (int) round(((int) $count / $propertyCategoryMaximum) * 100);
                        $barClass = [
                            'Residential' => 'bg-primary',
                            'Commercial' => 'bg-success',
                            'Farmlot' => 'bg-warning',
                            'Beachlot' => 'bg-info',
                            'Condo' => 'bg-purple text-white',
                            'House and Lot' => 'bg-primary',
                            'Townhouse' => 'bg-info',
                            'Apartment' => 'bg-purple text-white',
                            'Warehouse' => 'bg-warning',
                            'Office Space' => 'bg-success',
                            'Resort' => 'bg-info',
                            'Other / legacy' => 'bg-secondary',
                        ][$category] ?? 'bg-secondary';
                    ?>
                        <div role="listitem">
                            <div class="d-flex justify-content-between gap-3 small mb-1">
                                <span class="text-break"><?= htmlspecialchars((string) $category, ENT_QUOTES, 'UTF-8') ?></span>
                                <strong><?= number_format((int) $count) ?></strong>
                            </div>
                            <div class="progress" role="progressbar" aria-label="<?= htmlspecialchars((string) $category, ENT_QUOTES, 'UTF-8') ?> listings" aria-valuenow="<?= $percentage ?>" aria-valuemin="0" aria-valuemax="100" style="height: .65rem;">
                                <div class="progress-bar <?= $barClass ?>" style="width: <?= $percentage ?>%"></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    </div>
    <div class="col-lg-5">
        <section class="card p-3 p-lg-4 h-100" aria-labelledby="appointment-status-chart-title">
            <div class="mb-3">
                <h2 class="h5 fw-bold mb-1" id="appointment-status-chart-title">Viewing request status</h2>
                <p class="small text-muted mb-0"><?= number_format((int) $appointmentStatusTotal) ?> request(s) in total</p>
            </div>
            <?php if ($appointmentStatusTotal === 0): ?>
                <p class="small text-muted mb-0 py-3">Request status data will appear here when a client books a viewing.</p>
            <?php else: ?>
                <div class="d-grid gap-3" role="list" aria-label="Viewing request counts by status">
                    <?php foreach ($appointmentStatusCounts as $status => $count):
                        $percentage = (int) round(((int) $count / $appointmentStatusMaximum) * 100);
                        $barClass = [
                            'Pending' => 'bg-warning',
                            'Approved' => 'bg-success',
                            'Cancelled' => 'bg-secondary',
                        ][$status] ?? 'bg-primary';
                    ?>
                        <div role="listitem">
                            <div class="d-flex justify-content-between gap-3 small mb-1">
                                <span class="text-break"><?= htmlspecialchars((string) $status, ENT_QUOTES, 'UTF-8') ?></span>
                                <strong><?= number_format((int) $count) ?></strong>
                            </div>
                            <div class="progress" role="progressbar" aria-label="<?= htmlspecialchars((string) $status, ENT_QUOTES, 'UTF-8') ?> viewing requests" aria-valuenow="<?= $percentage ?>" aria-valuemin="0" aria-valuemax="100" style="height: .65rem;">
                                <div class="progress-bar <?= $barClass ?>" style="width: <?= $percentage ?>%"></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    </div>
</div>

<section class="card p-3 p-lg-4 mb-4 shadow-sm" id="property-list">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-3 pb-3 border-bottom">
        <div>
            <div class="page-eyebrow mb-1">Current inventory</div>
            <h2 class="h4 fw-bold mb-0">🏘️ Property list</h2>
            <p class="text-muted small mb-0">Active property listings visible across the KingQuiry catalog and customer portals.</p>
        </div>
        <div class="d-flex flex-wrap align-items-center gap-2">
            <span class="badge bg-primary px-3 py-2 fs-6 fw-bold" id="activePropsCountBadge"><?= number_format($properties->num_rows) ?> active listings</span>
            <a href="#properties" class="btn btn-sm btn-primary fw-semibold">+ Publish listing</a>
            <button type="button" class="btn btn-outline-danger fw-semibold d-inline-flex align-items-center gap-1" data-bs-toggle="modal" data-bs-target="#trashbinModal" id="btnOpenTrashbin">
                <span>🗑️</span> Trashbin (<span id="trashHeaderBadge"><?= $totalTrashed ?></span>)
            </button>
        </div>
    </div>

    <!-- Quick Search Input for Property Table -->
    <div class="row g-2 mb-3">
        <div class="col-md-6 col-lg-4">
            <input type="text" id="propTableSearch" class="form-control form-control-sm" placeholder="🔍 Quick search property list by title, category, or location...">
        </div>
    </div>

    <div class="table-responsive">
        <table class="table table-bordered table-hover align-middle mb-0" id="adminPropertiesTable">
            <thead class="table-light">
                <tr>
                    <th>Title</th>
                    <th>Category</th>
                    <th>Location</th>
                    <th>Asking price</th>
                    <th>Listing status</th>
                    <th class="text-end">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php while($row = $properties->fetch_assoc()):
                    $rowPropertyStatus = (string) ($row['status'] ?? 'Available');
                    $propertyStatusOptions = ['Available', 'Reserved', 'Sold'];
                    $propLocLabel = property_location_label($row);
                ?>
                    <tr class="prop-table-row" data-search="<?= htmlspecialchars(mb_strtolower($row['title'] . ' ' . $row['propertyType'] . ' ' . $propLocLabel . ' ' . ($row['barangay'] ?? '')), ENT_QUOTES, 'UTF-8') ?>">
                        <td class="fw-bold text-dark"><?= htmlspecialchars($row['title']) ?></td>
                        <td><span class="badge rounded-pill text-bg-light border"><?= htmlspecialchars($row['propertyType']) ?></span></td>
                        <td><?= htmlspecialchars($propLocLabel) ?><?php if (!empty($row['barangay'])): ?><br><small class="text-muted"><?= htmlspecialchars($row['barangay']) ?></small><?php endif; ?></td>
                        <td>₱<?= number_format((float) $row['price'], 2) ?><br><small class="text-muted"><?= $row['areaSqm'] !== null ? number_format((float) $row['areaSqm'], 2) . ' sq.m. · ₱' . number_format((float) $row['pricePerSqm'], 2) . '/sq.m.' : 'Legacy area not recorded' ?></small></td>
                        <td>
                            <form method="POST" class="d-flex align-items-center gap-2" style="min-width: 12rem;" data-confirm="Save this listing status change?">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
                                <input type="hidden" name="propertyID" value="<?= (int) $row['propertyID'] ?>">
                                <label class="visually-hidden" for="property-status-<?= (int) $row['propertyID'] ?>">Listing status</label>
                                <select id="property-status-<?= (int) $row['propertyID'] ?>" name="propertyStatus" class="form-select form-select-sm">
                                    <?php if (!in_array($rowPropertyStatus, $propertyStatusOptions, true)): ?>
                                        <option value="<?= htmlspecialchars($rowPropertyStatus, ENT_QUOTES, 'UTF-8') ?>" selected><?= htmlspecialchars($rowPropertyStatus !== '' ? $rowPropertyStatus : 'Unspecified', ENT_QUOTES, 'UTF-8') ?></option>
                                    <?php endif; ?>
                                    <?php foreach ($propertyStatusOptions as $propertyStatusOption): ?>
                                        <option value="<?= htmlspecialchars($propertyStatusOption, ENT_QUOTES, 'UTF-8') ?>" <?= $rowPropertyStatus === $propertyStatusOption ? 'selected' : '' ?>><?= htmlspecialchars($propertyStatusOption, ENT_QUOTES, 'UTF-8') ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="submit" name="set_property_status" class="btn btn-sm btn-outline-primary">Save</button>
                            </form>
                        </td>
                        <td class="text-end">
                            <div class="d-flex justify-content-end align-items-center gap-1">
                                <a href="admin_dashboard.php?edit_property=<?= (int) $row['propertyID'] ?>#properties" class="btn btn-sm btn-outline-primary py-1 px-2" title="Edit all property details">✏️ Edit</a>
                                <a href="property_detail.php?id=<?= (int) $row['propertyID'] ?>" target="_blank" class="btn btn-sm btn-outline-secondary py-1 px-2" title="View live property page">👁️ View</a>
                                <form method="POST" class="m-0 d-inline" data-confirm="Move this property to the trash? It will be hidden from the public catalog.">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
                                    <input type="hidden" name="propertyID" value="<?= (int) $row['propertyID'] ?>">
                                    <button type="submit" name="trash_property" class="btn btn-sm btn-outline-danger py-1 px-2" title="Move to trash bin">🗑️ Trash</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endwhile; ?>
                <?php if ($properties->num_rows === 0): ?><tr><td colspan="6" class="text-center text-muted py-4">No property listings found.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<!-- Property Trashbin (Archived Inventory) Modal -->
<div class="modal fade" id="trashbinModal" tabindex="-1" aria-labelledby="trashbinModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header bg-danger text-white">
                <div class="d-flex align-items-center gap-2">
                    <span class="fs-5">🗑️</span>
                    <div>
                        <h5 class="modal-title h6 mb-0 text-white fw-bold" id="trashbinModalLabel">Archived Inventory — Trashbin</h5>
                        <div class="small opacity-75">Restore listings or permanently delete property records</div>
                    </div>
                </div>
                <div class="d-flex align-items-center gap-2">
                    <span class="badge bg-white text-danger rounded-pill px-2 py-1 fw-bold small" id="trashCountBadge">
                        <?= $totalTrashed ?> in trash
                    </span>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
            </div>
            <div class="modal-body p-3 p-lg-4">
                <p class="text-muted small mb-3">Properties moved to the trashbin are hidden from public view and catalog searches. Select properties to restore or permanently remove.</p>

                <!-- Search and Sort Controls -->
                <div class="row g-2 mb-3">
                    <div class="col-sm-7">
                        <input type="text" id="trashSearchInput" class="form-control form-control-sm" placeholder="🔍 Search trashed properties by title or location...">
                    </div>
                    <div class="col-sm-5">
                        <select id="trashSortSelect" class="form-select form-select-sm">
                            <option value="newest">Trashed: Newest</option>
                            <option value="oldest">Trashed: Oldest</option>
                            <option value="name_asc">Title: A to Z</option>
                            <option value="name_desc">Title: Z to A</option>
                            <option value="price_asc">Price: Low to High</option>
                            <option value="price_desc">Price: High to Low</option>
                        </select>
                    </div>
                </div>

                <form method="POST" id="trashBulkForm">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">

                    <!-- Selection & Bulk Actions Bar -->
                    <div class="d-flex justify-content-between align-items-center p-2 mb-2 bg-light border rounded small">
                        <div class="form-check mb-0">
                            <input type="checkbox" id="selectAllTrash" class="form-check-input">
                            <label class="form-check-label fw-semibold" for="selectAllTrash">Select all</label>
                        </div>
                        <div class="d-flex gap-1">
                            <button type="submit" name="bulk_restore" id="btnBulkRestore" class="btn btn-sm btn-outline-success py-1 px-3 fw-semibold" data-confirm="Restore the selected properties to the active inventory?" disabled title="Restore selected properties">
                                ↺ Restore Selected
                            </button>
                            <button type="button" id="btnBulkDelete" class="btn btn-sm btn-danger py-1 px-3 fw-semibold" data-bs-toggle="modal" data-bs-target="#bulkDeleteModal" disabled title="Permanently delete selected properties">
                                🗑️ Delete Selected
                            </button>
                        </div>
                    </div>

                    <!-- List of Trashed Properties -->
                    <div id="trashItemsContainer" class="d-grid gap-2" style="max-height: 440px; overflow-y: auto;">
                        <?php if (!empty($trashedList)): ?>
                            <?php foreach ($trashedList as $tProp):
                                $tId = (int) $tProp['propertyID'];
                                $tTitle = (string) $tProp['title'];
                                $tPrice = (float) $tProp['price'];
                                $tDeletedAt = !empty($tProp['deletedAt']) ? strtotime($tProp['deletedAt']) : strtotime($tProp['createdAt']);
                            ?>
                                <div class="card p-2 border trash-item shadow-none"
                                     data-id="<?= $tId ?>"
                                     data-title="<?= htmlspecialchars(mb_strtolower($tTitle), ENT_QUOTES, 'UTF-8') ?>"
                                     data-price="<?= $tPrice ?>"
                                     data-date="<?= $tDeletedAt ?>">
                                    <div class="d-flex align-items-center justify-content-between gap-3">
                                        <div class="d-flex align-items-center gap-2 flex-grow-1 min-w-0">
                                            <!-- Explicit Select Button -->
                                            <div>
                                                <label class="btn btn-sm btn-outline-primary d-inline-flex align-items-center gap-1 py-1 px-2 mb-0 trash-select-btn" for="chkProp<?= $tId ?>" style="cursor: pointer;">
                                                    <input type="checkbox" id="chkProp<?= $tId ?>" name="selectedPropertyIDs[]" value="<?= $tId ?>" class="form-check-input trash-item-checkbox me-1 mt-0">
                                                    <span class="trash-select-text fw-semibold small">Select</span>
                                                </label>
                                            </div>
                                            <div class="flex-grow-1 min-w-0">
                                                <div class="fw-bold text-dark text-truncate small" title="<?= htmlspecialchars($tTitle, ENT_QUOTES, 'UTF-8') ?>">
                                                    <?= htmlspecialchars($tTitle, ENT_QUOTES, 'UTF-8') ?>
                                                </div>
                                                <div class="small text-muted text-truncate" style="font-size: 0.75rem;">
                                                    <span class="badge text-bg-light border"><?= htmlspecialchars($tProp['propertyType'], ENT_QUOTES, 'UTF-8') ?></span>
                                                    ₱<?= number_format($tPrice, 2) ?> · <?= htmlspecialchars(!empty($tProp['municipalityName']) ? $tProp['municipalityName'] . ', ' . $tProp['provinceName'] : $tProp['location'], ENT_QUOTES, 'UTF-8') ?>
                                                </div>
                                                <div class="text-secondary" style="font-size: 0.7rem;">
                                                    Trashed: <?= htmlspecialchars(date('M j, Y g:i A', $tDeletedAt), ENT_QUOTES, 'UTF-8') ?>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="d-flex gap-1 flex-shrink-0">
                                            <!-- Individual Restore Button -->
                                            <button type="submit" name="restore_property" value="<?= $tId ?>" formaction="admin_dashboard.php" class="btn btn-sm btn-outline-success py-1 px-2 fw-semibold" data-confirm="Restore this property to the active inventory?" title="Restore to active inventory">
                                                ↺ Restore
                                            </button>
                                            <!-- Individual Permanent Delete Button -->
                                            <button type="button" class="btn btn-sm btn-outline-danger py-1 px-2 fw-semibold" data-bs-toggle="modal" data-bs-target="#deleteSingleModal<?= $tId ?>" title="Delete permanently">
                                                🗑️ Delete
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="p-4 text-center text-muted small bg-light rounded" id="trashEmptyMsg">
                                <span class="fs-4 d-block mb-1">🏠</span>
                                No properties in the trashbin. Properties moved to trash will appear here.
                            </div>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
            <div class="modal-footer bg-light p-2 justify-content-between">
                <span class="small text-muted">Archived properties can be restored at any time.</span>
            </div>
        </div>
    </div>
</div>

<!-- Single Property Permanent Delete Modals -->
<?php if (!empty($trashedList)): ?>
    <?php foreach ($trashedList as $tProp):
        $tId = (int) $tProp['propertyID'];
        $tTitle = (string) $tProp['title'];
    ?>
        <div class="modal fade confirm-delete-modal" id="deleteSingleModal<?= $tId ?>" tabindex="-1" aria-labelledby="deleteSingleLabel<?= $tId ?>" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content border-0 shadow-lg">
                    <div class="modal-header bg-danger text-white">
                        <h5 class="modal-title h6 mb-0 text-white" id="deleteSingleLabel<?= $tId ?>">Confirm Permanent Deletion</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body p-4 text-center">
                        <div class="text-danger mb-3">
                            <svg width="48" height="48" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                        </div>
                        <h6 class="fw-bold text-dark mb-2">Are you sure you want to delete <?= htmlspecialchars($tTitle, ENT_QUOTES, 'UTF-8') ?>?</h6>
                        <p class="small text-muted mb-0">This will permanently delete this property from the database. This action cannot be undone.</p>
                    </div>
                    <div class="modal-footer bg-light justify-content-center gap-2 p-2">
                        <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <form method="POST" class="m-0">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
                            <input type="hidden" name="propertyID" value="<?= $tId ?>">
                            <button type="submit" name="permanent_delete_property" class="btn btn-sm btn-danger fw-bold px-4">Yes, delete</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<!-- Bulk Property Permanent Delete Modal -->
<div class="modal fade confirm-delete-modal" id="bulkDeleteModal" tabindex="-1" aria-labelledby="bulkDeleteLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title h6 mb-0 text-white" id="bulkDeleteLabel">Confirm Bulk Property Deletion</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4 text-center">
                <div class="text-danger mb-3">
                    <svg width="48" height="48" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                </div>
                <h6 class="fw-bold text-dark mb-2" id="bulkDeleteText">Are you sure you want to permanently delete the selected properties?</h6>
                <p class="small text-muted mb-0">This will remove all selected properties from the system forever. This action cannot be undone.</p>
            </div>
            <div class="modal-footer bg-light justify-content-center gap-2 p-2">
                <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-sm btn-danger fw-bold px-4" id="btnExecuteBulkDelete">Yes, delete</button>
            </div>
        </div>
    </div>
</div>

<section class="card p-4 p-lg-5 mb-4">
    <div class="page-eyebrow mb-2">Comparable listing analysis</div>
    <h2 class="h4 fw-bold mb-2">Property price estimate</h2>
    <p class="text-muted small mb-4">Uses available listing prices and the selected land area, category, and municipality to provide an indicative range. This is a planning reference, not a formal appraisal.</p>
    <form method="POST" class="row g-3 align-items-end">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
        <div class="col-md-3">
            <label class="form-label fw-semibold" for="estimate-area">Land area (sq.m.)</label>
            <input id="estimate-area" type="number" min="1" max="1000000" step="any" name="sqm_input" class="form-control" required placeholder="e.g. 500" value="<?= htmlspecialchars((string) ($inputSqm ?: ''), ENT_QUOTES, 'UTF-8') ?>">
        </div>
        <div class="col-md-3">
            <label class="form-label fw-semibold" for="estimate-type">Category</label>
            <select id="estimate-type" name="property_type" class="form-select" required>
                <?php foreach (property_type_labels() as $propertyType => $propertyLabel): ?>
                    <option value="<?= htmlspecialchars($propertyType, ENT_QUOTES, 'UTF-8') ?>" <?= $inputType === $propertyType ? 'selected' : '' ?>><?= htmlspecialchars($propertyLabel, ENT_QUOTES, 'UTF-8') ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3">
            <label class="form-label fw-semibold" for="estimate-location">Municipality / city</label>
            <select id="estimate-location" name="locationID" class="form-select" required>
                <?= property_location_options($locations, $estimateLocationId > 0 ? (int) $estimateLocationId : null) ?>
            </select>
        </div>
        <div class="col-md-3">
            <button type="submit" name="run_prediction" class="btn btn-primary w-100">Calculate estimate</button>
        </div>
    </form>

    <?php if ($estimate): ?>
        <div class="alert alert-success mt-4 mb-0" role="status">
            <h3 class="h6 fw-bold mb-2">Indicative estimate for <?= htmlspecialchars($estimate['location_name'], ENT_QUOTES, 'UTF-8') ?></h3>
            <p class="mb-1"><strong><?= number_format((float) $inputSqm, 2) ?> sq.m.</strong>: <strong class="text-dark">₱<?= number_format($estimate['low_estimate'], 2) ?>–₱<?= number_format($estimate['high_estimate'], 2) ?></strong></p>
            <p class="small mb-0">Based on <?= $estimate['comparable_count'] ?> available listing(s), including <?= $estimate['exact_match_count'] ?> in the same category and municipality. This range is indicative only.</p>
        </div>
    <?php endif; ?>
    <?php if ($estimateError !== ''): ?>
        <div class="alert alert-warning mt-4 mb-0" role="alert"><?= htmlspecialchars($estimateError, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>
</section>

<section class="card p-4 p-lg-5 mb-4" id="properties">
    <div class="page-eyebrow mb-2">Inventory management</div>
    <h2 class="h4 fw-bold mb-2"><?= $editingProperty ? 'Edit property listing' : 'Publish a property' ?></h2>
    <?php if ($formError !== '' && (isset($_GET['edit_property']) || isset($_POST['update_property']))): ?>
        <div class="alert alert-danger" role="alert"><?= htmlspecialchars($formError, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>
    <p class="text-muted small">Choose a verified municipality/city from the directory.</p>
    <form method="POST" action="admin_dashboard.php<?= $editingProperty ? '?edit_property=' . (int) $editingProperty['propertyID'] : '' ?>#properties" enctype="multipart/form-data" data-confirm="<?= $editingProperty ? 'Save these property listing changes?' : 'Publish this property listing to the catalog?' ?>">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
        <?php if ($editingProperty): ?>
            <input type="hidden" name="propertyID" value="<?= (int) $editingProperty['propertyID'] ?>">
        <?php endif; ?>
        <div class="row g-3 kq-property-form-grid">
            <div class="col-md-6"><label class="form-label fw-semibold" for="property-title">Listing title</label><input id="property-title" type="text" name="title" class="form-control" required minlength="3" maxlength="150" placeholder="e.g. Residential lot near the town center" value="<?= htmlspecialchars((string) ($propertyFormValues['title'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"></div>
            <div class="col-md-4"><label class="form-label fw-semibold" for="property-area">Land area (sq.m.)</label><input id="property-area" type="number" min="0.01" max="1000000" step="0.01" name="areaSqm" class="form-control" required placeholder="e.g. 500" value="<?= htmlspecialchars((string) ($propertyFormValues['areaSqm'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"></div>
            <div class="col-md-4"><label class="form-label fw-semibold" for="property-price">Total asking price (PHP)</label><input id="property-price" type="number" min="0.01" max="9999999999.99" step="0.01" name="price" class="form-control" required placeholder="1500000" value="<?= htmlspecialchars((string) ($propertyFormValues['price'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"></div>
            <div class="col-md-4"><label class="form-label fw-semibold" for="property-type">Property category</label><select id="property-type" name="propertyType" class="form-select" required><option value="">Choose a category</option><?php foreach (property_type_labels() as $propertyType => $propertyLabel): ?><option value="<?= htmlspecialchars($propertyType, ENT_QUOTES, 'UTF-8') ?>" <?= ($propertyFormValues['propertyType'] ?? '') === $propertyType ? 'selected' : '' ?>><?= htmlspecialchars($propertyLabel, ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select></div>
            <div class="col-md-6"><label class="form-label fw-semibold" for="property-location">City / municipality</label><select id="property-location" name="locationID" class="form-select" required><?= property_location_options($locations, isset($propertyFormValues['locationID']) ? (int) $propertyFormValues['locationID'] : null) ?></select></div>
            <div class="col-md-6"><label class="form-label fw-semibold" for="property-barangay">Barangay <span class="text-muted fw-normal">(optional)</span></label><input id="property-barangay" type="text" maxlength="120" name="barangay" class="form-control" placeholder="Enter verified barangay" value="<?= htmlspecialchars((string) ($propertyFormValues['barangay'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"></div>
            
            <!-- Interactive Map Picker for Pinning Land Location -->
            <div class="col-12">
                <input type="hidden" id="property-latitude" name="latitude" value="<?= htmlspecialchars((string) ($propertyFormValues['latitude'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" id="property-longitude" name="longitude" value="<?= htmlspecialchars((string) ($propertyFormValues['longitude'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">

                <div class="card bg-light border p-3 p-md-4 rounded-3">
                    <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-2">
                        <div>
                            <label class="form-label fw-bold mb-0" for="property-picker-map">
                                <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" class="me-1 text-primary align-text-bottom"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
                                Pin exact land location on the interactive map
                            </label>
                            <div class="text-muted small">Select a municipality to center the map, then click the exact location or drag its pin. The municipality center will not be saved as the property pin.</div>
                        </div>
                        <span class="badge text-bg-light border text-dark fw-semibold" id="pin-coords-badge">
                            Select municipality or click map to pin
                        </span>
                    </div>

                    <div id="property-picker-map" style="height: 360px; width: 100%; border-radius: .75rem; overflow: hidden; border: 1px solid #ced4da; background: #e9ecef; position: relative; z-index: 1;"></div>

                    <div class="d-flex align-items-center justify-content-between mt-2 flex-wrap gap-2">
                        <small class="text-muted">
                            Pinned coordinates: <strong class="text-primary" id="pin-coords-text">No exact pin selected</strong>
                        </small>
                        <button type="button" class="btn btn-sm btn-outline-primary" id="btn-recenter-municipality">
                            <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" class="me-1 align-text-bottom"><circle cx="12" cy="12" r="10"/><path d="m12 8 4 4-4 4M8 12h8"/></svg>Center on selected city
                        </button>
                    </div>
                </div>
            </div>

            <div class="col-12"><label class="form-label fw-semibold" for="property-characteristics">Land characteristics <span class="text-muted fw-normal">(optional)</span></label><input id="property-characteristics" type="text" maxlength="2000" name="landCharacteristics" class="form-control" placeholder="Road access, terrain, utilities, lot dimensions" value="<?= htmlspecialchars((string) ($propertyFormValues['landCharacteristics'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"></div>
            <div class="col-12"><label class="form-label fw-semibold" for="property-image"><?= $editingProperty ? 'Replace property image' : 'Property image' ?> <span class="text-muted fw-normal">(optional, JPG/PNG/WebP up to 5 MB<?= $editingProperty ? '; leave blank to keep the current image' : '' ?>)</span></label><input id="property-image" type="file" name="propertyImage" class="form-control" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp"><?php
                $currentPropertyImage = basename((string) ($editingProperty['image'] ?? ''));
                $currentPropertyImagePath = __DIR__ . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'images' . DIRECTORY_SEPARATOR . $currentPropertyImage;
                $hasCurrentPropertyImage = $currentPropertyImage !== '' && $currentPropertyImage !== 'default.jpg' && is_file($currentPropertyImagePath);
            ?><img id="property-image-preview" class="img-thumbnail mt-2<?= $hasCurrentPropertyImage ? '' : ' d-none' ?>" style="max-height: 180px;" alt="<?= $hasCurrentPropertyImage ? 'Current property image; selecting a new image will replace it' : 'Preview of the selected property image' ?>"<?= $hasCurrentPropertyImage ? ' src="assets/images/' . htmlspecialchars($currentPropertyImage, ENT_QUOTES, 'UTF-8') . '"' : '' ?>></div>
            <div class="col-12"><label class="form-label fw-semibold" for="property-description">Description</label><textarea id="property-description" name="description" class="form-control" rows="4" required minlength="10" maxlength="10000" placeholder="Share accurate property details, terms, documents available, and viewing instructions."><?= htmlspecialchars((string) ($propertyFormValues['description'] ?? ''), ENT_QUOTES, 'UTF-8') ?></textarea></div>
        </div>
        <?php if ($editingProperty): ?>
            <button type="submit" name="update_property" class="btn btn-primary mt-3 px-4">Save listing changes</button>
            <a href="admin_dashboard.php#property-list" class="btn btn-outline-secondary mt-3 ms-2">Cancel edit</a>
        <?php else: ?>
            <button type="submit" name="add_property" class="btn btn-primary mt-3 px-4">Publish listing</button>
        <?php endif; ?>
    </form>
</section>

<?php if (isset($_GET['property_trashed'])): ?>
    <div class="toast-container position-fixed bottom-0 end-0 p-3" style="z-index: 1090;">
        <div class="toast show bg-white border border-danger shadow-lg" role="alert" aria-live="assertive" aria-atomic="true">
            <div class="toast-header bg-danger text-white">
                <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24" class="me-2"><path d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                <strong class="me-auto">Property in Trashbin</strong>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="toast" aria-label="Close"></button>
            </div>
            <div class="toast-body text-dark">
                <p class="mb-2"><strong><?= htmlspecialchars($_GET['pname'] ?? 'The property', ENT_QUOTES, 'UTF-8') ?></strong> has been moved to the trashbin.</p>
                <button type="button" class="btn btn-sm btn-danger fw-semibold" data-bs-toggle="modal" data-bs-target="#trashbinModal">
                    🗑️ Open Trashbin
                </button>
            </div>
        </div>
    </div>
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', () => {
    // Nested modal support for delete confirmations over Trashbin modal
    document.addEventListener('show.bs.modal', function(e) {
        if (e.target && e.target.classList.contains('confirm-delete-modal')) {
            e.target.style.zIndex = '1070';
            setTimeout(() => {
                const backdrops = document.querySelectorAll('.modal-backdrop');
                if (backdrops.length > 1) {
                    backdrops[backdrops.length - 1].style.zIndex = '1065';
                }
            }, 10);
        }
    });
    document.addEventListener('hidden.bs.modal', function(e) {
        if (e.target && e.target.classList.contains('confirm-delete-modal')) {
            const trashModal = document.getElementById('trashbinModal');
            if (trashModal && trashModal.classList.contains('show')) {
                document.body.classList.add('modal-open');
            }
        }
    });

    // Helper to toggle visual style of explicit "Select" button on cards
    function updateTrashSelectBtn(checkbox) {
        const label = checkbox.closest('.trash-select-btn');
        if (!label) return;
        const textSpan = label.querySelector('.trash-select-text');
        if (checkbox.checked) {
            label.classList.remove('btn-outline-primary');
            label.classList.add('btn-primary', 'text-white');
            if (textSpan) textSpan.textContent = '✓ Selected';
        } else {
            label.classList.remove('btn-primary', 'text-white');
            label.classList.add('btn-outline-primary');
            if (textSpan) textSpan.textContent = 'Select';
        }
    }

    // Trashbin Properties: Search, Sort & Select-all
    const trashSearchInput = document.getElementById('trashSearchInput');
    const trashSortSelect = document.getElementById('trashSortSelect');
    const selectAllTrash = document.getElementById('selectAllTrash');
    const btnBulkRestore = document.getElementById('btnBulkRestore');
    const btnBulkDelete = document.getElementById('btnBulkDelete');
    const trashItemsContainer = document.getElementById('trashItemsContainer');
    const trashBulkForm = document.getElementById('trashBulkForm');
    const btnExecuteBulkDelete = document.getElementById('btnExecuteBulkDelete');

    if (trashItemsContainer) {
        function updateTrashControls() {
            const checkboxes = trashItemsContainer.querySelectorAll('.trash-item-checkbox:checked');
            const anyChecked = checkboxes.length > 0;
            if (btnBulkRestore) btnBulkRestore.disabled = !anyChecked;
            if (btnBulkDelete) btnBulkDelete.disabled = !anyChecked;
            const bulkDeleteText = document.getElementById('bulkDeleteText');
            if (bulkDeleteText) {
                bulkDeleteText.textContent = `Are you sure you want to permanently delete the ${checkboxes.length} selected properties?`;
            }
        }

        if (selectAllTrash) {
            selectAllTrash.addEventListener('change', function() {
                const visibleCheckboxes = trashItemsContainer.querySelectorAll('.trash-item:not([style*="display: none"]) .trash-item-checkbox');
                visibleCheckboxes.forEach(cb => {
                    cb.checked = selectAllTrash.checked;
                    updateTrashSelectBtn(cb);
                });
                updateTrashControls();
            });
        }

        trashItemsContainer.addEventListener('change', function(e) {
            if (e.target.classList.contains('trash-item-checkbox')) {
                updateTrashSelectBtn(e.target);
                updateTrashControls();
            }
        });

        if (btnExecuteBulkDelete && trashBulkForm) {
            btnExecuteBulkDelete.addEventListener('click', function() {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'bulk_permanent_delete';
                input.value = '1';
                trashBulkForm.appendChild(input);
                trashBulkForm.submit();
            });
        }

        if (trashSearchInput) {
            trashSearchInput.addEventListener('input', function() {
                const query = this.value.toLowerCase().trim();
                const items = trashItemsContainer.querySelectorAll('.trash-item');
                let matchCount = 0;
                items.forEach(item => {
                    const title = item.dataset.title || '';
                    const text = item.textContent.toLowerCase();
                    if (title.includes(query) || text.includes(query)) {
                        item.style.display = '';
                        matchCount++;
                    } else {
                        item.style.display = 'none';
                    }
                });
                const countBadge = document.getElementById('trashCountBadge');
                if (countBadge) {
                    countBadge.textContent = query !== '' ? `${matchCount} found` : `${items.length} in trash`;
                }
            });
        }

        if (trashSortSelect) {
            trashSortSelect.addEventListener('change', function() {
                const val = this.value;
                const items = Array.from(trashItemsContainer.querySelectorAll('.trash-item'));
                items.sort((a, b) => {
                    if (val === 'newest') return Number(b.dataset.date) - Number(a.dataset.date);
                    if (val === 'oldest') return Number(a.dataset.date) - Number(b.dataset.date);
                    if (val === 'price_asc') return Number(a.dataset.price) - Number(b.dataset.price);
                    if (val === 'price_desc') return Number(b.dataset.price) - Number(a.dataset.price);
                    if (val === 'name_asc') return (a.dataset.title || '').localeCompare(b.dataset.title || '');
                    if (val === 'name_desc') return (b.dataset.title || '').localeCompare(a.dataset.title || '');
                    return 0;
                });
                items.forEach(item => trashItemsContainer.appendChild(item));
            });
        }
    }

    // Admin Properties Table quick search
    const propTableSearch = document.getElementById('propTableSearch');
    if (propTableSearch) {
        propTableSearch.addEventListener('input', function() {
            const query = this.value.toLowerCase().trim();
            const rows = document.querySelectorAll('.prop-table-row');
            let matchCount = 0;
            rows.forEach(row => {
                const searchStr = row.getAttribute('data-search') || row.textContent.toLowerCase();
                if (query === '' || searchStr.includes(query)) {
                    row.style.display = '';
                    matchCount++;
                } else {
                    row.style.display = 'none';
                }
            });
            const activeBadge = document.getElementById('activePropsCountBadge');
            if (activeBadge) {
                activeBadge.textContent = query !== '' ? `${matchCount} found` : `${rows.length} active listings`;
            }
        });
    }
});
</script>

<!-- ══════════ SYSTEM AUDIT LOGS MODAL (SIR SADD) ══════════ -->
<div class="modal fade" id="auditLogsModal" tabindex="-1" aria-labelledby="auditLogsModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-xl modal-dialog-scrollable">
        <div class="modal-content shadow-lg border-0 rounded-4 overflow-hidden">
            <div class="modal-header border-0 text-white" style="background: linear-gradient(135deg, #142d4e 0%, #174a70 100%); padding: 1.25rem 1.5rem;">
                <div class="d-flex align-items-center gap-3">
                    <span class="fs-4">🛡️</span>
                    <div>
                        <h5 class="modal-title fw-bold text-white mb-0" id="auditLogsModalLabel">System Security &amp; Activity Audit Logs</h5>
                        <div class="small text-white-50">Immutable trail of administrative updates, role changes, and system modifications</div>
                    </div>
                </div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-3 p-md-4" style="background: #f8fafc;">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <div class="small text-muted">Showing latest 50 logged security events</div>
                </div>
                <div class="table-responsive">
                    <table class="table table-bordered table-hover align-middle bg-white small mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>#</th>
                                <th>Timestamp</th>
                                <th>User / Actor</th>
                                <th>Role</th>
                                <th>Action Taken</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($recentAuditLogs !== []): ?>
                                <?php foreach ($recentAuditLogs as $log): ?>
                                    <tr>
                                        <td class="text-muted fw-bold">#<?= (int) $log['logID'] ?></td>
                                        <td class="text-nowrap"><?= htmlspecialchars(date('M j, Y g:i A', strtotime($log['timestamp'])), ENT_QUOTES, 'UTF-8') ?></td>
                                        <td>
                                            <div class="fw-bold text-dark"><?= htmlspecialchars($log['fullname'] ?? 'System / Anonymous', ENT_QUOTES, 'UTF-8') ?></div>
                                            <?php if (!empty($log['email'])): ?><div class="text-muted" style="font-size: 0.75rem;"><?= htmlspecialchars($log['email'], ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
                                        </td>
                                        <td><span class="badge text-bg-light border"><?= htmlspecialchars($log['role'] ?? 'System', ENT_QUOTES, 'UTF-8') ?></span></td>
                                        <td class="text-break"><?= htmlspecialchars($log['action'], ENT_QUOTES, 'UTF-8') ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="5" class="text-center text-muted py-4">No audit log records found yet. All administrative actions are automatically recorded here.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
