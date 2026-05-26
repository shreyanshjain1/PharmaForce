CREATE TABLE IF NOT EXISTS `approval_records` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `entity_type` enum('report','expense') NOT NULL,
  `entity_id` int(11) NOT NULL,
  `submitted_by_user_id` int(11) DEFAULT NULL,
  `manager_status` enum('pending','approved','needs_changes') NOT NULL DEFAULT 'pending',
  `manager_comment` text DEFAULT NULL,
  `manager_user_id` int(11) DEFAULT NULL,
  `manager_reviewed_at` datetime DEFAULT NULL,
  `district_status` enum('pending','approved','needs_changes') NOT NULL DEFAULT 'pending',
  `district_comment` text DEFAULT NULL,
  `district_user_id` int(11) DEFAULT NULL,
  `district_reviewed_at` datetime DEFAULT NULL,
  `final_status` enum('pending','approved','needs_changes') NOT NULL DEFAULT 'pending',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_approval_entity` (`entity_type`, `entity_id`),
  KEY `idx_approval_final_status` (`final_status`),
  KEY `idx_approval_manager_status` (`manager_status`),
  KEY `idx_approval_district_status` (`district_status`),
  KEY `idx_approval_submitted_by` (`submitted_by_user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `approval_records` (`entity_type`, `entity_id`, `submitted_by_user_id`, `final_status`, `created_at`)
SELECT 'report', `id`, `user_id`, `status`, COALESCE(`created_at`, NOW())
FROM `reports`;

INSERT IGNORE INTO `approval_records` (`entity_type`, `entity_id`, `submitted_by_user_id`, `final_status`, `created_at`)
SELECT 'expense', `id`, `user_id`, `status`, COALESCE(`created_at`, NOW())
FROM `expense_reports`;
