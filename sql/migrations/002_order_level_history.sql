-- PartPulse 2.0 upgrade for installations created with version 1.x.
-- Import ONCE in phpMyAdmin (or just open admin.php, which applies it automatically).
SET NAMES utf8mb4;

ALTER TABLE imports
  ADD COLUMN source_type VARCHAR(20) NOT NULL DEFAULT 'xlsx' AFTER uploaded_by,
  ADD COLUMN source_generated_at DATETIME NULL AFTER source_type,
  ADD COLUMN order_count INT UNSIGNED NULL AFTER source_generated_at,
  ADD COLUMN history_added INT UNSIGNED NULL AFTER order_count,
  ADD COLUMN summary_json TEXT NULL AFTER history_added,
  ADD KEY idx_source_type (source_type, snapshot_date);

-- Order-level history tables (PartPulse 2.0).
-- Safe to run repeatedly: every statement uses CREATE TABLE IF NOT EXISTS.

CREATE TABLE IF NOT EXISTS order_snapshots (
  import_id BIGINT UNSIGNED NOT NULL,
  order_id INT UNSIGNED NOT NULL,
  submitted_at DATETIME NULL,
  updated_at DATETIME NULL,
  status VARCHAR(40) NOT NULL,
  priority VARCHAR(20) NOT NULL DEFAULT 'Normal',
  building_code VARCHAR(30) NOT NULL DEFAULT '',
  cost_center_id INT UNSIGNED NULL,
  category VARCHAR(120) NOT NULL DEFAULT '',
  item_description VARCHAR(500) NOT NULL DEFAULT '',
  part_number VARCHAR(100) NOT NULL DEFAULT '',
  quantity INT NULL,
  unit_price DECIMAL(12,2) NULL,
  total_price DECIMAL(14,2) NULL,
  supplier_id INT UNSIGNED NULL,
  supplier_name VARCHAR(150) NOT NULL DEFAULT '',
  requester_id INT UNSIGNED NULL,
  requester_name VARCHAR(150) NOT NULL DEFAULT '',
  assigned_user_id INT UNSIGNED NULL,
  date_needed DATE NULL,
  expected_delivery_date DATE NULL,
  delivery_confirmed_at DATETIME NULL,
  approval_status VARCHAR(30) NOT NULL DEFAULT '',
  po_number VARCHAR(50) NOT NULL DEFAULT '',
  row_hash CHAR(40) NOT NULL,
  PRIMARY KEY (import_id, order_id),
  KEY idx_snap_order (order_id, import_id),
  KEY idx_snap_status (import_id, status),
  CONSTRAINT fk_snap_import FOREIGN KEY (import_id) REFERENCES imports(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS order_history_log (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  source VARCHAR(12) NOT NULL,
  source_id BIGINT UNSIGNED NOT NULL,
  order_id INT UNSIGNED NOT NULL,
  field_name VARCHAR(100) NOT NULL,
  old_value VARCHAR(255) NULL,
  new_value VARCHAR(255) NULL,
  changed_by VARCHAR(150) NOT NULL DEFAULT '',
  changed_at DATETIME NULL,
  date_only TINYINT(1) NOT NULL DEFAULT 0,
  first_import_id BIGINT UNSIGNED NULL,
  UNIQUE KEY uq_history_source (source, source_id),
  KEY idx_history_time (changed_at),
  KEY idx_history_order (order_id, changed_at),
  KEY idx_history_field (field_name, changed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS order_assignments (
  source_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  order_id INT UNSIGNED NOT NULL,
  from_user_id INT UNSIGNED NULL,
  to_user_id INT UNSIGNED NULL,
  by_user_id INT UNSIGNED NULL,
  assignment_type VARCHAR(20) NOT NULL,
  created_at DATETIME NULL,
  first_import_id BIGINT UNSIGNED NULL,
  KEY idx_assign_time (created_at),
  KEY idx_assign_order (order_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Snapshot-to-snapshot classification, written once per import.
CREATE TABLE IF NOT EXISTS order_events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  import_id BIGINT UNSIGNED NOT NULL,
  prev_import_id BIGINT UNSIGNED NULL,
  order_id INT UNSIGNED NOT NULL,
  event_type ENUM('new','completed','cancelled','reopened','updated','removed') NOT NULL,
  old_status VARCHAR(40) NULL,
  new_status VARCHAR(40) NULL,
  changed_fields VARCHAR(500) NOT NULL DEFAULT '',
  KEY idx_events_import (import_id, event_type),
  KEY idx_events_order (order_id),
  CONSTRAINT fk_events_import FOREIGN KEY (import_id) REFERENCES imports(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS order_changes (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  import_id BIGINT UNSIGNED NOT NULL,
  order_id INT UNSIGNED NOT NULL,
  field_name VARCHAR(60) NOT NULL,
  old_value VARCHAR(500) NULL,
  new_value VARCHAR(500) NULL,
  KEY idx_changes_import (import_id, field_name),
  KEY idx_changes_order (order_id),
  CONSTRAINT fk_changes_import FOREIGN KEY (import_id) REFERENCES imports(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS dim_suppliers (
  supplier_id INT UNSIGNED NOT NULL PRIMARY KEY,
  name VARCHAR(150) NOT NULL,
  country VARCHAR(2) NOT NULL DEFAULT '',
  is_eu TINYINT(1) NOT NULL DEFAULT 0,
  active TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS dim_users (
  user_id INT UNSIGNED NOT NULL PRIMARY KEY,
  display_name VARCHAR(150) NOT NULL,
  role VARCHAR(30) NOT NULL DEFAULT '',
  building_code VARCHAR(30) NOT NULL DEFAULT '',
  active TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS dim_buildings (
  building_code VARCHAR(30) NOT NULL PRIMARY KEY,
  name VARCHAR(150) NOT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS dim_cost_centers (
  cost_center_id INT UNSIGNED NOT NULL PRIMARY KEY,
  building_code VARCHAR(30) NOT NULL DEFAULT '',
  code VARCHAR(100) NOT NULL DEFAULT '',
  name VARCHAR(150) NOT NULL DEFAULT '',
  active TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
