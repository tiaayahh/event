-- Strict schema verification for event_planner_db
-- Usage:
--   mariadb -u root -p < verify_schema.sql
-- or from MariaDB client:
--   SOURCE verify_schema.sql;

USE event_planner_db;

DROP PROCEDURE IF EXISTS verify_project_schema;

DELIMITER //
CREATE PROCEDURE verify_project_schema()
BEGIN
    DECLARE v_missing_tables INT DEFAULT 0;
    DECLARE v_missing_columns INT DEFAULT 0;

    CREATE TEMPORARY TABLE expected_tables (
        table_name VARCHAR(64) PRIMARY KEY
    );

    INSERT INTO expected_tables (table_name) VALUES
        ('users'),
        ('vendors'),
        ('attendees'),
        ('events'),
        ('event_budget_items'),
        ('event_ticket_types'),
        ('event_sponsorships'),
        ('event_financial_adjustments'),
        ('services'),
        ('bookings'),
        ('transactions'),
        ('attendances'),
        ('messages'),
        ('vendor_notification_state'),
        ('service_ratings'),
        ('password_resets'),
        ('audit_logs'),
        ('login_attempts'),
        ('user_totp_auth');

    CREATE TEMPORARY TABLE expected_columns (
        table_name VARCHAR(64) NOT NULL,
        column_name VARCHAR(64) NOT NULL,
        PRIMARY KEY (table_name, column_name)
    );

    INSERT INTO expected_columns (table_name, column_name) VALUES
        ('users', 'user_id'),
        ('users', 'full_name'),
        ('users', 'email'),
        ('users', 'password_hash'),
        ('users', 'role'),

        ('vendors', 'vendor_id'),
        ('vendors', 'user_id'),
        ('vendors', 'business_name'),
        ('vendors', 'service_type'),
        ('vendors', 'description'),

        ('attendees', 'attendee_id'),
        ('attendees', 'user_id'),

        ('events', 'event_id'),
        ('events', 'planner_id'),
        ('events', 'title'),
        ('events', 'event_date'),
        ('events', 'venue'),
        ('events', 'budget_total'),
        ('events', 'budget_committed'),
        ('events', 'ticket_price'),
        ('events', 'ticket_revenue'),
        ('events', 'attendee_contribution_target'),
        ('events', 'vendor_contribution_target'),
        ('events', 'vendor_fee_amount'),

        ('event_budget_items', 'item_id'),
        ('event_budget_items', 'event_id'),
        ('event_budget_items', 'item_name'),
        ('event_budget_items', 'planned_amount'),
        ('event_budget_items', 'spent_amount'),
        ('event_budget_items', 'sort_order'),
        ('event_budget_items', 'created_at'),

        ('event_ticket_types', 'ticket_type_id'),
        ('event_ticket_types', 'event_id'),
        ('event_ticket_types', 'ticket_type'),
        ('event_ticket_types', 'price'),
        ('event_ticket_types', 'description'),
        ('event_ticket_types', 'created_at'),
        ('event_ticket_types', 'updated_at'),

        ('event_sponsorships', 'sponsorship_id'),
        ('event_sponsorships', 'event_id'),
        ('event_sponsorships', 'sponsor_name'),
        ('event_sponsorships', 'contribution_amount'),
        ('event_sponsorships', 'created_at'),
        ('event_sponsorships', 'updated_at'),

        ('event_financial_adjustments', 'adjustment_id'),
        ('event_financial_adjustments', 'event_id'),
        ('event_financial_adjustments', 'entry_kind'),
        ('event_financial_adjustments', 'amount'),
        ('event_financial_adjustments', 'note'),
        ('event_financial_adjustments', 'created_by'),
        ('event_financial_adjustments', 'created_at'),

        ('services', 'service_id'),
        ('services', 'vendor_id'),
        ('services', 'name'),
        ('services', 'description'),
        ('services', 'price'),
        ('services', 'availability'),

        ('bookings', 'booking_id'),
        ('bookings', 'event_id'),
        ('bookings', 'service_id'),
        ('bookings', 'status'),
        ('bookings', 'booked_price'),
        ('bookings', 'platform_fee'),
        ('bookings', 'booth_number'),
        ('bookings', 'created_at'),

        ('transactions', 'booking_id'),
        ('transactions', 'mpesa_code'),
        ('transactions', 'amount'),
        ('transactions', 'status'),

        ('attendances', 'attendance_id'),
        ('attendances', 'event_id'),
        ('attendances', 'attendee_id'),
        ('attendances', 'status'),

        ('messages', 'message_id'),
        ('messages', 'planner_user_id'),
        ('messages', 'vendor_user_id'),
        ('messages', 'sender_role'),
        ('messages', 'message_text'),
        ('messages', 'is_read'),
        ('messages', 'created_at'),

        ('vendor_notification_state', 'vendor_id'),
        ('vendor_notification_state', 'last_seen_pending_bookings_at'),
        ('vendor_notification_state', 'updated_at'),

        ('service_ratings', 'rating_id'),
        ('service_ratings', 'attendee_id'),
        ('service_ratings', 'service_id'),
        ('service_ratings', 'vendor_id'),
        ('service_ratings', 'rating'),
        ('service_ratings', 'feedback'),
        ('service_ratings', 'created_at'),
        ('service_ratings', 'updated_at'),

        ('password_resets', 'reset_id'),
        ('password_resets', 'user_id'),
        ('password_resets', 'token_hash'),
        ('password_resets', 'expires_at'),
        ('password_resets', 'used_at'),
        ('password_resets', 'created_at'),

        ('audit_logs', 'log_id'),
        ('audit_logs', 'user_id'),
        ('audit_logs', 'role'),
        ('audit_logs', 'action'),
        ('audit_logs', 'target_type'),
        ('audit_logs', 'target_id'),
        ('audit_logs', 'metadata_json'),
        ('audit_logs', 'ip_address'),
        ('audit_logs', 'user_agent'),
        ('audit_logs', 'created_at'),

        ('login_attempts', 'attempt_id'),
        ('login_attempts', 'email'),
        ('login_attempts', 'attempted_at'),

        ('user_totp_auth', 'user_id'),
        ('user_totp_auth', 'secret_key'),
        ('user_totp_auth', 'is_enabled'),
        ('user_totp_auth', 'verified_at'),
        ('user_totp_auth', 'created_at'),
        ('user_totp_auth', 'updated_at');

    CREATE TEMPORARY TABLE missing_tables AS
    SELECT et.table_name
    FROM expected_tables et
    LEFT JOIN information_schema.tables t
        ON t.table_schema = DATABASE()
       AND t.table_name = et.table_name
    WHERE t.table_name IS NULL;

    CREATE TEMPORARY TABLE missing_columns AS
    SELECT ec.table_name, ec.column_name
    FROM expected_columns ec
    LEFT JOIN information_schema.columns c
        ON c.table_schema = DATABASE()
       AND c.table_name = ec.table_name
       AND c.column_name = ec.column_name
    WHERE c.column_name IS NULL;

    SELECT COUNT(*) INTO v_missing_tables FROM missing_tables;
    SELECT COUNT(*) INTO v_missing_columns FROM missing_columns;

    SELECT 'Missing tables' AS section, table_name
    FROM missing_tables
    ORDER BY table_name;

    SELECT 'Missing columns' AS section, table_name, column_name
    FROM missing_columns
    ORDER BY table_name, column_name;

    IF v_missing_tables + v_missing_columns > 0 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Schema verification failed: missing required tables/columns for current backend queries.';
    END IF;

    SELECT 'Schema verification passed' AS result;

    DROP TEMPORARY TABLE IF EXISTS missing_columns;
    DROP TEMPORARY TABLE IF EXISTS missing_tables;
    DROP TEMPORARY TABLE IF EXISTS expected_columns;
    DROP TEMPORARY TABLE IF EXISTS expected_tables;
END //
DELIMITER ;

CALL verify_project_schema();
DROP PROCEDURE IF EXISTS verify_project_schema;
