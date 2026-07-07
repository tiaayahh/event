
-- Full schema for event_planner_db
-- Usage:
--   mysql -u root -p < schema.sql

CREATE DATABASE IF NOT EXISTS event_planner_db
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE event_planner_db;

CREATE TABLE IF NOT EXISTS users (
    user_id INT AUTO_INCREMENT PRIMARY KEY,
    full_name VARCHAR(150) NOT NULL,
    email VARCHAR(190) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('planner', 'vendor', 'attendee') NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS vendors (
    vendor_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    business_name VARCHAR(190) NOT NULL,
    service_type VARCHAR(120) NOT NULL,
    vendor_type ENUM('service_provider','market_operator') NOT NULL DEFAULT 'service_provider',
    description TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_vendors_user (user_id),
    CONSTRAINT fk_vendors_user FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS attendees (
    attendee_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_attendees_user (user_id),
    CONSTRAINT fk_attendees_user FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS events (
    event_id INT AUTO_INCREMENT PRIMARY KEY,
    planner_id INT NOT NULL,
    title VARCHAR(190) NOT NULL,
    category VARCHAR(64) DEFAULT NULL,
    event_date DATE NOT NULL,
    venue VARCHAR(190) DEFAULT NULL,
    city VARCHAR(120) DEFAULT NULL,
    event_type ENUM('in_person','online') NOT NULL DEFAULT 'in_person',
    image_url VARCHAR(500) DEFAULT NULL,
    budget_total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    budget_committed DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    ticket_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    tickets_available INT NOT NULL DEFAULT 200,
    ticket_revenue DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    attendee_contribution_target DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    vendor_contribution_target DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    vendor_fee_amount DECIMAL(10,2) NOT NULL DEFAULT 100.00,
    archived_at DATETIME NULL DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_events_planner_date (planner_id, event_date),
    INDEX idx_events_archived_at (archived_at),
    CONSTRAINT fk_events_planner FOREIGN KEY (planner_id) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS event_budget_items (
    item_id BIGINT AUTO_INCREMENT PRIMARY KEY,
    event_id INT NOT NULL,
    item_name VARCHAR(190) NOT NULL,
    planned_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    spent_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    sort_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_event_budget_items_event_sort (event_id, sort_order),
    CONSTRAINT fk_event_budget_items_event FOREIGN KEY (event_id) REFERENCES events(event_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS event_ticket_types (
    ticket_type_id BIGINT AUTO_INCREMENT PRIMARY KEY,
    event_id INT NOT NULL,
    ticket_type VARCHAR(32) NOT NULL,
    price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    description VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_event_ticket_type (event_id, ticket_type),
    CONSTRAINT fk_event_ticket_types_event FOREIGN KEY (event_id) REFERENCES events(event_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS event_sponsorships (
    sponsorship_id BIGINT AUTO_INCREMENT PRIMARY KEY,
    event_id INT NOT NULL,
    sponsor_name VARCHAR(190) NOT NULL,
    contribution_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_event_sponsorships_event (event_id),
    CONSTRAINT fk_event_sponsorships_event FOREIGN KEY (event_id) REFERENCES events(event_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS event_financial_adjustments (
    adjustment_id BIGINT AUTO_INCREMENT PRIMARY KEY,
    event_id INT NOT NULL,
    entry_kind ENUM('committed_paid','cash_available') NOT NULL,
    amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    note VARCHAR(255) DEFAULT NULL,
    created_by INT DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_event_fin_adj_event_kind (event_id, entry_kind),
    CONSTRAINT fk_event_fin_adj_event FOREIGN KEY (event_id) REFERENCES events(event_id) ON DELETE CASCADE,
    CONSTRAINT fk_event_fin_adj_user FOREIGN KEY (created_by) REFERENCES users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS services (
    service_id INT AUTO_INCREMENT PRIMARY KEY,
    vendor_id INT NOT NULL,
    name VARCHAR(190) NOT NULL,
    description TEXT NULL,
    price DECIMAL(12,2) NOT NULL,
    availability TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_services_vendor_name (vendor_id, name),
    CONSTRAINT fk_services_vendor FOREIGN KEY (vendor_id) REFERENCES vendors(vendor_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS bookings (
    booking_id INT AUTO_INCREMENT PRIMARY KEY,
    event_id INT NOT NULL,
    service_id INT NOT NULL,
    status ENUM('pending', 'confirmed', 'cancelled') NOT NULL DEFAULT 'pending',
    booked_price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    platform_fee DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    booth_number VARCHAR(64) DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_booking_event_service (event_id, service_id),
    INDEX idx_bookings_status_created (status, created_at),
    CONSTRAINT fk_bookings_event FOREIGN KEY (event_id) REFERENCES events(event_id) ON DELETE CASCADE,
    CONSTRAINT fk_bookings_service FOREIGN KEY (service_id) REFERENCES services(service_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS stall_rentals (
    rental_id BIGINT AUTO_INCREMENT PRIMARY KEY,
    event_id INT NOT NULL,
    vendor_user_id INT NOT NULL,
    created_by_planner INT DEFAULT NULL,
    stall_label VARCHAR(80) DEFAULT NULL,
    amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    checkout_request_id VARCHAR(120) DEFAULT NULL,
    merchant_request_id VARCHAR(120) DEFAULT NULL,
    phone_number VARCHAR(20) DEFAULT NULL,
    mpesa_code VARCHAR(64) DEFAULT NULL,
    status ENUM('requested', 'paid', 'failed', 'cancelled') NOT NULL DEFAULT 'requested',
    payment_status ENUM('pending', 'paid', 'failed', 'cancelled') NOT NULL DEFAULT 'pending',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_stall_rentals_event_vendor (event_id, vendor_user_id),
    UNIQUE KEY uq_stall_rentals_checkout (checkout_request_id),
    INDEX idx_stall_rentals_event_status (event_id, payment_status),
    INDEX idx_stall_rentals_vendor (vendor_user_id),
    CONSTRAINT fk_stall_rentals_event FOREIGN KEY (event_id) REFERENCES events(event_id) ON DELETE CASCADE,
    CONSTRAINT fk_stall_rentals_vendor_user FOREIGN KEY (vendor_user_id) REFERENCES users(user_id) ON DELETE CASCADE,
    CONSTRAINT fk_stall_rentals_planner_user FOREIGN KEY (created_by_planner) REFERENCES users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS transactions (
    transaction_id INT AUTO_INCREMENT PRIMARY KEY,
    booking_id INT NOT NULL,
    mpesa_code VARCHAR(64) DEFAULT NULL,
    amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    status ENUM('pending', 'paid', 'failed') NOT NULL DEFAULT 'pending',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_transactions_booking (booking_id),
    INDEX idx_transactions_status (status),
    CONSTRAINT fk_transactions_booking FOREIGN KEY (booking_id) REFERENCES bookings(booking_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS attendances (
    attendance_id INT AUTO_INCREMENT PRIMARY KEY,
    event_id INT NOT NULL,
    attendee_id INT NOT NULL,
    status ENUM('registered', 'attended', 'cancelled') NOT NULL DEFAULT 'registered',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_attendance_event_attendee (event_id, attendee_id),
    CONSTRAINT fk_attendances_event FOREIGN KEY (event_id) REFERENCES events(event_id) ON DELETE CASCADE,
    CONSTRAINT fk_attendances_attendee FOREIGN KEY (attendee_id) REFERENCES attendees(attendee_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS messages (
    message_id INT AUTO_INCREMENT PRIMARY KEY,
    planner_user_id INT NOT NULL,
    vendor_user_id INT NOT NULL,
    sender_role ENUM('planner','vendor') NOT NULL,
    message_text TEXT NOT NULL,
    is_read TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_conversation (planner_user_id, vendor_user_id, created_at),
    CONSTRAINT fk_messages_planner FOREIGN KEY (planner_user_id) REFERENCES users(user_id) ON DELETE CASCADE,
    CONSTRAINT fk_messages_vendor_user FOREIGN KEY (vendor_user_id) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS attendee_messages (
    message_id INT AUTO_INCREMENT PRIMARY KEY,
    planner_user_id INT NOT NULL,
    attendee_user_id INT NOT NULL,
    sender_role ENUM('planner','attendee') NOT NULL,
    message_text TEXT NOT NULL,
    message_kind ENUM('direct','announcement') NOT NULL DEFAULT 'direct',
    attachment_name VARCHAR(255) DEFAULT NULL,
    attachment_path VARCHAR(500) DEFAULT NULL,
    is_read TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_attendee_conversation (planner_user_id, attendee_user_id, created_at),
    INDEX idx_attendee_recipient_unread (attendee_user_id, is_read, sender_role),
    INDEX idx_attendee_planner_recipient (planner_user_id, attendee_user_id),
    CONSTRAINT fk_attendee_messages_planner FOREIGN KEY (planner_user_id) REFERENCES users(user_id) ON DELETE CASCADE,
    CONSTRAINT fk_attendee_messages_attendee_user FOREIGN KEY (attendee_user_id) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS attendee_ticket_payments (
    payment_id BIGINT AUTO_INCREMENT PRIMARY KEY,
    event_id INT NOT NULL,
    attendee_id INT NOT NULL,
    checkout_request_id VARCHAR(120) DEFAULT NULL,
    merchant_request_id VARCHAR(120) DEFAULT NULL,
    phone_number VARCHAR(20) DEFAULT NULL,
    mpesa_code VARCHAR(64) DEFAULT NULL,
    amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    status ENUM('requested', 'paid', 'failed') NOT NULL DEFAULT 'requested',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_attendee_ticket_payment (event_id, attendee_id),
    INDEX idx_attendee_ticket_payment_event_status (event_id, status),
    INDEX idx_attendee_ticket_payment_attendee (attendee_id),
    CONSTRAINT fk_attendee_ticket_payment_event FOREIGN KEY (event_id) REFERENCES events(event_id) ON DELETE CASCADE,
    CONSTRAINT fk_attendee_ticket_payment_attendee FOREIGN KEY (attendee_id) REFERENCES attendees(attendee_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS attendee_saved_events (
    save_id BIGINT AUTO_INCREMENT PRIMARY KEY,
    attendee_id INT NOT NULL,
    event_id INT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_attendee_saved_event (attendee_id, event_id),
    INDEX idx_attendee_saved_events_attendee (attendee_id),
    INDEX idx_attendee_saved_events_event (event_id),
    CONSTRAINT fk_attendee_saved_events_attendee FOREIGN KEY (attendee_id) REFERENCES attendees(attendee_id) ON DELETE CASCADE,
    CONSTRAINT fk_attendee_saved_events_event FOREIGN KEY (event_id) REFERENCES events(event_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS vendor_notification_state (
    vendor_id INT NOT NULL PRIMARY KEY,
    last_seen_pending_bookings_at DATETIME DEFAULT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_vendor_notification_state_vendor FOREIGN KEY (vendor_id) REFERENCES vendors(vendor_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS service_ratings (
    rating_id INT AUTO_INCREMENT PRIMARY KEY,
    attendee_id INT NOT NULL,
    service_id INT NOT NULL,
    vendor_id INT NOT NULL,
    rating TINYINT NOT NULL,
    feedback VARCHAR(500) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_attendee_service_rating (attendee_id, service_id),
    INDEX idx_service_ratings_service (service_id),
    INDEX idx_service_ratings_vendor (vendor_id),
    INDEX idx_service_ratings_attendee (attendee_id),
    CONSTRAINT fk_service_ratings_attendee FOREIGN KEY (attendee_id) REFERENCES attendees(attendee_id) ON DELETE CASCADE,
    CONSTRAINT fk_service_ratings_service FOREIGN KEY (service_id) REFERENCES services(service_id) ON DELETE CASCADE,
    CONSTRAINT fk_service_ratings_vendor FOREIGN KEY (vendor_id) REFERENCES vendors(vendor_id) ON DELETE CASCADE,
    CONSTRAINT chk_service_ratings_value CHECK (rating BETWEEN 1 AND 5)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS password_resets (
    reset_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    token_hash VARCHAR(255) NOT NULL,
    expires_at DATETIME NOT NULL,
    used_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_password_resets_user (user_id),
    INDEX idx_password_resets_expires (expires_at),
    CONSTRAINT fk_password_resets_user FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS audit_logs (
    log_id BIGINT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL,
    role VARCHAR(32) NULL,
    action VARCHAR(80) NOT NULL,
    target_type VARCHAR(80) DEFAULT NULL,
    target_id VARCHAR(80) DEFAULT NULL,
    metadata_json TEXT NULL,
    ip_address VARCHAR(45) DEFAULT NULL,
    user_agent VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_audit_logs_user_created (user_id, created_at),
    INDEX idx_audit_logs_action_created (action, created_at),
    CONSTRAINT fk_audit_logs_user FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS login_attempts (
    attempt_id BIGINT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(190) NOT NULL,
    attempted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_login_attempts_email_time (email, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS user_totp_auth (
    user_id INT NOT NULL PRIMARY KEY,
    secret_key VARCHAR(64) NOT NULL,
    is_enabled TINYINT(1) NOT NULL DEFAULT 0,
    verified_at DATETIME DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_user_totp_auth_user FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

