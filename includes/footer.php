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
        <div class="container d-flex flex-column flex-md-row justify-content-between gap-2">
            <div>
                <div class="fw-bold text-white">KingQuiry</div>
                <div class="small">Property discovery and client services by Kings Land Properties.</div>
            </div>
            <div class="small align-self-md-end">&copy; <?= date('Y') ?> Kings Land Properties. All rights reserved.</div>
        </div>
    </footer>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>