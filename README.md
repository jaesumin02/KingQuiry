# KingQuiry

**KingQuiry** is a PHP and MySQL property listing and inquiry system for Kings
Land Properties. It provides a public property catalog and different pages for
clients, sales agents, and administrators.

- **Last reviewed:** 2026-10-17
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
   `kingquiry_db`, select it, and import `database/schema.sql`.
5. Open `http://localhost/kingquiry/`, register the first account, and promote
   only that account to administrator in phpMyAdmin using the safe query in
   [Local setup](#local-setup). Sign in again.
6. After this one-time setup, start Apache and MySQL in XAMPP and open
   `http://localhost/kingquiry/` to use the system.

The fresh schema creates the tables, tracking and analytics fields, chat
storage, and initial Batangas locations. It intentionally contains no users,
properties, messages, appointments, inquiries, or sales; create or import only
business data you are authorized to use. If the XAMPP folder or database
credentials differ, update the settings in `config/db.php` or set the
documented `KINGQUIRY_DB_*` environment variables.

## Features

### Public catalog

- Browse property listings and filter by keyword, category, or location.
- Open a property's details, price, price per square meter, land characteristics,
  and description.
- See optional property images from `assets/images/`.
- View the latest client appointment tracking details on the catalog when signed
  in as a client.
- Search properties by a safe, prepared keyword query, category, and the active
  locations configured by an administrator.

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
- Logout clears and destroys the session.
- Login and pages that use the shared header send no-cache headers. If the
  browser restores the login page from its back-forward cache, the page reloads
  so the current session can redirect the user to the correct dashboard.

### Client

- Request a property viewing appointment with a preferred date and time.
- Send an inquiry about a property.
- Track appointment status from the catalog or the client dashboard.
- Follow a dated property-request/sale timeline from inquiry or viewing request
  through contact, confirmed viewing, negotiation, reservation, document review,
  payment confirmation, and staff-confirmed successful completion.
- See the current step, tracking reference, and client-visible update notes in
  a dated vertical timeline in the client dashboard. Use the **My Tracking**
  navigation link to jump directly to it. Existing records receive an honest
  starting point; historical events from before the migration are not invented.
- Open the floating chat bubble on the catalog, property detail, and client
  dashboard pages to message the automated customer assistant.
- Request an indicative, comparable-listing-based land estimate in chat using
  the same estimator as the admin dashboard. Its result or missing-data error
  is displayed directly inside the estimate form in the customer chat bubble.
  Estimate answers do not move the chat transcript or scroll the panel away
  from the calculator. An admin can reply while
  their Customer Chat page is active; otherwise the rule-based assistant responds.

### Sales agent

- View client appointments and inquiries.
- Change appointment status to `Pending`, `Approved`, or `Cancelled`.

### Administrator

- View counts for properties, registered users, appointments, and inquiries.
- View automatically refreshed business analytics: verified completed sales,
  actual recorded revenue, an always-visible six-month monthly-revenue line
  graph, and top-selling properties ranked by actual sale value. The graph
  shows zero values until verified sales are recorded. The active pipeline is
  shown separately using listing asking prices, not as earned revenue.
- View property-category and appointment-status charts.
- Dashboard charts show accurate counts and empty states, group unrecognized
  legacy categories under an "Other / legacy" label, and render without a
  third-party chart service or JavaScript dependency.
- Add, deactivate, or reactivate locations using region code, region, province,
  and city/municipality fields. Active locations are shared by catalog filters,
  listing forms, chat estimates, and the admin estimator.
- Update client transaction progress through the dedicated transaction tracker.
  Only the next valid stage can be selected, every update is timestamped and
  attributed to the staff account. Successful completion requires a
  verification note and the actual agreed sale price; that recorded price
  automatically updates the sales analytics.
- Add and remove property listings, with a validated type, asking price,
  land area, standardized municipality selection, and optional validated
  property image. New price-per-square-meter values are calculated from total
  asking price and land area. Existing free-text location data stays visible as
  legacy data until an administrator replaces that listing.
- Use a data-based price estimator, which calculates a weighted price per
  square meter from existing listings, using a weighted median with stronger
  weighting for matching category and municipality. It reports its comparable
  listing count, exact-match count, a heuristic confidence label based on
  exact-match count, and an indicative range. It requires at least one positive
  listing price per square meter. The current system does not contain a trained
  machine-learning model; do not describe this comparable-listing estimator as
  machine learning or a formal appraisal.
- Read client conversations and send replies on the Customer Chat page. The
  admin's conversations and reply form are presented inside a floating bubble
  on that page, which is opened from the single **Customer inbox** button on
  the admin dashboard. The admin is considered online while the page is open
  and its heartbeat is current.

## Project files

| Path | Purpose |
| --- | --- |
| `index.php` | Public property catalog, filters, and client appointment tracking |
| `property_detail.php` | Property details, appointment booking, and inquiries |
| `register.php` | Client account registration |
| `login.php` | Login form, authentication, role-based redirects, and login-page cache handling |
| `logout.php` | Ends the current session and redirects to login |
| `admin_dashboard.php` | Business analytics, dynamic location directory, property management, and price estimator |
| `admin_chat.php` | Admin inbox for client and chatbot conversations |
| `agent_dashboard.php` | Sales-agent appointment and inquiry list; appointment status updates |
| `client_dashboard.php` | Client appointment tracking |
| `transaction_tracking.php` | Admin/agent transaction-stage updates and client-visible history |
| `chat_api.php` | Session-bound chat, polling, admin presence, replies, and estimates |
| `config/db.php` | MySQL connection settings |
| `includes/header.php` | Shared navigation, session startup, and page cache headers |
| `includes/footer.php` | Shared footer, Bootstrap JavaScript, and eligible public/client-page floating chat widget |
| `includes/chat_widget.php` | Floating customer chat, comparable-listing estimate form, and automated assistant interface |
| `includes/property_estimator.php` | Shared weighted comparable-listing estimator |
| `includes/property_images.php` | Validated property image upload and storage |
| `database/chat_schema.sql` | Tables required for client conversations, chat messages, and admin presence |
| `database/location_schema.sql` | Batangas seed data and one-time nullable structured property location and area migration for existing installations |
| `database/transaction_tracking_schema.sql` | Additive transaction timeline tables and safe starting-state backfill for existing appointments and inquiries |
| `database/schema.sql` | Complete fresh-install schema for all application tables plus the initial Batangas location seed data; no live user or transaction records |
| `update_pass.php` | CLI-only administrator password reset utility |
| `CHANGELOG.md` | Dated record of project changes |

## Local setup

1. Install and start Apache and MySQL through XAMPP.
2. Create a MySQL database named `kingquiry_db`.
3. For a **new/empty database**, select `kingquiry_db` in phpMyAdmin and import
   `database/schema.sql`. It creates all application tables and seeds the
   standardized Batangas location directory. It deliberately does not import
   accounts, property listings, appointment/inquiry records, chat messages, or
   audit-log entries from any developer's local database.
4. Configure the database environment variables if needed:
   `KINGQUIRY_DB_HOST`, `KINGQUIRY_DB_USER`, `KINGQUIRY_DB_PASSWORD`, and
   `KINGQUIRY_DB_NAME`. Local defaults are host `localhost`, user `root`, an
   empty password, and database `kingquiry_db`; do not use those defaults for
   production.
5. Put the project under XAMPP's web root and open it through the local Apache
   URL (for example, `http://localhost/kingquiry/`).
   Bootstrap styling and navigation scripts are currently loaded from a CDN,
   so the UI needs internet access unless those assets are later self-hosted.
6. For a **legacy database that already has the original application tables**,
   back it up first, then import `database/chat_schema.sql`, run
   `database/location_schema.sql` once, and import
   `database/transaction_tracking_schema.sql`. The location migration adds
   columns to `properties`, so do not run it more than once. It repeats the
   location table and seed definitions from `schema.sql` so it can independently
   upgrade an older database; it is not needed for a fresh install. The tracking
   migration creates its tables, adds the sale-price column if missing, and
   creates an initial state for existing appointments and inquiries; it does
   not invent past step-by-step history or sales amounts. After migration, the
   same codebase powers client chat, tracking, and analytics.
   Existing free-text locations are retained and left unmapped; only verified
   location data should be assigned to a standardized municipality.
7. Create the first administrator securely: register that account through the
   site first (registration creates only a `Client`), then in phpMyAdmin select
   the new account and change its role to `Admin` with a narrowly scoped query:

   ```sql
   UPDATE users
   SET role = 'Admin'
   WHERE email = 'admin@your-domain.example' AND role = 'Client';
   ```

   Replace the example email with the account you just registered and confirm
   exactly one row was changed. Sign in again so the new role is loaded. Create
   or import agent accounts using the database's `Agent` role; the application
   presents this role as "Sales Agent".
8. Sign in with the administrator account, open **Manage locations**, and add
   any verified locations not in the initial Batangas directory. The region
   code, region name, province, and city/municipality become available
   immediately in property forms, filters, and estimates. Deactivated locations
   remain attached to existing listings. To reproduce custom directory entries
   on another installation, transfer only the approved location rows through a
   reviewed SQL export; do not export personal records into the source repo.

### Database tables used by the application

The code expects these tables and corresponding fields:

- `users`: `userID`, `fullname`, `email`, `passwordHash`, `role`
- `properties`: `propertyID`, `title`, `description`, `price`, `pricePerSqm`,
  `location`, `locationID`, optional `barangay` and `areaSqm`, `propertyType`,
  `landCharacteristics`, and optional `image`
- `property_locations`: standardized region/province/municipality records
- `appointments`: `appointmentID`, `propertyID`, `clientID`, `appointmentDate`,
  `appointmentTime`, `status`
- `feedbackinquiries`: `inquiryID`, `propertyID`, `clientID`, `message`,
  `status`
- `auditlogs`: `logID`, `userID`, `action`, `timestamp`
- `chat_conversations`, `chat_messages`, and `chat_admin_presence`: customer
  conversations, their messages, and admin availability
- `property_transactions` and `property_transaction_events`: the current
  transaction stage, verified sale price when completed, and append-only
  timestamped updates

`database/schema.sql` is the portable source for a fresh database, not a backup
of an active installation. Keep real records and database credentials out of
the repository. To move an existing installation, create a private,
access-controlled backup and transfer it using a secure channel; review and
sanitize personal data before sharing or committing any export.

For an equivalent application setup on another computer, share the project
source and import `database/schema.sql` into a fresh database there. This
creates the same tables and initial location directory, but intentionally not
the users, listings, bookings, inquiries, chats, or logs from your local
database. Move live data only with the owner's approval, a protected database
backup, and a deliberate privacy review. Also transfer property image files
from `assets/images/` when the approved listing data refers to those files;
do not put private customer data or unreviewed database dumps in Git.

The exact SQL types, indexes, constraints, and any additional columns should
match the database that is used for the project.

### Transaction tracking workflow

Clients can track an inquiry or viewing from their dashboard. If a client sends
an inquiry and books a viewing for the same property while its case is open,
the app adds both to the same tracking reference. Admins and sales agents update
the case in **Transaction Tracking**. The normal progression is:

1. Inquiry received or viewing requested
2. Contacting client (inquiry cases)
3. Viewing requested and confirmed
4. Viewing completed
5. Offer / negotiation
6. Reservation processing
7. Document verification
8. Payment confirmation
9. Successful / completed

A case may be cancelled before completion. Staff updates are manual and each
step/note is time-stamped and linked to the staff account. Marking a case
successful also requires the actual agreed sale price, which is then included
in the revenue analytics. The app does not
process payments, verify escrow/bank transfers, send receipts, or track a
physical parcel with GPS. Staff must verify the sale and its documents outside
the app before recording the successful step; its required note is visible to
the client. Payment confirmation and successful completion both require staff
notes. Existing records only receive a migration-time starting status because
their previous transition history was not stored; no old sale prices are
invented.

### Price estimator limitations

The project has no supplied historical training dataset, and older listings
may lack verified land areas or structured locations. The estimator therefore
uses available `pricePerSqm` values as comparable listing data and a weighted
median, prioritizing exact category-and-municipality matches, then category
and municipality matches, with other listings given a lower weight. It shows
an indicative range of +/-10% around the calculated value. It does not train a
machine-learning model and should not be treated as a formal valuation or
appraisal.

Only available listings with a standardized municipality and a positive
price-per-square-meter value contribute as estimate comparables. Newly entered
listing rates are calculated from the validated total asking price divided by
land area. Legacy free-text locations remain searchable by keyword but do not
count as an exact municipality comparison; listings without verified area
remain marked as legacy and are not assigned a guessed size.

### Chat behavior

The floating customer chat bubble appears on the property catalog, property
detail, and client dashboard pages for guests and clients. The current
standardized location catalog covers
Batangas municipalities and cities; barangay names are optional free text
because a complete barangay reference dataset has not been supplied. It stores
messages in the chat tables, greets new
conversations, answers common greetings, estimate, appointment, purchase, and
thanks messages with fixed rule-based responses, and includes a form to request
an estimate. A single estimate action expands the fields and changes to the
calculate action; the result or a clear error appears beside those fields and
the assistant's result remains in the conversation. There is no external AI
service or API key. The admin inbox updates
the admin's online heartbeat while open; messages sent while an admin is away
receive an automatic assistant response, while messages sent with an admin
online are available for a live admin reply. Browser polling means replies are
near-real-time, not push notifications.

## Security and maintenance notes

- `update_pass.php` is CLI-only and accepts an administrator email and a new
  password. Use a secure terminal and avoid putting credentials in shared logs.
- Property image uploads accept JPG, PNG, or WebP up to 5 MB, use random file
  names, and are stored in `assets/images/`.
- Do not publish real database credentials or passwords in the repository.
- Configure production database credentials securely and use HTTPS in
  production.

## Documentation updates

`CHANGELOG.md` records dated code and documentation changes. The personal
Copilot instruction configured for this user asks coding agents to append a
dated entry there whenever they implement a change in a project, and to update
this README when behavior, setup, or project structure changes.
