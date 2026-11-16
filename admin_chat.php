<?php
include 'config/db.php';
require_once 'includes/auth.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!user_has_role($_SESSION['user'] ?? null, 'Admin')) {
    header('Location: admin_login.php');
    exit();
}

include 'includes/header.php';
?>

<style>
    #admin-chat-launcher {
        position: fixed;
        right: max(20px, env(safe-area-inset-right));
        bottom: max(20px, env(safe-area-inset-bottom));
        z-index: 1040;
        display: grid;
        width: 60px;
        height: 60px;
        place-items: center;
        padding: 0;
        border: 0;
        border-radius: 50%;
        box-shadow: 0 8px 24px rgba(18, 47, 77, .3);
    }
    #admin-chat-launcher:focus-visible {
        outline: 3px solid #d8ad63;
        outline-offset: 3px;
    }
    #admin-chat-panel {
        position: fixed;
        right: max(20px, env(safe-area-inset-right));
        bottom: calc(max(20px, env(safe-area-inset-bottom)) + 76px);
        z-index: 1040;
        display: flex;
        width: min(480px, calc(100vw - 32px));
        height: min(680px, calc(100dvh - 120px));
        max-height: calc(100vh - 120px);
        overflow: hidden;
        border: 1px solid rgba(24, 48, 76, .12) !important;
        border-radius: 20px;
        box-shadow: 0 20px 60px rgba(18, 47, 77, .24);
        opacity: 0;
        visibility: hidden;
        pointer-events: none;
        transform: translateY(12px) scale(.98);
        transform-origin: bottom right;
        transition: opacity .18s ease, transform .18s ease, visibility .18s ease;
    }
    #admin-chat-panel.is-open {
        opacity: 1;
        visibility: visible;
        pointer-events: auto;
        transform: translateY(0) scale(1);
    }
    #admin-chat-panel .card-header { border-radius: 0; }
    #admin-chat-conversations {
        max-height: 150px;
        overflow-y: auto;
        overscroll-behavior: contain;
    }
    #admin-chat-thread {
        display: flex;
        flex: 1 1 auto;
        min-height: 0;
        flex-direction: column;
    }
    #admin-chat-messages {
        flex: 1 1 auto;
        min-height: 120px;
        overflow-y: auto;
        overscroll-behavior: contain;
    }
    @media (max-width: 480px) {
        #admin-chat-panel {
            right: 12px;
            bottom: calc(max(12px, env(safe-area-inset-bottom)) + 72px);
            width: calc(100vw - 24px);
            height: min(680px, calc(100dvh - 104px));
            max-height: calc(100vh - 104px);
        }
        #admin-chat-launcher {
            right: max(16px, env(safe-area-inset-right));
            bottom: max(16px, env(safe-area-inset-bottom));
            width: 56px;
            height: 56px;
        }
    }
    @media (prefers-reduced-motion: reduce) {
        #admin-chat-panel { transition: none; }
    }
</style>

<section class="card admin-inbox-summary p-3 p-lg-4" aria-labelledby="admin-inbox-overview-title">
    <div class="d-flex flex-column flex-sm-row justify-content-between align-items-sm-start gap-3 mb-4">
        <div>
            <div class="page-eyebrow">Customer support</div>
            <h2 class="h4 fw-bold mb-1" id="admin-inbox-overview-title">Inbox overview</h2>
            <p class="text-muted mb-0">Review open conversations, follow up on customer questions, and continue the conversation in chat.</p>
        </div>
        <button type="button" id="admin-inbox-open-chat" class="btn btn-primary flex-shrink-0" aria-controls="admin-chat-panel" aria-expanded="false">Open customer chat</button>
    </div>
    <div class="row g-3 admin-inbox-metrics mb-4">
        <div class="col-md-4">
            <div class="kq-stat-card">
                <div class="kq-stat-label">Open conversations</div>
                <div class="kq-stat-value" id="admin-inbox-open-count">&mdash;</div>
                <div class="kq-stat-sub">All currently open customer threads.</div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="kq-stat-card">
                <div class="kq-stat-label">Awaiting staff reply</div>
                <div class="kq-stat-value" id="admin-inbox-awaiting-count">&mdash;</div>
                <div class="kq-stat-sub">A client message since the latest staff reply. Automated replies do not clear this count.</div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="kq-stat-card">
                <div class="kq-stat-label">Client messages</div>
                <div class="kq-stat-value" id="admin-inbox-message-count">&mdash;</div>
                <div class="kq-stat-sub">Client messages across all open conversations.</div>
            </div>
        </div>
    </div>
    <div class="d-flex flex-column flex-sm-row justify-content-between align-items-sm-center gap-2 mb-2">
        <h3 class="h6 fw-bold mb-0">Recent conversations</h3>
        <p class="small text-muted mb-0" id="admin-inbox-status" role="status">Loading live inbox activity&hellip;</p>
    </div>
    <p class="small text-muted mb-3">The five most recently active open conversations. Select a conversation to reply.</p>
    <div class="list-group admin-inbox-activity" id="admin-inbox-activity">
        <div class="list-group-item text-muted">Loading conversations&hellip;</div>
    </div>
    <noscript><p class="text-muted mt-3 mb-0">Enable JavaScript to load live inbox activity and open customer chat.</p></noscript>
</section>

<button id="admin-chat-launcher" type="button" class="btn btn-primary" aria-label="Open customer chat" title="Open customer chat" aria-expanded="false" aria-controls="admin-chat-panel">
    <svg width="27" height="27" viewBox="0 0 24 24" fill="none" aria-hidden="true">
        <path d="M20 11.5a7.5 7.5 0 0 1-7.5 7.5H5l1.7-3.4A7.5 7.5 0 1 1 20 11.5Z" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
        <path d="M8 11.5h.01M12 11.5h.01M16 11.5h.01" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"/>
    </svg>
</button>

<section id="admin-chat-panel" class="card border-0 flex-column" aria-label="Admin customer chat inbox" aria-hidden="true" inert>
    <header class="card-header bg-primary text-white d-flex justify-content-between align-items-center gap-2 py-3 px-3">
        <div>
            <strong>Customer inbox</strong>
            <div id="admin-chat-presence" class="small">Connecting...</div>
        </div>
        <button id="admin-chat-close" type="button" class="btn-close btn-close-white" aria-label="Minimize customer chat"></button>
    </header>
    <div class="d-flex flex-column flex-grow-1 overflow-hidden p-2 gap-2">
        <div class="card shadow-sm">
            <div class="card-header fw-bold py-2">Conversations</div>
            <div id="admin-chat-conversations" class="list-group list-group-flush" aria-live="polite">
                <div class="p-3 text-muted">Loading conversations...</div>
            </div>
        </div>
        <div id="admin-chat-thread" class="card shadow-sm">
            <div id="admin-chat-title" class="card-header fw-bold py-2">Select a conversation</div>
            <div id="admin-chat-messages" class="p-3 bg-light" aria-live="polite"></div>
            <form id="admin-chat-form" class="card-footer input-group">
                <input id="admin-chat-input" class="form-control" maxlength="1000" placeholder="Write a reply..." aria-label="Reply" required disabled>
                <button id="admin-chat-send" class="btn btn-primary" type="submit" disabled>Send</button>
            </form>
            <div id="admin-chat-error" class="text-danger small px-3 pb-2" role="status"></div>
        </div>
    </div>
</section>

<script>
(() => {
    const apiUrl = 'chat_api.php';
    const launcher = document.getElementById('admin-chat-launcher');
    const openChatButton = document.getElementById('admin-inbox-open-chat');
    const panel = document.getElementById('admin-chat-panel');
    const closeButton = document.getElementById('admin-chat-close');
    const presence = document.getElementById('admin-chat-presence');
    const conversationList = document.getElementById('admin-chat-conversations');
    const title = document.getElementById('admin-chat-title');
    const messages = document.getElementById('admin-chat-messages');
    const form = document.getElementById('admin-chat-form');
    const input = document.getElementById('admin-chat-input');
    const sendButton = document.getElementById('admin-chat-send');
    const error = document.getElementById('admin-chat-error');
    const overviewStatus = document.getElementById('admin-inbox-status');
    const activity = document.getElementById('admin-inbox-activity');
    const summaryValues = {
        open_conversations: document.getElementById('admin-inbox-open-count'),
        awaiting_staff_reply: document.getElementById('admin-inbox-awaiting-count'),
        client_messages: document.getElementById('admin-inbox-message-count')
    };
    let csrf = '';
    let selectedConversation = 0;
    let lastMessageId = 0;
    let listRequestInProgress = false;
    let messageRequestInProgress = false;
    let renderedMessageIds = new Set();
    let conversationSignature = '';
    let returnFocusTarget = launcher;
    let returnFocusConversationId = '';

    function setPanelOpen(open, trigger = launcher) {
        if (open) {
            returnFocusTarget = trigger;
            returnFocusConversationId = trigger.dataset.conversationId || '';
        }
        panel.classList.toggle('is-open', open);
        panel.setAttribute('aria-hidden', String(!open));
        panel.inert = !open;
        launcher.setAttribute('aria-expanded', String(open));
        openChatButton.setAttribute('aria-expanded', String(open));
        launcher.setAttribute('aria-label', `${open ? 'Close' : 'Open'} customer chat`);
        launcher.title = `${open ? 'Close' : 'Open'} customer chat`;
        if (open) {
            if (!input.disabled) input.focus();
            else closeButton.focus();
        }
    }

    function restoreOpenerFocus() {
        // Refreshes replace recent-conversation buttons; resolve the current row by ID.
        const currentConversationButton = returnFocusConversationId
            ? [...activity.querySelectorAll('[data-conversation-id]')].find((button) => button.dataset.conversationId === returnFocusConversationId)
            : null;
        const target = currentConversationButton || (returnFocusTarget.isConnected ? returnFocusTarget : launcher);
        target.focus();
    }

    function updateSummary(summary) {
        for (const [key, element] of Object.entries(summaryValues)) {
            const count = summary?.[key];
            element.textContent = Number.isSafeInteger(count) && count >= 0 ? count.toLocaleString() : '\u2014';
        }
        overviewStatus.classList.remove('text-danger');
        overviewStatus.classList.add('text-muted');
        overviewStatus.textContent = 'Live activity \u00b7 refreshes every 5 seconds';
    }

    function showOverviewError(message) {
        Object.values(summaryValues).forEach((element) => { element.textContent = '\u2014'; });
        overviewStatus.classList.remove('text-muted');
        overviewStatus.classList.add('text-danger');
        overviewStatus.textContent = message;
        activity.replaceChildren();
        const notice = document.createElement('div');
        notice.className = 'list-group-item text-muted';
        notice.textContent = 'Inbox activity is temporarily unavailable. The next refresh will try again.';
        activity.appendChild(notice);
        // A successful retry must restore the lists even when their data has not changed.
        conversationSignature = '';
    }

    function renderActivity(conversations) {
        activity.replaceChildren();
        if (!conversations.length) {
            const empty = document.createElement('div');
            empty.className = 'list-group-item text-muted py-4';
            empty.textContent = 'No open conversations yet. New customer messages will appear here.';
            activity.appendChild(empty);
            return;
        }
        conversations.slice(0, 5).forEach((conversation) => {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'list-group-item list-group-item-action text-start py-3';
            button.dataset.conversationId = String(conversation.conversation_id);
            const customer = document.createElement('strong');
            customer.className = 'd-block';
            customer.textContent = conversation.customer_name;
            const preview = document.createElement('span');
            preview.className = 'small text-muted text-truncate d-block';
            preview.textContent = conversation.last_message || 'New conversation';
            button.append(customer, preview);
            button.addEventListener('click', () => {
                setPanelOpen(true, button);
                selectConversation(Number(conversation.conversation_id), conversation.customer_name);
            });
            activity.appendChild(button);
        });
    }

    async function request(url, options = {}) {
        const response = await fetch(url, { credentials: 'same-origin', cache: 'no-store', ...options });
        const data = await response.json();
        if (!response.ok) throw new Error(data.error || 'Chat request failed.');
        return data;
    }

    async function post(action, extra = {}) {
        return request(apiUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action, csrf, after: lastMessageId, ...extra })
        });
    }

    function updatePresence(online) {
        presence.textContent = online ? 'Admin online — clients can chat with you' : 'Admin offline';
        presence.className = `badge ${online ? 'text-bg-success' : 'text-bg-secondary'}`;
    }

    function renderMessage(message) {
        const messageId = Number(message.message_id);
        if (renderedMessageIds.has(messageId)) return;
        renderedMessageIds.add(messageId);

        const row = document.createElement('div');
        row.className = `d-flex mb-2 ${message.sender === 'admin' ? 'justify-content-end' : 'justify-content-start'}`;
        const bubble = document.createElement('div');
        bubble.className = `rounded px-3 py-2 ${message.sender === 'admin' ? 'bg-primary text-white' : 'bg-white border'}`;
        bubble.style.maxWidth = '85%';
        bubble.style.whiteSpace = 'pre-wrap';
        bubble.style.overflowWrap = 'anywhere';
        bubble.textContent = `${message.sender === 'bot' ? 'Assistant' : message.sender === 'admin' ? 'You' : 'Client'}: ${message.body}`;
        row.appendChild(bubble);
        messages.appendChild(row);
        lastMessageId = Math.max(lastMessageId, messageId);
        messages.scrollTop = messages.scrollHeight;
    }

    async function loadConversations() {
        if (listRequestInProgress) return;
        listRequestInProgress = true;
        try {
            const data = await request(`${apiUrl}?action=admin_list`);
            updatePresence(data.admin_online);
            updateSummary(data.summary);
            const signature = JSON.stringify([selectedConversation, data.conversations.map((conversation) => [
                conversation.conversation_id,
                conversation.customer_name,
                conversation.last_message,
                conversation.last_activity_at
            ])]);
            if (signature === conversationSignature) return;
            conversationSignature = signature;
            const focusedConversationId = document.activeElement?.dataset?.conversationId || '';
            const focusedInOverview = activity.contains(document.activeElement);
            conversationList.replaceChildren();
            renderActivity(data.conversations);

            if (!data.conversations.length) {
                const empty = document.createElement('div');
                empty.className = 'p-3 text-muted';
                empty.textContent = 'No conversations yet.';
                conversationList.appendChild(empty);
                return;
            }

            data.conversations.forEach((conversation) => {
                const button = document.createElement('button');
                button.type = 'button';
                button.dataset.conversationId = String(conversation.conversation_id);
                button.className = `list-group-item list-group-item-action ${Number(conversation.conversation_id) === selectedConversation ? 'active' : ''}`;
                const customer = document.createElement('strong');
                customer.className = 'd-block';
                customer.textContent = conversation.customer_name;
                const preview = document.createElement('span');
                preview.className = 'small text-truncate d-block';
                preview.textContent = conversation.last_message || 'New conversation';
                button.append(customer, preview);
                button.addEventListener('click', () => selectConversation(Number(conversation.conversation_id), conversation.customer_name));
                conversationList.appendChild(button);
            });
            if (focusedConversationId) {
                const list = focusedInOverview ? activity : conversationList;
                list.querySelector(`[data-conversation-id="${focusedConversationId}"]`)?.focus();
            }
        } catch (requestError) {
            error.textContent = requestError.message;
            showOverviewError(requestError.message);
        } finally {
            listRequestInProgress = false;
        }
    }

    async function selectConversation(conversationId, customerName) {
        selectedConversation = conversationId;
        lastMessageId = 0;
        renderedMessageIds = new Set();
        messages.replaceChildren();
        title.textContent = `Conversation with ${customerName}`;
        input.disabled = false;
        sendButton.disabled = false;
        await loadMessages();
        await loadConversations();
        if (panel.classList.contains('is-open')) input.focus();
    }

    async function loadMessages() {
        if (!selectedConversation || messageRequestInProgress) return;
        messageRequestInProgress = true;
        const conversationId = selectedConversation;
        const afterId = lastMessageId;
        try {
            const data = await request(`${apiUrl}?action=admin_messages&conversation_id=${conversationId}&after=${afterId}`);
            if (conversationId === selectedConversation) data.messages.forEach(renderMessage);
        } catch (requestError) {
            error.textContent = requestError.message;
        } finally {
            messageRequestInProgress = false;
        }
    }

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        const message = input.value.trim();
        if (!selectedConversation || !message) return;
        const conversationId = selectedConversation;
        error.textContent = '';
        input.value = '';
        try {
            const data = await post('admin_reply', { conversation_id: conversationId, message });
            if (conversationId === selectedConversation) data.messages.forEach(renderMessage);
            await loadConversations();
        } catch (requestError) {
            error.textContent = requestError.message;
        }
    });

    async function heartbeat() {
        try {
            await post('admin_heartbeat');
            updatePresence(true);
        } catch (requestError) {
            error.textContent = requestError.message;
            updatePresence(false);
        }
    }

    async function initialize() {
        try {
            const data = await request(`${apiUrl}?action=admin_bootstrap`);
            csrf = data.csrf;
            await heartbeat();
            await loadConversations();
            window.setInterval(heartbeat, 20000);
            window.setInterval(loadConversations, 5000);
            window.setInterval(loadMessages, 3000);
        } catch (requestError) {
            error.textContent = requestError.message;
            updatePresence(false);
            showOverviewError(requestError.message);
            window.setTimeout(initialize, 5000);
        }
    }

    launcher.addEventListener('click', () => {
        setPanelOpen(launcher.getAttribute('aria-expanded') !== 'true');
    });
    openChatButton.addEventListener('click', () => setPanelOpen(true, openChatButton));
    closeButton.addEventListener('click', () => {
        setPanelOpen(false);
        restoreOpenerFocus();
    });
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && launcher.getAttribute('aria-expanded') === 'true') {
            setPanelOpen(false);
            restoreOpenerFocus();
        }
    });

    initialize();
})();
</script>

<?php include 'includes/footer.php'; ?>
