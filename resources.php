<?php
require_once __DIR__ . '/includes/auth.php';
include __DIR__ . '/includes/header.php';
?>

<section class="rounded-4 p-4 p-lg-5 mb-4 text-white" style="background: linear-gradient(125deg, #102a43, #174a70);">
    <div class="page-eyebrow text-warning">KingQuiry information center</div>
    <h1 class="display-6 fw-bold mb-2">Guides, services, and answers</h1>
    <p class="mb-0 text-white-50">Practical starting points for exploring property listings and getting help from the Kings Land Properties team.</p>
</section>

<nav class="card p-3 mb-4 shadow-sm" aria-label="Information center sections">
    <div class="d-flex flex-wrap gap-2">
        <a class="btn btn-sm btn-outline-primary" href="#buyers-guide">Buyer&apos;s Guide</a>
        <a class="btn btn-sm btn-outline-primary" href="#market-trends">Market Trends</a>
        <a class="btn btn-sm btn-outline-primary" href="#expert-advice">Expert Advice</a>
        <a class="btn btn-sm btn-outline-primary" href="#pricing-plans">Pricing</a>
        <a class="btn btn-sm btn-outline-secondary" href="about.php">About Kings Land Properties &rarr;</a>
    </div>
</nav>

<section class="card p-4 p-lg-5 mb-4" id="buyers-guide">
    <div class="page-eyebrow">Resources &amp; Guides</div>
    <h2 class="h3 fw-bold">Buyer&apos;s Guide</h2>
    <p class="text-muted">Use this checklist as a starting point. Confirm legal, tax, financing, and property-specific matters with the appropriate licensed professionals and government offices.</p>
    <ol class="mb-0">
        <li class="mb-2"><strong>Define your needs and budget.</strong> Consider intended use, location, land or floor area, and expenses beyond the asking price.</li>
        <li class="mb-2"><strong>Review the listing details.</strong> Ask the agent to confirm availability, boundaries, access, utilities, and which details are verified.</li>
        <li class="mb-2"><strong>Arrange a site visit.</strong> Use the property page to request a viewing and confirm the schedule with the assigned agent.</li>
        <li class="mb-2"><strong>Conduct independent due diligence.</strong> Have qualified professionals verify ownership, title, taxes, land use, boundaries, and any restrictions before paying or signing.</li>
        <li><strong>Keep written records.</strong> Retain agreements, receipts, official references, and the names of the people you contacted.</li>
    </ol>
</section>

<section class="card p-4 p-lg-5 mb-4" id="market-trends">
    <div class="page-eyebrow">Resources &amp; Guides</div>
    <h2 class="h3 fw-bold">Market Trends</h2>
    <div class="alert alert-info mb-3" role="status">Verified historical market data is not currently published on this site.</div>
    <p class="text-muted mb-3">Asking prices in the catalog are individual listing information and should not be treated as area-wide market averages, investment forecasts, or formal appraisals.</p>
    <a class="btn btn-outline-primary" href="index.php#all-properties">Compare current available listings</a>
</section>

<section class="card p-4 p-lg-5 mb-4" id="expert-advice">
    <div class="page-eyebrow">Resources &amp; Guides</div>
    <h2 class="h3 fw-bold">Expert Advice</h2>
    <div class="row g-3">
        <div class="col-md-4"><div class="h-100 rounded-3 bg-light p-3"><h3 class="h6 fw-bold">Verify before committing</h3><p class="small text-muted mb-0">Confirm property documents and claims independently. A listing or viewing request is not a reservation or sale agreement.</p></div></div>
        <div class="col-md-4"><div class="h-100 rounded-3 bg-light p-3"><h3 class="h6 fw-bold">Understand the full cost</h3><p class="small text-muted mb-0">Ask qualified advisers about taxes, transfer costs, registration, financing, and other expenses relevant to your transaction.</p></div></div>
        <div class="col-md-4"><div class="h-100 rounded-3 bg-light p-3"><h3 class="h6 fw-bold">Use safe payment practices</h3><p class="small text-muted mb-0">KingQuiry records payment reports for staff review but does not process transfers. Verify the recipient and retain official proof of payment.</p></div></div>
    </div>
</section>

<section class="card p-4 p-lg-5 mb-4" id="pricing-plans">
    <div class="page-eyebrow">Pricing &amp; Listings</div>
    <h2 class="h3 fw-bold">Listing prices</h2>
    <p class="text-muted">Confirmed listing prices and payment terms are not currently published here. Contact an agent for verified pricing and current availability.</p>
    <p class="mb-3">KingQuiry does not accept online plan purchases or property payments.</p>
    <a class="btn btn-primary" href="agents.php">Contact an agent</a>
</section>

<section class="card p-4 p-lg-5 mb-4 shadow-sm text-center">
    <div class="page-eyebrow">Kings Land Properties</div>
    <h2 class="h4 fw-bold mb-2">Looking for company background or FAQs?</h2>
    <p class="text-muted mb-3 mx-auto" style="max-width: 600px;">Our company background, service history, client testimonials, and frequently asked questions are located in our dedicated About Us section.</p>
    <div>
        <a class="btn btn-primary px-4 fw-semibold" href="about.php">Visit About Us section</a>
    </div>
</section>

<?php include __DIR__ . '/includes/footer.php'; ?>
