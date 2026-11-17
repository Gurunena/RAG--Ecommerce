# `data/products.json` — the AI's catalogue snapshot

**This file is generated. Do not edit it by hand.**

```
php api/tools/export_products_json.php     # rebuild it from MySQL
# …then RESTART the AI service, or nothing changes
```

---

## What it is

`products.json` is a **snapshot of the live catalogue**, written out of MySQL.
The Python AI service (`ai_service/main.py`) reads it once at startup and
embeds every product into its vector index.

It does *not* query MySQL, and that is deliberate: re-embedding the catalogue
on every chat message would make the consultant unusably slow, and the vector
index has to be built once up front.

So there are **two copies of the catalogue**, and they drift apart.

## When it goes stale

Anything that changes what is on sale leaves this file behind:

| Someone does this | …and the AI still thinks |
|---|---|
| A seller adds a listing, an admin approves it | it does not exist |
| A seller changes a price | the old price |
| A seller schedules a sale | there is no sale |
| A seller's stock runs out | it is in stock |
| An admin takes a listing down | it is still for sale |

`api/ai/health.php` reports this. It compares the product count in this file
with the number of active rows in the database and returns
`snapshot.in_sync: false` when they disagree — which is what makes the chat's
status-dot tooltip say so, and what puts a warning in the browser console
instead of leaving you to wonder why the consultant is recommending something
that 404s.

## Why a stale snapshot does not show a wrong price

Two layers handle it, and it is worth knowing which does what:

1. **At render time — always correct.** `api/ai/consult.php` throws the AI's
   product objects away and keeps only their **ids**. The browser re-reads
   those from `api/products/by_ids.php`, so a card shows what MySQL says it
   costs and whether you can buy it. An id the AI recommends that is no longer
   buyable simply does not come back, and the card disappears rather than
   offering something that cannot be bought.

   *The AI decides which products to show. The database decides what they
   cost.*

2. **At export time — keeps the AI's reasoning honest.** Layer 1 cannot fix
   the AI *recommending the wrong things in the first place*: it cannot
   suggest a product that is not in its index, and it will happily reason
   out loud about a price that has since changed ("this is the cheapest
   option under 150,000" — when it no longer is). Only rebuilding the
   snapshot fixes that.

So: layer 1 means a stale snapshot is never *dangerous*. Layer 2 is what makes
the advice *good*.

## How to rebuild it

```bash
cd C:\E-commerce\consult-project
php api/tools/export_products_json.php
```

Useful flags:

| Flag | Does |
|---|---|
| `--dry-run`, `-n` | Print what it would write, change nothing |
| `--out=PATH` | Write somewhere else |
| `--help`, `-h` | Usage |

Then **restart the AI service** — this is not optional:

```bash
cd C:\E-commerce\ai_service
venv\Scripts\activate
python -m uvicorn main:app --port 8001
```

`main.py` loads this file at import and rebuilds its Qdrant collection there.
A new file with the old process still running does nothing at all.

> Do **not** use `uvicorn --reload`. Qdrant runs in embedded mode and locks
> `ai_service/qdrant_data/` for the life of the process; the reloader starts a
> second process that tries to open the same folder and fails with *"storage
> folder is already accessed by another instance"*. `main.py` says the same
> thing in its own comments.

## What gets exported

Only what a shopper could actually buy: products with `status = 'active'` and
no `deleted_at`, whose seller has a `public_id`. Recommending anything else
would be recommending something that cannot be bought.

- The **price** is the *effective* price — today's sale price if one is
  running, with the normal price as `oldPrice` — so the AI reasons about the
  figure a shopper would actually pay.
- **Sellers** are limited to those with at least one live listing.
- **Reviews** carry a first name and a surname initial (`Dilshan P.`), never
  a full name and never an email. The AI can quote this file back to a
  shopper, so nothing identifying goes into it.
- `specs` is always an object, never `null` — `main.py` calls `.items()` on it.
- `meta.generated_at` records when the snapshot was taken, which is the first
  thing you want to know when the answers look out of date.

The shape is exactly what `main.py` already expects (`{sellers, products}`,
camelCase, `why` for the description, `reviews` as a count and `reviewsList`
as the array), so nothing in `ai_service/` had to change for any of this.

## Safety

- Written to a temp file and renamed into place, so an interrupted export
  cannot leave the AI service with a half-written JSON file.
- The version being replaced is kept as `products.json.bak`.
- It refuses to write an empty catalogue — if no active products are found it
  exits non-zero and leaves the old file alone, rather than handing the AI
  service nothing to answer from.

## Verifying it worked

```bash
curl http://localhost/consult-project/api/ai/health.php
```

```json
{
  "online": true,
  "snapshot": {
    "products_in_file": 8,
    "products_in_db": 8,
    "products_in_service": 8,
    "generated_at": "2026-11-08T10:17:09+05:30",
    "in_sync": true,
    "needs_export": false,
    "needs_restart": false,
    "regenerate_with": null
  }
}
```

Three counts, because there are three copies and two distinct things that
can go wrong:

| Field | Is | Goes stale when |
|---|---|---|
| `products_in_db` | the truth | never |
| `products_in_file` | this snapshot on disk | a listing is approved, taken down or deleted → **`needs_export`** |
| `products_in_service` | what the running process holds in memory | the file is regenerated but the service is not restarted → **`needs_restart`** |

`needs_export` and `needs_restart` are reported separately because they
have different fixes — exporting without restarting is the mistake that is
easy to make and silent, so it gets its own flag.

### What this cannot detect

Only **counts** are compared. A seller changing a *price* without the
product count changing leaves all three numbers equal and `in_sync: true`,
while the snapshot still holds the old figure.

That is survivable precisely because of layer 1 above: every card the chat
renders is re-priced from MySQL, so the shopper never sees the stale number.
What the AI may still do is *reason* about it out loud. If the consultant
quotes a price that looks wrong, re-export — do not go hunting for a bug in
the card rendering.

`api/tools/test_public.php` covers all of this — including deliberately
approving a listing to make the snapshot drift, checking that `health.php`
notices, regenerating, and confirming it reports in sync again.
