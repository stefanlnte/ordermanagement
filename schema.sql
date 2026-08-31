-- ============================================================================
--  schema.sql — Database schema for the Color Print order management app
-- ----------------------------------------------------------------------------
--  The app has no migration tooling; this file recreates a fresh, EMPTY
--  database that matches exactly what the PHP code reads and writes
--  (every column below is referenced by at least one query in the repo).
--
--  Import options:
--    * phpMyAdmin: http://localhost/phpmyadmin  ->  Import  ->  schema.sql
--    * CLI:        mysql -u root < schema.sql
--
--  Requirements:
--    * MySQL 8.0.13+ or MariaDB 10.2.1+ — expression defaults
--      DEFAULT (CURRENT_DATE) / (CURRENT_TIME) are used because the
--      "add order" INSERT in dashboard.php does not provide order_date /
--      order_time.
--    * InnoDB (archive_orders.php uses transactions).
--
--  Notes:
--    * Intentionally NO foreign key constraints: the app enforces
--      integrity in PHP, and archive_orders.php renumbers orders.order_id,
--      which FKs would complicate. Helpful indexes are provided instead.
--    * CREATE TABLE IF NOT EXISTS / INSERT IGNORE make this file safe to
--      re-run and safe to import over an existing dev database.
--    * Seed data: one login (admin / "password" — CHANGE IT after first
--      login) and one category ("Diverse") so the add-order form works on
--      a fresh install. Edit or remove the SEED section as needed.
-- ============================================================================

CREATE DATABASE IF NOT EXISTS order_management_system
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE order_management_system;

SET NAMES utf8mb4;

-- ----------------------------------------------------------------------------
-- clients — written by add_order.php / dashboard.php (dedupe check by phone),
-- read by fetch_clients.php / get_client.php, updated by update_client.php /
-- edit_client.php.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS clients (
    client_id    INT          NOT NULL AUTO_INCREMENT,
    client_name  VARCHAR(255) NOT NULL,
    client_email VARCHAR(255) NULL,
    client_phone VARCHAR(20)  NOT NULL,
    PRIMARY KEY (client_id),
    KEY idx_clients_phone (client_phone)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- users — login/auth (authenticate.php), operators dropdown
-- (get_operators.php), statistics.php joins. Note: get_operators.php
-- excludes user_id 3 and 4 by design.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
    user_id  INT          NOT NULL AUTO_INCREMENT,
    username VARCHAR(50)  NOT NULL,
    password VARCHAR(255) NOT NULL,                 -- bcrypt (password_hash)
    role     VARCHAR(20)  NOT NULL DEFAULT 'OPERATOR',
    PRIMARY KEY (user_id),
    UNIQUE KEY uq_users_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- categories — read with SELECT * by dashboard.php; used in the add-order
-- form and in the archive filters (category_id / category_name).
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS categories (
    category_id   INT          NOT NULL AUTO_INCREMENT,
    category_name VARCHAR(255) NOT NULL,
    PRIMARY KEY (category_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- articles — catalog of article types. `price` is the default price:
-- update_default_price.php writes it, add_article.php / fetch_articles.php
-- read it.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS articles (
    id    INT           NOT NULL AUTO_INCREMENT,
    name  VARCHAR(255)  NOT NULL,
    price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    PRIMARY KEY (id),
    KEY idx_articles_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- orders — the main table. Column notes:
--   * order_date/order_time  : dashboard.php's INSERT omits them, so they
--                              rely on the expression defaults; add_order.php
--                              sets them explicitly.
--   * status                 : dashboard.php's INSERT omits it -> defaults to
--                              'assigned'. Values in use: assigned, completed,
--                              delivered, cancelled. VARCHAR (not ENUM) on
--                              purpose — some legacy files use a different
--                              vocabulary (fetch_orders.php: UNASSIGNED etc.).
--   * due_time               : the dashboard form never posts it -> stays
--                              NULL (view_order.php treats the deadline as
--                              18:00 of due_date).
--   * delivery_date          : DATETIME — script.js posts 'Y-m-d H:i:s'.
--   * finished_date/time     : written by update_order_status.php when a
--                              status becomes 'completed'.
--   * is_pinned / is_achitat : toggle_pin.php / update_achitat.php.
--   * detalii_suplimentare   : update_order_details.php / view_order.php.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS orders (
    order_id             INT           NOT NULL AUTO_INCREMENT,
    client_id            INT           NOT NULL,
    order_details        TEXT          NOT NULL,
    detalii_suplimentare TEXT          NULL,
    order_date           DATE          NOT NULL DEFAULT (CURRENT_DATE),
    order_time           TIME          NULL     DEFAULT (CURRENT_TIME),
    due_date             DATE          NOT NULL,
    due_time             TIME          NULL,
    category_id          INT           NULL,
    avans                DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    total                DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    assigned_to          INT           NULL,
    created_by           INT           NULL,
    status               VARCHAR(20)   NOT NULL DEFAULT 'assigned',
    delivery_date        DATETIME      NULL,
    finished_date        DATE          NULL,
    finished_time        TIME          NULL,
    is_pinned            TINYINT(1)    NOT NULL DEFAULT 0,
    is_achitat           TINYINT(1)    NOT NULL DEFAULT 0,
    PRIMARY KEY (order_id),
    KEY idx_orders_client (client_id),
    KEY idx_orders_status (status),
    KEY idx_orders_due_date (due_date),
    KEY idx_orders_assigned_to (assigned_to),
    KEY idx_orders_category (category_id),
    KEY idx_orders_delivery_date (delivery_date),
    KEY idx_orders_is_pinned (is_pinned)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- archived_orders — mirror of `orders`. Rows are copied here (and deleted
-- from `orders`) by the destructive one-shot archive_orders.php; archive.php
-- reads them back. Mirroring the full `orders` shape keeps that copy simple
-- (archive_orders.php inserts a 13-column subset; the rest fall back to the
-- defaults below).
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS archived_orders (
    order_id             INT           NOT NULL AUTO_INCREMENT,
    client_id            INT           NOT NULL,
    order_details        TEXT          NOT NULL,
    detalii_suplimentare TEXT          NULL,
    order_date           DATE          NOT NULL DEFAULT (CURRENT_DATE),
    order_time           TIME          NULL     DEFAULT (CURRENT_TIME),
    due_date             DATE          NOT NULL,
    due_time             TIME          NULL,
    category_id          INT           NULL,
    avans                DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    total                DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    assigned_to          INT           NULL,
    created_by           INT           NULL,
    status               VARCHAR(20)   NOT NULL DEFAULT 'assigned',
    delivery_date        DATETIME      NULL,
    finished_date        DATE          NULL,
    finished_time        TIME          NULL,
    is_pinned            TINYINT(1)    NOT NULL DEFAULT 0,
    is_achitat           TINYINT(1)    NOT NULL DEFAULT 0,
    PRIMARY KEY (order_id),
    KEY idx_arch_client (client_id),
    KEY idx_arch_status (status),
    KEY idx_arch_assigned_to (assigned_to),
    KEY idx_arch_category (category_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- order_articles — the article lines of an order (the "bon"). orders.total is
-- recalculated from these rows by add_article.php / delete_article.php /
-- update_order_details.php. Read by view_order.php / fetch_order_articles.php /
-- order_preview.php.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS order_articles (
    id             INT           NOT NULL AUTO_INCREMENT,
    order_id       INT           NOT NULL,
    article_id     INT           NOT NULL,
    quantity       INT           NOT NULL DEFAULT 1,
    price_per_unit DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    PRIMARY KEY (id),
    KEY idx_oa_order (order_id),
    KEY idx_oa_article (article_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- order_attachments — metadata for uploaded files; the files themselves live
-- on disk under uploads/orders/<order_id>/ (created by upload_attachment.php).
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS order_attachments (
    id       INT          NOT NULL AUTO_INCREMENT,
    order_id INT          NOT NULL,
    filename VARCHAR(255) NOT NULL,
    filepath VARCHAR(512) NOT NULL,               -- absolute path on disk
    PRIMARY KEY (id),
    KEY idx_att_order (order_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- remember_tokens — "Tine-ma minte" (30-day) login cookies, written by
-- authenticate.php, validated by the auth block duplicated in every page,
-- deleted by logout.php.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS remember_tokens (
    id      INT         NOT NULL AUTO_INCREMENT,
    user_id INT         NOT NULL,
    token   VARCHAR(64) NOT NULL,                 -- bin2hex(random_bytes(32))
    PRIMARY KEY (id),
    UNIQUE KEY uq_rt_token (token),
    KEY idx_rt_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- unpaid_orders — "comenzi nefacturate" (unpaid_orders.php). order_date is
-- never written by the code, hence the CURRENT_TIMESTAMP default.
-- Known code quirk (not a schema issue): the filtered COUNT(*) query in
-- unpaid_orders.php references a `client_name` column that does not exist
-- here, so that page's client filter count can fail — the list query itself
-- joins `clients` and works fine.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS unpaid_orders (
    order_id      INT      NOT NULL AUTO_INCREMENT,
    client_id     INT      NOT NULL,
    order_details TEXT     NOT NULL,
    created_by    INT      NULL,
    order_date    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (order_id),
    KEY idx_uo_client (client_id),
    KEY idx_uo_date (order_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
--  SEED DATA (INSERT IGNORE — safe to re-run; harmless on existing databases)
-- ============================================================================

-- Default login: admin / "password"  —  CHANGE THE PASSWORD after first login.
-- Generate a bcrypt hash for your own password with hash.php.
INSERT IGNORE INTO users (user_id, username, password, role) VALUES
    (1, 'admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'OPERATOR');

-- One neutral category so the add-order form works out of the box
-- (the category dropdown is built from this table). Add your real
-- categories here or later via phpMyAdmin.
INSERT IGNORE INTO categories (category_id, category_name) VALUES
    (1, 'Diverse');