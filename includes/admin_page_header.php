<?php
(static function (array $page): void {
    ?>
    <div class="admin-control-room-banner">
        <span class="kq-dashboard-brand">4 KINGS</span>
        <div class="admin-control-room-banner-copy">
            <div class="page-eyebrow admin-management-eyebrow">KingQuiry management</div>
            <div class="text-white fw-semibold mb-0">System admin control room</div>
        </div>
    </div>
    <header class="admin-page-header">
        <div class="admin-page-heading">
            <div class="page-eyebrow admin-management-eyebrow">KingQuiry management</div>
            <h1 class="page-title h2 mb-1"><?= htmlspecialchars($page['title'], ENT_QUOTES, 'UTF-8') ?></h1>
            <p class="admin-page-subtitle mb-0"><?= htmlspecialchars($page['subtitle'], ENT_QUOTES, 'UTF-8') ?></p>
        </div>
        <?php include __DIR__ . '/admin_nav.php'; ?>
    </header>
    <?php
})($adminPage);
