# Changelog

Notable project changes are recorded here in reverse chronological order.
Dates use `YYYY-MM-DD`.

## 2026-11-06 — Admin management additions

- Administrator client management at `admin_clients.php`: create client accounts
  with a temporary password, edit contact details, reset passwords, search and
  filter by status, activate/deactivate accounts, and delete only accounts with
  no linked appointments, inquiries, transactions, or payments.
- Client profile fields `phone`, `address`, and `accountStatus` on `users`;
  inactive client accounts are blocked from signing in.
- Payments register at `admin_payments.php` backed by the new `payments` table:
  record payments against tracked transactions, verify/reject/mark pending,
  remove records, notify clients on verification, and chart six months of
  verified collections.
- Sales and transactions dashboard at `admin_sales.php`: verified sales,
  revenue and average sale, conversion, active pipeline, outstanding balances,
  stage breakdown, six-month revenue chart, payment reconciliation, and a
  searchable transaction register.
- Property listing status controls (`Available`, `Reserved`, `Sold`) on the
  admin dashboard, plus collections/client/sales summary cards, management
  shortcuts, and a **Manage** navigation dropdown.
- Shared helpers and idempotent schema setup in `includes/admin_management.php`;
  legacy migration in `database/admin_management_schema.sql`; fresh-install
  updates in `database/schema.sql`; documentation in `README.md`.
- Verification: `php -l` on all 32 PHP files, fresh-schema import, the legacy
  migration run twice, and end-to-end admin/client flows on a separate test
  database.

## 2026-11-06

<<<<<<< HEAD
- Added client-submitted payment records for any payment method, with optional
  reference numbers and staff confirmation/rejection. A confirmed payment is
  required before advancing to payment confirmation. Documented that payments
  are manually recorded and verified, not processed by an online gateway.
=======
- Added administrator client management at `admin_clients.php`: create client
  accounts with a temporary password, search and filter by status, edit contact
  details, reset passwords, and activate or deactivate accounts. Accounts with
  appointments, inquiries, transactions, or payments cannot be deleted; they
  must be deactivated so linked history is preserved.
- Added client profile fields `phone`, `address`, and `accountStatus` to
  `users`. Inactive client accounts are blocked at client login with an
  explanatory message.
- Added the `payments` table and `admin_payments.php` payment register. Staff
  can record payments tied to a tracked transaction, verify, reject, or mark
  them pending, remove records, and review six months of verified collections in
  a server-rendered SVG bar chart. Verified payments notify the client and are
  prevented from exceeding a recorded sale price.
- Added `admin_sales.php`, a sales and transaction dashboard with verified
  sales, actual and average revenue, conversion rate, active pipeline value,
  outstanding balances, stage distribution, a six-month revenue chart, payment
  reconciliation, and a searchable/filterable transaction register linked to the
  tracker and payment form.
- Added property listing status management (`Available`, `Reserved`, `Sold`)
  directly from the admin dashboard property table.
- Added collections, client, and sales-pipeline summary cards plus management
  shortcuts to the admin dashboard, and a **Manage** navigation dropdown for
  Clients, Payments, Sales & transactions, and Transaction tracking.
- Added `includes/admin_management.php` for shared helpers and idempotent schema
  setup, `database/admin_management_schema.sql` for legacy databases, and
  updated `database/schema.sql` for fresh installs.
>>>>>>> ec857e417d2253282bb2591891f1036a7cca49ba

## 2026-10-17

- Simplified customer land estimates to one context-aware action button that
  stays available beside the always-visible fields; results and errors appear
  inside the calculator without moving the chat transcript. Removed the
  redundant Customer Chat header link so the admin dashboard's Customer inbox
  shortcut is the single page entry point.
- Clarified database setup: `database/schema.sql` is the single import for a
  fresh install and already creates/seeds the location directory.
  `database/location_schema.sql` remains only for upgrading legacy databases
  that lack the structured location fields.
- Changed the Admin Customer Chat inbox into an accessible floating bubble
  with a collapsible conversation list and reply panel, so the chat remains a
  bubble on the page tested by administrators as well as on client pages.
- Added live admin business analytics with verified sale totals, an always
  visible six-month actual monthly revenue line graph, top-selling properties,
  and a separately labeled
  active-pipeline asking-price estimate. Staff must enter the verified agreed
  sale price to complete a transaction; legacy records are not assigned
  invented prices.
- Added an idempotent migration for the actual sale-price field and included it
  in the fresh-install schema. The customer chat remains a floating bubble on
  the public catalog, property details, and client dashboard. Documented that
  the comparable-listing price estimator is data-based, not a trained ML model.
- Changed the guest navigation label from "Login" to "Sign in" to match the
  English sign-in page wording.
- Made client transaction tracking easier to find with a direct dashboard link,
  a mobile-friendly vertical update timeline, a prominent latest update, and a
  clear browse-properties action when the client has no requests to track.
- Added the floating customer chat bubble to the client dashboard as well as
  the catalog and property detail pages.
- Added client-visible real-estate transaction tracking from inquiry/viewing
  through negotiation, reservation, document and payment confirmation, and
  successful completion. Staff updates are ordered, timestamped, attributed to
  the updater, and require verification notes for payment and successful
  completion. Related inquiry/viewing submissions share an open case reference.
- Added an Admin/Sales Agent transaction tracker, synchronized viewing approval
  and cancellation with the client timeline, and added privacy-safe schema and
  an existing-record backfill migration without inventing earlier history.
- Documented the tracking workflow, its manual-verification and no-payment/GPS
  limitations, and the database migration required on existing installations.
- Replaced the public chat text button with an accessible floating chat bubble,
  a responsive animated panel, keyboard dismissal, and polling only while the
  conversation panel is open. The launcher label now tracks the open/closed
  state for assistive technology.
- Added administrator controls to add verified region/province/city locations
  and deactivate/reactivate them without deleting location records referenced
  by properties. Location options now flow dynamically to listings, catalog
  filters, and estimate forms.
- Consolidated the admin location directory read into a single database query
  and clarified catalog wording for locations beyond the initial Batangas seed.
- Corrected the region-code input pattern for current browser regular-expression
  validation so the location form remains warning-free.
- Expanded setup documentation for portable installation, custom location
  setup, first-admin provisioning, privacy-safe database transfer, image files,
  CDN connectivity, and runtime requirements.
- Prevented cached login/authenticated pages from presenting stale content
  after browser Back navigation. The login page reloads when restored from the
  back-forward cache; a still-active session is redirected to its role-based
  dashboard.
- Added this README covering the current pages, roles, main features, local
  setup, database expectations, and maintenance/security notes.
- Added a user-level Copilot instruction requesting dated changelog entries for
  future code changes across workspaces.
- Added a rule-based customer chat widget on the catalog and property detail
  pages, with automatic greetings and offline replies, client conversations,
  and admin replies in a new admin inbox.
- Replaced the demo average-times-factor estimator with a shared weighted
  comparable-listing estimator and indicative +/-10% price range for the admin
  dashboard and customer chat.
- Added the SQL schema migration required by chat and updated setup and feature
  documentation.
- Applied a consistent responsive design system and refreshed catalog,
  account, admin, agent, client, and property-detail screens.
- Added a normalized Batangas municipality directory and structured property
  location fields while retaining existing free-text location values.
- Added verified land-area entry, automatic per-square-meter pricing,
  location/category-aware robust estimates, and optional validated property
  image uploads.
- Hardened authentication, account/property form validation, appointment
  requests, CSRF protection, database configuration, and the password-reset
  utility; fixed legacy `Agent` role routing.
- Added a complete fresh-install `database/schema.sql` for all current tables
  and the Batangas location directory, without copying private/local records.
- Reworked dashboard charts to aggregate all property categories and request
  statuses from the database, group unexpected legacy values, provide accurate
  counts and empty states, and work without an external chart library.

## 2026-11-06

- Improved text contrast in the transaction tracking page and admin dashboard,
  making muted labels, placeholders, analytics headings, and status text easier
  to read against their backgrounds.
- Clarified agent account setup: register the agent's name, email, and password
  through public registration (which creates a client account), then have an
  administrator assign that exact email the `Agent` role in phpMyAdmin before
  signing in through the private agent portal.
- Finalized the premium 4 KINGS luxury polish across the client, agent, and
  admin dashboards with a more cohesive dark-brand hero treatment, gold
  accenting, and a cleaner high-end portal presentation that matches the
  dedicated role-separated login flow.
- Corrected the remaining admin-only redirect to the dedicated admin portal and
  made role-based guard redirects choose the correct client, agent, or admin
  login page so the role-separated access flow remains consistent across
  protected pages.
- Refined the login and registration pages to a cleaner luxury property style
  with a stronger 4 KINGS wordmark, crown branding, dark premium backdrop, and
  simplified sign-in/sign-up labels that match the intended logo direction.
- Applied the same KINGS premium theme to the client dashboard, sales-agent
  dashboard, and admin dashboard so the overall system feels more cohesive and
  aligned with the property-brand identity.
- Added a dedicated `assets/css/style.css` and `assets/js/app.js` structure for
  reusable UI and interaction logic, and linked both through the shared header
  and footer so the styling remains consistent across pages.
- Refined the registration experience by giving the account form a cleaner
  auth-card layout and adding a password visibility toggle for easier input
  handling.
- Added the transaction tracking and client progress updates that feed the
  dashboard, agent workflow, and admin verification process with timestamped
  notes and stage validation.
- Expanded the project with a shared notification system, agent feedback
  handling, and property summary PDF export for client-facing updates and
  record sharing.
- Improved the estimator, dashboard analytics, and chat-related pricing flow so
  the land estimate and sales calculations use the same validated logic across
  the app.
- Updated the database schema to support the latest tracking, analytics, and
  chat-related fields required by the current admin, agent, and client pages.
- Refined the admin, agent, and client dashboard interfaces, including the
  login/session flow, property detail presentation, and the new compact
  estimate form behavior in the customer chat.
- Reworked the visual styling and layout to use the branded background image
  treatment while keeping the chat estimate input more compact and easier to
  use.
- Finalized the custom 4 KINGS luxury sign-in and sign-up branding so the
  authentication flow matches the same premium real-estate identity on every
  role-based screen, with a cleaner brand logo treatment, stronger dark
  contrast, and a working password visibility toggle on the registration form.
- Separated client, agent, and admin access into distinct portal sign-in
  paths so public client registration stays visible while staff-only portals
  remain private and clearly segmented for privacy and role control.

## 2026-11-04
- Added "CREATE DATABASE IF NOT EXISTS kingquiry_db" and "USE kinguiry_db;" 
  at the top of 'database/schema.sql' So that it allows you to import the SQL 
  directly without creating the "kingquiry_db" database.
- Replaced the solid-color with a background image with a semi-transparent 
  gradient overlay and added 'assets/images/' directory to store the images.
- Changed the land estimate form in the KingQuiry Assistant chat into a 
  collapsible menu to clear up the chat view.