<?php
include 'config/db.php';
require_once 'includes/auth.php';
require_once 'includes/location_directory.php';
require_once 'includes/property_categories.php';
require_once 'includes/property_images.php';
require_once 'includes/admin_management.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (normalized_user_role($_SESSION['user'] ?? null) !== 'Admin') {
    header('Location: admin_login.php');
    exit();
}

ensure_admin_management_schema($conn);

$locations = get_property_locations($conn);
$formError = '';
$formSuccess = isset($_GET['property_added'])
    ? 'The property listing has been published.'
    : (isset($_GET['property_updated']) ? 'The property listing has been updated.' : '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!has_valid_csrf_token()) {
        $formError = 'Your session expired. Refresh the page and try again.';
    } elseif (isset($_POST['delete_gallery_image'])) {
        $delImgId = (int) ($_POST['imageID'] ?? 0);
        $propId = (int) ($_POST['propertyID'] ?? 0);
        if ($delImgId > 0 && $propId > 0) {
            delete_property_gallery_image($conn, $delImgId, $propId);
        }
        header('Location: admin_dashboard.php?edit_property=' . $propId . '#properties');
        exit();
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
        } elseif ((float) $areaSqm < property_type_min_area_sqm($propertyType)) {
            $formError = sprintf(
                '%s listings require a realistic area of at least %s sq.m.',
                property_type_label($propertyType),
                number_format(property_type_min_area_sqm($propertyType), 0)
            );
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
                    $newPropertyId = (int) $conn->insert_id;
                    if ($newPropertyId > 0 && $imageName !== 'default.jpg') {
                        add_property_gallery_image($conn, $newPropertyId, $imageName, 'Primary photo', 1, true);
                    }
                    if ($newPropertyId > 0 && !empty($_FILES['galleryImages'])) {
                        $galleryFiles = store_property_images_batch($_FILES['galleryImages']);
                        $gSort = 2;
                        foreach ($galleryFiles as $gFile) {
                            add_property_gallery_image($conn, $newPropertyId, $gFile, null, $gSort, false);
                            $gSort++;
                        }
                    }
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
        } elseif ((float) $areaSqm < property_type_min_area_sqm($propertyType)) {
            $formError = sprintf(
                '%s listings require a realistic area of at least %s sq.m.',
                property_type_label($propertyType),
                number_format(property_type_min_area_sqm($propertyType), 0)
            );
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

                    if ($newImageName !== null && $newImageName !== 'default.jpg') {
                        add_property_gallery_image($conn, $editPropertyId, $newImageName, 'Primary photo', 1, true);
                    }
                    if (!empty($_FILES['galleryImages'])) {
                        $galleryFiles = store_property_images_batch($_FILES['galleryImages']);
                        if (!empty($galleryFiles)) {
                            $maxSortStmt = $conn->prepare("SELECT COALESCE(MAX(sortOrder), 0) AS maxSort FROM property_images WHERE propertyID = ?");
                            $maxSortStmt->bind_param('i', $editPropertyId);
                            $maxSortStmt->execute();
                            $maxSort = (int) $maxSortStmt->get_result()->fetch_assoc()['maxSort'];
                            foreach ($galleryFiles as $gFile) {
                                $maxSort++;
                                add_property_gallery_image($conn, $editPropertyId, $gFile, null, $maxSort, false);
                            }
                        }
                    }

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
            $statusTransactionStarted = false;
            try {
                $conn->begin_transaction();
                $statusTransactionStarted = true;
                $propertyLockStmt = $conn->prepare(
                    'SELECT propertyID, status FROM properties WHERE propertyID = ? AND isDeleted = 0 FOR UPDATE'
                );
                $propertyLockStmt->bind_param('i', $propertyId);
                $propertyLockStmt->execute();
                $lockedProperty = $propertyLockStmt->get_result()->fetch_assoc();
                if (!$lockedProperty) {
                    throw new InvalidArgumentException('The selected property was not found or is no longer active.');
                }

                $completedSaleStmt = $conn->prepare(
                    "SELECT transactionID FROM property_transactions
                     WHERE propertyID = ? AND currentStage = 'completed'
                     LIMIT 1"
                );
                $completedSaleStmt->bind_param('i', $propertyId);
                $completedSaleStmt->execute();
                $hasCompletedSale = $completedSaleStmt->get_result()->fetch_assoc() !== null;
                if (($hasCompletedSale || $lockedProperty['status'] === 'Sold') && $propertyStatus !== 'Sold') {
                    throw new InvalidArgumentException('A completed sale is final. This property must remain Sold and cannot be relisted.');
                }

                $statusStmt = $conn->prepare('UPDATE properties SET status = ? WHERE propertyID = ?');
                $statusStmt->bind_param('si', $propertyStatus, $propertyId);
                $statusStmt->execute();
                $conn->commit();
                $statusTransactionStarted = false;
                header('Location: admin_dashboard.php?property_status_updated=1#properties');
                exit();
            } catch (InvalidArgumentException $exception) {
                if ($statusTransactionStarted) {
                    $conn->rollback();
                }
                $formError = $exception->getMessage();
            } catch (mysqli_sql_exception $exception) {
                if ($statusTransactionStarted) {
                    $conn->rollback();
                }
                error_log('KingQuiry property status update failed: ' . $exception->getMessage());
                $formError = 'The property listing status could not be saved. Please try again.';
            } catch (Throwable $exception) {
                if ($statusTransactionStarted) {
                    $conn->rollback();
                }
                error_log('KingQuiry property status update failed: ' . $exception->getMessage());
                $formError = 'The property listing status could not be saved. Please try again.';
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
require_once 'includes/admin_analytics.php';
$adminMetrics = get_admin_summary_metrics($conn);

$adminRecentTx = $conn->query(
    "SELECT t.transactionID, t.propertyID, t.clientID, t.currentStage, t.salePrice, t.updatedAt, t.createdAt,
            p.title, p.location,
            u.fullname AS clientName, u.email AS clientEmail,
            COALESCE(agentUser.fullname, inqAgentUser.fullname, 'Unassigned') AS assignedAgentName,
            COALESCE(a.agentID, ia.agentID) AS assignedAgentID
     FROM property_transactions t
     JOIN properties p ON p.propertyID = t.propertyID
     JOIN users u ON u.userID = t.clientID
     LEFT JOIN appointments a ON a.appointmentID = t.appointmentID
     LEFT JOIN users agentUser ON agentUser.userID = a.agentID
     LEFT JOIN agent_inquiry_assignments ia ON ia.inquiryID = t.inquiryID
     LEFT JOIN users inqAgentUser ON inqAgentUser.userID = ia.agentID
     WHERE t.currentStage NOT IN ('inquiry_received', 'contacting_client')
     ORDER BY (a.agentID IS NULL AND ia.agentID IS NULL) DESC, t.updatedAt DESC, t.transactionID DESC
     LIMIT 25"
)->fetch_all(MYSQLI_ASSOC);

$adminStageLabels = function_exists('property_transaction_stages') ? property_transaction_stages() : [
    'reservation_requested' => 'Reservation Requested',
    'reservation_confirmed' => 'Reservation Confirmed',
    'viewing_requested' => 'Viewing Requested',
    'reviewing_schedule' => 'Reviewing Schedule',
    'viewing_confirmed' => 'Viewing Approved',
    'viewing_completed' => 'Viewing Completed',
    'payment_in_process' => 'Payment In Process',
    'document_verification' => 'Document Verification',
    'payment_confirmation' => 'Payment Confirmed',
    'completed' => 'Completed',
    'cancelled' => 'Cancelled',
];

$properties = $conn->query(
    "SELECT p.*, l.municipalityName, l.provinceName,
            EXISTS(
                SELECT 1 FROM property_transactions t
                WHERE t.propertyID = p.propertyID AND t.currentStage = 'completed'
            ) AS hasCompletedSale
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

<?php if ($formSuccess !== ''): ?><div class="alert alert-success mb-3" role="status"><?= htmlspecialchars($formSuccess, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
<?php if ($formError !== '' && !isset($_GET['edit_property']) && !isset($_POST['update_property'])): ?><div class="alert alert-danger mb-3" role="alert"><?= htmlspecialchars($formError, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>

<section class="mb-4" aria-labelledby="business-analytics-title">
    <div class="d-flex flex-wrap justify-content-between align-items-end gap-3 mb-3">
        <div>
            <div class="page-eyebrow">Admin overview</div>
            <h2 class="h4 fw-bold mb-0" id="business-analytics-title">Business at a glance</h2>
        </div>
    </div>
    <div class="row g-3">
        <div class="col-6 col-lg-4 col-xl-2"><div class="card h-100"><div class="card-body p-3"><div class="page-eyebrow">Available listings</div><h3 class="h3 mb-0"><?= number_format($adminMetrics['available_listings']) ?></h3><small class="text-muted">Not deleted</small></div></div></div>
        <div class="col-6 col-lg-4 col-xl-2"><div class="card h-100"><div class="card-body p-3"><div class="page-eyebrow text-warning">Pending viewing requests</div><h3 class="h3 mb-0 text-warning"><?= number_format($adminMetrics['pending_viewing_requests']) ?></h3><small class="text-muted">Awaiting review</small></div></div></div>
        <div class="col-6 col-lg-4 col-xl-2"><div class="card h-100"><div class="card-body p-3"><div class="page-eyebrow text-info">Inquiries (30 days)</div><h3 class="h3 mb-0 text-info"><?= number_format($adminMetrics['recent_inquiries']) ?></h3><small class="text-muted">All client inquiries</small></div></div></div>
        <div class="col-6 col-lg-4 col-xl-2"><div class="card h-100"><div class="card-body p-3"><div class="page-eyebrow text-primary">Active pipeline</div><h3 class="h3 mb-0 text-primary"><?= number_format($adminMetrics['active_pipeline']) ?></h3><small class="text-muted"><a href="admin_sales.php" class="text-decoration-none">Transactions &rarr;</a></small></div></div></div>
        <div class="col-6 col-lg-4 col-xl-2"><div class="card h-100"><div class="card-body p-3"><div class="page-eyebrow text-success">Completed sales</div><h3 class="h3 mb-0 text-success"><?= number_format($adminMetrics['completed_sales']) ?></h3><small class="text-muted">All completed transactions</small></div></div></div>
        <div class="col-6 col-lg-4 col-xl-2"><div class="card h-100"><div class="card-body p-3">        <div class="page-eyebrow text-success">Actual revenue</div><h3 class="h3 mb-0 text-success"><?= htmlspecialchars(kq_format_compact_peso($adminMetrics['actual_revenue']), ENT_QUOTES, 'UTF-8') ?></h3><small class="text-muted">Recorded sale prices</small></div></div></div>
    </div>
</section>

<section class="card p-3 p-lg-4 mb-4 shadow-sm" id="client-transactions">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-3 pb-3 border-bottom">
        <div>
            <div class="page-eyebrow mb-1">Live Pipeline</div>
            <h2 class="h4 fw-bold mb-0">Client Transactions &amp; Pipeline</h2>
            <p class="text-muted small mb-0">Real-time overview of all transactions created from client viewing requests and property inquiries.</p>
        </div>
        <div class="d-flex flex-wrap align-items-center gap-2">
            <span class="badge bg-primary-subtle text-primary border border-primary-subtle rounded-pill px-3 py-1"><?= count($adminRecentTx) ?> Tracked Case<?= count($adminRecentTx) === 1 ? '' : 's' ?></span>
            <a href="transaction_tracking.php" class="btn btn-sm btn-primary">Open Tracking Hub &rarr;</a>
        </div>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>Ref ID</th>
                    <th>Property</th>
                    <th>Client</th>
                    <th>Stage</th>
                    <th>Assigned Agent</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php if ($adminRecentTx !== []): ?>
                <?php foreach ($adminRecentTx as $tx):
                    $txId = (int) $tx['transactionID'];
                    $txStage = (string) $tx['currentStage'];
                    $isUnassigned = empty($tx['assignedAgentID']);
                    $txBadge = match ($txStage) {
                        'completed' => 'text-bg-success',
                        'cancelled' => 'text-bg-secondary',
                        'reservation_requested' => 'text-bg-warning text-dark',
                        'reservation_confirmed' => 'text-bg-success',
                        'payment_in_process' => 'text-bg-warning text-dark',
                        'payment_confirmation' => 'text-bg-info text-dark',
                        default => 'text-bg-primary',
                    };
                ?>
                    <tr>
                        <td>
                            <strong class="text-dark">#KQT-<?= $txId ?></strong>
                            <div class="small text-muted"><?= htmlspecialchars(date('M j, Y', strtotime($tx['createdAt'])), ENT_QUOTES, 'UTF-8') ?></div>
                        </td>
                        <td>
                            <strong class="text-dark text-truncate d-block" style="max-width: 220px;"><?= htmlspecialchars($tx['title'], ENT_QUOTES, 'UTF-8') ?></strong>
                            <div class="small text-muted text-truncate" style="max-width: 220px;"><?= htmlspecialchars($tx['location'], ENT_QUOTES, 'UTF-8') ?></div>
                        </td>
                        <td>
                            <div class="fw-semibold text-dark"><?= htmlspecialchars($tx['clientName'], ENT_QUOTES, 'UTF-8') ?></div>
                            <div class="small text-muted"><?= htmlspecialchars($tx['clientEmail'], ENT_QUOTES, 'UTF-8') ?></div>
                        </td>
                        <td>
                            <span class="badge rounded-pill <?= $txBadge ?>"><?= htmlspecialchars($adminStageLabels[$txStage] ?? ucfirst($txStage), ENT_QUOTES, 'UTF-8') ?></span>
                        </td>
                        <td>
                            <?php if ($isUnassigned): ?>
                                <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle rounded-pill">Unassigned</span>
                            <?php else: ?>
                                <span class="badge bg-secondary-subtle text-secondary rounded-pill"><?= htmlspecialchars($tx['assignedAgentName'], ENT_QUOTES, 'UTF-8') ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="text-end">
                            <div class="d-inline-flex align-items-center gap-1 justify-content-end flex-wrap">
                                <button type="button" class="btn btn-sm btn-outline-primary d-inline-flex align-items-center gap-1 shadow-sm text-nowrap" onclick="openDirectChat(<?= $txId ?>, '<?= addslashes(htmlspecialchars($tx['title'], ENT_QUOTES, 'UTF-8')) ?>', '<?= addslashes(htmlspecialchars($tx['clientName'], ENT_QUOTES, 'UTF-8')) ?>')" title="Direct chat with <?= htmlspecialchars($tx['clientName'], ENT_QUOTES, 'UTF-8') ?>">
                                    <span>💬 Chat</span>
                                </button>
                                <a href="transaction_tracking.php" class="btn btn-sm btn-primary fw-semibold shadow-sm text-nowrap">
                                    <span>Track &rarr;</span>
                                </a>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr>
                    <td colspan="6" class="text-center text-muted py-4">No client transactions yet. Transactions initiated by clients will appear here automatically.</td>
                </tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<section class="card p-3 p-lg-4 mb-4 shadow-sm" id="property-list">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-3 pb-3 border-bottom">
        <div>
            <div class="page-eyebrow mb-1">Current inventory</div>
            <h2 class="h4 fw-bold mb-0">Property listings</h2>
            <p class="text-muted small mb-0">Active property listings visible across the KingQuiry catalog and client portals.</p>
        </div>
        <div class="d-flex flex-wrap align-items-center gap-2">
            <span class="badge bg-primary px-3 py-2 fs-6 fw-bold" id="activePropsCountBadge"><?= number_format($properties->num_rows) ?> active listings</span>
            <a href="#properties" class="btn btn-sm btn-primary fw-semibold">Publish listing</a>
            <button type="button" class="btn btn-outline-danger fw-semibold d-inline-flex align-items-center gap-1" data-bs-toggle="modal" data-bs-target="#trashbinModal" id="btnOpenTrashbin">
                Trashbin (<span id="trashHeaderBadge"><?= $totalTrashed ?></span>)
            </button>
        </div>
    </div>

    <!-- Quick Search Input for Property Table -->
    <div class="row g-2 mb-3">
        <div class="col-md-6 col-lg-4">
            <input type="text" id="propTableSearch" class="form-control form-control-sm" placeholder="Search by title, category, or location">
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
                        <td><span class="badge text-bg-light border"><?= htmlspecialchars($row['propertyType']) ?></span></td>
                        <td><?= htmlspecialchars($propLocLabel) ?><?php if (!empty($row['barangay'])): ?><br><small class="text-muted"><?= htmlspecialchars($row['barangay']) ?></small><?php endif; ?></td>
                        <td>₱<?= number_format((float) $row['price'], 2) ?><br><small class="text-muted"><?= $row['areaSqm'] !== null ? number_format((float) $row['areaSqm'], 2) . ' sq.m. · ₱' . number_format((float) $row['pricePerSqm'], 2) . '/sq.m.' : 'Legacy area not recorded' ?></small></td>
                        <td>
                            <?php if ((int) $row['hasCompletedSale'] === 1 || $rowPropertyStatus === 'Sold'): ?>
                                <span class="badge rounded-pill text-bg-danger"><i class="bi bi-lock-fill me-1" aria-hidden="true"></i>Sold · locked</span>
                                <div class="small text-muted mt-1">Completed sale; cannot be relisted.</div>
                            <?php else: ?>
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
                            <?php endif; ?>
                        </td>
                        <td class="text-end">
                            <div class="d-flex justify-content-end align-items-center gap-1">
                                <a href="admin_dashboard.php?edit_property=<?= (int) $row['propertyID'] ?>#properties" class="btn btn-sm btn-outline-primary py-1 px-2" title="Edit all property details">Edit</a>
                                <a href="property_detail.php?id=<?= (int) $row['propertyID'] ?>" target="_blank" class="btn btn-sm btn-outline-secondary py-1 px-2" title="View live property page">View</a>
                                <form method="POST" class="m-0 d-inline" data-confirm="Move this property to the trash? It will be hidden from the public catalog.">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
                                    <input type="hidden" name="propertyID" value="<?= (int) $row['propertyID'] ?>">
                                    <button type="submit" name="trash_property" class="btn btn-sm btn-outline-danger py-1 px-2" title="Move to trash bin">Trash</button>
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
                        <input type="text" id="trashSearchInput" class="form-control form-control-sm" placeholder="Search trashed properties by title or location">
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
                                Restore Selected
                            </button>
                            <button type="button" id="btnBulkDelete" class="btn btn-sm btn-danger py-1 px-3 fw-semibold" data-bs-toggle="modal" data-bs-target="#bulkDeleteModal" disabled title="Permanently delete selected properties">
                                Delete Selected
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
                                                Restore
                                            </button>
                                            <!-- Individual Permanent Delete Button -->
                                            <button type="button" class="btn btn-sm btn-outline-danger py-1 px-2 fw-semibold" data-bs-toggle="modal" data-bs-target="#deleteSingleModal<?= $tId ?>" title="Delete permanently">
                                                Delete
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="p-4 text-center text-muted small bg-light rounded" id="trashEmptyMsg">
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

            <div class="col-12">
                <label class="form-label fw-semibold" for="property-gallery-images">
                    <?= $editingProperty ? 'Add more gallery photos' : 'Additional gallery photos' ?>
                    <span class="text-muted fw-normal">(optional, select multiple JPG/PNG/WebP photos up to 5 MB each)</span>
                </label>
                <input id="property-gallery-images" type="file" name="galleryImages[]" class="form-control" multiple accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp">
            </div>

            <?php if ($editingProperty): 
                $adminEditGallery = get_property_gallery_images($conn, (int) $editingProperty['propertyID']);
                if (!empty($adminEditGallery)):
            ?>
                <div class="col-12">
                    <label class="form-label fw-semibold d-block">Existing Property Gallery Photos (<?= count($adminEditGallery) ?>)</label>
                    <div class="d-flex flex-wrap gap-3 align-items-center p-3 bg-light rounded-3 border">
                        <?php foreach ($adminEditGallery as $gImg): 
                            $gFile = basename($gImg['imagePath']);
                        ?>
                            <div class="position-relative text-center bg-white p-1 rounded-2 border shadow-sm" style="width: 104px;">
                                <img src="assets/images/<?= rawurlencode($gFile) ?>" class="rounded-2 w-100" style="height: 68px; object-fit: cover;" alt="Gallery photo">
                                <?php if (!empty($gImg['isPrimary'])): ?>
                                    <span class="badge bg-primary position-absolute top-0 start-0 m-1" style="font-size: 0.65rem;">Primary</span>
                                <?php endif; ?>
                                <button type="submit" name="delete_gallery_image" form="deleteGalleryPhotoForm_<?= (int) $gImg['imageID'] ?>" class="btn btn-sm btn-outline-danger py-0 px-1 mt-1 w-100" style="font-size: 0.72rem;" title="Remove this photo" onclick="return confirm('Remove this photo from the property gallery?');">Remove</button>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; endif; ?>

            <div class="col-12"><label class="form-label fw-semibold" for="property-description">Description</label><textarea id="property-description" name="description" class="form-control" rows="4" required minlength="10" maxlength="10000" placeholder="Share accurate property details, terms, documents available, and viewing instructions."><?= htmlspecialchars((string) ($propertyFormValues['description'] ?? ''), ENT_QUOTES, 'UTF-8') ?></textarea></div>
        </div>
        <?php if ($editingProperty): ?>
            <button type="submit" name="update_property" class="btn btn-primary mt-3 px-4">Save listing changes</button>
            <a href="admin_dashboard.php#property-list" class="btn btn-outline-secondary mt-3 ms-2">Cancel edit</a>
        <?php else: ?>
            <button type="submit" name="add_property" class="btn btn-primary mt-3 px-4">Publish listing</button>
        <?php endif; ?>
    </form>
    <?php if ($editingProperty && !empty($adminEditGallery)): ?>
        <?php foreach ($adminEditGallery as $gImg): ?>
            <form id="deleteGalleryPhotoForm_<?= (int) $gImg['imageID'] ?>" method="POST" action="admin_dashboard.php?edit_property=<?= (int) $editingProperty['propertyID'] ?>#properties" class="d-none">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="delete_gallery_image" value="1">
                <input type="hidden" name="imageID" value="<?= (int) $gImg['imageID'] ?>">
                <input type="hidden" name="propertyID" value="<?= (int) $editingProperty['propertyID'] ?>">
            </form>
        <?php endforeach; ?>
    <?php endif; ?>
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
                    Open Trashbin
                </button>
            </div>
        </div>
    </div>
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', () => {
    // Nested modal support for delete confirmations over Trashbin modal
    function promoteConfirmDeleteBackdrop() {
        const backdrops = document.querySelectorAll('.modal-backdrop');
        if (backdrops.length > 1) {
            const topBackdrop = backdrops[backdrops.length - 1];
            topBackdrop.classList.add('confirm-delete-backdrop');
            topBackdrop.style.setProperty('z-index', '1065', 'important');
        }
    }

    const backdropObserver = new MutationObserver(() => {
        const activeModal = document.querySelector('.confirm-delete-modal.show, .confirm-delete-modal.showing');
        if (activeModal) {
            promoteConfirmDeleteBackdrop();
        }
    });
    backdropObserver.observe(document.body, { childList: true });

    document.addEventListener('show.bs.modal', function(e) {
        if (e.target && e.target.classList.contains('confirm-delete-modal')) {
            e.target.style.setProperty('z-index', '1070', 'important');
            promoteConfirmDeleteBackdrop();
            setTimeout(promoteConfirmDeleteBackdrop, 10);
            setTimeout(promoteConfirmDeleteBackdrop, 50);
            setTimeout(promoteConfirmDeleteBackdrop, 150);
        }
    });

    document.addEventListener('shown.bs.modal', function(e) {
        if (e.target && e.target.classList.contains('confirm-delete-modal')) {
            e.target.style.setProperty('z-index', '1070', 'important');
            promoteConfirmDeleteBackdrop();
        }
    });

    document.addEventListener('hidden.bs.modal', function(e) {
        if (e.target && e.target.classList.contains('confirm-delete-modal')) {
            const trashModal = document.getElementById('trashbinModal');
            if (trashModal && trashModal.classList.contains('show')) {
                document.body.classList.add('modal-open');
                trashModal.style.overflowY = 'auto';
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

<?php include 'includes/footer.php'; ?>
