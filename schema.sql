-- SadarBudget 1.0 Final
-- Instalasi awal untuk XAMPP, MySQL, atau MariaDB.
-- Gunakan pada database baru/kosong.

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET time_zone = '+00:00';

CREATE DATABASE IF NOT EXISTS `keuangan_pribadi`
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

ALTER DATABASE `keuangan_pribadi`
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE `keuangan_pribadi`;

CREATE TABLE IF NOT EXISTS `users` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(100) COLLATE utf8mb4_unicode_ci NOT NULL,
    `email` VARCHAR(190) COLLATE utf8mb4_unicode_ci NOT NULL,
    `password` VARCHAR(255) COLLATE utf8mb4_unicode_ci NOT NULL,
    `profile_photo` VARCHAR(255) COLLATE utf8mb4_unicode_ci NULL,
    `password_changed_at` DATETIME NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_users_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `categories` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT UNSIGNED NOT NULL,
    `name` VARCHAR(100) COLLATE utf8mb4_unicode_ci NOT NULL,
    `type` ENUM('income','expense') COLLATE utf8mb4_unicode_ci NOT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_categories_user_type_name` (`user_id`,`type`,`name`),
    KEY `idx_categories_user_type_active` (`user_id`,`type`,`is_active`),
    CONSTRAINT `fk_categories_user`
      FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `transactions` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT UNSIGNED NOT NULL,
    `category_id` INT UNSIGNED NULL,
    `category_name_snapshot` VARCHAR(100) COLLATE utf8mb4_unicode_ci NOT NULL,
    `type` ENUM('income','expense') COLLATE utf8mb4_unicode_ci NOT NULL,
    `amount` DECIMAL(15,2) NOT NULL,
    `description` VARCHAR(255) COLLATE utf8mb4_unicode_ci NULL,
    `transaction_date` DATE NOT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_transactions_user_date` (`user_id`,`transaction_date`,`created_at`),
    KEY `idx_transactions_user_type_date` (`user_id`,`type`,`transaction_date`),
    KEY `idx_transactions_category` (`category_id`),
    CONSTRAINT `fk_transactions_user`
      FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_transactions_category`
      FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `savings_goals` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT UNSIGNED NOT NULL,
    `name` VARCHAR(100) COLLATE utf8mb4_unicode_ci NOT NULL,
    `target_amount` DECIMAL(15,2) NOT NULL,
    `description` VARCHAR(255) COLLATE utf8mb4_unicode_ci NULL,
    `target_date` DATE NULL,
    `status` ENUM('active','archived','deleted') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
    `deleted_at` DATETIME NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_savings_goals_user_status` (`user_id`,`status`),
    KEY `idx_savings_goals_target_date` (`user_id`,`target_date`),
    CONSTRAINT `fk_savings_goals_user`
      FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `savings_entries` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT UNSIGNED NOT NULL,
    `savings_goal_id` BIGINT UNSIGNED NOT NULL,
    `type` ENUM('deposit','withdrawal','spend') COLLATE utf8mb4_unicode_ci NOT NULL,
    `amount` DECIMAL(15,2) NOT NULL,
    `category_id` INT UNSIGNED NULL,
    `goal_name_snapshot` VARCHAR(100) COLLATE utf8mb4_unicode_ci NOT NULL,
    `category_name_snapshot` VARCHAR(100) COLLATE utf8mb4_unicode_ci NULL,
    `note` VARCHAR(255) COLLATE utf8mb4_unicode_ci NULL,
    `entry_date` DATE NOT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_savings_entries_user_date` (`user_id`,`entry_date`,`created_at`),
    KEY `idx_savings_entries_goal_date` (`savings_goal_id`,`entry_date`),
    KEY `idx_savings_entries_category` (`category_id`),
    CONSTRAINT `fk_savings_entries_user`
      FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_savings_entries_goal`
      FOREIGN KEY (`savings_goal_id`) REFERENCES `savings_goals` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_savings_entries_category`
      FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DELIMITER $$

DROP TRIGGER IF EXISTS `sb_savings_entries_fill_goal_name_before_insert`$$
CREATE TRIGGER `sb_savings_entries_fill_goal_name_before_insert`
BEFORE INSERT ON `savings_entries`
FOR EACH ROW
BEGIN
    DECLARE v_goal_name VARCHAR(100) DEFAULT NULL;

    IF NEW.goal_name_snapshot IS NULL OR TRIM(NEW.goal_name_snapshot) = '' THEN
        SELECT NULLIF(TRIM(`name`), '')
          INTO v_goal_name
          FROM `savings_goals`
         WHERE `id` = NEW.savings_goal_id
           AND `user_id` = NEW.user_id
         LIMIT 1;

        SET NEW.goal_name_snapshot = COALESCE(
            v_goal_name,
            CONCAT('Tujuan tabungan #', NEW.savings_goal_id)
        );
    END IF;
END$$

DROP TRIGGER IF EXISTS `sb_savings_entries_fill_goal_name_before_update`$$
CREATE TRIGGER `sb_savings_entries_fill_goal_name_before_update`
BEFORE UPDATE ON `savings_entries`
FOR EACH ROW
BEGIN
    DECLARE v_goal_name VARCHAR(100) DEFAULT NULL;

    IF NEW.goal_name_snapshot IS NULL OR TRIM(NEW.goal_name_snapshot) = '' THEN
        SELECT NULLIF(TRIM(`name`), '')
          INTO v_goal_name
          FROM `savings_goals`
         WHERE `id` = NEW.savings_goal_id
           AND `user_id` = NEW.user_id
         LIMIT 1;

        SET NEW.goal_name_snapshot = COALESCE(
            v_goal_name,
            CONCAT('Tujuan tabungan #', NEW.savings_goal_id)
        );
    END IF;
END$$

DELIMITER ;

SELECT 'SadarBudget 1.0 Final siap digunakan.' AS `status`;
