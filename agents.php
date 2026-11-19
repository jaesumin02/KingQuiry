<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/auth.php';

$agentId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
$agentId = $agentId === false ? 0 : max(0, $agentId);
$agent = null;

if ($agentId > 0) {
    $agentStmt = $conn->prepare(
        "SELECT userID, fullname, email, phone
         FROM users
         WHERE userID = ?
           AND role IN ('Agent', 'Sales Agent')
           AND accountStatus = 'Active'
         LIMIT 1"
    );
    $agentStmt->bind_param('i', $agentId);
    $agentStmt->execute();
    $agent = $agentStmt->get_result()->fetch_assoc();

    if (!$agent) {
        http_response_code(404);
    }

    $agents = $agent ? [$agent] : [];
} else {
    $agentsResult = $conn->query(
        "SELECT userID, fullname, email, phone
         FROM users
         WHERE role IN ('Agent', 'Sales Agent')
           AND accountStatus = 'Active'
         ORDER BY fullname ASC, userID ASC"
    );
    $agents = $agentsResult->fetch_all(MYSQLI_ASSOC);
}

include __DIR__ . '/includes/header.php';
?>

<section class="rounded-4 p-4 p-lg-5 mb-4 text-white" style="background: linear-gradient(125deg, #102a43, #174a70);">
    <div class="page-eyebrow text-warning">Kings Land Properties</div>
    <h1 class="display-6 fw-bold mb-2"><?= $agent ? 'Meet ' . htmlspecialchars((string) $agent['fullname'], ENT_QUOTES, 'UTF-8') : 'Connect with our agents' ?></h1>
    <p class="mb-0 text-white-50">Browse active sales-agent contacts and reach out directly about available properties and viewing requests.</p>
</section>

<?php if ($agentId > 0 && !$agent): ?>
    <div class="alert alert-warning" role="status">This agent profile is unavailable. Please choose an active agent from the directory.</div>
    <a class="btn btn-primary" href="agents.php">Browse agents</a>
<?php elseif ($agents === []): ?>
    <div class="card p-4 p-lg-5 text-center">
        <h2 class="h4 fw-bold">No active agent profiles are available</h2>
        <p class="text-muted mb-3">Browse current listings or contact Kings Land Properties for assistance.</p>
        <a class="btn btn-primary align-self-center" href="index.php#all-properties">Browse properties</a>
    </div>
<?php else: ?>
    <?php if ($agent): ?>
        <div class="card p-3 p-md-4 p-lg-5">
            <div class="mb-4">
                <a class="btn btn-sm btn-outline-secondary" href="agents.php">&larr; Back to all agents</a>
            </div>
            <div class="row align-items-center g-4">
                <div class="col-12 col-lg-8">
                    <div class="d-flex align-items-center gap-3 gap-md-4">
                        <span class="d-inline-flex flex-shrink-0 rounded-circle align-items-center justify-content-center bg-primary-subtle text-primary fw-bold" style="width: 76px; height: 76px; font-size: 1.8rem;" aria-hidden="true"><?= htmlspecialchars(strtoupper(substr((string) $agent['fullname'], 0, 1)), ENT_QUOTES, 'UTF-8') ?></span>
                        <div class="min-w-0">
                            <div class="page-eyebrow">Sales agent profile</div>
                            <h2 class="h3 fw-bold mb-2 text-break"><?= htmlspecialchars((string) $agent['fullname'], ENT_QUOTES, 'UTF-8') ?></h2>
                            <p class="text-muted mb-0">Contact this agent to ask about available property listings or arrange a viewing.</p>
                        </div>
                    </div>
                </div>
                <?php
                $phoneHref = preg_replace('/[^0-9+]/', '', (string) ($agent['phone'] ?? ''));
                $phoneIsCallable = is_string($phoneHref) && preg_match('/^\+?[0-9]{7,15}$/', $phoneHref) === 1;
                $emailIsValid = filter_var($agent['email'], FILTER_VALIDATE_EMAIL) !== false;
                ?>
                <div class="col-12 col-lg-4">
                    <div class="rounded-3 border bg-light p-3 p-md-4 h-100">
                        <div class="page-eyebrow mb-2">Contact this agent</div>
                        <div class="d-grid gap-2">
                            <button type="button" class="btn btn-primary"
                                    data-kq-chat-agent="<?= htmlspecialchars((string) $agent['fullname'], ENT_QUOTES, 'UTF-8') ?>"
                                    data-kq-chat-agent-id="<?= (int) $agent['userID'] ?>"
                                    data-kq-chat-agent-email="<?= $emailIsValid ? htmlspecialchars((string) $agent['email'], ENT_QUOTES, 'UTF-8') : '' ?>"
                                    data-kq-chat-agent-phone="<?= $phoneIsCallable ? htmlspecialchars((string) $agent['phone'], ENT_QUOTES, 'UTF-8') : '' ?>">Chat with agent</button>
                            <?php if ($emailIsValid): ?>
                                <a class="btn btn-outline-primary" href="mailto:<?= htmlspecialchars((string) $agent['email'], ENT_QUOTES, 'UTF-8') ?>?subject=KingQuiry%20property%20inquiry">Email agent</a>
                            <?php endif; ?>
                            <?php if ($phoneIsCallable): ?>
                                <a class="btn btn-outline-primary" href="tel:<?= htmlspecialchars($phoneHref, ENT_QUOTES, 'UTF-8') ?>">Call agent</a>
                            <?php endif; ?>
                            <?php if (!$emailIsValid && !$phoneIsCallable): ?>
                                <p class="small text-muted mb-0">Direct contact details are not currently available for this profile.</p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            <hr class="my-4">
            <div class="row g-3">
                <?php if ($emailIsValid): ?>
                    <div class="col-12 col-md-6">
                        <div class="small text-muted mb-1">Email</div>
                        <a class="text-break" href="mailto:<?= htmlspecialchars((string) $agent['email'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars((string) $agent['email'], ENT_QUOTES, 'UTF-8') ?></a>
                    </div>
                <?php endif; ?>
                <?php if ($phoneIsCallable): ?>
                    <div class="col-12 col-md-6">
                        <div class="small text-muted mb-1">Phone</div>
                        <a href="tel:<?= htmlspecialchars($phoneHref, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars((string) $agent['phone'], ENT_QUOTES, 'UTF-8') ?></a>
                    </div>
                <?php endif; ?>
            </div>
            <p class="small text-muted mt-4 mb-0">Direct chat messages are sent to the selected agent through KingQuiry. Email and phone links open outside the site. Do not send passwords, payment credentials, or sensitive identity documents.</p>
        </div>
    <?php else: ?>
        <div class="d-flex justify-content-between align-items-end flex-wrap gap-2 mb-3">
            <div>
                <div class="page-eyebrow">Our team</div>
                <h2 class="h3 fw-bold mb-0">Active sales agents</h2>
            </div>
            <span class="text-muted small"><?= count($agents) ?> profile<?= count($agents) === 1 ? '' : 's' ?></span>
        </div>
        <div class="row g-3">
            <?php foreach ($agents as $listedAgent):
                $emailIsValid = filter_var($listedAgent['email'], FILTER_VALIDATE_EMAIL) !== false;
                $phoneHref = preg_replace('/[^0-9+]/', '', (string) ($listedAgent['phone'] ?? ''));
                $phoneIsCallable = is_string($phoneHref) && preg_match('/^\+?[0-9]{7,15}$/', $phoneHref) === 1;
            ?>
                <div class="col-md-6 col-xl-4">
                    <article class="card h-100 p-4 d-flex flex-column">
                        <div class="d-flex align-items-center gap-3 mb-3">
                            <span class="d-inline-flex rounded-circle align-items-center justify-content-center bg-primary-subtle text-primary fw-bold" style="width: 52px; height: 52px;" aria-hidden="true"><?= htmlspecialchars(strtoupper(substr((string) $listedAgent['fullname'], 0, 1)), ENT_QUOTES, 'UTF-8') ?></span>
                            <div>
                                <h3 class="h5 fw-bold mb-1"><?= htmlspecialchars((string) $listedAgent['fullname'], ENT_QUOTES, 'UTF-8') ?></h3>
                                <div class="small text-muted">Sales agent</div>
                            </div>
                        </div>
                        <p class="text-muted small flex-grow-1">Ask about available listings, property details, or arranging a viewing.</p>
                        <div class="d-grid gap-2 mt-auto">
                            <a class="btn btn-sm btn-outline-primary" href="agents.php?id=<?= (int) $listedAgent['userID'] ?>">View profile</a>
                            <button type="button" class="btn btn-sm btn-outline-primary"
                                    data-kq-chat-agent="<?= htmlspecialchars((string) $listedAgent['fullname'], ENT_QUOTES, 'UTF-8') ?>"
                                    data-kq-chat-agent-id="<?= (int) $listedAgent['userID'] ?>"
                                    data-kq-chat-agent-email="<?= $emailIsValid ? htmlspecialchars((string) $listedAgent['email'], ENT_QUOTES, 'UTF-8') : '' ?>"
                                    data-kq-chat-agent-phone="<?= $phoneIsCallable ? htmlspecialchars((string) $listedAgent['phone'], ENT_QUOTES, 'UTF-8') : '' ?>">Chat with agent</button>
                            <?php if ($emailIsValid): ?>
                                <a class="btn btn-sm btn-primary" href="mailto:<?= htmlspecialchars((string) $listedAgent['email'], ENT_QUOTES, 'UTF-8') ?>?subject=KingQuiry%20property%20inquiry">Email agent</a>
                            <?php endif; ?>
                            <?php if ($phoneIsCallable): ?>
                                <a class="btn btn-sm btn-primary" href="tel:<?= htmlspecialchars($phoneHref, ENT_QUOTES, 'UTF-8') ?>">Call agent</a>
                            <?php endif; ?>
                        </div>
                    </article>
                </div>
            <?php endforeach; ?>
        </div>
        <p class="small text-muted mt-3 mb-0">Use Chat with agent for an in-site message or the email and phone links for direct contact. Agent availability should be confirmed.</p>
    <?php endif; ?>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
