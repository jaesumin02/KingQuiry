<?php
(static function (): void {
    require_once __DIR__ . '/admin_layout.php';
    $currentPage = basename($_SERVER['PHP_SELF'] ?? '');
    $pages = kq_admin_pages();
    ?>
    <button class="kq-menu-toggle" type="button" data-portal-menu-toggle data-menu-label="Admin menu" aria-label="Open Admin menu" aria-controls="admin-navigation" aria-expanded="false">
        <span class="kq-menu-toggle-icon" aria-hidden="true"><span></span><span></span><span></span></span>
        <span>Admin menu</span>
    </button>
    <div class="kq-admin-sidebar-heading">Admin menu</div>
    <nav class="admin-nav" id="admin-navigation" aria-label="Administration navigation">
        <?php foreach ($pages as $route => $page): ?>
            <a class="btn admin-nav-tab <?= $route === $currentPage ? 'btn-primary active' : 'btn-outline-primary' ?>" href="<?= htmlspecialchars($route, ENT_QUOTES, 'UTF-8') ?>"<?= $route === $currentPage ? ' aria-current="page"' : '' ?>><?= htmlspecialchars($page['title'], ENT_QUOTES, 'UTF-8') ?></a>
        <?php endforeach; ?>
        <?php if ($currentPage === 'admin_dashboard.php'): ?>
            <a class="btn btn-outline-primary admin-nav-tab" href="admin_dashboard.php#client-transactions">Client transactions</a>
            <a class="btn btn-outline-primary admin-nav-tab" href="admin_dashboard.php#property-list">Property listings</a>
            <button type="button" class="btn btn-outline-primary admin-nav-tab" data-bs-toggle="modal" data-bs-target="#auditLogsModal">Audit logs</button>
        <?php endif; ?>
    </nav>
    <?php
})();
