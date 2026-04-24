-- ============================================================
--  SAVANT MOTORS — Job Costing & Invoicing Schema
--  Run against: savant_motors_pos
-- ============================================================

-- ── job_cards (master job record) ───────────────────────────
CREATE TABLE IF NOT EXISTS `job_cards` (
  `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `job_number`      VARCHAR(20)  NOT NULL UNIQUE,        -- e.g. JC-00001
  `customer_id`     INT UNSIGNED NOT NULL,
  `vehicle_reg`     VARCHAR(20)  NOT NULL,
  `vehicle_make`    VARCHAR(60)  DEFAULT NULL,
  `vehicle_model`   VARCHAR(60)  DEFAULT NULL,
  `description`     TEXT         DEFAULT NULL,           -- work description
  `status`          ENUM('open','in_progress','completed','invoiced','cancelled')
                                 NOT NULL DEFAULT 'open',
  `labour_cost`     DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `parts_cost`      DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `total_amount`    DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `completion_date` DATE         DEFAULT NULL,
  `created_at`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_customer`  (`customer_id`),
  KEY `idx_status`    (`status`),
  KEY `idx_comp_date` (`completion_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ── job_labour_lines (labour cost breakdown) ─────────────────
CREATE TABLE IF NOT EXISTS `job_labour_lines` (
  `id`          INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `job_id`      INT UNSIGNED  NOT NULL,
  `description` VARCHAR(255)  NOT NULL,
  `hours`       DECIMAL(8,2)  NOT NULL DEFAULT 0.00,
  `rate`        DECIMAL(12,2) NOT NULL DEFAULT 0.00,    -- UGX per hour
  `line_total`  DECIMAL(15,2) GENERATED ALWAYS AS (`hours` * `rate`) STORED,
  PRIMARY KEY (`id`),
  KEY `idx_job` (`job_id`),
  CONSTRAINT `fk_labour_job` FOREIGN KEY (`job_id`) REFERENCES `job_cards`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ── job_parts_lines (parts / materials breakdown) ────────────
CREATE TABLE IF NOT EXISTS `job_parts_lines` (
  `id`          INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `job_id`      INT UNSIGNED  NOT NULL,
  `description` VARCHAR(255)  NOT NULL,
  `quantity`    DECIMAL(10,2) NOT NULL DEFAULT 1.00,
  `unit_price`  DECIMAL(12,2) NOT NULL DEFAULT 0.00,   -- UGX per unit
  `line_total`  DECIMAL(15,2) GENERATED ALWAYS AS (`quantity` * `unit_price`) STORED,
  PRIMARY KEY (`id`),
  KEY `idx_job` (`job_id`),
  CONSTRAINT `fk_parts_job` FOREIGN KEY (`job_id`) REFERENCES `job_cards`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ── invoices (optional — links invoice record to job_card) ───
--  Only needed if your existing `invoices` table lacks job_card_id.
--  Skip this ALTER if the column already exists.
ALTER TABLE `invoices`
  ADD COLUMN IF NOT EXISTS `job_card_id` INT UNSIGNED DEFAULT NULL AFTER `customer_id`,
  ADD KEY IF NOT EXISTS `idx_inv_job` (`job_card_id`);


-- ── Sample data (optional — remove in production) ────────────
/*
INSERT INTO job_cards (job_number, customer_id, vehicle_reg, vehicle_make, vehicle_model, description, status, labour_cost, parts_cost, total_amount, completion_date)
VALUES
  ('JC-00001', 1, 'UAA 123B', 'Toyota', 'Corolla', 'Full service + brake pads replacement', 'invoiced',  150000, 85000, 235000, CURDATE() - INTERVAL 5 DAY),
  ('JC-00002', 2, 'UAB 456C', 'Nissan', 'X-Trail',  'Engine oil change + air filter',         'completed',  80000, 45000, 125000, CURDATE() - INTERVAL 2 DAY),
  ('JC-00003', 3, 'UAC 789D', 'Toyota', 'Land Cruiser','Gearbox overhaul',                   'in_progress',     0,     0,      0, NULL);
*/
