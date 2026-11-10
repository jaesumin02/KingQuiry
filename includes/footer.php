<?php
$chatWidgetPages = ['index.php', 'property_detail.php', 'client_dashboard.php'];
$currentPage = basename($_SERVER['SCRIPT_NAME'] ?? '');
$currentRole = normalized_user_role($_SESSION['user'] ?? null);
if (in_array($currentPage, $chatWidgetPages, true) && ($currentRole === null || $currentRole === 'Client')) {
    include __DIR__ . '/chat_widget.php';
}
?>
    </main>
    <footer class="app-footer">
        <div class="container d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
            <div>
                <div class="fw-bold text-white">KingQuiry</div>
                <div class="small" style="color: rgba(255,255,255,.72);">Property discovery and client services by Kings Land Properties &bull; Nasugbu, Batangas.</div>
            </div>
            <div class="d-flex align-items-center gap-3 small flex-wrap">
                <?php if ($currentRole === null || $currentRole === 'Client'): ?>
                    <button type="button" class="btn btn-link text-white-50 p-0 text-decoration-none hover-white" data-bs-toggle="modal" data-bs-target="#contactUsModal">
                        Contact Us
                    </button>
                    <a href="https://www.facebook.com/p/Kings-Land-Properties-61568411772451/" target="_blank" rel="noopener noreferrer" class="text-white-50 text-decoration-none hover-white d-inline-flex align-items-center gap-1">
                        <span>Facebook Page</span>
                        <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
                    </a>
                <?php endif; ?>
                <span class="text-white-50">&copy; <?= date('Y') ?> Kings Land Properties. All rights reserved.</span>
            </div>
        </div>
    </footer>

    <?php if ($currentRole === null || $currentRole === 'Client'): ?>
    <!-- ═══════════════════ CONTACT US MODAL ═══════════════════ -->
    <div class="modal fade" id="contactUsModal" tabindex="-1" aria-labelledby="contactUsModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content shadow-lg border-0 rounded-4 overflow-hidden">
                <!-- Modal Header -->
                <div class="modal-header border-0 text-white" style="background: linear-gradient(135deg, #142d4e 0%, #174a70 100%); padding: 1.35rem 1.5rem;">
                    <div class="d-flex align-items-center gap-3">
                        <div class="d-inline-flex align-items-center justify-content-center rounded-circle flex-shrink-0" style="width: 44px; height: 44px; background: rgba(216, 173, 99, 0.18); border: 1.5px solid rgba(216, 173, 99, 0.6); color: #d8ad63;">
                            <svg width="22" height="22" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
                        </div>
                        <div>
                            <h5 class="modal-title fw-bold text-white mb-0" id="contactUsModalLabel">Contact Us</h5>
                            <div class="small" style="color: rgba(255, 255, 255, 0.85);">Kings Land Properties &bull; Nasugbu, Batangas</div>
                        </div>
                    </div>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>

                <!-- Modal Body -->
                <div class="modal-body p-3 p-md-4" style="background: #f8fafc;">
                    <!-- Facebook Page Card -->
                    <div class="card border-0 shadow-sm mb-3 rounded-3 overflow-hidden" style="border-left: 4px solid #1877f2 !important; background: #ffffff;">
                        <div class="card-body p-3">
                            <div class="d-flex align-items-start gap-3">
                                <div class="flex-shrink-0">
                                    <span class="d-inline-flex align-items-center justify-content-center rounded-circle" style="width: 44px; height: 44px; background: #e7f3ff; color: #1877f2;">
                                        <svg width="24" height="24" fill="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                            <path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/>
                                        </svg>
                                    </span>
                                </div>
                                <div class="flex-grow-1 min-w-0">
                                    <div class="text-uppercase fw-bold text-muted" style="font-size: 0.72rem; letter-spacing: 0.08em;">Official Facebook Page</div>
                                    <h6 class="fw-bold mb-1">
                                        <a href="https://www.facebook.com/p/Kings-Land-Properties-61568411772451/" target="_blank" rel="noopener noreferrer" class="text-decoration-none text-dark" style="transition: color 0.15s ease;">
                                            Kings Land Properties | Nasugbu | Facebook
                                        </a>
                                    </h6>
                                    <p class="text-muted small mb-2">Connect with us on Facebook for inquiries, new property listings, and updates in Nasugbu, Batangas.</p>
                                    <a href="https://www.facebook.com/p/Kings-Land-Properties-61568411772451/" target="_blank" rel="noopener noreferrer" class="btn btn-sm text-white fw-semibold d-inline-flex align-items-center gap-1.5" style="background: #1877f2; border-radius: 0.5rem; padding: 0.38rem 0.85rem;">
                                        <span>Visit Facebook Page</span>
                                        <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Contact Number Card -->
                    <div class="card border-0 shadow-sm mb-3 rounded-3 overflow-hidden" style="border-left: 4px solid #198754 !important; background: #ffffff;">
                        <div class="card-body p-3">
                            <div class="d-flex align-items-start gap-3">
                                <div class="flex-shrink-0">
                                    <span class="d-inline-flex align-items-center justify-content-center rounded-circle" style="width: 44px; height: 44px; background: #e8f5e9; color: #198754;">
                                        <svg width="22" height="22" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24">
                                            <path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/>
                                        </svg>
                                    </span>
                                </div>
                                <div class="flex-grow-1 min-w-0">
                                    <div class="text-uppercase fw-bold text-muted" style="font-size: 0.72rem; letter-spacing: 0.08em;">Contact &amp; Mobile Number</div>
                                    <div class="h5 fw-bold text-dark mb-1 d-flex align-items-center gap-2 flex-wrap">
                                        <span id="contactPhoneNumber">+63 917 123 4567</span>
                                        <span class="badge bg-light text-secondary border fw-normal" style="font-size: 0.75rem;">(0917 123 4567)</span>
                                    </div>
                                    <p class="text-muted small mb-2">Available for calls &amp; inquiries &bull; Mon&ndash;Sat 8:00 AM &ndash; 5:00 PM</p>
                                    <div class="d-flex align-items-center gap-2 flex-wrap">
                                        <a href="tel:+639171234567" class="btn btn-sm btn-success fw-semibold d-inline-flex align-items-center gap-1.5" style="border-radius: 0.5rem; padding: 0.38rem 0.85rem;">
                                            <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
                                            <span>Call Now</span>
                                        </a>
                                        <button type="button" class="btn btn-sm btn-outline-secondary fw-semibold d-inline-flex align-items-center gap-1.5" id="btnCopyPhone" onclick="copyContactPhoneNumber('+63 917 123 4567', this)" style="border-radius: 0.5rem; padding: 0.38rem 0.85rem;">
                                            <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
                                            <span class="copy-label">Copy Number</span>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Location Card -->
                    <div class="card border-0 shadow-sm rounded-3" style="background: #ffffff;">
                        <div class="card-body p-3">
                            <div class="d-flex align-items-center gap-2 mb-1">
                                <span class="text-primary">
                                    <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
                                </span>
                                <strong class="small text-dark">Location:</strong>
                                <span class="small text-muted">Nasugbu, Batangas, Philippines</span>
                            </div>
                            <div class="small text-muted ps-4">
                                Catering to buyers looking for residential lots, farm lots, beach lots, condos &amp; commercial properties.
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Modal Footer -->
                <div class="modal-footer border-0 pt-0 px-4 pb-3" style="background: #f8fafc;">
                    <button type="button" class="btn btn-outline-secondary btn-sm px-3 rounded-pill" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($currentUser !== null): ?>
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
                        <a href="logout.php" class="btn btn-danger w-50 fw-semibold">Yes, Log Out</a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ═══════════════════ MANAGE ACCOUNT / CHANGE PASSWORD MODAL ═══════════════════ -->
    <div class="modal fade" id="manageAccountModal" tabindex="-1" aria-labelledby="manageAccountModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content shadow-lg border-0 rounded-4 overflow-hidden">
                <div class="modal-header border-0 text-white" style="background: linear-gradient(135deg, #142d4e 0%, #174a70 100%); padding: 1.25rem 1.5rem;">
                    <div class="d-flex align-items-center gap-3">
                        <div class="d-inline-flex align-items-center justify-content-center rounded-circle flex-shrink-0" style="width: 42px; height: 42px; background: rgba(216, 173, 99, 0.2); border: 1.5px solid rgba(216, 173, 99, 0.6); color: #d8ad63;">
                            <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
                        </div>
                        <div>
                            <h5 class="modal-title fw-bold text-white mb-0" id="manageAccountModalLabel">Manage Account</h5>
                            <div class="small" style="color: rgba(255, 255, 255, 0.85);"><?= htmlspecialchars((string) ($displayName ?? ($currentUser['fullname'] ?? '')), ENT_QUOTES, 'UTF-8') ?><?php if ($currentRole === 'Admin' || $currentRole === 'Sales Agent'): ?> &bull; <?= htmlspecialchars((string) $currentRole, ENT_QUOTES, 'UTF-8') ?><?php endif; ?></div>
                        </div>
                    </div>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-4" style="background: #ffffff;">
                    <div id="manageAccountAlert" class="alert d-none" role="alert"></div>

                    <!-- Profile summary box -->
                    <div class="bg-light p-3 rounded-3 mb-3 border">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <span class="text-muted small fw-semibold">Account</span>
                            <span class="fw-bold small text-dark"><?= htmlspecialchars((string) ($displayName ?? ($currentUser['fullname'] ?? '')), ENT_QUOTES, 'UTF-8') ?></span>
                        </div>
                        <?php if (!empty($currentUser['email'])): ?>
                            <div class="d-flex justify-content-between align-items-center mb-1">
                                <span class="text-muted small fw-semibold">Email</span>
                                <span class="small text-dark"><?= htmlspecialchars((string) $currentUser['email'], ENT_QUOTES, 'UTF-8') ?></span>
                            </div>
                        <?php endif; ?>
                        <?php if ($currentRole === 'Admin' || $currentRole === 'Sales Agent'): ?>
                            <div class="d-flex justify-content-between align-items-center">
                                <span class="text-muted small fw-semibold">Role</span>
                                <span class="badge bg-secondary-subtle text-secondary border"><?= htmlspecialchars((string) $currentRole, ENT_QUOTES, 'UTF-8') ?></span>
                            </div>
                        <?php endif; ?>
                    </div>

                    <h6 class="fw-bold mb-3 d-flex align-items-center gap-2">
                        <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                        <span>Change Password</span>
                    </h6>

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
                            <button type="button" class="btn btn-outline-danger btn-sm" data-bs-dismiss="modal" data-bs-toggle="modal" data-bs-target="#logoutConfirmModal">
                                Log Out
                            </button>
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
    <?php endif; ?>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
    <script src="assets/js/app.js"></script>
</body>
</html>