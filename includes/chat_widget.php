<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/location_directory.php';
require_once __DIR__ . '/property_categories.php';

if (!function_exists('chat_csrf_token')) {
    function chat_csrf_token(): string
    {
        if (!isset($_SESSION['chat_csrf'])) {
            $_SESSION['chat_csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['chat_csrf'];
    }
}
$chatLocations = get_property_locations($conn);
$widgetCsrfToken = chat_csrf_token();
$widgetCurrentRole = normalized_user_role($_SESSION['user'] ?? null);
$widgetClientTransactions = [];

if (!empty($_SESSION['user']['userID']) && $widgetCurrentRole === 'Client') {
    if (isset($trackingRows) && is_array($trackingRows) && $trackingRows !== []) {
        $widgetClientTransactions = $trackingRows;
    } else {
        $wClientId = (int) $_SESSION['user']['userID'];
        $wQuery = $conn->prepare("
            SELECT t.transactionID, t.propertyID, t.currentStage, p.title,
                   COALESCE(
                       apptAgent.fullname,
                       inqAgent.fullname,
                       reviewAgent.fullname,
                       (
                           SELECT u2.fullname FROM property_transaction_events e
                           JOIN users u2 ON u2.userID = e.changedBy
                           WHERE e.transactionID = t.transactionID AND e.changedBy IS NOT NULL
                           ORDER BY e.createdAt DESC, e.eventID DESC LIMIT 1
                       ),
                       'Kings Land Sales Agent'
                   ) AS agentName,
                   COALESCE(apptAgent.phone, inqAgent.phone, '0965 803 0933') AS agentPhone,
                   COALESCE(apptAgent.email, inqAgent.email, 'contact@kingsland.ph') AS agentEmail,
                   COALESCE(
                       a.agentID,
                       ia.agentID,
                       ar.agentID,
                       (
                           SELECT e.changedBy FROM property_transaction_events e
                           WHERE e.transactionID = t.transactionID AND e.changedBy IS NOT NULL
                           ORDER BY e.createdAt DESC, e.eventID DESC LIMIT 1
                       )
                   ) AS agentID
            FROM property_transactions t
            JOIN properties p ON p.propertyID = t.propertyID
            LEFT JOIN appointments a ON a.appointmentID = t.appointmentID
            LEFT JOIN users apptAgent ON apptAgent.userID = a.agentID
            LEFT JOIN agent_inquiry_assignments ia ON ia.inquiryID = t.inquiryID
            LEFT JOIN users inqAgent ON inqAgent.userID = ia.agentID
            LEFT JOIN agent_reviews ar ON ar.transactionID = t.transactionID
            LEFT JOIN users reviewAgent ON reviewAgent.userID = ar.agentID
            WHERE t.clientID = ?
            ORDER BY t.updatedAt DESC, t.transactionID DESC
            LIMIT 50
        ");
        if ($wQuery) {
            $wQuery->bind_param('i', $wClientId);
            $wQuery->execute();
            $widgetClientTransactions = $wQuery->get_result()->fetch_all(MYSQLI_ASSOC);
        }
    }
}

$widgetStageLabels = function_exists('property_transaction_stages') ? property_transaction_stages() : [
    'inquiry_received' => 'Inquiry Received',
    'contacting_client' => 'Contacting Client',
    'viewing_requested' => 'Viewing Requested',
    'viewing_confirmed' => 'Viewing Approved',
    'viewing_completed' => 'Viewing Completed',
    'payment_in_process' => 'Payment In Process',
    'document_verification' => 'Document Verification',
    'payment_confirmation' => 'Payment Confirmed',
    'completed' => 'Completed',
    'cancelled' => 'Cancelled',
];

$conversationsData = array_map(static function($t) use ($widgetStageLabels) {
    $rawStage = (string) ($t['currentStage'] ?? '');
    return [
        'transaction_id' => (int) ($t['transactionID'] ?? 0),
        'property_id' => (int) ($t['propertyID'] ?? 0),
        'property_title' => (string) ($t['title'] ?? 'Selected Property'),
        'stage' => $rawStage,
        'stage_label' => (string) ($widgetStageLabels[$rawStage] ?? ($rawStage !== '' ? ucfirst($rawStage) : 'Active')),
        'agent_id' => (int) ($t['agentID'] ?? 0),
        'agent_name' => (string) ($t['agentName'] ?? 'Sales Agent'),
        'agent_email' => (string) ($t['agentEmail'] ?? 'contact@kingsland.ph'),
        'agent_phone' => (string) ($t['agentPhone'] ?? '0965 803 0933'),
    ];
}, $widgetClientTransactions);
?>

<style>
    /* ══════════ CORNER CHAT BUBBLE & FLOATING MESSENGER WINDOW ══════════ */
    #kingquiry-chat-launcher {
        position: fixed;
        right: max(20px, env(safe-area-inset-right));
        bottom: max(20px, env(safe-area-inset-bottom));
        z-index: 1060;
        display: grid;
        width: 60px;
        height: 60px;
        place-items: center;
        padding: 0;
        border: 0;
        border-radius: 50%;
        background: linear-gradient(135deg, #174a70 0%, #0d2a42 100%);
        color: #ffffff;
        box-shadow: 0 8px 26px rgba(13, 42, 66, .38);
        transition: transform .2s cubic-bezier(.34,1.56,.64,1), box-shadow .2s ease;
        cursor: pointer;
    }

    #kingquiry-chat-launcher:hover {
        transform: translateY(-3px) scale(1.05);
        box-shadow: 0 12px 32px rgba(13, 42, 66, .48);
    }

    #kingquiry-chat-launcher:focus-visible {
        outline: 3px solid #d8ad63;
        outline-offset: 3px;
    }

    #kingquiry-chat-panel {
        position: fixed;
        right: max(20px, env(safe-area-inset-right));
        bottom: calc(max(20px, env(safe-area-inset-bottom)) + 74px);
        z-index: 1060;
        display: flex;
        flex-direction: column;
        width: min(460px, calc(100vw - 32px));
        height: min(660px, calc(100dvh - 110px));
        max-height: calc(100vh - 110px);
        overflow: hidden;
        border: 1px solid rgba(24, 48, 76, .16) !important;
        border-radius: 20px;
        box-shadow: 0 24px 64px rgba(12, 34, 54, .32);
        background: #ffffff;
        opacity: 0;
        visibility: hidden;
        pointer-events: none;
        transform: translateY(16px) scale(.96);
        transform-origin: bottom right;
        transition: opacity .22s ease, transform .22s cubic-bezier(.34,1.56,.64,1), visibility .22s ease;
    }

    #kingquiry-chat-panel.is-open {
        opacity: 1;
        visibility: visible;
        pointer-events: auto;
        transform: translateY(0) scale(1);
    }

    /* Header styling matching screenshot */
    #kingquiry-chat-panel .kingquiry-chat-header {
        background: linear-gradient(135deg, #174a70 0%, #0c263c 100%);
        color: #ffffff;
        padding: 0.85rem 1rem;
        border-bottom: 1px solid rgba(255, 255, 255, 0.12);
        flex-shrink: 0;
    }

    /* Top Conversations horizontal selector */
    .kq-chat-conv-bar {
        background: #f8fafc;
        border-bottom: 1px solid #e2e8f0;
        padding: 0.5rem 0.75rem;
        flex-shrink: 0;
    }

    .kq-chat-conv-btn {
        display: inline-flex;
        flex-direction: column;
        align-items: flex-start;
        border: 1.5px solid #cbd5e1;
        background: #ffffff;
        color: #1e293b;
        border-radius: 0.75rem;
        padding: 0.4rem 0.75rem;
        transition: all 0.16s ease;
        white-space: nowrap;
        cursor: pointer;
        min-width: 160px;
        text-align: left;
    }

    .kq-chat-conv-btn:hover {
        border-color: #174a70;
        background: #f1f5f9;
        transform: translateY(-1px);
    }

    .kq-chat-conv-btn.is-active {
        background: linear-gradient(135deg, #174a70, #0f324e) !important;
        color: #ffffff !important;
        border-color: #174a70 !important;
        box-shadow: 0 4px 12px rgba(23, 74, 112, 0.25);
    }

    .kq-chat-conv-btn.is-active .text-muted,
    .kq-chat-conv-btn.is-active .kq-conv-sub {
        color: rgba(255, 255, 255, 0.82) !important;
    }

    .kq-chat-conv-btn.is-active .badge {
        background-color: rgba(255, 255, 255, 0.25) !important;
        color: #ffffff !important;
    }

    /* Direct Channel Bar */
    .kq-chat-channel-bar {
        background: #ffffff;
        border-bottom: 1px solid #e2e8f0;
        padding: 0.45rem 0.85rem;
        font-size: 0.76rem;
        flex-shrink: 0;
    }

    /* Message Thread Container */
    #kingquiry-chat-messages {
        flex: 1 1 0;
        min-height: 80px;
        overflow-y: auto;
        background: #f1f5f9;
        padding: 0.85rem;
        overscroll-behavior: contain;
    }

    .kq-chat-bubble-agent {
        background: #ffffff;
        border: 1px solid #e2e8f0;
        color: #0f172a;
        border-radius: 14px 14px 14px 3px;
        box-shadow: 0 2px 6px rgba(15, 23, 42, 0.05);
        font-size: 0.86rem;
        padding: 0.65rem 0.85rem;
        white-space: pre-wrap;
        word-break: break-word;
    }

    .kq-chat-bubble-client {
        background: linear-gradient(135deg, #174a70 0%, #0f324e 100%);
        color: #ffffff;
        border-radius: 14px 14px 3px 14px;
        box-shadow: 0 2px 8px rgba(23, 74, 112, 0.2);
        font-size: 0.86rem;
        padding: 0.65rem 0.85rem;
        white-space: pre-wrap;
        word-break: break-word;
    }

    /* Footer area */
    .kingquiry-chat-footer {
        background: #ffffff;
        border-top: 1px solid #e2e8f0;
        padding: 0.65rem 0.85rem;
        flex: 0 1 auto;
        min-height: 0;
        overflow-y: auto;
        overscroll-behavior: contain;
    }

    #kingquiry-estimate-result {
        white-space: pre-line;
        line-height: 1.5;
    }

    /* Dark Mode Theme Support */
    [data-bs-theme="dark"] #kingquiry-chat-panel {
        background: #0b1722 !important;
        border-color: #1e3a4e !important;
        box-shadow: 0 24px 64px rgba(0, 0, 0, .6);
    }

    [data-bs-theme="dark"] #kingquiry-chat-panel .kingquiry-chat-header {
        background: linear-gradient(135deg, #102537 0%, #081520 100%) !important;
        border-bottom-color: #1e3a4e !important;
    }

    [data-bs-theme="dark"] .kq-chat-conv-bar {
        background: #0f202e !important;
        border-bottom-color: #1e3a4e !important;
    }

    [data-bs-theme="dark"] .kq-chat-conv-btn {
        background: #142838 !important;
        border-color: #24415c !important;
        color: #f1f5f9 !important;
    }

    [data-bs-theme="dark"] .kq-chat-conv-btn:hover {
        background: #1a3449 !important;
        border-color: #38bdf8 !important;
    }

    [data-bs-theme="dark"] .kq-chat-conv-btn.is-active {
        background: linear-gradient(135deg, #1d4ed8 0%, #1e40af 100%) !important;
        border-color: #3b82f6 !important;
        color: #ffffff !important;
    }

    [data-bs-theme="dark"] .kq-chat-channel-bar {
        background: #0e1c28 !important;
        border-bottom-color: #1e3a4e !important;
        color: #cbd5e1 !important;
    }

    [data-bs-theme="dark"] #kingquiry-chat-messages {
        background: #081119 !important;
    }

    [data-bs-theme="dark"] .kq-chat-bubble-agent {
        background: #132433 !important;
        border-color: #1e3a4e !important;
        color: #f8fafc !important;
    }

    [data-bs-theme="dark"] .kingquiry-chat-footer {
        background: #0c1824 !important;
        border-top-color: #1e3a4e !important;
    }

    [data-bs-theme="dark"] #kingquiry-chat-input {
        background: #152737 !important;
        border-color: #24425d !important;
        color: #f8fafc !important;
    }

    [data-bs-theme="dark"] #kingquiry-chat-input::placeholder {
        color: #94a3b8 !important;
    }

    @media (max-width: 480px) {
        #kingquiry-chat-panel {
            right: 12px;
            bottom: calc(max(12px, env(safe-area-inset-bottom)) + 70px);
            width: calc(100vw - 24px);
            height: min(640px, calc(100dvh - 96px));
            max-height: calc(100vh - 96px);
            border-radius: 16px;
        }
        #kingquiry-chat-launcher {
            right: max(16px, env(safe-area-inset-right));
            bottom: max(16px, env(safe-area-inset-bottom));
            width: 54px;
            height: 54px;
        }
    }

    @media (prefers-reduced-motion: reduce) {
        #kingquiry-chat-launcher,
        #kingquiry-chat-panel {
            transition: none;
        }
    }
</style>

<!-- Floating Messenger Chat Launcher Bubble (Bottom Corner) -->
<button id="kingquiry-chat-launcher"
        type="button"
        aria-label="Open KingQuiry chat"
        title="Chat with KingQuiry and your Sales Agent"
        aria-expanded="false"
        aria-controls="kingquiry-chat-panel">
    <svg width="28" height="28" viewBox="0 0 24 24" fill="none" aria-hidden="true">
        <path d="M20 11.5a7.5 7.5 0 0 1-7.5 7.5H5l1.7-3.4A7.5 7.5 0 1 1 20 11.5Z" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
        <path d="M8 11.5h.01M12 11.5h.01M16 11.5h.01" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"/>
    </svg>
</button>

<!-- Corner Bottom Floating Chat Panel (Exact UI from Design Screenshot) -->
<section id="kingquiry-chat-panel"
         class="card border-0"
         aria-label="KingQuiry chat"
         aria-hidden="true"
         inert
         data-csrf="<?= htmlspecialchars($widgetCsrfToken, ENT_QUOTES, 'UTF-8') ?>">
    
    <!-- 1. Header with Agent Info, Badges, Tracking Button, and Minimize -->
    <header class="kingquiry-chat-header d-flex justify-content-between align-items-center">
        <div class="d-flex align-items-center gap-2 min-w-0">
            <div class="rounded-circle d-flex align-items-center justify-content-center bg-white text-primary fw-bold shadow-sm flex-shrink-0"
                 style="width: 38px; height: 38px; font-size: 1.2rem;"
                 id="kingquiry-chat-avatar">
                👨‍💼
            </div>
            <div class="min-w-0">
                <div class="d-flex align-items-center gap-1 flex-wrap">
                    <strong class="text-white text-truncate" style="font-size: 0.95rem;">
                        Chat with <span id="kingquiry-chat-agent-name">Sales Agent</span>
                    </strong>
                    <span class="badge text-bg-light border text-primary small fw-semibold" id="kingquiry-chat-role-badge" style="font-size: 0.65rem;">Assigned Sales Agent</span>
                    <span class="badge text-bg-success text-white small" id="kingquiry-chat-online-badge" style="font-size: 0.65rem;">
                        <i class="bi bi-circle-fill me-1" style="font-size: 0.45rem;"></i>Online
                    </span>
                </div>
                <div class="text-white-75 text-truncate mt-0" id="kingquiry-chat-regarding" style="font-size: 0.74rem;">
                    General Inquiry &amp; Client Assistance
                </div>
            </div>
        </div>
        <div class="d-flex align-items-center gap-2 flex-shrink-0 ms-2">
            <button type="button"
                    class="btn btn-sm btn-outline-light rounded-pill px-2 py-0 fw-semibold shadow-sm d-inline-flex align-items-center gap-1"
                    id="kingquiry-chat-tracking-btn"
                    onclick="kingQuiryChatGoToTracking()"
                    title="Open Transaction Tracking"
                    style="font-size: 0.74rem; height: 26px;">
                <span>Tracking</span>
            </button>
            <button id="kingquiry-chat-close"
                    type="button"
                    class="btn-close btn-close-white"
                    aria-label="Minimize chat bubble"
                    style="font-size: 0.8rem;"></button>
        </div>
    </header>

    <!-- 2. Top Section: Conversations Tabs (Switch between Transactions & Assigned Agents) -->
    <div class="kq-chat-conv-bar" id="kingquiry-chat-conversations-bar">
        <div class="d-flex align-items-center justify-content-between px-1 pb-1 mb-1">
            <div class="d-flex align-items-center gap-1">
                <strong class="text-uppercase fw-bold text-dark" style="font-size: 0.72rem; letter-spacing: 0.04em;">
                    <i class="bi bi-chat-left-dots me-1 text-primary"></i> CONVERSATIONS
                </strong>
                <span class="badge text-bg-primary rounded-pill" id="kingquiry-conv-count" style="font-size: 0.65rem;">
                    <?= count($conversationsData) ?>
                </span>
            </div>
            <span class="text-muted" style="font-size: 0.7rem;">Click to switch conversation</span>
        </div>
        <div class="d-flex flex-nowrap gap-2 overflow-x-auto py-1 px-1" id="kingquiry-conv-list" style="scrollbar-width: thin;">
            <!-- Populated dynamically with active transactions & agents -->
        </div>
    </div>

    <!-- 3. Direct Channel Info Bar -->
    <div class="kq-chat-channel-bar d-flex align-items-center justify-content-between flex-wrap gap-1" id="kingquiry-channel-bar">
        <div class="d-flex align-items-center gap-2 flex-wrap">
            <span class="badge bg-primary text-white" style="font-size: 0.65rem;">Direct Channel</span>
            <span class="text-muted" id="kingquiry-channel-email"><i class="bi bi-envelope me-1"></i>contact@kingsland.ph</span>
            <span class="text-muted" id="kingquiry-channel-phone"><i class="bi bi-telephone me-1"></i>0965 803 0933</span>
        </div>
        <div class="text-muted" style="font-size: 0.7rem;">
            <i class="bi bi-shield-check text-success me-1"></i>Verified KingQuiry Sales Agent
        </div>
    </div>

    <!-- 4. Message Thread Container -->
    <div id="kingquiry-chat-messages" class="d-flex flex-column gap-2" aria-live="polite">
        <div class="text-center text-muted small py-4" id="kingquiry-chat-loading">
            <div class="spinner-border spinner-border-sm text-primary me-2" role="status"></div>
            Connecting to KingQuiry chat...
        </div>
    </div>

    <!-- 5. Footer: Optional Estimate Toggle & Strict Text-Only Message Form -->
    <div class="kingquiry-chat-footer">
        <!-- Collapsible land estimate tool -->
        <button class="btn btn-sm btn-outline-secondary w-100 mb-2 py-1"
                type="button"
                data-bs-toggle="collapse"
                data-bs-target="#estimateCollapse"
                aria-expanded="false"
                aria-controls="estimateCollapse"
                style="font-size: 0.75rem;">
            Need a land estimate? Click here
        </button>
        <div class="collapse" id="estimateCollapse">
            <form id="kingquiry-estimate-form" class="border rounded p-2 mb-2 bg-light">
                <label class="form-label small mb-1" for="kingquiry-estimate-area" style="font-size: 0.75rem;">Land area (sq.m.)</label>
                <input id="kingquiry-estimate-area" class="form-control form-control-sm mb-2" type="number" name="area" min="1" max="1000000" step="any" required>
                <label class="form-label small mb-1" for="kingquiry-estimate-type" style="font-size: 0.75rem;">Property category</label>
                <select id="kingquiry-estimate-type" class="form-select form-select-sm mb-2" name="property_type" required>
                    <?php foreach (property_type_labels() as $propertyType => $propertyLabel): ?>
                        <option value="<?= htmlspecialchars($propertyType, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($propertyLabel, ENT_QUOTES, 'UTF-8') ?></option>
                    <?php endforeach; ?>
                </select>
                <label class="form-label small mb-1" for="kingquiry-estimate-location" style="font-size: 0.75rem;">City or municipality</label>
                <select id="kingquiry-estimate-location" class="form-select form-select-sm mb-2" name="location_id" required>
                    <?= property_location_options($chatLocations) ?>
                </select>
                <div id="kingquiry-estimate-result" class="alert alert-success small py-2 mb-2" role="status" aria-live="polite" hidden></div>
                <button id="kingquiry-estimate-action" type="submit" class="btn btn-sm btn-primary w-100 py-1" style="font-size: 0.78rem;">Calculate estimate</button>
            </form>
        </div>

        <!-- Text Message Input Form -->
        <form id="kingquiry-chat-form" class="input-group">
            <input id="kingquiry-chat-input"
                   type="text"
                   class="form-control"
                   maxlength="1000"
                   placeholder="Write a message to your assigned Sales Agent..."
                   aria-label="Your message"
                   required
                   autocomplete="off">
            <button class="btn btn-primary px-3" type="submit" id="kingquiry-chat-send-btn">
                <span>Send</span>
            </button>
        </form>
        <div class="d-flex align-items-center justify-content-between mt-1 text-muted small px-1" style="font-size: 0.72rem;">
            <span class="text-truncate me-2"><i class="bi bi-shield-lock-fill text-primary me-1"></i>Text messages only · File uploads disabled.</span>
            <span id="kingquiry-chat-char-count" class="flex-shrink-0">1000 chars left</span>
        </div>
    </div>
</section>

<script>
(() => {
    const apiUrl = 'chat_api.php';
    const launcher = document.getElementById('kingquiry-chat-launcher');
    const panel = document.getElementById('kingquiry-chat-panel');
    const closeButton = document.getElementById('kingquiry-chat-close');
    const messages = document.getElementById('kingquiry-chat-messages');
    const chatForm = document.getElementById('kingquiry-chat-form');
    const chatInput = document.getElementById('kingquiry-chat-input');
    const sendBtn = document.getElementById('kingquiry-chat-send-btn');
    const charCount = document.getElementById('kingquiry-chat-char-count');
    const estimateForm = document.getElementById('kingquiry-estimate-form');
    const estimateAction = document.getElementById('kingquiry-estimate-action');
    const estimateResult = document.getElementById('kingquiry-estimate-result');

    // Header & Channel elements
    const avatarEl = document.getElementById('kingquiry-chat-avatar');
    const agentNameEl = document.getElementById('kingquiry-chat-agent-name');
    const roleBadgeEl = document.getElementById('kingquiry-chat-role-badge');
    const onlineBadgeEl = document.getElementById('kingquiry-chat-online-badge');
    const regardingEl = document.getElementById('kingquiry-chat-regarding');
    const trackingBtnEl = document.getElementById('kingquiry-chat-tracking-btn');
    const channelEmailEl = document.getElementById('kingquiry-channel-email');
    const channelPhoneEl = document.getElementById('kingquiry-channel-phone');
    const convListEl = document.getElementById('kingquiry-conv-list');
    const convCountEl = document.getElementById('kingquiry-conv-count');

    // Server-provided transactions and CSRF
    const conversationsData = <?= json_encode($conversationsData, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
    let csrf = panel?.dataset?.csrf || '<?= htmlspecialchars($widgetCsrfToken, ENT_QUOTES, 'UTF-8') ?>';
    
    let activeTxId = conversationsData.length > 0 ? conversationsData[0].transaction_id : 0;
    let activeAgentId = conversationsData.length > 0 ? conversationsData[0].agent_id : 0;
    let activeAgentName = conversationsData.length > 0 ? conversationsData[0].agent_name : 'Sales Agent';
    let activePropertyTitle = conversationsData.length > 0 ? conversationsData[0].property_title : '';
    
    let lastMessageId = 0;
    let pollTimer = null;
    let requestInProgress = false;
    let bootstrapped = false;
    const renderedMessageIds = new Set();
    const greetedTransactions = new Set();

    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text || '';
        return div.innerHTML;
    }

    function renderConversationsList() {
        if (!convListEl) return;
        convListEl.innerHTML = '';

        if (!Array.isArray(conversationsData) || conversationsData.length === 0) {
            // General support card for visitors/guests
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'kq-chat-conv-btn is-active';
            btn.innerHTML = `
                <div class="d-flex align-items-center justify-content-between w-100 gap-2">
                    <strong class="small text-truncate" style="font-size: 0.78rem;">🤖 KingQuiry Assistant</strong>
                    <span class="badge text-bg-secondary" style="font-size: 0.62rem;">General</span>
                </div>
                <div class="small text-muted kq-conv-sub text-truncate w-100 mt-1" style="font-size: 0.7rem;">
                    Property Discovery &amp; Support
                </div>
            `;
            convListEl.appendChild(btn);
            if (convCountEl) convCountEl.textContent = '1';
            return;
        }

        if (convCountEl) convCountEl.textContent = String(conversationsData.length);

        conversationsData.forEach((conv) => {
            const isCurrent = conv.transaction_id === activeTxId;
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = `kq-chat-conv-btn ${isCurrent ? 'is-active' : ''}`;
            btn.onclick = () => selectConversation(conv.transaction_id);

            btn.innerHTML = `
                <div class="d-flex align-items-center justify-content-between w-100 gap-2">
                    <strong class="small text-truncate" style="font-size: 0.78rem;">👨‍💼 ${escapeHtml(conv.agent_name)}</strong>
                    <span class="badge text-bg-secondary" style="font-size: 0.62rem;">#KQT-${conv.transaction_id}</span>
                </div>
                <div class="small text-muted kq-conv-sub text-truncate w-100 mt-1" style="font-size: 0.7rem;">
                    ${escapeHtml(conv.property_title)}
                </div>
            `;
            convListEl.appendChild(btn);
        });
    }

    function updateActiveHeaderAndDetails(conv) {
        if (conv) {
            activeTxId = conv.transaction_id;
            activeAgentId = conv.agent_id;
            activeAgentName = conv.agent_name;
            activePropertyTitle = conv.property_title;

            if (avatarEl) avatarEl.textContent = '👨‍💼';
            if (agentNameEl) agentNameEl.textContent = conv.agent_name;
            if (roleBadgeEl) roleBadgeEl.textContent = 'Assigned Sales Agent';
            if (regardingEl) regardingEl.textContent = `Regarding: ${conv.property_title} (Transaction #KQT-${conv.transaction_id}) · ${conv.stage_label}`;
            if (channelEmailEl) channelEmailEl.innerHTML = `<i class="bi bi-envelope me-1"></i>${escapeHtml(conv.agent_email)}`;
            if (channelPhoneEl) channelPhoneEl.innerHTML = `<i class="bi bi-telephone me-1"></i>${escapeHtml(conv.agent_phone)}`;
            if (chatInput) chatInput.placeholder = `Write a message to ${conv.agent_name}...`;
            if (trackingBtnEl) trackingBtnEl.style.display = 'inline-flex';
        } else {
            activeTxId = 0;
            activeAgentId = 0;
            activeAgentName = 'KingQuiry Support';
            activePropertyTitle = '';

            if (avatarEl) avatarEl.textContent = '🤖';
            if (agentNameEl) agentNameEl.textContent = 'KingQuiry Assistant';
            if (roleBadgeEl) roleBadgeEl.textContent = 'Support Staff';
            if (regardingEl) regardingEl.textContent = 'General Inquiry & Property Assistance';
            if (channelEmailEl) channelEmailEl.innerHTML = `<i class="bi bi-envelope me-1"></i>contact@kingsland.ph`;
            if (channelPhoneEl) channelPhoneEl.innerHTML = `<i class="bi bi-telephone me-1"></i>0965 803 0933`;
            if (chatInput) chatInput.placeholder = 'Type your message...';
            if (trackingBtnEl) trackingBtnEl.style.display = 'none';
        }

        renderConversationsList();
    }

    function appendMessage(message) {
        const messageId = Number(message.message_id);
        if (renderedMessageIds.has(messageId)) return;
        renderedMessageIds.add(messageId);

        // Remove loading indicator if present
        const loadingEl = document.getElementById('kingquiry-chat-loading');
        if (loadingEl) loadingEl.remove();

        const isClient = message.sender === 'client';
        const isBot = message.sender === 'bot';
        const timeStr = message.created_at ? new Date(message.created_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) : '';

        const row = document.createElement('div');
        row.className = `d-flex mb-2 ${isClient ? 'justify-content-end' : 'justify-content-start'}`;

        if (!isClient) {
            // Agent / Bot bubble on left with avatar
            row.innerHTML = `
                <div class="d-flex align-items-start gap-2" style="max-width: 88%;">
                    <div class="rounded-circle d-flex align-items-center justify-content-center bg-primary text-white shadow-sm flex-shrink-0 mt-1" style="width: 32px; height: 32px; font-size: 0.95rem;">
                        ${isBot ? '🤖' : '👨‍💼'}
                    </div>
                    <div>
                        <div class="d-flex align-items-center gap-2 mb-1 px-1">
                            <strong class="small text-truncate" style="font-size: 0.76rem;">${escapeHtml(isBot ? 'Assistant' : (message.agent_name || activeAgentName))}</strong>
                            <span class="badge ${isBot ? 'text-bg-light border' : 'text-bg-primary-subtle text-primary border'} small" style="font-size: 0.62rem;">${isBot ? 'Automated' : 'Assigned Sales Agent'}</span>
                            <span class="text-muted small" style="font-size: 0.68rem;">${timeStr}</span>
                        </div>
                        <div class="kq-chat-bubble-agent shadow-sm">${escapeHtml(message.body)}</div>
                    </div>
                </div>
            `;
        } else {
            // Client bubble on right
            row.innerHTML = `
                <div class="d-flex flex-column align-items-end" style="max-width: 88%;">
                    <div class="d-flex align-items-center gap-1 mb-1 px-1 text-muted small" style="font-size: 0.68rem;">
                        <span>You</span> · <span>${timeStr}</span> <i class="bi bi-check2-all text-primary ms-1"></i>
                    </div>
                    <div class="kq-chat-bubble-client shadow-sm">${escapeHtml(message.body)}</div>
                </div>
            `;
        }

        messages.appendChild(row);
        lastMessageId = Math.max(lastMessageId, messageId);
        messages.scrollTop = messages.scrollHeight;
    }

    function updateOnlineStatus(adminOnline) {
        if (!onlineBadgeEl) return;
        onlineBadgeEl.className = `badge ${adminOnline ? 'text-bg-success' : 'text-bg-secondary'} small`;
        onlineBadgeEl.innerHTML = `<i class="bi bi-circle-fill me-1" style="font-size: 0.45rem;"></i>${adminOnline ? 'Online' : 'Offline'}`;
    }

    function setPanelOpen(open) {
        panel.classList.toggle('is-open', open);
        panel.setAttribute('aria-hidden', String(!open));
        panel.inert = !open;
        launcher.setAttribute('aria-expanded', String(open));
        launcher.setAttribute('aria-label', `${open ? 'Close' : 'Open'} KingQuiry chat`);

        if (open) {
            chatInput.focus();
            if (bootstrapped) startPolling();
        } else {
            stopPolling();
        }
    }

    function startPolling() {
        if (pollTimer === null) {
            poll();
            pollTimer = window.setInterval(poll, 3500);
        }
    }

    function stopPolling() {
        if (pollTimer !== null) {
            window.clearInterval(pollTimer);
            pollTimer = null;
        }
    }

    async function getJson(url) {
        const response = await fetch(url, { credentials: 'same-origin', cache: 'no-store' });
        const data = await response.json();
        if (!response.ok) {
            throw new Error(data.error || 'Chat request failed.');
        }
        return data;
    }

    async function postJson(action, extra = {}) {
        const response = await fetch(apiUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action, csrf, after: lastMessageId, ...extra })
        });
        const data = await response.json();
        if (!response.ok) {
            throw new Error(data.error || 'Chat request failed.');
        }
        return data;
    }

    async function poll() {
        if (requestInProgress) return;
        requestInProgress = true;
        try {
            const data = await getJson(`${apiUrl}?action=poll&after=${lastMessageId}`);
            if (data.csrf) csrf = data.csrf;
            if (Array.isArray(data.messages)) {
                data.messages.forEach(appendMessage);
            }
            if (typeof data.admin_online === 'boolean') {
                updateOnlineStatus(data.admin_online);
            }
        } catch (error) {
            console.warn('Chat poll error:', error.message);
        } finally {
            requestInProgress = false;
        }
    }

    async function bootstrapChat() {
        if (bootstrapped) return;
        try {
            const data = await getJson(`${apiUrl}?action=bootstrap`);
            if (data.csrf) csrf = data.csrf;
            bootstrapped = true;
            if (Array.isArray(data.messages)) {
                data.messages.forEach(appendMessage);
            }
            if (typeof data.admin_online === 'boolean') {
                updateOnlineStatus(data.admin_online);
            }
            if (panel.classList.contains('is-open')) {
                startPolling();
            }
        } catch (error) {
            console.error('Chat bootstrap error:', error.message);
        }
    }

    async function requestAgentGreeting(conv) {
        if (!conv || !conv.transaction_id || greetedTransactions.has(conv.transaction_id)) return;
        greetedTransactions.add(conv.transaction_id);

        try {
            const data = await postJson('agent_greeting', {
                agent_name: conv.agent_name,
                property_title: conv.property_title,
                transaction_id: Number(conv.transaction_id) || 0
            });
            if (data.csrf) csrf = data.csrf;
            if (Array.isArray(data.messages)) {
                data.messages.forEach(appendMessage);
            }
            if (typeof data.admin_online === 'boolean') {
                updateOnlineStatus(data.admin_online);
            }
        } catch (err) {
            console.warn('Agent greeting error:', err);
        }
    }

    function selectConversation(transactionId) {
        const matched = conversationsData.find(c => c.transaction_id === transactionId) || null;
        updateActiveHeaderAndDetails(matched);
        if (matched) {
            requestAgentGreeting(matched);
        }
        chatInput.focus();
    }

    // Exported function for tracking button in chat header
    window.kingQuiryChatGoToTracking = function() {
        setPanelOpen(false);
        const modalEl = document.getElementById('trackingModal' + activeTxId);
        if (modalEl && typeof bootstrap !== 'undefined' && bootstrap.Modal) {
            const inst = bootstrap.Modal.getOrCreateInstance(modalEl);
            inst.show();
        } else {
            window.location.href = 'client_dashboard.php#transaction-tracking';
        }
    };

    // Public API for opening chat with specific transaction
    window.openKingQuiryChatWithTransaction = async function(transactionId) {
        // Hide any open modal
        try {
            if (typeof bootstrap !== 'undefined' && bootstrap.Modal) {
                document.querySelectorAll('.modal.show').forEach(m => {
                    const inst = bootstrap.Modal.getInstance(m) || bootstrap.Modal.getOrCreateInstance(m);
                    inst?.hide();
                });
            }
        } catch (e) {
            console.warn(e);
        }

        const matched = conversationsData.find(c => c.transaction_id === transactionId) || conversationsData[0] || null;
        updateActiveHeaderAndDetails(matched);

        setPanelOpen(true);

        if (!bootstrapped) {
            await bootstrapChat();
        }

        if (matched) {
            await requestAgentGreeting(matched);
        }

        chatInput.focus();
    };

    // Compatibility alias
    window.openChatWithAgent = async function(agentName = '', propertyTitle = '', transactionId = '', agentId = '', agentEmail = '', agentPhone = '') {
        const txNum = Number(transactionId) || 0;
        if (txNum > 0) {
            await window.openKingQuiryChatWithTransaction(txNum);
            return;
        }

        const targetAgentId = Number(agentId) || 0;
        const safeAgentName = typeof agentName === 'string' && agentName.trim() !== ''
            ? agentName.trim()
            : 'the Sales Agent';
        const safePropertyTitle = typeof propertyTitle === 'string' ? propertyTitle.trim() : '';
        const prefill = `Hi, I would like to chat with ${safeAgentName}${safePropertyTitle ? ` about ${safePropertyTitle}` : ''}.`;
        await window.openChatWidget(prefill);

        if (targetAgentId > 0) {
            updateActiveHeaderAndDetails(null);
            activeAgentId = targetAgentId;
            activeAgentName = safeAgentName;
            activePropertyTitle = safePropertyTitle;
            if (agentNameEl) agentNameEl.textContent = safeAgentName;
            if (roleBadgeEl) roleBadgeEl.textContent = 'Direct Sales Agent chat';
            if (regardingEl) regardingEl.textContent = safePropertyTitle
                ? `Regarding: ${safePropertyTitle}`
                : `Direct message to ${safeAgentName}`;
            if (channelEmailEl && typeof agentEmail === 'string' && agentEmail.trim() !== '') {
                channelEmailEl.innerHTML = `<i class="bi bi-envelope me-1"></i>${escapeHtml(agentEmail.trim())}`;
            }
            if (channelPhoneEl && typeof agentPhone === 'string' && agentPhone.trim() !== '') {
                channelPhoneEl.innerHTML = `<i class="bi bi-telephone me-1"></i>${escapeHtml(agentPhone.trim())}`;
            }
            if (chatInput) chatInput.placeholder = `Write a message to ${safeAgentName}...`;
            if (trackingBtnEl) trackingBtnEl.style.display = 'none';
            renderConversationsList();
        }
        chatInput.focus();
    };

    // Compatibility alias for generic chat opener
    window.openChatWidget = async function(prefill = '') {
        try {
            if (typeof bootstrap !== 'undefined' && bootstrap.Modal) {
                document.querySelectorAll('.modal.show').forEach(m => {
                    bootstrap.Modal.getInstance(m)?.hide();
                });
            }
        } catch (e) {}

        setPanelOpen(true);

        if (!bootstrapped) {
            await bootstrapChat();
        }

        if (typeof prefill === 'string' && prefill.trim() !== '') {
            if (!chatInput.value.trim()) {
                chatInput.value = prefill;
            }
        }
        chatInput.focus();
    };

    // Launcher click handler
    launcher.addEventListener('click', async () => {
        const isOpen = panel.classList.contains('is-open');
        setPanelOpen(!isOpen);

        if (!isOpen && !bootstrapped) {
            await bootstrapChat();
            if (activeTxId > 0) {
                const matched = conversationsData.find(c => c.transaction_id === activeTxId);
                if (matched) await requestAgentGreeting(matched);
            }
        }
    });

    document.addEventListener('click', (event) => {
        const trigger = event.target instanceof Element
            ? event.target.closest('[data-kq-chat-agent]')
            : null;
        if (!(trigger instanceof HTMLElement)) return;
        event.preventDefault();
        window.openChatWithAgent(
            trigger.dataset.kqChatAgent || '',
            trigger.dataset.kqChatProperty || '',
            '',
            trigger.dataset.kqChatAgentId || '',
            trigger.dataset.kqChatAgentEmail || '',
            trigger.dataset.kqChatAgentPhone || ''
        );
    });

    closeButton.addEventListener('click', () => {
        setPanelOpen(false);
        launcher.focus();
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && panel.classList.contains('is-open')) {
            setPanelOpen(false);
            launcher.focus();
        }
    });

    // Character counter & Strict Text-Only constraints
    chatInput.addEventListener('input', () => {
        const left = 1000 - chatInput.value.length;
        if (charCount) charCount.textContent = `${left} chars left`;
    });

    chatInput.addEventListener('drop', (e) => {
        if (e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files.length > 0) {
            e.preventDefault();
            alert('File attachments are not supported. You can only send text messages.');
        }
    });

    chatInput.addEventListener('paste', (e) => {
        if (e.clipboardData && e.clipboardData.files && e.clipboardData.files.length > 0) {
            e.preventDefault();
            alert('File attachments are not supported. You can only send text messages.');
        }
    });

    // Chat message form submission
    chatForm.addEventListener('submit', async (event) => {
        event.preventDefault();
        const message = chatInput.value.trim();
        if (!message) return;

        chatInput.disabled = true;
        sendBtn.disabled = true;

        try {
            const data = await postJson('send', {
                message,
                transaction_id: activeTxId,
                agent_id: activeAgentId
            });

            chatInput.value = '';
            if (charCount) charCount.textContent = '1000 chars left';
            if (data.csrf) csrf = data.csrf;
            if (Array.isArray(data.messages)) {
                data.messages.forEach(appendMessage);
            }
            if (typeof data.admin_online === 'boolean') {
                updateOnlineStatus(data.admin_online);
            }
        } catch (error) {
            alert(error.message || 'Could not send message.');
        } finally {
            chatInput.disabled = false;
            sendBtn.disabled = false;
            chatInput.focus();
        }
    });

    // Estimate form handler
    if (estimateForm) {
        estimateForm.addEventListener('submit', async (event) => {
            event.preventDefault();
            const formData = new FormData(estimateForm);
            const area = Number(formData.get('area'));
            const locationId = Number(formData.get('location_id'));
            estimateResult.hidden = true;
            estimateResult.textContent = '';
            estimateResult.classList.remove('alert-warning');
            estimateResult.classList.add('alert-success');

            if (!Number.isFinite(area) || area <= 0 || !Number.isInteger(locationId) || locationId <= 0) {
                estimateResult.hidden = false;
                estimateResult.classList.remove('alert-success');
                estimateResult.classList.add('alert-warning');
                estimateResult.textContent = 'Enter a valid land area and choose a city or municipality.';
                return;
            }

            estimateAction.disabled = true;
            estimateAction.textContent = 'Calculating...';

            try {
                const data = await postJson('estimate', {
                    message: 'Please estimate this property.',
                    area,
                    property_type: String(formData.get('property_type')),
                    location_id: locationId
                });
                if (data.csrf) csrf = data.csrf;
                let estimateMessage = null;
                data.messages.forEach((msg) => {
                    lastMessageId = Math.max(lastMessageId, Number(msg.message_id));
                    if (msg.sender === 'bot') {
                        estimateMessage = msg;
                    } else {
                        appendMessage(msg);
                    }
                });
                if (!estimateMessage) {
                    throw new Error('The estimate response was not returned. Please try again.');
                }
                const locationSelect = estimateForm.querySelector('[name="location_id"]');
                const locationName = locationSelect instanceof HTMLSelectElement
                    ? locationSelect.selectedOptions[0]?.textContent.trim()
                    : '';
                estimateResult.textContent = locationName
                    ? `Municipality: ${locationName}\n${estimateMessage.body}`
                    : estimateMessage.body;
                estimateResult.hidden = false;
                estimateResult.scrollIntoView({ block: 'nearest' });
            } catch (error) {
                estimateResult.classList.remove('alert-success');
                estimateResult.classList.add('alert-warning');
                estimateResult.textContent = error.message;
                estimateResult.hidden = false;
            } finally {
                estimateAction.disabled = false;
                estimateAction.textContent = 'Calculate estimate';
            }
        });
    }

    // Initialize conversation tabs and initial details
    renderConversationsList();
    if (conversationsData.length > 0) {
        updateActiveHeaderAndDetails(conversationsData[0]);
    } else {
        updateActiveHeaderAndDetails(null);
    }

    window.addEventListener('pagehide', () => {
        stopPolling();
    });
})();
</script>
