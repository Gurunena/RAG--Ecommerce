# Schema changes: `project.sql` → `sql/schema.sql`

`project.sql` (the original design) is left untouched for comparison. It had
never been run; these are every change made to get it running on MariaDB
10.4.32 (XAMPP) and to support the live app.

## Fixes: it would not run as written

| # | Problem in `project.sql` | Fix |
|---|---|---|
| 1 | Line 11: stray `-` after `password_hash ... NOT NULL,` (syntax error) | Removed |
| 2 | `cart_items` never closed: missing `) ENGINE=InnoDB;`, so the next `CREATE TABLE` was swallowed into it | Closed the table |
| 3 | `sub_orders` had its columns but no `CREATE TABLE sub_orders (` line | Added the header |
| 4 | Database named `consult_db`, brief specifies `consult` | Renamed to `consult` |
| 5 | Seed rows used fake hashes (`$2y$10$replace_with_real_hash`), so nobody could log in | Moved seed to `seed.sql`, real bcrypt hashes from `password_hash()` |
| 6 | Not re-runnable (fails on second import) | `DROP TABLE IF EXISTS` for every table first |

## MariaDB compatibility

- `JSON` columns kept. On MariaDB this is an alias for `LONGTEXT` with an automatic
  `JSON_VALID()` check, so invalid JSON is rejected by the database (verified).
  `JSON_EXTRACT` works. The API will also validate JSON in PHP before saving.
- `CHECK` constraints are enforced on MariaDB 10.2+ (verified with a bad sale date range).
- Every table declares `utf8mb4` / `utf8mb4_unicode_ci` explicitly (Sinhala/Tamil names, `·`, `—`).
- No MySQL-8-only syntax is used.

## Behaviour changes (decisions from Phase 0)

| Area | Change | Why |
|---|---|---|
| Status flow | `sub_orders.status` and `order_status_log.status` gain `refunded` | Refund is its own end state, not "cancelled" |
| Escrow | `sub_orders.delivered_at` added; delivery is confirmed by the **customer** | Seller stops at `shipped`; customer confirmation releases `payout_released_at` |
| Who changed it | `order_status_log.changed_by` gains `customer`; adds `changed_by_user_id`, `note` | Customer confirms delivery; audit needs the actor and reason |
| Append-only log | Triggers block `UPDATE`/`DELETE` on `order_status_log` | Makes "append-only audit trail" enforced, not a convention |

## Columns added for features the frontend already has

| Table | Added | Used by |
|---|---|---|
| `users` | `status` (active/suspended), `last_login_at`, unique key name | Admin suspend/reactivate |
| `sellers` | `public_id` (`s1`…), `kyc_rejection_reason`, `kyc_reviewed_at`, `kyc_reviewed_by` | AI catalogue mapping; KYC reject reason shown to seller |
| `sellers` | `commission_rate` now `NULL` = platform default (was `NOT NULL DEFAULT 8.00`) | "default vs negotiated rate" in both dashboards |
| `products` | `public_id` (`p1`…), `rating_breakdown`, `rejection_reason`, `reviewed_at`, `reviewed_by`, `deleted_at` | AI ids; detail-page rating bars; moderation/take-down reason; soft delete |
| `sub_orders` | `commission_rate` (snapshot), `refunded_at`, `dispute_*` (reason, opened_by, opened_at, resolution, resolved_at) | Commission history; disputes; refunds |
| `order_items` | `product_title` (snapshot) | Order history survives product edits/deletion |
| `orders` / `payments` | `partially_refunded` status; `payments.refunded_amount`; provider default `'demo'` | Refunding one sub-order of a multi-seller order; simulated gateway |
| `admin_actions` | `target_type` gains `order`, `faq`, `settings`; `target_id` nullable; `notes` 255 → 500 | Settings changes have no target row |
| `faqs` | `created_at`, `updated_at` | FAQ editor |
| `contact_messages` | `user_id`, `ip_address` | Link to account; abuse handling |

## New tables

| Table | Purpose |
|---|---|
| `settings` | Key/value platform settings (commission rate, categories, sign-up toggles) |
| `login_attempts` | Login rate limiting: 5 failures per email/IP per 10 minutes |

## Safer foreign keys

| FK | Was | Now | Why |
|---|---|---|---|
| `orders.user_id` | `CASCADE` | `RESTRICT` | Deleting a user must not delete financial records |
| `admin_actions.admin_id` | `CASCADE` | `RESTRICT` | Deleting an admin must not erase the audit trail |

Accounts are suspended, never deleted, so `RESTRICT` never blocks normal use.

## Extra constraints and indexes

- `CHECK`: price > 0, stock ≥ 0, cart quantity 1–99, order item quantity > 0,
  sale dates and price, coupon dates, review rating 1–5, seller commission 0–50.
- Indexes for the hot queries: seller orders by status, customer orders by date,
  active sale lookup `(product_id, start_date, end_date)`, login attempts by email/IP.
- `product_tags` unique per `(product_id, tag)`.
