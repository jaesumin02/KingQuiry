# KingQuiry

**KingQuiry** is a PHP and MySQL property listing and inquiry system for Kings
Land Properties. It provides a public property catalog and different pages for
clients, sales agents, and administrators.

- **Last reviewed:** 2026-11-27
- **Application type:** Server-rendered PHP website
- **Database:** MySQL, accessed with PHP `mysqli`
- **Local development environment:** XAMPP
- **Runtime requirements:** PHP 8.0+ with `mysqli` and `fileinfo`;
  MySQL 8.0+ or a compatible MariaDB release

## Quick setup on another Windows/XAMPP computer

For a clean installation, the source code and `database/schema.sql` are the
complete application setup. No developer's local database or credentials are
required:

1. Install XAMPP with PHP 8+, MySQL/MariaDB, and the PHP `mysqli` and
   `fileinfo` extensions.
2. Copy the whole project folder to `C:\xampp\htdocs\kingquiry`.
3. Open the XAMPP Control Panel and start **Apache** and **MySQL**.
4. Open `http://localhost/phpmyadmin/`, create a database named
   `kingquiry_db`, select it, and import `database/schema.sql`. It creates the
   tables, Batangas locations, listings, demo accounts, appointments,
   inquiries, transaction history, and payment records in one import.
5. Open `http://localhost/kingquiry/` and sign in with the local demo
   administrator account described under [Local setup](#local-setup).
6. After this one-time setup, start Apache and MySQL in XAMPP and open
   `http://localhost/kingquiry/` to use the system.

The import includes demonstration records for local testing, including
pre-configured accounts and historical transactions. These are sample records,
not live business data. Listing photos marked representative may not depict
the exact property. Change or remove the demo accounts before any public
deployment. If the XAMPP folder or database credentials differ, update the settings in
`config/db.php` or set the documented `KINGQUIRY_DB_*` environment variables.

In development, the application initializes the schema only when the selected
database is empty and can attempt idempotent setup for missing required tables
and columns. Production mode disables request-time database/schema changes and
requires the expected schema to be present. Back up and review any existing
database before applying a migration; `database/schema.sql` is for a fresh
installation, not a versioned migration for a live database.

The complete system-analysis report, diagrams, user guide, test evidence, and
remaining stakeholder-evidence checklist are in
[`docs/KingQuiry_SAD_Documentation.md`](docs/KingQuiry_SAD_Documentation.md).

## Algorithm guide

Search the source files for `ALGORITHM:` to find these main decision and
calculation flows:

| Algorithm | Location | What it does |
| --- | --- | --- |
| Property recommendations | [`includes/ml_recommender.php`](includes/ml_recommender.php) | Ranks available listings by weighted price (40%), category (25%), municipality (20%), and area (15%) similarity; cold-start results do not claim a match percentage. |
| Property-detail calculator | [`property_detail.php`](property_detail.php) | Multiplies the entered lot area by the listing's price per square meter and shows the full-price and 20% down-payment figures. |
| Property request intake | [`property_detail.php`](property_detail.php) | Validates client submissions, prevents replay, checks listing availability, and atomically records inquiries/viewings with their tracking event. |
| Viewing schedule conflict check | [`property_detail.php`](property_detail.php) | Rejects duplicate active requests and same-property pending/approved viewings less than one hour apart. |
| Transaction stage flow | [`includes/transaction_tracking.php`](includes/transaction_tracking.php) | Defines allowed next transaction stages; non-cash payments require document verification, while Spot Cash may skip it. |
| Inventory insights | [`admin_dashboard.php`](admin_dashboard.php) | Uses fixed inquiry/listing thresholds to suggest an inventory or marketing review. |

These are the system's main algorithms and rule-based decision flows; ordinary
form validation and database CRUD operations are not listed as algorithms.

### Property inquiry and viewing request algorithm

1. A direct property-detail request returns 404 for deleted listings, sample
   listings, and statuses other than `Available` or `Sold`. Sold listings remain
   viewable as archived details but cannot accept new requests.
2. Only a signed-in client may submit a request. Each form submission must carry
   the session CSRF token and a per-property, per-action one-time token. A valid
   token is consumed only after the database transaction commits, so validation
   or database errors can be corrected and retried without accepting a replay.
3. Valid client submissions are rate limited to 10 requests per client and 30
   requests per IP address in a fixed 10-minute window. These checks occur
   before field validation, so a validly authenticated but malformed submission
   also counts toward the limit.
4. A viewing request must use a real `YYYY-MM-DD` date and `HH:MM` time in
   `Asia/Manila`, and the combined appointment must be in the future. The app
   starts a database transaction, locks the property row, and rechecks that the
   listing is still available. Only one active viewing request is allowed per
   property, regardless of client or schedule. A pending/approved request blocks
   another until its viewing is completed or the request is cancelled. Duplicate
   attempts show the existing schedule and do not create another appointment.
   The appointment and its transaction-tracking event are inserted atomically.
5. An inquiry must contain 5–3,000 valid UTF-8 code points. The app also locks
   and rechecks the property row, then inserts the inquiry and its tracking event
   in the same transaction. Both request paths roll back if either business
   record or tracking record cannot be saved.
6. Client and agent notifications are attempted only after commit. A notification
   failure does not report the already-saved inquiry/viewing as failed; the
   client is told that the request was saved and can be tracked, while the
   failure is logged. Successful submissions use a redirect-after-POST flow.

## Features

### Public catalog

- Browse property listings and filter by keyword, category, or location.
- Use the simplified shared navigation on desktop, or its three-line menu on
  narrower screens. The Home link beside the logo opens Home; Agents is under About Us > Our Team.
  Public links and account actions remain available without crowding the row.
- Signed-in users have a Dashboard link across shared pages; Contact Us and
  utility controls are separated toward the right on desktop.
- Switch between light and dark themes from the shared menu; the preference is
  saved and reused across the public, client, agent, and administrator pages.
- View featured suggestions ranked by the existing weighted recommendation
  algorithm. With no client profile or search activity, the newest listings are
  shown without claiming a match score.
- Sort properties by price (ascending or descending), alphabetical title (A to Z or Z to A), or newest listings.
- Configurable per-page viewing limits (10 properties by default, 6, 15, 24, or all) with responsive numbered pagination and next/previous page navigation.
- Open a property's details, price, price per square meter, land characteristics,
  and description.
- See optional property images from `assets/images/`.
- View the latest client appointment tracking details on the catalog when signed
  in as a client.
- Search properties by a safe, prepared keyword query, category, and the active
  locations configured by an administrator.
- Property categories are Residential, Commercial, Farm Lot, Beach Lot, and
  Condo. Listing statuses are Available, Reserved, and Sold; only Available
  listings appear in the public catalog.
- Browse active sales-agent contacts and open an individual profile with email
  and telephone links when valid contact details are available. Direct
  communication opens the user's email or phone app and is not stored in
  KingQuiry.
- Read the buyer checklist, safe property guidance, FAQs, and system/service
  scope from the information center. Market statistics, company milestones,
  testimonials, and unconfirmed listing prices are not published as facts.

### Accounts and sessions

- Register an account using a full name, email, and password. New registrations
  receive the `Client` role.
- Log in with an email address or the part of the email address before `@`.
- Passwords entered during registration are stored using PHP's
  `password_hash()` and checked with `password_verify()`.
- The signed-in user's role determines the dashboard they are sent to:
  administrator, sales agent, or client.
- A signed-in user who opens the login page is redirected to their role-based
  dashboard. Legacy `Agent` role records are treated as `Sales Agent`.
- Account management dropdown in the navbar displays the signed-in user's name,
  quick profile summary, a "Manage Account" modal/page for editing passwords
  with current password verification, and a red "Log Out" button with a
  confirmation pop-up.
- Logout clears and destroys the session.
- Login and pages that use the shared header send no-cache headers. If the
  browser restores the login page from its back-forward cache, the page reloads
  so the current session can redirect the user to the correct dashboard.

### Client

- Request a property viewing appointment with a preferred date and time.
- Send an inquiry about a property.
- Report an offline Cash, Bank Transfer, or Check payment for staff verification;
  the dashboard does not process or verify transfers.
- Track appointment status from the catalog or the client dashboard.
- Follow a dated property-request/sale timeline from inquiry or viewing request
  through contact, confirmed viewing, negotiation, reservation, document review,
  payment confirmation, and staff-confirmed successful completion.
- See the current step, tracking reference, and client-visible update notes in
  a dated vertical timeline in the client dashboard. Use the **My Tracking**
  navigation link to jump directly to it. Existing records receive an honest
  starting point; historical events from before the migration are not invented.
- Coordinate directly with assigned Sales Agents via phone and email links across active transaction records.
- Calculate a total asking price from lot area and the listing's price per
  square meter, then review the payment terms breakdown on property details.

### Sales agent

- View client inquiries and viewing/transaction requests, claim open requests,
  and work on requests assigned to the agent. Claims are checked against other
  assignments so an already-owned request cannot be taken over.
- Approve or cancel viewing requests and update the transaction through its
  permitted milestones.
- Add and manage property listings where allowed. Agent accounts are provisioned
  and maintained by an administrator.

### Administrator

- View counts for properties, registered users, appointments, and inquiries.
- View a compact operational overview on the dashboard. Use **Reports &
  Analytics** for the annual January–December actual-sales line chart; its year
  selector includes recorded years, the current year, and five future years.
  Future years are actuals-only and are not forecasts. **Sales & transactions**
  and **Payments** provide their own pipeline and collection views. Active
  pipeline value uses listing asking prices, not earned revenue.
- View property-category and appointment-status charts.
- Dashboard charts show accurate counts and empty states, group unrecognized
  legacy categories under an "Other / legacy" label, and render without a
  third-party chart service or JavaScript dependency.
- Add, deactivate, or reactivate locations using region code, region, province,
  and city/municipality fields. Active locations are shared by catalog filters,
  listing forms, and maps.
- Update client transaction progress through the dedicated transaction tracker.
  Only the next valid stage can be selected, every update is timestamped and
  attributed to the staff account. Successful completion requires a
  verification note and the actual agreed sale price; that recorded price
  automatically updates the sales analytics.
- Manage the client directory from **Clients**: create client accounts with a
  temporary password, update contact details, reset passwords, search and filter
  the directory, deactivate or reactivate accounts, and remove accounts that
  have no appointments, inquiries, transactions, or payments. Inactive clients
  cannot sign in to the client portal, and linked history is preserved by
  requiring deactivation instead of deletion.
- Manage Sales Agent accounts from **Sales Agents**: create accounts, update
  contact details, reset passwords, and deactivate/reactivate access. Existing
  agent assignments and transaction history are retained.
- Record and reconcile collections from **Payments**. Each payment is linked to
  a tracked transaction and stores its amount, type, method, reference number,
  payment date, note, and status. Staff can record pending or verified payments,
  verify or reject them later, and see six months of verified collections in a
  chart. A verified payment cannot push the total collected beyond the recorded
  sale price when one exists.
- Use **Sales & transactions** for a consolidated pipeline view: verified sales,
  actual revenue, average sale value, active pipeline, outstanding balances,
  stage distribution, six-month revenue chart, payment reconciliation, and a
  searchable transaction register with quick links to the tracker and payment
  form.
- Set each property's listing status to `Available`, `Reserved`, or `Sold`
  directly from the inventory table on the admin dashboard.
- View a compact collections, client, and sales-pipeline summary on the admin
  dashboard, alongside the existing charts, with shortcuts to the new modules.
- Add, edit, and remove property listings, with a validated type, asking price,
  land area, standardized municipality selection, and optional validated
  property image. New price-per-square-meter values are calculated from total
  asking price and land area. Existing free-text location data stays visible as
  legacy data until an administrator replaces that listing.
- Property details include a lot-area calculator based on the listing's current
  price per square meter. It displays the calculated asking price and a 20%
  down-payment figure; it is not an independent market valuation.
- Read client conversations and send replies on the Customer Chat page. The
  admin's conversations and reply form are presented inside a floating bubble
  on that page, which is opened from the single **Customer inbox** button on
  the admin dashboard. The admin is considered online while the page is open
  and its heartbeat is current.

## Project files

| Path | Purpose |
| --- | --- |
| `index.php` | Public property catalog, filters, and client appointment tracking |
| `agents.php` | Public active-agent directory and contact profiles |
| `resources.php` | Buyer guide, market-data notice, advice, pricing information, company/service scope, testimonials notice, and FAQs |
| `property_detail.php` | Property details, appointment booking, and inquiries |
| `register.php` | Client account registration |
| `login.php` | Login form, authentication, role-based redirects, and login-page cache handling |
| `logout.php` | Ends the current session and redirects to login |
| `admin_dashboard.php` | Admin overview, dynamic location directory, and property management/listing status |
| `admin_reports.php` | Annual actual-sales report, inventory, viewing, and inquiry analytics |
| `admin_clients.php` | Client directory: create and edit client accounts, reset passwords, and activate or deactivate accounts |
| `admin_agents.php` | Sales Agent accounts: create, edit, reset passwords, and activate or deactivate accounts |
| `admin_payments.php` | Payment register: record, verify, reject, and remove collections with a six-month verified-collections chart |
| `admin_sales.php` | Sales and transaction dashboard: revenue, pipeline, stage breakdown, reconciliation, and transaction register |
| `agent_dashboard.php` | Sales Agent request queue, transaction pipeline, request claiming, property listings, and viewing status updates |
| `client_dashboard.php` | Client appointment tracking and assigned agent contact |
| `transaction_tracking.php` | Admin/agent transaction-stage updates and client-visible history |
| `config/db.php` | MySQL connection settings |
| `includes/header.php` | Shared navigation, session startup, and page cache headers |
| `includes/footer.php` | Shared footer, brand identity, and Bootstrap JavaScript |
| `includes/property_images.php` | Validated property image upload and storage |
| `includes/admin_management.php` | Shared client, payment, and sales helpers, including idempotent schema setup |
| `database/schema.sql` | Single fresh-install import: schema, Batangas locations, property listings, and local demonstration records |
| `docs/KingQuiry_SAD_Documentation.md` | System-aligned project report, diagrams, user guide, test evidence, and evidence checklist |
| `CHANGELOG.md` | Dated record of project changes |

## Local setup

1. Install and start Apache and MySQL through XAMPP.
2. Create a MySQL database named `kingquiry_db`.
3. For a **new or disposable database**, select `kingquiry_db` in phpMyAdmin
   and import `database/schema.sql`. It creates all application tables and
   loads the standardized Batangas locations, 24 demonstration listings,
   accounts, appointments, inquiries, transactions, and payment records.
   **The import resets seeded application tables; never run it on a database
   containing records you need to keep.** Sign in as `admin@kingquiry.ph`
   with the demo password `Password123!`. Change or remove these accounts
   before deployment.
4. Configure the database environment variables if needed:
   `KINGQUIRY_DB_HOST`, `KINGQUIRY_DB_PORT`, `KINGQUIRY_DB_USER`,
   `KINGQUIRY_DB_PASSWORD`, and `KINGQUIRY_DB_NAME`. Local defaults are host
   `localhost`, port `3306`, user `root`, an empty password, and database
   `kingquiry_db`; set `KINGQUIRY_DB_PORT` to match your MySQL server. Do not
   use those defaults for production.
5. Put the project under XAMPP's web root and open it through the local Apache
   URL (for example, `http://localhost/kingquiry/`).
   Bootstrap styling and navigation scripts are currently loaded from a CDN,
   so the UI needs internet access unless those assets are later self-hosted.
6. For an **existing database**, do not follow older instructions that refer to
   standalone SQL migration files; those files are not present in this
   repository. Back up the database, create a staging copy, compare its actual
   schema with `database/schema.sql` and the application's required columns,
   then apply a reviewed migration appropriate to that database. Development
   mode can attempt non-production schema setup, but it is not a substitute for
   a reviewed production migration. Production mode checks the required schema
   and fails safely rather than altering the database from a web request.
   Preserve existing data and do not assign legacy free-text locations to a
   standardized municipality unless the location has been verified.

7. The import includes local administrator and Sales Agent accounts for
   demonstration. Replace the sample accounts with organization-approved
   accounts before deployment.
   Self-registered accounts receive the `Client` role.
8. Sign in with the administrator account, open **Manage locations**, and add
   any verified locations not in the initial Batangas directory. The region
   code, region name, province, and city/municipality become available
   immediately in property forms, filters, and maps. Deactivated locations
   remain attached to existing listings. To reproduce custom directory entries
   on another installation, transfer only the approved location rows through a
   reviewed SQL export; do not export personal records into the source repo.

## Status reference

| Record | Status / stage | Meaning |
| --- | --- | --- |
| Account | `Active` / `Inactive` | Active users can sign in; inactive users cannot. Admins can manage Client and Sales Agent accounts. |
| Property | `Available` / `Reserved` / `Sold` | Available listings accept new requests; Reserved listings are on hold; Sold listings are closed. |
| Viewing | `Pending` / `Approved` / `Cancelled` | Request awaiting staff action, approved schedule, or cancelled request. |
| Inquiry | `Unread` / `Read` / `Replied` | Staff handling state for a property inquiry. |
| Payment | `Pending` / `Verified` / `Rejected` | Staff review state; only verified records count as collected payments. |
| Transaction | `inquiry_received` → `contacting_client` → viewing/reservation → payment/document checks → `completed` or `cancelled` | Open client requests remain visible and claimable by an Agent, and intake stages can be advanced from the tracker. Later changes follow the permitted sequence shown there. Spot Cash may skip document verification; other payment paths require it. |

## Production deployment requirements

The XAMPP instructions above are for local coursework use, not a production
deployment. Before exposing this application publicly:

- Serve it only over HTTPS. Set `KINGQUIRY_COOKIE_SECURE=true` when TLS is
  terminated by a trusted reverse proxy and use a certificate for the public
  hostname. If traffic passes through a proxy, configure the web server to set
  `REMOTE_ADDR` from an explicit trusted-proxy allowlist; the app deliberately
  ignores arbitrary forwarded headers.
- Set `KINGQUIRY_ENV=production`, `KINGQUIRY_COOKIE_SECURE=true` when needed,
  all `KINGQUIRY_DB_*` variables, and a securely generated
  `KINGQUIRY_RATE_LIMIT_SECRET` of at least 32 characters. Production mode
  refuses the default `root`/blank-password database account and refuses to
  create a missing database or initialize an empty schema at request time.
- Use a dedicated least-privilege MySQL account. Do not deploy with XAMPP's
  default `root` account, and store database credentials in the hosting
  environment rather than source control.
- Never import the fresh-install `database/schema.sql` into an active database.
  Back up the database and apply only a reviewed, environment-specific
  migration. Production checks required tables and columns at connection time
  and never applies schema changes from web requests.
- On Apache 2.4, enable `mod_rewrite` and `mod_authz_core` and configure
  `AllowOverride` so the
  repository's `.htaccess` protection is applied. On other web servers, deny
  direct HTTP access to `config`, `database`, `includes`, `scratch`, `tests`,
  `logs`, hidden files, and SQL/log/backup files in the server configuration.
  Also prevent script execution inside `assets/images`, where uploaded property
  images are stored.
- Set up encrypted, off-site scheduled backups and test restoration. The
  browser-accessible SQL export is not a substitute for protected operational
  backups.
- Configure and test an approved outbound mail transport and set
  `KINGQUIRY_MAIL_FROM` to a verified sender address. Without it, in-app
  notifications still work, but email is not sent; production does not write
  message content or recipient addresses into a local email log.
- Configure error logs, monitoring, alerting, retention, privacy notices, and
  incident-response ownership with the hosting team. Test the deployment and
  restoration procedure with non-production data first.
- The application has inline scripts/styles and currently cannot enforce a
  strict Content Security Policy without a broader template migration. Do not
  treat the existing response headers as a complete substitute for a security
  review and deployment-specific hardening.

### Database tables used by the application

The code expects these tables and corresponding fields:

- `users`: `userID`, `fullname`, `email`, optional `phone` and `address`,
  `passwordHash`, `role`, and `accountStatus` (`Active` or `Inactive`)
- `properties`: `propertyID`, `title`, `description`, `price`, `pricePerSqm`,
  `location`, `locationID`, optional `barangay` and `areaSqm`, `propertyType`,
  `landCharacteristics`, optional `image`, and `status`
- `property_locations`: standardized region/province/municipality records
- `appointments`: `appointmentID`, `propertyID`, `clientID`, `appointmentDate`,
  `appointmentTime`, `status`
- `feedbackinquiries`: `inquiryID`, `propertyID`, `clientID`, `message`,
  `status`
- `auditlogs`: `logID`, `userID`, `action`, `timestamp`
- `chat_conversations`, `chat_messages`, and `chat_admin_presence`: customer
  conversations, their messages, and admin availability
- `property_transactions` and `property_transaction_events`: the current
  transaction stage, assigned Sales Agent, verified sale price when completed,
  and append-only timestamped updates
- `payments`: `paymentID`, optional `transactionID`, `clientID`, `amount`,
  `paymentType`, `paymentMethod`, `referenceNo`, `status` (`Pending`,
  `Verified`, or `Rejected`), `paidAt`, `note`, `recordedBy`, and timestamps

`database/schema.sql` is the portable source for a fresh database, not a backup
of an active installation. Keep real records and database credentials out of
the repository. To move an existing installation, create a private,
access-controlled backup and transfer it using a secure channel; review and
sanitize personal data before sharing or committing any export.

For an equivalent application setup on another computer, share the project
source and import `database/schema.sql` into a new or disposable database
there. The import includes sample accounts and transaction data for local
demonstration; it is not a backup of an active installation. Move live data
only with the owner's approval, a protected database backup, and a deliberate
privacy review. Also transfer approved property image files from
`assets/images/` when the listing data refers to them; do not put private
customer data or unreviewed database dumps in Git.

Existing production databases must apply the reviewed migration for
`property_transactions.assignedAgentID` and its index/foreign key before
deploying this version; production startup intentionally does not alter schema.
After a protected backup and after confirming these objects do not already
exist, the one-time migration is:

```sql
ALTER TABLE `property_transactions`
  ADD COLUMN `assignedAgentID` INT DEFAULT NULL AFTER `inquiryID`,
  ADD KEY `idx_property_transactions_agent` (`assignedAgentID`, `updatedAt`),
  ADD CONSTRAINT `fk_property_transactions_agent`
    FOREIGN KEY (`assignedAgentID`) REFERENCES `users` (`userID`) ON DELETE SET NULL;
```

The exact SQL types, indexes, constraints, and any additional columns should
match the database that is used for the project.

### Transaction tracking workflow

Clients can track an inquiry or viewing from their dashboard. If a client sends
an inquiry and books a viewing for the same property while its case is open,
the app adds both to the same tracking reference. Admins and sales agents update
the case in **Transaction Tracking**. The implemented state machine allows:

- `inquiry_received` → `contacting_client`, `viewing_requested`, or `cancelled`
- `contacting_client` → `viewing_requested` or `cancelled`
- A Client reservation action can start `reservation_requested` → `reservation_confirmed`;
  a confirmed reservation can proceed to viewing, payment processing, or cancellation.
- `viewing_requested` → `reviewing_schedule`, `viewing_confirmed`, or `cancelled`
- `reviewing_schedule` → `viewing_confirmed`, `viewing_completed`, or `cancelled`
- `viewing_confirmed` → `viewing_completed`, `payment_in_process`, or `cancelled`
- `viewing_completed` → `payment_in_process`, `document_verification`, or
  `cancelled`
- `payment_in_process` → `document_verification` or `cancelled`; Spot Cash may
  advance directly to `payment_confirmation`
- `document_verification` → `payment_confirmation` or `cancelled`
- `payment_confirmation` → `completed` or `cancelled`
- `completed` and `cancelled` are terminal states

Offer/negotiation and reservation discussions are not separate enforced stages;
staff can record those details in client-visible notes.

The transition rules reject out-of-order stage changes. Payment confirmation
requires a positive verified payment; completion requires a positive sale price,
an available non-deleted property, and verified payment coverage of that price.
Client payment reports are pending until reviewed by staff. When calculating
coverage, the tracker compares its verified payment ledger with the admin
payment register and uses the higher total rather than adding potentially
mirrored payments. Transaction updates commit before notifications are sent, so
a mail or notification failure does not roll back a recorded stage or payment.

A case may be cancelled before completion. Staff updates are manual and each
step/note is time-stamped and linked to the staff account. Marking a case
successful also requires the actual agreed sale price, which is then included
in the revenue analytics. The app does not
process payments, verify escrow/bank transfers, send receipts, or track a
physical parcel with GPS. Staff must verify the sale and its documents outside
the app before recording successful completion. If a staff note is omitted, the
tracker records a generated milestone note; any supplied note is visible to the
client. Because admin payments and tracker payments are stored in separate
ledgers, completion compares the higher verified total instead of adding both
ledgers and risking duplicate mirrored entries. Reconcile payments before
completion if they were split between the ledgers. Existing records only receive
a migration-time starting status because their previous transition history was
not stored; no old sale prices are invented.

Clients may report a payment from the transaction tracker while a case is in
payment processing, document verification, or payment confirmation. A report is
saved as pending for staff review and is protected by CSRF, a one-time session
token, and limits of 10 submissions per client and 30 per IP address in a
10-minute window. The page records an offline payment only; it does not initiate
or verify a transfer.

### Property-detail calculator

The calculator multiplies the lot area entered on a property detail page by
that listing's recorded price per square meter. It also displays the full
asking-price amount and a 20% down-payment figure. This is a listing-based
calculation, not an independent market valuation or appraisal.

### Chat behavior

The floating customer chat bubble appears on the property catalog, property
detail, and client dashboard pages for guests and clients. The current
standardized location catalog covers
Batangas municipalities and cities; barangay names are optional free text
because a complete barangay reference dataset has not been supplied. It stores
messages in the chat tables, greets new conversations, and uses fixed
rule-based responses and a live admin handoff. There is no external AI service
or API key. The admin inbox updates
the admin's online heartbeat while open; messages sent while an admin is away
receive an automatic assistant response, while messages sent with an admin
online are available for a live admin reply. Browser polling means replies are
near-real-time, not push notifications.

## Security and maintenance notes

- Property image uploads accept JPG, PNG, or WebP up to 5 MB, use random file
  names, and are stored in `assets/images/`.
- Do not publish real database credentials or passwords in the repository.
- Configure production database credentials securely and use HTTPS in
  production.

## Tests and project documentation

Run the maintained checks from the repository root:

```powershell
php tests/production_baseline_test.php
```

This test runs the repository's maintained session, transaction-rule,
recommendation, property-category, and reporting checks. The fresh-install
schema contains demonstration accounts and transaction records; use approved
isolated data for demonstrations and replace the default demo credentials
before deployment. `CHANGELOG.md` records notable implementation changes. See
[`docs/KingQuiry_SAD_Documentation.md`](docs/KingQuiry_SAD_Documentation.md)
for the system diagrams, role guide, data model, test evidence, and items that
still require organization approval.
