-- DrJessie.life contact form submissions
-- Run in phpMyAdmin against your DrJessie database.

CREATE TABLE IF NOT EXISTS dj_contact_submissions (
  id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  ticket_ref       VARCHAR(32)     NOT NULL COMMENT 'Unique contact reference code',

  full_name        VARCHAR(200)    NOT NULL,
  email            VARCHAR(255)    NOT NULL,
  subject          VARCHAR(255)    NOT NULL,
  message          MEDIUMTEXT      NOT NULL,

  consent_contact  TINYINT(1)      NOT NULL DEFAULT 1,
  ip_address       VARCHAR(45)     DEFAULT NULL,
  user_agent       VARCHAR(600)    DEFAULT NULL,
  status           ENUM('new','reviewed','resolved','spam') NOT NULL DEFAULT 'new',
  submitted_at     TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (id),
  UNIQUE KEY uq_ticket_ref (ticket_ref),
  KEY idx_email (email),
  KEY idx_status (status),
  KEY idx_submitted_at (submitted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
