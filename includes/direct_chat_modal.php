<?php
if (!isset($currentUser) && isset($_SESSION['user'])) {
    $currentUser = $_SESSION['user'];
}
$currentRole = function_exists('normalized_user_role') ? normalized_user_role($currentUser ?? null) : ($currentUser['role'] ?? '');
$isClientUser = ($currentRole === 'Client');
$csrfToken = function_exists('csrf_token') ? csrf_token() : ((string) ($_SESSION['csrf_token'] ?? ''));
?>
<!-- Direct Client <-> Agent Messaging Modal -->
<div class="modal fade" id="directChatModal" tabindex="-1" aria-labelledby="directChatModalLabel" aria-hidden="true" data-bs-backdrop="static">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable" style="max-width: 580px; width: 95%;">
        <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden" style="min-height: 520px;">
            <div class="modal-header bg-primary text-white py-2 px-3 align-items-center">
                <div class="d-flex align-items-center gap-2 flex-grow-1 overflow-hidden">
                    <span class="rounded-circle bg-white text-primary fw-bold d-inline-flex align-items-center justify-content-center flex-shrink-0" style="width: 38px; height: 38px; font-size: 1.1rem;" id="kqChatPartnerAvatar">💬</span>
                    <div class="text-truncate">
                        <div class="d-flex align-items-center gap-2">
                            <span class="fw-bold fs-6 text-white text-truncate mb-0" id="kqChatPartnerName">Direct Message</span>
                            <span id="kqChatPartnerStatus" class="badge rounded-pill bg-success-subtle text-success border border-success-subtle d-none" style="font-size: 0.68rem; font-weight: 600;">🟢 Online</span>
                        </div>
                        <div class="small text-white-50 text-truncate" style="font-size: 0.76rem;" id="kqChatPropertyTitle">Property Reference</div>
                    </div>
                </div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close" id="kqChatModalCloseBtn"></button>
            </div>

            <div class="modal-body p-3 bg-body-tertiary d-flex flex-column" id="kqChatMessagesContainer" style="height: 380px; overflow-y: auto;">
                <div class="text-center text-muted my-auto py-4" id="kqChatLoadingSpinner">
                    <div class="spinner-border spinner-border-sm text-primary mb-2" role="status"></div>
                    <div class="small">Connecting to conversation...</div>
                </div>
                <div id="kqChatEmptyState" class="text-center text-muted my-auto py-4 d-none">
                    <div class="fs-1 mb-2">👋</div>
                    <div class="fw-bold text-dark">No messages yet</div>
                    <div class="small text-muted">Send a message below to start your conversation.</div>
                </div>
                <div id="kqChatMessagesList" class="d-flex flex-column gap-2 mt-auto"></div>
            </div>

            <div class="px-3 py-2 bg-body border-top <?= $isClientUser ? 'd-flex' : 'd-none' ?> gap-1 align-items-center flex-wrap kq-chat-quick-ask-bar" id="kqChatChipsBar">
                <span class="small text-muted me-1" style="font-size: 0.7rem;">Quick ask:</span>
                <button type="button" class="btn btn-sm btn-outline-secondary rounded-pill py-0 px-2" id="kqChipViewing" style="font-size: 0.72rem;" onclick="kqInsertSuggestion('Can we schedule a property viewing?')">📅 Viewing</button>
                <button type="button" class="btn btn-sm btn-outline-secondary rounded-pill py-0 px-2" id="kqChipPayment" style="font-size: 0.72rem;" onclick="kqInsertSuggestion('What are the payment terms and options?')">💰 Payment terms</button>
                <button type="button" class="btn btn-sm btn-outline-secondary rounded-pill py-0 px-2" id="kqChipDocs" style="font-size: 0.72rem;" onclick="kqInsertSuggestion('What documents are required to purchase?')">📄 Required docs</button>
                <button type="button" class="btn btn-sm btn-outline-secondary rounded-pill py-0 px-2" id="kqChipLocation" style="font-size: 0.72rem;" onclick="kqInsertSuggestion('Can you share location details and directions?')">📍 Location</button>
            </div>

            <div class="modal-footer p-2 bg-body border-top">
                <form id="kqDirectChatForm" class="d-flex w-100 gap-2 align-items-center m-0" onsubmit="return kqSendDirectMessage(event);">
                    <input type="hidden" id="kqChatTransactionId" value="0">
                    <input type="hidden" id="kqChatCsrfToken" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                    <input type="text" class="form-control rounded-pill px-3 shadow-none" id="kqChatMessageInput" placeholder="Type a message..." maxlength="2000" autocomplete="off" required>
                    <button type="submit" class="btn btn-primary rounded-circle d-flex align-items-center justify-content-center shadow-sm" style="width: 42px; height: 42px; flex-shrink: 0;" id="kqChatSendBtn" title="Send message" aria-label="Send message">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" style="margin-left: 2px;">
                            <path d="M2.01 21L23 12 2.01 3 2 10l15 2-15 2z"/>
                        </svg>
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<style>
#directChatModal.modal {
    z-index: 1085 !important;
}
.modal-backdrop.direct-chat-backdrop {
    z-index: 1080 !important;
}
.kq-bubble-mine {
    background: linear-gradient(135deg, #174a70 0%, #0f324e 100%) !important;
    color: #ffffff !important;
    border-radius: 16px 16px 3px 16px;
    align-self: flex-end;
    width: fit-content;
    min-width: 5.5rem;
    max-width: min(80%, 26rem);
    padding: 0.55rem 0.95rem;
    box-shadow: 0 2px 8px rgba(23, 74, 112, 0.2);
    word-break: break-word;
    font-size: 0.9rem;
    line-height: 1.45;
}
.kq-bubble-theirs {
    background: var(--bs-card-bg, #ffffff) !important;
    color: var(--bs-body-color, #1e293b) !important;
    border: 1px solid var(--bs-border-color, #e2e8f0);
    border-radius: 16px 16px 16px 3px;
    align-self: flex-start;
    width: fit-content;
    min-width: 5.5rem;
    max-width: min(80%, 26rem);
    padding: 0.55rem 0.95rem;
    box-shadow: 0 2px 6px rgba(0, 0, 0, 0.04);
    word-break: break-word;
    font-size: 0.9rem;
    line-height: 1.45;
}
#kqChatMessagesList,
.kq-chat-message-row {
    width: 100%;
}
.kq-chat-quick-ask-bar.d-flex {
    display: grid !important;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 0.4rem !important;
}
.kq-chat-quick-ask-bar > span {
    grid-column: 1 / -1;
}
.kq-chat-quick-ask-bar > button {
    width: 100%;
    min-width: 0;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
@media (max-width: 575.98px) {
    .kq-bubble-mine,
    .kq-bubble-theirs {
        max-width: 90%;
    }

    .kq-chat-quick-ask-bar.d-flex {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}
[data-bs-theme="dark"] .kq-bubble-theirs {
    background: #162838 !important;
    border-color: #24415c !important;
    color: #f1f5f9 !important;
}
</style>

<script>
(function() {
    let activeChatTxId = 0;
    let pollInterval = null;
    let heartbeatInterval = null;
    let isFetching = false;
    let knownMessageCount = 0;

    async function kqSafeJson(resp) {
        if (!resp) return null;
        try {
            const text = await resp.text();
            try {
                return JSON.parse(text);
            } catch (jsonErr) {
                const match = text.match(/\{[\s\S]*\}/);
                if (match) {
                    try {
                        return JSON.parse(match[0]);
                    } catch (e) {}
                }
                console.warn('Non-JSON response from chat API:', text);
                return null;
            }
        } catch (e) {
            console.error('Failed to read response body:', e);
            return null;
        }
    }

    window.openDirectChat = function(transactionId, propertyTitle, partnerName, hasScheduledViewing) {
        activeChatTxId = parseInt(transactionId, 10);
        if (!activeChatTxId) return;

        const modalEl = document.getElementById('directChatModal');
        if (!modalEl) return;

        document.getElementById('kqChatTransactionId').value = activeChatTxId;
        document.getElementById('kqChatPropertyTitle').textContent = propertyTitle ? ('Re: ' + propertyTitle) : 'Transaction #KQT-' + activeChatTxId;
        document.getElementById('kqChatPartnerName').textContent = partnerName || 'Sales Support';

        const statusBadge = document.getElementById('kqChatPartnerStatus');
        if (statusBadge) {
            statusBadge.classList.add('d-none');
        }

        // Set initial viewing chip visibility based on passed parameter
        const viewingChip = document.getElementById('kqChipViewing');
        if (viewingChip) {
            if (hasScheduledViewing === true) {
                viewingChip.classList.add('d-none');
            } else if (hasScheduledViewing === false) {
                viewingChip.classList.remove('d-none');
            }
        }

        const listEl = document.getElementById('kqChatMessagesList');
        listEl.innerHTML = '';
        document.getElementById('kqChatLoadingSpinner').classList.remove('d-none');
        document.getElementById('kqChatEmptyState').classList.add('d-none');
        knownMessageCount = 0;

        modalEl.style.setProperty('z-index', '1085', 'important');
        const modalInstance = bootstrap.Modal.getOrCreateInstance(modalEl);
        modalInstance.show();
        promoteDirectChatBackdrop();
        setTimeout(promoteDirectChatBackdrop, 30);

        fetchMessages();
        startPolling();
    };

    window.openPropertyChat = async function(propertyId, propertyTitle) {
        const propId = parseInt(propertyId, 10);
        if (!propId) return;

        const modalEl = document.getElementById('directChatModal');
        if (!modalEl) {
            const guestModal = document.getElementById('guestChatModal');
            if (guestModal) {
                bootstrap.Modal.getOrCreateInstance(guestModal).show();
            } else {
                window.location.href = 'login.php';
            }
            return;
        }

        // Open chat modal with connecting state while resolving the conversation
        document.getElementById('kqChatTransactionId').value = '0';
        document.getElementById('kqChatPropertyTitle').textContent = propertyTitle ? ('Re: ' + propertyTitle) : 'Connecting...';
        document.getElementById('kqChatPartnerName').textContent = 'Sales Agent';
        document.getElementById('kqChatMessagesList').innerHTML = '';
        document.getElementById('kqChatLoadingSpinner').classList.remove('d-none');
        document.getElementById('kqChatEmptyState').classList.add('d-none');

        const statusBadge = document.getElementById('kqChatPartnerStatus');
        if (statusBadge) statusBadge.classList.add('d-none');

        const modalInstance = bootstrap.Modal.getOrCreateInstance(modalEl);
        modalInstance.show();

        try {
            const resp = await fetch('chat_api.php?action=initiate_property_chat&property_id=' + propId, {
                cache: 'no-store',
                credentials: 'same-origin'
            });
            const data = await kqSafeJson(resp);
            if (data && data.ok && data.transaction_id) {
                window.openDirectChat(
                    data.transaction_id,
                    data.property_title || propertyTitle,
                    data.partner_name || 'Sales Agent',
                    data.has_scheduled_viewing
                );
            } else {
                alert((data && data.error) ? data.error : 'Could not connect with a Sales Agent for this property.');
                modalInstance.hide();
            }
        } catch (err) {
            console.error('Error starting property chat:', err);
            alert('Failed to connect with an agent. Please check your connection.');
            modalInstance.hide();
        }
    };

    function startPolling() {
        stopPolling();
        pollInterval = setInterval(fetchMessages, 3500);
        startHeartbeat();
    }

    function stopPolling() {
        if (pollInterval) {
            clearInterval(pollInterval);
            pollInterval = null;
        }
        stopHeartbeat();
    }

    function startHeartbeat() {
        stopHeartbeat();
        heartbeatInterval = setInterval(async function() {
            if (!activeChatTxId) return;
            try {
                await fetch('chat_api.php?action=heartbeat', { cache: 'no-store', credentials: 'same-origin' });
            } catch (e) {}
        }, 45000);
    }

    function stopHeartbeat() {
        if (heartbeatInterval) {
            clearInterval(heartbeatInterval);
            heartbeatInterval = null;
        }
    }

    async function fetchMessages(force = false) {
        if (!activeChatTxId || (isFetching && !force)) return;
        isFetching = true;
        try {
            const resp = await fetch('chat_api.php?action=fetch&transaction_id=' + activeChatTxId, {
                cache: 'no-store',
                credentials: 'same-origin'
            });
            if (resp.status === 401) {
                stopPolling();
                document.getElementById('kqChatLoadingSpinner').classList.add('d-none');
                const emptyEl = document.getElementById('kqChatEmptyState');
                if (emptyEl) {
                    emptyEl.innerHTML = '<div class="text-danger fw-bold mb-1">Session expired</div><div class="small text-muted mb-2">You must be signed in to view and send messages.</div><a href="login.php" class="btn btn-sm btn-primary rounded-pill px-3">Sign in</a>';
                    emptyEl.classList.remove('d-none');
                }
                return;
            }
            if (!resp.ok && resp.status !== 200) {
                document.getElementById('kqChatLoadingSpinner').classList.add('d-none');
                return;
            }
            const data = await kqSafeJson(resp);
            if (!data || !data.ok) {
                document.getElementById('kqChatLoadingSpinner').classList.add('d-none');
                return;
            }

            document.getElementById('kqChatLoadingSpinner').classList.add('d-none');

            // Quick ask suggestions should only appear in the client view
            const chipsBar = document.getElementById('kqChatChipsBar');
            if (chipsBar) {
                if (data.is_client) {
                    chipsBar.classList.remove('d-none');
                    chipsBar.classList.add('d-flex');
                } else {
                    chipsBar.classList.add('d-none');
                    chipsBar.classList.remove('d-flex');
                }
            }

            // If the property already has a scheduled viewing, hide the "Viewing" quick ask chip
            const viewingChip = document.getElementById('kqChipViewing');
            if (viewingChip) {
                const alreadyScheduled = Boolean(data.has_scheduled_viewing || (data.transaction && data.transaction.hasScheduledViewing));
                if (alreadyScheduled) {
                    viewingChip.classList.add('d-none');
                } else {
                    viewingChip.classList.remove('d-none');
                }
            }

            // Update live agent presence badge in modal header
            const statusBadge = document.getElementById('kqChatPartnerStatus');
            if (statusBadge) {
                if (data.is_client) {
                    statusBadge.classList.remove('d-none');
                    const isOnline = Boolean(data.agent_is_online || (data.agent_presence && data.agent_presence.is_online));
                    if (isOnline) {
                        statusBadge.className = 'badge rounded-pill bg-success-subtle text-success border border-success-subtle';
                        statusBadge.innerHTML = '🟢 Online';
                        statusBadge.title = 'Assigned sales agent is currently online (live conversation)';
                    } else {
                        statusBadge.className = 'badge rounded-pill bg-secondary text-white border border-light-subtle';
                        statusBadge.innerHTML = '⚪ Offline';
                        statusBadge.title = 'Assigned Sales Agent is currently away. Send a message and they will reply when available.';
                    }
                } else {
                    statusBadge.classList.add('d-none');
                }
            }

            if (data.csrf_token) {
                const tokenInput = document.getElementById('kqChatCsrfToken');
                if (tokenInput) tokenInput.value = data.csrf_token;
            }

            if (data.transaction) {
                if (data.transaction.partnerName) {
                    document.getElementById('kqChatPartnerName').textContent = data.transaction.partnerName;
                }
                if (data.transaction.propertyTitle) {
                    document.getElementById('kqChatPropertyTitle').textContent = 'Re: ' + data.transaction.propertyTitle;
                }
            }

            const messages = data.messages || [];
            const listEl = document.getElementById('kqChatMessagesList');
            const emptyEl = document.getElementById('kqChatEmptyState');

            if (messages.length === 0) {
                emptyEl.classList.remove('d-none');
                listEl.innerHTML = '';
                knownMessageCount = 0;
            } else {
                emptyEl.classList.add('d-none');
                if (messages.length !== knownMessageCount) {
                    renderMessages(messages);
                    knownMessageCount = messages.length;
                    scrollToBottom();
                }
            }
        } catch (e) {
            console.error('Direct chat fetch error:', e);
            document.getElementById('kqChatLoadingSpinner').classList.add('d-none');
        } finally {
            isFetching = false;
        }
    }

    function renderMessages(messages) {
        const listEl = document.getElementById('kqChatMessagesList');
        listEl.innerHTML = '';

        messages.forEach(function(m) {
            const wrap = document.createElement('div');
            wrap.className = 'kq-chat-message-row d-flex flex-column ' + (m.isMe ? 'align-items-end' : 'align-items-start');

            const bubble = document.createElement('div');
            bubble.className = m.isMe ? 'kq-bubble-mine' : 'kq-bubble-theirs';

            if (!m.isMe) {
                const author = document.createElement('div');
                author.className = 'small fw-bold opacity-75 mb-1 d-flex align-items-center gap-1 flex-wrap';
                author.style.fontSize = '0.72rem';

                const nameSpan = document.createElement('span');
                const senderName = String(m.senderName || '').trim();
                const senderRole = String(m.senderRole || '').trim();
                const normalizedName = senderName.toLocaleLowerCase();
                const normalizedRole = senderRole.toLocaleLowerCase();
                nameSpan.textContent = senderName && senderRole
                    && normalizedName !== normalizedRole
                    && !normalizedName.endsWith(' ' + normalizedRole)
                    ? senderName + ' (' + senderRole + ')'
                    : (senderName || senderRole || 'Sales Agent');
                author.appendChild(nameSpan);

                bubble.appendChild(author);
            }

            const text = document.createElement('div');
            text.textContent = String(m.message || '').replace(/\s*\[Automated Reply\]\s*/gi, ' ').trim();
            bubble.appendChild(text);

            const time = document.createElement('div');
            time.className = 'small opacity-50 mt-1 ' + (m.isMe ? 'text-end' : 'text-start');
            time.style.fontSize = '0.68rem';
            time.textContent = m.time;
            bubble.appendChild(time);

            wrap.appendChild(bubble);
            listEl.appendChild(wrap);
        });
    }

    function scrollToBottom() {
        const c = document.getElementById('kqChatMessagesContainer');
        if (c) {
            setTimeout(function() {
                c.scrollTop = c.scrollHeight;
            }, 60);
        }
    }

    window.kqInsertSuggestion = function(text) {
        const input = document.getElementById('kqChatMessageInput');
        if (!input) return;
        input.value = text;
        input.focus();
        window.kqSendDirectMessage();
    };

    window.kqSendDirectMessage = async function(e) {
        if (e) e.preventDefault();
        const inputEl = document.getElementById('kqChatMessageInput');
        const text = inputEl.value.trim();
        if (!text || !activeChatTxId) return false;

        const csrf = document.getElementById('kqChatCsrfToken').value;
        const sendBtn = document.getElementById('kqChatSendBtn');

        inputEl.disabled = true;
        sendBtn.disabled = true;

        try {
            const formData = new FormData();
            formData.append('action', 'send');
            formData.append('transaction_id', activeChatTxId);
            formData.append('message', text);
            formData.append('csrf_token', csrf);

            const resp = await fetch('chat_api.php', {
                method: 'POST',
                body: formData,
                credentials: 'same-origin'
            });

            const data = await kqSafeJson(resp);
            if (resp.status === 401 || (data && data.code === 'session_expired')) {
                alert('Your session has expired. Please sign in again.');
                window.location.href = 'login.php';
                return false;
            }

            if (data && data.ok) {
                inputEl.value = '';
                if (data.csrf_token) {
                    document.getElementById('kqChatCsrfToken').value = data.csrf_token;
                }
                if (data.messages && Array.isArray(data.messages)) {
                    document.getElementById('kqChatEmptyState').classList.add('d-none');
                    renderMessages(data.messages);
                    knownMessageCount = data.messages.length;
                    scrollToBottom();

                    const viewingChip = document.getElementById('kqChipViewing');
                    if (viewingChip && typeof data.has_scheduled_viewing !== 'undefined') {
                        if (data.has_scheduled_viewing) {
                            viewingChip.classList.add('d-none');
                        } else {
                            viewingChip.classList.remove('d-none');
                        }
                    }
                } else {
                    await fetchMessages(true);
                }
                return false;
            }

            if (data && data.error) {
                alert(data.error);
            } else if (resp.ok) {
                // If HTTP 200 was returned and processed, refresh messages seamlessly
                inputEl.value = '';
                await fetchMessages(true);
            } else {
                alert('Could not deliver message. Please try again.');
            }
        } catch (err) {
            console.error('Direct chat send error:', err);
            alert('Failed to send message. Please check your connection.');
        } finally {
            inputEl.disabled = false;
            sendBtn.disabled = false;
            inputEl.focus();
        }
        return false;
    };

    function promoteDirectChatBackdrop() {
        const modalEl = document.getElementById('directChatModal');
        if (!modalEl || (!modalEl.classList.contains('show') && !modalEl.classList.contains('showing'))) return;
        modalEl.style.setProperty('z-index', '1085', 'important');
        const backdrops = document.querySelectorAll('.modal-backdrop');
        if (backdrops.length > 1) {
            const topBackdrop = backdrops[backdrops.length - 1];
            topBackdrop.classList.add('direct-chat-backdrop');
            topBackdrop.style.setProperty('z-index', '1080', 'important');
        }
    }

    const chatBackdropObserver = new MutationObserver(() => {
        const modalEl = document.getElementById('directChatModal');
        if (modalEl && (modalEl.classList.contains('show') || modalEl.classList.contains('showing'))) {
            promoteDirectChatBackdrop();
        }
    });
    if (document.body) {
        chatBackdropObserver.observe(document.body, { childList: true });
    }

    document.addEventListener('DOMContentLoaded', function() {
        const modalEl = document.getElementById('directChatModal');
        if (modalEl) {
            modalEl.addEventListener('show.bs.modal', function() {
                modalEl.style.setProperty('z-index', '1085', 'important');
                promoteDirectChatBackdrop();
                setTimeout(promoteDirectChatBackdrop, 20);
                setTimeout(promoteDirectChatBackdrop, 100);
            });
            modalEl.addEventListener('shown.bs.modal', function() {
                modalEl.style.setProperty('z-index', '1085', 'important');
                promoteDirectChatBackdrop();
                const input = document.getElementById('kqChatMessageInput');
                if (input) input.focus();
            });
            modalEl.addEventListener('hidden.bs.modal', function() {
                stopPolling();
                activeChatTxId = 0;
                // If another modal (e.g. trackingModal or recordNextModal) is still open, keep body scroll-locked for it
                const anyOpenModal = document.querySelector('.modal.show:not(#directChatModal)');
                if (anyOpenModal) {
                    document.body.classList.add('modal-open');
                    anyOpenModal.style.overflowY = 'auto';
                }
            });
        }
    });
})();
</script>
