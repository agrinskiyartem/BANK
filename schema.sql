-- Schema for ATM project (MySQL 8)
-- NOTE: Replace password_hash/pin_hash values with freshly computed hashes in PHP using password_hash(...)
-- e.g. UPDATE users SET password_hash = :hash WHERE login = 'admin';
--      UPDATE cards SET pin_hash = :hash WHERE card_number = '4111111111111111';

CREATE DATABASE IF NOT EXISTS bank_atm
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;
USE bank_atm;

DROP TABLE IF EXISTS audit_log;
DROP TABLE IF EXISTS withdrawals;
DROP TABLE IF EXISTS accounts;
DROP TABLE IF EXISTS cards;
DROP TABLE IF EXISTS clients;
DROP TABLE IF EXISTS users;
DROP TABLE IF EXISTS atm_status;
DROP TABLE IF EXISTS atms;
DROP TABLE IF EXISTS banks;

CREATE TABLE banks (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(255) NOT NULL,
  bic VARCHAR(20) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,
  UNIQUE KEY ux_banks_bic (bic),
  KEY idx_banks_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE atms (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  bank_owner_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(255) NOT NULL,
  address VARCHAR(255) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,
  KEY idx_atms_bank_owner_id (bank_owner_id),
  CONSTRAINT fk_atms_bank_owner
    FOREIGN KEY (bank_owner_id) REFERENCES banks(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE atm_status (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  atm_id BIGINT UNSIGNED NOT NULL,
  status ENUM('online','maintenance','offline') NOT NULL DEFAULT 'online',
  note VARCHAR(255) NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY ux_atm_status_atm_id (atm_id),
  KEY idx_atm_status_status (status),
  CONSTRAINT fk_atm_status_atm
    FOREIGN KEY (atm_id) REFERENCES atms(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE users (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  login VARCHAR(255) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('admin','client') NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  UNIQUE KEY ux_users_login (login),
  KEY idx_users_role (role)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE clients (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  full_name VARCHAR(255) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,
  UNIQUE KEY ux_clients_user_id (user_id),
  CONSTRAINT fk_clients_user
    FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE cards (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  card_number VARCHAR(32) NOT NULL,
  pin_hash VARCHAR(255) NOT NULL,
  bank_issuer_id BIGINT UNSIGNED NOT NULL,
  client_id BIGINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,
  expires_at DATE NULL,
  is_blocked TINYINT(1) NOT NULL DEFAULT 0,
  UNIQUE KEY ux_cards_card_number (card_number),
  KEY idx_cards_bank_issuer_id (bank_issuer_id),
  KEY idx_cards_client_id (client_id),
  CONSTRAINT fk_cards_bank_issuer
    FOREIGN KEY (bank_issuer_id) REFERENCES banks(id),
  CONSTRAINT fk_cards_client
    FOREIGN KEY (client_id) REFERENCES clients(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE accounts (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  card_id BIGINT UNSIGNED NOT NULL,
  balance DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  currency CHAR(3) NOT NULL DEFAULT 'RUB',
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY ux_accounts_card_id (card_id),
  CONSTRAINT fk_accounts_card
    FOREIGN KEY (card_id) REFERENCES cards(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE withdrawals (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  card_id BIGINT UNSIGNED NOT NULL,
  atm_id BIGINT UNSIGNED NOT NULL,
  bank_issuer_id BIGINT UNSIGNED NOT NULL,
  bank_owner_id BIGINT UNSIGNED NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  commission_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  total_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  mode_safe TINYINT(1) NOT NULL DEFAULT 1,
  status ENUM('success','failed') NOT NULL DEFAULT 'success',
  error_reason VARCHAR(255) NULL,
  KEY idx_withdrawals_card_id (card_id),
  KEY idx_withdrawals_atm_id (atm_id),
  KEY idx_withdrawals_bank_issuer_id (bank_issuer_id),
  KEY idx_withdrawals_bank_owner_id (bank_owner_id),
  KEY idx_withdrawals_created_at (created_at),
  CONSTRAINT fk_withdrawals_card
    FOREIGN KEY (card_id) REFERENCES cards(id),
  CONSTRAINT fk_withdrawals_atm
    FOREIGN KEY (atm_id) REFERENCES atms(id),
  CONSTRAINT fk_withdrawals_bank_issuer
    FOREIGN KEY (bank_issuer_id) REFERENCES banks(id),
  CONSTRAINT fk_withdrawals_bank_owner
    FOREIGN KEY (bank_owner_id) REFERENCES banks(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE audit_log (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  actor_user_id BIGINT UNSIGNED NULL,
  action VARCHAR(100) NOT NULL,
  entity VARCHAR(100) NOT NULL,
  entity_id BIGINT UNSIGNED NOT NULL,
  details VARCHAR(500) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  ip_address VARCHAR(45) NULL,
  user_agent VARCHAR(255) NULL,
  KEY idx_audit_log_actor_user_id (actor_user_id),
  KEY idx_audit_log_created_at (created_at),
  CONSTRAINT fk_audit_log_actor_user
    FOREIGN KEY (actor_user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DELIMITER $$
CREATE TRIGGER trg_withdrawals_commission
BEFORE INSERT ON withdrawals
FOR EACH ROW
BEGIN
  DECLARE fee_rate DECIMAL(5,4);
  SET fee_rate = IF(NEW.bank_issuer_id = NEW.bank_owner_id, 0.0000, 0.0120);
  SET NEW.commission_amount = ROUND(NEW.amount * fee_rate, 2);
  SET NEW.total_amount = ROUND(NEW.amount + NEW.commission_amount, 2);
END$$
DELIMITER ;

INSERT INTO banks (name, bic, created_at, updated_at) VALUES
  ('North Bank', 'NBANKRU00', '2024-01-10 09:00:00', '2024-03-01 10:00:00'),
  ('Central Finance', 'CFINRU00', '2024-02-15 11:00:00', '2024-03-05 12:00:00'),
  ('City Credit', 'CCRDTRU0', '2024-03-20 08:30:00', '2024-03-22 09:15:00');

INSERT INTO atms (bank_owner_id, name, address, is_active, created_at, updated_at) VALUES
  (1, 'ATM-NB-01', 'Moscow, Tverskaya 1', 1, '2024-04-01 09:00:00', '2024-04-10 09:00:00'),
  (1, 'ATM-NB-02', 'Moscow, Arbat 12', 1, '2024-04-02 10:00:00', '2024-04-11 09:00:00'),
  (2, 'ATM-CF-01', 'Saint Petersburg, Nevsky 5', 1, '2024-04-03 10:30:00', '2024-04-12 09:00:00'),
  (3, 'ATM-CC-01', 'Kazan, Kremlin 3', 1, '2024-04-04 11:00:00', '2024-04-13 09:00:00');

INSERT INTO atm_status (atm_id, status, note, updated_at) VALUES
  (1, 'online', 'Готов к работе', '2024-04-10 09:00:00'),
  (2, 'online', 'Загружен наличными', '2024-04-11 09:00:00'),
  (3, 'maintenance', 'Плановое обслуживание', '2024-04-12 09:00:00'),
  (4, 'offline', 'Нет связи', '2024-04-13 09:00:00');

INSERT INTO users (login, password_hash, role, created_at, updated_at, is_active) VALUES
  ('admin', '$2y$12$/zvXBwMMjuZwD5Qj0mkev.p5cvQWMD9x/C4FUyMpYcgjNbD7qvXqm', 'admin', '2024-03-01 09:00:00', '2024-03-01 09:00:00', 1),
  ('client1', '$2y$12$MXohwtluHo8A4oLXtRjiuuD5HroRZqGrBOjvqidYcuZSWQhC8S0Su', 'client', '2024-03-02 09:00:00', '2024-03-02 09:00:00', 1),
  ('client2', '$2y$12$Pqytd782SUXSCdTauNeLdueEKkWyfuZrpVY54imdVGxMDa9yN2qRK', 'client', '2024-03-03 09:00:00', '2024-03-03 09:00:00', 1);

INSERT INTO clients (user_id, full_name, created_at, updated_at) VALUES
  (2, 'Ivan Petrov', '2024-03-02 10:00:00', '2024-03-02 10:00:00'),
  (3, 'Olga Smirnova', '2024-03-03 10:00:00', '2024-03-03 10:00:00');

INSERT INTO cards (card_number, pin_hash, bank_issuer_id, client_id, created_at, updated_at, expires_at, is_blocked) VALUES
  ('4111111111111111', '$2y$12$YgtbmPy3L2NG./4024zvJ.ZRJmIS5qV.xchY/rZbXiXOtgGyEGdYO', 1, 1, '2024-03-05 09:00:00', '2024-03-05 09:00:00', '2027-03-01', 0),
  ('5500000000000004', '$2y$12$2Fy1FMS3G/he3hfYRoJQEeN4dpVDfCUmPjqpCxKl2EJdfN5ABsYo6', 2, 2, '2024-03-06 09:00:00', '2024-03-06 09:00:00', '2027-03-01', 0);

INSERT INTO accounts (card_id, balance, currency, updated_at) VALUES
  (1, 50000.00, 'RUB', '2024-04-15 09:00:00'),
  (2, 30000.00, 'RUB', '2024-04-15 09:00:00');

INSERT INTO withdrawals (
  card_id,
  atm_id,
  bank_issuer_id,
  bank_owner_id,
  amount,
  commission_amount,
  total_amount,
  created_at,
  mode_safe,
  status,
  error_reason
) VALUES
  (1, 1, 1, 1, 2000.00, 0.00, 0.00, '2024-05-01 10:00:00', 1, 'success', NULL),
  (1, 3, 1, 2, 1500.00, 0.00, 0.00, '2024-05-02 11:15:00', 1, 'success', NULL),
  (1, 4, 1, 3, 1000.00, 0.00, 0.00, '2024-05-10 12:30:00', 0, 'success', NULL),
  (2, 2, 2, 1, 2500.00, 0.00, 0.00, '2024-06-01 09:45:00', 1, 'success', NULL),
  (2, 3, 2, 2, 3000.00, 0.00, 0.00, '2024-06-05 14:20:00', 1, 'success', NULL);

INSERT INTO audit_log (actor_user_id, action, entity, entity_id, details, created_at, ip_address, user_agent) VALUES
  (1, 'seed', 'banks', 1, 'Initial seed for banks/atms/users', '2024-04-01 08:00:00', '127.0.0.1', 'seed-script');
