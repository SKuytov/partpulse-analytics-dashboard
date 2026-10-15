SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS imports (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  snapshot_date DATE NOT NULL,
  original_name VARCHAR(255) NOT NULL,
  stored_name VARCHAR(255) NOT NULL,
  file_hash CHAR(64) NOT NULL,
  file_size BIGINT UNSIGNED NOT NULL,
  uploaded_by VARCHAR(100) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_import_hash (file_hash),
  KEY idx_snapshot_date (snapshot_date, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS status_metrics (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  import_id BIGINT UNSIGNED NOT NULL,
  status_name VARCHAR(100) NOT NULL,
  order_count INT UNSIGNED NOT NULL DEFAULT 0,
  percentage DECIMAL(7,2) NOT NULL DEFAULT 0,
  CONSTRAINT fk_status_import FOREIGN KEY (import_id) REFERENCES imports(id) ON DELETE CASCADE,
  UNIQUE KEY uq_status_import (import_id, status_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sla_violations (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  import_id BIGINT UNSIGNED NOT NULL,
  order_no BIGINT UNSIGNED NOT NULL,
  article TEXT NOT NULL,
  status_name VARCHAR(100) NOT NULL,
  building_code VARCHAR(30) NOT NULL,
  priority VARCHAR(30) NOT NULL,
  days_in_status INT UNSIGNED NOT NULL,
  threshold_days INT UNSIGNED NOT NULL,
  requester VARCHAR(150) NOT NULL,
  CONSTRAINT fk_sla_import FOREIGN KEY (import_id) REFERENCES imports(id) ON DELETE CASCADE,
  KEY idx_sla_import (import_id),
  KEY idx_sla_priority (import_id, priority),
  KEY idx_sla_building (import_id, building_code),
  KEY idx_sla_order (order_no)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS building_costs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  import_id BIGINT UNSIGNED NOT NULL,
  building_code VARCHAR(30) NOT NULL,
  building_name VARCHAR(150) NOT NULL,
  costs_eur DECIMAL(15,2) NOT NULL DEFAULT 0,
  order_count INT UNSIGNED NOT NULL DEFAULT 0,
  percentage DECIMAL(7,2) NOT NULL DEFAULT 0,
  CONSTRAINT fk_building_import FOREIGN KEY (import_id) REFERENCES imports(id) ON DELETE CASCADE,
  UNIQUE KEY uq_building_import (import_id, building_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
