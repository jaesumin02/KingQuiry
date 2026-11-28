<?php
(static function (): void {
    require_once __DIR__ . '/admin_layout.php';
    $currentPage = basename($_SERVER['PHP_SELF'] ?? '');
    $items = kq_admin_sidebar_items($currentPage);
    ?>
    <button class="kq-menu-toggle" type="button" data-portal-menu-toggle data-menu-label="Admin menu" aria-label="Open Admin menu" aria-controls="admin-navigation" aria-expanded="false">
        <svg class="kq-menu-toggle-crown" viewBox="0 0 24 24" aria-hidden="true"><path d="M3 19.5h18l-1.65-9.2-4.45 3.05L12 5.2l-2.9 8.15-4.45-3.05L3 19.5Z"/><path d="M5.2 21.5h13.6" fill="none" stroke="currentColor" stroke-linecap="round" stroke-width="1.5"/></svg>
        <span>Admin menu</span>
    </button>
    <div class="kq-admin-sidebar-heading">ADMIN MENU</div>
    <nav class="admin-nav" id="admin-navigation" aria-label="Administration navigation">
        <?php foreach ($items as $item): ?>
            <?php
            $isActive = !empty($item['is_active']);
            $classes = 'btn admin-nav-tab ' . ($isActive ? 'btn-primary active' : 'btn-outline-primary');
            ?>
            <?php if ($item['type'] === 'modal'): ?>
                <button type="button" class="<?= $classes ?>" id="<?= htmlspecialchars($item['id'], ENT_QUOTES, 'UTF-8') ?>" data-bs-toggle="modal" data-bs-target="<?= htmlspecialchars($item['modal_target'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($item['title'], ENT_QUOTES, 'UTF-8') ?></button>
            <?php else: ?>
                <a class="<?= $classes ?>" id="<?= htmlspecialchars($item['id'], ENT_QUOTES, 'UTF-8') ?>" href="<?= htmlspecialchars($item['href'], ENT_QUOTES, 'UTF-8') ?>"<?= $isActive ? ' aria-current="page"' : '' ?>><?= htmlspecialchars($item['title'], ENT_QUOTES, 'UTF-8') ?></a>
            <?php endif; ?>
        <?php endforeach; ?>
    </nav>
    <?php
})();
