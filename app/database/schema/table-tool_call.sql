CREATE TABLE `tool_call` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `is_error` tinyint DEFAULT NULL,
  `answered` tinyint NOT NULL,
  `output` longtext NOT NULL,
  `answered_at` datetime DEFAULT NULL,
  `call_id` varchar(128) NOT NULL,
  `name` varchar(128) NOT NULL,
  `input` longtext NOT NULL,
  `exchange_id` bigint NOT NULL,
  PRIMARY KEY (`id`),
  KEY `tool_call_id` (`call_id`),
  KEY `tool_name` (`name`),
  KEY `IDX_17CC6F7568AFD1A0` (`exchange_id`),
  CONSTRAINT `FK_17CC6F7568AFD1A0` FOREIGN KEY (`exchange_id`) REFERENCES `exchange` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
