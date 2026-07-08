<?php
require_once '../includes/auth.php';
checkAuth();
requireRole('planner');

$fullName = $_SESSION['full_name'] ?? 'Admin';
$flashSuccess = $_SESSION['flash_success'] ?? '';
$flashError = $_SESSION['flash_error'] ?? '';
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

$totalBudget = 0;
$totalCommitted = 0;
$totalTicketRevenue = 0;
$totalVendorContributions = 0;
$totalVendorRevenue = 0;
$events = [];
$recentBookings = [];
$unreadMessages = 0;
$pendingPayments = 0;
$failedPayments = 0;
$pendingBookings = 0;
$attentionItems = [];
$activityFeed = [];
$eventOpsById = [];
$totalItemSpentAll = 0.0;
$manualCashAvailableTotal = 0.0;
$totalSponsorshipReceived = 0.0;
$totalStallRevenue = 0.0;

function ensureEventFinancialAdjustmentsSchema(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS event_financial_adjustments (
            adjustment_id BIGINT AUTO_INCREMENT PRIMARY KEY,
            event_id INT NOT NULL,
            entry_kind ENUM('committed_paid','cash_available') NOT NULL,
            amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            note VARCHAR(255) DEFAULT NULL,
            created_by INT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_event_fin_adj_event_kind (event_id, entry_kind),
            CONSTRAINT fk_event_fin_adj_event FOREIGN KEY (event_id) REFERENCES events(event_id) ON DELETE CASCADE,
            CONSTRAINT fk_event_fin_adj_user FOREIGN KEY (created_by) REFERENCES users(user_id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $ready = true;
}

function ensureEventBudgetItemSpentSchema(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }

    $stmt = $pdo->query("SHOW COLUMNS FROM event_budget_items LIKE 'spent_amount'");
    if (!$stmt->fetch()) {
        $pdo->exec("ALTER TABLE event_budget_items ADD COLUMN spent_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER planned_amount");
    }

    $ready = true;
}

function ensureEventSponsorshipsSchema(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS event_sponsorships (
            sponsorship_id BIGINT AUTO_INCREMENT PRIMARY KEY,
            event_id INT NOT NULL,
            sponsor_name VARCHAR(190) NOT NULL,
            contribution_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_event_sponsorships_event (event_id),
            CONSTRAINT fk_event_sponsorships_event FOREIGN KEY (event_id) REFERENCES events(event_id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $ready = true;
}

function ensureStallRentalsDashboardSchema(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS stall_rentals (
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
            payment_status ENUM('pending', 'paid', 'failed', 'cancelled') NOT NULL DEFAULT 'pending',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_stall_rentals_event_vendor (event_id, vendor_user_id),
            UNIQUE KEY uq_stall_rentals_checkout (checkout_request_id),
            INDEX idx_stall_rentals_event_status (event_id, payment_status),
            INDEX idx_stall_rentals_vendor (vendor_user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $stmt = $pdo->query("SHOW COLUMNS FROM stall_rentals LIKE 'payment_status'");
    if (!$stmt->fetch()) {
        $pdo->exec("ALTER TABLE stall_rentals ADD COLUMN payment_status ENUM('pending', 'paid', 'failed', 'cancelled') NOT NULL DEFAULT 'pending' AFTER mpesa_code");
    }

    $stmt = $pdo->query("SHOW COLUMNS FROM stall_rentals LIKE 'status'");
    if (!$stmt->fetch()) {
        $pdo->exec("ALTER TABLE stall_rentals ADD COLUMN status ENUM('requested', 'paid', 'failed', 'cancelled') NOT NULL DEFAULT 'requested' AFTER mpesa_code");
    }

    $pdo->exec(
        "UPDATE stall_rentals
         SET payment_status = CASE LOWER(COALESCE(status, 'requested'))
             WHEN 'paid' THEN 'paid'
             WHEN 'failed' THEN 'failed'
             WHEN 'cancelled' THEN 'cancelled'
             ELSE 'pending'
         END"
    );

    $ready = true;
}

ensureEventFinancialAdjustmentsSchema($pdo);
ensureEventBudgetItemSpentSchema($pdo);
ensureEventSponsorshipsSchema($pdo);
ensureStallRentalsDashboardSchema($pdo);

try {
    $stmt = $pdo->prepare("SELECT * FROM events WHERE planner_id = ? AND archived_at IS NULL ORDER BY event_date DESC");
    $stmt->execute([$_SESSION['user_id']]);
    $events = $stmt->fetchAll();

    $stmt = $pdo->prepare(
        "SELECT
            e.event_id,
            COUNT(b.booking_id) AS total_bookings,
            SUM(CASE WHEN b.status = 'pending' THEN 1 ELSE 0 END) AS pending_bookings,
            SUM(CASE WHEN COALESCE(t.status, 'pending') = 'pending' THEN 1 ELSE 0 END) AS pending_payments,
            SUM(CASE WHEN t.status = 'failed' THEN 1 ELSE 0 END) AS failed_payments,
            COALESCE(SUM(CASE WHEN t.status = 'paid' THEN COALESCE(t.amount, 0) ELSE 0 END), 0) AS paid_transactions_total,
            COALESCE(SUM(CASE WHEN b.status = 'confirmed' THEN b.platform_fee ELSE 0 END), 0) AS vendor_revenue_received
         FROM events e
         LEFT JOIN bookings b ON b.event_id = e.event_id
         LEFT JOIN transactions t ON t.booking_id = b.booking_id
         WHERE e.planner_id = ? AND e.archived_at IS NULL
         GROUP BY e.event_id"
    );
    $stmt->execute([$_SESSION['user_id']]);
    foreach ($stmt->fetchAll() as $row) {
        $eventOpsById[(int)$row['event_id']] = [
            'total_bookings' => (int)($row['total_bookings'] ?? 0),
            'pending_bookings' => (int)($row['pending_bookings'] ?? 0),
            'pending_payments' => (int)($row['pending_payments'] ?? 0),
            'failed_payments' => (int)($row['failed_payments'] ?? 0),
            'paid_transactions_total' => (float)($row['paid_transactions_total'] ?? 0),
            'item_spent_total' => 0.0,
            'manual_cash_available' => 0.0,
            'sponsorship_received' => 0.0,
            'stall_revenue_received' => 0.0,
            'vendor_revenue_received' => (float)($row['vendor_revenue_received'] ?? 0),
            'unread_vendor_messages' => 0,
        ];
    }

    $stmt = $pdo->prepare(
        "SELECT efa.event_id,
                COALESCE(SUM(CASE WHEN efa.entry_kind = 'cash_available' THEN efa.amount ELSE 0 END), 0) AS manual_cash_available
         FROM event_financial_adjustments efa
         JOIN events e ON e.event_id = efa.event_id
         WHERE e.planner_id = ? AND e.archived_at IS NULL
         GROUP BY efa.event_id"
    );
    $stmt->execute([$_SESSION['user_id']]);
    foreach ($stmt->fetchAll() as $row) {
        $eventId = (int)$row['event_id'];
        if (!isset($eventOpsById[$eventId])) {
            $eventOpsById[$eventId] = [
                'total_bookings' => 0,
                'pending_bookings' => 0,
                'pending_payments' => 0,
                'failed_payments' => 0,
                'paid_transactions_total' => 0.0,
                'item_spent_total' => 0.0,
                'manual_cash_available' => 0.0,
                'sponsorship_received' => 0.0,
                'stall_revenue_received' => 0.0,
                'vendor_revenue_received' => 0.0,
                'unread_vendor_messages' => 0,
            ];
        }
        $eventOpsById[$eventId]['manual_cash_available'] = (float)($row['manual_cash_available'] ?? 0);
    }

    $stmt = $pdo->prepare(
        "SELECT ebi.event_id, COALESCE(SUM(ebi.spent_amount), 0) AS item_spent_total
         FROM event_budget_items ebi
         JOIN events e ON e.event_id = ebi.event_id
         WHERE e.planner_id = ? AND e.archived_at IS NULL
         GROUP BY ebi.event_id"
    );
    $stmt->execute([$_SESSION['user_id']]);
    foreach ($stmt->fetchAll() as $row) {
        $eventId = (int)$row['event_id'];
        if (!isset($eventOpsById[$eventId])) {
            $eventOpsById[$eventId] = [
                'total_bookings' => 0,
                'pending_bookings' => 0,
                'pending_payments' => 0,
                'failed_payments' => 0,
                'paid_transactions_total' => 0.0,
                'item_spent_total' => 0.0,
                'manual_cash_available' => 0.0,
                'sponsorship_received' => 0.0,
                'stall_revenue_received' => 0.0,
                'vendor_revenue_received' => 0.0,
                'unread_vendor_messages' => 0,
            ];
        }
        $eventOpsById[$eventId]['item_spent_total'] = (float)($row['item_spent_total'] ?? 0);
    }

    $stmt = $pdo->prepare(
        "SELECT es.event_id, COALESCE(SUM(es.contribution_amount), 0) AS sponsorship_received
         FROM event_sponsorships es
         JOIN events e ON e.event_id = es.event_id
         WHERE e.planner_id = ? AND e.archived_at IS NULL
         GROUP BY es.event_id"
    );
    $stmt->execute([$_SESSION['user_id']]);
    foreach ($stmt->fetchAll() as $row) {
        $eventId = (int)$row['event_id'];
        if (!isset($eventOpsById[$eventId])) {
            $eventOpsById[$eventId] = [
                'total_bookings' => 0,
                'pending_bookings' => 0,
                'pending_payments' => 0,
                'failed_payments' => 0,
                'paid_transactions_total' => 0.0,
                'item_spent_total' => 0.0,
                'manual_cash_available' => 0.0,
                'sponsorship_received' => 0.0,
                'stall_revenue_received' => 0.0,
                'vendor_revenue_received' => 0.0,
                'unread_vendor_messages' => 0,
            ];
        }
        $eventOpsById[$eventId]['sponsorship_received'] = (float)($row['sponsorship_received'] ?? 0);
    }

    $stmt = $pdo->prepare(
        "SELECT sr.event_id,
                COALESCE(SUM(CASE
                    WHEN COALESCE(sr.payment_status,
                        CASE LOWER(COALESCE(sr.status, 'requested'))
                            WHEN 'paid' THEN 'paid'
                            WHEN 'failed' THEN 'failed'
                            WHEN 'cancelled' THEN 'cancelled'
                            ELSE 'pending'
                        END
                    ) = 'paid' THEN sr.amount ELSE 0 END), 0) AS stall_revenue_received
         FROM stall_rentals sr
         JOIN events e ON e.event_id = sr.event_id
         WHERE e.planner_id = ? AND e.archived_at IS NULL
         GROUP BY sr.event_id"
    );
    $stmt->execute([$_SESSION['user_id']]);
    foreach ($stmt->fetchAll() as $row) {
        $eventId = (int)$row['event_id'];
        if (!isset($eventOpsById[$eventId])) {
            $eventOpsById[$eventId] = [
                'total_bookings' => 0,
                'pending_bookings' => 0,
                'pending_payments' => 0,
                'failed_payments' => 0,
                'paid_transactions_total' => 0.0,
                'item_spent_total' => 0.0,
                'manual_cash_available' => 0.0,
                'sponsorship_received' => 0.0,
                'stall_revenue_received' => 0.0,
                'vendor_revenue_received' => 0.0,
                'unread_vendor_messages' => 0,
            ];
        }
        $eventOpsById[$eventId]['stall_revenue_received'] = (float)($row['stall_revenue_received'] ?? 0);
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS messages (
            message_id INT AUTO_INCREMENT PRIMARY KEY,
            planner_user_id INT NOT NULL,
            vendor_user_id INT NOT NULL,
            sender_role ENUM('planner','vendor') NOT NULL,
            message_text TEXT NOT NULL,
            is_read TINYINT(1) NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_conversation (planner_user_id, vendor_user_id, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $stmt = $pdo->query("SHOW COLUMNS FROM messages LIKE 'is_read'");
    if (!$stmt->fetch()) {
        $pdo->exec("ALTER TABLE messages ADD COLUMN is_read TINYINT(1) NOT NULL DEFAULT 0");
    }

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM messages WHERE planner_user_id = ? AND sender_role = 'vendor' AND is_read = 0");
    $stmt->execute([$_SESSION['user_id']]);
    $unreadMessages = (int)$stmt->fetchColumn();

    $stmt = $pdo->prepare(
        "SELECT
            b.event_id,
            COUNT(DISTINCT m.message_id) AS unread_vendor_messages
         FROM bookings b
         JOIN services s ON s.service_id = b.service_id
         JOIN vendors v ON v.vendor_id = s.vendor_id
         JOIN events e ON e.event_id = b.event_id
         JOIN messages m
           ON m.vendor_user_id = v.user_id
          AND m.planner_user_id = ?
          AND m.sender_role = 'vendor'
          AND m.is_read = 0
         WHERE e.planner_id = ? AND e.archived_at IS NULL
         GROUP BY b.event_id"
    );
    $stmt->execute([$_SESSION['user_id'], $_SESSION['user_id']]);
    foreach ($stmt->fetchAll() as $row) {
        $eventId = (int)$row['event_id'];
        if (!isset($eventOpsById[$eventId])) {
            $eventOpsById[$eventId] = [
                'total_bookings' => 0,
                'pending_bookings' => 0,
                'pending_payments' => 0,
                'failed_payments' => 0,
                'paid_transactions_total' => 0.0,
                'item_spent_total' => 0.0,
                'manual_cash_available' => 0.0,
                'sponsorship_received' => 0.0,
                'stall_revenue_received' => 0.0,
                'vendor_revenue_received' => 0.0,
                'unread_vendor_messages' => 0,
            ];
        }
        $eventOpsById[$eventId]['unread_vendor_messages'] = (int)($row['unread_vendor_messages'] ?? 0);
    }

    $stmt = $pdo->prepare(
        "SELECT
            SUM(CASE WHEN COALESCE(t.status, 'pending') = 'pending' THEN 1 ELSE 0 END) AS pending_payments,
            SUM(CASE WHEN t.status = 'failed' THEN 1 ELSE 0 END) AS failed_payments,
            SUM(CASE WHEN b.status = 'pending' THEN 1 ELSE 0 END) AS pending_bookings
         FROM bookings b
         JOIN events e ON b.event_id = e.event_id
         LEFT JOIN transactions t ON t.booking_id = b.booking_id
         WHERE e.planner_id = ? AND e.archived_at IS NULL"
    );
    $stmt->execute([$_SESSION['user_id']]);
    $opsStats = $stmt->fetch();
    if ($opsStats) {
        $pendingPayments = (int)($opsStats['pending_payments'] ?? 0);
        $failedPayments = (int)($opsStats['failed_payments'] ?? 0);
        $pendingBookings = (int)($opsStats['pending_bookings'] ?? 0);
    }

    $stmt = $pdo->prepare(
        "SELECT COALESCE(SUM(b.booked_price), 0)
         FROM bookings b
         JOIN events e ON e.event_id = b.event_id
         WHERE e.planner_id = ? AND e.archived_at IS NULL"
    );
    $stmt->execute([$_SESSION['user_id']]);
    $totalVendorContributions = (float)$stmt->fetchColumn();

    $stmt = $pdo->prepare(
        "SELECT COALESCE(SUM(b.platform_fee), 0)
         FROM bookings b
         JOIN events e ON e.event_id = b.event_id
                 WHERE e.planner_id = ? AND e.archived_at IS NULL
           AND b.status = 'confirmed'"
    );
    $stmt->execute([$_SESSION['user_id']]);
    $totalVendorRevenue = (float)$stmt->fetchColumn();

    foreach ($events as $e) {
        $totalBudget += $e['budget_total'];
        $totalTicketRevenue += $e['ticket_revenue'];
    }

    $stmt = $pdo->prepare(
        "SELECT COALESCE(SUM(t.amount), 0)
         FROM transactions t
         JOIN bookings b ON b.booking_id = t.booking_id
         JOIN events e ON e.event_id = b.event_id
         WHERE e.planner_id = ? AND e.archived_at IS NULL AND t.status = 'paid'"
    );
    $stmt->execute([$_SESSION['user_id']]);
    $totalCommitted = (float)$stmt->fetchColumn();

    $stmt = $pdo->prepare(
        "SELECT
            COALESCE(SUM(CASE WHEN entry_kind = 'cash_available' THEN amount ELSE 0 END), 0) AS manual_cash_available_total
         FROM event_financial_adjustments efa
         JOIN events e ON e.event_id = efa.event_id
         WHERE e.planner_id = ? AND e.archived_at IS NULL"
    );
    $stmt->execute([$_SESSION['user_id']]);
    $manualTotals = $stmt->fetch();
    $manualCashAvailableTotal = (float)($manualTotals['manual_cash_available_total'] ?? 0);

    $stmt = $pdo->prepare(
        "SELECT COALESCE(SUM(es.contribution_amount), 0)
         FROM event_sponsorships es
         JOIN events e ON e.event_id = es.event_id
         WHERE e.planner_id = ? AND e.archived_at IS NULL"
    );
    $stmt->execute([$_SESSION['user_id']]);
    $totalSponsorshipReceived = (float)$stmt->fetchColumn();

    $stmt = $pdo->prepare(
        "SELECT COALESCE(SUM(sr.amount), 0)
         FROM stall_rentals sr
         JOIN events e ON e.event_id = sr.event_id
         WHERE e.planner_id = ?
           AND e.archived_at IS NULL
           AND COALESCE(sr.payment_status,
                CASE LOWER(COALESCE(sr.status, 'requested'))
                    WHEN 'paid' THEN 'paid'
                    WHEN 'failed' THEN 'failed'
                    WHEN 'cancelled' THEN 'cancelled'
                    ELSE 'pending'
                END
           ) = 'paid'"
    );
    $stmt->execute([$_SESSION['user_id']]);
    $totalStallRevenue = (float)$stmt->fetchColumn();

    $stmt = $pdo->prepare(
        "SELECT COALESCE(SUM(ebi.spent_amount), 0)
         FROM event_budget_items ebi
         JOIN events e ON e.event_id = ebi.event_id
         WHERE e.planner_id = ? AND e.archived_at IS NULL"
    );
    $stmt->execute([$_SESSION['user_id']]);
    $totalItemSpentAll = (float)$stmt->fetchColumn();
    $totalCommitted += $totalItemSpentAll;

    $stmt = $pdo->prepare("
        SELECT b.booking_id, b.status, b.booked_price, s.name AS service_name, v.business_name,
               e.title AS event_title
        FROM bookings b
        JOIN services s ON b.service_id = s.service_id
        JOIN vendors v ON s.vendor_id = v.vendor_id
        JOIN events e ON b.event_id = e.event_id
        WHERE e.planner_id = ? AND e.archived_at IS NULL
        ORDER BY b.created_at DESC
        LIMIT 5
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $recentBookings = $stmt->fetchAll();

    $stmt = $pdo->prepare(
        "SELECT * FROM (
            SELECT
                'booking' AS entry_type,
                b.created_at AS entry_at,
                e.title AS event_title,
                v.business_name AS actor_name,
                s.name AS context_label,
                b.status AS status_label,
                b.booked_price AS amount,
                b.booking_id AS reference_id
            FROM bookings b
            JOIN services s ON s.service_id = b.service_id
            JOIN vendors v ON v.vendor_id = s.vendor_id
            JOIN events e ON e.event_id = b.event_id
            WHERE e.planner_id = ? AND e.archived_at IS NULL

            UNION ALL

            SELECT
                'payment' AS entry_type,
                COALESCE(t.created_at, b.created_at) AS entry_at,
                e.title AS event_title,
                v.business_name AS actor_name,
                s.name AS context_label,
                COALESCE(t.status, 'pending') AS status_label,
                COALESCE(t.amount, b.booked_price) AS amount,
                b.booking_id AS reference_id
            FROM bookings b
            JOIN services s ON s.service_id = b.service_id
            JOIN vendors v ON v.vendor_id = s.vendor_id
            JOIN events e ON e.event_id = b.event_id
            LEFT JOIN transactions t ON t.booking_id = b.booking_id
            WHERE e.planner_id = ? AND e.archived_at IS NULL

            UNION ALL

            SELECT
                'message' AS entry_type,
                m.created_at AS entry_at,
                '-' AS event_title,
                v.business_name AS actor_name,
                LEFT(m.message_text, 90) AS context_label,
                CASE WHEN m.sender_role = 'vendor' THEN 'vendor_update' ELSE 'planner_note' END AS status_label,
                0 AS amount,
                m.vendor_user_id AS reference_id
            FROM messages m
            JOIN vendors v ON v.user_id = m.vendor_user_id
            WHERE m.planner_user_id = ?
        ) merged
        ORDER BY merged.entry_at DESC
        LIMIT 12"
    );
    $stmt->execute([$_SESSION['user_id'], $_SESSION['user_id'], $_SESSION['user_id']]);
    $activityFeed = $stmt->fetchAll();
} catch (Throwable $e) {
    $flashError = 'Could not load dashboard data.';
}

$available = $totalTicketRevenue + $totalVendorRevenue + $totalStallRevenue + $manualCashAvailableTotal + $totalSponsorshipReceived;
$budgetRemainingForPlan = $totalBudget - $totalCommitted;
$remainingCashAtUse = max(0, $available - $totalCommitted);
$percent = $totalBudget > 0 ? (($totalCommitted > 0 ? $totalCommitted : 0) / $totalBudget) * 100 : 0;
$budgetColor = $percent > 80 ? 'status-danger' : ($percent > 50 ? 'status-warning' : 'status-good');
$budgetLabel = $percent > 80 ? 'Over Budget' : ($percent > 50 ? 'Caution' : 'Good');
$hasFundingOverrun = $totalBudget > 0 && $totalCommitted > $totalBudget;
$fundingGap = max(0, $totalCommitted - $totalBudget);
$fundingBuffer = max(0, $totalBudget - $totalCommitted);

if ($hasFundingOverrun) {
    $attentionItems[] = [
        'type' => 'danger',
        'label' => 'Budget overrun detected. Money spent is above planned budget.',
        'link' => 'mpesa_payments.php',
        'link_text' => 'Review spending',
    ];
}

if ($pendingPayments > 0) {
    $attentionItems[] = [
        'type' => 'warning',
        'label' => $pendingPayments . ' booking payments are pending reconciliation.',
        'link' => 'mpesa_payments.php',
        'link_text' => 'Reconcile payments',
    ];
}

if ($failedPayments > 0) {
    $attentionItems[] = [
        'type' => 'danger',
        'label' => $failedPayments . ' payment records are marked as failed and need follow-up.',
        'link' => 'mpesa_payments.php',
        'link_text' => 'Resolve failures',
    ];
}

if ($unreadMessages > 0) {
    $attentionItems[] = [
        'type' => 'info',
        'label' => $unreadMessages . ' unread vendor message(s) may contain delivery or payment confirmations.',
        'link' => 'messages.php?unread=1',
        'link_text' => 'View unread chats',
    ];
}

if ($pendingBookings > 0) {
    $attentionItems[] = [
        'type' => 'info',
        'label' => $pendingBookings . ' booking(s) are pending confirmation and may impact deadlines.',
        'link' => 'mpesa_payments.php',
        'link_text' => 'Review bookings',
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="../assets/style.css">
    <title>Planora Dashboard</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Inter', sans-serif;
        }

        body {
            background-color: #F5F5F5;
            color: #2D2D2D;
            display: flex;
            min-height: 100vh;
        }

        .sidebar {
            width: 240px;
            background-color: #FFFFFF;
            border-right: 1px solid #E5E7EB;
            display: flex;
            flex-direction: column;
            position: fixed;
            top: 0;
            bottom: 0;
            left: 0;
            z-index: 100;
        }

        .sidebar-brand {
            background-color: #6C63FF;
            color: #FFFFFF;
            padding: 20px;
            font-size: 24px;
            font-weight: 700;
            height: 70px;
            display: flex;
            align-items: center;
        }

        .sidebar-menu {
            list-style: none;
            padding: 20px 0;
        }

        .sidebar-menu li a {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 14px 24px;
            color: #4B5563;
            text-decoration: none;
            font-weight: 500;
            transition: background 0.2s;
        }

        .sidebar-menu li a .menu-label {
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .badge-unread {
            display: inline-block;
            min-width: 18px;
            height: 18px;
            border-radius: 999px;
            background: #e74c3c;
            color: #fff;
            font-size: 11px;
            font-weight: 700;
            line-height: 18px;
            text-align: center;
            padding: 0 5px;
        }

        .sidebar-menu li.active a, .sidebar-menu li a:hover {
            background-color: #F0EEFF;
            color: #6C63FF;
            font-weight: 600;
        }

        .sidebar-menu li a i {
            margin-right: 15px;
            width: 20px;
            text-align: center;
            font-size: 18px;
        }

        .main-wrapper {
            flex: 1;
            margin-left: 240px;
            display: flex;
            flex-direction: column;
            min-height: 100vh;
        }

        .header {
            background-color: #6C63FF;
            height: 70px;
            display: flex;
            justify-content: flex-end;
            align-items: center;
            padding: 0 30px;
            position: sticky;
            top: 0;
            z-index: 90;
        }

        .header-brand-mobile {
            display: none;
            color: #FFFFFF;
            font-size: 24px;
            font-weight: 700;
            margin-right: auto;
        }

        .logout-btn {
            background-color: rgba(255, 255, 255, 0.2);
            color: #FFFFFF;
            border: 1px solid rgba(255, 255, 255, 0.4);
            padding: 8px 16px;
            border-radius: 6px;
            font-weight: 500;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
            font-size: 14px;
            transition: background 0.2s;
        }

        .logout-btn:hover {
            background-color: rgba(255, 255, 255, 0.3);
        }

        .content {
            padding: 30px;
            max-width: 1200px;
            width: 100%;
            margin: 0 auto;
            display: flex;
            flex-direction: column;
            gap: 24px;
        }

        .message {
            padding: 10px 12px;
            border-radius: 6px;
            font-size: 13px;
            margin-bottom: 0;
        }

        .message-success {
            background: #ecfff0;
            color: #1c7a36;
            border: 1px solid #c9f0d4;
        }

        .message-error {
            background: #ffecec;
            color: #9d2020;
            border: 1px solid #f6caca;
        }

        .top-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 24px;
        }

        .card {
            background-color: #FFFFFF;
            border: 1px solid #E5E7EB;
            border-radius: 8px;
            padding: 24px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
        }

        .card-title {
            font-size: 18px;
            font-weight: 600;
            color: #2D2D2D;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .budget-metric {
            font-size: 15px;
            margin-bottom: 10px;
            color: #4B5563;
        }

        .budget-metric strong {
            color: #2D2D2D;
        }

        .status-good { color: #2ecc71; font-weight: 600; }
        .status-warning { color: #f39c12; font-weight: 600; }
        .status-danger { color: #e74c3c; font-weight: 600; }

        .progress-bar-container {
            width: 100%;
            background-color: #E5E7EB;
            height: 8px;
            border-radius: 4px;
            margin-top: 15px;
            overflow: hidden;
        }

        .progress-bar-fill {
            background-color: #6C63FF;
            height: 100%;
            border-radius: 4px;
            transition: width 0.3s ease;
        }

        .mini-alert {
            margin-top: 12px;
            padding: 9px 11px;
            border-radius: 8px;
            font-size: 12px;
            font-weight: 600;
            border: 1px solid transparent;
        }

        .mini-alert-danger {
            background: #fff1f1;
            color: #9b1c1c;
            border-color: #f3c7c7;
        }

        .mini-alert-success {
            background: #eefcf3;
            color: #166534;
            border-color: #bfe6cb;
        }

        .event-budget-list {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .event-budget-item {
            border: 1px solid #e8e8ef;
            border-radius: 8px;
            overflow: hidden;
            background: #fff;
        }

        .event-budget-toggle {
            width: 100%;
            border: 0;
            background: #f8f7ff;
            color: #2d2d2d;
            padding: 12px 14px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 10px;
            text-align: left;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
        }

        .event-budget-toggle:hover {
            background: #f2f0ff;
        }

        .event-budget-chevron {
            color: #4f46c8;
            transition: transform 0.2s ease;
        }

        .event-budget-item.open .event-budget-chevron {
            transform: rotate(180deg);
        }

        .event-budget-panel {
            display: none;
            padding: 12px 14px 14px;
            border-top: 1px solid #eee;
        }

        .event-budget-item.open .event-budget-panel {
            display: block;
        }

        .action-buttons {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .btn {
            width: 100%;
            padding: 12px;
            border-radius: 6px;
            font-size: 15px;
            font-weight: 500;
            text-align: center;
            cursor: pointer;
            border: none;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            text-decoration: none;
        }

        .btn-primary {
            background-color: #6C63FF;
            color: #FFFFFF;
        }

        .btn-primary:hover { background-color: #5A52E0; }

        .btn-secondary {
            background-color: #B8A8FF;
            color: #2D2D2D;
        }

        .btn-secondary:hover { background-color: #A898F0; }

        .list-container {
            display: flex;
            flex-direction: column;
        }

        .list-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 16px 0;
            border-bottom: 1px solid #F3F4F6;
            color: #4B5563;
            text-decoration: none;
            font-size: 15px;
        }

        .list-item:last-child {
            border-bottom: none;
            padding-bottom: 0;
        }

        .event-main {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .event-title-line {
            color: #2d2d2d;
        }

        .event-chips {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
        }

        .event-funding-alert {
            margin-top: 6px;
            padding: 6px 8px;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 600;
            border: 1px solid transparent;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .event-funding-alert.danger {
            background: #fff1f1;
            color: #9b1c1c;
            border-color: #f1c4c4;
        }

        .event-funding-alert.success {
            background: #eefcf3;
            color: #166534;
            border-color: #bfe6cb;
        }

        .chip {
            font-size: 11px;
            font-weight: 700;
            border-radius: 999px;
            padding: 4px 8px;
        }

        .chip-risk { background: #ffe8e8; color: #9d2020; }
        .chip-warn { background: #fff4df; color: #a36500; }
        .chip-info { background: #ece9ff; color: #4a40bf; }
        .chip-good { background: #e8f9ef; color: #1c7a36; }

        .event-actions {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
            justify-content: flex-end;
        }

        .event-actions a {
            font-size: 12px;
            color: #3e36c8;
            text-decoration: none;
            font-weight: 600;
        }

        .status-badge { font-weight: 600; }
        .status-badge.confirmed { color: #2ecc71; }
        .status-badge.pending { color: #f39c12; }

        .ops-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 24px;
        }

        .attention-list,
        .activity-list {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .attention-item,
        .activity-item {
            border: 1px solid #ebebeb;
            border-radius: 8px;
            padding: 12px;
            background: #fafafa;
        }

        .attention-item.warning { border-color: #f4d9a6; background: #fffaf0; }
        .attention-item.danger { border-color: #f2c0c0; background: #fff5f5; }
        .attention-item.info { border-color: #cfdcff; background: #f5f8ff; }

        .attention-item p {
            font-size: 14px;
            color: #344054;
            margin-bottom: 8px;
            line-height: 1.45;
        }

        .attention-item a {
            font-size: 13px;
            color: #3e36c8;
            text-decoration: none;
            font-weight: 600;
        }

        .activity-top {
            display: flex;
            justify-content: space-between;
            gap: 10px;
            margin-bottom: 6px;
        }

        .activity-kind {
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 0.02em;
            text-transform: uppercase;
            color: #5f56d8;
        }

        .activity-time {
            font-size: 12px;
            color: #8b8b8b;
        }

        .activity-main {
            font-size: 14px;
            color: #2d2d2d;
            margin-bottom: 4px;
        }

        .activity-meta {
            font-size: 12px;
            color: #666;
        }

        .activity-link {
            margin-top: 7px;
            display: inline-block;
            font-size: 12px;
            font-weight: 600;
            color: #3e36c8;
            text-decoration: none;
        }

        .bottom-nav {
            display: none;
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            background-color: #6C63FF;
            height: 65px;
            z-index: 100;
            justify-content: space-around;
            align-items: center;
        }

        .bottom-nav-item {
            display: flex;
            flex-direction: column;
            align-items: center;
            color: rgba(255, 255, 255, 0.7);
            text-decoration: none;
            font-size: 11px;
            gap: 4px;
        }

        .bottom-nav-item i { font-size: 20px; }
        .bottom-nav-item.active, .bottom-nav-item:hover { color: #FFFFFF; }

        .bottom-nav-item .badge-unread {
            margin-top: 2px;
            background: #ff6b6b;
        }

        .empty-state {
            color: #888;
            padding: 10px 0;
            font-size: 14px;
        }

        @media (max-width: 768px) {
            .sidebar { display: none; }
            .main-wrapper { margin-left: 0; padding-bottom: 75px; }
            .header { justify-content: space-between; padding: 0 20px; }
            .header-brand-mobile { display: block; }
            .content { padding: 20px; gap: 20px; }
            .top-grid { grid-template-columns: 1fr; gap: 20px; }
            .ops-grid { grid-template-columns: 1fr; gap: 20px; }
            .bottom-nav { display: flex; }
        }
    </style>
</head>
<body>

    <aside class="sidebar">
        <div class="sidebar-brand">Planora</div>
        <ul class="sidebar-menu">
            <li class="active"><a href="dashboard.php"><span class="menu-label"><i class="fa-solid fa-house"></i> Home</span></a></li>
            <li><a href="create_event.php"><span class="menu-label"><i class="fa-solid fa-calendar-days"></i> Events</span></a></li>
            <li><a href="browse_vendors.php"><span class="menu-label"><i class="fa-solid fa-shop"></i> Vendors</span></a></li>
            <li><a href="messages.php"><span class="menu-label"><i class="fa-solid fa-comments"></i> Messages</span><?php if ($unreadMessages > 0): ?><span class="badge-unread"><?php echo $unreadMessages; ?></span><?php endif; ?></a></li>
            <li><a href="mpesa_payments.php"><span class="menu-label"><i class="fa-solid fa-book-bookmark"></i> Booking Payments</span></a></li>
            <li><a href="stall_payments.php"><span class="menu-label"><i class="fa-solid fa-store"></i> Stall Payments</span></a></li>
            <li><a href="profile.php"><span class="menu-label"><i class="fa-solid fa-user"></i> Profile</span></a></li>
        </ul>
    </aside>

    <div class="main-wrapper">
        <header class="header">
            <div class="header-brand-mobile">Planora</div>
            <div style="display:flex; align-items:center; gap:8px;">
                <a href="../two_factor_setup.php" class="logout-btn">
                    <i class="fa-solid fa-shield-halved"></i> Manage 2-Step
                </a>
                <a href="../logout.php" class="logout-btn">
                    <i class="fa-solid fa-right-from-bracket"></i> Logout
                </a>
            </div>
        </header>

        <main class="content">

            <?php if ($flashSuccess !== ''): ?>
                <div class="message message-success"><?php echo htmlspecialchars($flashSuccess); ?></div>
            <?php endif; ?>

            <?php if ($flashError !== ''): ?>
                <div class="message message-error"><?php echo htmlspecialchars($flashError); ?></div>
            <?php endif; ?>

            <div class="top-grid">
                <div class="card">
                    <h3 class="card-title">
                        <i class="fa-regular fa-folder-open" style="color: #6C63FF;"></i> Event Budget Overview
                    </h3>
                    <?php if (empty($events)): ?>
                        <div class="empty-state">No events created yet.</div>
                    <?php else: ?>
                        <div class="event-budget-list" id="event_budget_list">
                            <?php foreach ($events as $event): ?>
                                <?php
                                    $eventId = (int)$event['event_id'];
                                    $ops = $eventOpsById[$eventId] ?? [
                                        'total_bookings' => 0,
                                        'pending_bookings' => 0,
                                        'pending_payments' => 0,
                                        'failed_payments' => 0,
                                        'paid_transactions_total' => 0.0,
                                        'item_spent_total' => 0.0,
                                        'manual_cash_available' => 0.0,
                                        'sponsorship_received' => 0.0,
                                        'stall_revenue_received' => 0.0,
                                        'vendor_revenue_received' => 0.0,
                                        'unread_vendor_messages' => 0,
                                    ];

                                    $eventTotalBudget = (float)$event['budget_total'];
                                    $eventPaidTransactions = (float)($ops['paid_transactions_total'] ?? 0);
                                    $eventItemSpentTotal = (float)($ops['item_spent_total'] ?? 0);
                                    $eventAttendeeRevenue = (float)$event['ticket_revenue'];
                                    $eventVendorRevenue = (float)($ops['vendor_revenue_received'] ?? 0);
                                    $eventStallRevenue = (float)($ops['stall_revenue_received'] ?? 0);
                                    $eventSponsorships = (float)($ops['sponsorship_received'] ?? 0);
                                    $eventCommittedPaid = $eventPaidTransactions + $eventItemSpentTotal;
                                    $eventManualCashAtUse = (float)($ops['manual_cash_available'] ?? 0);
                                    $eventAvailableFunds = $eventAttendeeRevenue + $eventVendorRevenue + $eventStallRevenue + $eventSponsorships + $eventManualCashAtUse;
                                    $eventRemainingCashAtUse = max(0, $eventAvailableFunds - $eventCommittedPaid);
                                    $eventFundingGapLocal = max(0, $eventCommittedPaid - $eventTotalBudget);
                                    $eventFundingBufferLocal = max(0, $eventTotalBudget - $eventCommittedPaid);
                                    $eventHasFundingOverrun = $eventTotalBudget > 0 && $eventCommittedPaid > $eventTotalBudget;
                                    $eventPercent = $eventTotalBudget > 0 ? (($eventCommittedPaid > 0 ? $eventCommittedPaid : 0) / $eventTotalBudget) * 100 : 0;
                                ?>
                                <div class="event-budget-item" data-event-budget-item>
                                    <button type="button" class="event-budget-toggle" data-event-budget-toggle>
                                        <span><?php echo htmlspecialchars((string)$event['title']); ?> (<?php echo htmlspecialchars((string)$event['event_date']); ?>)</span>
                                        <span style="display:flex; align-items:center; gap:10px;">
                                            <strong>KES <?php echo number_format($eventTotalBudget, 2); ?></strong>
                                            <i class="fa-solid fa-chevron-down event-budget-chevron"></i>
                                        </span>
                                    </button>
                                    <div class="event-budget-panel">
                                        <div class="budget-metric">Budget Plan: <strong>KES <?php echo number_format($eventTotalBudget, 2); ?></strong></div>
                                        <div class="budget-metric">Money Spent: <strong>KES <?php echo number_format($eventCommittedPaid, 2); ?></strong></div>
                                        <div class="budget-metric">Item Spend Total: <strong>KES <?php echo number_format($eventItemSpentTotal, 2); ?></strong></div>
                                        <div class="budget-metric">Attendee Money: <strong>KES <?php echo number_format($eventAttendeeRevenue, 2); ?></strong></div>
                                        <div class="budget-metric">Vendor Money: <strong>KES <?php echo number_format($eventVendorRevenue, 2); ?></strong></div>
                                        <div class="budget-metric">Stall Money: <strong>KES <?php echo number_format($eventStallRevenue, 2); ?></strong></div>
                                        <div class="budget-metric">Sponsorships: <strong>KES <?php echo number_format($eventSponsorships, 2); ?></strong></div>
                                        <div class="budget-metric">Extra Cash: <strong>KES <?php echo number_format($eventManualCashAtUse, 2); ?></strong></div>
                                        <div class="budget-metric">Money Received: <strong>KES <?php echo number_format($eventAvailableFunds, 2); ?></strong></div>
                                        <div class="budget-metric">Cash Left: <strong>KES <?php echo number_format($eventRemainingCashAtUse, 2); ?></strong></div>
                                        <?php if ($eventHasFundingOverrun): ?>
                                            <div class="mini-alert mini-alert-danger">
                                                <i class="fa-solid fa-triangle-exclamation"></i>
                                                Overrun: Money spent is above budget by KES <?php echo number_format($eventFundingGapLocal, 2); ?>.
                                            </div>
                                        <?php else: ?>
                                            <div class="mini-alert mini-alert-success">
                                                <i class="fa-solid fa-circle-check"></i>
                                                Good: Spending is within budget by KES <?php echo number_format($eventFundingBufferLocal, 2); ?>.
                                            </div>
                                        <?php endif; ?>
                                        <div class="progress-bar-container">
                                            <div class="progress-bar-fill" style="width: <?php echo min(100, max(0, $eventPercent)); ?>%;"></div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    <div class="budget-metric" style="margin-top:12px; font-size:13px; color:#667085;">
                        Click an event to show its budget overview, click again to hide it.
                    </div>
                </div>

                <div class="card">
                    <h3 class="card-title">
                        <i class="fa-solid fa-bolt" style="color: #6C63FF;"></i> Quick Actions
                    </h3>
                    <div class="action-buttons">
                        <a href="create_event.php" class="btn btn-primary">
                            <i class="fa-solid fa-circle-plus"></i> Create Event
                        </a>
                        <a href="browse_vendors.php" class="btn btn-secondary">
                            <i class="fa-solid fa-magnifying-glass"></i> Browse Vendors
                        </a>
                        <a href="messages.php?unread=1" class="btn btn-secondary">
                            <i class="fa-solid fa-comments"></i> Unread Messages
                        </a>
                        <a href="mpesa_payments.php" class="btn btn-secondary">
                            <i class="fa-solid fa-money-bill-wave"></i> Payment Reconciliation
                        </a>
                        <a href="stall_payments.php" class="btn btn-secondary">
                            <i class="fa-solid fa-store"></i> Stall Payment Reconciliation
                        </a>
                    </div>
                </div>
            </div>

            <div class="ops-grid">
                <div class="card">
                    <h3 class="card-title">
                        <i class="fa-solid fa-triangle-exclamation" style="color: #6C63FF;"></i> Operations Alerts
                    </h3>
                    <div class="attention-list">
                        <?php if (empty($attentionItems)): ?>
                            <div class="empty-state">No urgent alerts right now. Your planning workflow is in sync.</div>
                        <?php else: ?>
                            <?php foreach ($attentionItems as $item): ?>
                                <article class="attention-item <?php echo htmlspecialchars($item['type']); ?>">
                                    <p><?php echo htmlspecialchars($item['label']); ?></p>
                                    <a href="<?php echo htmlspecialchars($item['link']); ?>"><?php echo htmlspecialchars($item['link_text']); ?></a>
                                </article>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="card">
                    <h3 class="card-title">
                        <i class="fa-solid fa-timeline" style="color: #6C63FF;"></i> Unified Activity Feed
                    </h3>
                    <div class="activity-list">
                        <?php if (empty($activityFeed)): ?>
                            <div class="empty-state">No activity yet. Create an event, book a vendor, or start a conversation.</div>
                        <?php else: ?>
                            <?php foreach ($activityFeed as $entry): ?>
                                <?php
                                    $kind = strtolower((string)$entry['entry_type']);
                                    $status = strtolower((string)$entry['status_label']);
                                    $actionLink = 'dashboard.php';
                                    $actionText = 'Open dashboard';

                                    if ($kind === 'payment' || $kind === 'booking') {
                                        $actionLink = 'initiate_payment.php?booking_id=' . (int)$entry['reference_id'];
                                        $actionText = 'Open payment';
                                    } elseif ($kind === 'message') {
                                        $actionLink = 'messages.php?vendor_user_id=' . (int)$entry['reference_id'];
                                        $actionText = 'Open chat';
                                    }
                                ?>
                                <article class="activity-item">
                                    <div class="activity-top">
                                        <span class="activity-kind"><?php echo htmlspecialchars($kind); ?></span>
                                        <span class="activity-time"><?php echo htmlspecialchars((string)$entry['entry_at']); ?></span>
                                    </div>
                                    <div class="activity-main">
                                        <?php if ($kind === 'message'): ?>
                                            <?php echo htmlspecialchars((string)$entry['actor_name']); ?> shared a conversation update.
                                        <?php elseif ($kind === 'payment'): ?>
                                            Payment <?php echo htmlspecialchars($status); ?> for <?php echo htmlspecialchars((string)$entry['actor_name']); ?>.
                                        <?php else: ?>
                                            Booking <?php echo htmlspecialchars($status); ?> for <?php echo htmlspecialchars((string)$entry['actor_name']); ?>.
                                        <?php endif; ?>
                                    </div>
                                    <div class="activity-meta">
                                        Event: <?php echo htmlspecialchars((string)$entry['event_title']); ?>
                                        <?php if ($kind !== 'message'): ?>
                                            | Service: <?php echo htmlspecialchars((string)$entry['context_label']); ?>
                                            | Amount: KES <?php echo number_format((float)$entry['amount'], 2); ?>
                                        <?php else: ?>
                                            | <?php echo htmlspecialchars((string)$entry['context_label']); ?>
                                        <?php endif; ?>
                                    </div>
                                    <a class="activity-link" href="<?php echo htmlspecialchars($actionLink); ?>"><?php echo htmlspecialchars($actionText); ?></a>
                                </article>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="card">
                <h3 class="card-title">
                    <i class="fa-regular fa-calendar-check" style="color: #6C63FF;"></i> Upcoming Events
                </h3>
                <div class="list-container">
                    <?php if (empty($events)): ?>
                        <div class="empty-state">No events created yet.</div>
                    <?php else: ?>
                        <?php foreach ($events as $event): ?>
                            <?php
                                $eventId = (int)$event['event_id'];
                                $ops = $eventOpsById[$eventId] ?? [
                                    'total_bookings' => 0,
                                    'pending_bookings' => 0,
                                    'pending_payments' => 0,
                                    'failed_payments' => 0,
                                    'item_spent_total' => 0.0,
                                    'manual_cash_available' => 0.0,
                                    'sponsorship_received' => 0.0,
                                    'stall_revenue_received' => 0.0,
                                    'vendor_revenue_received' => 0.0,
                                    'unread_vendor_messages' => 0,
                                ];
                                $availableBudget = (float)$event['budget_total'] - (float)$event['budget_committed'];
                                $availableFundsReceived = (float)$event['ticket_revenue']
                                    + (float)($ops['vendor_revenue_received'] ?? 0)
                                    + (float)($ops['stall_revenue_received'] ?? 0)
                                    + (float)($ops['sponsorship_received'] ?? 0)
                                    + (float)($ops['manual_cash_available'] ?? 0);
                                $eventSpentTotal = (float)($ops['paid_transactions_total'] ?? 0) + (float)($ops['item_spent_total'] ?? 0);
                                $eventFundingGap = max(0, $eventSpentTotal - (float)$event['budget_total']);
                                $eventFundingBuffer = max(0, (float)$event['budget_total'] - $eventSpentTotal);
                                $eventFundingOverrun = $eventSpentTotal > (float)$event['budget_total'];
                                $isHealthy = !$eventFundingOverrun
                                    && (int)$ops['pending_bookings'] === 0
                                    && (int)$ops['pending_payments'] === 0
                                    && (int)$ops['failed_payments'] === 0
                                    && (int)$ops['unread_vendor_messages'] === 0;
                            ?>
                            <div class="list-item">
                                <div class="event-main">
                                    <span class="event-title-line"><?php echo htmlspecialchars($event['title']); ?> (<?php echo $event['event_date']; ?>) &ndash; Budget: <strong>KES <?php echo number_format($event['budget_total'], 2); ?></strong></span>
                                    <?php if ($eventFundingOverrun): ?>
                                        <div class="event-funding-alert danger">
                                            <i class="fa-solid fa-triangle-exclamation"></i>
                                            Overrun: money spent above budget by KES <?php echo number_format($eventFundingGap, 2); ?>
                                        </div>
                                    <?php else: ?>
                                        <div class="event-funding-alert success">
                                            <i class="fa-solid fa-circle-check"></i>
                                            Good: spending is within budget by KES <?php echo number_format($eventFundingBuffer, 2); ?>
                                        </div>
                                    <?php endif; ?>
                                    <div class="event-chips">
                                        <?php if ($eventFundingOverrun): ?><span class="chip chip-risk">Budget Overrun</span><?php endif; ?>
                                        <?php if ((int)$ops['failed_payments'] > 0): ?><span class="chip chip-risk">Failed Payments: <?php echo (int)$ops['failed_payments']; ?></span><?php endif; ?>
                                        <?php if ((int)$ops['pending_payments'] > 0): ?><span class="chip chip-warn">Pending Payments: <?php echo (int)$ops['pending_payments']; ?></span><?php endif; ?>
                                        <?php if ((int)$ops['pending_bookings'] > 0): ?><span class="chip chip-info">Pending Bookings: <?php echo (int)$ops['pending_bookings']; ?></span><?php endif; ?>
                                        <?php if ((int)$ops['unread_vendor_messages'] > 0): ?><span class="chip chip-info">Unread Updates: <?php echo (int)$ops['unread_vendor_messages']; ?></span><?php endif; ?>
                                        <?php if ($isHealthy): ?><span class="chip chip-good">In Sync</span><?php endif; ?>
                                    </div>
                                </div>
                                <div class="event-actions">
                                    <a href="create_event.php">Details</a>
                                    <a href="budget_breakdown.php?event_id=<?php echo $eventId; ?>">Budget Breakdown</a>
                                    <a href="mpesa_payments.php">Payments</a>
                                    <a href="messages.php?unread=1">Messages</a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <div class="card">
                <h3 class="card-title">
                    <i class="fa-regular fa-list-alt" style="color: #6C63FF;"></i> Recent Bookings
                </h3>
                <div class="list-container">
                    <?php if (empty($recentBookings)): ?>
                        <div class="empty-state">No bookings yet.</div>
                    <?php else: ?>
                        <?php foreach ($recentBookings as $b): ?>
                            <div class="list-item">
                                <span>
                                    <?php echo htmlspecialchars($b['business_name']); ?> &mdash; <?php echo htmlspecialchars($b['service_name']); ?>
                                    (Status: <span class="status-badge <?php echo strtolower($b['status']); ?>"><?php echo ucfirst($b['status']); ?></span>)
                                    <?php if (strtolower((string)$b['status']) === 'pending'): ?>
                                        &middot; <a href="initiate_payment.php?booking_id=<?php echo (int)$b['booking_id']; ?>">Pay Now</a>
                                    <?php endif; ?>
                                </span>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

        </main>
    </div>

    <nav class="bottom-nav">
        <a href="dashboard.php" class="bottom-nav-item active">
            <i class="fa-solid fa-house"></i>
            <span>Home</span>
        </a>
        <a href="create_event.php" class="bottom-nav-item">
            <i class="fa-solid fa-calendar-days"></i>
            <span>Events</span>
        </a>
        <a href="browse_vendors.php" class="bottom-nav-item">
            <i class="fa-solid fa-shop"></i>
            <span>Vendors</span>
        </a>
        <a href="messages.php" class="bottom-nav-item">
            <i class="fa-solid fa-comments"></i>
            <span>Messages</span>
            <?php if ($unreadMessages > 0): ?><span class="badge-unread"><?php echo $unreadMessages; ?></span><?php endif; ?>
        </a>
        <a href="mpesa_payments.php" class="bottom-nav-item">
            <i class="fa-solid fa-book-bookmark"></i>
            <span>M-Pesa</span>
        </a>
        <a href="../logout.php" class="bottom-nav-item">
            <i class="fa-solid fa-right-from-bracket"></i>
            <span>Logout</span>
        </a>
    </nav>

    <script>
        (function () {
            const budgetList = document.getElementById('event_budget_list');
            if (!budgetList) {
                return;
            }

            const items = Array.from(budgetList.querySelectorAll('[data-event-budget-item]'));
            const toggles = Array.from(budgetList.querySelectorAll('[data-event-budget-toggle]'));

            toggles.forEach((toggle) => {
                toggle.addEventListener('click', () => {
                    const currentItem = toggle.closest('[data-event-budget-item]');
                    if (!currentItem) {
                        return;
                    }

                    const isOpen = currentItem.classList.contains('open');

                    items.forEach((item) => {
                        item.classList.remove('open');
                    });

                    if (!isOpen) {
                        currentItem.classList.add('open');
                    }
                });
            });
        })();
    </script>

</body>
</html>

