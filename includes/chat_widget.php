<?php
$chatLocations = get_property_locations($conn);
?>
<style>
    #kingquiry-chat-launcher {
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
        transition: transform .2s ease, box-shadow .2s ease;
    }

    #kingquiry-chat-launcher:hover {
        transform: translateY(-3px);
        box-shadow: 0 12px 28px rgba(18, 47, 77, .38);
    }

    #kingquiry-chat-launcher:focus-visible {
        outline: 3px solid #d8ad63;
        outline-offset: 3px;
    }

    #kingquiry-chat-panel {
        position: fixed;
        right: max(20px, env(safe-area-inset-right));
        bottom: calc(max(20px, env(safe-area-inset-bottom)) + 76px);
        z-index: 1040;
        display: flex;
        width: min(380px, calc(100vw - 32px));
        height: min(600px, calc(100dvh - 120px));
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

    #kingquiry-chat-panel.is-open {
        opacity: 1;
        visibility: visible;
        pointer-events: auto;
        transform: translateY(0) scale(1);
    }

    #kingquiry-chat-panel .card-header {
        flex: 0 0 auto;
        border: 0;
        border-radius: 0;
    }

    #kingquiry-chat-messages {
        flex: 1 1 auto;
        min-height: 100px;
        overflow-y: auto;
        background: #f5f6f8;
        overscroll-behavior: contain;
    }

    .kingquiry-chat-message {
        max-width: 90%;
        white-space: pre-wrap;
        overflow-wrap: anywhere;
    }

    @media (max-width: 480px) {
        #kingquiry-chat-panel {
            right: 12px;
            bottom: calc(max(12px, env(safe-area-inset-bottom)) + 72px);
            width: calc(100vw - 24px);
            height: min(620px, calc(100dvh - 104px));
            max-height: calc(100vh - 104px);
        }
        #kingquiry-chat-launcher {
            right: max(16px, env(safe-area-inset-right));
            bottom: max(16px, env(safe-area-inset-bottom));
            width: 56px;
            height: 56px;
        }
    }

    @media (prefers-reduced-motion: reduce) {
        #kingquiry-chat-launcher,
        #kingquiry-chat-panel {
            transition: none;
        }
    }
</style>

<button id="kingquiry-chat-launcher" type="button" class="btn btn-primary" aria-label="Open chat with KingQuiry" title="Chat with KingQuiry" aria-expanded="false" aria-controls="kingquiry-chat-panel">
    <svg width="27" height="27" viewBox="0 0 24 24" fill="none" aria-hidden="true">
        <path d="M20 11.5a7.5 7.5 0 0 1-7.5 7.5H5l1.7-3.4A7.5 7.5 0 1 1 20 11.5Z" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
        <path d="M8 11.5h.01M12 11.5h.01M16 11.5h.01" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"/>
    </svg>
</button>

<section id="kingquiry-chat-panel" class="card border-0 flex-column" aria-label="KingQuiry customer chat" aria-hidden="true" inert>
    <header class="card-header bg-primary text-white d-flex justify-content-between align-items-center py-3 px-3">
        <div>
            <strong>KingQuiry Assistant</strong>
            <div id="kingquiry-chat-status" class="small">Connecting...</div>
        </div>
        <button id="kingquiry-chat-close" type="button" class="btn-close btn-close-white" aria-label="Minimize chat"></button>
    </header>
    <div id="kingquiry-chat-messages" class="p-3" aria-live="polite"></div>
    <div class="p-2 border-top bg-white">
        <form id="kingquiry-estimate-form" class="border rounded p-2 mb-2">
            <label class="form-label small mb-1" for="kingquiry-estimate-area">Land area (sq.m.)</label>
            <input id="kingquiry-estimate-area" class="form-control form-control-sm mb-2" type="number" name="area" min="1" max="1000000" step="any" required>
            <label class="form-label small mb-1" for="kingquiry-estimate-type">Property category</label>
            <select id="kingquiry-estimate-type" class="form-select form-select-sm mb-2" name="property_type" required>
                <option value="Residential">Residential</option>
                <option value="Commercial">Commercial</option>
                <option value="Farmlot">Farmlot</option>
                <option value="Beachlot">Beachlot</option>
            </select>
            <label class="form-label small mb-1" for="kingquiry-estimate-location">City or municipality</label>
            <select id="kingquiry-estimate-location" class="form-select form-select-sm mb-2" name="location_id" required>
                <?= property_location_options($chatLocations) ?>
            </select>
            <div id="kingquiry-estimate-result" class="alert alert-success small py-2 mb-2" role="status" aria-live="polite" hidden></div>
            <button id="kingquiry-estimate-action" type="submit" class="btn btn-sm btn-outline-primary w-100">Calculate estimate</button>
        </form>
        <form id="kingquiry-chat-form" class="input-group">
            <input id="kingquiry-chat-input" type="text" class="form-control" maxlength="1000" placeholder="Type your message..." aria-label="Your message" required>
            <button class="btn btn-primary" type="submit">Send</button>
        </form>
        <div class="text-muted small mt-2">Estimates are indicative only and are not a property appraisal.</div>
    </div>
</section>

<script>
(() => {
    const apiUrl = 'chat_api.php';
    const launcher = document.getElementById('kingquiry-chat-launcher');
    const panel = document.getElementById('kingquiry-chat-panel');
    const closeButton = document.getElementById('kingquiry-chat-close');
    const status = document.getElementById('kingquiry-chat-status');
    const messages = document.getElementById('kingquiry-chat-messages');
    const chatForm = document.getElementById('kingquiry-chat-form');
    const chatInput = document.getElementById('kingquiry-chat-input');
    const estimateForm = document.getElementById('kingquiry-estimate-form');
    const estimateAction = document.getElementById('kingquiry-estimate-action');
    const estimateResult = document.getElementById('kingquiry-estimate-result');
    let csrf = '';
    let lastMessageId = 0;
    let pollTimer = null;
    let requestInProgress = false;
    let bootstrapped = false;
    const renderedMessageIds = new Set();

    function startPolling() {
        if (pollTimer === null) {
            poll();
            pollTimer = window.setInterval(poll, 4000);
        }
    }

    function stopPolling() {
        if (pollTimer !== null) {
            window.clearInterval(pollTimer);
            pollTimer = null;
        }
    }

    function setPanelOpen(open) {
        panel.classList.toggle('is-open', open);
        panel.setAttribute('aria-hidden', String(!open));
        panel.inert = !open;
        launcher.setAttribute('aria-expanded', String(open));
        launcher.setAttribute('aria-label', `${open ? 'Close' : 'Open'} chat with KingQuiry`);

        if (open) {
            chatInput.focus();
            if (bootstrapped) startPolling();
        } else {
            stopPolling();
        }
    }

    function appendMessage(message) {
        const messageId = Number(message.message_id);
        if (renderedMessageIds.has(messageId)) return;
        renderedMessageIds.add(messageId);

        const row = document.createElement('div');
        row.className = `d-flex mb-2 ${message.sender === 'client' ? 'justify-content-end' : 'justify-content-start'}`;

        const bubble = document.createElement('div');
        bubble.className = `kingquiry-chat-message rounded px-3 py-2 ${message.sender === 'client' ? 'bg-primary text-white' : 'bg-white border'}`;
        bubble.textContent = message.body;
        row.appendChild(bubble);
        messages.appendChild(row);
        lastMessageId = Math.max(lastMessageId, messageId);
        messages.scrollTop = messages.scrollHeight;
    }

    function updateStatus(adminOnline) {
        status.textContent = adminOnline ? 'Admin is online' : 'Automated assistant is available; admin is away';
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
            data.messages.forEach(appendMessage);
            updateStatus(data.admin_online);
        } catch (error) {
            status.textContent = error.message;
        } finally {
            requestInProgress = false;
        }
    }

    launcher.addEventListener('click', async () => {
        const isOpen = launcher.getAttribute('aria-expanded') === 'true';
        setPanelOpen(!isOpen);

        if (!isOpen && !bootstrapped) {
            try {
                const data = await getJson(`${apiUrl}?action=bootstrap`);
                csrf = data.csrf;
                bootstrapped = true;
                data.messages.forEach(appendMessage);
                updateStatus(data.admin_online);
                if (launcher.getAttribute('aria-expanded') === 'true') startPolling();
            } catch (error) {
                status.textContent = error.message;
            }
        }
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

    chatForm.addEventListener('submit', async (event) => {
        event.preventDefault();
        const message = chatInput.value.trim();
        if (!message) return;

        chatInput.value = '';
        try {
            const data = await postJson('send', { message });
            data.messages.forEach(appendMessage);
            updateStatus(data.admin_online);
        } catch (error) {
            status.textContent = error.message;
        }
    });

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
        estimateAction.textContent = 'Calculating...';
        try {
            const data = await postJson('estimate', {
                message: 'Please estimate this property.',
                area,
                property_type: String(formData.get('property_type')),
                location_id: locationId
            });
            let estimateMessage = null;
            data.messages.forEach((message) => {
                lastMessageId = Math.max(lastMessageId, Number(message.message_id));
                if (message.sender === 'bot') {
                    estimateMessage = message;
                } else if (message.sender === 'admin') {
                    appendMessage(message);
                }
            });
            updateStatus(data.admin_online);
            if (!estimateMessage) {
                throw new Error('The estimate response was not returned. Please try again.');
            }
            estimateResult.textContent = estimateMessage.body;
            estimateResult.hidden = false;
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

    window.addEventListener('pagehide', () => {
        stopPolling();
    });
})();
</script>
