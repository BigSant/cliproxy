CREATE TABLE `quota_snapshot` (
  `id` int NOT NULL AUTO_INCREMENT,
  `windows` json NOT NULL,
  `auth_index` varchar(64) NOT NULL,
  `account` varchar(190) NOT NULL,
  `provider` varchar(32) NOT NULL,
  `fetched_at` datetime NOT NULL,
  `source` varchar(16) NOT NULL,
  `plan` varchar(64) DEFAULT NULL,
  `banked_resets` int DEFAULT NULL,
  `error` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `quota_account` (`auth_index`,`fetched_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
