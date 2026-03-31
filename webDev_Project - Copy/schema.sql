CREATE DATABASE IF NOT EXISTS mtg
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE mtg;

CREATE TABLE IF NOT EXISTS users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(32) NOT NULL,
  email VARCHAR(255) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_users_username (username),
  UNIQUE KEY uniq_users_email (email)
) ENGINE=InnoDB;

-- Global catalog of cards (minimum fields to start)
CREATE TABLE IF NOT EXISTS cards (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(255) NOT NULL,
  type_line VARCHAR(255) NULL,
  oracle_text TEXT NULL,
  set_code VARCHAR(16) NULL,
  collector_number VARCHAR(32) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_cards_printing (set_code, collector_number),
  KEY idx_cards_name (name)
) ENGINE=InnoDB;

-- User inventory rows (one row per user per card printing, with your required collection fields)
CREATE TABLE IF NOT EXISTS user_collection (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  card_id INT NOT NULL,

  -- required project fields for a user's copy/copies
  card_condition ENUM('NM','LP','MP','HP','DMG') NOT NULL DEFAULT 'NM',
  card_language VARCHAR(32) NOT NULL DEFAULT 'English',
  qty INT NOT NULL DEFAULT 1,
  is_signed TINYINT(1) NOT NULL DEFAULT 0,
  is_altered TINYINT(1) NOT NULL DEFAULT 0,

  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  CONSTRAINT fk_uc_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_uc_card FOREIGN KEY (card_id) REFERENCES cards(id) ON DELETE CASCADE,

  CONSTRAINT chk_uc_qty CHECK (qty >= 0),

  -- allow multiple rows for the same card if condition/language/signed/altered differ
  UNIQUE KEY uniq_uc_user_card_variant (user_id, card_id, card_condition, card_language, is_signed, is_altered),

  KEY idx_uc_user (user_id),
  KEY idx_uc_card (card_id)
) ENGINE=InnoDB;