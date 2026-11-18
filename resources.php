<?php
require_once __DIR__ . '/includes/auth.php';
include __DIR__ . '/includes/header.php';
?>

<section class="rounded-4 p-4 p-lg-5 mb-4 text-white" style="background: linear-gradient(125deg, #102a43, #174a70);">
    <div class="page-eyebrow text-warning">KingQuiry information center</div>
    <h1 class="display-6 fw-bold mb-2">Guides, services, and answers</h1>
    <p class="mb-0 text-white-50">Practical starting points for exploring property listings and getting help from the Kings Land Properties team.</p>
</section>

<nav class="card p-3 mb-4" aria-label="Information center sections">
    <div class="d-flex flex-wrap gap-2">
        <a class="btn btn-sm btn-outline-primary" href="#buyers-guide">Buyer&apos;s Guide</a>
        <a class="btn btn-sm btn-outline-primary" href="#market-trends">Market Trends</a>
        <a class="btn btn-sm btn-outline-primary" href="#expert-advice">Expert Advice</a>
        <a class="btn btn-sm btn-outline-primary" href="#pricing-plans">Pricing</a>
        <a class="btn btn-sm btn-outline-primary" href="#company">Company</a>
        <a class="btn btn-sm btn-outline-primary" href="#service-history">Service History</a>
        <a class="btn btn-sm btn-outline-primary" href="#testimonials">Testimonials</a>
        <a class="btn btn-sm btn-outline-primary" href="#faqs">FAQs</a>
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

<section class="card p-4 p-lg-5 mb-4" id="company">
    <div class="page-eyebrow">About Us</div>
    <h2 class="h3 fw-bold">Company background</h2>
    <p class="text-muted mb-0">KingQuiry is the property discovery and client-service system for Kings Land Properties. The current site presents property listings and supports client inquiries, viewing requests, and transaction progress tracking. A fuller company and group history will be published when approved details are available.</p>
</section>

<section class="card p-4 p-lg-5 mb-4" id="service-history">
    <div class="page-eyebrow">About Us</div>
    <h2 class="h3 fw-bold">Service history</h2>
    <p class="text-muted">This page does not yet have verified company service-history dates or milestones to publish. The services currently represented in the system are:</p>
    <ul class="mb-0">
        <li>Browsing available property listings and requesting property details.</li>
        <li>Submitting viewing requests and following their status.</li>
        <li>Contacting active sales agents through the contact details on their profiles.</li>
        <li>Following transaction updates and reporting offline payments for staff verification.</li>
    </ul>
</section>

<section class="card p-4 p-lg-5 mb-4" id="testimonials">
    <div class="page-eyebrow">About Us</div>
    <h2 class="h3 fw-bold">Testimonials</h2>
    <div class="rounded-3 bg-light p-3">
        <p class="mb-1 fw-semibold">No approved testimonials have been published yet.</p>
        <p class="small text-muted mb-0">Client feedback will only be shown here after it is reviewed and approved for public display.</p>
    </div>
</section>

<section class="card p-4 p-lg-5 mb-4" id="faqs">
    <div class="page-eyebrow">About Us</div>
    <h2 class="h3 fw-bold mb-3">Frequently asked questions</h2>
    <div class="accordion" id="kingquiryFaqs">
        <div class="accordion-item">
            <h3 class="accordion-header"><button class="accordion-button" type="button" data-bs-toggle="collapse" data-bs-target="#faqBrowse" aria-expanded="true" aria-controls="faqBrowse">How do I ask about a property?</button></h3>
            <div id="faqBrowse" class="accordion-collapse collapse show" data-bs-parent="#kingquiryFaqs"><div class="accordion-body">Open an available listing and submit an inquiry, or contact an active agent through the Agents page.</div></div>
        </div>
        <div class="accordion-item">
            <h3 class="accordion-header"><button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faqViewing" aria-expanded="false" aria-controls="faqViewing">How do I request a viewing?</button></h3>
            <div id="faqViewing" class="accordion-collapse collapse" data-bs-parent="#kingquiryFaqs"><div class="accordion-body">Sign in as a client, open an available property, and submit a preferred future date and time. The request remains pending until staff updates it.</div></div>
        </div>
        <div class="accordion-item">
            <h3 class="accordion-header"><button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faqPayments" aria-expanded="false" aria-controls="faqPayments">Can I pay online through KingQuiry?</button></h3>
            <div id="faqPayments" class="accordion-collapse collapse" data-bs-parent="#kingquiryFaqs"><div class="accordion-body">No. The system can record an offline payment report for staff verification; it does not collect, transfer, or independently verify funds.</div></div>
        </div>
        <div class="accordion-item">
            <h3 class="accordion-header"><button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faqPricing" aria-expanded="false" aria-controls="faqPricing">Where can I see listing plan prices?</button></h3>
            <div id="faqPricing" class="accordion-collapse collapse" data-bs-parent="#kingquiryFaqs"><div class="accordion-body">Listing packages are not configured in the system yet. Contact an agent for confirmed details.</div></div>
        </div>
    </div>
</section>

<?php include __DIR__ . '/includes/footer.php'; ?>
