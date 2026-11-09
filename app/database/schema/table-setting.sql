CREATE TABLE `setting` (
  `name` varchar(64) NOT NULL,
  `value` json NOT NULL,
  PRIMARY KEY (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
