<!-- ═══════════════════ LOGOUT CONFIRMATION MODAL ═══════════════════ -->
<div class="modal fade" id="logoutConfirmModal" tabindex="-1" aria-labelledby="logoutConfirmModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content shadow-lg border-0 rounded-4 text-center p-2">
            <div class="modal-body p-3">
                <div class="d-inline-flex align-items-center justify-content-center rounded-circle bg-danger-subtle text-danger mb-3" style="width: 54px; height: 54px;">
                    <svg width="26" height="26" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
                </div>
                <h5 class="fw-bold mb-2 text-dark" id="logoutConfirmModalLabel">Log Out?</h5>
                <p class="text-muted small mb-4">Are you sure you want to log out of your account?</p>
                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-outline-secondary w-50 fw-semibold" data-bs-dismiss="modal">Cancel</button>
                    <form method="POST" action="logout.php" class="w-50 m-0">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
                        <button type="submit" class="btn btn-danger w-100 fw-semibold">Log Out</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ═══════════════════ MANAGE ACCOUNT MODAL ═══════════════════ -->
<div class="modal fade" id="manageAccountModal" tabindex="-1" aria-labelledby="manageAccountModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content shadow-lg border-0 rounded-4">
            <div class="modal-header border-0 pb-0">
                <div class="d-flex align-items-center gap-2">
                    <div class="d-inline-flex align-items-center justify-content-center rounded-circle bg-primary-subtle text-primary" style="width: 40px; height: 40px;">
                        <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                    </div>
                    <div>
                        <h5 class="fw-bold mb-0 text-dark" id="manageAccountModalLabel">Manage Account</h5>
                        <div class="small text-muted">Review your profile and update your password</div>
                    </div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body px-4 pt-3 pb-4">
                <!-- User Profile Summary Card -->
                <div class="card p-3 mb-3 border shadow-none bg-light rounded-3">
                    <div class="d-flex align-items-center justify-content-between gap-2 mb-2">
                        <span class="small text-muted fw-semibold">Account Profile</span>
                        <?php if (!empty($currentRole)): ?>
                            <span class="badge bg-secondary-subtle text-secondary border" style="font-size: 0.72rem;"><?= htmlspecialchars($currentRole, ENT_QUOTES, 'UTF-8') ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="fw-bold text-dark fs-6"><?= htmlspecialchars((string) ($currentUser['fullname'] ?? $displayName ?? 'User'), ENT_QUOTES, 'UTF-8') ?></div>
                    <?php if (!empty($currentUser['email'])): ?>
                        <div class="text-muted small"><?= htmlspecialchars((string) $currentUser['email'], ENT_QUOTES, 'UTF-8') ?></div>
                    <?php endif; ?>
                    <?php if (!empty($currentUser['phone'])): ?>
                        <div class="text-muted small mt-1"><?= htmlspecialchars((string) $currentUser['phone'], ENT_QUOTES, 'UTF-8') ?></div>
                    <?php endif; ?>
                </div>

                <!-- Alert Feedback Box -->
                <div id="manageAccountAlert" class="alert d-none mb-3" role="alert"></div>

                <!-- Change Password Form -->
                <form id="modalChangePasswordForm" method="POST" action="change_password.php">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
                    
                    <div class="mb-3">
                        <label class="form-label small fw-semibold" for="modal-current-password">Current Password</label>
                        <div class="password-field">
                            <input type="password" id="modal-current-password" name="current_password" class="form-control" required placeholder="Enter current password">
                            <button type="button" class="password-toggle" data-toggle-password="modal-current-password">Show</button>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-semibold" for="modal-new-password">New Password</label>
                        <div class="password-field">
                            <input type="password" id="modal-new-password" name="new_password" class="form-control" minlength="8" maxlength="72" required placeholder="At least 8 characters">
                            <button type="button" class="password-toggle" data-toggle-password="modal-new-password">Show</button>
                        </div>
                        <div class="form-text small">Minimum of 8 characters.</div>
                    </div>

                    <div class="mb-4">
                        <label class="form-label small fw-semibold" for="modal-confirm-password">Confirm New Password</label>
                        <div class="password-field">
                            <input type="password" id="modal-confirm-password" name="confirm_password" class="form-control" minlength="8" maxlength="72" required placeholder="Re-enter new password">
                            <button type="button" class="password-toggle" data-toggle-password="modal-confirm-password">Show</button>
                        </div>
                    </div>

                    <div class="d-flex justify-content-between align-items-center">
                        <a href="manage_account.php" class="small text-decoration-none fw-semibold">
                            Full account settings &rarr;
                        </a>
                        <div class="d-flex gap-2">
                            <button type="button" class="btn btn-outline-secondary btn-sm px-3" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" id="modalSubmitPasswordBtn" class="btn btn-primary btn-sm px-4 fw-semibold">Save Password</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
