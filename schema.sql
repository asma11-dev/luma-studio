CREATE DATABASE IF NOT EXISTS luma_studio CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE luma_studio;

CREATE TABLE users (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  email VARCHAR(190) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('customer','stylist','owner') NOT NULL DEFAULT 'customer',
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE services (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  description TEXT NOT NULL,
  duration_minutes SMALLINT UNSIGNED NOT NULL,
  price_cents INT UNSIGNED NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  display_order SMALLINT NOT NULL DEFAULT 0
) ENGINE=InnoDB;

CREATE TABLE stylists (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NULL UNIQUE,
  name VARCHAR(120) NOT NULL,
  title VARCHAR(120) NOT NULL DEFAULT 'Stylist',
  bio TEXT NOT NULL,
  photo_url VARCHAR(500) NULL,
  accent VARCHAR(24) NOT NULL DEFAULT '#b9816c',
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  CONSTRAINT fk_stylist_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE stylist_services (
  stylist_id BIGINT UNSIGNED NOT NULL,
  service_id BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (stylist_id, service_id),
  CONSTRAINT fk_ss_stylist FOREIGN KEY (stylist_id) REFERENCES stylists(id) ON DELETE CASCADE,
  CONSTRAINT fk_ss_service FOREIGN KEY (service_id) REFERENCES services(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE working_hours (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  stylist_id BIGINT UNSIGNED NOT NULL,
  weekday TINYINT UNSIGNED NOT NULL,
  opens_at TIME NULL,
  closes_at TIME NULL,
  is_closed TINYINT(1) NOT NULL DEFAULT 0,
  UNIQUE KEY uq_stylist_weekday (stylist_id, weekday),
  CONSTRAINT fk_hours_stylist FOREIGN KEY (stylist_id) REFERENCES stylists(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE time_off (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  stylist_id BIGINT UNSIGNED NULL,
  starts_at DATETIME NOT NULL,
  ends_at DATETIME NOT NULL,
  reason VARCHAR(190) NOT NULL DEFAULT 'Time off',
  INDEX ix_timeoff_range (stylist_id, starts_at, ends_at),
  CONSTRAINT fk_timeoff_stylist FOREIGN KEY (stylist_id) REFERENCES stylists(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE blocked_times (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  stylist_id BIGINT UNSIGNED NULL,
  starts_at DATETIME NOT NULL,
  ends_at DATETIME NOT NULL,
  reason VARCHAR(190) NOT NULL,
  created_by BIGINT UNSIGNED NOT NULL,
  INDEX ix_block_range (stylist_id, starts_at, ends_at),
  CONSTRAINT fk_block_stylist FOREIGN KEY (stylist_id) REFERENCES stylists(id) ON DELETE CASCADE,
  CONSTRAINT fk_block_creator FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE appointments (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  customer_id BIGINT UNSIGNED NOT NULL,
  stylist_id BIGINT UNSIGNED NOT NULL,
  service_id BIGINT UNSIGNED NOT NULL,
  starts_at DATETIME NOT NULL,
  ends_at DATETIME NOT NULL,
  status ENUM('confirmed','cancelled','completed','no_show') NOT NULL DEFAULT 'confirmed',
  customer_note VARCHAR(1000) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  cancelled_at DATETIME NULL,
  reminder_sent_at DATETIME NULL,
  INDEX ix_appointment_overlap (stylist_id, status, starts_at, ends_at),
  INDEX ix_customer_upcoming (customer_id, status, starts_at),
  INDEX ix_appointment_date (starts_at, status),
  CONSTRAINT fk_appt_customer FOREIGN KEY (customer_id) REFERENCES users(id),
  CONSTRAINT fk_appt_stylist FOREIGN KEY (stylist_id) REFERENCES stylists(id),
  CONSTRAINT fk_appt_service FOREIGN KEY (service_id) REFERENCES services(id)
) ENGINE=InnoDB;

CREATE TABLE customer_notes (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  customer_id BIGINT UNSIGNED NOT NULL,
  author_id BIGINT UNSIGNED NOT NULL,
  note TEXT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX ix_customer_notes (customer_id, created_at),
  CONSTRAINT fk_note_customer FOREIGN KEY (customer_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_note_author FOREIGN KEY (author_id) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE password_resets (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  token_hash CHAR(64) NOT NULL UNIQUE,
  expires_at DATETIME NOT NULL,
  used_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX ix_reset_expiry (expires_at),
  CONSTRAINT fk_reset_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

INSERT INTO services (name, description, duration_minutes, price_cents, display_order) VALUES
('Signature cut', 'A considered cut, wash and finish, shaped around your hair and your day.', 60, 6800, 1),
('Gloss & blow dry', 'A soft, luminous finish with a custom gloss and a polished blow dry.', 75, 9200, 2),
('Full colour', 'Personalised colour from consultation through a beautiful, wearable finish.', 150, 18500, 3),
('Balayage', 'Dimensional, hand-painted colour designed to grow out naturally.', 210, 26500, 4),
('Bridal styling', 'An unhurried styling appointment for celebrations and special occasions.', 90, 14500, 5);

INSERT INTO stylists (name, title, bio, accent) VALUES
('Amara James', 'Creative Director', 'Known for thoughtful shapes, effortless movement and hair that feels like you.', '#c48470'),
('Maya Laurent', 'Colour Specialist', 'Soft dimension and lived-in colour, always tailored to your natural tone.', '#8f9c80'),
('Nina Park', 'Senior Stylist', 'Modern cuts and a calm, considered approach to every appointment.', '#b291a1'),
('Sofia Reyes', 'Stylist', 'Beautiful, practical styling with a little extra attention to the details.', '#d2a462');

INSERT INTO stylist_services (stylist_id, service_id)
SELECT st.id, sv.id FROM stylists st CROSS JOIN services sv;

-- Studio hours: Monday closed, Tuesday-Saturday 09:00-18:00, Sunday 10:00-16:00.
INSERT INTO working_hours (stylist_id, weekday, opens_at, closes_at, is_closed)
SELECT id, 1, NULL, NULL, 1 FROM stylists;
INSERT INTO working_hours (stylist_id, weekday, opens_at, closes_at, is_closed)
SELECT id, 2, '09:00', '18:00', 0 FROM stylists;
INSERT INTO working_hours (stylist_id, weekday, opens_at, closes_at, is_closed)
SELECT id, 3, '09:00', '18:00', 0 FROM stylists;
INSERT INTO working_hours (stylist_id, weekday, opens_at, closes_at, is_closed)
SELECT id, 4, '09:00', '18:00', 0 FROM stylists;
INSERT INTO working_hours (stylist_id, weekday, opens_at, closes_at, is_closed)
SELECT id, 5, '09:00', '18:00', 0 FROM stylists;
INSERT INTO working_hours (stylist_id, weekday, opens_at, closes_at, is_closed)
SELECT id, 6, '09:00', '18:00', 0 FROM stylists;
INSERT INTO working_hours (stylist_id, weekday, opens_at, closes_at, is_closed)
SELECT id, 7, '10:00', '16:00', 0 FROM stylists;
