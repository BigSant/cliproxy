CREATE TABLE `alert` (
  `id` int NOT NULL AUTO_INCREMENT,
  `last_seen_at` datetime NOT NULL,
  `resolved_at` datetime DEFAULT NULL,
  `notified_at` datetime DEFAULT NULL,
  `level` varchar(16) NOT NULL,
  `message` varchar(500) NOT NULL,
  `first_seen_at` datetime NOT NULL,
  `alert_key` varchar(190) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `alert_open` (`resolved_at`),
  KEY `alert_key` (`alert_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
