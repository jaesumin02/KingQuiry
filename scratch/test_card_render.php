<?php
// Mock test to render tracking card with completed property
$stage = 'completed';
$tracking = [
    'transactionID' => 1,
    'currentStage' => 'completed',
    'reviewID' => null,
    'clientRating' => null,
    'agentName' => 'Sarah Connor',
    'agentID' => 5,
    'title' => 'Port Access Industrial & Commercial Lot',
    'salePrice' => 5000000.00,
    'paymentMethod' => 'Spot Cash',
    'appointmentDate' => '2026-11-25',
    'appointmentTime' => '16:14:00',
    'municipalityName' => 'Batangas City',
    'provinceName' => 'Batangas',
    'location' => 'Batangas City, Batangas'
];

function csrf_token() {
    return 'mock_csrf_token_12345';
}

$transactionId = (int) $tracking['transactionID'];
$isSpotCash = true;
$stageLabels = ['completed' => 'Successful / completed'];
$stageBadgeClass = 'text-bg-success';
$location = 'Batangas City, Batangas';
$progress = 100;
$stageIndex = 6;
$txProgressStages = [1, 2, 3, 4, 5, 6, 7];

ob_start();
?>
<div class="col-12" id="trackingCardCol<?= $transactionId ?>">
    <div class="card p-3 p-md-4 border shadow-sm kq-tracking-card-btn position-relative"
         data-bs-toggle="modal"
         data-bs-target="#trackingModal<?= $transactionId ?>"
         role="button"
         tabindex="0"
         aria-label="View tracking details for <?= htmlspecialchars($tracking['title'], ENT_QUOTES, 'UTF-8') ?>"
         style="cursor: pointer; border-radius: 0.85rem; transition: transform 0.15s ease, box-shadow 0.15s ease, border-color 0.15s ease;">
        
        <?php if ($stage === 'completed' && empty($tracking['reviewID'])): ?>
            <!-- TOP AGENT RATING BANNER -->
            <div class="kq-top-rating-banner p-2 px-3 rounded-3 mb-3 bg-warning-subtle border border-warning d-flex align-items-center justify-content-between flex-wrap gap-2"
                 id="topRatingBanner<?= $transactionId ?>"
                 onclick="event.stopPropagation();"
                 data-txid="<?= $transactionId ?>"
                 role="region"
                 aria-label="Rate your sales agent">
                <div class="d-flex align-items-center gap-2 flex-wrap">
                    <span class="fs-5">⭐</span>
                    <div>
                        <div class="small fw-bold text-dark">
                            Rate your sales agent (<?= htmlspecialchars($tracking['agentName'] ?? 'Assigned Agent', ENT_QUOTES, 'UTF-8') ?>):
                        </div>
                        <div class="small text-muted" style="font-size: 0.76rem;">Property purchase completed! Click 1–5 stars to rate:</div>
                    </div>
                </div>
                <div class="d-flex align-items-center gap-2">
                    <div class="kq-top-star-picker d-inline-flex gap-1"
                         data-txid="<?= $transactionId ?>"
                         data-agent="<?= htmlspecialchars($tracking['agentName'] ?? 'Assigned Agent', ENT_QUOTES, 'UTF-8') ?>"
                         data-csrf="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
                        <?php for ($s = 1; $s <= 5; $s++): ?>
                            <button type="button"
                                    class="btn p-0 border-0 bg-transparent kq-top-star-btn"
                                    data-rating="<?= $s ?>"
                                    data-txid="<?= $transactionId ?>"
                                    aria-label="Rate <?= $s ?> out of 5 stars"
                                    title="<?= $s ?> star<?= $s > 1 ? 's' : '' ?>">★</button>
                        <?php endfor; ?>
                    </div>
                    <span class="badge text-bg-light border text-muted small py-1 px-2 d-none d-sm-inline kq-top-rating-label" id="topRatingLabel<?= $transactionId ?>">
                        Click a star
                    </span>
                </div>
            </div>
        <?php endif; ?>

        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
            <div class="d-flex align-items-start gap-3">
                <div class="rounded-3 p-3 d-flex align-items-center justify-content-center bg-primary-subtle text-primary flex-shrink-0" style="width: 52px; height: 52px;">
                    <i class="bi bi-building fs-4"></i>
                </div>
                <div>
                    <div class="d-flex align-items-center gap-2 mb-1 flex-wrap">
                        <span class="small fw-bold text-primary">#KQT-<?= $transactionId ?></span>
                        <span class="badge rounded-pill <?= $stageBadgeClass ?>"><?= htmlspecialchars($stageLabels[$stage] ?? 'Status unavailable', ENT_QUOTES, 'UTF-8') ?></span>
                    </div>
                    <h3 class="h5 fw-bold mb-1 text-dark tracking-card-title"><?= htmlspecialchars($tracking['title'], ENT_QUOTES, 'UTF-8') ?></h3>
                </div>
            </div>
        </div>
    </div>
</div>
<?php
$html = ob_get_clean();

// Check if XML parser can parse it (wrapped in a root)
$doc = new DOMDocument();
libxml_use_internal_errors(true);
$loaded = $doc->loadHTML('<!DOCTYPE html><html><body>' . $html . '</body></html>');
$errors = libxml_get_errors();
libxml_clear_errors();

echo "Rendered HTML length: " . strlen($html) . " bytes\n";
echo "DOM loaded successfully: " . ($loaded ? "YES" : "NO") . "\n";
$xpath = new DOMXPath($doc);
$banners = $xpath->query('//div[contains(@class, "kq-top-rating-banner")]');
echo "Top rating banner found: " . ($banners->length === 1 ? "YES" : "NO") . "\n";
$stars = $xpath->query('//button[contains(@class, "kq-top-star-btn")]');
echo "5 Star buttons found: " . ($stars->length === 5 ? "YES" : "NO") . "\n";
