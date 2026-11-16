<?php
// These modules load separate pages, so retain native navigation semantics.
(static function (): void {
    require_once __DIR__ . '/admin_layout.php';
    $currentPage = basename($_SERVER['PHP_SELF'] ?? '');
    $pages = kq_admin_pages();
    ?>
    <nav class="admin-nav" aria-label="Admin navigation">
        <?php foreach ($pages as $route => $page): ?>
            <a class="btn admin-nav-tab <?= $route === $currentPage ? 'btn-primary active' : 'btn-outline-primary' ?>" href="<?= htmlspecialchars($route, ENT_QUOTES, 'UTF-8') ?>"<?= $route === $currentPage ? ' aria-current="page"' : '' ?>><?= htmlspecialchars($page['title'], ENT_QUOTES, 'UTF-8') ?></a>
        <?php endforeach; ?>
    </nav>
    <?php
})();
