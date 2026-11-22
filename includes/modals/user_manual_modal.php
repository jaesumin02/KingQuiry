<!-- ═══════════════════ USER MANUAL & GUIDELINES MODAL ═══════════════════ -->
<div class="modal fade" id="userManualModal" tabindex="-1" aria-labelledby="userManualModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg modal-dialog-scrollable kq-manual-dialog">
        <div class="modal-content shadow-lg border-0 rounded-4 overflow-hidden">
            <div class="modal-header kq-manual-header">
                <div class="d-flex align-items-center gap-3 min-w-0">
                    <img class="kq-official-logo kq-logo-manual" src="assets/images/kings-land-properties-logo.png" alt="4 Kings Realty Services">
                    <div>
                        <div class="kq-manual-kicker">KINGQUIRY GUIDE</div>
                        <h5 class="modal-title fw-bold text-white mb-1" id="userManualModalLabel">User Manual &amp; Guidelines</h5>
                        <div class="small kq-manual-subtitle">Kings Land Properties &bull; Practical steps for clients</div>
                    </div>
                </div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body kq-manual-body">
                <p class="kq-manual-intro">Find clear guidance for property viewings, land estimates, reservations, and account access.</p>
                <ul class="nav nav-pills kq-manual-tabs" id="userManualTabs" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link active" id="manual-tripping-tab" data-bs-toggle="pill" data-bs-target="#manual-tripping" type="button" role="tab" aria-controls="manual-tripping" aria-selected="true">
                            <span class="kq-manual-tab-number">01</span><span>Property viewings</span>
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="manual-calculator-tab" data-bs-toggle="pill" data-bs-target="#manual-calculator" type="button" role="tab" aria-controls="manual-calculator" aria-selected="false">
                            <span class="kq-manual-tab-number">02</span><span>Price guide</span>
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="manual-reservation-tab" data-bs-toggle="pill" data-bs-target="#manual-reservation" type="button" role="tab" aria-controls="manual-reservation" aria-selected="false">
                            <span class="kq-manual-tab-number">03</span><span>Reservation</span>
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="manual-roles-tab" data-bs-toggle="pill" data-bs-target="#manual-roles" type="button" role="tab" aria-controls="manual-roles" aria-selected="false">
                            <span class="kq-manual-tab-number">04</span><span>Account roles</span>
                        </button>
                    </li>
                </ul>

                <div class="tab-content kq-manual-content" id="userManualTabsContent">
                    <div class="tab-pane fade show active" id="manual-tripping" role="tabpanel" aria-labelledby="manual-tripping-tab">
                        <h6 class="fw-bold mb-2">Requesting a property viewing</h6>
                        <p class="small mb-2">Kings Land Properties facilitates physical on-site inspections for all active parcels across Batangas.</p>
                        <ul class="small mb-3 ps-3">
                            <li><strong>Scheduling:</strong> To ensure dedicated Sales Agent assistance and unhurried inspections, viewing requests for the same property must be at least <strong>1 hour apart</strong>. Double-booking conflicts are automatically blocked.</li>
                            <li><strong>Approval notice:</strong> Once requested, a Sales Agent receives an immediate system notification to review the request. Check your <a href="client_dashboard.php#transaction-tracking">Client dashboard</a> for schedule status.</li>
                            <li><strong>What to bring:</strong> A valid government-issued photo ID and proof of billing for identity verification during the property viewing.</li>
                        </ul>
                    </div>

                    <div class="tab-pane fade" id="manual-calculator" role="tabpanel" aria-labelledby="manual-calculator-tab">
                        <h6 class="fw-bold mb-2">Land estimates and price breakdown</h6>
                        <p class="small mb-2">Every property listing displays an exact price per square meter (<strong>₱/sq.m.</strong>) breakdown to assist smart acquisition decisions.</p>
                        <div class="kq-manual-formula p-3 mb-3 rounded small">
                            <code>Total Land Value = Registered Area (sq.m.) × Base Price per sq.m.</code>
                        </div>
                        <ul class="small mb-0 ps-3">
                            <li><strong>Valuation Estimator:</strong> Use the interactive valuation calculator on any property detail page to compute indicative land values and down payment estimates.</li>
                            <li><strong>Down Payment Rule:</strong> Standard initial down payment is typically 20% of the total verified property price.</li>
                        </ul>
                    </div>

                    <div class="tab-pane fade" id="manual-reservation" role="tabpanel" aria-labelledby="manual-reservation-tab">
                        <h6 class="fw-bold mb-2">Reservation policy and contracts</h6>
                        <p class="small mb-2">When you decide to acquire a property, a formal reservation secures the parcel exclusively under your name.</p>
                        <div class="alert alert-warning py-3 px-3 small mb-3 border-warning">
                            <strong>7-day rule:</strong> Upon payment of the initial reservation fee, the buyer has <strong>7 calendar days</strong> to complete the down payment and submit compliance documents. Unsettled reservations are released back to the public catalog.
                        </div>
                        <h6 class="fw-bold mb-2 small">Legal pathway and turnover</h6>
                        <ul class="small mb-2 ps-3">
                            <li><strong>Installment Basis:</strong> Execution of a notarized <strong>Contract to Sell (CTS)</strong> accompanied by monthly amortizations or post-dated checks.</li>
                            <li><strong>Spot Cash / Full Payment:</strong> Execution of a <strong>Deed of Absolute Sale (DOAS)</strong> upon receipt of full payment.</li>
                            <li><strong>Transfer of Title:</strong> As standard Philippine real estate practice, title transfer fees, Documentary Stamp Tax (DST), and registration fees are <strong>shouldered by the buyer</strong> unless otherwise agreed in writing.</li>
                            <li><strong>Automatic Off-Market:</strong> Once finalized as completed, the property status automatically shifts to <em>Sold</em> and is removed from public catalog searches.</li>
                        </ul>
                    </div>

                    <div class="tab-pane fade" id="manual-roles" role="tabpanel" aria-labelledby="manual-roles-tab">
                        <h6 class="fw-bold mb-2">Account roles and support</h6>
                        <ul class="small mb-3 ps-3">
                            <li><strong>Client:</strong> Search the catalog, request property viewings, track transaction milestones, and manage account details.</li>
                            <li><strong>Sales Agent:</strong> Review client viewings, inspect face-to-face paperwork and payments, coordinate directly with clients, and advance transaction stages.</li>
                            <li><strong>System Admin:</strong> System administration, property publishing, user management, audit logging, and database backups.</li>
                        </ul>
                        <div class="small text-muted">For urgent concerns, contact Kings Land Properties at <strong>+63 965 803 0933</strong> or via our <a href="https://www.facebook.com/p/Kings-Land-Properties-61568411772451/" target="_blank" rel="noopener noreferrer">Official Facebook Page</a>.</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
