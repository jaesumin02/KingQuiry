<?php
(static function (array $page): void {
    ?>
    <div class="admin-control-room-banner">
        <img class="kq-official-logo kq-logo-dashboard kq-logo-on-dark" src="assets/images/kings-land-properties-logo-light.png" alt="4 Kings Realty Services">
        <div class="admin-control-room-banner-copy">
            <div class="page-eyebrow admin-management-eyebrow">ADMINISTRATION</div>
            <div class="text-white fw-semibold mb-0">Management workspace</div>
        </div>
    </div>
    <header class="admin-page-header">
        <div class="admin-page-heading">
            <h1 class="page-title h2 mb-1"><?= htmlspecialchars($page['title'], ENT_QUOTES, 'UTF-8') ?></h1>
            <p class="admin-page-subtitle mb-0"><?= htmlspecialchars($page['subtitle'], ENT_QUOTES, 'UTF-8') ?></p>
        </div>
    </header>
    <?php
})($adminPage);
