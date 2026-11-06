<?php
include 'config/db.php';
require_once 'includes/auth.php';
require_once 'includes/admin_management.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

apply_secure_headers();
require_role('Admin');
ensure_admin_management_schema($conn);

$actorId = (int) ($_SESSION['user']['userID'] ?? 0);
$formError = '';
$formSuccess = '';

$flashMessages = [
    'client_added' => 'The client account has been created.',
    'client_updated' => 'The client profile has been updated.',
    'password_reset' => 'The client password has been reset.',
    'client_deleted' => 'The client account has been removed.',
    'status_updated' => 'The client account status has been updated.',
];
foreach ($flashMessages as $flashKey => $flashMessage) {
    if (isset($_GET[$flashKey])) {
        $formSuccess = $flashMessage;
        break;
    }
}

$clientForm = [
    'userID' => 0,
    'fullname' => '',
    'email' => '',
    'phone' => '',
    'address' => '',
    'accountStatus' => 'Active',
];
$formMode = 'create';
$showPasswordField = true;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['client_action'])) {
    $action = is_string($_POST['client_action'] ?? null) ? $_POST['client_action'] : '';

    if (!has_valid_csrf_token()) {
        $formError = 'Your session expired. Refresh the page and try again.';
    } else {
        $fullname = is_string($_POST['fullname'] ?? null) ? trim($_POST['fullname']) : '';
        $email = is_string($_POST['email'] ?? null) ? strtolower(trim($_POST['email'])) : '';
        $phone = is_string($_POST['phone'] ?? null) ? trim($_POST['phone']) : '';
        $address = is_string($_POST['address'] ?? null) ? trim($_POST['address']) : '';
        $accountStatus = is_string($_POST['accountStatus'] ?? null) ? $_POST['accountStatus'] : 'Active';
        $clientId = filter_var($_POST['userID'] ?? null, FILTER_VALIDATE_INT);

        if (in_array($action, ['create_client', 'update_client'], true)) {
            $clientForm = [
                'userID' => $action === 'update_client' && $clientId !== false ? (int) $clientId : 0,
                'fullname' => $fullname,
                'email' => $email,
                'phone' => $phone,
                'address' => $address,
                'accountStatus' => $accountStatus,
            ];
            $formMode = $action === 'update_client' ? 'edit' : 'create';
            $showPasswordField = $action === 'create_client';
        }

        try {
            if ($action === 'create_client') {
                $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';

                if (strlen($fullname) < 2 || strlen($fullname) > 100 || !preg_match("/^[\p{L}\p{M} .'-]+$/u", $fullname)) {
                    throw new InvalidArgumentException('Enter a valid client name using letters and standard punctuation.');
                }
                if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 100) {
                    throw new InvalidArgumentException('Enter a valid client email address.');
                }
                if ($phone !== '' && (strlen($phone) > 30 || !preg_match('/^[0-9+()\-.\s]{6,30}$/', $phone))) {
                    throw new InvalidArgumentException('Enter a valid phone number or leave the field empty.');
                }
                if (strlen($address) > 255) {
                    throw new InvalidArgumentException('The address must be 255 characters or fewer.');
                }
                if (strlen($password) < 8 || strlen($password) > 72) {
                    throw new InvalidArgumentException('Use a temporary password between 8 and 72 characters.');
                }

                $passwordHash = password_hash($password, PASSWORD_DEFAULT);
                if ($passwordHash === false) {
                    throw new RuntimeException('The password could not be secured.');
                }

                $role = 'Client';
                $stmt = $conn->prepare(
                    'INSERT INTO users (fullname, email, phone, address, passwordHash, role, accountStatus)
                     VALUES (?, ?, ?, ?, ?, ?, ?)'
                );
                $stmt->bind_param('sssssss', $fullname, $email, $phone, $address, $passwordHash, $role, $accountStatus);
                $stmt->execute();

                kq_log_admin_action(
                    $conn,
                    $actorId,
                    'Created client account for ' . $fullname . ' (' . $email . ').'
                );
                header('Location: admin_clients.php?client_added=1');
                exit();
            }

            if ($action === 'update_client') {
                if ($clientId === false || $clientId <= 0) {
                    throw new InvalidArgumentException('Choose a valid client to update.');
                }
                if (strlen($fullname) < 2 || strlen($fullname) > 100 || !preg_match("/^[\p{L}\p{M} .'-]+$/u", $fullname)) {
                    throw new InvalidArgumentException('Enter a valid client name using letters and standard punctuation.');
                }
                if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 100) {
                    throw new InvalidArgumentException('Enter a valid client email address.');
                }
                if ($phone !== '' && (strlen($phone) > 30 || !preg_match('/^[0-9+()\-.\s]{6,30}$/', $phone))) {
                    throw new InvalidArgumentException('Enter a valid phone number or leave the field empty.');
                }
                if (strlen($address) > 255) {
                    throw new InvalidArgumentException('The address must be 255 characters or fewer.');
                }
                if (!in_array($accountStatus, ['Active', 'Inactive'], true)) {
                    throw new InvalidArgumentException('Choose a valid account status.');
                }

                $role = 'Client';
                $stmt = $conn->prepare(
                    'UPDATE users
                     SET fullname = ?, email = ?, phone = ?, address = ?, accountStatus = ?
                     WHERE userID = ? AND role = ?'
                );
                $stmt->bind_param('sssssis', $fullname, $email, $phone, $address, $accountStatus, $clientId, $role);
                $stmt->execute();
                if ($stmt->affected_rows === 0) {
                    $checkStmt = $conn->prepare("SELECT userID FROM users WHERE userID = ? AND role = 'Client' LIMIT 1");
                    $checkStmt->bind_param('i', $clientId);
                    $checkStmt->execute();
                    if (!$checkStmt->get_result()->fetch_assoc()) {
                        throw new InvalidArgumentException('The selected client was not found.');
                    }
                }

                kq_log_admin_action(
                    $conn,
                    $actorId,
                    'Updated client profile for ' . $fullname . ' (#' . $clientId . ').'
                );
                header('Location: admin_clients.php?client_updated=1');
                exit();
            }

            if ($action === 'reset_password') {
                $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
                if ($clientId === false || $clientId <= 0) {
                    throw new InvalidArgumentException('Choose a valid client.');
                }
                if (strlen($password) < 8 || strlen($password) > 72) {
                    throw new InvalidArgumentException('Use a new password between 8 and 72 characters.');
                }

                $passwordHash = password_hash($password, PASSWORD_DEFAULT);
                if ($passwordHash === false) {
                    throw new RuntimeException('The password could not be secured.');
                }

                $role = 'Client';
                $stmt = $conn->prepare('UPDATE users SET passwordHash = ? WHERE userID = ? AND role = ?');
                $stmt->bind_param('sis', $passwordHash, $clientId, $role);
                $stmt->execute();
                if ($stmt->affected_rows === 0) {
                    throw new InvalidArgumentException('The selected client was not found or the password was unchanged.');
                }

                kq_log_admin_action($conn, $actorId, 'Reset client password for user #' . $clientId . '.');
                header('Location: admin_clients.php?password_reset=1');
                exit();
            }

            if ($action === 'set_client_status') {
                if ($clientId === false || $clientId <= 0 || !in_array($accountStatus, ['Active', 'Inactive'], true)) {
                    throw new InvalidArgumentException('Choose a valid client and account status.');
                }

                $role = 'Client';
                $stmt = $conn->prepare('UPDATE users SET accountStatus = ? WHERE userID = ? AND role = ?');
                $stmt->bind_param('sis', $accountStatus, $clientId, $role);
                $stmt->execute();
                if ($stmt->affected_rows === 0) {
                    throw new InvalidArgumentException('The selected client was not found.');
                }

                kq_log_admin_action(
                    $conn,
                    $actorId,
                    'Set client #' . $clientId . ' account status to ' . $accountStatus . '.'
                );
                header('Location: admin_clients.php?status_updated=1');
                exit();
            }

            if ($action === 'delete_client') {
                if ($clientId === false || $clientId <= 0) {
                    throw new InvalidArgumentException('Choose a valid client to remove.');
                }

                $checkStmt = $conn->prepare(
                    "SELECT
                        (SELECT COUNT(*) FROM appointments WHERE clientID = ?) +
                        (SELECT COUNT(*) FROM feedbackinquiries WHERE clientID = ?) +
                        (SELECT COUNT(*) FROM property_transactions WHERE clientID = ?) +
                        (SELECT COUNT(*) FROM payments WHERE clientID = ?) AS linkedRecords"
                );
                $checkStmt->bind_param('iiii', $clientId, $clientId, $clientId, $clientId);
                $checkStmt->execute();
                $linkedRecords = (int) ($checkStmt->get_result()->fetch_assoc()['linkedRecords'] ?? 0);
                if ($linkedRecords > 0) {
                    throw new InvalidArgumentException(
                        'This client has appointments, inquiries, transactions, or payments on record. Set the account to Inactive instead so history is preserved.'
                    );
                }

                $role = 'Client';
                $stmt = $conn->prepare('DELETE FROM users WHERE userID = ? AND role = ?');
                $stmt->bind_param('is', $clientId, $role);
                $stmt->execute();
                if ($stmt->affected_rows === 0) {
                    throw new InvalidArgumentException('The selected client was not found.');
                }

                kq_log_admin_action($conn, $actorId, 'Removed client account #' . $clientId . '.');
                header('Location: admin_clients.php?client_deleted=1');
                exit();
            }

            throw new InvalidArgumentException('Choose a valid client action.');
        } catch (InvalidArgumentException $exception) {
            $formError = $exception->getMessage();
        } catch (mysqli_sql_exception $exception) {
            if ($exception->getCode() === 1062) {
                $formError = 'That email address is already used by another account.';
            } else {
                error_log('KingQuiry client management failed: ' . $exception->getMessage());
                $formError = 'The client record could not be saved. Confirm MySQL is running and try again.';
            }
        } catch (Throwable $exception) {
            error_log('KingQuiry client management failed: ' . $exception->getMessage());
            $formError = 'The client record could not be saved. Please try again.';
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || $formError === '') {
    $editId = filter_var($_GET['edit'] ?? null, FILTER_VALIDATE_INT);
    if ($editId !== false && $editId !== null && $editId > 0) {
        $stmt = $conn->prepare(
            "SELECT userID, fullname, email, phone, address, accountStatus
             FROM users
             WHERE userID = ? AND role = 'Client'
             LIMIT 1"
        );
        $stmt->bind_param('i', $editId);
        $stmt->execute();
        $editingClient = $stmt->get_result()->fetch_assoc();
        if ($editingClient) {
            $clientForm = [
                'userID' => (int) $editingClient['userID'],
                'fullname' => (string) $editingClient['fullname'],
                'email' => (string) $editingClient['email'],
                'phone' => (string) ($editingClient['phone'] ?? ''),
                'address' => (string) ($editingClient['address'] ?? ''),
                'accountStatus' => (string) ($editingClient['accountStatus'] ?? 'Active'),
            ];
            $formMode = 'edit';
            $showPasswordField = false;
        } elseif ($formError === '') {
            $formError = 'The selected client was not found.';
        }
    }
}

$search = is_string($_GET['q'] ?? null) ? trim($_GET['q']) : '';
$statusFilter = is_string($_GET['status'] ?? null) ? $_GET['status'] : '';
if (!in_array($statusFilter, ['Active', 'Inactive'], true)) {
    $statusFilter = '';
}

$clients = [];
$where = ["u.role = 'Client'"];
$bindTypes = '';
$bindValues = [];

if ($search !== '') {
    $where[] = '(u.fullname LIKE ? OR u.email LIKE ? OR u.phone LIKE ?)';
    $like = '%' . $search . '%';
    $bindTypes .= 'sss';
    array_push($bindValues, $like, $like, $like);
}
if ($statusFilter !== '') {
    $where[] = 'u.accountStatus = ?';
    $bindTypes .= 's';
    $bindValues[] = $statusFilter;
}

$clientQuery = $conn->prepare(
    "SELECT u.userID, u.fullname, u.email, u.phone, u.address, u.accountStatus, u.createdAt,
            (SELECT COUNT(*) FROM appointments a WHERE a.clientID = u.userID) AS appointmentCount,
            (SELECT COUNT(*) FROM feedbackinquiries i WHERE i.clientID = u.userID) AS inquiryCount,
            (SELECT COUNT(*) FROM property_transactions t WHERE t.clientID = u.userID) AS transactionCount,
            (SELECT COUNT(*) FROM property_transactions t
              WHERE t.clientID = u.userID AND t.currentStage NOT IN ('completed', 'cancelled')) AS activeTransactionCount,
            (SELECT COUNT(*) FROM payments p WHERE p.clientID = u.userID) AS paymentCount,
            (SELECT COALESCE(SUM(p.amount), 0) FROM payments p
              WHERE p.clientID = u.userID AND p.status = 'Verified') AS verifiedPaymentTotal,
            NULLIF(GREATEST(
                COALESCE((SELECT MAX(a.createdAt) FROM appointments a WHERE a.clientID = u.userID), '1970-01-01 00:00:00'),
                COALESCE((SELECT MAX(i.createdAt) FROM feedbackinquiries i WHERE i.clientID = u.userID), '1970-01-01 00:00:00'),
                COALESCE((SELECT MAX(t.updatedAt) FROM property_transactions t WHERE t.clientID = u.userID), '1970-01-01 00:00:00'),
                COALESCE((SELECT MAX(p.createdAt) FROM payments p WHERE p.clientID = u.userID), '1970-01-01 00:00:00')
            ), '1970-01-01 00:00:00') AS lastActivity
     FROM users u
     WHERE " . implode(' AND ', $where) . "
     ORDER BY u.createdAt DESC, u.userID DESC
     LIMIT 500"
);
if ($bindTypes !== '') {
    kq_bind_dynamic($clientQuery, $bindTypes, $bindValues);
}
$clientQuery->execute();
$clientsResult = $clientQuery->get_result();
while ($row = $clientsResult->fetch_assoc()) {
    $clients[] = $row;
}

$clientStats = $conn->query(
    "SELECT COUNT(*) AS totalClients,
            COALESCE(SUM(accountStatus = 'Active'), 0) AS activeClients,
            COALESCE(SUM(accountStatus = 'Inactive'), 0) AS inactiveClients,
            COALESCE(SUM(createdAt >= DATE_FORMAT(CURRENT_DATE, '%Y-%m-01')), 0) AS newThisMonth
     FROM users
     WHERE role = 'Client'"
)->fetch_assoc();

$collectionStats = $conn->query(
    "SELECT COALESCE(SUM(CASE WHEN status = 'Verified' THEN amount ELSE 0 END), 0) AS verifiedTotal,
            COALESCE(SUM(CASE WHEN status = 'Pending' THEN amount ELSE 0 END), 0) AS pendingTotal
     FROM payments"
)->fetch_assoc();

$activeTrackedClients = (int) $conn->query(
    "SELECT COUNT(DISTINCT clientID) AS clientCount
     FROM property_transactions
     WHERE currentStage NOT IN ('completed', 'cancelled')"
)->fetch_assoc()['clientCount'];

include 'includes/header.php';
?>

<style>
    body {
        background: radial-gradient(circle at top, rgba(13, 37, 43, 0.85), rgba(6, 15, 19, 1) 52%);
    }

    .kq-admin-shell {
        max-width: 1280px;
        margin: 0 auto;
        padding: 1.2rem 0 2rem;
        color: #f7f5f0;
    }

    .kq-admin-shell .page-title { color: #f7f5f0; }
    .kq-admin-shell .text-muted { color: rgba(245, 238, 227, 0.74) !important; }
    .kq-admin-shell .card {
        border: 1px solid rgba(20, 34, 41, 0.08) !important;
        border-radius: 1.2rem !important;
        background: rgba(255, 255, 255, 0.98);
        color: var(--kq-ink);
        box-shadow: 0 18px 40px rgba(0, 0, 0, 0.12);
    }
    .kq-admin-shell .card .text-muted { color: #536579 !important; }
    .kq-admin-shell .card .page-eyebrow { color: #805a20; }
    .kq-admin-shell .kq-stat-card {
        background: linear-gradient(180deg, rgba(255,255,255,1), rgba(243,247,250,1));
        border: 1px solid rgba(19, 45, 66, 0.08);
        border-radius: 1rem;
        box-shadow: 0 12px 26px rgba(17, 43, 69, 0.07);
        height: 100%;
        padding: 1.1rem 1.2rem;
    }
    .kq-admin-shell .table { --bs-table-color: var(--kq-ink); --bs-table-border-color: var(--kq-line); }
    .kq-admin-shell .form-label { color: #33475c; }
    .kq-admin-shell .kq-panel-header {
        position: relative;
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 1rem;
        margin-bottom: 1.1rem;
        padding-bottom: .75rem;
        border-bottom: 1px solid rgba(19, 45, 66, 0.08);
    }
    .kq-admin-shell .kq-panel-header h2 { color: #f7f5f0; }
    .kq-admin-shell .kq-badge-soft {
        display: inline-flex;
        align-items: center;
        padding: .38rem .7rem;
        border-radius: 999px;
        background: rgba(255, 255, 255, 0.12);
        color: #f7f5f0;
        font-size: .75rem;
        font-weight: 700;
    }
    .kq-admin-shell .kq-dashboard-hero {
        display: flex;
        align-items: center;
        gap: .9rem;
        padding: 1rem 1.2rem;
        margin-bottom: 1.5rem;
        border: 1px solid rgba(212, 175, 110, 0.28);
        border-radius: 1.1rem;
        background: linear-gradient(135deg, rgba(14, 29, 39, 0.92), rgba(25, 47, 58, 0.82));
        box-shadow: 0 14px 32px rgba(0, 0, 0, 0.14);
    }
    .kq-admin-shell .kq-dashboard-brand {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-width: 86px;
        padding: .5rem .8rem;
        border: 1px solid rgba(212, 175, 110, 0.4);
        border-radius: 999px;
        background: rgba(212, 175, 110, 0.12);
        color: #f2d79f;
        font-size: .7rem;
        font-weight: 800;
        letter-spacing: .2em;
        text-transform: uppercase;
    }
    .kq-client-avatar {
        display: inline-grid;
        place-items: center;
        width: 2.2rem;
        height: 2.2rem;
        margin-right: .6rem;
        border-radius: 50%;
        background: rgba(23, 74, 112, 0.1);
        color: #174a70;
        font-weight: 800;
    }
</style>

<div class="kq-admin-shell">
    <div class="kq-dashboard-hero">
        <span class="kq-dashboard-brand">4 KINGS</span>
        <div>
            <div class="page-eyebrow">Client relationship management</div>
            <div class="text-white fw-semibold mb-0">KingQuiry client directory</div>
        </div>
    </div>

    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-end gap-3 mb-4">
        <div>
            <div class="page-eyebrow">KingQuiry management</div>
            <h1 class="page-title h2 mb-1">Clients</h1>
            <p class="text-muted mb-0">Create and maintain client accounts, contact details, and account status.</p>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <a href="admin_dashboard.php" class="btn btn-outline-primary">Admin dashboard</a>
            <a href="admin_payments.php" class="btn btn-outline-primary">Payments</a>
            <a href="admin_sales.php" class="btn btn-primary">Sales &amp; transactions</a>
        </div>
    </div>

    <?php if ($formSuccess !== ''): ?><div class="alert alert-success" role="status"><?= kq_e($formSuccess) ?></div><?php endif; ?>
    <?php if ($formError !== ''): ?><div class="alert alert-danger" role="alert"><?= kq_e($formError) ?></div><?php endif; ?>

    <div class="row g-3 mb-4">
        <div class="col-6 col-lg-3">
            <div class="kq-stat-card">
                <div class="kq-stat-label">Total clients</div>
                <div class="kq-stat-value"><?= number_format((int) $clientStats['totalClients']) ?></div>
                <div class="kq-stat-sub"><?= number_format((int) $clientStats['newThisMonth']) ?> joined this month.</div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="kq-stat-card">
                <div class="kq-stat-label">Active accounts</div>
                <div class="kq-stat-value"><?= number_format((int) $clientStats['activeClients']) ?></div>
                <div class="kq-stat-sub"><?= number_format((int) $clientStats['inactiveClients']) ?> inactive account(s).</div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="kq-stat-card">
                <div class="kq-stat-label">In active sales</div>
                <div class="kq-stat-value"><?= number_format($activeTrackedClients) ?></div>
                <div class="kq-stat-sub">Clients with a transaction still in progress.</div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="kq-stat-card">
                <div class="kq-stat-label">Verified collections</div>
                <div class="kq-stat-value"><?= kq_format_compact_peso((float) $collectionStats['verifiedTotal']) ?></div>
                <div class="kq-stat-sub"><?= kq_format_compact_peso((float) $collectionStats['pendingTotal']) ?> still pending verification.</div>
            </div>
        </div>
    </div>

    <section class="card p-3 p-lg-4 mb-4" id="client-form">
        <div class="d-flex flex-wrap justify-content-between align-items-end gap-2 mb-3">
            <div>
                <div class="page-eyebrow"><?= $formMode === 'edit' ? 'Edit client' : 'New client' ?></div>
                <h2 class="h4 fw-bold mb-0"><?= $formMode === 'edit' ? 'Update client profile' : 'Create a client account' ?></h2>
            </div>
            <?php if ($formMode === 'edit'): ?>
                <a href="admin_clients.php" class="btn btn-sm btn-outline-secondary">Create a new client instead</a>
            <?php endif; ?>
        </div>

        <form method="POST" class="row g-3">
            <input type="hidden" name="csrf_token" value="<?= kq_e(csrf_token()) ?>">
            <input type="hidden" name="client_action" value="<?= $formMode === 'edit' ? 'update_client' : 'create_client' ?>">
            <input type="hidden" name="userID" value="<?= (int) $clientForm['userID'] ?>">

            <div class="col-md-6">
                <label class="form-label fw-semibold" for="client-fullname">Full name</label>
                <input id="client-fullname" type="text" name="fullname" class="form-control" minlength="2" maxlength="100" required value="<?= kq_e($clientForm['fullname']) ?>" placeholder="e.g. Maria Santos">
            </div>
            <div class="col-md-6">
                <label class="form-label fw-semibold" for="client-email">Email address</label>
                <input id="client-email" type="email" name="email" class="form-control" maxlength="100" required value="<?= kq_e($clientForm['email']) ?>" placeholder="client@example.com">
            </div>
            <div class="col-md-4">
                <label class="form-label fw-semibold" for="client-phone">Phone <span class="text-muted fw-normal">(optional)</span></label>
                <input id="client-phone" type="text" name="phone" class="form-control" maxlength="30" value="<?= kq_e($clientForm['phone']) ?>" placeholder="e.g. 0917 123 4567">
            </div>
            <div class="col-md-4">
                <label class="form-label fw-semibold" for="client-address">Address <span class="text-muted fw-normal">(optional)</span></label>
                <input id="client-address" type="text" name="address" class="form-control" maxlength="255" value="<?= kq_e($clientForm['address']) ?>" placeholder="City / municipality">
            </div>
            <div class="col-md-4">
                <label class="form-label fw-semibold" for="client-status">Account status</label>
                <select id="client-status" name="accountStatus" class="form-select">
                    <option value="Active" <?= $clientForm['accountStatus'] === 'Active' ? 'selected' : '' ?>>Active</option>
                    <option value="Inactive" <?= $clientForm['accountStatus'] === 'Inactive' ? 'selected' : '' ?>>Inactive</option>
                </select>
            </div>
            <?php if ($showPasswordField): ?>
                <div class="col-md-8">
                    <label class="form-label fw-semibold" for="client-password">Temporary password</label>
                    <input id="client-password" type="password" name="password" class="form-control" minlength="8" maxlength="72" required autocomplete="new-password" placeholder="At least 8 characters">
                    <div class="form-text">Share the temporary password securely. The client can sign in through the client portal immediately.</div>
                </div>
            <?php endif; ?>
            <div class="col-12 d-flex flex-wrap gap-2">
                <button type="submit" class="btn btn-primary px-4"><?= $formMode === 'edit' ? 'Save client profile' : 'Create client' ?></button>
                <?php if ($formMode === 'edit'): ?><a href="admin_clients.php" class="btn btn-outline-secondary">Cancel</a><?php endif; ?>
            </div>
        </form>

        <?php if ($formMode === 'edit'): ?>
            <hr class="my-4">
            <form method="POST" class="row g-3 align-items-end">
                <input type="hidden" name="csrf_token" value="<?= kq_e(csrf_token()) ?>">
                <input type="hidden" name="client_action" value="reset_password">
                <input type="hidden" name="userID" value="<?= (int) $clientForm['userID'] ?>">
                <div class="col-md-8">
                    <label class="form-label fw-semibold" for="client-new-password">Reset password</label>
                    <input id="client-new-password" type="password" name="password" class="form-control" minlength="8" maxlength="72" required autocomplete="new-password" placeholder="New password (8-72 characters)">
                </div>
                <div class="col-md-4">
                    <button type="submit" class="btn btn-outline-primary w-100">Reset password</button>
                </div>
            </form>
        <?php endif; ?>
    </section>

    <section class="card p-3 p-lg-4">
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-end gap-3 mb-3">
            <div>
                <div class="page-eyebrow">Client directory</div>
                <h2 class="h4 fw-bold mb-0"><?= number_format(count($clients)) ?> client<?= count($clients) === 1 ? '' : 's' ?> shown</h2>
            </div>
            <form method="GET" class="d-flex flex-column flex-sm-row gap-2 align-items-stretch">
                <label class="visually-hidden" for="client-search">Search clients</label>
                <input id="client-search" type="search" name="q" class="form-control form-control-sm" value="<?= kq_e($search) ?>" placeholder="Search name, email, or phone">
                <label class="visually-hidden" for="client-filter-status">Filter by status</label>
                <select id="client-filter-status" name="status" class="form-select form-select-sm">
                    <option value="">All statuses</option>
                    <option value="Active" <?= $statusFilter === 'Active' ? 'selected' : '' ?>>Active</option>
                    <option value="Inactive" <?= $statusFilter === 'Inactive' ? 'selected' : '' ?>>Inactive</option>
                </select>
                <button type="submit" class="btn btn-sm btn-primary">Filter</button>
                <?php if ($search !== '' || $statusFilter !== ''): ?><a href="admin_clients.php" class="btn btn-sm btn-outline-secondary">Clear</a><?php endif; ?>
            </form>
        </div>

        <div class="table-responsive">
            <table class="table align-middle">
                <thead>
                    <tr>
                        <th>Client</th>
                        <th>Contact</th>
                        <th>Joined</th>
                        <th>Activity</th>
                        <th>Verified payments</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($clients as $client): ?>
                        <tr>
                            <td>
                                <div class="d-flex align-items-start">
                                    <span class="kq-client-avatar" aria-hidden="true"><?= kq_e(strtoupper(substr((string) $client['fullname'], 0, 1))) ?></span>
                                    <div>
                                        <div class="fw-semibold"><?= kq_e($client['fullname']) ?></div>
                                        <div class="small text-muted">Client #<?= (int) $client['userID'] ?></div>
                                    </div>
                                </div>
                            </td>
                            <td class="small">
                                <div><?= kq_e($client['email']) ?></div>
                                <?php if (!empty($client['phone'])): ?><div class="text-muted"><?= kq_e($client['phone']) ?></div><?php endif; ?>
                                <?php if (!empty($client['address'])): ?><div class="text-muted"><?= kq_e($client['address']) ?></div><?php endif; ?>
                            </td>
                            <td class="small text-nowrap"><?= kq_e(date('M j, Y', strtotime((string) $client['createdAt']))) ?></td>
                            <td class="small">
                                <span class="badge text-bg-light"><?= (int) $client['appointmentCount'] ?> viewing<?= (int) $client['appointmentCount'] === 1 ? '' : 's' ?></span>
                                <span class="badge text-bg-light"><?= (int) $client['inquiryCount'] ?> inquiry<?= (int) $client['inquiryCount'] === 1 ? '' : 'ies' ?></span>
                                <span class="badge text-bg-light"><?= (int) $client['transactionCount'] ?> transaction<?= (int) $client['transactionCount'] === 1 ? '' : 's' ?></span>
                                <?php if ((int) $client['activeTransactionCount'] > 0): ?>
                                    <div class="text-success fw-semibold mt-1"><?= (int) $client['activeTransactionCount'] ?> active deal<?= (int) $client['activeTransactionCount'] === 1 ? '' : 's' ?></div>
                                <?php endif; ?>
                                <?php if (!empty($client['lastActivity'])): ?>
                                    <div class="text-muted mt-1">Last activity <?= kq_e(date('M j, Y', strtotime((string) $client['lastActivity']))) ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="small">
                                <div class="fw-semibold"><?= kq_format_peso((float) $client['verifiedPaymentTotal']) ?></div>
                                <div class="text-muted"><?= (int) $client['paymentCount'] ?> payment record<?= (int) $client['paymentCount'] === 1 ? '' : 's' ?></div>
                            </td>
                            <td>
                                <span class="badge <?= $client['accountStatus'] === 'Active' ? 'text-bg-success' : 'text-bg-secondary' ?>"><?= kq_e($client['accountStatus']) ?></span>
                            </td>
                            <td>
                                <div class="d-flex flex-wrap gap-2">
                                    <a href="admin_clients.php?edit=<?= (int) $client['userID'] ?>#client-form" class="btn btn-sm btn-outline-primary">Edit</a>
                                    <form method="POST" class="d-inline">
                                        <input type="hidden" name="csrf_token" value="<?= kq_e(csrf_token()) ?>">
                                        <input type="hidden" name="client_action" value="set_client_status">
                                        <input type="hidden" name="userID" value="<?= (int) $client['userID'] ?>">
                                        <input type="hidden" name="accountStatus" value="<?= $client['accountStatus'] === 'Active' ? 'Inactive' : 'Active' ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-secondary"><?= $client['accountStatus'] === 'Active' ? 'Deactivate' : 'Reactivate' ?></button>
                                    </form>
                                    <form method="POST" class="d-inline" onsubmit="return confirm('Remove this client account? Accounts with appointments, inquiries, transactions, or payments cannot be deleted; deactivate them instead.');">
                                        <input type="hidden" name="csrf_token" value="<?= kq_e(csrf_token()) ?>">
                                        <input type="hidden" name="client_action" value="delete_client">
                                        <input type="hidden" name="userID" value="<?= (int) $client['userID'] ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger">Remove</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if ($clients === []): ?>
                        <tr><td colspan="7" class="text-center text-muted py-4">No clients match this view. Create a client above or clear the filters.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</div>

<?php include 'includes/footer.php'; ?>
