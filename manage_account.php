<?php
include 'config/db.php';
require_once 'includes/auth.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$currentUser = $_SESSION['user'] ?? null;
if (!$currentUser || empty($currentUser['userID'])) {
    header('Location: client_login.php');
    exit();
}

$currentRole = normalized_user_role($currentUser);
$userID = (int) $currentUser['userID'];

// Fetch latest user details from DB
$stmt = $conn->prepare('SELECT userID, fullname, email, phone, address, role, accountStatus, createdAt FROM users WHERE userID = ? LIMIT 1');
$stmt->bind_param('i', $userID);
$stmt->execute();
$dbUser = $stmt->get_result()->fetch_assoc();
if ($dbUser) {
    $currentUser = array_merge($currentUser, $dbUser);
    $_SESSION['user'] = $currentUser;
}

$flashSuccess = $_SESSION['flash_success'] ?? '';
$flashError = $_SESSION['flash_error'] ?? '';
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

include 'includes/header.php';
?>

<div class="container py-4 py-lg-5" style="max-width: 780px;">
    <div class="mb-4">
        <a href="javascript:history.back()" class="text-decoration-none small text-muted d-inline-flex align-items-center gap-1 mb-2">
            &larr; Back
        </a>
        <h1 class="h3 fw-bold mb-1">Manage Account</h1>
        <p class="text-muted mb-0">Review your profile details and update your password.</p>
    </div>

    <?php if ($flashSuccess !== ''): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <strong>Success:</strong> <?= htmlspecialchars($flashSuccess, ENT_QUOTES, 'UTF-8') ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <?php if ($flashError !== ''): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <strong>Error:</strong> <?= htmlspecialchars($flashError, ENT_QUOTES, 'UTF-8') ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <!-- Account Details Card -->
    <div class="card p-4 mb-4 border shadow-sm rounded-4">
        <h2 class="h5 fw-bold mb-3 d-flex align-items-center gap-2">
            <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
            <span>Profile Details</span>
        </h2>
        <div class="row g-3">
            <div class="col-sm-6">
                <div class="text-muted small fw-semibold">Full Name</div>
                <div class="fw-bold text-dark"><?= htmlspecialchars((string) ($currentUser['fullname'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
            </div>
            <div class="col-sm-6">
                <div class="text-muted small fw-semibold">Email Address</div>
                <div class="text-dark"><?= htmlspecialchars((string) ($currentUser['email'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
            </div>
            <?php if ($currentRole === 'Admin' || $currentRole === 'Sales Agent'): ?>
                <div class="col-sm-6">
                    <div class="text-muted small fw-semibold">Role</div>
                    <div><span class="badge bg-secondary"><?= htmlspecialchars((string) $currentRole, ENT_QUOTES, 'UTF-8') ?></span></div>
                </div>
            <?php endif; ?>
            <div class="col-sm-6">
                <div class="text-muted small fw-semibold">Member Since</div>
                <div class="text-dark"><?= !empty($currentUser['createdAt']) ? date('M j, Y', strtotime((string) $currentUser['createdAt'])) : 'N/A' ?></div>
            </div>
        </div>
    </div>

    <!-- Change Password Card -->
    <div class="card p-4 border shadow-sm rounded-4">
        <h2 class="h5 fw-bold mb-2 d-flex align-items-center gap-2">
            <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
            <span>Change Password</span>
        </h2>
        <p class="text-muted small mb-4">Choose a strong password containing at least 8 characters.</p>

        <form method="POST" action="change_password.php">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">

            <div class="mb-3">
                <label class="form-label fw-semibold" for="page-current-password">Current Password</label>
                <div class="password-field">
                    <input type="password" id="page-current-password" name="current_password" class="form-control" required placeholder="Enter your current password">
                    <button type="button" class="password-toggle" data-toggle-password="page-current-password">Show</button>
                </div>
            </div>

            <div class="mb-3">
                <label class="form-label fw-semibold" for="page-new-password">New Password</label>
                <div class="password-field">
                    <input type="password" id="page-new-password" name="new_password" class="form-control" minlength="8" maxlength="72" required placeholder="Enter new password">
                    <button type="button" class="password-toggle" data-toggle-password="page-new-password">Show</button>
                </div>
                <div class="form-text">Must be at least 8 characters long.</div>
            </div>

            <div class="mb-4">
                <label class="form-label fw-semibold" for="page-confirm-password">Confirm New Password</label>
                <div class="password-field">
                    <input type="password" id="page-confirm-password" name="confirm_password" class="form-control" minlength="8" maxlength="72" required placeholder="Re-enter new password">
                    <button type="button" class="password-toggle" data-toggle-password="page-confirm-password">Show</button>
                </div>
            </div>

            <div class="d-flex justify-content-between align-items-center">
                <button type="button" class="btn btn-outline-danger" data-bs-toggle="modal" data-bs-target="#logoutConfirmModal">
                    Log Out
                </button>
                <button type="submit" class="btn btn-primary px-4 fw-semibold">
                    Save New Password
                </button>
            </div>
        </form>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
