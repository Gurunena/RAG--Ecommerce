-- ================================================================
-- Consult — database schema
-- Target: MariaDB 10.4+ (XAMPP) and MySQL 8. Charset utf8mb4.
--
-- Derived from ../../project.sql (the original design), fixed so it
-- actually runs and extended with what the live app needs. Every
-- change is listed in sql/SCHEMA-CHANGES.md.
--
-- Design decisions this schema exists to show (viva talking points):
--   * multi-seller checkout: ONE orders row per payment, split into one
--     sub_orders row per seller; each seller only ever sees their own
--   * escrow: sub_orders.payout_released_at stays NULL until the
--     customer confirms delivery; payments.status 'captured' = held
--   * order_items.unit_price / product_title and sub_orders.commission_rate
--     snapshot the values at purchase time
--   * the shipping address is snapshotted onto orders, not linked
--   * order_status_log is an append-only audit trail (enforced by triggers)
--
-- Re-runnable: drops and recreates every table. THIS DELETES ALL DATA.
-- ================================================================

CREATE DATABASE IF NOT EXISTS consult
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE consult;

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS contact_messages, faqs, admin_actions, settings, login_attempts,
  coupons, payments, order_status_log, order_items, sub_orders, orders,
  cart_items, wishlist, reviews, product_sales, product_tags, product_images,
  products, sellers, users;
SET FOREIGN_KEY_CHECKS = 1;

-- ----------------------------------------------------------------
-- 1. USERS — customers, sellers and admins share one table
-- ----------------------------------------------------------------
CREATE TABLE users (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  name           VARCHAR(120) NOT NULL,
  email          VARCHAR(150) NOT NULL,
  phone          VARCHAR(20)  NULL,
  password_hash  VARCHAR(255) NOT NULL,                 -- password_hash() output, never plain text
  role           ENUM('customer','seller','admin') NOT NULL DEFAULT 'customer',
  status         ENUM('active','suspended') NOT NULL DEFAULT 'active',
  avatar_url     VARCHAR(255) NULL,
  last_login_at  DATETIME NULL,
  created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  UNIQUE KEY uq_users_email (email),
  INDEX idx_users_role (role)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------
-- 2. SELLERS — 1:1 extension of a users row (shared primary key)
-- ----------------------------------------------------------------
CREATE TABLE sellers (
  id                    INT PRIMARY KEY,                -- = users.id
  public_id             VARCHAR(20)  NULL,              -- 's1'-style id used by data/products.json + the AI service
  business_name         VARCHAR(150) NOT NULL,
  business_type         ENUM('sole_proprietorship','partnership','plc','other') NOT NULL DEFAULT 'sole_proprietorship',
  registration_no       VARCHAR(60)  NULL,
  city                  VARCHAR(80)  NULL,
  district              VARCHAR(60)  NULL,
  primary_category      VARCHAR(60)  NULL,
  nic_number            VARCHAR(20)  NOT NULL,
  bank_name             VARCHAR(80)  NULL,
  account_number        VARCHAR(40)  NULL,
  branch                VARCHAR(80)  NULL,
  kyc_status            ENUM('pending','verified','rejected') NOT NULL DEFAULT 'pending',
  kyc_document_url      VARCHAR(255) NULL,
  kyc_rejection_reason  VARCHAR(500) NULL,
  kyc_reviewed_at       DATETIME     NULL,
  kyc_reviewed_by       INT          NULL,
  commission_rate       DECIMAL(4,2) NULL,              -- NULL = platform default (settings.commission_rate)
  rating                DECIMAL(2,1) NOT NULL DEFAULT 0.0,
  created_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  UNIQUE KEY uq_sellers_public (public_id),
  INDEX idx_sellers_kyc (kyc_status),
  CONSTRAINT fk_sellers_user     FOREIGN KEY (id)              REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_sellers_reviewer FOREIGN KEY (kyc_reviewed_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT chk_sellers_rate CHECK (commission_rate IS NULL OR commission_rate BETWEEN 0 AND 50)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------
-- 3. PRODUCTS
-- ----------------------------------------------------------------
CREATE TABLE products (
  id                INT AUTO_INCREMENT PRIMARY KEY,
  public_id         VARCHAR(20)  NOT NULL,              -- 'p1'-style id; the AI service returns these
  seller_id         INT NOT NULL,
  title             VARCHAR(180) NOT NULL,
  subtitle          VARCHAR(255) NULL,
  category          VARCHAR(60)  NOT NULL,
  icon              VARCHAR(40)  NULL,
  description       TEXT NULL,
  price             DECIMAL(12,2) NOT NULL,
  old_price         DECIMAL(12,2) NULL,
  currency          VARCHAR(6) NOT NULL DEFAULT 'LKR',
  stock             INT NOT NULL DEFAULT 0,
  specs             JSON NULL,                          -- MariaDB: alias for LONGTEXT + JSON_VALID check
  rating_breakdown  JSON NULL,                          -- {"5":80,"4":14,...} percentages shown on the detail page
  badge             VARCHAR(30) NULL,
  status            ENUM('pending','active','rejected','inactive') NOT NULL DEFAULT 'pending',
  rejection_reason  VARCHAR(500) NULL,                  -- shown to the seller (reject or take-down)
  reviewed_at       DATETIME NULL,
  reviewed_by       INT NULL,
  rating            DECIMAL(2,1) NOT NULL DEFAULT 0.0,  -- denormalised aggregate, kept in sync by the API
  review_count      INT NOT NULL DEFAULT 0,
  deleted_at        DATETIME NULL,                      -- soft delete: order_items must keep pointing here
  created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  UNIQUE KEY uq_products_public (public_id),
  INDEX idx_products_category (category),
  INDEX idx_products_seller (seller_id),
  INDEX idx_products_status (status),
  CONSTRAINT fk_products_seller   FOREIGN KEY (seller_id)   REFERENCES sellers(id) ON DELETE CASCADE,
  CONSTRAINT fk_products_reviewer FOREIGN KEY (reviewed_by) REFERENCES users(id)   ON DELETE SET NULL,
  CONSTRAINT chk_products_price CHECK (price > 0),
  CONSTRAINT chk_products_stock CHECK (stock >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE product_images (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  product_id  INT NOT NULL,
  image_url   VARCHAR(255) NOT NULL,
  sort_order  TINYINT NOT NULL DEFAULT 0,

  INDEX idx_pimg_product (product_id, sort_order),
  CONSTRAINT fk_pimg_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE product_tags (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  product_id  INT NOT NULL,
  tag         VARCHAR(40) NOT NULL,

  UNIQUE KEY uq_ptag (product_id, tag),
  INDEX idx_ptag_tag (tag),
  CONSTRAINT fk_ptag_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- scheduled, date-to-date sale pricing; the active price is computed in
-- SQL by date (see the API), so a sale starts and ends with no cron job
CREATE TABLE product_sales (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  product_id  INT NOT NULL,
  sale_price  DECIMAL(12,2) NOT NULL,
  start_date  DATE NOT NULL,
  end_date    DATE NOT NULL,
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  INDEX idx_psale_active (product_id, start_date, end_date),
  CONSTRAINT fk_psale_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
  CONSTRAINT chk_psale_dates CHECK (end_date >= start_date),
  CONSTRAINT chk_psale_price CHECK (sale_price > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------
-- 4. REVIEWS — one per product per user
-- ----------------------------------------------------------------
CREATE TABLE reviews (
  id                 INT AUTO_INCREMENT PRIMARY KEY,
  product_id         INT NOT NULL,
  user_id            INT NOT NULL,
  rating             TINYINT NOT NULL,
  review_text        TEXT NULL,
  is_verified_buyer  BOOLEAN NOT NULL DEFAULT FALSE,
  helpful_count      INT NOT NULL DEFAULT 0,
  created_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  UNIQUE KEY uq_one_review_per_user (product_id, user_id),
  CONSTRAINT fk_review_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
  CONSTRAINT fk_review_user    FOREIGN KEY (user_id)    REFERENCES users(id)    ON DELETE CASCADE,
  CONSTRAINT chk_review_rating CHECK (rating BETWEEN 1 AND 5)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------
-- 5. WISHLIST
-- ----------------------------------------------------------------
CREATE TABLE wishlist (
  user_id     INT NOT NULL,
  product_id  INT NOT NULL,
  added_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (user_id, product_id),
  CONSTRAINT fk_wish_user    FOREIGN KEY (user_id)    REFERENCES users(id)    ON DELETE CASCADE,
  CONSTRAINT fk_wish_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------
-- 6. CART — server-side for signed-in users
-- ----------------------------------------------------------------
CREATE TABLE cart_items (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  user_id     INT NOT NULL,
  product_id  INT NOT NULL,
  quantity    INT NOT NULL DEFAULT 1,
  added_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  UNIQUE KEY uq_user_product (user_id, product_id),
  CONSTRAINT fk_cart_user    FOREIGN KEY (user_id)    REFERENCES users(id)    ON DELETE CASCADE,
  CONSTRAINT fk_cart_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
  CONSTRAINT chk_cart_qty CHECK (quantity BETWEEN 1 AND 99)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------
-- 7. ORDERS — one checkout, one payment, ONE row here
--    the shipping address is a snapshot, not a link to the profile
-- ----------------------------------------------------------------
CREATE TABLE orders (
  id                INT AUTO_INCREMENT PRIMARY KEY,
  user_id           INT NOT NULL,
  total_amount      DECIMAL(12,2) NOT NULL,
  currency          VARCHAR(6) NOT NULL DEFAULT 'LKR',
  payment_status    ENUM('pending','paid','failed','refunded','partially_refunded') NOT NULL DEFAULT 'pending',
  shipping_name     VARCHAR(120) NOT NULL,
  shipping_phone    VARCHAR(20)  NOT NULL,
  shipping_address  VARCHAR(255) NOT NULL,
  shipping_city     VARCHAR(80)  NOT NULL,
  shipping_zip      VARCHAR(12)  NULL,
  shipping_note     VARCHAR(255) NULL,
  created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  INDEX idx_orders_user (user_id, created_at),
  CONSTRAINT fk_order_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------
-- 8. SUB_ORDERS — the split per seller; status, escrow and disputes
--    live here because each seller ships (and is paid) separately
-- ----------------------------------------------------------------
CREATE TABLE sub_orders (
  id                   INT AUTO_INCREMENT PRIMARY KEY,
  order_id             INT NOT NULL,
  seller_id            INT NOT NULL,
  status               ENUM('pending','packed','shipped','delivered','cancelled','disputed','refunded')
                         NOT NULL DEFAULT 'pending',
  subtotal             DECIMAL(12,2) NOT NULL,
  delivery_fee         DECIMAL(12,2) NOT NULL DEFAULT 0,
  commission_rate      DECIMAL(4,2) NOT NULL,           -- snapshot: later rate changes don't rewrite history
  tracking_number      VARCHAR(60)  NULL,
  courier_name         VARCHAR(60)  NULL,
  status_updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  delivered_at         DATETIME NULL,                   -- set when the CUSTOMER confirms delivery
  payout_released_at   DATETIME NULL,                   -- escrow: NULL until delivery is confirmed
  refunded_at          DATETIME NULL,
  dispute_reason       VARCHAR(500) NULL,
  dispute_opened_by    ENUM('customer','admin') NULL,
  dispute_opened_at    DATETIME NULL,
  dispute_resolution   VARCHAR(500) NULL,
  dispute_resolved_at  DATETIME NULL,

  INDEX idx_suborder_order (order_id),
  INDEX idx_suborder_seller (seller_id, status),
  INDEX idx_suborder_status (status),
  CONSTRAINT fk_sub_order  FOREIGN KEY (order_id)  REFERENCES orders(id)  ON DELETE CASCADE,
  CONSTRAINT fk_sub_seller FOREIGN KEY (seller_id) REFERENCES sellers(id) ON DELETE RESTRICT,
  CONSTRAINT chk_sub_amounts CHECK (subtotal >= 0 AND delivery_fee >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- individual line items; price and title are snapshots taken at checkout
CREATE TABLE order_items (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  sub_order_id   INT NOT NULL,
  product_id     INT NOT NULL,
  product_title  VARCHAR(180) NOT NULL,
  quantity       INT NOT NULL,
  unit_price     DECIMAL(12,2) NOT NULL,

  INDEX idx_item_product (product_id),
  CONSTRAINT fk_item_suborder FOREIGN KEY (sub_order_id) REFERENCES sub_orders(id) ON DELETE CASCADE,
  CONSTRAINT fk_item_product  FOREIGN KEY (product_id)   REFERENCES products(id)   ON DELETE RESTRICT,
  CONSTRAINT chk_item_qty CHECK (quantity > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- append-only audit trail of every status change (see triggers below)
CREATE TABLE order_status_log (
  id                  INT AUTO_INCREMENT PRIMARY KEY,
  sub_order_id        INT NOT NULL,
  status              ENUM('pending','packed','shipped','delivered','cancelled','disputed','refunded') NOT NULL,
  changed_by          ENUM('customer','seller','admin','system') NOT NULL DEFAULT 'system',
  changed_by_user_id  INT NULL,
  note                VARCHAR(255) NULL,
  changed_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  INDEX idx_log_suborder (sub_order_id, changed_at),
  CONSTRAINT fk_log_suborder FOREIGN KEY (sub_order_id)       REFERENCES sub_orders(id) ON DELETE CASCADE,
  CONSTRAINT fk_log_user     FOREIGN KEY (changed_by_user_id) REFERENCES users(id)      ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------
-- 9. PAYMENTS — separate from orders so refunds/disputes are traceable.
--    The gateway is SIMULATED: provider 'demo', status 'captured' = held.
-- ----------------------------------------------------------------
CREATE TABLE payments (
  id                  INT AUTO_INCREMENT PRIMARY KEY,
  order_id            INT NOT NULL,
  provider            VARCHAR(30) NOT NULL DEFAULT 'demo',
  provider_ref        VARCHAR(100) NULL,
  amount              DECIMAL(12,2) NOT NULL,
  refunded_amount     DECIMAL(12,2) NOT NULL DEFAULT 0,
  status              ENUM('initiated','captured','failed','refunded','partially_refunded') NOT NULL DEFAULT 'initiated',
  captured_at         DATETIME NULL,
  escrow_released_at  DATETIME NULL,                    -- set once every sub-order has been paid out

  INDEX idx_payment_order (order_id),
  CONSTRAINT fk_payment_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------
-- 10. COUPONS (kept from the design; not wired to the UI yet)
-- ----------------------------------------------------------------
CREATE TABLE coupons (
  id                INT AUTO_INCREMENT PRIMARY KEY,
  seller_id         INT NULL,                           -- NULL = platform-wide
  code              VARCHAR(30) NOT NULL,
  discount_type     ENUM('percent','fixed') NOT NULL,
  discount_value    DECIMAL(10,2) NOT NULL,
  min_order_amount  DECIMAL(12,2) NOT NULL DEFAULT 0,
  valid_from        DATE NOT NULL,
  valid_to          DATE NOT NULL,
  usage_limit       INT NULL,
  times_used        INT NOT NULL DEFAULT 0,

  UNIQUE KEY uq_coupon_code (code),
  CONSTRAINT fk_coupon_seller FOREIGN KEY (seller_id) REFERENCES sellers(id) ON DELETE CASCADE,
  CONSTRAINT chk_coupon_dates CHECK (valid_to >= valid_from)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------
-- 11. ADMIN / PLATFORM TABLES
-- ----------------------------------------------------------------
-- every admin action: who, what, target, reason, when
CREATE TABLE admin_actions (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  admin_id     INT NOT NULL,
  action_type  VARCHAR(60) NOT NULL,
  target_type  ENUM('user','seller','product','order','sub_order','faq','settings') NOT NULL,
  target_id    INT NULL,                                -- NULL for platform-wide settings
  notes        VARCHAR(500) NULL,
  created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  INDEX idx_admin_actions_time (created_at),
  CONSTRAINT fk_admin_action_user FOREIGN KEY (admin_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- platform settings as key/value; value is JSON so one table holds
-- numbers, booleans and the category list
CREATE TABLE settings (
  setting_key  VARCHAR(60) PRIMARY KEY,
  value        JSON NOT NULL,
  updated_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  updated_by   INT NULL,

  CONSTRAINT fk_settings_user FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE faqs (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  question    VARCHAR(255) NOT NULL,
  answer      TEXT NOT NULL,
  category    VARCHAR(60) NOT NULL DEFAULT 'general',
  sort_order  INT NOT NULL DEFAULT 0,
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  INDEX idx_faqs_order (category, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE contact_messages (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  user_id     INT NULL,                                 -- set when a signed-in user writes in
  name        VARCHAR(120) NOT NULL,
  email       VARCHAR(150) NOT NULL,
  subject     VARCHAR(150) NULL,
  message     TEXT NOT NULL,
  ip_address  VARCHAR(45) NULL,
  status      ENUM('new','read','replied') NOT NULL DEFAULT 'new',
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  CONSTRAINT fk_contact_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- login rate limiting: 5 failures per email or IP per 10 minutes
CREATE TABLE login_attempts (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  email         VARCHAR(150) NOT NULL,
  ip_address    VARCHAR(45)  NOT NULL,
  attempted_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  INDEX idx_login_email (email, attempted_at),
  INDEX idx_login_ip (ip_address, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------
-- order_status_log is append-only: no UPDATE, no direct DELETE.
-- (Rows still go away with their sub-order via ON DELETE CASCADE —
-- InnoDB doesn't fire triggers for cascaded deletes.)
-- ----------------------------------------------------------------
DELIMITER //
CREATE TRIGGER trg_status_log_no_update BEFORE UPDATE ON order_status_log
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'order_status_log is append-only';
END//

CREATE TRIGGER trg_status_log_no_delete BEFORE DELETE ON order_status_log
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'order_status_log is append-only';
END//
DELIMITER ;
