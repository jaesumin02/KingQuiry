# Changelog

Notable project changes are recorded here in reverse chronological order.
Dates use `YYYY-MM-DD`.

## 2026-11-19 — Capstone Standardization: Business Rules, Analytics, UI Stabilization, and Code Quality

- **Business Rules Enforcement**:
  - Enforced and displayed the **7-day reservation grace period** across `client_dashboard.php`, `property_detail.php`, `transaction_tracking.php`, and `resources.php`.
  - Standardized legal pathways: **Contract to Sell (CTS)** for installment purchases (requiring document verification) vs. **Deed of Absolute Sale (DOAS)** for Spot Cash (bypassing document verification directly to turnover payment confirmation).
  - Explicitly disclosed that **title transfer expenses, registration fees, and Documentary Stamp Taxes (DST) are strictly shouldered by the buyer** across property listings, estimation calculators, chat responses, and resource guides.
  - Added an interactive **Land Estimation & Acquisition Terms Calculator** to `property_detail.php` strictly adhering to `Total Asking Price = Lot Area (sq.m.) × Price per sq.m.`, showing instant DOAS vs. CTS down payment breakdowns.
- **UI & Modal Layout Stabilization**:
  - Fixed modal narrow-column squishing and horizontal button overflow (seen in transaction tracking) by adding automatic body relocation in `assets/js/app.js` (`document.body.appendChild(modal)` on `show.bs.modal`), isolating modals from parent CSS Grid or transform contexts.
  - Standardized `.modal`, `.modal-dialog`, `.modal-content`, and `.btn` styles in `assets/css/style.css` with responsive max-widths (`min(94%, 860px)` for `.modal-lg`), word wrapping, and `white-space: normal` button wrapping.
  - Enhanced SVG analytics chart styling with semantic classes (`.kq-chart-grid`, `.kq-chart-axis`, `.kq-chart-text`) with full dark/light theme contrast adaptation.
- **Dashboard & Analytics Standardization**:
  - Unified the top overview across **Agent Dashboard**, **Client Dashboard**, **Admin Dashboard**, and **Admin Sales** into a consistent, organized 6-card responsive stat row (`col-6 col-lg-4 col-xl-2`) with color-coded accents, uppercase micro-eyebrows, and direct section shortcuts.
  - Expanded `agent_dashboard.php` metrics to 6 stat cards, integrating active pipeline tracking and closed deal counts alongside appointments and customer inquiries.
  - Implemented 6 KPI cards on `client_dashboard.php` (`My Properties`, `Active Pipeline`, `Site Viewings`, `Pending Visits`, `Inquiries`, `Completed Acquisitions`) with efficient pre-fetched array iteration.
  - Replaced bulky admin cards on `admin_dashboard.php` and `admin_sales.php` with the standardized 6-card row (`Active Listings`, `Pending Action`, `Inquiries 30d`, `Active Pipeline`, `Completed Sales`, `Revenue Secured`).
  - Completed Admin Agent Assignment Dispatch in `transaction_tracking.php` with assigned representative status badges in the main table and modal dispatch dropdown for allocating agents.
  - Verified consistent status thresholds across properties (`Available`, `Reserved`, `Sold`), appointments (`Pending`, `Approved`, `Cancelled`), and transactions.
- **Code & Database Quality**:
  - Verified 100% PHP syntax linting with zero errors across all PHP files.
  - Added automated test assertions to `tests/production_baseline_test.php` validating land estimation math, CTS document verification stages, and Spot Cash DOAS transitions. All tests passed.

## 2026-11-19 — Portal Chat, Confirmations, and Dashboard Reliability

- Show all available properties in the catalog by default and remove the
  recommendation match-percentage badge.
- Removed the seven shortcut buttons from Resources & Guides while retaining
  clear section links and a Meet the Team button.
- Made the chat widget available on the public guides, company, and agent pages.
  Agent profile buttons now open a prefilled direct chat routed to that active
  agent; the chat API validates the selected recipient.
- Added confirmation prompts before key payment, listing, appointment,
  transaction, review, and account changes. Existing delete-confirmation
  modals remain in place; search, estimates, and ordinary chat messages are
  not interrupted.
- Replaced the admin dashboard’s CDN-dependent revenue chart with a
  server-rendered SVG chart and deduplicated completed transactions in both
  admin revenue summaries. Listing activity now compares available listings
  with inquiries received in the last 30 days.
- Removed the stale “Updated live” badge and redundant dashboard shortcuts.
  Executed the payment-report function against connection-scoped temporary
  tables only; no real payment was sent and no persistent records were added.

## 2026-11-19 — Unified Schema, Property Navigation, and Public Guides

- Moved the repeat-safe property listing inserts into
  [database/schema.sql](database/schema.sql) and removed the separate listing
  SQL file. Fresh setup now needs only the schema import.
- Added six property categories across search, listings, maps, and estimate
  forms; the catalog search links directly to the available-property results.
- Simplified the main navigation to Home and Resources & Guides, removed the
  top-level Properties button and the Browse Properties resource button as
  requested, and removed unsupported Market Trends while gathering
  FAQ, client feedback, team, and company links on the Resources & Guides page.
- Expanded the FAQ with complete answers about search, listing photos,
  viewings, tracking, payments, estimates, due diligence, and reviews.
- Kept customer reviews private and made the Testimonials section link clients
  to the existing completed-transaction review form.
- Reworked the agent profile and directory layout; Help and Contact Us now use
  the same neutral styling, turning orange only on hover. Added an About the
  Team link in Resources & Guides that leads to the agent directory button.
- Added ten licensed representative photos with visible credits and updated all
  31 current available listings to use distinct images. Photos remain labeled
  representative and are not presented as property-specific pictures.

## 2026-11-19 — Restored Local Database Connectivity and Property Listings

- Added `KINGQUIRY_DB_PORT` support for local MySQL configurations and documented
  the XAMPP port setting in [README.md](README.md).
- Recovered property timestamps and trash flags from the preserved tablespace
  using its original column order; kept the database name `kingquiry_db`.
- Verified all 32 property records, including 20 available listings and 11
  trashed listings.

## 2026-11-19 — Workspace Cleanup: Removed Obsolete Test Files, Temporary Scripts, and Legacy Database Seeds

- Cleaned Obsolete Scripts & Test Files:
  - Removed obsolete `scripts/` folder (`update_admin_password.php`).
  - Removed temporary feature verification test files (`chat_message_button_test.php`, `client_dashboard_render_test.php`, `dark_theme_verification_test.php`, `manage_account_modal_test.php`, `navbar_layout_test.php`, `comprehensive_system_test.php`).
  - Retained [tests/production_baseline_test.php](file:///d:/xampp/htdocs/KingQuiry/tests/production_baseline_test.php) to ensure [.github/workflows/php.yml](file:///d:/xampp/htdocs/KingQuiry/.github/workflows/php.yml) CI checks pass without issue.
  - Removed legacy database archives `database/legacy_migrations/` and `database/seeds/`, retaining the authoritative single [database/schema.sql](file:///d:/xampp/htdocs/KingQuiry/database/schema.sql).


## 2026-11-19 — Cleaned Up Transaction Tracking Card: Removed Redundant Message Button

- Tracking Card Clean-Up ([client_dashboard.php](file:///d:/xampp/htdocs/KingQuiry/client_dashboard.php#L816)):
  - Removed the redundant `💬 Message` button on the main transaction card next to `View tracking →`, keeping the card clean and uncluttered.
  - Clients access conversations via the bottom-corner Facebook Messenger chat bubble or the assigned agent profile within the tracking details modal.


## 2026-11-19 — Resolved Fatal Error `chat_csrf_token()` Restoring Live Clock, View Tracking Modals, and Dashboard Chat Bubble

- Resolved Root-Cause Fatal Error in Chat Widget ([includes/auth.php](file:///d:/xampp/htdocs/KingQuiry/includes/auth.php), [includes/chat_widget.php](file:///d:/xampp/htdocs/KingQuiry/includes/chat_widget.php), [chat_api.php](file:///d:/xampp/htdocs/KingQuiry/chat_api.php)):
  - Fixed `Call to undefined function chat_csrf_token()` by declaring `chat_csrf_token()` in [includes/auth.php](file:///d:/xampp/htdocs/KingQuiry/includes/auth.php) and adding defensive `function_exists('chat_csrf_token')` guards across [includes/chat_widget.php](file:///d:/xampp/htdocs/KingQuiry/includes/chat_widget.php) and [chat_api.php](file:///d:/xampp/htdocs/KingQuiry/chat_api.php).
  - This fatal error occurred inside [includes/footer.php](file:///d:/xampp/htdocs/KingQuiry/includes/footer.php) when loading [includes/chat_widget.php](file:///d:/xampp/htdocs/KingQuiry/includes/chat_widget.php) on [client_dashboard.php](file:///d:/xampp/htdocs/KingQuiry/client_dashboard.php), which truncated HTML rendering before reaching `bootstrap.bundle.min.js` and `assets/js/app.js`.
  - As a direct result of fixing the error:
    1. **Live Date & Time Clock Restored**: [assets/js/app.js](file:///d:/xampp/htdocs/KingQuiry/assets/js/app.js) loads and executes normally, running the live Philippine clock tick loop every second.
    2. **View Tracking Button & Modal Restored**: Bootstrap's bundle loads into the DOM, making all `data-bs-toggle="modal"` triggers and `#trackingModal[ID]` modals interactive again.
    3. **Corner Bottom Chat Bubble Restored**: `#kingquiry-chat-launcher` and `#kingquiry-chat-panel` render on [client_dashboard.php](file:///d:/xampp/htdocs/KingQuiry/client_dashboard.php) with multi-condition page matching in [includes/footer.php](file:///d:/xampp/htdocs/KingQuiry/includes/footer.php) (`SCRIPT_NAME`, `PHP_SELF`, `REQUEST_URI`, and `$trackingRows` presence).
- Verification ([tests/client_dashboard_render_test.php](file:///d:/xampp/htdocs/KingQuiry/tests/client_dashboard_render_test.php), [tests/chat_message_button_test.php](file:///d:/xampp/htdocs/KingQuiry/tests/chat_message_button_test.php)):
  - Output reaches `</html>` without truncation (131k bytes).
  - Verified `bootstrap.bundle.min.js`, `assets/js/app.js`, `kq-live-clock`, `kingquiry-chat-launcher`, and `trackingModal` all render and pass 100%.

## 2026-11-19 — Facebook Messenger-Style Corner Bottom Chat Bubble with Unified Conversations Navigation and Real-Time Agent Messaging

- Corner Bottom Floating Messenger Bubble ([includes/chat_widget.php](file:///d:/xampp/htdocs/KingQuiry/includes/chat_widget.php), [includes/footer.php](file:///d:/xampp/htdocs/KingQuiry/includes/footer.php)):
  - Converted the client-side conversation UI into a Facebook Messenger-style floating circular chat launcher (`#kingquiry-chat-launcher`) fixed in the bottom-right corner (`60x60px` circle, royal blue gradient, subtle elevation shadow).
  - Integrated the chat bubble onto `client_dashboard.php` by adding `'client_dashboard.php'` to `$chatWidgetPages` in [includes/footer.php](file:///d:/xampp/htdocs/KingQuiry/includes/footer.php), ensuring clients can access the chat bubble on their dashboard and across client pages.
  - Clicking the launcher opens the corner bottom floating window (`#kingquiry-chat-panel`, `460px` wide, `660px` tall, `20px` border-radius) housing the exact conversation design from the user's reference image.
- Unified Conversations Navigation & Direct Channel Info ([includes/chat_widget.php](file:///d:/xampp/htdocs/KingQuiry/includes/chat_widget.php)):
  - Dynamic **"CONVERSATIONS (N)"** top section with horizontal tabs allowing clients with multiple property requests/transactions to switch between assigned sales agents with 1 click.
  - Each tab displays the assigned agent's name (`👨‍💼 Agent one`), transaction badge (`#KQT-[ID]`), and property title (`Beach lang to`).
  - Active header displays agent avatar, name, badges (`Assigned Sales Representative`, `Online`), and regarding subtitle (`Regarding: Beach lang to (Transaction #KQT-1) · Cancelled`).
  - Added a dedicated `[Tracking]` pill button in the chat header: clicking it smoothly minimizes the chat window and opens that transaction's tracking details modal.
  - Added a Direct Channel bar displaying the agent's verified contact email (`contact@kingsland.ph`), phone (`0965 803 0933`), and `Verified KingQuiry Representative` status.
- End-to-End Reliable Messaging & Agent Notification Bug Fixes ([chat_api.php](file:///d:/xampp/htdocs/KingQuiry/chat_api.php), [client_dashboard.php](file:///d:/xampp/htdocs/KingQuiry/client_dashboard.php)):
  - Fixed database exception in [chat_api.php](file:///d:/xampp/htdocs/KingQuiry/chat_api.php): replaced non-existent column `status` with `accountStatus` in user table queries (`accountStatus = 'Active' OR accountStatus IS NULL`), which previously caused a 500 error preventing message dispatch.
  - Dual CSRF validation: updated `chat_validate_csrf()` to accept both `chat_csrf_token()` and canonical session `csrf_token()`, eliminating 403 expiration errors.
  - Server-side pre-rendered CSRF token directly embedded in [includes/chat_widget.php](file:///d:/xampp/htdocs/KingQuiry/includes/chat_widget.php) (`data-csrf`), ensuring zero-latency message submission without race conditions.
  - Sending messages immediately dispatches `{ action: 'send', message, transaction_id, agent_id, csrf }`, records the message in `chat_messages` with `sender = 'client'`, updates `chat_conversations.updated_at`, and generates an unread notification in `user_notifications` for the assigned agent (`link = 'admin_chat.php'`).
  - The assigned agent sees the message in real-time in [admin_chat.php](file:///d:/xampp/htdocs/KingQuiry/admin_chat.php) and receives the notification in their top navbar notification center.
  - The client sees their message instantly with real-time timestamps and double checkmarks.
- First-Time Automated Agent Greeting & Text-Only Enforcement:
  - First-time messaging automatically generates the personalized greeting: *"Hello [Client Name]! I am [Agent Name], your assigned sales representative for [Property Title] (Transaction #KQT-[ID]). How can I assist you with your inquiry or transaction today?"*.
  - Strict text-only messaging with 1000 character countdown, drag-and-drop file blocking, and clipboard file paste blocking.
- Streamlined Client Dashboard Architecture ([client_dashboard.php](file:///d:/xampp/htdocs/KingQuiry/client_dashboard.php)):
  - Removed duplicate `#clientAgentChatModal` and its redundant scripts.
  - Added "💬 Message" button directly on each transaction card next to "View tracking", as well as inside the tracking modal.
  - Updated `openClientAgentChat(transactionId)` to seamlessly hide any open tracking modal and pop open the corner Messenger bubble with that transaction selected.
- Test Verification ([tests/chat_message_button_test.php](file:///d:/xampp/htdocs/KingQuiry/tests/chat_message_button_test.php)):
  - 100% passing tests covering corner chat bubble launcher, floating panel, conversations tabs, direct channel info, text-only enforcement, database tables, message delivery, and agent notifications.


## 2026-11-19 — Unified Client-Side Agent Chat with Conversations Navigation, Real-Time Agent Notifications, and Dark Mode Contrast Fix

- Real-Time Agent Notifications & Inbox Synchronization ([chat_api.php](file:///d:/xampp/htdocs/KingQuiry/chat_api.php)):
  - Connected client-side messages directly to [chat_api.php](file:///d:/xampp/htdocs/KingQuiry/chat_api.php), synchronizing them with [admin_chat.php](file:///d:/xampp/htdocs/KingQuiry/admin_chat.php) where agents review and reply to conversations.
  - On `action === 'send'`: Resolves the client's assigned sales representative (from `agent_id`, `transaction_id`, or active `property_transactions`) and automatically triggers `create_user_notification(...)` in `user_notifications`.
  - The assigned agent's top navbar notification bell immediately updates with an unread badge and dropdown item ("New Message from Jane Doe") linking directly to [admin_chat.php](file:///d:/xampp/htdocs/KingQuiry/admin_chat.php).
  - On `action === 'admin_reply'`: When an agent replies in [admin_chat.php](file:///d:/xampp/htdocs/KingQuiry/admin_chat.php), `create_user_notification(...)` dispatches a notification to the client's navbar bell linking back to [client_dashboard.php](file:///d:/xampp/htdocs/KingQuiry/client_dashboard.php).
  - Suppressed generic canned bot answers during direct client-to-assigned-agent conversations, keeping the human conversation thread clean and uncluttered.
- Unified Client Chat Window with Conversations Navigation ([client_dashboard.php](file:///d:/xampp/htdocs/KingQuiry/client_dashboard.php)):
  - Designed and deployed `#clientAgentChatModal` featuring a top **"Conversations"** section matching the layout in [admin_chat.php](file:///d:/xampp/htdocs/KingQuiry/admin_chat.php).
  - If a client has multiple property transactions or inquiries, each transaction card/pill is listed with the agent's name, property title, and transaction reference (`#KQT-[ID]`).
  - Clients can switch between transactions and assigned sales representatives with 1 click (`selectClientChatConversation(...)`).
  - Clicking "💬 Message" inside any transaction tracking window immediately transitions smoothly to that transaction's conversation tab with the agent's initial automated greeting loaded.
  - Added a convenient `← Tracking` button in the chat modal header allowing seamless return to the tracking modal.
- Modernized Chat Thread & Box Design ([client_dashboard.php](file:///d:/xampp/htdocs/KingQuiry/client_dashboard.php), [assets/css/style.css](file:///d:/xampp/htdocs/KingQuiry/assets/css/style.css)):
  - Agent speech bubbles feature verified agent badges, agent avatar (👨‍💼), crisp timestamps, and high-contrast card styling (`#ffffff` with `#e2e8f0` border in light mode; `#132537` with `#1e3a4e` border in dark mode).
  - Client speech bubbles feature a modern royal blue gradient (`linear-gradient(135deg, #174a70, #0f324e)`) with crisp white typography and double checkmark delivery status.
  - Live 3.5-second polling loop automatically checks for new agent responses while the chat window is open and pauses on modal dismissal.
- Strict Text-Only Messaging Enforcement:
  - Form accepts text messages only (1 to 1000 characters) with a real-time character counter.
  - Drag-and-drop (`drop`) and clipboard pasting (`paste`) of files or images are strictly blocked on both the input element and message container with warning alerts.
  - Backend [chat_api.php](file:///d:/xampp/htdocs/KingQuiry/chat_api.php) rejects file uploads (`$_FILES`) with HTTP 400.
- Dark Mode Contrast & Readability Fix in Transaction Updates ([client_dashboard.php](file:///d:/xampp/htdocs/KingQuiry/client_dashboard.php), [includes/header.php](file:///d:/xampp/htdocs/KingQuiry/includes/header.php), [assets/css/style.css](file:///d:/xampp/htdocs/KingQuiry/assets/css/style.css)):
  - Fixed the unreadable white-on-white text bug in Transaction Updates timeline under dark mode.
  - Replaced hardcoded `bg-white` and `.text-dark` classes with semantic classes: `.kq-tracking-updates-box`, `.kq-tracking-updates-title`, `.kq-tracking-event-title`, `.kq-tracking-event-time`, `.kq-tracking-event-author`, `.kq-tracking-event-note`.
  - In dark mode (`[data-bs-theme="dark"]`), event boxes change background to `#09141d` (normal) and `#092019` (latest/completed) with `#1e3a4e` / `#17543e` borders, and crisp high-contrast text (`#f8fafc` titles, `#34d399` latest badge, `#94a3b8` timestamps, `#cbd5e1` authors, `#e2e8f0` notes).
- Comprehensive Test Verification ([tests/chat_message_button_test.php](file:///d:/xampp/htdocs/KingQuiry/tests/chat_message_button_test.php), [tests/client_dashboard_render_test.php](file:///d:/xampp/htdocs/KingQuiry/tests/client_dashboard_render_test.php)):
  - Verified 21 critical checks: unified modal existence, conversations list, message form, text-only constraints, agent notifications creation, bot canned response suppression, and dark mode contrast selectors.


## 2026-11-19 — Resolved View Tracking Modal Issue: CSRF Function Name Alignment

- Root Cause Resolution ([client_dashboard.php](file:///d:/xampp/htdocs/KingQuiry/client_dashboard.php#L1473), [agent_message_api.php](file:///d:/xampp/htdocs/KingQuiry/agent_message_api.php#L126-L165)):
  - Resolved fatal error (`Call to undefined function get_csrf_token()`) in the newly added client dashboard script.
  - The undefined function call prevented PHP from finishing the rendering of [client_dashboard.php](file:///d:/xampp/htdocs/KingQuiry/client_dashboard.php), which truncated the document before [includes/footer.php](file:///d:/xampp/htdocs/KingQuiry/includes/footer.php) was reached.
  - As a result, Bootstrap's JavaScript bundle (`bootstrap.bundle.min.js`) was never loaded into the DOM, causing all `data-bs-toggle="modal"` buttons (including the "View tracking" button and cards) to become unresponsive.
  - Replaced `get_csrf_token()` with the canonical `csrf_token()` defined in [includes/auth.php](file:///d:/xampp/htdocs/KingQuiry/includes/auth.php#L228).
  - Also protected `config/db.php` helper function declaration with `function_exists` guard to ensure idempotent inclusion.
- Verification ([tests/client_dashboard_render_test.php](file:///d:/xampp/htdocs/KingQuiry/tests/client_dashboard_render_test.php)):
  - Added an end-to-end HTML buffer test verifying that [client_dashboard.php](file:///d:/xampp/htdocs/KingQuiry/client_dashboard.php) renders cleanly to `</html>`, loads `bootstrap.bundle.min.js`, and outputs the "View tracking" modal and trigger buttons without warnings or fatal errors.

## 2026-11-19 — Dedicated Agent Direct Message Window, No Chat Bubble, and Per-Agent Saved Messages

- Dedicated Agent Direct Message Window ([client_dashboard.php](file:///d:/xampp/htdocs/KingQuiry/client_dashboard.php#L754-L762)):
  - Replaced the generic KingQuiry Assistant chat bubble with a dedicated direct message pop-up window (`#agentMessageModal_<?= $transactionId ?>`) specifically for the assigned sales representative.
  - When the client clicks "💬 Message", the tracking modal transitions directly to the Agent Message Window with smooth focus.
  - Window header clearly presents the assigned agent's avatar, name, email, phone number, and the referenced transaction and property (`#KQT-[ID]`).
  - Added a convenient "← Tracking" return button allowing the client to switch back to transaction progress at any time.
- Isolated Per-Agent Message Storage ([includes/agent_messages.php](file:///d:/xampp/htdocs/KingQuiry/includes/agent_messages.php)):
  - Implemented the `agent_direct_messages` database table to permanently store and isolate conversation histories between clients and their assigned sales agents per transaction.
  - Ensures a client's conversation with one agent does not mix with another agent or with the generic automated assistant.
  - All messages are permanently saved to MySQL and reloaded whenever the client reopens the window for that agent.
- Automated First-Time Agent Greeting ([includes/agent_messages.php](file:///d:/xampp/htdocs/KingQuiry/includes/agent_messages.php), [agent_message_api.php](file:///d:/xampp/htdocs/KingQuiry/agent_message_api.php)):
  - When a client opens the message window for that assigned agent for the first time, an automated greeting from the agent is automatically generated and recorded:
    `"Hello [Client Name]! I am [Agent Name], your assigned sales representative for [Property Title] (Transaction #KQT-[ID]). How can I assist you with your transaction or inquiry today?"`
  - The greeting displays on the left as an agent speech bubble with sender attribution and timestamp.
- Strict Text-Only Messaging Enforcement ([client_dashboard.php](file:///d:/xampp/htdocs/KingQuiry/client_dashboard.php), [agent_message_api.php](file:///d:/xampp/htdocs/KingQuiry/agent_message_api.php)):
  - Message form accepts text only (max 1000 characters) with a live character countdown.
  - Intercepted drag-and-drop (`drop`) and clipboard paste (`paste`) events on the input to block file drops or images with user-friendly alerts.
  - In `agent_message_api.php`, blocked incoming file uploads (`$_FILES`) with HTTP 400 and strictly validated text string content.
- Exclusion of Chat Bubble on Client Dashboard ([includes/footer.php](file:///d:/xampp/htdocs/KingQuiry/includes/footer.php#L2)):
  - Removed `client_dashboard.php` from `$chatWidgetPages`, ensuring no floating chat bubble appears on the client dashboard.
- Dark Theme Styling ([assets/css/style.css](file:///d:/xampp/htdocs/KingQuiry/assets/css/style.css#L2285-L2325)):
  - Added dark theme surfaces for `.agent-chat-info-bar` (`#162838`), `.agent-messages-scroll` (`#09141d`), and `.agent-message-bubble` (`#162838`) with crisp slate borders.
- Automated Test Suite ([tests/chat_message_button_test.php](file:///d:/xampp/htdocs/KingQuiry/tests/chat_message_button_test.php)):
  - Verified button binding, dedicated modal window, absence of generic chat bubble on dashboard, API validation, text-only enforcement, and database table creation (18/18 checks passed).

## 2026-11-19 — Transaction Tracking Message Popup Window, Persistent Chat Bubble, Text-Only Restrictions, and First-Time Automated Agent Greeting

- Transaction Tracking Message Popup Window ([client_dashboard.php](file:///d:/xampp/htdocs/KingQuiry/client_dashboard.php#L754-L762)):
  - Connected the "💬 Message" button on the assigned sales representative card inside transaction tracking details.
  - Configured HTML5 data attributes (`data-agent-name`, `data-property-title`, `data-transaction-id`) to safely deliver transaction metadata to client handlers.
  - Automatically closes the tracking modal on click so the chat window pops up without modal or dark backdrop obstruction.
- Persistent Chat Bubble & History Retention ([includes/chat_widget.php](file:///d:/xampp/htdocs/KingQuiry/includes/chat_widget.php), [chat_api.php](file:///d:/xampp/htdocs/KingQuiry/chat_api.php#L42-L73)):
  - Preserved the fixed circular chat bubble launcher (`#kingquiry-chat-launcher`) on screen (`z-index: 1060`) for easy access.
  - When the client minimizes or closes the chat window (`×`), the chat bubble remains accessible at the bottom-right corner.
  - Client conversations are indexed by `user_id` in `chat_conversations` and stored permanently in `chat_messages`, ensuring chat history is preserved whenever the chat bubble is reopened across sessions or page reloads.
- Automated First-Time Agent Greeting ([chat_api.php](file:///d:/xampp/htdocs/KingQuiry/chat_api.php#L182-L224), [includes/chat_widget.php](file:///d:/xampp/htdocs/KingQuiry/includes/chat_widget.php#L324-L370)):
  - Implemented the `agent_greeting` API action called by `window.openChatWithAgent(...)`.
  - When a client clicks the message button to contact their assigned sales agent for the first time, the system checks whether a greeting for this transaction `#KQT-[ID]` already exists. If not, the assigned agent sends an automated greeting directly into the chat thread:
    `Hello [Client Name]! I am [Agent Name], your assigned sales representative for [Property Title] (Transaction #KQT-[ID]). How can I assist you with your inquiry or transaction today?`
  - Subsequent clicks open the window displaying the full saved conversation without creating duplicate greetings.
- Strict Text-Only Messaging Enforcement ([includes/chat_widget.php](file:///d:/xampp/htdocs/KingQuiry/includes/chat_widget.php), [chat_api.php](file:///d:/xampp/htdocs/KingQuiry/chat_api.php#L147-L151)):
  - Client can only send text messages: input field has `type="text"` with 1000 character limit.
  - Intercepted drag-and-drop (`drop`) and clipboard paste (`paste`) events on the chat input, displaying an alert that file attachments are not supported.
  - Displayed a clear notice below the input: "Text messages only (file attachments are not supported)."
  - In `chat_api.php`, blocked incoming file uploads (`$_FILES`) with HTTP 400 and strictly validated that messages are text strings (1–1000 characters).
- Chat Widget Dark Theme Styling ([includes/chat_widget.php](file:///d:/xampp/htdocs/KingQuiry/includes/chat_widget.php#L48-L67)):
  - Converted chat panel footer to `.kingquiry-chat-footer` with dark slate `#0f1f2b` background, `#1e3a4e` border, and dark text input `#162838` in dark mode.
- Automated Test Suite ([tests/chat_message_button_test.php](file:///d:/xampp/htdocs/KingQuiry/tests/chat_message_button_test.php)):
  - Created end-to-end verification suite covering button binding, popup window execution, persistent chat bubble, text-only validation, and automated first-time agent greeting (17/17 checks passed).

## 2026-11-19 — Navbar Streamlining: Removed Redundant Client "My Tracking" Link

- Navbar Link Streamlining ([includes/header.php](file:///d:/xampp/htdocs/KingQuiry/includes/header.php#L585-L592)):
  - Removed redundant `My Tracking` link from the top navigation bar for authenticated clients.
  - Clients accessing their `Dashboard` ([client_dashboard.php](file:///d:/xampp/htdocs/KingQuiry/client_dashboard.php)) already land immediately on their interactive transaction tracking tracker as the primary dashboard feature.
  - Eliminating this redundant link declutters the header and provides additional horizontal space across wide and compact desktop screens.
- Verification ([tests/navbar_layout_test.php](file:///d:/xampp/htdocs/KingQuiry/tests/navbar_layout_test.php)):
  - Added assertion confirming `>My Tracking<` link is omitted from the public navbar (16/16 checks passed).

## 2026-11-19 — Manage Account Modal ID Alignment and Profile Integration

- Modal Identifier Alignment ([includes/modals/account_modals.php](file:///d:/xampp/htdocs/KingQuiry/includes/modals/account_modals.php)):
  - Resolved bug where clicking "Manage Account" in the user dropdown menu did nothing. The dropdown link targeted `data-bs-target="#manageAccountModal"` and [assets/js/app.js](file:///d:/xampp/htdocs/KingQuiry/assets/js/app.js) listened for `manageAccountModal`, but the modal in [includes/modals/account_modals.php](file:///d:/xampp/htdocs/KingQuiry/includes/modals/account_modals.php) was erroneously named `id="modalChangePassword"`.
  - Renamed the modal container to `id="manageAccountModal"` and added the required `#manageAccountAlert` feedback element.
  - Enhanced the modal with an Account Profile Summary Card displaying the user's Full Name, Email, Role badge, and direct link to the full settings page ([manage_account.php](file:///d:/xampp/htdocs/KingQuiry/manage_account.php)).
- Session Safety ([includes/footer.php](file:///d:/xampp/htdocs/KingQuiry/includes/footer.php)):
  - Guarded `$currentUser` resolution from `$_SESSION['user']` to ensure modals render reliably on every authenticated page.
- Automated Test Suite ([tests/manage_account_modal_test.php](file:///d:/xampp/htdocs/KingQuiry/tests/manage_account_modal_test.php)):
  - Added end-to-end verification for modal ID matching, form binding, alert box presence, and JavaScript hook alignment (all 9 checks passed).

## 2026-11-19 — Manage Account Modal ID Alignment and Profile Integration

- Modal Identifier Alignment ([includes/modals/account_modals.php](file:///d:/xampp/htdocs/KingQuiry/includes/modals/account_modals.php)):
  - Resolved bug where clicking "Manage Account" in the user dropdown menu did nothing. The dropdown link targeted `data-bs-target="#manageAccountModal"` and [assets/js/app.js](file:///d:/xampp/htdocs/KingQuiry/assets/js/app.js) listened for `manageAccountModal`, but the modal in [includes/modals/account_modals.php](file:///d:/xampp/htdocs/KingQuiry/includes/modals/account_modals.php) was erroneously named `id="modalChangePassword"`.
  - Renamed the modal container to `id="manageAccountModal"` and added the required `#manageAccountAlert` feedback element.
  - Enhanced the modal with an Account Profile Summary Card displaying the user's Full Name, Email, Role badge, and direct link to the full settings page ([manage_account.php](file:///d:/xampp/htdocs/KingQuiry/manage_account.php)).
- Session Safety ([includes/footer.php](file:///d:/xampp/htdocs/KingQuiry/includes/footer.php)):
  - Guarded `$currentUser` resolution from `$_SESSION['user']` to ensure modals render reliably on every authenticated page.
- Automated Test Suite ([tests/manage_account_modal_test.php](file:///d:/xampp/htdocs/KingQuiry/tests/manage_account_modal_test.php)):
  - Added end-to-end verification for modal ID matching, form binding, alert box presence, and JavaScript hook alignment (all 9 checks passed).

## 2026-11-19 — Transaction Tracking Timeline Dark Theme Resolution

- Dark Mode Timeline Container & Card Theming ([includes/header.php](file:///d:/xampp/htdocs/KingQuiry/includes/header.php#L367-L395), [assets/css/style.css](file:///d:/xampp/htdocs/KingQuiry/assets/css/style.css#L1647-L1690)):
  - Resolved issue in client dashboard transaction updates where `.kq-tracking-event-content` had hardcoded light gray (`#f7f9fb`) and pale green (`#f0f8f4`) backgrounds while dark theme turned the status text (`.text-dark`) off-white (`#f1f5f9`), resulting in unreadable white-on-white text.
  - Implemented dark mode surfaces for `.kq-tracking-event-content` using dark slate (`#0d1b26`) with border (`#1e3a4e`) and latest milestone update container using deep forest emerald (`#0b221e`) with green accent border (`#17543e`) and emerald border strip (`box-shadow: inset 4px 0 0 #10b981`).
  - Darkened timeline connecting line (`border-left-color: #1e3a4e`) and timeline node dots (`.kq-tracking-event::before`) with dark slate fills and borders.
  - Styled all inner text elements with high WCAG AA contrast: stage titles (`#f1f5f9`), update timestamps (`#94a3b8`), latest update badge (`#34d399`), agent attribution (`#cbd5e1`), and note descriptions (`#e2e8f0`).
- Test Verification ([tests/dark_theme_verification_test.php](file:///d:/xampp/htdocs/KingQuiry/tests/dark_theme_verification_test.php)):
  - Added selector check for `[data-bs-theme="dark"] .kq-tracking-event-content` (40/40 checks passed).

## 2026-11-19 — Footer Dark Mode Background and Contrast Fix

- Root Cause Resolution ([includes/header.php](file:///d:/xampp/htdocs/KingQuiry/includes/header.php#L372-L390), [assets/css/style.css](file:///d:/xampp/htdocs/KingQuiry/assets/css/style.css#L1624-L1645)):
  - Fixed `.app-footer` which previously used `background: var(--kq-ink);`. Because `--kq-ink` was re-mapped to off-white (`#f1f5f9`) in dark mode as the general text color, the footer background turned stark white with white text in dark mode.
  - Replaced with solid deep navy (`#102a43`) in light mode and midnight navy (`#081522`) under `[data-bs-theme="dark"]`, accompanied by subtle border (`1px solid rgba(255, 255, 255, 0.1)`), high-contrast off-white text (`#f1f5f9`), and gold hover accents (`#d8ad63`).
- Full-Width Alignment ([includes/footer.php](file:///d:/xampp/htdocs/KingQuiry/includes/footer.php#L15)):
  - Updated footer inner container to `container-fluid px-3 px-sm-4 px-xl-5`, matching the horizontally expanded header layout.
- Automated Test Suite ([tests/dark_theme_verification_test.php](file:///d:/xampp/htdocs/KingQuiry/tests/dark_theme_verification_test.php)):
  - Added selector verification for `[data-bs-theme="dark"] .app-footer` to ensure footer dark theme styling is strictly guarded against regressions (39/39 checks passed).

## 2026-11-19 — Dedicated About Us Page and Clean Navigation Dropdown

- Created Dedicated About Us Section ([about.php](file:///d:/xampp/htdocs/KingQuiry/about.php)):
  - Migrated the four company overview sections ("Company background", "Service history", "Testimonials", and "Frequently asked questions") out of [resources.php](file:///d:/xampp/htdocs/KingQuiry/resources.php) into their own standalone [about.php](file:///d:/xampp/htdocs/KingQuiry/about.php) page.
  - Added dedicated hero header, in-page navigation anchors (`#company`, `#service-history`, `#testimonials`, `#faqs`), and a direct link to the active sales agents directory.
- Reorganized Resources & Guides ([resources.php](file:///d:/xampp/htdocs/KingQuiry/resources.php)):
  - Retained core property buyer resources (Buyer's Guide, Market Trends, Expert Advice, Listing Prices) and removed all company/history/testimonials/FAQ sections.
  - Added cross-navigation callout to [about.php](file:///d:/xampp/htdocs/KingQuiry/about.php).
- Cleaned About Us Navbar Dropdown Menu ([includes/header.php](file:///d:/xampp/htdocs/KingQuiry/includes/header.php)):
  - Removed confusing anchor links that redirected visitors to the Resources & Guides page (`resources.php#company`, `resources.php#service-history`, `resources.php#testimonials`, `resources.php#faqs`).
  - Streamlined the About Us dropdown into two clean, direct destinations: "About Us" ([about.php](file:///d:/xampp/htdocs/KingQuiry/about.php)) and "Our Team" ([agents.php](file:///d:/xampp/htdocs/KingQuiry/agents.php)).
- Automated Verification ([tests/navbar_layout_test.php](file:///d:/xampp/htdocs/KingQuiry/tests/navbar_layout_test.php)):
  - Verified absence of external resource redirects in About Us dropdown, presence of all four sections in [about.php](file:///d:/xampp/htdocs/KingQuiry/about.php), and removal from [resources.php](file:///d:/xampp/htdocs/KingQuiry/resources.php) (15/15 checks passed).

## 2026-11-19 — Navbar Horizontal Expansion, Sizing Enhancement, and Theme-Matched Buttons

- Full-Width Edge-to-Edge Navbar Layout ([includes/header.php](file:///d:/xampp/htdocs/KingQuiry/includes/header.php)):
  - Replaced constrained `<div class="container">` (fixed max-width 1320px) with `<div class="container-fluid px-3 px-sm-4 px-xl-5">` to fully utilize widescreen displays and eliminate empty navy gutters on the left and right.
  - Set `--kq-content-width: 1480px;` and updated desktop navbar media query to `flex-wrap: nowrap; align-items: center; gap: .45rem;`, eliminating wrapping of the Help and Theme toggle buttons beneath "Home".
- Navbar Sizing Enhancement:
  - Increased navbar height from 58px to 68px (`min-height: 68px;`) for a more spacious, premium feel.
  - Scaled brand wordmark to `1.15rem`, nav links to `.95rem` with `.52rem .8rem` padding, and official logo to `86px x 56px`.
  - Scaled live Philippine clock display: date to `.82rem` and time to `1.12rem` bold with `0.95rem` divider padding.
- Theme-Matched Help & Dark/Light Mode Buttons ([includes/header.php](file:///d:/xampp/htdocs/KingQuiry/includes/header.php), [assets/css/style.css](file:///d:/xampp/htdocs/KingQuiry/assets/css/style.css), [assets/js/app.js](file:///d:/xampp/htdocs/KingQuiry/assets/js/app.js)):
  - Removed generic white wireframe borders (`btn-outline-light btn-sm`) and OS-rendered text emojis (`❓` and `🌙`).
  - Implemented bespoke luxury styles `.kq-help-btn` and `.kq-theme-toggle-btn` featuring translucent royal gold backgrounds (`rgba(216, 173, 99, 0.12)`), gold borders (`rgba(216, 173, 99, 0.42)`), and crisp gold vector SVGs that harmonize with the 4Kings branding and the primary "Contact Us" gold button.
  - Added smooth interactive hover state with gradient fill (`linear-gradient(135deg, #d8ad63, #c49643)`), deep navy text/icon (`#102a43`), subtle lift (`translateY(-1px)`), and gold glow shadow (`0 4px 16px rgba(216, 173, 99, 0.4)`).
  - Updated [assets/js/app.js](file:///d:/xampp/htdocs/KingQuiry/assets/js/app.js) `updateThemeIcon()` to dynamically render crisp vector SVG icons for Moon (in light mode) and Sun (in dark mode).
- Testing & Verification:
  - Added [tests/navbar_layout_test.php](file:///d:/xampp/htdocs/KingQuiry/tests/navbar_layout_test.php) to verify edge-to-edge layout, sizing, no-wrap behavior, and SVG theme button attributes (100% pass).

## 2026-11-19 — Complete Dark Theme Overhaul and Contrast Redesign

- Master Dark Theme Architecture ([assets/css/style.css](file:///d:/xampp/htdocs/KingQuiry/assets/css/style.css)):
  re-engineered the complete dark mode experience under `[data-bs-theme="dark"]`
  with unified WCAG AA accessible contrast, consistent palettes, and responsive styling
  across all public, client, agent, and administrator interfaces.
- Scoped Dynamic CSS Variables ([includes/header.php](file:///d:/xampp/htdocs/KingQuiry/includes/header.php)):
  defined root dark mode custom properties (`--kq-ink: #f1f5f9`, `--kq-muted: #94a3b8`,
  `--kq-primary: #60a5fa`, `--kq-surface: #0f1f2b`, `--kq-canvas: #09131a`,
  `--kq-line: #1e3a4e`, `--kq-shadow: 0 12px 32px rgba(0,0,0,0.45)`).
- Admin Control Room Dark Theme: resolved issue where `--admin-ink` was hardcoded
  to `#0f2942` (black) causing black text on dark surfaces; configured full dark mode
  variables for `body.admin-control-room`, stat cards, and navigation tabs.
- Buttons Contrast and Legibility:
  - `.btn-outline-primary`: corrected dark navy blue `#123b5a` text to crisp `#93c5fd`
    with `#3b82f6` border and bright active/hover states.
  - `.btn-outline-secondary`: tuned to `#cbd5e1` with visible `#475569` border.
  - `.btn-light`: converted from stark white to elevated dark navy surface `#1e3244` with `#f1f5f9` text.
  - `.btn-close`: added `filter: invert(1) grayscale(100%) brightness(200%)` for crisp visibility in modals.
  - `.password-toggle`: tuned show/hide buttons to legible `#93c5fd`.
- Modals & Popups Refactoring:
  - [includes/modals/contact_us_modal.php](file:///d:/xampp/htdocs/KingQuiry/includes/modals/contact_us_modal.php):
    removed hardcoded inline `style="background: #ffffff;"` and `style="background: #f8fafc;"`,
    replacing with adaptive `.kq-contact-card`, `.kq-fb-icon-box`, and `.kq-phone-icon-box`.
  - [includes/modals/notifications_modal.php](file:///d:/xampp/htdocs/KingQuiry/includes/modals/notifications_modal.php):
    removed hardcoded white background so notification cards and unread badges render with proper dark mode contrast.
  - [includes/modals/user_manual_modal.php](file:///d:/xampp/htdocs/KingQuiry/includes/modals/user_manual_modal.php):
    themed tab navigation, numbered badges, guide containers, and formula blocks.
- Dropdown Menus and Notifications:
  - Formatted `.dropdown-menu` with dark elevation (`#0f1f2b`), borders (`#1e3a4e`),
    legible `.dropdown-item` hover states (`#162838`), and dark-safe headers.
- Forms & Controls:
  - Styled `.form-control`, `.form-select`, `input`, and `textarea` with dark slate
    backgrounds (`#0a1620`), borders (`#24415c`), off-white inputs (`#f1f5f9`),
    and gold focus glows (`#d8ad63`).
- Tables & Stat Cards:
  - Applied dark theme table variables (`--bs-table-bg`, `--bs-table-color: #f1f5f9`,
    `--bs-table-border-color: #1e3a4e`), styled headers (`#162838`), and dark stat cards.
- Homepage Search Bar & Catalog:
  - Adaptive `.hero-search-bar` container, inputs, and autocomplete dropdowns in dark mode.
- Chat Assistant & Support Inboxes:
  - Styled floating chat message thread and land estimate collapse form for dark mode.
- Verification & Automated Tests:
  - Added [tests/dark_theme_verification_test.php](file:///d:/xampp/htdocs/KingQuiry/tests/dark_theme_verification_test.php)
    verifying 38 critical selectors and CSS variable integrity (100% pass).

## 2026-11-19 — Project Structure Organization and Codebase Cleanup

- Integrated live clock into main navigation bar ([includes/header.php](file:///d:/xampp/htdocs/KingQuiry/includes/header.php)):
  removed the redundant top dark bar ("Kings Land Properties") and relocated the
  live Philippine date and time directly to the top-right corner of the blue
  primary navbar with responsive font sizing and clean divider styling.
- Modularized modal architecture: extracted 400+ lines of inline modal dialogs
  from `includes/footer.php` into clean partials under `includes/modals/`
  (`contact_us_modal.php`, `notifications_modal.php`, `account_modals.php`,
  and `user_manual_modal.php`), shrinking `footer.php` to a concise 48-line file.
- Unified authentication handling: abstracted ~150 lines of duplicate POST
  processing across `client_login.php`, `agent_login.php`, and `admin_login.php`
  into `kq_process_user_login()` in `includes/auth.php`.
- Organized database assets: categorized 15 scattered SQL files under `database/`
  into `database/schema.sql` (single authoritative source of truth),
  `database/seeds/` (demo, testing, and property datasets), and
  `database/legacy_migrations/` (historical reference snippets).
- Eliminated dead files and development clutter: removed obsolete `scratch/` test
  scripts, the 0-byte `database/seed_runner.php`, and the dead `property_map.php`
  redirect. Relocated the CLI password tool to `scripts/update_admin_password.php`.
- Database auto-schema migration resilience at `config/db.php`: resolved 503 "The
  service is temporarily unavailable" error caused by strict table validation on
  pre-existing databases missing newly introduced tables (such as
  `security_rate_limits`, `agent_dismissed_appointments`, or
  `agent_inquiry_assignments`).
- Automated DDL creation & column repair: in non-production environments, missing
  tables and columns (`properties.isDeleted`, `properties.deletedAt`,
  `property_locations.isDeleted`, `users.phone`, `users.address`,
  `users.accountStatus`, `property_transactions.paymentMethod`) are now automatically
  detected and created safely without data loss, eliminating manual migration steps.

## 2026-11-18 — Public Navigation and Information Organization

- Simplified the public navbar to Properties, Resources & Guides, About Us,
  Contact Us, sign-in/registration, Help, and the theme toggle. The brand links
  home, and the agent directory remains under About Us > Our Team.
- Set desktop navigation text to 16px with 44px action targets; smaller screens
  retain the scrollable three-line menu.
- Kept a role-aware Dashboard link in the shared navbar across pages and aligned
  Contact Us and utility controls as a separate group on the right.
- Changed the brand label to a plain Home link with extra spacing from the
  logo; both continue to open the home page.
- Added a responsive active-agent directory and individual contact profiles
  with validated email/telephone links; communication remains outside the app.
- Added an information center for the buyer guide, property advice, pricing
  inquiry, company overview, service scope, testimonials, and FAQs.
- Withheld unconfirmed pricing figures until agents verify the current prices
  and complete payment terms.
- Kept the shared light/dark theme preference across public, client, agent, and
  administrator pages and improved dark-theme contrast in both dashboards.
- Added a featured-properties section backed by the existing recommendation
  ranking; cold-start listings are shown without a fabricated match percentage.
- Kept the supported listing categories and statuses consistent across entry
  and management: Residential, Commercial, Farm Lot, Beach Lot, Condo; Available,
  Reserved, and Sold.
- Clearly noted missing verified market statistics, company-history details,
  and testimonials rather than inventing business claims.
- Did not add Top/Urgent property categories because the system has no curation
  fields or agreed selection rules for them.

## 2026-11-18 — Pre-Presentation Test Suite, Deduplication & Agent Assignment

- **Idempotent Presentation Demo Seed Data** ([database/seed_demo_presentation_data.sql](file:///c:/xampp/htdocs/kingquiry/database/seed_demo_presentation_data.sql)):
  - Added dedicated seed script generating three authorized demo accounts: Client (`client.demo@kingquiry.ph`), Sales Agent (`agent.demo@kingquiry.ph`), and Administrator (`admin.demo@kingquiry.ph`) with bcrypt hashes.
  - Included sample Available and Sold listings without altering live customer records.
- **Inquiry & Payment Deduplication**:
  - Implemented a 120-second content deduplication window for inquiries on [property_detail.php](file:///c:/xampp/htdocs/kingquiry/property_detail.php), preventing intentional or accidental duplicate submissions of the same message to the same property.
  - Implemented payment deduplication on [includes/transaction_tracking.php](file:///c:/xampp/htdocs/kingquiry/includes/transaction_tracking.php) checking for duplicate reference numbers or identical submissions within 120 seconds.
- **Dual Payment Ledger Reconciliation Policy**:
  - Reconciled operational tracking payments (`property_transaction_payments`) and accounting records (`payments`) by matching counterpart entries via reference number / amount while accumulating distinct installment records.
- **Dedicated Assigned Agent Card on Client Dashboard** ([client_dashboard.php](file:///c:/xampp/htdocs/kingquiry/client_dashboard.php)):
  - Surfaced the assigned sales agent's name, verified contact number, email, and direct communication actions (Call/SMS and Chat) directly in the client's tracking card and modal window.
- **Automated Comprehensive Test Suite** ([tests/comprehensive_system_test.php](file:///c:/xampp/htdocs/kingquiry/tests/comprehensive_system_test.php)):
  - Created automated test runner verifying 1-hour anti-overlap scheduling conflicts, transaction pipeline state transitions, reconciliation calculations, RBAC security, and machine learning utility scoring.

## 2026-11-18 — Property Request Reliability

- Added strict future date/time validation for viewing requests using
  `Asia/Manila`, including rejection of invalid calendar dates and past times.
- Added one-time per-session property request tokens and client/IP throttling to
  reduce accidental duplicate submissions and request spam.
- Rechecked and locked listing availability inside both viewing and inquiry
  transactions to prevent requests being recorded after a listing is removed or
  sold.
- Made inquiry and tracking-event persistence atomic, and separated
  post-commit notification failures from the success status of saved requests.
- Reported unsuccessful client/agent notification delivery without discarding a
  committed inquiry or viewing request.
- Removed the artificial minimum match score from recommendations and stopped
  presenting a cold-start listing as a measured match.
- Required document verification before payment confirmation for non-cash
  methods; only Spot Cash can use the direct transition.
- Moved transaction-stage, payment-review, and viewing-status notifications
  after their database commits so notification/email failures cannot undo a
  saved business update.
- Connected the client dashboard's payment-reporting flow to staff review,
  restricted reported methods to Cash, Bank Transfer, and Check, and added
  CSRF, replay protection, and request throttling.
- Validated transaction money precision and rejected payment entries submitted
  for non-payment milestones.
- Expanded the algorithm guide with the property-request validation, locking,
  conflict, persistence, and notification rules; added edge-case tests for
  same-day and malformed viewing times.

## 2026-11-18 — Algorithm Guide and Payment / Contact Cleanup

- Added `ALGORITHM:` source markers and a README index for the system's main
  recommendation, price-estimation, appointment-conflict, transaction-stage,
  inventory-insight, and chatbot decision logic.
- Updated the estimator documentation to describe the implemented batch
  gradient descent model and weighted-median fallback.
- Removed GCash from payment entry options and validation, including new-install
  schema definitions; existing payment records were left unchanged.
- Removed the duplicate Contact Us action from the shared footer; the navbar
  contact button and modal remain available.
- Removed destructive database resets from request-time schema checks; an empty
  database may be initialized, while a partial schema now fails without
  dropping existing data.
- Made viewing conflict checks transactional per property, added agent schedule
  conflict checks, and separated notification failure from booking success.
- Required positive verified payments before payment confirmation and full
  verified payment coverage before a transaction can be completed.
- Added CSRF checks and strict local-route redirects to notification actions;
  failed dismissals now stay visible and report an error.
- Added secure session-cookie defaults, idle session expiration, active-account
  session refresh, session-ID/CSRF rotation at login, and stopped storing
  password hashes in session data.
- Added persistent hashed-key throttling for registration, sign-in, and chat
  write requests, plus additive migrations for rate-limit storage and agent
  inquiry ownership.
- Restricted agent transaction access to assigned appointments/inquiries and
  added a concurrency-safe inquiry-claim workflow.
- Added production-mode database safety guards, Apache source/data-directory
  protections, CSRF-protected logout and backup export, and baseline CI checks.
- Added explicit coordinate and agent-assignment migrations; production now
  validates required migration-created tables and columns without request-time
  DDL.
- Added the missing legacy notifications-table migration and corrected the
  documented migration sequence.
- Keyed request-throttling digests with a production-required secret to prevent
  offline guessing of hashed email/IP identifiers.
- Prevented production email notifications from leaking message contents into
  local logs and made outbound-mail failures explicit.
- Hardened Apache access rules for source, dependency, manifest, and backup
  paths; blocked script-like image paths while keeping static assets accessible.

## 2026-11-17 — Inventory Editing and Portal Navigation

- Added full property-detail editing from the admin inventory, including safe optional image replacement and preservation of the listing's map pin.
- Added fixed hamburger drawers with full-width navigation links for admin, agent, and client dashboards at desktop and mobile sizes.
- Documented the actual weighted property-ranking and batch-gradient-descent estimation algorithms beside their implementations.

## 2026-11-17 — Dashboard Layout and Branding Polish

- Added responsive left-side navigation to the client and agent dashboards and removed duplicated or luxury-themed headings.
- Made the property publishing forms adapt their columns to the available content width, and ensured the admin workspace fills its layout.
- Reworded admin inventory insights and property estimates to describe the actual data without technical or appraisal claims.
- Removed the logo's white backing and added a transparent light-colored variant for dark backgrounds.

## 2026-11-17 — Official Logo and User Guide Refresh

- Added the supplied 4 Kings Realty Services logo to the shared navigation and footer, authentication pages, role dashboards, customer chat, and admin portal.
- Redesigned the User Manual modal with responsive numbered tabs, clearer section headings, dark-theme contrast, and a single close control.

## 2026-11-17 — Property Map and Admin UI Cleanup

- Moved admin destinations into the left sidebar and removed duplicate admin links from the global header.
- Removed duplicate modal footer **Close** buttons where the header X already closes the dialog.
- Fixed a missing recommendation-image helper that stopped property detail pages before the map and footer scripts could render; verified the map and tiles load.
- Removed stale map-explorer styling and kept the property location map on property details.
- Removed the admin dashboard's duplicate hero styles and kept backup/SQL export controls out of the admin UI.

## 2026-11-17 — Capstone System Integration & Optimization (Four Academic Pillars)

- **Course 1: Fundamentals of Analytics Modeling (Sir Fam)**:
  - Anti-Overlap Viewing Validation ([property_detail.php](file:///c:/xampp/htdocs/kingquiry/property_detail.php)): Implemented deterministic 1-hour temporal collision detection (`ABS(TIME_TO_SEC(TIMEDIFF(appointmentTime, ?))) < 3600`) to strictly eliminate concurrent or overlapping trippings on the same parcel.
  - Mathematical Property Valuation: Surfaced transparent breakdown models including price per square meter ($P_{sqm} = \frac{\text{Price}}{\text{LotArea}}$) and total valuation calculations in catalog, direct property cards, and estimating widgets.
- **Course 2: Fundamentals of Business Analytics (Sir Kalo)**:
  - Executive Business Dashboard ([admin_dashboard.php](file:///c:/xampp/htdocs/kingquiry/admin_dashboard.php)): Integrated interactive sales and revenue trajectory analytics using dynamic Chart.js line charts (`#adminRevenueTrajectoryChart`) with responsive hover tooltips and monetary formatting.
  - Prescriptive Analytics Engine: Implemented municipality-level Demand-to-Supply Index ($DSI = \frac{\text{Inquiries} + \text{Appointments}}{\text{Listings} + 1}$) matrix generating prescriptive inventory expansion and price adjustment recommendations.
- **Course 3: Systems Administration and Maintenance (Sir Sadd)**:
  - Role-Based Access Control (RBAC): Enforced strict multi-tier role boundaries separating Admin, Sales Agents, and Clients across views and API endpoints.
  - Audit Trail Activity Logs: Created modal viewer in Admin Dashboard inspecting the latest system audit events (`auditlogs`) with actor attribution, action type, IP address, and timestamp.
  - Disaster Recovery Database Backup ([admin_backup.php](file:///c:/xampp/htdocs/kingquiry/admin_backup.php)): Added authenticated one-click SQL dump export utility with automated audit logging.
- **Course 4: Web Systems, Technologies & Machine Learning (Sir Calvin)**:
  - Property Similarity Ranking ([includes/ml_recommender.php](file:///c:/xampp/htdocs/kingquiry/includes/ml_recommender.php)): Uses a deterministic weighted score for price proximity (40%), category match (25%), municipality match (20%), and area proximity (15%), surfaced as personalized cards on `property_detail.php` and catalog.
  - Indicative Price Estimation ([includes/property_estimator.php](file:///c:/xampp/htdocs/kingquiry/includes/property_estimator.php)): Trains normalized linear regression with batch gradient descent and falls back to a weighted median of comparable price-per-square-meter rates.
  - Multi-tier MVC-style modular codebase with clean separation of presentation, business logic, and prepared SQL data layers.
- **Business Workflow & Reservation Policy**:
  - 7-Day Reservation Grace Period & Contract Pathways ([client_dashboard.php](file:///c:/xampp/htdocs/kingquiry/client_dashboard.php), [transaction_tracking.php](file:///c:/xampp/htdocs/kingquiry/transaction_tracking.php)): Surfaced legal warnings regarding the 7-day down payment grace period, Contract to Sell (CTS) for installments, Deed of Absolute Sale (DOAS) for spot cash, and buyer-shouldered title transfer and taxes.
  - Chatbot Knowledge Expansion ([chat_api.php](file:///c:/xampp/htdocs/kingquiry/chat_api.php)): Enriched rule-based chatbot with land estimation formulas, reservation policies, and human agent handoff logic.
- **UI/UX & Accessibility Enhancements**:
  - Dark / Light Mode Switcher ([includes/header.php](file:///c:/xampp/htdocs/kingquiry/includes/header.php), [assets/js/app.js](file:///c:/xampp/htdocs/kingquiry/assets/js/app.js), [assets/css/style.css](file:///c:/xampp/htdocs/kingquiry/assets/css/style.css)): Persistent theme toggle in navbar, zero-FOUC initialization script in head, and complete theme palettes for modals, cards, and tables.
  - User Manual & Help Modal ([includes/footer.php](file:///c:/xampp/htdocs/kingquiry/includes/footer.php)): Integrated comprehensive tabbed modal accessible from anywhere on the platform.

## 2026-11-17 — Live Manila Clock and Chat Auto-Replies

- Added a server-synchronized live clock in the Asia/Manila timezone to the shared site header.
- Expanded the rule-based chat assistant with automatic replies for greetings, estimates, appointments, contact details, and property questions regardless of admin presence; unknown questions receive a handoff acknowledgment.

## 2026-11-17 — Restore Catalog to 20 Backup Listings

- Restored 11 non-sample records from the existing 32-property database backup to bring public Available listings to 20; the Sold listing remains hidden.
- Archived the 11 previously added demo records so they are excluded from the public catalog, map, direct property pages, and estimate calculations.
- Added licensed representative photos with visible creator and license credits; replace these with actual property photos before live sale listings.
- Added an idempotent SQL script at [database/catalog_restore_20_listings.sql](./database/catalog_restore_20_listings.sql) for the restore.
- Clarified the catalog total versus its per-page display limit, added an immediate chat acknowledgment while an admin is online but may be busy, and excluded archived sample listings from public maps and estimate calculations.

## 2026-11-17 — Catalog Navigation and Contact Information Updates

- Changed the guest navigation label from **Client Sign in** to **Sign In**.
- Updated the Contact Us modal with phone number **0965 803 0933**, location **Munting Indang, Nasugbu, Batangas**, and **Always Open** availability; synchronized the call, copy, and fallback phone number.
- Removed the duplicate **Close** button from the Contact Us modal, leaving the top X control.
- Updated the catalog footer location and removed the dash before **Your trusted** in the catalog subtitle.

## 2026-11-17 — Multi-agent viewing dispatch, notification modal, auth redirection, and admin layout fixes

- Multi-agent viewing request dispatch and claiming: when a client requests a
  viewing, all active agents receive notifications. The first agent to confirm
  the request claims the viewing (`agentID` column on `appointments`). Other
  agents are informed that the viewing was taken by that agent, and can dismiss
  the item with an 'X' close button backed by `agent_dismissed_appointments` and
  smooth AJAX removal.
- Notification pop-up modal: removed the static "Property updates and
  notification" card from `client_dashboard.php` and replaced the navbar dropdown
  link "View in client dashboard" with "View all". Clicking "View all" opens a
  centralized `#allNotificationsModal` pop-up window in `includes/footer.php` with
  tabbed filtering, full history, and instant mark-as-read.
- Authentication redirection and navigation cleanup: renamed the header navigation
  link from "Client Sign in" to "Sign in" in `includes/header.php`. Updated client
  authentication flow across `client_login.php`, `login.php`, `admin_login.php`,
  and `agent_login.php` to direct clients directly to the main property catalog
  (`index.php`) upon signing in instead of the dashboard.
- Admin dashboard layout and header de-duplication at `admin_dashboard.php`:
  removed the redundant second header and hero banner, keeping the unified top
  control room header from `includes/admin_page_header.php`. Removed the nested
  inner `.kq-admin-shell` wrapper and local `1280px` constraint, allowing all
  cards, analytics charts, and tables to expand to the full 1440px control room
  container. Placed a quick `🏘️ Property list` jump button into the Live business
  analytics header toolbar.
- Interactive location view hover preview: updated property map pins to display
  listing details in a hoverable pop-up card with a smooth 0.5-second hover
  delay instead of requiring pin clicks.
- Database schema updates: added `agentID` foreign key to `appointments` and
  created `agent_dismissed_appointments` table in `database/schema.sql` with
  idempotent runtime setup in `includes/appointment_management.php`.

## 2026-11-16 — Fix Undefined `kq_admin_pages()` Fatal Error After Merge

- **Restored Missing Include Dependencies in Header** ([includes/header.php](file:///c:/xampp/htdocs/kingquiry/includes/header.php)):
  - During the git merge of remote `main` (commit `8918427e`), `require_once __DIR__ . '/admin_layout.php';` and `require_once __DIR__ . '/notifications.php';` were accidentally dropped, causing a fatal error `Call to undefined function kq_admin_pages()` when loading [admin_dashboard.php](file:///c:/xampp/htdocs/kingquiry/admin_dashboard.php).
  - Restored both required files and added defensive `function_exists('kq_admin_pages')` guard to eliminate any possibility of uncaught fatal errors.
- **Admin Dashboard Cleanup** ([admin_dashboard.php](file:///c:/xampp/htdocs/kingquiry/admin_dashboard.php)):
  - Removed duplicate legacy "Manage properties" table that was re-introduced at the bottom of the page during the merge.
  - Restored the quick-jump `🏘️ Property list` button in the header toolbar and the `property_trashed` toast notification.


## 2026-11-16 — Top-of-Card Agent Rating with Instant Feedback & Auto-Fade

- **Top-of-Card Sales Agent Rating Banner** ([client_dashboard.php](file:///c:/xampp/htdocs/kingquiry/client_dashboard.php)):
  - Placed a dedicated rating prompt at the **very top** inside each completed property tracking card (`.kq-top-rating-banner`) for transactions that have not yet been reviewed.
  - Displays the closing sales agent's name (`Rate your sales agent (Agent Name):`) alongside 1–5 clickable gold stars with interactive hover preview.
  - Isolated click events via `event.stopPropagation()` to ensure rating clicks do not inadvertently trigger the property card's modal pop-up window.
  - Submits ratings asynchronously (AJAX) to `client_dashboard.php` (`submit_agent_review`), recording the rating and agent association in `agent_reviews`.
- **Instant "Thanks for the feedback" Confirmation & Auto-Fadeout**:
  - Upon selecting a rating, the top banner instantly replaces the stars with a green confirmation badge: `Thanks for the feedback`.
  - After 3 seconds, the confirmation banner smoothly transitions opacity to 0, collapses height, and cleanly removes itself from the card layout without page reload.
  - Dynamically synchronizes rating status with the transaction modal and the client review history on the dashboard.
- **Dedicated Styling** ([assets/css/style.css](file:///c:/xampp/htdocs/kingquiry/assets/css/style.css)):
  - Added `.kq-top-rating-banner` and `.kq-top-star-btn` styles with scale transformations, active gold highlight `#f59e0b`, and smooth transitions.


## 2026-11-16 — Fixed Squeezed Modal & Nested DOM Overflow on Transaction Tracking

- **Fixed Modal DOM Structure & Sizing** ([transaction_tracking.php](file:///c:/xampp/htdocs/kingquiry/transaction_tracking.php)):
  - Removed duplicate trailing HTML input and premature closing `</div>` tags inside `#recordNextModal<ID>` that were previously closing `modal-body`, `modal-content`, and `modal-dialog` early.
  - This premature closure had caused the form fields (sale price, note, advance button, payment history) to spill outside the modal onto the bottom of the page and squished the modal dialog into a narrow left column.
  - Added `modal-dialog-scrollable`, `rounded-4`, and gradient header styling (`linear-gradient(135deg, #142d4e 0%, #174a70 100%)`) to ensure the modal stays centered, properly proportioned (`modal-lg`, 800px max width on desktop), and vertically scrollable without layout distortion.
  - Added automated DOM tree validation test to prevent future nesting errors.


## 2026-11-16 — Client Transaction Tracking Modal Window Upgrade

- **Clickable Property Tracker Card & Popup Window** ([client_dashboard.php](file:///c:/xampp/htdocs/kingquiry/client_dashboard.php)):
  - Converted the cluttered inline tracking sections into an organized, responsive list of **Clickable Property Cards**.
  - Each card compactly displays the tracking reference (`#KQT-<ID>`), property title, location, viewing schedule, stage status badge, Spot Cash indicator, overall progress bar (percentage & step count), and a **"View tracking →"** action button.
  - Clicking any property card triggers an interactive Bootstrap pop-up modal (`#trackingModal<ID>`) displaying:
    1. **Transaction Pipeline**: Sequential milestone stepper with real-time indicators for passed, active, and upcoming stages (with Spot Cash skipping `document_verification`).
    2. **Overall Progress**: Percentage bar, current milestone description, and last updated timestamp.
    3. **Payments & Paperwork / Documents**: Detailed records of verified face-to-face payments, amounts (₱), payment methods, reference numbers, ORs, agent notes, and confirmation status.
    4. **Transaction Updates**: Chronological event history timeline with timestamps, agent name, and client-visible update notes.
    5. **Finalization & Review**: If completed, displays verified sale price and a direct action button to rate the sales agent.
- **Enhanced Card Styles & Accessibility** ([assets/css/style.css](file:///c:/xampp/htdocs/kingquiry/assets/css/style.css)):
  - Added `.kq-tracking-card-btn` with smooth hover elevation, border highlight, and title color transitions for intuitive clickability.


## 2026-11-16 — Spot Cash Conditional Document Verification, Auto-Exclusion of Sold Properties, and 1-5 Star Agent Rating

- **Conditional Document Verification Stage (Spot Cash vs. Bank Transfer / Check)**:
  - **Stage Transition Logic** ([includes/transaction_tracking.php](file:///c:/xampp/htdocs/kingquiry/includes/transaction_tracking.php)):
    - Updated `property_transaction_next_stages($stage, $paymentMethod)`: When `paymentMethod === 'Spot Cash'`, the pipeline from `payment_in_process` automatically skips `document_verification` and advances straight to `payment_confirmation` (or `completed`).
    - For `Bank Transfer`, `Check`, and paperwork, `document_verification` remains an explicit, required milestone.
    - Updated `advance_property_transaction()` to persist `paymentMethod` in `property_transactions.paymentMethod`.
  - **Dynamic Modal UI for Agents** ([transaction_tracking.php](file:///c:/xampp/htdocs/kingquiry/transaction_tracking.php)):
    - Inside `#recordNextModal<ID>`, added real-time JavaScript: selecting **"Spot Cash"** dynamically hides the `document_verification` option from the "Choose next step" dropdown, auto-selects `payment_confirmation`, and displays a `⚡ Spot Cash: Document verification stage is automatically skipped` alert.
    - Selecting **"Bank Transfer"** or **"Check"** restores the `document_verification` milestone.
    - In the modal pipeline stepper, if Spot Cash is active, `document_verification` is omitted from the step count and visual indicators.
  - **Client Tracking & Notifications** ([client_dashboard.php](file:///c:/xampp/htdocs/kingquiry/client_dashboard.php)):
    - In the client progress stepper, if the transaction was processed with `Spot Cash`, `document_verification` is dynamically filtered out, showing seamless progression from `Payment in process` directly to `Payment confirmation`.
    - Stage notifications inform the client that Spot Cash payment was received and document verification was bypassed.
- **Immediate Removal of Sold Properties from Public Listings**:
  - **Auto-Sold Status Transition** ([includes/transaction_tracking.php](file:///c:/xampp/htdocs/kingquiry/includes/transaction_tracking.php)):
    - In `advance_property_transaction()`, when the transaction stage is finalized as `completed`, the property's status in `properties` table is immediately updated to `Sold`.
    - Because [index.php](file:///c:/xampp/htdocs/kingquiry/index.php) and interactive maps query `status = 'Available'`, the sold property automatically disappears from all public search and catalog listings.
  - **Off-the-Market Notice on Property Detail** ([property_detail.php](file:///c:/xampp/htdocs/kingquiry/property_detail.php)):
    - If accessed directly, sold properties display a prominent `🏷️ SOLD & CLOSED` banner.
    - Replaced the viewing booking and inquiry forms with a clean `Off the Market — Property Officially Sold` card and direct link to browse available properties.
- **Interactive 1–5 Star Sales Agent Rating System** ([client_dashboard.php](file:///c:/xampp/htdocs/kingquiry/client_dashboard.php)):
  - Updated `$completedReviewRows` query to identify and display the sales agent's full name (`agentName`) who handled the transaction.
  - Built an interactive 1–5 star rating widget (`★ ★ ★ ★ ★`) with real-time hover/click preview and dynamic rating labels (`1 Star - Poor` up to `5 Stars - Excellent!`).
  - Added a direct **"⭐ Rate sales agent"** action button inside the completed transaction card in the Client Dashboard tracking section to jump straight to `#client-reviews`.
  - Displays submitted ratings with gold stars, submission date, and client feedback.
- **Database Schema**:
  - Added `paymentMethod VARCHAR(50) DEFAULT NULL` column to `property_transactions` in MySQL and updated [database/schema.sql](file:///c:/xampp/htdocs/kingquiry/database/schema.sql).

## 2026-11-16 — Post-Viewing Pipeline Redesign & Stage-Completed Client Notifications

- **Redesigned Post-Viewing Agent Flow** ([transaction_tracking.php](file:///c:/xampp/htdocs/kingquiry/transaction_tracking.php)):
  - Redesigned the **"Record next step"** modal into a unified, sequential post-viewing pipeline:
    `1. Viewing requested` ➔ `2. Viewing confirmed` ➔ `3. Viewing completed` ➔ `4. Payment in process` ➔ `5. Document verification` ➔ `6. Payment confirmation` ➔ `7. Successful / completed`.
  - Added an interactive visual pipeline stepper inside the modal showing current stage progress, passed steps, and upcoming steps.
  - Added context-aware agent action guidance explaining exactly what to do at each stage.
  - Removed duplicate disjointed forms; unified into a single streamlined submission that supports optional payment/paperwork details (amount, payment method, reference) and verified sale price.
- **Stage-Completed Notifications to Clients** ([includes/transaction_tracking.php](file:///c:/xampp/htdocs/kingquiry/includes/transaction_tracking.php)):
  - Implemented `get_transaction_stage_notification()` to generate specific, informative client notifications upon advancing each stage:
    - **Viewing completed**: Alerts client that the site visit was conducted and completed; next stage is Payment Processing.
    - **Payment in process**: Alerts client that payment coordination and reservation/down payment processing is underway.
    - **Document verification**: Alerts client that payment processing is done and verified by the sales agent, and the transaction has advanced to Document Verification.
    - **Payment confirmation**: Alerts client that document verification has been completed, and the agent is finalizing official receipts and clearance.
    - **Successful / completed**: Alerts client that payment confirmation is complete, transaction is finalized with verified sale price, and invites them to download their summary and review their agent.
  - Removed the previous auto-advance bug that force-skipped `viewing_completed` straight into `payment_in_process`, restoring `viewing_completed` as a real, distinct milestone.


## 2026-11-16 — Swapped Property List to Prime Location & Removed Location Management

- **Swapped Property List Position** ([admin_dashboard.php](file:///c:/xampp/htdocs/kingquiry/admin_dashboard.php)):
  - Moved the **🏘️ Property list** (`#property-list`) directly beneath the analytics charts (viewing requests and property distribution), placing active catalog inventory in the most prominent position on the dashboard.
  - Placed the **🗑️ Trashbin** button directly on the Property List header for instant access.
  - Removed the duplicate property list section from the bottom of the dashboard.
- **Completely Removed Location Directory Management**:
  - Removed the **Manage locations** section (`#locations`), table listing, and the "+ Add Location" form from [admin_dashboard.php](file:///c:/xampp/htdocs/kingquiry/admin_dashboard.php).
  - Cleaned up all location management backend handlers (`add_location`, `set_location_status`, `edit_location`, `delete_location`, `restore_location`, `permanent_delete_location`, `bulk_restore_locations`, `bulk_permanent_delete_locations`).
  - Simplified the **Trashbin modal** (`#trashbinModal`) into a dedicated Property Trashbin by removing the location tabs, location listing pane, and location-specific delete/restore action controls and modals.
  - Removed the **📍 Locations** link from the Admin Manage dropdown in [includes/header.php](file:///c:/xampp/htdocs/kingquiry/includes/header.php).
  - Maintained read-only location population via `get_property_locations()` for publishing properties and the land price estimator.


## 2026-11-16 — Property Inventory Reduction to 10 Properties & Admin Navigation Upgrade

- **Minimized Active Inventory to 10 Curated Properties**:
  - Reduced the active property inventory from 32 down to exactly **10 diverse properties** across all 5 core property categories (2 Residential, 2 Commercial, 2 Farmlot, 2 Beachlot, 2 Condo) in major Batangas municipalities (Nasugbu, Lipa City, Calatagan, Batangas City).
  - Preserved Property `#17` (*Port Access Industrial & Commercial Lot*) with its active appointments and transaction tracking records (`#KQT-1`).
  - Created a complete backup of all 32 original property listings in [database/backup_all_32_properties.sql](file:///c:/xampp/htdocs/kingquiry/database/backup_all_32_properties.sql).
- **Property List Visibility & Fast Navigation in Admin Dashboard** ([admin_dashboard.php](file:///c:/xampp/htdocs/kingquiry/admin_dashboard.php)):
  - Added direct jump button **"🏘️ Property list (10)"** in the top dashboard hero action bar.
  - Added a direct link in the top **Property Listings** KPI summary card to jump straight to `#property-list`.
  - Added `id="property-list"` to the section, complete with an instant real-time search filter (`#propTableSearch`), direct link to live listings (`👁️ View`), and status update controls.
  - Added **"🏘️ Property list"** to the global Admin **Manage** navigation dropdown in [includes/header.php](file:///c:/xampp/htdocs/kingquiry/includes/header.php).

## 2026-11-16 — Transaction Tracking Modal Window Upgrade ("Record Next Step")

- **Modal Window for Recording Transaction Steps** ([transaction_tracking.php](file:///c:/xampp/htdocs/kingquiry/transaction_tracking.php)):
  - Replaced the large inline forms embedded directly within each table row's "Record next step" column with a clean, compact action button: **"📝 Record next step"**.
  - Clicking the button opens a dedicated **"Record Next Step" modal window** (`#recordNextModal<ID>`) specific to that transaction.
  - The modal dialog contains:
    - **Transaction Overview Header**: Displays tracking reference, property title, client info, viewing schedule, and current stage badge.
    - **Advance Milestone Form**: Dropdown to select the next verified stage, optional client-visible update note, and verified sale price.
    - **Face-to-Face Payment & Paperwork Form**: Allows agents to quickly mark in-person paperwork status as "Confirmed" or "In process" with optional notes.
    - **Payment Records**: Displays client-submitted payment history and review actions if applicable.
  - Closed/completed transactions display a status badge and a compact **"👁️ View"** button to inspect historical milestone records.

## 2026-11-16 — Registration Success Screen & Direct Login Redirection

- **Post-Registration Confirmation View** ([register.php](file:///c:/xampp/htdocs/kingquiry/register.php)):
  - Removed the previous behavior where the registration form was re-rendered with emptied text inputs.
  - When an account is created, the form and input fields are completely replaced with a dedicated success screen and a prominent button **"Back to Login Screen"** that redirects directly to the login page ([client_login.php](file:///c:/xampp/htdocs/kingquiry/client_login.php)).
- **Clean Login Screen** ([client_login.php](file:///c:/xampp/htdocs/kingquiry/client_login.php)):
  - Kept the login page clean and free of redundant banners upon redirecting from registration.

## 2026-11-16 — Trashbin Modal UX Upgrade: Direct Soft-Delete, modal-xl Size & Select Buttons

- **Instant One-Click Move to Trashbin (Zero Intermediate Confirmation)**:
  - Removed confirmation prompts when moving a location or property into the trashbin. Clicking "🗑️ Delete" on any location in Manage Locations or "🗑️ Move to Trash" on a property now immediately soft-deletes the item to the trashbin (`isDeleted = 1`) without interruption.
  - Displays an instant toast notification with property/location name and an immediate **"Open Trashbin"** button.
  - Retained strict permanent deletion confirmation prompts with red buttons (`btn-danger`) exclusively for irreversible deletions inside the trashbin modal.
- **Enlarged Trashbin Modal (`modal-xl`)**:
  - Expanded the `#trashbinModal` size to extra-large (`modal-xl`, up to 1140px width on desktop) to provide ample room for viewing wide item details, category badges, dates, and action controls without cramping.
- **Dedicated "Select" Buttons on All Items**:
  - Added explicit interactive "Select" buttons (`.trash-select-btn`) to every individual property and location card in the trashbin.
  - Clicking "Select" dynamically toggles button appearance between outlined (`btn-outline-primary`, "Select") and solid active blue (`btn-primary text-white`, "✓ Selected").
  - Synchronized with "Select all" master checkboxes for both **🏠 Properties** and **📍 Locations** tabs.
  - Wired up bulk actions for locations: "↺ Restore Selected" and "🗑️ Delete Selected" with custom bulk confirmation modal.

## 2026-11-16 — Admin Manage Locations Edit/Delete Actions & In-Frame Trashbin Modal Button

- **Location Directory Trashbin Integration** ([admin_dashboard.php](file:///c:/xampp/htdocs/kingquiry/admin_dashboard.php)):
  - Added `isDeleted TINYINT(1) NOT NULL DEFAULT 0` and `deletedAt TIMESTAMP NULL DEFAULT NULL` support to `property_locations` table and schema ([database/schema.sql](file:///c:/xampp/htdocs/kingquiry/database/schema.sql)).
  - Updated location delete action in Manage Locations table to soft-delete locations to the Trashbin (`isDeleted = 1`, `deletedAt = CURRENT_TIMESTAMP`), hiding them from active directories and public listing filters while keeping properties intact.
  - Upgraded the **Trashbin Modal** with dedicated tabs: **🏠 Properties** and **📍 Locations**.
  - Trashed locations tab allows single-click location restoration (`↺ Restore`) and permanent removal (`🗑️ Delete`) with confirmation modal and red confirmation button.
  - Total trash counter badge on the Manage Locations button aggregates both trashed properties and locations.
  - Added toast notification on location deletion with a direct one-click **"Open Trashbin"** button.
- **Fixed Bulk Deletion Display Glitch**:
  - Removed duplicate un-nested modal dialog markup that was causing the bulk deletion confirmation dialog to display statically at the bottom of the Manage Locations frame.
- **Fixed Typography & Text Contrast**:
  - Scoped the hero `.text-muted` override strictly to the dark hero subtitle (`.kq-admin-shell > div.mb-4 > .text-muted`), preventing muted text across white cards, tables, and modals from turning white or unreadable.
  - Explicitly enforced high-contrast dark text (`#1a252f`) and clear muted grey (`#536579 !important`) for all card and modal headings, paragraphs, labels, spans, and tables.
  - Restored visible white text label `Trashbin / Archived Inventory` to the red header button.
- **Location Management Actions in Admin Dashboard** ([admin_dashboard.php](file:///c:/xampp/htdocs/kingquiry/admin_dashboard.php)):
  - Added **Edit** and **Delete** actions for each location in the Manage Locations table.
  - Implemented an **Edit Location Modal** allowing administrators to update Region Code, Region Name, Province, Municipality, and Active status. Updating a location synchronizes its canonical string across existing properties.
  - Implemented a **Delete Location Confirmation Modal** to safely remove unused locations with database safety handling.
- **In-Frame Trashbin Button in Manage Locations**:
  - Replaced the side-by-side persistent column with a dedicated, instantly visible **"Trashbin / Archived Inventory"** button located directly inside the Manage Locations card header.
  - Features an eye-catching red button (`btn-danger`) with a trash icon and live counter pill badge indicating the number of trashed listings.
  - Opens the complete **Property Trashbin (Archived Inventory)** management modal without cluttering the page or squeezing the location directory table.
- **Property Trashbin with Soft Delete & Toast Notification**:
  - Soft delete support: Moving a property to the trashbin flags `isDeleted = 1` and records `deletedAt = CURRENT_TIMESTAMP`, keeping listings hidden from public search, catalog, and bookings while preserving historical data.
  - Added an interactive **toast notification** that alerts the admin upon moving a property: *"The property has been moved to the trashbin"* with a direct one-click **"Open Trashbin"** button.
  - **Search & Sort**: Real-time instant search by title/location and sorting by Recently Trashed, Oldest Trashed, Price (Low to High, High to Low), and Name (A-Z, Z-A).
  - **Select All & Bulk Actions**: Dedicated "Select All" checkbox with multi-select bulk Restore and bulk Permanent Delete capabilities.
  - **Strict Confirmation Safety Prompts**:
    - Single property permanent deletion prompts with: *"Are you sure you want to delete (property name)?"* featuring a prominent red button (`btn-danger`).
    - Bulk permanent deletion prompts with a dedicated confirmation modal specifying the exact count of items being permanently deleted.
    - Added nested modal handling so delete confirmations stack smoothly above the trashbin modal.
- **Schema & Catalog Synchronization**:
  - Added `isDeleted TINYINT(1) NOT NULL DEFAULT 0` and `deletedAt TIMESTAMP NULL DEFAULT NULL` to the `properties` schema in [database/schema.sql](file:///c:/xampp/htdocs/kingquiry/database/schema.sql).
  - Updated catalog queries in [index.php](file:///c:/xampp/htdocs/kingquiry/index.php) and detail views in [property_detail.php](file:///c:/xampp/htdocs/kingquiry/property_detail.php) to filter out trashed properties.

## 2026-11-16 — Real-Time Property Update Notifications & Agent Viewing Request Alerts

- Implemented comprehensive in-app notification infrastructure in [includes/notifications.php](file:///c:/xampp/htdocs/kingquiry/includes/notifications.php):
  - Created `user_notifications` database table with auto-migration (`ensure_notifications_table`) and added schema definition to [database/schema.sql](file:///c:/xampp/htdocs/kingquiry/database/schema.sql).
  - Added notification lifecycle helper functions: `create_user_notification()`, `get_user_notifications()`, `get_user_unread_notification_count()`, `mark_notification_as_read()`, and `mark_all_notifications_as_read()`.
  - Upgraded `send_property_notification()` to simultaneously log in-app user notifications and dispatch fast non-blocking email logs to `logs/email_notifications.log`.
- **Client Property Update Notifications**:
  - Whenever a client's requested property receives an update (viewing confirmed, viewing completed, payment in process, document verification, payment confirmation, paperwork logged, or successful completion), an in-app notification is automatically generated for the client with direct links to [client_dashboard.php#transaction-tracking](file:///c:/xampp/htdocs/kingquiry/client_dashboard.php#transaction-tracking).
  - Added a dedicated **"Property Updates & Notifications"** panel on [client_dashboard.php](file:///c:/xampp/htdocs/kingquiry/client_dashboard.php) with unread counter, "Mark all as read" button, and direct navigation pills.
- **Sales Agent Viewing Request Alerts**:
  - Implemented `notify_agents_new_viewing()` and `notify_agents_new_inquiry()` in [includes/notifications.php](file:///c:/xampp/htdocs/kingquiry/includes/notifications.php).
  - When a client books a new property viewing on [property_detail.php](file:///c:/xampp/htdocs/kingquiry/property_detail.php), every active Sales Agent and Admin immediately receives an in-app notification specifying the client's name, property title, and appointment date/time.
  - Added a dedicated **"Viewing Alerts & Notifications"** section on [agent_dashboard.php](file:///c:/xampp/htdocs/kingquiry/agent_dashboard.php) with an unread badge and quick links directly to the appointment in the scheduling queue.
- **Global Navigation Notification Center**:
  - Added a Notification Bell icon in [includes/header.php](file:///c:/xampp/htdocs/kingquiry/includes/header.php) for logged-in users with a live unread badge counter.
  - Interactive dropdown menu displaying recent notifications, unread status indicators, direct links, and a one-click "Mark all as read" trigger.
  - Created [notifications_action.php](file:///c:/xampp/htdocs/kingquiry/notifications_action.php) to handle secure notification read status transitions, AJAX updates, and safe local redirects.

## 2026-11-16 — Streamlined 9-Stage Transaction Pipeline & Client Visual Progress Stepper

- Streamlined property transaction stages in [includes/transaction_tracking.php](file:///c:/xampp/htdocs/kingquiry/includes/transaction_tracking.php):
  - Removed deprecated `offer_negotiation` (Offer / negotiation) and `reservation_processing` (Reservation processing) stages, per real-world real estate operations where negotiation is conducted face-to-face and reservation is equivalent to the viewing request.
  - Finalized the streamlined 9-stage pipeline:
    1. `inquiry_received`: Inquiry received (0%)
    2. `contacting_client`: Contacting client (13%)
    3. `viewing_requested`: Viewing requested (25%)
    4. `viewing_confirmed`: Viewing confirmed (38%)
    5. `viewing_completed`: Viewing completed (50%)
    6. `payment_in_process`: Payment in process (63%)
    7. `document_verification`: Document verification (75%)
    8. `payment_confirmation`: Payment confirmation (88%)
    9. `completed`: Successful / completed (100%)
  - Configured frictionless post-viewing state transitions: `viewing_completed` ➔ `payment_in_process` ➔ `document_verification` ➔ `payment_confirmation` ➔ `completed`.
  - In `advance_property_transaction()`: smoothly auto-creates confirmed paperwork payment records when moving to `payment_confirmation` if not already present, preventing workflow lockouts.
- Built a visual 9-stage interactive pipeline stepper on [client_dashboard.php](file:///c:/xampp/htdocs/kingquiry/client_dashboard.php):
  - Added a dedicated "Transaction Pipeline" stepper box above overall progress, visibly displaying all 9 stages in sequence with stage number, status icon (completed checkmark, current active pulse, or pending), and "Stage X of 9" badge.
  - Added informative reassuring client notice: *"Viewing is complete. You can relax and wait while your sales agent completes the remaining stages."*
  - Updated overall progress percentage calculation based on the 9 stages (viewing completed at 50%, payment in process at 63%).
- Enforced 100% solid black font color (`#000000 !important`) across all timeline events, card descriptions, timestamps, and agent update tags in [client_dashboard.php](file:///c:/xampp/htdocs/kingquiry/client_dashboard.php) and [assets/css/style.css](file:///c:/xampp/htdocs/kingquiry/assets/css/style.css), completely eliminating low-contrast / washed-out white text.

## 2026-11-16 — Added 'Payment in process' transaction stage and agent face-to-face payment entry

- Added `payment_in_process` ("Payment in process") transaction stage to the transaction pipeline in `includes/transaction_tracking.php`:
  - Positioned directly after property viewing completion (`viewing_completed`), reflecting the real-world Philippine real estate workflow where payment discussions take place face-to-face during or right after property site visits.
  - **Automatic stage progression**: When an agent selects "Viewing completed" and clicks "Save update", the system records the viewing completed milestone and automatically advances the transaction stage directly to **"Payment in process"**, immediately revealing the face-to-face payment inspection form and advancing the client's progress bar.
  - Configured state machine transitions allowing transactions to advance smoothly from `viewing_completed` to `payment_in_process`, and onward to `reservation_processing`, `document_verification`, `payment_confirmation`, `completed`, or `cancelled`.
  - Also permitted transitions to `payment_in_process` from `offer_negotiation` and `reservation_processing`.
- Implemented `record_staff_transaction_payment()` helper function:
  - Enables agents and administrators to record verified face-to-face payments (cash, bank transfer, check, GCash, reservation fee, down payment, or spot cash) directly during on-site meetings.
  - Automatically records payment records in `property_transaction_payments`, logs tracking events in `property_transaction_events`, and dispatches real-time email notifications to the client.
  - Automatically advances active transactions from viewing stages directly to `payment_in_process` whenever a payment is logged.
- Streamlined face-to-face form & paperwork workflow:
  - Removed granular online payment details (amount, payment method, reference number) from the face-to-face form in `transaction_tracking.php`, recognizing that Philippine land & property deals are completed through physical paperwork and in-person agreements.
  - Form now automatically selects **"Confirmed"** by default, with an option to select **"In process"**, along with an optional paperwork note.
  - Implemented `record_staff_face_to_face_payment()`: Confirmed status advances transaction to `payment_confirmation` (displaying "Payment complete" on client timeline), while In process sets/keeps `payment_in_process`.
  - Removed client-side "Record a payment / Submit payment details" form from `client_dashboard.php`, updating client messaging to explain that agreements and payments are handled face-to-face through verified paperwork.
- Updated `client_dashboard.php`:
  - Fixed white/low-contrast text across the client dashboard: corrected `.text-muted` override that caused timestamps, notes, and agent update details to render in off-white on light cards, turning font colors to crisp, high-contrast black (`#000000`) across all timeline cards, tracking panels, and tables.
  - Timeline events for confirmed face-to-face payments (such as Spot cash) now clearly display **"Payment complete"** as the event title instead of echoing the prior viewing status.
  - Added `payment_in_process` to the client progress bar (`$progressStages`) for accurate percentage tracking.
  - Added informative warning alert banner explaining that the agent is actively inspecting/recording payment details following their viewing discussion.
  - Enabled client payment recording during `payment_in_process`.
  - Styled `payment_in_process` badges with warning pill badges across client and administrative views.
- Updated `admin_sales.php` and `admin_payments.php` to display and track `Payment in process` transactions.

## 2026-11-10 — Interactive property pin map picker, single-property detail map, catalog sorting, and account management

- Added an interactive Leaflet map picker feature when adding/publishing properties in both `admin_dashboard.php` and `agent_dashboard.php`:
  - Agents and Admins can click anywhere on the map or drag the pin marker to pin the exact coordinates (`latitude` and `longitude`) of the land.
  - Automatically pans and centers the map when a municipality is chosen, with an option to recenter.
  - Automatically migrates existing and fresh database schemas with `latitude` and `longitude` fields in the `properties` table.
- Enhanced the single-property detail map on `property_detail.php`:
  - Directly displays the exact coordinates pinned by the agent with an auto-opened branded pin popup.
  - Falls back smoothly to municipality center coordinates for legacy listings without custom coordinates.
- Retired the standalone Map Explorer page (`property_map.php`) and removed its navigation link from `includes/header.php`. Direct requests to `property_map.php` cleanly redirect to `index.php`.
- Integrated a dedicated, single-property pinned map into the property information view (`property_detail.php`):
  - Positioned prominently on the right side alongside the property details and above the request actions.
  - Displays exclusively the single pinned location of the selected property, with custom KingQuiry pin styling and auto-opening popup details (title, category badge, location, and price).
- Built a property sorting feature on the property catalog (`index.php`):
  - Descending price (`Price: High to Low`), ascending price (`Price: Low to High`),
    A to Z alphabetical title sorting (`Title: A to Z`), Z to A title sorting (`Title: Z to A`),
    and default `Newest First`.
  - Added dedicated catalog toolbar above the listing grid with quick sort selector and
    showing range display (`Showing X–Y of Z properties`).
- Added customizable properties-per-page selector with options for 10 properties (default),
  6 properties, 15 properties, 24 properties, or Show all.
- Built responsive numbered pagination below the property grid with Previous and Next controls
  that appears whenever matching properties exceed the chosen per-page limit:
  - Supports smart windowed ellipses for multi-page listings.
  - Retains all active search keywords, category filters, location filters, and sort options across page transitions.
  - Updates hero search bar with hidden inputs to maintain sort and page size preferences on new queries.
  - Synchronized verified listing stat counters to reflect the full matching database count.
- Added custom toolbar and pagination styling in `assets/css/style.css`.
- Removed the 3 highlight stat cards ("Verified listings", "Property portfolio", and "Client process") from `index.php` across all roles to streamline the catalog presentation.
- Added an account management dropdown to the top-right navbar in `includes/header.php`:
  once signed in, the user's name is displayed in place of the generic logout button.
- Scoped the display of the user role in account management (dropdown header, modal,
  and manage account page) to Admin and Sales Agent accounts only, hiding it on client accounts.
- Built an interactive "Manage Account" modal in `includes/footer.php` and dedicated
  page in `manage_account.php` allowing users to edit and update their password with
  validation (current password verification, 8-character minimum, and confirmation check).
- Added AJAX backend endpoint `change_password.php` with CSRF protection, secure
  password hashing, and instant modal feedback.
- Replaced the direct logout link with a red "Log Out" item in the user dropdown that
  triggers a confirmation pop-up modal (`#logoutConfirmModal`) prompting "Are you sure you
  want to log out?" with Cancel and Confirm actions.
- Added a prominent gold "Contact Us" button to the top-right navbar navigation
  in `includes/header.php`, visible only on the guest and client sides and
  hidden on the manager/staff side (`Admin` and `Sales Agent`).
- Added mobile Contact action beside the hamburger menu toggle, scoped to guest
  and client roles.
- Removed floating hero contact chip from `index.php` to keep the edge-to-edge
  hero clean and unobstructed.
- Added a responsive Contact Us modal in `includes/footer.php` (client/guest only)
  featuring:
  - Direct link and card for the official Facebook page
    [Kings Land Properties | Nasugbu | Facebook](https://www.facebook.com/p/Kings-Land-Properties-61568411772451/).
  - Contact number display (`+63 917 123 4567` / `0917 123 4567`) with one-tap
    calling (`tel:`) and a one-click copy button with clipboard feedback.
  - Nasugbu, Batangas office information and property service overview.
- Added clipboard copy helper and modal password AJAX submission in `assets/js/app.js`.
- Added custom styling in `assets/css/style.css` for user dropdown, avatar badge,
  contact button, and mobile action.

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