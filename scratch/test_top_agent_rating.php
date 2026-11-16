<?php
// Test verification script for top agent rating on completed transactions
$code = file_get_contents(__DIR__ . '/../client_dashboard.php');
$css = file_get_contents(__DIR__ . '/../assets/css/style.css');

$tests = [];

// 1. Verify tracking query selects reviewID, clientRating, agentName, agentID
$tests['Query includes reviewID and agentName'] = (
    strpos($code, 'ar.reviewID') !== false &&
    strpos($code, 'ar.rating AS clientRating') !== false &&
    strpos($code, 'agentName') !== false &&
    strpos($code, 'agentID') !== false
);

// 2. Verify top rating banner placed at very top of tracking card when completed and unreviewed
$tests['Top rating banner inside card'] = (
    strpos($code, '$stage === \'completed\' && empty($tracking[\'reviewID\'])') !== false &&
    strpos($code, 'kq-top-rating-banner') !== false &&
    strpos($code, 'id="topRatingBanner<?= $transactionId ?>"') !== false
);

// 3. Verify event.stopPropagation() on rating banner
$tests['Stop propagation on banner'] = (
    strpos($code, 'onclick="event.stopPropagation();"') !== false
);

// 4. Verify 1 to 5 star buttons generated with data-rating and data-txid
$tests['Star buttons generated'] = (
    strpos($code, 'class="btn p-0 border-0 bg-transparent kq-top-star-btn"') !== false &&
    strpos($code, 'data-rating="<?= $s ?>"') !== false &&
    strpos($code, 'data-txid="<?= $transactionId ?>"') !== false
);

// 5. Verify AJAX support in backend handler
$tests['AJAX review handler returns JSON'] = (
    strpos($code, 'is_ajax') !== false &&
    strpos($code, 'echo json_encode([\'success\' => true, \'message\' => \'Thanks for the feedback\'') !== false
);

// 6. Verify "Thanks for the feedback" message in JS
$tests['JS shows Thanks for the feedback'] = (
    strpos($code, 'Thanks for the feedback') !== false
);

// 7. Verify setTimeout for disappearing message
$tests['JS disappears in a few seconds'] = (
    strpos($code, 'banner.remove()') !== false &&
    strpos($code, 'setTimeout') !== false
);

// 8. Verify CSS styles for .kq-top-rating-banner and .kq-top-star-btn
$tests['CSS contains top rating styles'] = (
    strpos($css, '.kq-top-rating-banner') !== false &&
    strpos($css, '.kq-top-star-btn') !== false
);

// Output results
$allPassed = true;
echo "=== TESTING TOP AGENT RATING IMPLEMENTATION ===\n";
foreach ($tests as $name => $passed) {
    echo ($passed ? "[PASS] " : "[FAIL] ") . $name . "\n";
    if (!$passed) $allPassed = false;
}

if ($allPassed) {
    echo "\nALL 8 TESTS PASSED SUCCESSFULLY!\n";
    exit(0);
} else {
    echo "\nSOME TESTS FAILED!\n";
    exit(1);
}
