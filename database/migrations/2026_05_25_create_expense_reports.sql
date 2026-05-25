CREATE TABLE IF NOT EXISTS `expense_reports` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `report_month` date NOT NULL,
  `title` varchar(180) NOT NULL DEFAULT 'Liquidation of Expenses',
  `status` enum('pending','approved','needs_changes') NOT NULL DEFAULT 'pending',
  `manager_comment` text DEFAULT NULL,
  `total_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  KEY `report_month` (`report_month`),
  KEY `status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `expense_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `expense_report_id` int(11) NOT NULL,
  `expense_date` date DEFAULT NULL,
  `particulars` varchar(255) NOT NULL,
  `gasoline` decimal(12,2) NOT NULL DEFAULT 0.00,
  `toll` decimal(12,2) NOT NULL DEFAULT 0.00,
  `parking` decimal(12,2) NOT NULL DEFAULT 0.00,
  `transportation` decimal(12,2) NOT NULL DEFAULT 0.00,
  `representation` decimal(12,2) NOT NULL DEFAULT 0.00,
  `accommodation` decimal(12,2) NOT NULL DEFAULT 0.00,
  `others` decimal(12,2) NOT NULL DEFAULT 0.00,
  `total` decimal(12,2) NOT NULL DEFAULT 0.00,
  `remarks` text DEFAULT NULL,
  `receipt_path` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `expense_report_id` (`expense_report_id`),
  KEY `expense_date` (`expense_date`),
  CONSTRAINT `fk_expense_items_report` FOREIGN KEY (`expense_report_id`) REFERENCES `expense_reports` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
