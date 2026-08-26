# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this project is

A Romanian-language PHP/MySQL order management system for a print shop (Color Print, deployed at `color-print.ro/magazincp/`). It is a classic LAMP-style app: PHP files mix HTML, SQL, and JS, with a single shared MySQL connection and no framework. The user-facing UI is in Romanian — keep user-visible strings in Romanian, but keep code, comments, and identifiers in English unless the existing file already does otherwise (several files mix Romanian/English in comments — match the surrounding style).

There is no package manager, no build step, no test runner, and no linter. **Do not invent one.**

## How to run it

This is a plain PHP + MySQL app intended for XAMPP/Apache on Windows. No `composer.json`, `package.json`, or build config exists.

- **Database**: `order_management_system` on `localhost` / `root` / (no password) — see `db.php`. Import schema manually; no migrations are tracked in the repo.
- **Web root**: drop the folder under `htdocs/` (or your equivalent) and visit `http://localhost/<folder>/login.php`.
- **Session/auth config**: `php.ini` at the repo root configures 30-day session cookies (`secure=1`, `httponly=1`). Production runs behind HTTPS.
- **Upload path**: `uploads/orders/<order_id>/` is created on demand by `upload_attachment.php` and must be writable.
- **One-off scripts**: `hash.php` prints a bcrypt hash for a placeholder password; `archive_orders.php` is a one-shot migration with a hard-coded password in the query string (`?pw=colorprint2010`) that moves delivered/cancelled orders to `archived_orders` and renumbers remaining `order_id`s. There are no unit tests — validation is by opening the page in a browser.

## High-level architecture

There is no routing layer — every `.php` file at the repo root is an endpoint that the browser navigates to directly. The shared `db.php` opens a mysqli connection into `$conn`; almost every page does `include 'db.php'` (or `require 'db.php'` / `require_once 'db.php'`) and then runs raw SQL.

Key files and what they own:

- `db.php` — single mysqli connection (`$conn`) to the `order_management_system` database. Every other PHP file includes it.
- `authenticate.php` / `login.php` / `logout.php` — login, password hashing (`password_hash` / `password_verify`), 30-day "Ține-mă minte" cookie + `remember_tokens` table.
- `dashboard.php` — main page. Auth-guarded; renders the orders table with filters, pagination (18 per page), pinned-orders strip, stat cards, the "add order" sidebar, notes modal, WhatsApp sender modal, and a hero header with time-of-day greetings. Hosts the `orderSliderPanel` iframe + `quietRefresh()` AJAX partial-refresh logic. **Big file (~106 KB) with PHP, SQL, HTML, CSS, and JS interleaved.**
- `view_order.php` — single-order detail view. Embeds itself in the dashboard slider via `?embedded=1`. Renders the article table (the "bon"), upload (Dropzone), edit forms, status stepper, SLA countdown, and the WhatsApp template-message modal.
- `add_order.php`, `update_order_status.php`, `cancel_order.php`, `delete_order.php`, `update_order_details.php`, `update_achitat.php`, `toggle_pin.php`, `update_client.php`, `update_default_price.php`, `add_article.php`, `delete_article.php`, `edit_client.php` — small POST-only mutators, one per action.
- `fetch_*.php`, `search_orders.php`, `get_client.php`, `get_users.php` — small JSON endpoints backing Select2 AJAX searches and dashboard widgets. Note: `fetch_client__details.php` and `notes_api.php` were removed as unused.
- `upload_attachment.php`, `download_attachment.php`, `delete_attachment.php` — file handling for `order_attachments`; files stored on disk under `uploads/orders/<order_id>/` with rows in `order_attachments`.
- `archive.php` (read-only viewer over `archived_orders`) and `archive_orders.php` (one-shot renumbering script — destructive, password-gated, no UI).
- `unpaid_orders.php` — separate list of "nefacturate" (not-invoiced) orders backed by an `unpaid_orders` table.
- `statistics.php` — ApexCharts dashboard (daily/weekly/monthly revenue per user, plus a pie of delivered counts). A hard-coded `$userColors` map in this file maps specific operator names → colors and is used across all charts.
- `notes_api.php` — single-file JSON API (`?action=add|fetch|unread_count|mark_read|delete`) for the in-app notes between operators.
- `order_preview.php` — server-rendered HTML fragment that the dashboard loads into a Tippy tooltip on order-row hover.

There is no `uploads/` or `vendor/` directory in the repo; the uploads directory is created at runtime.

## Conventions and patterns specific to this codebase

### Database access

- Always go through `mysqli` and `prepare()` + `bind_param()`. Do not introduce PDO, query builders, or ORMs.
- Bind types are written as a concatenated string of `i`/`s`/`d` chars and spread with `...$params` — follow that pattern when you see it (e.g. `dashboard.php` filter list, `update_order_details.php`).
- Most filter pages also build a parallel `COUNT(*)` query for pagination using the same conditions.
- Status strings used across the app: `assigned`, `completed`, `delivered`, `cancelled`. `delivered` and `cancelled` are excluded from the default dashboard view. Several files use a different (inconsistent) vocabulary: `fetch_orders.php` and the `unpaid_orders` form use `UNASSIGNED` / `IN PROGRESS` / `FINISHED`, and `view_order.php` and `archive_orders.php` mostly use lowercase. Match the file you're editing — don't "fix" one to match the other.
- Two users are hard-excluded in multiple places via `WHERE user_id NOT IN (3, 4)`: the "operator" dropdown, the notes recipient list, and one assignment filter. Don't remove these conditions without a deliberate decision.

### Auth and session

- Every page that needs the user must copy the `validateRememberToken` block from `dashboard.php` (or include a file that does). The "if not logged in, redirect to login.php" pattern is duplicated in nearly every page — that's the current shape, follow it.
- Session cookie config is repeated in PHP code in addition to the `php.ini`. Don't strip either.

### Frontend

- The whole UI is jQuery + Select2 + SweetAlert2 + AOS + Tippy + Dropzone + ApexCharts, all loaded from CDNs in each page's `<head>`. Do not add npm/vite/yarn.
- Theme color is yellow + black/grey — `#FFFF00` is the accent and `.theme-yellow`/`.theme-magenta`/`.theme-cyan`/`.theme-green`/`.theme-key` classes drive the order-row "heavy theme" in `dashboard.php`.
- "Quiet refresh" pattern: `dashboard.php` exposes `window.quietRefresh(url, { resetForm })` which fetches the same URL, parses the response, and patches `.pinned-section`, `.main-content tbody`, and `.pagination` in place — using `document.startViewTransition` when available. New filter/pagination handlers should call it instead of doing full navigations.
- Multi-user live updates: each dashboard client polls `refresh_check.php` every ~10s, sending its current filters (`status_filter`/`assigned_filter`/`category_filter`/`client_filter`/`sort_order`/`page`) + a hash (`sig`) of the table it's showing. The server re-runs the same filtered query and re-hashes; only if the hash differs does it answer `changed:true`, and the client then calls `window.quietRefresh(..., { resetForm:false })`. This makes a change show up for OTHER users but never when the user's active filters wouldn't show it. Guards in `script.js` skip the refresh while the "Add order" form has any filled field, while the order slider/modal is open, and for ~10s after the local user's own submit. `refresh_check.php` must keep `$limit = 18` and the WHERE/ORDER logic exactly in sync with `dashboard.php`.
- Order detail opens in an off-canvas iframe (`#orderSliderPanel`) sourced from `view_order.php?order_id=...&embedded=1` — globals `window.openOrderSlider` and `window.openStatsSlider` are the entry points.
- The order row click handler is wired by `bindOrderClickEvents()` and is called both on initial load and after every quiet refresh. If you swap out `.main-content tbody`, re-invoke it.
- Many pages have near-duplicated Select2 yellow-theme CSS blocks. Don't try to extract a shared stylesheet — the existing pattern is per-page.
- `script.js` is a small helper file used by the add-order form; most other JS is inlined in each page.

### WhatsApp / SMS

- Phone numbers are normalized to `+4<digits>` for `wa.me` links in `order_preview.php` and `view_order.php`. The dashboard's "WhatsApp Sender" modal lets the operator pick a country prefix (RO/IT/ES/UK/DE) or enter one manually.
- A `send_sms.php` endpoint is referenced from `view_order.php` but is **not** in the repo — if SMS is needed, that file is missing and must be added.

### Romanian-only data quirks

- Phone numbers are validated with the literal pattern `0[0-9]{9}` (10 digits, leading zero) on the client. Keep that constraint.
- Day/month names in the UI are hard-coded in Romanian (`Luni`, `februarie`, etc.) in many files; don't translate them.
- Currency is `lei`. Totals are formatted with `number_format(..., 2)`.

## Things to be careful about

- `archive_orders.php` is destructive: it deletes delivered/cancelled orders with `order_id > 2000`, copies them to `archived_orders`, then **renumbers every remaining `order_id` starting at 1**. Don't run it casually. It's the only place where `order_id` is mutated.
- Several files have leftover `echo`/debug statements and PHP notices left in (`echo "Comanda a fost adăugată cu succes! 🚀 🚀 🚀 ";` in `dashboard.php`, raw `echo` from `cancel_order.php`, `update_order_status.php`, etc.). Don't add new ones, but don't go on a cleanup spree either unless asked.
- `add_user.php` is a half-finished admin script — `$username` and `$password` are declared as empty (which is a syntax error in current PHP). Don't try to "fix" this without checking the admin's intent.
- The `console.log` file at the repo root is empty and not used by the app.
- `comenzi.html` is a static redirect page to the live site — not part of the PHP app.
