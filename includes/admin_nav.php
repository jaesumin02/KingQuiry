<?php
// These modules load separate pages, so retain native navigation semantics.
(static function (): void {
    $currentPage = basename($_SERVER['PHP_SELF'] ?? '');
    $pages = [
        'admin_dashboard.php' => 'Admin dashboard',
        'admin_sales.php' => 'Sales & transactions',
        'admin_clients.php' => 'Clients',
        'admin_payments.php' => 'Payments',
        'transaction_tracking.php' => 'Transaction tracking',
        'admin_chat.php' => 'Customer inbox',
    ];
    ?>
    <nav class="d-flex flex-wrap gap-2 admin-nav" aria-label="Admin navigation">
        <?php foreach ($pages as $page => $label): ?>
            <a class="btn admin-nav-tab <?= $page === $currentPage ? 'btn-primary active' : 'btn-outline-primary' ?>" href="<?= htmlspecialchars($page, ENT_QUOTES, 'UTF-8') ?>"<?= $page === $currentPage ? ' aria-current="page"' : '' ?>><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></a>
        <?php endforeach; ?>
    </nav>
    <?php
})();
