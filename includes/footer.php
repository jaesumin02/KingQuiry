<?php
$chatWidgetPages = ['index.php', 'property_detail.php', 'client_dashboard.php', 'agents.php', 'resources.php', 'about.php'];
$currentScript = basename($_SERVER['SCRIPT_NAME'] ?? '');
$currentPhpSelf = basename($_SERVER['PHP_SELF'] ?? '');
$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
$currentUri = is_string($requestPath) ? basename($requestPath) : '';

$isChatPage = in_array($currentScript, $chatWidgetPages, true)
    || in_array($currentPhpSelf, $chatWidgetPages, true)
    || in_array($currentUri, $chatWidgetPages, true)
    || (isset($trackingRows) && is_array($trackingRows));

$currentRole = normalized_user_role($_SESSION['user'] ?? null);
if ($isChatPage && ($currentRole === null || $currentRole === 'Client')) {
    include __DIR__ . '/chat_widget.php';
}
?>
        <?php if ($isAdminControlRoom): ?>
                </div>
            </div>
        <?php endif; ?>
    </main>
    <footer class="app-footer">
        <div class="container-fluid px-3 px-sm-4 px-xl-5 d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
            <div class="d-flex align-items-center gap-3">
                <img class="kq-official-logo kq-logo-footer kq-logo-on-dark" src="assets/images/kings-land-properties-logo-light.png" alt="4 Kings Realty Services">
                <div>
                    <div class="fw-bold text-white">KingQuiry</div>
                    <div class="small" style="color: rgba(255,255,255,.72);">Property discovery and client services by Kings Land Properties &bull; Munting Indang, Nasugbu, Batangas.</div>
                </div>
            </div>
            <div class="d-flex align-items-center gap-3 small flex-wrap">
                <?php if ($currentRole === null || $currentRole === 'Client'): ?>
                    <a href="https://www.facebook.com/p/Kings-Land-Properties-61568411772451/" target="_blank" rel="noopener noreferrer" class="text-white-50 text-decoration-none hover-white d-inline-flex align-items-center gap-1">
                        <span>Facebook Page</span>
                        <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
                    </a>
                <?php endif; ?>
                <span class="text-white-50">&copy; <?= date('Y') ?> Kings Land Properties. All rights reserved.</span>
            </div>
        </div>
    </footer>

    <?php
    // Modular application modals
    if ($currentRole === null || $currentRole === 'Client') {
        include __DIR__ . '/modals/contact_us_modal.php';
    }
    $sessionUser = $_SESSION['user'] ?? $currentUser ?? null;
    if ($sessionUser !== null) {
        $currentUser = $sessionUser;
        include __DIR__ . '/modals/notifications_modal.php';
        include __DIR__ . '/modals/account_modals.php';
    }
    include __DIR__ . '/modals/user_manual_modal.php';
    ?>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="assets/js/app.js"></script>
</body>
</html>
