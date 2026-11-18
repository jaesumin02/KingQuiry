<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
include __DIR__ . '/includes/header.php';
?>

<section class="rounded-4 p-4 p-lg-5 mb-4 text-white" style="background: linear-gradient(125deg, #102a43, #174a70);">
    <div class="page-eyebrow text-warning">Kings Land Properties</div>
    <h1 class="display-6 fw-bold mb-2">About Kings Land Properties</h1>
    <p class="mb-0 text-white-50">Discover KingQuiry, our company background, client service scope, verified feedback, and answers to common questions.</p>
</section>

<nav class="card p-3 mb-4 shadow-sm" aria-label="About Us sections">
    <div class="d-flex flex-wrap gap-2">
        <a class="btn btn-sm btn-outline-primary" href="#company">Company Background</a>
        <a class="btn btn-sm btn-outline-primary" href="#service-history">Service History</a>
        <a class="btn btn-sm btn-outline-primary" href="#testimonials">Testimonials</a>
        <a class="btn btn-sm btn-outline-primary" href="#faqs">Frequently Asked Questions</a>
        <a class="btn btn-sm btn-outline-primary" href="agents.php">Our Team</a>
    </div>
</nav>

<section class="card p-4 p-lg-5 mb-4 shadow-sm" id="company">
    <div class="page-eyebrow">About Us</div>
    <h2 class="h3 fw-bold mb-3">Company background</h2>
    <p class="text-muted mb-0">KingQuiry is the property discovery and client-service system for Kings Land Properties. The current site presents property listings and supports client inquiries, viewing requests, and transaction progress tracking. A fuller company and group history will be published when approved details are available.</p>
</section>

<section class="card p-4 p-lg-5 mb-4 shadow-sm" id="service-history">
    <div class="page-eyebrow">About Us</div>
    <h2 class="h3 fw-bold mb-3">Service history</h2>
    <p class="text-muted mb-3">This page does not yet have verified company service-history dates or milestones to publish. The services currently represented in the system are:</p>
    <ul class="mb-0 text-muted ps-3">
        <li class="mb-2">Browsing available property listings and requesting property details.</li>
        <li class="mb-2">Submitting viewing requests and following their status.</li>
        <li class="mb-2">Contacting active sales agents through the contact details on their profiles.</li>
        <li>Following transaction updates and reporting offline payments for staff verification.</li>
    </ul>
</section>

<section class="card p-4 p-lg-5 mb-4 shadow-sm" id="testimonials">
    <div class="page-eyebrow">About Us</div>
    <h2 class="h3 fw-bold mb-3">Testimonials</h2>
    <div class="rounded-3 p-3 border" style="background: rgba(16, 42, 67, 0.04);">
        <p class="mb-1 fw-semibold">No approved testimonials have been published yet.</p>
        <p class="small text-muted mb-0">Client feedback will only be shown here after it is reviewed and approved for public display.</p>
    </div>
</section>

<section class="card p-4 p-lg-5 mb-4 shadow-sm" id="faqs">
    <div class="page-eyebrow">About Us</div>
    <h2 class="h3 fw-bold mb-3">Frequently asked questions</h2>
    <div class="accordion" id="kingquiryFaqs">
        <div class="accordion-item">
            <h3 class="accordion-header">
                <button class="accordion-button" type="button" data-bs-toggle="collapse" data-bs-target="#faqBrowse" aria-expanded="true" aria-controls="faqBrowse">
                    How do I ask about a property?
                </button>
            </h3>
            <div id="faqBrowse" class="accordion-collapse collapse show" data-bs-parent="#kingquiryFaqs">
                <div class="accordion-body">Open an available listing and submit an inquiry, or contact an active agent through the Agents page.</div>
            </div>
        </div>
        <div class="accordion-item">
            <h3 class="accordion-header">
                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faqViewing" aria-expanded="false" aria-controls="faqViewing">
                    How do I request a viewing?
                </button>
            </h3>
            <div id="faqViewing" class="accordion-collapse collapse" data-bs-parent="#kingquiryFaqs">
                <div class="accordion-body">Sign in as a client, open an available property, and submit a preferred future date and time. The request remains pending until staff updates it.</div>
            </div>
        </div>
        <div class="accordion-item">
            <h3 class="accordion-header">
                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faqPayments" aria-expanded="false" aria-controls="faqPayments">
                    Can I pay online through KingQuiry?
                </button>
            </h3>
            <div id="faqPayments" class="accordion-collapse collapse" data-bs-parent="#kingquiryFaqs">
                <div class="accordion-body">No. The system can record an offline payment report for staff verification; it does not collect, transfer, or independently verify funds.</div>
            </div>
        </div>
        <div class="accordion-item">
            <h3 class="accordion-header">
                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faqPricing" aria-expanded="false" aria-controls="faqPricing">
                    Where can I see listing plan prices?
                </button>
            </h3>
            <div id="faqPricing" class="accordion-collapse collapse" data-bs-parent="#kingquiryFaqs">
                <div class="accordion-body">Listing packages are not configured in the system yet. Contact an agent for confirmed details.</div>
            </div>
        </div>
    </div>
</section>

<section class="card p-4 p-lg-5 mb-4 shadow-sm text-center">
    <div class="page-eyebrow">Our Team</div>
    <h2 class="h4 fw-bold mb-2">Connect with our licensed agents</h2>
    <p class="text-muted mb-3 mx-auto" style="max-width: 620px;">Our dedicated team of sales agents is ready to assist you with inquiries, scheduling viewing appointments, and guiding you through your property purchase.</p>
    <div>
        <a class="btn btn-primary px-4 fw-semibold" href="agents.php">Browse agent directory</a>
    </div>
</section>

<?php include __DIR__ . '/includes/footer.php'; ?>
