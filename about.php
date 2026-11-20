<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
include __DIR__ . '/includes/header.php';
?>

<section class="rounded-4 p-4 p-lg-5 mb-4 text-white" style="background: linear-gradient(125deg, #102a43, #174a70);">
    <div class="page-eyebrow text-warning">Kings Land Properties</div>
    <h1 class="display-6 fw-bold mb-2">About Kings Land Properties</h1>
    <p class="mb-0 text-white-50">Discover KingQuiry, our company background, client services, and answers to common questions.</p>
</section>

<nav class="card p-3 mb-4 shadow-sm" aria-label="About Us sections">
    <div class="d-flex flex-wrap gap-2">
        <a class="btn btn-sm btn-outline-primary" href="#company">Company Background</a>
        <a class="btn btn-sm btn-outline-primary" href="#service-history">Service History</a>
        <a class="btn btn-sm btn-outline-primary" href="#faqs">Frequently Asked Questions</a>
        <a class="btn btn-sm btn-outline-primary" href="#team">About the Team</a>
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
        <li class="mb-2">Contacting active Sales Agents through the contact details on their profiles.</li>
        <li>Following transaction updates and reporting offline payments for staff verification.</li>
    </ul>
</section>

<?php
$faqItems = [
    [
        'id' => 'faq-search',
        'question' => 'How do I search and filter the available properties?',
        'answer' => 'Open the Properties catalog and enter a location, municipality, or keyword. You can also choose a municipality and a property type, then press Search. The catalog shows listings marked Available. Use the clear-filters link to return to the full catalog.',
    ],
    [
        'id' => 'faq-photos',
        'question' => 'Do the listing photos always show the exact property?',
        'answer' => 'Some listing photos are illustrative and may not show the exact property being offered. The image credit identifies the photo source. Ask a Sales Agent for current property-specific photos and confirm the listing details before arranging a property viewing or making a decision.',
    ],
    [
        'id' => 'faq-account',
        'question' => 'Do I need an account to ask about a property or request a viewing?',
        'answer' => 'You can browse listings without signing in. To send a property inquiry or request a viewing, create a client account and sign in first. Keep your login details private and use the account registered to you.',
    ],
    [
        'id' => 'faq-viewing',
        'question' => 'How do I request a property viewing, and when is it confirmed?',
        'answer' => 'Sign in as a Client, open a real listing marked Available, and submit a future date and time using Request a viewing. The request is initially Pending; it is not approved until a Sales Agent or Admin updates its status. Check your Client dashboard for the latest status and messages.',
    ],
    [
        'id' => 'faq-tracking',
        'question' => 'Where can I check the status of an inquiry or transaction?',
        'answer' => 'Sign in and open your Client dashboard. The transaction-tracking area shows recorded status updates for your viewing request, inquiry, or transaction. If information is unclear, contact the assigned Sales Agent using the details shown for your transaction.',
    ],
    [
        'id' => 'faq-payment',
        'question' => 'Can I pay for a property or send money through KingQuiry?',
        'answer' => 'No. KingQuiry does not collect or transfer money. It can record a payment report for staff review, but submitting a report does not mean the payment has been received or verified. Confirm the recipient, amount, and official instructions directly with authorized staff, and keep your official receipt.',
    ],
    [
        'id' => 'faq-price',
        'question' => 'Are listing prices and property estimates guaranteed?',
        'answer' => 'No. Ask a Sales Agent to confirm a real listing’s current availability and asking price. Any estimate shown by the site is indicative only, based on available listing information, and is not a formal appraisal, valuation, financing offer, or guarantee of a sale price.',
    ],
    [
        'id' => 'faq-documents',
        'question' => 'Does a listing or viewing request verify property ownership and documents?',
        'answer' => 'No. A listing and a viewing request do not confirm ownership, title, boundaries, zoning, access, taxes, or legal status. Before paying or signing anything, independently verify documents and claims with qualified legal and property professionals and the appropriate government offices.',
    ],
    [
        'id' => 'faq-agent',
        'question' => 'How do I contact a Sales Agent?',
        'answer' => 'Open Resources & Guides and choose About the Team, or visit the Sales Agent directory directly. Select View profile to see the active Sales Agent’s available contact details. Email and phone calls are handled outside KingQuiry; do not share passwords or payment credentials.',
    ],
];
?>
<section class="card p-4 p-lg-5 mb-4 shadow-sm" id="faqs">
    <div class="page-eyebrow">About Us</div>
    <h2 class="h3 fw-bold mb-3">Frequently asked questions</h2>
    <div class="accordion" id="kingquiryFaqs">
        <?php foreach ($faqItems as $faq): ?>
            <div class="accordion-item">
                <h3 class="accordion-header">
                    <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#<?= htmlspecialchars($faq['id'], ENT_QUOTES, 'UTF-8') ?>" aria-expanded="false" aria-controls="<?= htmlspecialchars($faq['id'], ENT_QUOTES, 'UTF-8') ?>">
                        <?= htmlspecialchars($faq['question'], ENT_QUOTES, 'UTF-8') ?>
                    </button>
                </h3>
                <div id="<?= htmlspecialchars($faq['id'], ENT_QUOTES, 'UTF-8') ?>" class="accordion-collapse collapse" data-bs-parent="#kingquiryFaqs">
                    <div class="accordion-body"><?= htmlspecialchars($faq['answer'], ENT_QUOTES, 'UTF-8') ?></div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</section>

<section class="card p-4 p-lg-5 mb-4 shadow-sm text-center" id="team">
    <div class="page-eyebrow">About the Team</div>
    <h2 class="h4 fw-bold mb-2">Connect with our active Sales Agents</h2>
    <p class="text-muted mb-3 mx-auto" style="max-width: 620px;">Our dedicated Sales Agent team is ready to assist you with inquiries, property viewings, and guidance through your property purchase.</p>
    <div>
        <a class="btn btn-primary px-4 fw-semibold" href="agents.php">Meet the Sales Agents</a>
    </div>
</section>

<?php include __DIR__ . '/includes/footer.php'; ?>
