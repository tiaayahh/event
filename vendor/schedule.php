<?php
require_once '../includes/auth.php';
checkAuth();
requireRole('vendor');

$schedule = [];
$newPendingCount = 0;
$isMarketOperator = false;

try {
    $stmt = $pdo->prepare("SELECT vendor_id, COALESCE(vendor_type, 'service_provider') AS vendor_type FROM vendors WHERE user_id = ? LIMIT 1");
    $stmt->execute([$_SESSION['user_id']]);
    $vendor = $stmt->fetch();

    if ($vendor) {
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
        } else {
            $stmt = $pdo->prepare(
                "SELECT COUNT(*)
                 FROM bookings b
                 JOIN services s ON b.service_id = s.service_id
                 WHERE s.vendor_id = ? AND b.status = 'pending'"
            );
            $stmt->execute([$vendorId]);
        }
        $newPendingCount = (int)$stmt->fetchColumn();

        $stmt = $pdo->prepare(
            "SELECT e.title AS event_title, e.event_date, s.name AS service_name, b.status
            FROM bookings b
            JOIN events e ON b.event_id = e.event_id
            JOIN services s ON b.service_id = s.service_id
            WHERE s.vendor_id = ?
            ORDER BY e.event_date ASC"
        );
        $stmt->execute([$vendorId]);
        $schedule = $stmt->fetchAll();
    }
} catch (Throwable $e) {
    error_log('vendor/schedule.php error: ' . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="../assets/style.css">
    <title>Planora - My Schedule</title>
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
        .brand-logo { font-size: 22px; font-weight: 700; }
        .logout-btn {
            background: rgba(255,255,255,0.25);
            color: white;
            padding: 6px 14px;
            border-radius: 4px;
            text-decoration: none;
            font-size: 13px;
        }
        .container {
            max-width: 800px;
            margin: 0 auto;
            padding: 20px;
        }
        .card {
            background: white;
            border-radius: 6px;
            padding: 20px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
        }
        .card-title {
            font-size: 18px;
            font-weight: 700;
            margin-bottom: 15px;
        }
        .schedule-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 0;
            border-bottom: 1px solid #eee;
        }
        .schedule-item:last-child { border-bottom: none; }
        .status-confirmed { color: #2ecc71; }
        .status-pending { color: #f39c12; }
        .bottom-nav {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            background: #2D2D2D;
            display: flex;
            justify-content: space-around;
            align-items: center;
            height: 65px;
            z-index: 999;
        }
        .nav-link {
            color: white;
            text-decoration: none;
            font-size: 12px;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 4px;
            opacity: 0.8;
            flex: 1;
            position: relative;
        }
        .nav-link i { font-size: 18px; }
        .nav-link.active { opacity: 1; background: rgba(255,255,255,0.08); }
        .badge-unread { display: inline-block; min-width: 18px; height: 18px; border-radius: 999px; background: #e74c3c; color: #fff; font-size: 11px; font-weight: 700; line-height: 18px; text-align: center; padding: 0 5px; position: absolute; top: 8px; right: 12px; }
    </style>
</head>
<body>
    <header class="header">
        <div class="brand-logo">PLANORA</div>
        <a href="../logout.php" class="logout-btn">Logout</a>
    </header>

    <div class="container">
        <div class="card">
            <h2 class="card-title">My Schedule</h2>
            <?php if (empty($schedule)): ?>
                <p>No bookings in your schedule.</p>
            <?php else: ?>
                <?php foreach ($schedule as $item): ?>
                    <div class="schedule-item">
                        <div>
                            <strong><?php echo htmlspecialchars($item['service_name']); ?></strong> &mdash;
                            <?php echo htmlspecialchars($item['event_title']); ?>
                        </div>
                        <div>
                            <span><?php echo $item['event_date']; ?></span>
                            <span class="<?php echo $item['status'] === 'confirmed' ? 'status-confirmed' : 'status-pending'; ?>">
                                (<?php echo ucfirst($item['status']); ?>)
                            </span>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <nav class="bottom-nav">
        <a href="dashboard.php" class="nav-link"><i class="fa-solid fa-house"></i><span>Home</span></a>
        <a href="services.php" class="nav-link"><i class="fa-solid fa-bell-concierge"></i><span>Services</span></a>
        <a href="bookings.php" class="nav-link"><i class="fa-solid fa-book-open"></i><span>Bookings</span><?php if ($newPendingCount > 0): ?><span class="badge-unread"><?php echo $newPendingCount; ?></span><?php endif; ?></a>
        <?php if ($isMarketOperator): ?>
            <a href="pay_fee.php" class="nav-link"><i class="fa-solid fa-wallet"></i><span>Fees</span></a>
            <a href="schedule.php" class="nav-link active"><i class="fa-solid fa-calendar-days"></i><span>Schedule</span></a>
        <?php else: ?>
            <a href="booking_history.php" class="nav-link"><i class="fa-solid fa-clock-rotate-left"></i><span>History</span></a>
            <a href="payment_history.php" class="nav-link"><i class="fa-solid fa-money-bill-wave"></i><span>Payments</span></a>
        <?php endif; ?>
        <a href="profile.php" class="nav-link"><i class="fa-solid fa-user"></i><span>Profile</span></a>
    </nav>
</body>
</html>

