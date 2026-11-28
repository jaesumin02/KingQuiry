<?php
// Chat Picker & Guidance Modals
$navClientTransactions = $navClientTransactions ?? [];
?>
<!-- Modal for Clients with Multiple Active Transactions -->
<div class="modal fade" id="clientChatPickerModal" tabindex="-1" aria-labelledby="clientChatPickerModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" style="max-width: 540px;">
        <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
            <div class="modal-header bg-primary text-white py-3 px-4">
                <div class="d-flex align-items-center gap-2">
                    <span class="fs-5">💬</span>
                    <div>
                        <h5 class="modal-title h6 mb-0 text-white" id="clientChatPickerModalLabel">Chat with an Assigned Sales Agent</h5>
                        <div class="small text-white-50" style="font-size: 0.78rem;">Choose which property request you want to discuss:</div>
                    </div>
                </div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-3 p-sm-4 bg-body-tertiary">
                <div class="d-flex flex-column gap-2">
                    <?php foreach ($navClientTransactions as $tx):
                        $txId = (int) $tx['transactionID'];
                        $propTitle = (string) $tx['title'];
                        $agentName = (string) $tx['agentName'];
                        $propImage = basename((string) ($tx['image'] ?? ''));
                        if ($propImage === '' || $propImage === 'default.jpg' || !is_file(__DIR__ . '/../../assets/images/' . $propImage)) {
                            $propImage = function_exists('property_category_image') ? (property_category_image((string) ($tx['propertyType'] ?? '')) ?? '') : '';
                        }
                        $hasPropImage = $propImage !== '' && is_file(__DIR__ . '/../../assets/images/' . $propImage);
                        $propImgSrc = $hasPropImage ? ('assets/images/' . rawurlencode($propImage)) : 'assets/images/residential-property-01.jpg';
                    ?>
                        <div class="card p-3 border shadow-sm rounded-3 bg-body">
                            <div class="d-flex align-items-center justify-content-between gap-3">
                                <div class="d-flex align-items-center gap-3 overflow-hidden">
                                    <div class="rounded-3 overflow-hidden flex-shrink-0 border" style="width: 50px; height: 50px;">
                                        <img src="<?= htmlspecialchars($propImgSrc, ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($propTitle, ENT_QUOTES, 'UTF-8') ?>" class="w-100 h-100" style="object-fit: cover;">
                                    </div>
                                    <div class="overflow-hidden">
                                        <strong class="d-block text-truncate text-dark" style="font-size: 0.92rem;"><?= htmlspecialchars($propTitle, ENT_QUOTES, 'UTF-8') ?></strong>
                                        <div class="small text-muted text-truncate" style="font-size: 0.78rem;">
                                            <span>#KQT-<?= $txId ?></span> &bull; <span>Sales Agent: <strong><?= htmlspecialchars($agentName, ENT_QUOTES, 'UTF-8') ?></strong></span>
                                        </div>
                                    </div>
                                </div>
                                <button type="button" class="btn btn-sm btn-primary fw-semibold text-nowrap px-3 shadow-sm flex-shrink-0" onclick="const modal = bootstrap.Modal.getInstance(document.getElementById('clientChatPickerModal')); if (modal) modal.hide(); openDirectChat(<?= $txId ?>, '<?= addslashes(htmlspecialchars($propTitle, ENT_QUOTES, 'UTF-8')) ?>', '<?= addslashes(htmlspecialchars($agentName, ENT_QUOTES, 'UTF-8')) ?>');">
                                    <span>💬 Chat</span>
                                </button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal for Clients with 0 Active Transactions -->
<div class="modal fade" id="noAgentChatModal" tabindex="-1" aria-labelledby="noAgentChatModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" style="max-width: 480px;">
        <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
            <div class="modal-header bg-primary text-white py-3 px-4">
                <div class="d-flex align-items-center gap-2">
                    <span class="fs-5">💬</span>
                    <h5 class="modal-title h6 mb-0 text-white" id="noAgentChatModalLabel">Direct Sales Agent Chat</h5>
                </div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4 text-center">
                <div class="fs-1 mb-3">🏡</div>
                <h6 class="fw-bold mb-2">No Active Assigned Agent Yet</h6>
                <p class="text-muted small mb-4">
                    Direct messaging is automatically enabled when you request a property viewing or submit an inquiry. An experienced Sales Agent is then assigned to assist you through every milestone.
                </p>
                <div class="d-flex flex-column gap-2">
                    <a href="index.php" class="btn btn-primary fw-semibold py-2">
                        Browse Property Catalog &rarr;
                    </a>
                    <button type="button" class="btn btn-outline-secondary py-2" data-bs-dismiss="modal" data-bs-toggle="modal" data-bs-target="#contactUsModal">
                        Contact Support Office Directly
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal for Guests / Unauthenticated Visitors -->
<div class="modal fade" id="guestChatModal" tabindex="-1" aria-labelledby="guestChatModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" style="max-width: 480px;">
        <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
            <div class="modal-header bg-primary text-white py-3 px-4">
                <div class="d-flex align-items-center gap-2">
                    <span class="fs-5">💬</span>
                    <h5 class="modal-title h6 mb-0 text-white" id="guestChatModalLabel">Chat with a Sales Agent</h5>
                </div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4 text-center">
                <div class="fs-1 mb-3">💬</div>
                <h6 class="fw-bold mb-2">Sign in to Chat with an Agent</h6>
                <p class="text-muted small mb-4">
                    Please sign in or create a KingQuiry client account to chat directly with our licensed Sales Agents, schedule tripping viewings, and track purchases.
                </p>
                <div class="d-flex flex-column gap-2">
                    <a href="login.php" class="btn btn-primary fw-semibold py-2">
                        Sign In to Your Account &rarr;
                    </a>
                    <a href="register.php" class="btn btn-outline-primary fw-semibold py-2">
                        Create Free Account
                    </a>
                    <button type="button" class="btn btn-outline-secondary py-2" data-bs-dismiss="modal" data-bs-toggle="modal" data-bs-target="#contactUsModal">
                        Reach Office via Phone or Email
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
if (typeof window.openPropertyChat !== 'function') {
    window.openPropertyChat = function(propertyId, propertyTitle) {
        const guestModal = document.getElementById('guestChatModal');
        if (guestModal) {
            bootstrap.Modal.getOrCreateInstance(guestModal).show();
        } else {
            window.location.href = 'login.php';
        }
    };
}
</script>
