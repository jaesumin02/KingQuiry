# Changelog

Notable project changes are recorded here in reverse chronological order.
Dates use `YYYY-MM-DD`.

## 2026-11-09 — Property map explorer, homepage search redesign, and 5-category portfolio

- Interactive property map explorer at `property_map.php` backed by the
  `api/map_properties.php` JSON endpoint: Leaflet.js map with OpenStreetMap
  tiles, real-time municipality centering, responsive card filtering, and
  category pills for all five listing categories.
- Hoverable map markers on `property_map.php`: pin cards display property
  image, pricing, specs, and details link after a 0.5-second (500ms) hover
  delay, with an interactive popup body and instant click-to-card scrolling.
- Panoramic edge-to-edge hero on `index.php` with a brightened background image
  (overlay opacity reduced to a 28%–42% wash), updated headline "Find the right
  land for what comes next", and Kings Land Properties (Philippines) company
  identity subtitle.
- Wide 1,080px search bar on `index.php` with municipality autocomplete,
  dropdown filters, and an orange search action button; removed the verified
  medal badge and removed the embedded map so available listings display
  directly in a responsive 3-column grid.
- Expanded portfolio across the platform to five core categories:
  `Residential` (Residential Lot), `Farmlot` (Farm Lot), `Beachlot` (Beach Lot),
  `Condo` (Condo), and `Commercial` (Commercial Property) across `index.php`,
  `property_map.php`, `property_detail.php`, `admin_dashboard.php`,
  `includes/property_estimator.php`, and `includes/chat_widget.php`.
- Test properties dataset in `database/test_properties_seed.sql` containing 26
  curated listings across high-volume, low-volume, and zero-volume Batangas
  locations with dynamic `locationID` lookups, plus a 1-click web runner at
  `database/seed_runner.php` showing real-time inventory counts.
- Navigation and verification: added **Map Explorer** link to
  `includes/header.php`, updated quick access links across navigation, and
  verified `php -l` lint syntax across modified PHP files.

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