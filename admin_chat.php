<?php
include 'config/db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['user']) || ($_SESSION['user']['role'] ?? '') !== 'Admin') {
    header('Location: login.php');
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

<div class="mb-4">
    <div class="page-eyebrow">Admin workspace</div>
    <h1 class="page-title h2 mb-1">Customer Chat</h1>
    <p class="text-muted mb-0">Open the floating chat bubble to reply to client questions and land-estimate requests.</p>
</div>

<button id="admin-chat-launcher" type="button" class="btn btn-primary" aria-label="Open customer chat" title="Open customer chat" aria-expanded="false" aria-controls="admin-chat-panel">
    <svg width="27" height="27" viewBox="0 0 24 24" fill="none" aria-hidden="true">
        <path d="M20 11.5a7.5 7.5 0 0 1-7.5 7.5H5l1.7-3.4A7.5 7.5 0 1 1 20 11.5Z" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
        <path d="M8 11.5h.01M12 11.5h.01M16 11.5h.01" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"/>
    </svg>
</button>

<section id="admin-chat-panel" class="card border-0 flex-column" aria-label="Admin customer chat inbox" aria-hidden="true" inert>
    <header class="card-header bg-primary text-white d-flex justify-content-between align-items-center gap-2 py-3 px-3">
        <div>
            <strong>Customer Chat</strong>
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
    let csrf = '';
    let selectedConversation = 0;
    let lastMessageId = 0;
    let listRequestInProgress = false;
    let messageRequestInProgress = false;
    let renderedMessageIds = new Set();
    let conversationSignature = '';

    function setPanelOpen(open) {
        panel.classList.toggle('is-open', open);
        panel.setAttribute('aria-hidden', String(!open));
        panel.inert = !open;
        launcher.setAttribute('aria-expanded', String(open));
        launcher.setAttribute('aria-label', `${open ? 'Close' : 'Open'} customer chat`);
        launcher.title = `${open ? 'Close' : 'Open'} customer chat`;
        if (open && !input.disabled) input.focus();
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
            const signature = JSON.stringify(data.conversations.map((conversation) => [
                conversation.conversation_id,
                conversation.customer_name,
                conversation.last_message
            ]));
            if (signature === conversationSignature) return;
            conversationSignature = signature;
            const focusedConversationId = document.activeElement?.dataset?.conversationId || '';
            conversationList.replaceChildren();

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
                conversationList.querySelector(`[data-conversation-id="${focusedConversationId}"]`)?.focus();
            }
        } catch (requestError) {
            error.textContent = requestError.message;
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
        input.focus();
    }

    async function loadMessages() {
        if (!selectedConversation || messageRequestInProgress) return;
        messageRequestInProgress = true;
        try {
            const data = await request(`${apiUrl}?action=admin_messages&conversation_id=${selectedConversation}&after=${lastMessageId}`);
            data.messages.forEach(renderMessage);
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
        error.textContent = '';
        input.value = '';
        try {
            const data = await post('admin_reply', { conversation_id: selectedConversation, message });
            data.messages.forEach(renderMessage);
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
        }
    }

    launcher.addEventListener('click', () => {
        setPanelOpen(launcher.getAttribute('aria-expanded') !== 'true');
    });
    closeButton.addEventListener('click', () => {
        setPanelOpen(false);
        launcher.focus();
    });
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && launcher.getAttribute('aria-expanded') === 'true') {
            setPanelOpen(false);
            launcher.focus();
        }
    });

    initialize();
})();
</script>

<?php include 'includes/footer.php'; ?>
