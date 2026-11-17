-- ================================================================
-- Consult — Risk Radar schema
--
-- Unusual order activity detection. Additive only: this file creates new
-- tables and adds columns to sub_orders. It does NOT touch any existing
-- table's data, and it is safe to run twice.
--
-- Target: MariaDB 10.4+ (XAMPP). Charset utf8mb4.
--
-- THE DESIGN RULE, which is also the thing to say out loud: the system
-- flags and explains, humans decide. Nothing here bans, cancels or refunds
-- anything. A flagged order gets a score, a list of signals with the real
-- numbers behind them, a readable sentence, and — only at high risk — a
-- HOLD on its escrow payout until an admin has looked at it.
--
-- Run with:
--   C:\xampp\mysql\bin\mysql.exe -u root consult < sql\risk.sql
--   C:\xampp\php\php.exe sql\verify_risk.php
-- ================================================================

USE consult;

SET NAMES utf8mb4;

-- ----------------------------------------------------------------
-- 1. ORDER_RISK_SCORES — one row per scoring run
--
-- Append-only by convention: a re-score inserts a NEW row rather than
-- updating the old one, so "what did we think at the time, and why" is
-- answerable after the rules have been tuned. The newest row per order is
-- the current one.
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS order_risk_scores (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  order_id      INT NOT NULL,
  sub_order_id  INT NULL,                       -- set when a signal is about one seller's half

  score         TINYINT UNSIGNED NOT NULL DEFAULT 0,   -- the blended 0-100 figure
  level         ENUM('low','medium','high','unscored') NOT NULL DEFAULT 'unscored',

  rule_score    TINYINT UNSIGNED NOT NULL DEFAULT 0,   -- what the PHP engine alone said
  ml_score      TINYINT UNSIGNED NULL,                 -- NULL when the Python service was unavailable

  -- [{code, label, weight, evidence}, ...]. On MariaDB JSON is LONGTEXT with
  -- an automatic JSON_VALID() check, so a malformed array is refused by the
  -- database — the PHP side validates before saving regardless.
  signals       JSON NULL,

  explanation         TEXT NULL,
  explanation_source  ENUM('template','llm') NOT NULL DEFAULT 'template',
  model_version       VARCHAR(30) NULL,         -- which model produced ml_score

  scored_by     ENUM('checkout','sweep','admin') NOT NULL DEFAULT 'checkout',
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  INDEX idx_risk_order (order_id, created_at),
  INDEX idx_risk_level (level, created_at),
  CONSTRAINT fk_risk_order     FOREIGN KEY (order_id)     REFERENCES orders(id)     ON DELETE CASCADE,
  CONSTRAINT fk_risk_suborder  FOREIGN KEY (sub_order_id) REFERENCES sub_orders(id) ON DELETE CASCADE,
  CONSTRAINT chk_risk_score    CHECK (score <= 100),
  CONSTRAINT chk_risk_rule     CHECK (rule_score <= 100),
  CONSTRAINT chk_risk_ml       CHECK (ml_score IS NULL OR ml_score <= 100)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------
-- 2. RISK_ALERTS — the admin's work queue
--
-- A score is a measurement; an alert is a thing somebody has to decide
-- about. They are separate tables because every order gets scored and only
-- a few need a human.
--
-- alert_type says what is being flagged: a single order, a buyer's pattern
-- across orders, or a seller's. The buyer/seller types come from the
-- scheduled sweep, which is why order_id is nullable here.
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS risk_alerts (
  id            INT AUTO_INCREMENT PRIMARY KEY,

  alert_type    ENUM('order','buyer','seller') NOT NULL DEFAULT 'order',
  order_id      INT NULL,
  sub_order_id  INT NULL,
  seller_id     INT NULL,
  user_id       INT NULL,                       -- the buyer

  level         ENUM('medium','high') NOT NULL,
  score         TINYINT UNSIGNED NOT NULL DEFAULT 0,
  risk_score_id INT NULL,                       -- the scoring run that raised it

  status        ENUM('open','under_review','confirmed_fraud','dismissed') NOT NULL DEFAULT 'open',
  reviewed_by   INT NULL,
  review_note   VARCHAR(500) NULL,

  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  reviewed_at   DATETIME NULL,

  -- The queue is read as "open, worst first, newest first", so the index is
  -- in that order.
  INDEX idx_alert_queue (status, level, created_at),
  INDEX idx_alert_order (order_id),
  INDEX idx_alert_seller (seller_id, status),
  INDEX idx_alert_user (user_id, status),

  CONSTRAINT fk_alert_order    FOREIGN KEY (order_id)      REFERENCES orders(id)            ON DELETE CASCADE,
  CONSTRAINT fk_alert_suborder FOREIGN KEY (sub_order_id)  REFERENCES sub_orders(id)        ON DELETE CASCADE,
  CONSTRAINT fk_alert_seller   FOREIGN KEY (seller_id)     REFERENCES sellers(id)           ON DELETE CASCADE,
  CONSTRAINT fk_alert_user     FOREIGN KEY (user_id)       REFERENCES users(id)             ON DELETE CASCADE,
  CONSTRAINT fk_alert_score    FOREIGN KEY (risk_score_id) REFERENCES order_risk_scores(id) ON DELETE SET NULL,
  -- reviewed_by is RESTRICT, like admin_actions.admin_id: an admin who has
  -- made a judgement call cannot be deleted out from under it.
  CONSTRAINT fk_alert_reviewer FOREIGN KEY (reviewed_by)   REFERENCES users(id)             ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------
-- 3. THE PAYOUT HOLD, on sub_orders
--
-- Why a new column rather than reusing the dispute_* fields: a risk hold is
-- not a dispute. Nobody has complained. Writing one into dispute_reason
-- would corrupt the dispute-rate figures the admin finance screen reports —
-- and one of the risk rules below is itself "this seller's dispute rate is
-- over 25%", so it would feed back into its own input.
--
-- ADD COLUMN IF NOT EXISTS is MariaDB-specific and is what makes this file
-- re-runnable.
--
-- The hold is ENFORCED in order_apply_status() (api/orders/_orders.php) —
-- the single place escrow is ever released — not here. A CHECK constraint
-- cannot express "payout_released_at must stay NULL while payout_hold = 1"
-- across the UPDATE that sets both.
-- ----------------------------------------------------------------
ALTER TABLE sub_orders
  ADD COLUMN IF NOT EXISTS payout_hold         TINYINT(1)   NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS payout_hold_at      DATETIME     NULL,
  ADD COLUMN IF NOT EXISTS payout_hold_reason  VARCHAR(200) NULL,
  ADD COLUMN IF NOT EXISTS payout_hold_alert_id INT         NULL;

-- Separate statement: ADD INDEX IF NOT EXISTS is also MariaDB-only, and
-- keeping it apart makes a failure here easy to read.
ALTER TABLE sub_orders
  ADD INDEX IF NOT EXISTS idx_suborder_hold (payout_hold, payout_released_at);

-- ----------------------------------------------------------------
-- 4. DEMO DATA MARKER
--
-- Phase C generates ~1,500 synthetic orders so there is something to score.
-- They are tracked HERE rather than with a column on orders, so the
-- production schema carries nothing for a test concern — and `--clear`
-- deletes exactly the generated set and nothing else.
--
-- One batch tag per generator run, so two runs can be cleared separately.
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS risk_demo_data (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  batch       VARCHAR(40) NOT NULL,             -- e.g. 'demo-2026-11-08-a1b2'
  -- 'product' rows are not objects this script created: they are SNAPSHOTS
  -- of an existing seeded product's displayed aggregate, restored on --clear.
  object_type ENUM('order','review','user','product') NOT NULL,
  object_id   INT NOT NULL,
  -- Which injected anomaly this row is, or NULL for ordinary traffic. This
  -- is the LABEL the ML evaluation scores itself against.
  anomaly     VARCHAR(40) NULL,
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  -- Anything the generator must put BACK on --clear. Used for a product's
  -- rating and review_count: those columns are a denormalised aggregate the
  -- seed sets to the catalogue's real figures (p1 shows 128 reviews while
  -- the reviews table holds 5 samples), so recomputing them from the table
  -- would quietly destroy the seeded display values. Snapshot, then restore.
  meta        JSON NULL,

  UNIQUE KEY uq_demo_object (object_type, object_id),
  INDEX idx_demo_batch (batch, object_type),
  INDEX idx_demo_anomaly (anomaly)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A database created before the meta column existed gets it here, so an
-- existing install does not need the table dropped.
ALTER TABLE risk_demo_data
  ADD COLUMN IF NOT EXISTS meta JSON NULL,
  MODIFY COLUMN object_type ENUM('order','review','user','product') NOT NULL;

-- ----------------------------------------------------------------
-- 5. SETTINGS
--
-- INSERT IGNORE, not ON DUPLICATE KEY UPDATE: re-running this file must not
-- throw away thresholds an admin has tuned. The values are JSON because
-- that is what the settings.value column is.
-- ----------------------------------------------------------------
INSERT IGNORE INTO settings (setting_key, value) VALUES
  -- Level boundaries. low < medium_at, medium < high_at, high >= high_at.
  ('risk_medium_at',      '35'),
  ('risk_high_at',        '65'),
  -- How the rule score and the ML score combine. They must sum to 1.0; the
  -- rule engine is weighted higher because its signals are explainable and
  -- the model's are not.
  ('risk_rule_weight',    '0.7'),
  ('risk_ml_weight',      '0.3'),
  -- Hold the escrow payout automatically at this level or above. 'high'
  -- only, by design: holding a medium-risk payout would punish ordinary
  -- sellers for a statistical wobble.
  ('risk_hold_at_level',  '"high"'),
  -- Master switch. Off means score and alert, but never hold a payout.
  ('risk_holds_enabled',  'true'),
  -- Let the LLM word the explanation. Off means always use the PHP template.
  ('risk_llm_explain',    'true');

-- ================================================================
-- What this file deliberately does NOT do
--
--  * No trigger sets a payout hold. A hold is a decision with a reason
--    attached, made by code that can log it; a trigger would make it
--    invisible.
--  * No ON DELETE CASCADE from risk_alerts to anything that would delete an
--    order. Resolving an alert never deletes data.
--  * No column on orders or products. Everything here is additive and can
--    be dropped without touching the shop:
--
--      DROP TABLE IF EXISTS risk_alerts, order_risk_scores, risk_demo_data;
--      ALTER TABLE sub_orders
--        DROP COLUMN IF EXISTS payout_hold,
--        DROP COLUMN IF EXISTS payout_hold_at,
--        DROP COLUMN IF EXISTS payout_hold_reason,
--        DROP COLUMN IF EXISTS payout_hold_alert_id;
--      DELETE FROM settings WHERE setting_key LIKE 'risk_%';
-- ================================================================

-- ================================================================
-- PHASE D: the audit trail has to be able to name a risk alert
--
-- admin_actions.target_type is an ENUM, so an admin decision on an alert
-- had nowhere to point. 'order' was the tempting shortcut and it is wrong
-- for two of the three alert types: a buyer alert and a seller alert have
-- no order at all, and filing them under a NULL order would make the audit
-- trail unsearchable for exactly the decisions most worth finding later.
--
-- MODIFY rather than a guarded ADD because MySQL has no
-- "ALTER ... ADD ENUM VALUE". Re-running it is harmless: it restates the
-- whole list, so the file stays idempotent. ADMIN_TARGETS in
-- api/admin/_admin.php must list the same values -- admin_log() checks
-- against it and throws on anything else, which is what turns a typo into
-- a loud failure rather than a missing audit row.
-- ================================================================

ALTER TABLE admin_actions
  MODIFY COLUMN target_type
    ENUM('user','seller','product','order','sub_order','faq','settings','risk_alert')
    NOT NULL;

-- ================================================================
-- PHASE D: notes the buyer and the seller must not read
--
-- risk_persist.php appends to order_status_log ("Flagged for review —
-- payout held"), because that is where an order's history belongs and the
-- admin detail screen reads it from there.
--
-- But order_timelines_for() in api/orders/_orders.php serves the CUSTOMER'S
-- own order page and the SELLER'S, so without this column the buyer was
-- shown "Flagged for review" on their own order. Two things wrong with
-- that: it tells the subject of a review that they are being reviewed
-- (which is the same reason seller/risk_notices.php hides the score), and
-- "payout held" is about the seller's money and means nothing to a buyer.
--
-- changed_by could not be used to tell them apart: 'system' is ALSO the
-- legitimate "Order placed · payment held in escrow (demo)" note, which the
-- customer should absolutely see. So the distinction has to be explicit.
--
-- Default 0, so every existing row and every write from the rest of the
-- app stays visible exactly as before.
-- ================================================================

ALTER TABLE order_status_log
  ADD COLUMN IF NOT EXISTS internal TINYINT(1) NOT NULL DEFAULT 0
    COMMENT 'admin-only note; hidden from the buyer and seller timelines';

ALTER TABLE order_status_log
  ADD INDEX IF NOT EXISTS idx_log_internal (sub_order_id, internal);
