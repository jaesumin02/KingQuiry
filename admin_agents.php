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
    'agent_added' => 'The Sales Agent account has been created.',
    'agent_updated' => 'The Sales Agent account has been updated.',
    'password_reset' => 'The Sales Agent password has been reset.',
    'status_updated' => 'The Sales Agent account status has been updated.',
];
foreach ($flashMessages as $flashKey => $flashMessage) {
    if (isset($_GET[$flashKey])) {
        $formSuccess = $flashMessage;
        break;
    }
}

$agentForm = [
    'userID' => 0,
    'fullname' => '',
    'email' => '',
    'phone' => '',
    'address' => '',
    'accountStatus' => 'Active',
];
$formMode = 'create';
$action = is_string($_POST['agent_action'] ?? null) ? $_POST['agent_action'] : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action !== '') {
    $agentId = filter_var($_POST['userID'] ?? null, FILTER_VALIDATE_INT);
    if (!has_valid_csrf_token()) {
        $formError = 'Your session expired. Refresh the page and try again.';
    } else {
        try {
            if ($action === 'create_agent' || $action === 'update_agent') {
                $fullname = is_string($_POST['fullname'] ?? null) ? trim($_POST['fullname']) : '';
                $email = is_string($_POST['email'] ?? null) ? strtolower(trim($_POST['email'])) : '';
                $phone = is_string($_POST['phone'] ?? null) ? trim($_POST['phone']) : '';
                $address = is_string($_POST['address'] ?? null) ? trim($_POST['address']) : '';
                $accountStatus = is_string($_POST['accountStatus'] ?? null) ? $_POST['accountStatus'] : '';
                $agentForm = [
                    'userID' => $action === 'update_agent' && $agentId !== false ? (int) $agentId : 0,
                    'fullname' => $fullname,
                    'email' => $email,
                    'phone' => $phone,
                    'address' => $address,
                    'accountStatus' => $accountStatus,
                ];
                $formMode = $action === 'update_agent' ? 'edit' : 'create';

                if (strlen($fullname) < 2 || strlen($fullname) > 100 || !preg_match("/^[\p{L}\p{M} .'-]+$/u", $fullname)) {
                    throw new InvalidArgumentException('Enter a valid Sales Agent name using letters and standard punctuation.');
                }
                if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 100) {
                    throw new InvalidArgumentException('Enter a valid Sales Agent email address.');
                }
                if ($phone !== '' && (strlen($phone) > 30 || !preg_match('/^[0-9+()\-.\s]{6,30}$/', $phone))) {
                    throw new InvalidArgumentException('Enter a valid phone number or leave the field empty.');
                }
                if (strlen($address) > 255 || !in_array($accountStatus, ['Active', 'Inactive'], true)) {
                    throw new InvalidArgumentException('Enter a valid address and account status.');
                }

                if ($action === 'create_agent') {
                    $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
                    $confirmPassword = is_string($_POST['confirm_password'] ?? null) ? $_POST['confirm_password'] : '';
                    if (strlen($password) < 8 || strlen($password) > 72) {
                        throw new InvalidArgumentException('Use a temporary password between 8 and 72 characters.');
                    }
                    if ($password !== $confirmPassword) {
                        throw new InvalidArgumentException('Passwords do not match. Please re-enter.');
                    }
                    $passwordHash = password_hash($password, PASSWORD_DEFAULT);
                    $role = 'Agent';
                    $stmt = $conn->prepare(
                        'INSERT INTO users (fullname, email, phone, address, passwordHash, role, accountStatus)
                         VALUES (?, ?, ?, ?, ?, ?, ?)'
                    );
                    $stmt->bind_param('sssssss', $fullname, $email, $phone, $address, $passwordHash, $role, $accountStatus);
                    $stmt->execute();
                    kq_log_admin_action($conn, $actorId, 'Created Sales Agent account for ' . $fullname . ' (' . $email . ').');
                    header('Location: admin_agents.php?agent_added=1');
                    exit();
                }

                if ($agentId === false || $agentId <= 0) {
                    throw new InvalidArgumentException('Choose a valid Sales Agent to update.');
                }
                $stmt = $conn->prepare(
                    "UPDATE users
                     SET phoneVerifiedAt = IF(phone <=> NULLIF(?, ''), phoneVerifiedAt, NULL),
                         fullname = ?, email = ?, phone = ?, address = ?, accountStatus = ?
                     WHERE userID = ? AND role IN ('Agent', 'Sales Agent')"
                );
                $stmt->bind_param('ssssssi', $phone, $fullname, $email, $phone, $address, $accountStatus, $agentId);
                $stmt->execute();
                if ($stmt->affected_rows === 0) {
                    $checkStmt = $conn->prepare(
                        "SELECT userID FROM users WHERE userID = ? AND role IN ('Agent', 'Sales Agent') LIMIT 1"
                    );
                    $checkStmt->bind_param('i', $agentId);
                    $checkStmt->execute();
                    if (!$checkStmt->get_result()->fetch_assoc()) {
                        throw new InvalidArgumentException('The selected Sales Agent was not found.');
                    }
                }
                kq_log_admin_action($conn, $actorId, 'Updated Sales Agent account #' . $agentId . '.');
                header('Location: admin_agents.php?agent_updated=1');
                exit();
            }

            if ($agentId === false || $agentId <= 0) {
                throw new InvalidArgumentException('Choose a valid Sales Agent account.');
            }

            if ($action === 'reset_agent_password') {
                $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
                if (strlen($password) < 8 || strlen($password) > 72) {
                    throw new InvalidArgumentException('Use a new password between 8 and 72 characters.');
                }
                $passwordHash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $conn->prepare(
                    "UPDATE users SET passwordHash = ? WHERE userID = ? AND role IN ('Agent', 'Sales Agent')"
                );
                $stmt->bind_param('si', $passwordHash, $agentId);
                $stmt->execute();
                if ($stmt->affected_rows === 0) {
                    throw new InvalidArgumentException('The selected Sales Agent was not found or the password was unchanged.');
                }
                kq_log_admin_action($conn, $actorId, 'Reset Sales Agent password for user #' . $agentId . '.');
                header('Location: admin_agents.php?password_reset=1');
                exit();
            }

            if ($action === 'set_agent_status') {
                $accountStatus = is_string($_POST['accountStatus'] ?? null) ? $_POST['accountStatus'] : '';
                if (!in_array($accountStatus, ['Active', 'Inactive'], true)) {
                    throw new InvalidArgumentException('Choose a valid account status.');
                }
                $stmt = $conn->prepare(
                    "UPDATE users SET accountStatus = ? WHERE userID = ? AND role IN ('Agent', 'Sales Agent')"
                );
                $stmt->bind_param('si', $accountStatus, $agentId);
                $stmt->execute();
                if ($stmt->affected_rows === 0) {
                    $checkStmt = $conn->prepare(
                        "SELECT userID FROM users WHERE userID = ? AND role IN ('Agent', 'Sales Agent') LIMIT 1"
                    );
                    $checkStmt->bind_param('i', $agentId);
                    $checkStmt->execute();
                    if (!$checkStmt->get_result()->fetch_assoc()) {
                        throw new InvalidArgumentException('The selected Sales Agent was not found.');
                    }
                }
                kq_log_admin_action($conn, $actorId, 'Set Sales Agent #' . $agentId . ' account status to ' . $accountStatus . '.');
                header('Location: admin_agents.php?status_updated=1');
                exit();
            }

            throw new InvalidArgumentException('Choose a valid Sales Agent action.');
        } catch (InvalidArgumentException $exception) {
            $formError = $exception->getMessage();
        } catch (mysqli_sql_exception $exception) {
            if ($exception->getCode() === 1062) {
                $formError = 'That email address is already used by another account.';
            } else {
                error_log('KingQuiry Sales Agent management failed: ' . $exception->getMessage());
                $formError = 'The Sales Agent record could not be saved. Confirm MySQL is running and try again.';
            }
        } catch (Throwable $exception) {
            error_log('KingQuiry Sales Agent management failed: ' . $exception->getMessage());
            $formError = 'The Sales Agent record could not be saved. Please try again.';
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || $formError === '') {
    $editId = filter_var($_GET['edit'] ?? null, FILTER_VALIDATE_INT);
    if ($editId !== false && $editId !== null && $editId > 0) {
        $stmt = $conn->prepare(
            "SELECT userID, fullname, email, phone, address, accountStatus
             FROM users
             WHERE userID = ? AND role IN ('Agent', 'Sales Agent')
             LIMIT 1"
        );
        $stmt->bind_param('i', $editId);
        $stmt->execute();
        $editingAgent = $stmt->get_result()->fetch_assoc();
        if ($editingAgent) {
            $agentForm = [
                'userID' => (int) $editingAgent['userID'],
                'fullname' => (string) $editingAgent['fullname'],
                'email' => (string) $editingAgent['email'],
                'phone' => (string) ($editingAgent['phone'] ?? ''),
                'address' => (string) ($editingAgent['address'] ?? ''),
                'accountStatus' => (string) ($editingAgent['accountStatus'] ?? 'Active'),
            ];
            $formMode = 'edit';
        } elseif ($formError === '') {
            $formError = 'The selected Sales Agent was not found.';
        }
    }
}

$search = is_string($_GET['q'] ?? null) ? trim($_GET['q']) : '';
$statusFilter = is_string($_GET['status'] ?? null) ? $_GET['status'] : '';
if (!in_array($statusFilter, ['Active', 'Inactive'], true)) {
    $statusFilter = '';
}

$where = ["u.role IN ('Agent', 'Sales Agent')"];
$bindTypes = '';
$bindValues = [];
if ($search !== '') {
    $where[] = '(u.fullname LIKE ? OR u.email LIKE ? OR u.phone LIKE ?)';
    $like = '%' . $search . '%';
    $bindTypes = 'sss';
    array_push($bindValues, $like, $like, $like);
}
if ($statusFilter !== '') {
    $where[] = 'u.accountStatus = ?';
    $bindTypes .= 's';
    $bindValues[] = $statusFilter;
}

$agentsStmt = $conn->prepare(
    "SELECT u.userID, u.fullname, u.email, u.phone, u.address, u.accountStatus, u.createdAt,
            (SELECT COUNT(*) FROM appointments a WHERE a.agentID = u.userID) AS appointmentCount,
            (SELECT COUNT(*) FROM agent_inquiry_assignments ia WHERE ia.agentID = u.userID) AS inquiryCount,
            (SELECT COUNT(*) FROM property_transactions t
             LEFT JOIN appointments a ON a.appointmentID = t.appointmentID
             LEFT JOIN agent_inquiry_assignments ia ON ia.inquiryID = t.inquiryID
             WHERE COALESCE(t.assignedAgentID, a.agentID, ia.agentID) = u.userID
               AND t.currentStage NOT IN ('completed', 'cancelled')) AS activeTransactionCount
     FROM users u
     WHERE " . implode(' AND ', $where) . "
     ORDER BY u.createdAt DESC, u.userID DESC
     LIMIT 500"
);
if ($bindTypes !== '') {
    kq_bind_dynamic($agentsStmt, $bindTypes, $bindValues);
}
$agentsStmt->execute();
$agents = $agentsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$agentStats = $conn->query(
    "SELECT COUNT(*) AS totalAgents,
            COALESCE(SUM(accountStatus = 'Active'), 0) AS activeAgents,
            COALESCE(SUM(accountStatus = 'Inactive'), 0) AS inactiveAgents
     FROM users
     WHERE role IN ('Agent', 'Sales Agent')"
)->fetch_assoc();

include 'includes/header.php';
?>

<?php if ($formSuccess !== ''): ?><div class="alert alert-success" role="status"><?= kq_e($formSuccess) ?></div><?php endif; ?>
<?php if ($formError !== ''): ?><div class="alert alert-danger" role="alert"><?= kq_e($formError) ?></div><?php endif; ?>

<section class="card p-3 p-lg-4 mb-4" id="agent-form">
    <div class="d-flex flex-wrap justify-content-between align-items-end gap-2 mb-3">
        <div>
            <div class="page-eyebrow"><?= $formMode === 'edit' ? 'Edit Sales Agent account' : 'New Sales Agent account' ?></div>
            <h2 class="h4 fw-bold mb-0"><?= $formMode === 'edit' ? 'Edit Sales Agent account' : 'Create a Sales Agent account' ?></h2>
        </div>
        <?php if ($formMode === 'edit'): ?><a href="admin_agents.php" class="btn btn-sm btn-outline-secondary">Create a new account instead</a><?php endif; ?>
    </div>
    <form method="POST" class="row g-3" data-confirm="<?= $formMode === 'edit' ? 'Save changes to this Sales Agent account?' : 'Create this Sales Agent account?' ?>">
        <input type="hidden" name="csrf_token" value="<?= kq_e(csrf_token()) ?>">
        <input type="hidden" name="agent_action" value="<?= $formMode === 'edit' ? 'update_agent' : 'create_agent' ?>">
        <input type="hidden" name="userID" value="<?= (int) $agentForm['userID'] ?>">
        <div class="col-md-6">
            <label class="form-label fw-semibold" for="agent-fullname">Full name</label>
            <input id="agent-fullname" type="text" name="fullname" class="form-control" minlength="2" maxlength="100" required value="<?= kq_e($agentForm['fullname']) ?>">
        </div>
        <div class="col-md-6">
            <label class="form-label fw-semibold" for="agent-email">Email address</label>
            <input id="agent-email" type="email" name="email" class="form-control" maxlength="100" required value="<?= kq_e($agentForm['email']) ?>">
        </div>
        <div class="col-md-4">
            <label class="form-label fw-semibold" for="agent-phone">Phone <span class="text-muted fw-normal">(optional)</span></label>
            <input id="agent-phone" type="text" name="phone" class="form-control" maxlength="30" value="<?= kq_e($agentForm['phone']) ?>">
        </div>
        <div class="col-md-4">
            <label class="form-label fw-semibold" for="agent-address">Address <span class="text-muted fw-normal">(optional)</span></label>
            <input id="agent-address" type="text" name="address" class="form-control" maxlength="255" value="<?= kq_e($agentForm['address']) ?>">
        </div>
        <div class="col-md-4">
            <label class="form-label fw-semibold" for="agent-status">Account status</label>
            <select id="agent-status" name="accountStatus" class="form-select">
                <option value="Active" <?= $agentForm['accountStatus'] === 'Active' ? 'selected' : '' ?>>Active</option>
                <option value="Inactive" <?= $agentForm['accountStatus'] === 'Inactive' ? 'selected' : '' ?>>Inactive</option>
            </select>
        </div>
        <?php if ($formMode === 'create'): ?>
            <div class="col-md-6">
                <label class="form-label fw-semibold" for="agent-password">Temporary password</label>
                <input id="agent-password" type="password" name="password" class="form-control" minlength="8" maxlength="72" required autocomplete="new-password">
            </div>
            <div class="col-md-6">
                <label class="form-label fw-semibold" for="agent-confirm-password">Confirm password</label>
                <input id="agent-confirm-password" type="password" name="confirm_password" class="form-control" minlength="8" maxlength="72" required autocomplete="new-password">
            </div>
        <?php endif; ?>
        <div class="col-12 d-flex flex-wrap gap-2">
            <button type="submit" class="btn btn-primary px-4"><?= $formMode === 'edit' ? 'Save changes' : 'Create Sales Agent account' ?></button>
            <?php if ($formMode === 'edit'): ?><a href="admin_agents.php" class="btn btn-outline-secondary">Cancel</a><?php endif; ?>
        </div>
    </form>
    <?php if ($formMode === 'edit'): ?>
        <hr class="my-4">
        <form method="POST" class="row g-3 align-items-end" data-confirm="Reset this Sales Agent’s password now?">
            <input type="hidden" name="csrf_token" value="<?= kq_e(csrf_token()) ?>">
            <input type="hidden" name="agent_action" value="reset_agent_password">
            <input type="hidden" name="userID" value="<?= (int) $agentForm['userID'] ?>">
            <div class="col-md-8">
                <label class="form-label fw-semibold" for="agent-new-password">Reset password</label>
                <input id="agent-new-password" type="password" name="password" class="form-control" minlength="8" maxlength="72" required autocomplete="new-password">
            </div>
            <div class="col-md-4"><button type="submit" class="btn btn-outline-primary w-100">Reset password</button></div>
        </form>
    <?php endif; ?>
</section>

<section class="card p-3 p-lg-4">
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-end gap-3 mb-3">
        <div>
            <div class="page-eyebrow">Sales Agent directory</div>
            <h2 class="h4 fw-bold mb-0"><?= number_format(count($agents)) ?> Sales Agent<?= count($agents) === 1 ? '' : 's' ?> shown</h2>
            <p class="small text-muted mb-0"><?= (int) $agentStats['activeAgents'] ?> active · <?= (int) $agentStats['inactiveAgents'] ?> inactive · <?= (int) $agentStats['totalAgents'] ?> total</p>
        </div>
        <form method="GET" action="admin_agents.php#agent-results" class="d-flex flex-column flex-sm-row gap-2 align-items-stretch">
            <label class="visually-hidden" for="agent-search">Search Sales Agents</label>
            <input id="agent-search" type="search" name="q" class="form-control form-control-sm" value="<?= kq_e($search) ?>" placeholder="Search name, email, or phone">
            <label class="visually-hidden" for="agent-filter-status">Filter by status</label>
            <select id="agent-filter-status" name="status" class="form-select form-select-sm">
                <option value="">All statuses (<?= (int) $agentStats['totalAgents'] ?>)</option>
                <option value="Active" <?= $statusFilter === 'Active' ? 'selected' : '' ?>>Active (<?= (int) $agentStats['activeAgents'] ?>)</option>
                <option value="Inactive" <?= $statusFilter === 'Inactive' ? 'selected' : '' ?>>Inactive (<?= (int) $agentStats['inactiveAgents'] ?>)</option>
            </select>
            <button type="submit" class="btn btn-sm btn-primary">Apply</button>
            <?php if ($search !== '' || $statusFilter !== ''): ?><a href="admin_agents.php#agent-results" class="btn btn-sm btn-outline-secondary">Clear</a><?php endif; ?>
        </form>
    </div>
    <div class="table-responsive" id="agent-results">
        <table class="table align-middle">
            <thead><tr><th>Sales Agent</th><th>Contact</th><th>Assigned work</th><th>Joined</th><th>Status</th><th>Actions</th></tr></thead>
            <tbody>
            <?php foreach ($agents as $agent): ?>
                <tr>
                    <td><div class="fw-semibold"><?= kq_e($agent['fullname']) ?></div><div class="small text-muted">Agent #<?= (int) $agent['userID'] ?></div></td>
                    <td class="small"><div><?= kq_e($agent['email']) ?></div><?php if (!empty($agent['phone'])): ?><div class="text-muted"><?= kq_e($agent['phone']) ?></div><?php endif; ?></td>
                    <td class="small"><?= (int) $agent['appointmentCount'] ?> viewings · <?= (int) $agent['inquiryCount'] ?> inquiries · <?= (int) $agent['activeTransactionCount'] ?> active cases</td>
                    <td class="small text-nowrap"><?= kq_e(date('M j, Y', strtotime((string) $agent['createdAt']))) ?></td>
                    <td><span class="badge <?= $agent['accountStatus'] === 'Active' ? 'text-bg-success' : 'text-bg-secondary' ?>"><?= kq_e($agent['accountStatus']) ?></span></td>
                    <td>
                        <div class="d-flex flex-wrap gap-2">
                            <a href="admin_agents.php?edit=<?= (int) $agent['userID'] ?>#agent-form" class="btn btn-sm btn-outline-primary">Edit</a>
                            <form method="POST" class="d-inline" data-confirm="Change this Sales Agent’s account status? Existing assignments will remain in the records.">
                                <input type="hidden" name="csrf_token" value="<?= kq_e(csrf_token()) ?>">
                                <input type="hidden" name="agent_action" value="set_agent_status">
                                <input type="hidden" name="userID" value="<?= (int) $agent['userID'] ?>">
                                <input type="hidden" name="accountStatus" value="<?= $agent['accountStatus'] === 'Active' ? 'Inactive' : 'Active' ?>">
                                <button type="submit" class="btn btn-sm btn-outline-secondary"><?= $agent['accountStatus'] === 'Active' ? 'Deactivate' : 'Reactivate' ?></button>
                            </form>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if ($agents === []): ?><tr><td colspan="6" class="text-center text-muted py-4">No Sales Agents match this view. Create an account above or clear the filters.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<?php include 'includes/footer.php'; ?>
