# Consult — backend

A multi-seller e-commerce storefront with an AI shopping consultant.
PHP 8.2 + MySQL (MariaDB) on XAMPP, vanilla JavaScript on the front, and a
separate Python service for the AI chat.

```
Browser ──► PHP (api/) ──► MySQL          everything transactional
   │                │
   │                └────► Python AI service (port 8001)
   └── never talks to MySQL or to port 8001 directly
```

---

## Table of contents

1. [Setup on XAMPP](#1-setup-on-xampp)
2. [Importing the database](#2-importing-the-database)
3. [Demo accounts](#3-demo-accounts)
4. [Starting the AI service](#4-starting-the-ai-service-optional)
5. [Folder map](#5-folder-map)
6. [Endpoint reference](#6-endpoint-reference)
7. [Running the tests](#7-running-the-tests)
8. [Troubleshooting](#8-troubleshooting)
9. [**Viva cheat sheet**](#9-viva-cheat-sheet)

---

## 1. Setup on XAMPP

### 1.1 Put the project in `htdocs`

The project must be served by Apache, **not** opened as a `file://` page and
not through VS Code's Live Server. The API lives at a path relative to the
page, so the whole site has to come from one web server.

```
C:\xampp\htdocs\consult-project\
```

If you are working in `C:\E-commerce\consult-project` instead, point Apache
at it rather than copying. Open
`C:\xampp\apache\conf\extra\httpd-vhosts.conf` and add:

```apache
Alias /consult-project "C:/E-commerce/consult-project"
<Directory "C:/E-commerce/consult-project">
    AllowOverride All
    Require all granted
</Directory>
```

`AllowOverride All` matters: the `.htaccess` files in `api/`, `uploads/`,
`logs/` and `sql/` are part of the security model, and Apache ignores them
without it.

### 1.2 Start Apache and MySQL

Open the **XAMPP Control Panel** and press **Start** next to both:

| Module | Port | Shows |
|---|---|---|
| Apache | 80, 443 | green `Running` |
| MySQL  | 3306    | green `Running` |

If Apache will not start, something else is on port 80 — usually IIS or
Skype. Either stop it, or change Apache's port in
`C:\xampp\apache\conf\httpd.conf` (`Listen 80` → `Listen 8080`) and use
`http://localhost:8080/consult-project/` throughout.

### 1.3 Check PHP has what it needs

```
C:\xampp\php\php.exe -m
```

Required: **pdo_mysql**, **json**, **mbstring**, **curl**, **openssl**.
All five ship enabled in XAMPP. `curl` is the one worth confirming — the AI
proxy needs it.

### 1.4 Writable folders

`uploads/` and `logs/` are written to at runtime. On a default Windows
XAMPP install they already are; if an upload fails with *"The server could
not save the file"*, check them.

---

## 2. Importing the database

### 2.1 Create the database

The schema creates it, so there is nothing to do by hand. But if you prefer
phpMyAdmin:

1. Open <http://localhost/phpmyadmin>
2. **Databases** → name it `consult` → **Collation** `utf8mb4_unicode_ci` → **Create**

### 2.2 Import the schema, then the seed

**Order matters.** `schema.sql` drops and recreates every table, so running
it after the seed throws the data away.

#### In phpMyAdmin

1. Select the `consult` database → **Import** → choose `sql/schema.sql` → **Import**
2. Still in `consult` → **Import** → choose `sql/seed.sql` → **Import**

#### Or from the command line (faster, and shows errors plainly)

```bash
cd C:\E-commerce\consult-project
C:\xampp\mysql\bin\mysql.exe -u root < sql\schema.sql
C:\xampp\mysql\bin\mysql.exe -u root consult < sql\seed.sql
```

### 2.3 Verify

```bash
C:\xampp\php\php.exe sql\verify.php
```

Or by hand — you should see **28 users, 10 products, 8 orders, 6 FAQs**:

```sql
SELECT (SELECT COUNT(*) FROM users)    AS users,
       (SELECT COUNT(*) FROM products) AS products,
       (SELECT COUNT(*) FROM orders)   AS orders,
       (SELECT COUNT(*) FROM faqs)     AS faqs;
```

### 2.4 Database credentials

`api/config.php` defaults to XAMPP's own: user `root`, no password, on
`127.0.0.1:3306`. If yours differ, **do not edit `config.php`** — create
`api/config.local.php`, which is git-ignored and overrides it:

```php
<?php
return [
    'db_user' => 'root',
    'db_pass' => 'your-password',
];
```

### 2.5 The seed data ages

The seeded scheduled sale uses `CURDATE() ± INTERVAL`, so a **fresh import**
always has one sale running today and one starting next week. Those dates
are resolved at import time, so a database imported weeks ago has a sale
that has since expired — harmless, but the demo looks better re-imported,
or re-anchored:

```sql
UPDATE product_sales SET start_date = CURDATE() - INTERVAL 2 DAY,
                         end_date   = CURDATE() + INTERVAL 5 DAY  WHERE product_id = 8;
UPDATE product_sales SET start_date = CURDATE() + INTERVAL 7 DAY,
                         end_date   = CURDATE() + INTERVAL 14 DAY WHERE product_id = 3;
```

---

## 3. Demo accounts

**Every seeded account uses the same password: `Consult@2026`**

| Role | Email | Use it to show |
|---|---|---|
| **Admin** | `admin@consult.lk` | The whole web-master console |
| **Seller** (verified) | `techhub@example.com` | A working seller dashboard — 4 listings, real orders |
| Seller (verified) | `nexus@example.com` | A second seller, for the two-seller order split |
| Seller (verified) | `islandmobile@example.com` | |
| Seller (verified) | `homeline@example.com` | Has the disputed order |
| **Seller (pending)** | `kandygadgets@example.com` | The *"your store is under review"* gate |
| **Seller (rejected)** | `galleaudio@example.com` | The rejection reason shown to a seller |
| **Customer** | `nimal@example.com` | 3 orders, including a dispute |
| Customer | `sanduni@example.com` | 3 orders |
| Customer | `kavindu@example.com` | 2 orders |

The remaining 18 accounts (`*@reviewers.consult.lk`) exist so the product
reviews have real authors — `reviews.user_id` is a foreign key, so a review
cannot exist without one.

> Passwords are stored as `password_hash()` bcrypt output. There is no plain
> text anywhere, including in `seed.sql`, which contains real hashes.

---

## 4. Starting the AI service (optional)

**The site works fully without it.** The chat falls back to local keyword
matching and says so with a grey status dot.

```bash
cd C:\E-commerce\ai_service
venv\Scripts\activate
python -m uvicorn main:app --port 8001
```

Wait for `AI service ready on http://localhost:8001`. It loads an embedding
model and builds its vector index at startup, so the first run takes a
minute. It also needs **Ollama** running for the language model.

> **Never use `--reload`.** Qdrant runs embedded and locks
> `ai_service/qdrant_data/` for the life of the process; the reloader starts
> a second process that tries to open the same folder and fails.

The browser never talks to port 8001. It posts to `api/ai/consult.php`,
which forwards with cURL. The port lives in `api/config.php`
(`ai_service_url`) and nowhere else.

### Keeping the AI catalogue in sync

The service reads `data/products.json` **once at startup** — it does not
query MySQL. So the snapshot drifts whenever a listing is approved, repriced
or taken down.

```bash
php api/tools/export_products_json.php    # rebuild from MySQL
# …then restart the AI service
```

`api/ai/health.php` reports the drift, and `data/README.md` explains the
whole arrangement. Crucially, a stale snapshot never shows a wrong price:
`api/ai/consult.php` keeps only the product **ids** from an AI answer and the
browser re-reads those from `api/products/by_ids.php`, so every card is
priced by MySQL. The AI decides *which* products; the database decides what
they cost.

---

## 5. Folder map

```
consult-project/
├── api/                        every endpoint. PHP, JSON in and out.
│   ├── bootstrap.php           FIRST LINE OF EVERY ENDPOINT: error handling,
│   │                           headers, same-origin guard, session
│   ├── config.php              credentials + tunables (config.local.php overrides)
│   ├── db.php                  the one PDO handle + db_transaction()
│   ├── helpers.php             envelope, validation, CSRF, auth gates
│   ├── catalog.php             ONE definition of "what a product looks like"
│   ├── _uploads.php            the only place a browser file becomes a disk file
│   │
│   ├── auth/                   register, login, logout, me, csrf, profile, avatar
│   ├── products/               list, detail, by_ids (re-prices the AI's cards)
│   ├── cart/                   get/add/update/remove/clear/merge  (+ _cart.php)
│   ├── wishlist/               list, toggle
│   ├── reviews/                list, create                       (+ _review_rules.php)
│   ├── orders/                 checkout, list, detail, confirm_delivery (+ _orders.php)
│   ├── seller/                 dashboard, product CRUD, stock, sale, orders, profile
│   │                           (+ _seller.php — the seller_id scoping lives here)
│   ├── admin/                  dashboard, verify, moderate, users, orders, finance,
│   │                           faqs, settings                      (+ _admin.php)
│   ├── ai/                     consult.php (proxy), health.php
│   ├── settings/public.php     pre-login category list + signup toggles
│   ├── faqs.php                the public FAQ
│   ├── contact_open.php        stamps the session when the form renders
│   ├── contact_submit.php      stores a message (honeypot + timing + rate limit)
│   └── tools/                  CLI only, behind TWO locks (see below)
│       ├── .htaccess                    Require all denied
│       ├── audit.php                    the hardening review, re-runnable
│       ├── export_products_json.php     rebuild the AI snapshot from MySQL
│       └── test_*.php                   six test suites
│
├── sql/
│   ├── schema.sql              every table, re-runnable (DROPS DATA)
│   ├── seed.sql                demo data with real bcrypt hashes
│   ├── SCHEMA-CHANGES.md       every change from the original project.sql, and why
│   └── verify.php              sanity-check an import
│
├── js/
│   ├── api.js                  THE ONLY PLACE fetch() IS CALLED
│   ├── common.js               Session, cart mirror, nav, toast, validation
│   ├── dash-common.js          shared dashboard helpers (icons, dates, charts)
│   ├── seller-dashboard.js     six views, one load, synchronous render
│   ├── admin-dashboard.js      eight views, same arrangement
│   └── …one file per page
│
├── css/                        styles.css + the two dashboard stylesheets
├── data/
│   ├── products.json           GENERATED — the AI's catalogue snapshot
│   └── README.md               how and when to regenerate it
├── uploads/                    user images. .htaccess forbids execution.
├── logs/                       app.log. .htaccess denies all access.
├── api-test.http               a request for every endpoint
└── *.html                      the pages
```

### `api/tools/` is locked twice, on purpose

These are CLI scripts, not endpoints. Over HTTP they would be severe:
`test_admin.php` creates accounts, suspends users, refunds orders and then
deletes its fixtures — with no authentication, because a command-line script
has no session to check.

So there are two independent locks:

1. **`api/tools/.htaccess`** — `Require all denied`.
2. **A `PHP_SAPI !== 'cli'` guard** at the top of every script, before any
   `require`.

The second exists because the first only takes effect where Apache was
configured with `AllowOverride All`. A project copied to a host without it
would serve these files happily — the guard is what makes that survivable.

`api/tools/audit.php` checks both, and fails if either is missing from any
script, so this cannot quietly regress.

> This was a real hole found during the Phase 8 review, not a precaution
> written up after the fact: `api/tools/` had no `.htaccess` and every script
> answered HTTP 200 to a browser.

### Four files are load-bearing

| File | If you change it |
|---|---|
| `api/bootstrap.php` | Every endpoint's error handling, headers and session |
| `api/catalog.php` | The product shape the storefront, cart, wishlist and AI all render |
| `api/orders/_orders.php` | The order status machine and escrow, for all three roles |
| `js/api.js` | Every call the browser makes |

---

## 6. Endpoint reference

Every endpoint answers in one of exactly two shapes:

```json
{ "ok": true,  "data": … }
{ "ok": false, "error": "a sentence for a person", "code": "VALIDATION|AUTH|FORBIDDEN|NOT_FOUND|SERVER" }
```

A `VALIDATION` failure may add `"fields": {"email": "…"}` so the form can put
each message under the right input.

**Gate** — `public` no session · `login` any signed-in user · `customer` /
`seller` / `admin` that role only · `seller+` a **verified** seller.
**CSRF** — every mutating request needs the `X-CSRF-Token` header.

### Auth

| Method | Path | Gate | CSRF | Purpose |
|---|---|---|---|---|
| GET | `auth/csrf.php` | public | — | Mint a CSRF token |
| GET | `auth/me.php` | public | — | Who is signed in (+ a fresh token). Never 401s |
| POST | `auth/register.php` | public | ✓ | Create a customer account |
| POST | `auth/login.php` | public | ✓ | Sign in. Rate-limited: 5 failures per email/IP per 10 min |
| POST | `auth/logout.php` | public | ✓ | Destroy the session |
| POST | `auth/update_profile.php` | login | ✓ | Your own name, email, phone |
| POST | `auth/change_password.php` | login | ✓ | Your own password |
| POST | `auth/avatar.php` | login | ✓ | Your own photo (multipart), or `{remove:true}` |

### Catalogue (public)

| Method | Path | Gate | CSRF | Purpose |
|---|---|---|---|---|
| GET | `products/list.php` | public | — | Paged, filterable, sortable. Active listings only |
| GET | `products/detail.php` | public | — | One product with specs, gallery, reviews |
| GET | `products/by_ids.php` | public | — | `?ids=p1,p4` — re-prices the AI chat's cards from MySQL |
| GET | `reviews/list.php` | public | — | One product's reviews |
| POST | `reviews/create.php` | customer | ✓ | One review per product per customer |
| GET | `faqs.php` | public | — | The public FAQ, grouped by category |
| GET | `settings/public.php` | public | — | Category list + signup toggles, before any session |

### Cart & wishlist (your own, always)

| Method | Path | Gate | CSRF | Purpose |
|---|---|---|---|---|
| GET | `cart/get.php` | login | — | The whole cart + server-computed totals |
| POST | `cart/add.php` | login | ✓ | Add or top up a line |
| POST | `cart/update.php` | login | ✓ | Set an absolute quantity (0 removes) |
| POST | `cart/remove.php` | login | ✓ | Remove a line |
| POST | `cart/clear.php` | login | ✓ | Empty it |
| POST | `cart/merge.php` | login | ✓ | Fold a guest cart in on sign-in |
| GET | `wishlist/list.php` | login | — | Your wishlist (`?ids_only=1` for the hearts) |
| POST | `wishlist/toggle.php` | login | ✓ | Add/remove |

### Orders & escrow

| Method | Path | Gate | CSRF | Purpose |
|---|---|---|---|---|
| POST | `orders/checkout.php` | login | ✓ | **The big one.** One transaction: validate, re-price from the DB, write order + sub-orders + items + payment, decrement stock, clear the cart |
| GET | `orders/list.php` | login | — | Your order history with timelines |
| GET | `orders/detail.php` | login | — | One order (`?id=12` or `ORD-000012`) |
| POST | `orders/confirm_delivery.php` | login | ✓ | **Releases escrow.** The only thing that does |

### Seller

| Method | Path | Gate | CSRF | Purpose |
|---|---|---|---|---|
| POST | `seller/register.php` | public | ✓ | Application → `users` + `sellers` (`kyc_status='pending'`) |
| GET | `seller/dashboard.php` | seller | — | The whole workspace in one request: KPIs, 30-day series, listings, orders, payouts |
| POST | `seller/product_save.php` | seller+ | ✓ | Create or edit a listing. New ones are always `pending` |
| POST | `seller/product_delete.php` | seller | ✓ | Soft delete (order history still references it) |
| POST | `seller/product_toggle.php` | seller+ | ✓ | active ↔ inactive |
| POST | `seller/stock.php` | seller+ | ✓ | `{stock}` absolute, `{delta}` relative, or `{ids,stock}` bulk |
| POST | `seller/sale.php` | seller+ | ✓ | Schedule or clear a date-ranged sale |
| POST | `seller/order_status.php` | seller+ | ✓ | pending → packed → shipped, + cancel. **Never `delivered`** |
| POST | `seller/profile.php` | seller | ✓ | Shop and payout details |
| POST | `seller/upload.php` | seller+ | ✓ | A product image (multipart) |

### Admin

| Method | Path | Gate | CSRF | Purpose |
|---|---|---|---|---|
| GET | `admin/dashboard.php` | admin | — | Whole console: KPIs, series, queues, users, orders, FAQs, settings, audit trail |
| GET | `admin/finance.php` | admin | — | GMV, commission by seller, payouts. `&format=csv` downloads |
| POST | `admin/seller_verify.php` | admin | ✓ | `approve` / `reject` (reason required) / `commission` |
| POST | `admin/product_moderate.php` | admin | ✓ | `approve` / `reject` / `takedown` (reason required) |
| POST | `admin/user_status.php` | admin | ✓ | `suspend` / `reactivate` |
| POST | `admin/order_action.php` | admin | ✓ | `override` / `dispute` / `refund` / `resolve` |
| POST | `admin/faqs.php` | admin | ✓ | FAQ create / edit / delete |
| POST | `admin/settings.php` | admin | ✓ | Commission rate, categories, signup toggles |

### AI & contact

| Method | Path | Gate | CSRF | Purpose |
|---|---|---|---|---|
| POST | `ai/consult.php` | public | ✓ | Forwards to the Python service. Returns the message + product **ids** |
| GET | `ai/health.php` | public | — | Is the service up; has the snapshot drifted |
| GET | `contact_open.php` | public | — | Stamps the session (the anti-bot timing check) |
| POST | `contact_submit.php` | public | ✓ | Stores a message. Honeypot + timing + 5/hour per IP |

**49 endpoints.** `api/api-test.http` has a worked request for each.

---

## 7. Running the tests

Apache and MySQL must be running. Each suite talks to the API over real HTTP
with a cookie jar, so sessions, CSRF and the role gates are genuinely
exercised — not bypassed by calling PHP functions directly.

```bash
cd C:\E-commerce\consult-project

C:\xampp\php\php.exe api\tools\audit.php          # the hardening review
C:\xampp\php\php.exe api\tools\test_auth.php      # sessions, CSRF, rate limiting
C:\xampp\php\php.exe api\tools\test_catalog.php   # products, cart, wishlist, reviews
C:\xampp\php\php.exe api\tools\test_orders.php    # checkout, the split, escrow
C:\xampp\php\php.exe api\tools\test_seller.php    # seller backend + isolation
C:\xampp\php\php.exe api\tools\test_admin.php     # admin backend + audit trail
C:\xampp\php\php.exe api\tools\test_public.php    # FAQ, contact, AI proxy
```

Each creates throwaway accounts and deletes them at the end, so the seeded
database is left as it was found. **Run them one at a time** — each cleans up
by matching its own `*test-%` email pattern, which would also match a
concurrent run's fixtures.

`test_public.php` works with the AI service off; it says which path it took
and skips the checks that need a live language model.

---

## 8. Troubleshooting

| Symptom | Cause | Fix |
|---|---|---|
| *"That API endpoint is missing. Is the site being served by Apache?"* | The page was opened as `file://` or via Live Server | Serve it from Apache, through `http://localhost/…` |
| *"Could not reach the server. Is Apache running in XAMPP?"* | Apache is down | Start it |
| Every page shows *"Something went wrong on our side"* | MySQL is down | Start it. The reason is in `logs/app.log` |
| *"Your session has expired. Refresh the page"* on every save | The CSRF token is stale | Refresh. If it persists, check `session.save_path` is writable |
| Logged out on every page load | Cookies blocked, or the site is being reached on two different hostnames | Use one of `localhost` **or** `127.0.0.1`, not both |
| Uploads fail | `uploads/` is not writable | Check the folder's permissions |
| Chat has a grey dot and generic answers | The Python service is not running | Section 4, or ignore it — this is the designed fallback |
| Chat says *"taking too long"* | A cold or busy Ollama | Raise `ai_service_timeout` in `api/config.php` |
| AI recommends something that 404s | The catalogue snapshot is stale | `php api/tools/export_products_json.php`, then restart the service |
| Seller dashboard shows the review gate | `kyc_status` is not `verified` | Approve them in the admin console |
| A new listing is invisible in the shop | It is `pending` | Approve it in the admin console. **This is correct behaviour** |

**The log is the first place to look.** `logs/app.log` has the real reason
for every 500 — the browser deliberately only ever sees a friendly sentence.

---

## 9. Viva cheat sheet

### 9.1 ER overview

Fifteen tables. The five that carry the design:

```
users ─┬─1:1─ sellers ──1:N─ products ─┬─1:N─ product_images
       │         (shared PK)           ├─1:N─ product_tags
       │                               ├─1:N─ product_sales   ← scheduled discounts
       │                               └─1:N─ reviews
       │
       ├─1:N─ cart_items                      (user_id + product_id, UNIQUE)
       ├─1:N─ wishlist                        (composite PK)
       │
       └─1:N─ orders ──┬──1:N─ sub_orders ─┬─1:N─ order_items
                       │      (per seller) └─1:N─ order_status_log   ← append-only
                       └──1:1─ payments
```

Plus `admin_actions` (the audit trail), `settings`, `faqs`,
`contact_messages`, `login_attempts`, `coupons`.

**Three things to be able to point at:**

1. **`users` ⟷ `sellers` share a primary key.** `sellers.id` *is* `users.id`,
   not a separate key with a `user_id` column. A seller is a user with extra
   fields — one identity, one row to suspend, and the 1:1 is enforced by the
   database rather than by convention.
2. **`orders` → `sub_orders` is where the multi-seller split lives.** One
   checkout, one payment, one `orders` row — but one `sub_orders` row per
   seller, because each seller ships and is paid separately.
3. **`order_status_log` is append-only, enforced by triggers.**
   `BEFORE UPDATE` and `BEFORE DELETE` on that table both `SIGNAL SQLSTATE
   '45000'`. The audit trail is not erasable even by someone with SQL access.

Snapshot columns, and why each exists:

| Column | Snapshots | So that |
|---|---|---|
| `order_items.unit_price` | the price paid | a seller repricing does not rewrite history |
| `order_items.product_title` | the name bought | a renamed product does not change an old receipt |
| `sub_orders.commission_rate` | the rate that applied | a rate change is not retroactive |
| `orders.shipping_*` | the address used | editing your profile does not change where a past order went |

### 9.2 Why PDO prepared statements

**The claim:** there is no SQL injection in this codebase, and it is not
because the input is cleaned — it is because values never become SQL.

```php
$stmt = db()->prepare('SELECT … FROM products WHERE public_id = ?');
$stmt->execute([$id]);
```

The query goes to MySQL with a `?`, and the value goes separately. MySQL has
already parsed the statement before it ever sees the data, so `'; DROP TABLE
products; --` is looked up as a *product id* — it finds nothing. There is no
escaping to get wrong.

Three decisions that back it up:

- **`PDO::ATTR_EMULATE_PREPARES => false`** (`api/db.php`). With emulation
  *on*, PDO interpolates the values itself in PHP and sends one finished
  string — which is exactly the thing we are claiming not to do. Off means a
  real server-side prepare.
- **`PDO::ERRMODE_EXCEPTION`.** A failed query throws instead of returning
  `false`, so a broken write cannot half-complete and be ignored.
- **An `IN` list is built as placeholders, never values:**
  ```php
  $in = implode(',', array_fill(0, count($ids), '?'));   // "?,?,?"
  $stmt = db()->prepare("… WHERE product_id IN ($in)");
  $stmt->execute($ids);
  ```
  The *number* of placeholders is dynamic; not one value is.

**What about `LIMIT`?** It cannot take a placeholder with emulation off, so
it is built with `sprintf('LIMIT %d', …)` on an integer that
`query_int()` has already cast and clamped. `api/tools/audit.php` checks
every interpolation in the codebase and requires a recorded reason for each.

**Prove it:** `test_public.php` asks `by_ids.php` for
`p1,'; DROP TABLE products; --` and then asserts the table is still there.

### 9.3 How escrow works

The customer's money is held by the platform until they confirm the parcel
arrived. It is **one column**: `sub_orders.payout_released_at`.

```
checkout            payments.status = 'captured'      ← money recorded as HELD
                    payout_released_at IS NULL

seller packs        status → 'packed'                 payout still NULL
seller ships        status → 'shipped'                payout still NULL
                    ↑ the seller's authority stops here

CUSTOMER confirms   status → 'delivered'
                    delivered_at       = NOW()
                    payout_released_at = NOW()        ← money is the seller's
```

**Why the customer and not the seller.** A seller who could mark its own
shipment delivered could pay itself without posting anything. So
`order_role_map()` in `api/orders/_orders.php` simply does not grant
`delivered` to the seller role — the endpoint refuses it with a 409, and
there is no second code path that could.

Escrow is released per **sub-order**, so on a two-seller order one seller can
be paid while the other is still shipping. Once *every* sub-order of a
payment has settled, `payments.escrow_released_at` is stamped too.

A disputed sub-order holds the payout (`state: 'held'`). An admin resolves it
either way: for the seller → `delivered`, which releases it through the same
code path as the customer's own confirmation; for the customer → `refunded`,
which never pays out and puts the stock back.

**Prove it:** `test_orders.php` — *"ESCROW STILL HELD after the seller shipped
it"*, *"Seller marking it DELIVERED is refused (409)"*, *"ESCROW RELEASED —
payout_released_at stamped"*.

### 9.4 How the order split works

One cart holding items from two sellers becomes:

```
orders            1 row    the whole checkout, one address, one total
payments          1 row    ONE payment for the lot
sub_orders        2 rows   one per seller — status, escrow, disputes live here
order_items       N rows   each attached to its seller's sub-order
order_status_log  2 rows   'pending' for each, written at the same instant
```

The grouping is a `foreach` over the cart keyed by `seller_id`
(`api/orders/checkout.php`). Each sub-order gets its own `subtotal`, its own
450 delivery fee, and a **snapshot** of that seller's commission rate.

**Why split at all?** Because the three things that follow a sale are all
per-seller: who packs it, when it ships, and who gets paid. One flat order
row cannot hold two different statuses.

**Why one payment?** Because the customer paid once. Splitting the payment
would mean two card charges for one basket.

The scoping is what makes it safe: a seller's every query has
`seller_id = ?` in its **WHERE clause**, so a two-seller order looks like one
shipment from either side. Not a check after fetching — part of the query, so
there is no path where it can be forgotten.

**Prove it:** `test_orders.php` — *"ONE order row · ONE payment · TWO
sub-orders"*, *"Seller A cannot advance the OTHER half → 404"*, *"Seller B's
sub-order is UNAFFECTED"*.

### 9.5 Why transactions at checkout

`orders/checkout.php` performs **eight writes**:

```
1. orders               the order
2. sub_orders           one per seller
3. order_items          one per line
4. products.stock       decrement each
5. payments             the captured payment
6. order_status_log     the first audit entry per sub-order
7. cart_items           delete the cart
```

Without a transaction, a failure at step 4 leaves a **paid order with no
stock movement**, or stock gone with no order to show for it. Both are
corruption no amount of later code can repair, because you cannot tell what
was supposed to have happened.

```php
db_transaction(function (PDO $pdo) { … });   // commit, or roll back everything
```

Two more things it buys:

- **`SELECT … FOR UPDATE`** locks the cart and product rows for the
  transaction's life, so two tabs clicking "pay" on the last unit cannot both
  pass the stock check. The second waits, then sees the decremented stock.
- **Re-pricing inside the lock.** The price charged is read from `products`
  within the same transaction, so nothing can change between the check and
  the charge.

**Prices are never taken from the request.** The browser sends only the
delivery address. `test_orders.php` posts `total: 5` with a 510,800 cart and
asserts it is charged 510,800.

**Prove the rollback:** that suite installs a trigger that makes the first
`order_items` insert fail — by which point the order and a sub-order are
already written — then asserts no order row, no sub-order rows, no payment,
no stock change, and the cart still intact.

### 9.6 How sessions + CSRF work

**Sessions.** A PHP session, with four deliberate settings
(`api/bootstrap.php`):

| Setting | Why |
|---|---|
| `httponly` | JavaScript cannot read the cookie, so an XSS cannot steal it |
| `samesite=Lax` | The cookie is not sent on a cross-site POST — half the CSRF defence |
| `use_strict_mode` | PHP refuses a session id it never issued, killing session fixation |
| `path` scoped to the app | The session cannot collide with another project in `htdocs` |

On login, `session_regenerate_id(true)` issues a **new** id, so an id an
attacker planted before sign-in is worthless after it.

**The session stores only `uid` and `role`.** Everything else is re-read from
MySQL on every request (`current_user()`), which is why suspending an account
or verifying a seller takes effect on that user's *very next click* with
nothing to log out of. `test_admin.php` asserts exactly that.

**CSRF.** The session cookie alone is not enough, because a browser attaches
cookies to requests the user did not mean to make — a form on another site
posting to `cart/add.php` would arrive authenticated.

```
GET  auth/csrf.php         → a 64-char random token, stored in the session
POST anything              → must send it back as X-CSRF-Token
                             checked with hash_equals() against the session's copy
```

The attacker's page cannot read the token: it lives in *our* session, and the
same-origin policy stops them fetching it. So they can make the browser send
a request, but not a *valid* one.

Three supporting details:

- **`hash_equals()`**, not `===` — a constant-time comparison, so the check
  cannot be attacked by timing it.
- **A new token on login.** `session_promote()` discards the old one with the
  old session id.
- **One silent retry.** `js/api.js` refetches the token once on a 403 and
  retries, because a restarted PHP means a stale token — a real failure fails
  twice.

**Why only mutating requests.** A GET is a read; there is nothing to forge.
`api/tools/audit.php` asserts the rule from the other direction: **every**
POST endpoint calls `csrf_check()`.

### 9.7 The hardening review, in one command

```bash
C:\xampp\php\php.exe api\tools\audit.php
```

It reads all 49 endpoints and checks for: a missing auth check, a missing
role check, SQL built by concatenation, raw `$_GET`/`$_POST`, `SELECT *`, a
leaked column (`password_hash`, `nic_number`), an unshaped error, and a POST
without CSRF. Each endpoint's **intended** gate is declared in the script
with the reason, so "this one is public" is a decision on the record — and a
new endpoint that is not declared is reported.

It exits non-zero on any finding, so it is the one review that stays true
after the next change.

### 9.8 Questions worth having an answer ready for

**"Is the payment real?"** No, and it is labelled *"Demo payment ·
simulated"* in the UI. `payments.provider = 'demo'`, the reference is
`DEMO-…`, and `status = 'captured'` means *held in escrow* in this system.
Everything after that point — the escrow, the split, the refund arithmetic —
is real.

**"What stops a seller seeing another seller's orders?"** `seller_id = ?` in
the WHERE clause of every query in `api/seller/_seller.php`. Not a check
after fetching, which can be forgotten — part of the statement, so another
seller's row cannot be returned to be checked.

**"What if the AI recommends something that is out of stock?"** The card
never renders. `ai/consult.php` keeps only the ids and the browser re-reads
them from `products/by_ids.php`, which returns active listings only; anything
else comes back in `missing` and is logged.

**"Why is the FAQ readable without a login but writable only by an admin?"**
They are two different endpoints — `faqs.php` and `admin/faqs.php` — rather
than one with a role branch inside it. A branch is a thing that can be got
wrong; a separate file with `require_admin()` at the top cannot be.

**"What happens if MySQL goes down mid-demo?"** Every page shows a friendly
on-page message, the real reason goes to `logs/app.log`, and the storefront
falls back to `data/products.json` so the grid is not blank. No stack trace
reaches the browser — `bootstrap.php` installs an error handler, an exception
handler and a shutdown handler so even a fatal answers with the JSON
envelope.
