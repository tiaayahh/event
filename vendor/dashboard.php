<?php
require_once '../includes/auth.php';
checkAuth();
requireRole('vendor');

$fullName = $_SESSION['full_name'] ?? 'Vendor';
$pendingBookings = [];
$services = [];
$hasNewAlert = false;
$newPendingCount = 0;
$unreadConversations = 0;
$upcomingEvents = [];
$confirmedBookingsCount = 0;
$pendingBookingRequestsCount = 0;
$paymentStatusCounts = [
    'paid' => 0,
    'pending' => 0,
    'partial' => 0,
];
$notificationsCount = 0;
$eventCountdownText = 'No upcoming event countdown available yet.';
$isMarketOperator = false;
$marketEventCount = 0;
$stallPaidCount = 0;
$stallPendingCount = 0;
$flashSuccess = $_SESSION['flash_success'] ?? '';
$flashError = $_SESSION['flash_error'] ?? '';
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

function ensureVendorTypeSchema(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }

    $stmt = $pdo->query("SHOW COLUMNS FROM vendors LIKE 'vendor_type'");
    if (!$stmt->fetch()) {
        $pdo->exec("ALTER TABLE vendors ADD COLUMN vendor_type ENUM('service_provider','market_operator') NOT NULL DEFAULT 'service_provider' AFTER service_type");
    }

    $ready = true;
}

function ensureStallRentalsSchema(PDO $pdo): void
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
            status ENUM('requested', 'paid', 'failed', 'cancelled') NOT NULL DEFAULT 'requested',
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

function resolveEventImageUrl(?string $rawUrl): string
{
    $url = trim((string)$rawUrl);
    if ($url === '') {
        return '';
    }

    $url = str_replace('\\', '/', $url);

    if (preg_match('/^(https?:)?\/\//i', $url) === 1 || stripos($url, 'data:') === 0) {
        return $url;
    }

    if (strpos($url, '../') === 0 || strpos($url, './') === 0) {
        return $url;
    }

    if (strpos($url, '/') === 0) {
        return '..' . $url;
    }

    return '../' . ltrim($url, '/');
}

try {
    ensureVendorTypeSchema($pdo);

    $stmt = $pdo->prepare("SELECT vendor_id, COALESCE(vendor_type, 'service_provider') AS vendor_type FROM vendors WHERE user_id = ? LIMIT 1");
    $stmt->execute([$_SESSION['user_id']]);
    $vendor = $stmt->fetch();

    if (!$vendor) {
        $flashError = 'Vendor profile not found. Please complete vendor setup first.';
    } else {
        $vendorId = (int)$vendor['vendor_id'];
        $isMarketOperator = ((string)($vendor['vendor_type'] ?? 'service_provider')) === 'market_operator';

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS vendor_notification_state (
                vendor_id INT NOT NULL PRIMARY KEY,
                last_seen_pending_bookings_at DATETIME NULL,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                CONSTRAINT fk_vendor_notification_state_vendor FOREIGN KEY (vendor_id) REFERENCES vendors(vendor_id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

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
            try {
                $pdo->exec("ALTER TABLE messages ADD COLUMN is_read TINYINT(1) NOT NULL DEFAULT 0");
            } catch (Throwable $e) {
                if (stripos($e->getMessage(), 'Duplicate column name') === false) {
                    throw $e;
                }
            }
        }

        $stmt = $pdo->prepare("SELECT COUNT(DISTINCT planner_user_id) FROM messages WHERE vendor_user_id = ? AND sender_role = 'planner' AND is_read = 0");
        $stmt->execute([$_SESSION['user_id']]);
        $unreadConversations = (int)$stmt->fetchColumn();

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_pending_bookings_seen'])) {
            $stmt = $pdo->prepare(
                'INSERT INTO vendor_notification_state (vendor_id, last_seen_pending_bookings_at) VALUES (?, NOW())
                 ON DUPLICATE KEY UPDATE last_seen_pending_bookings_at = NOW()'
            );
            $stmt->execute([$vendorId]);
            $_SESSION['flash_success'] = 'Booking alerts marked as seen.';
            header('Location: dashboard.php');
            exit;
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_service_availability'], $_POST['service_id'])) {
            $serviceId = filter_input(INPUT_POST, 'service_id', FILTER_VALIDATE_INT);

            if (!$serviceId) {
                $_SESSION['flash_error'] = 'Invalid service selected.';
            } else {
                $newAvailability = isset($_POST['availability']) ? 1 : 0;
                $stmt = $pdo->prepare('UPDATE services SET availability = ? WHERE service_id = ? AND vendor_id = ?');
                $stmt->execute([$newAvailability, $serviceId, $vendorId]);

                audit_log(
                    $pdo,
                    (int)$_SESSION['user_id'],
                    (string)$_SESSION['role'],
                    'service.availability_update',
                    'service',
                    (string)$serviceId,
                    [
                        'vendor_id' => $vendorId,
                        'availability' => $newAvailability,
                        'source' => 'vendor_dashboard'
                    ]
                );

                $_SESSION['flash_success'] = 'Service availability updated.';
            }

            header('Location: dashboard.php');
            exit;
        }

        $stmt = $pdo->prepare('SELECT service_id, name, price, availability FROM services WHERE vendor_id = ? ORDER BY name ASC');
         $stmt->execute([$vendorId]);
        $services = $stmt->fetchAll();

        $stmt = $pdo->prepare(
            "SELECT b.booking_id, e.title AS event_title, e.event_date, COALESCE(e.image_url, '') AS image_url, s.name AS service_name, b.status, b.created_at
            FROM bookings b
            JOIN events e ON b.event_id = e.event_id
            JOIN services s ON b.service_id = s.service_id
            WHERE s.vendor_id = ? AND b.status = 'pending'
            ORDER BY b.created_at DESC"
        );
        $stmt->execute([$vendorId]);
        $pendingBookings = $stmt->fetchAll();

        $stmt = $pdo->prepare('SELECT last_seen_pending_bookings_at FROM vendor_notification_state WHERE vendor_id = ? LIMIT 1');
        $stmt->execute([$vendorId]);
        $lastSeenPendingAt = $stmt->fetchColumn();

        if ($lastSeenPendingAt) {
            $stmt = $pdo->prepare(
                "SELECT COUNT(*)
                FROM bookings b
                JOIN services s ON b.service_id = s.service_id
                WHERE s.vendor_id = ? AND b.status = 'pending' AND b.created_at > ?"
            );
            $stmt->execute([$vendorId, $lastSeenPendingAt]);
            $newPendingCount = (int)$stmt->fetchColumn();
        } else {
            $newPendingCount = count($pendingBookings);
        }

        $pendingBookingRequestsCount = count($pendingBookings);

        $stmt = $pdo->prepare(
            "SELECT COUNT(*)
             FROM bookings b
             JOIN services s ON b.service_id = s.service_id
             WHERE s.vendor_id = ? AND b.status = 'confirmed'"
        );
        $stmt->execute([$vendorId]);
        $confirmedBookingsCount = (int)$stmt->fetchColumn();

        $stmt = $pdo->prepare(
            "SELECT
                COALESCE(SUM(CASE
                    WHEN COALESCE(t.status, 'pending') = 'paid' AND COALESCE(t.amount, 0) >= b.booked_price THEN 1
                    ELSE 0
                END), 0) AS paid_count,
                COALESCE(SUM(CASE
                    WHEN COALESCE(t.status, 'pending') = 'paid' AND COALESCE(t.amount, 0) > 0 AND COALESCE(t.amount, 0) < b.booked_price THEN 1
                    ELSE 0
                END), 0) AS partial_count,
                COALESCE(SUM(CASE
                    WHEN COALESCE(t.status, 'pending') <> 'paid' THEN 1
                    WHEN COALESCE(t.status, 'pending') = 'paid' AND COALESCE(t.amount, 0) <= 0 THEN 1
                    ELSE 0
                END), 0) AS pending_count
             FROM bookings b
             JOIN services s ON b.service_id = s.service_id
             LEFT JOIN transactions t ON t.booking_id = b.booking_id
             WHERE s.vendor_id = ? AND b.status IN ('pending', 'confirmed')"
        );
        $stmt->execute([$vendorId]);
        $paymentStats = $stmt->fetch();
        if ($paymentStats) {
            $paymentStatusCounts = [
                'paid' => (int)($paymentStats['paid_count'] ?? 0),
                'pending' => (int)($paymentStats['pending_count'] ?? 0),
                'partial' => (int)($paymentStats['partial_count'] ?? 0),
            ];
        }

        $stmt = $pdo->prepare(
            "SELECT
                e.event_id,
                e.title,
                e.event_date,
                COALESCE(e.image_url, '') AS image_url,
                                COALESCE(e.venue, '') AS venue,
                                COALESCE(e.city, '') AS city,
                                COALESCE(e.category, '') AS category,
                                COALESCE(e.ticket_price, 0) AS ticket_price,
                                u.full_name AS organizer_name,
                COUNT(DISTINCT b.booking_id) AS booking_count,
                SUM(CASE WHEN b.status = 'confirmed' THEN 1 ELSE 0 END) AS confirmed_count
                         FROM events e
                         JOIN users u ON u.user_id = e.planner_id
                         LEFT JOIN bookings b ON b.event_id = e.event_id AND b.status IN ('pending', 'confirmed')
                         WHERE e.archived_at IS NULL
               AND e.event_date >= CURDATE()
                         GROUP BY e.event_id, e.title, e.event_date, e.image_url, e.venue, e.city, e.category, e.ticket_price, u.full_name
             ORDER BY e.event_date ASC"
        );
                $stmt->execute();
        $upcomingEvents = $stmt->fetchAll();

        if (!empty($upcomingEvents)) {
            $nextEvent = $upcomingEvents[0];
            $today = new DateTime('today');
            $eventDate = DateTime::createFromFormat('Y-m-d', (string)$nextEvent['event_date']);
            if (!$eventDate) {
                $eventDate = new DateTime((string)$nextEvent['event_date']);
            }
            $daysUntil = (int)$today->diff($eventDate)->format('%r%a');

            if ($daysUntil <= 0) {
                $eventCountdownText = (string)$nextEvent['title'] . ' starts today.';
            } elseif ($daysUntil === 1) {
                $eventCountdownText = (string)$nextEvent['title'] . ' starts in 1 day.';
            } else {
                $eventCountdownText = (string)$nextEvent['title'] . ' starts in ' . $daysUntil . ' days.';
            }
        }

        $hasNewAlert = $newPendingCount > 0;
        $notificationsCount = $newPendingCount + $unreadConversations;

        if ($isMarketOperator) {
            ensureStallRentalsSchema($pdo);

            $stmt = $pdo->query(
                "SELECT COUNT(*)
                 FROM events
                 WHERE archived_at IS NULL
                   AND event_date >= CURDATE()
                   AND LOWER(COALESCE(category, '')) LIKE '%market%'"
            );
            $marketEventCount = (int)$stmt->fetchColumn();

            $stmt = $pdo->prepare(
                "SELECT
                    COALESCE(SUM(CASE WHEN COALESCE(payment_status,
                        CASE LOWER(COALESCE(status, 'requested'))
                            WHEN 'paid' THEN 'paid'
                            WHEN 'failed' THEN 'failed'
                            WHEN 'cancelled' THEN 'cancelled'
                            ELSE 'pending'
                        END
                    ) = 'paid' THEN 1 ELSE 0 END), 0) AS paid_count,
                    COALESCE(SUM(CASE WHEN COALESCE(payment_status,
                        CASE LOWER(COALESCE(status, 'requested'))
                            WHEN 'paid' THEN 'paid'
                            WHEN 'failed' THEN 'failed'
                            WHEN 'cancelled' THEN 'cancelled'
                            ELSE 'pending'
                        END
                    ) = 'pending' THEN 1 ELSE 0 END), 0) AS pending_count
                 FROM stall_rentals
                 WHERE vendor_user_id = ?"
            );
            $stmt->execute([$_SESSION['user_id']]);
            $stallStats = $stmt->fetch();
            $stallPaidCount = (int)($stallStats['paid_count'] ?? 0);
            $stallPendingCount = (int)($stallStats['pending_count'] ?? 0);
        }
    }
} catch (Throwable $e) {
    error_log('vendor/dashboard.php error: ' . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="../assets/style.css">
    <title>Planora - Vendor Dashboard</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
        }

        body {
            background-color: #F5F5F5;
            color: #2D2D2D;
            padding-bottom: 80px;
        }

        .header {
            background-color: #6C63FF;
            color: white;
            padding: 14px 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .brand-logo {
            font-size: 22px;
            font-weight: 700;
            letter-spacing: 0.5px;
        }

        .logout-btn {
            background-color: rgba(255, 255, 255, 0.25);
            color: white;
            border: 1px solid rgba(255, 255, 255, 0.3);
            padding: 6px 14px;
            border-radius: 4px;
            text-decoration: none;
            font-size: 13px;
            font-weight: 500;
            transition: background 0.2s;
        }

        .logout-btn:hover {
            background-color: rgba(255, 255, 255, 0.35);
        }

        .container {
            max-width: 1100px;
            margin: 0 auto;
            padding: 20px;
        }

        .dashboard-card {
            background-color: #ffffff;
            border-radius: 6px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
        }

        .card-title {
            font-size: 16px;
            font-weight: 700;
            color: #1a1a1a;
            margin-bottom: 15px;
        }

        .card-subtitle {
            color: #666666;
            font-size: 13px;
            margin-top: -8px;
            margin-bottom: 12px;
        }

        .message {
            border-radius: 6px;
            padding: 10px 12px;
            margin-bottom: 14px;
            font-size: 13px;
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

        .alert-banner {
            background-color: #B8A8FF;
            color: #2D2D2D;
            padding: 14px 18px;
            border-radius: 6px;
            font-weight: 600;
            font-size: 14px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
        }

        .alert-message {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .alert-actions {
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .alert-action-btn {
            border: 1px solid #5f56d8;
            background: #ffffff;
            color: #4a42b5;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 700;
            padding: 5px 10px;
            cursor: pointer;
        }

        .availability-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .availability-form {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .service-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            padding: 12px 0;
            border-bottom: 1px solid #eeeeee;
        }

        .service-item:last-child {
            border-bottom: none;
            padding-bottom: 0;
        }

        .service-name {
            font-size: 14px;
            font-weight: 600;
            color: #222222;
        }

        .service-meta {
            color: #666666;
            font-size: 12px;
            margin-top: 4px;
        }

        .save-btn {
            background-color: #6C63FF;
            color: #ffffff;
            border: none;
            padding: 6px 12px;
            border-radius: 4px;
            font-size: 12px;
            cursor: pointer;
        }

        .switch {
            position: relative;
            display: inline-block;
            width: 46px;
            height: 24px;
        }

        .switch input { 
            opacity: 0;
            width: 0;
            height: 0;
        }

        .slider {
            position: absolute;
            cursor: pointer;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: #ffffff;
            border: 1px solid #777777;
            transition: .3s;
            border-radius: 24px;
        }

        .slider:before {
            position: absolute;
            content: "";
            height: 16px;
            width: 16px;
            left: 3px;
            bottom: 3px;
            background-color: #333333;
            transition: .3s;
            border-radius: 50%;
        }

        input:checked + .slider {
            background-color: #6C63FF;
            border-color: #6C63FF;
        }

        input:checked + .slider:before {
            transform: translateX(22px);
            background-color: #ffffff;
        }

        .booking-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 14px 0;
            font-size: 14px;
            color: #444444;
            border-bottom: 1px solid #eeeeee;
        }

        .booking-item:last-child {
            border-bottom: none;
            padding-bottom: 0;
        }

        .status-icon {
            color: #777777;
            font-size: 14px;
        }

        .bottom-nav {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            background-color: #2D2D2D;
            display: flex;
            justify-content: space-around;
            align-items: center;
            height: 65px;
            box-shadow: 0 -2px 10px rgba(0,0,0,0.1);
            z-index: 999;
        }

        .nav-link {
            color: #ffffff;
            text-decoration: none;
            font-size: 12px;
            font-weight: 500;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 6px;
            flex-grow: 1;
            height: 100%;
            transition: background-color 0.2s, opacity 0.2s;
            opacity: 0.85;
            position: relative;
        }

        .nav-link i {
            font-size: 18px;
        }

        .nav-link:hover, .nav-link.active {
            opacity: 1;
            background-color: rgba(255, 255, 255, 0.08);
        }

        .badge-unread {
            display: inline-block;
            min-width: 18px;
            height: 18px;
            border-radius: 999px;
            background: #ff6b6b;
            color: #fff;
            font-size: 11px;
            font-weight: 700;
            line-height: 18px;
            text-align: center;
            padding: 0 5px;
            position: absolute;
            top: 8px;
            right: 12px;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 10px;
        }

        .stat-chip {
            border: 1px solid #ececec;
            border-radius: 8px;
            background: #fafafa;
            padding: 10px 12px;
        }

        .stat-label {
            font-size: 12px;
            color: #666;
            margin-bottom: 5px;
            font-weight: 600;
        }

        .stat-value {
            font-size: 18px;
            font-weight: 700;
            color: #1f1f1f;
        }

        .quick-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }

        .quick-link {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            border: 1px solid #c9c2ff;
            background: #ece9ff;
            color: #3f379f;
            border-radius: 999px;
            padding: 7px 12px;
            text-decoration: none;
            font-size: 12px;
            font-weight: 700;
        }

        .event-list-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 1px solid #eeeeee;
            padding: 11px 0;
            gap: 10px;
        }

        .event-list-item:last-child {
            border-bottom: none;
            padding-bottom: 0;
        }

        .event-meta-small {
            font-size: 12px;
            color: #666;
            margin-top: 3px;
        }

        .event-item-main {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .event-thumb {
            width: 56px;
            height: 56px;
            border-radius: 8px;
            background: linear-gradient(135deg, #ece9ff, #d6d0ff);
            background-size: cover;
            background-position: center;
            flex-shrink: 0;
        }

        .cards {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
            gap: 10px;
        }

        .event-card {
            background: #fff;
            border: 1px solid #ece9ff;
            border-radius: 12px;
            padding: 12px;
        }

        .thumb {
            height: 88px;
            border-radius: 8px;
            background: linear-gradient(130deg, #6C63FF, #B8A8FF);
            margin-bottom: 9px;
            overflow: hidden;
        }

        .thumb img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
        }

        .event-title {
            font-size: 15px;
            font-weight: 700;
            margin-bottom: 4px;
        }

        .event-meta {
            font-size: 12px;
            color: #666;
            margin-bottom: 4px;
        }

        .action-row {
            display: flex;
            gap: 6px;
            flex-wrap: wrap;
            margin-top: 8px;
        }

        .btn-light {
            background: #ece9ff;
            color: #3f379f;
            border: none;
            border-radius: 10px;
            padding: 8px 11px;
            cursor: pointer;
            font-weight: 700;
            font-size: 12px;
            text-decoration: none;
            display: inline-block;
        }
    </style>
</head>
<body>

    <header class="header">
        <div class="brand-logo">PLANORA</div>
        <a href="../logout.php" class="logout-btn">Logout</a>
    </header>

    <div class="container">
        <p class="card-subtitle">Signed in as <?php echo htmlspecialchars($fullName); ?>.</p>

        <?php if ($flashSuccess !== ''): ?>
            <div class="message message-success"><?php echo htmlspecialchars($flashSuccess); ?></div>
        <?php endif; ?>

        <?php if ($flashError !== ''): ?>
            <div class="message message-error"><?php echo htmlspecialchars($flashError); ?></div>
        <?php endif; ?>
        
        <div class="dashboard-card">
            <h2 class="card-title">Booking Alerts</h2>
            <div class="alert-banner">
                <div class="alert-message">
                    <i class="fa-solid fa-bell"></i>
                    <?php echo $hasNewAlert ? ('New pending bookings: ' . (int)$newPendingCount) : 'No new pending bookings.'; ?>
                </div>
                <?php if ($hasNewAlert): ?>
                    <form method="POST" class="alert-actions">
                        <?php echo csrf_input(); ?>
                        <input type="hidden" name="mark_pending_bookings_seen" value="1">
                        <button type="submit" class="alert-action-btn">Mark as seen</button>
                    </form>
                <?php endif; ?>
            </div>
        </div>

        <div class="dashboard-card availability-row">
            <h2 class="card-title" style="margin-bottom: 0;">Service Availability</h2>
        </div>

        <div class="dashboard-card">
            <h2 class="card-title">Vendor Overview</h2>
            <p class="card-subtitle"><?php echo htmlspecialchars($eventCountdownText); ?></p>
            <div class="stats-grid">
                <div class="stat-chip">
                    <div class="stat-label">Upcoming Events</div>
                    <div class="stat-value"><?php echo count($upcomingEvents); ?></div>
                </div>
                <div class="stat-chip">
                    <div class="stat-label">Confirmed Bookings</div>
                    <div class="stat-value"><?php echo (int)$confirmedBookingsCount; ?></div>
                </div>
                <div class="stat-chip">
                    <div class="stat-label">Pending Booking Requests</div>
                    <div class="stat-value"><?php echo (int)$pendingBookingRequestsCount; ?></div>
                </div>
                <div class="stat-chip">
                    <div class="stat-label">Notifications</div>
                    <div class="stat-value"><?php echo (int)$notificationsCount; ?></div>
                </div>
            </div>
        </div>

        <div class="dashboard-card">
            <h2 class="card-title">Payment Status</h2>
            <div class="stats-grid">
                <div class="stat-chip">
                    <div class="stat-label">Paid</div>
                    <div class="stat-value"><?php echo (int)$paymentStatusCounts['paid']; ?></div>
                </div>
                <div class="stat-chip">
                    <div class="stat-label">Pending</div>
                    <div class="stat-value"><?php echo (int)$paymentStatusCounts['pending']; ?></div>
                </div>
                <div class="stat-chip">
                    <div class="stat-label">Partially Paid</div>
                    <div class="stat-value"><?php echo (int)$paymentStatusCounts['partial']; ?></div>
                </div>
            </div>
        </div>

        <div class="dashboard-card">
            <h2 class="card-title">Quick Actions</h2>
            <div class="quick-actions">
                <a href="schedule.php" class="quick-link"><i class="fa-solid fa-calendar-days"></i> View Schedule</a>
                <a href="messages.php?unread=1" class="quick-link"><i class="fa-solid fa-comments"></i> View Messages</a>
                <a href="services.php" class="quick-link"><i class="fa-solid fa-bell-concierge"></i> Update Services</a>
                <a href="download_pass.php" class="quick-link"><i class="fa-solid fa-id-badge"></i> Download Vendor Pass</a>
            </div>
        </div>

        <?php if (!$isMarketOperator): ?>
            <div class="dashboard-card">
                <h2 class="card-title">Service Provider Payments</h2>
                <p class="card-subtitle">You are paid by planners for confirmed bookings. No vendor fee is required.</p>
            </div>
        <?php endif; ?>

        <?php if ($isMarketOperator): ?>
            <div class="dashboard-card">
                <h2 class="card-title">Register for Vendor Markets</h2>
                <p class="card-subtitle">Reserve a stall for upcoming market events and pay the stall rental fee via M-Pesa.</p>
                <div class="stats-grid" style="margin-bottom:12px;">
                    <div class="stat-chip">
                        <div class="stat-label">Market Events Open</div>
                        <div class="stat-value"><?php echo (int)$marketEventCount; ?></div>
                    </div>
                    <div class="stat-chip">
                        <div class="stat-label">Stalls Paid</div>
                        <div class="stat-value"><?php echo (int)$stallPaidCount; ?></div>
                    </div>
                    <div class="stat-chip">
                        <div class="stat-label">Stalls Pending</div>
                        <div class="stat-value"><?php echo (int)$stallPendingCount; ?></div>
                    </div>
                </div>
                <a href="stall_registration.php" class="save-btn" style="display:inline-block; text-decoration:none;">Open Stall Registration</a>
            </div>
        <?php endif; ?>

        <div class="dashboard-card">
            <?php if (empty($services)): ?>
                <div class="booking-item">
                    <span>No services found. <a href="services.php">Add a service</a> to control availability.</span>
                </div>
            <?php else: ?>
                <?php foreach ($services as $service): ?>
                    <div class="service-item">
                        <div>
                            <div class="service-name"><?php echo htmlspecialchars($service['name']); ?></div>
                            <div class="service-meta">Price: KES <?php echo number_format((float)$service['price'], 2); ?></div>
                        </div>
                        <form method="POST" class="availability-form">
                            <?php echo csrf_input(); ?>
                            <input type="hidden" name="toggle_service_availability" value="1">
                            <input type="hidden" name="service_id" value="<?php echo (int)$service['service_id']; ?>">
                            <label class="switch">
                                <input type="checkbox" name="availability" <?php echo (int)$service['availability'] === 1 ? 'checked' : ''; ?>>
                                <span class="slider"></span>
                            </label>
                            <button type="submit" class="save-btn">Save</button>
                        </form>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <div class="dashboard-card">
            <h2 class="card-title">Upcoming Events</h2>
            <?php if (empty($upcomingEvents)): ?>
                <div class="booking-item">
                    <span>No upcoming planner events available right now.</span>
                </div>
            <?php else: ?>
                <div class="cards">
                    <?php foreach ($upcomingEvents as $upcoming): ?>
                        <?php $upcomingImage = resolveEventImageUrl((string)($upcoming['image_url'] ?? '')); ?>
                        <div class="event-card">
                            <div class="thumb">
                                <?php if ($upcomingImage !== ''): ?>
                                    <img src="<?php echo htmlspecialchars($upcomingImage); ?>" alt="Event banner">
                                <?php endif; ?>
                            </div>
                            <div class="event-title"><?php echo htmlspecialchars((string)$upcoming['title']); ?></div>
                            <div class="event-meta"><?php echo htmlspecialchars((string)$upcoming['event_date']); ?> &middot; <?php echo htmlspecialchars((string)(($upcoming['venue'] ?? '') !== '' ? $upcoming['venue'] : 'Venue TBA')); ?></div>
                            <div class="event-meta">Organizer: <?php echo htmlspecialchars((string)($upcoming['organizer_name'] ?? 'Organizer')); ?></div>
                            <div class="event-meta">Category: <?php echo htmlspecialchars((string)(($upcoming['category'] ?? '') !== '' ? $upcoming['category'] : 'General')); ?><?php if ((string)($upcoming['city'] ?? '') !== ''): ?> &middot; <?php echo htmlspecialchars((string)$upcoming['city']); ?><?php endif; ?></div>
                            <div class="event-meta">Bookings: <?php echo (int)$upcoming['booking_count']; ?> &middot; Confirmed: <?php echo (int)$upcoming['confirmed_count']; ?></div>
                            <div class="action-row">
                                <a class="btn-light" href="schedule.php">View Schedule</a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="dashboard-card">
            <h2 class="card-title">Pending Bookings</h2>

            <?php if (empty($pendingBookings)): ?>
                <div class="booking-item">
                    <span>No pending bookings.</span>
                </div>
            <?php else: ?>
                <?php foreach ($pendingBookings as $booking): ?>
                    <?php $pendingImage = resolveEventImageUrl((string)($booking['image_url'] ?? '')); ?>
                    <div class="booking-item">
                        <div class="event-item-main">
                            <div class="event-thumb"<?php if ($pendingImage !== ''): ?> style="background-image:url('<?php echo htmlspecialchars($pendingImage); ?>');"<?php endif; ?>></div>
                            <span>
                                <?php
                                echo htmlspecialchars($booking['event_title']) . ', ' .
                                     htmlspecialchars($booking['service_name']) . ', ' .
                                     htmlspecialchars($booking['event_date']) . ', ' .
                                     htmlspecialchars(ucfirst($booking['status']));
                                ?>
                            </span>
                        </div>
                        <i class="fa-regular fa-hourglass-half status-icon"></i>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

    </div>

    <nav class="bottom-nav">
        <a href="dashboard.php" class="nav-link active">
            <i class="fa-solid fa-house"></i>
            <span>Home</span>
        </a>
        <a href="messages.php?unread=1" class="nav-link">
            <i class="fa-solid fa-comments"></i>
            <span>Messages</span>
            <?php if ($unreadConversations > 0): ?><span class="badge-unread"><?php echo $unreadConversations; ?></span><?php endif; ?>
        </a>
        <a href="services.php" class="nav-link">
            <i class="fa-solid fa-bell-concierge"></i>
            <span>Services</span>
        </a>
        <a href="bookings.php" class="nav-link">
            <i class="fa-solid fa-book-open"></i>
            <span>Bookings</span>
            <?php if ($newPendingCount > 0): ?><span class="badge-unread"><?php echo $newPendingCount; ?></span><?php endif; ?>
        </a>
        <a href="schedule.php" class="nav-link">
            <i class="fa-solid fa-calendar-days"></i>
            <span>Schedule</span>
        </a>
        <a href="profile.php" class="nav-link">
            <i class="fa-solid fa-user"></i>
            <span>Profile</span>
        </a>
    </nav>

</body>
</html>

