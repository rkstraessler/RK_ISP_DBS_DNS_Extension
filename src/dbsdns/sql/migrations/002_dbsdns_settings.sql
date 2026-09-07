CREATE TABLE IF NOT EXISTS `dbsdns_settings` (
  `settings_id` tinyint(3) unsigned NOT NULL,
  `wsdl_url` varchar(2048) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `username` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `password_ciphertext` text CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `connection_status` enum('untested','success','failure') NOT NULL DEFAULT 'untested',
  `connection_source` varchar(16) NOT NULL DEFAULT '',
  `last_tested_at` datetime DEFAULT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`settings_id`)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

ALTER TABLE `dbsdns_settings`
  CONVERT TO CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

ALTER TABLE `dbsdns_settings`
  MODIFY `password_ciphertext` text CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
