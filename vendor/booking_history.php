<?php
require_once '../includes/auth.php';
checkAuth();
requireRole('vendor');
requireVendorType('service_provider');

$rows = [];
$newPendingCount = 0;

function ensureServiceRatingsTable(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS service_ratings (
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
            INDEX idx_service_ratings_attendee (attendee_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $ready = true;
}

try {
    ensureServiceRatingsTable($pdo);

    $stmt = $pdo->prepare("SELECT vendor_id FROM vendors WHERE user_id = ? LIMIT 1");
    $stmt->execute([$_SESSION['user_id']]);
    $vendor = $stmt->fetch();

    if ($vendor) {
        $vendorId = (int)$vendor['vendor_id'];

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
            "SELECT
                b.booking_id,
                e.title AS event_title,
                e.event_date,
                s.name AS service_name,
                p.full_name AS planner_name,
                b.status AS booking_status,
                COALESCE(t.status, 'pending') AS payment_status,
                COALESCE(t.amount, 0) AS payment_amount,
                COALESCE(t.mpesa_code, '') AS mpesa_code,
                COALESCE(sr_avg.avg_rating, 0) AS avg_rating,
                COALESCE(sr_avg.rating_count, 0) AS rating_count,
                COALESCE(sr_feedback.feedback_preview, '') AS feedback_preview
             FROM bookings b
             JOIN events e ON b.event_id = e.event_id
             JOIN users p ON e.planner_id = p.user_id
             JOIN services s ON b.service_id = s.service_id
             LEFT JOIN transactions t ON t.booking_id = b.booking_id
             LEFT JOIN (
                SELECT service_id,
                       ROUND(AVG(rating), 1) AS avg_rating,
                       COUNT(*) AS rating_count
                FROM service_ratings
                GROUP BY service_id
             ) sr_avg ON sr_avg.service_id = s.service_id
             LEFT JOIN (
                SELECT y.service_id,
                       SUBSTRING_INDEX(
                           GROUP_CONCAT(y.feedback_line ORDER BY y.updated_at DESC SEPARATOR '\n'),
                           '\n',
                           3
                       ) AS feedback_preview
                FROM (
                    SELECT sr.service_id,
                           sr.updated_at,
                           CONCAT(u.full_name, ': ', sr.feedback) AS feedback_line
                    FROM service_ratings sr
                    JOIN attendees a ON a.attendee_id = sr.attendee_id
                    JOIN users u ON u.user_id = a.user_id
                    WHERE COALESCE(sr.feedback, '') <> ''
                ) y
                GROUP BY y.service_id
             ) sr_feedback ON sr_feedback.service_id = s.service_id
             WHERE s.vendor_id = ?
             ORDER BY e.event_date DESC, b.created_at DESC"
        );
        $stmt->execute([$vendorId]);
        $rows = $stmt->fetchAll();
    }
} catch (Throwable $e) {
    error_log('vendor/booking_history.php error: ' . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="../assets/style.css">
    <title>Planora - Booking History</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; }
        body { background: #F5F5F5; color: #2D2D2D; padding-bottom: 80px; }
        .header { background: #6C63FF; color: #fff; padding: 14px 24px; display: flex; justify-content: space-between; align-items: center; }
        .brand-logo { font-size: 22px; font-weight: 700; }
        .logout-btn { background: rgba(255,255,255,0.25); color: #fff; border: 1px solid rgba(255,255,255,0.3); padding: 6px 14px; border-radius: 4px; text-decoration: none; font-size: 13px; }
        .container { max-width: 980px; margin: 0 auto; padding: 20px; }
        .card { background: #fff; border-radius: 8px; padding: 18px; box-shadow: 0 1px 3px rgba(0,0,0,.05); }
        .title { font-size: 18px; font-weight: 700; margin-bottom: 10px; }
        .subtitle { color: #666; font-size: 13px; margin-bottom: 14px; }
        .booking { border: 1px solid #ececec; border-radius: 8px; padding: 12px; margin-bottom: 10px; background: #fafafa; }
        .booking:last-child { margin-bottom: 0; }
        .row { font-size: 13px; margin-bottom: 4px; }
        .label { color: #666; }
        .pill { display: inline-block; padding: 2px 8px; border-radius: 999px; font-size: 11px; font-weight: 700; }
        .pending { background: #fff4df; color: #a36500; }
        .paid { background: #e8f9ef; color: #1c7a36; }
        .failed { background: #ffe8e8; color: #a22b2b; }
        .rating { margin-top: 8px; border-top: 1px dashed #ddd; padding-top: 8px; }
        .feedback { margin-top: 6px; white-space: pre-line; color: #444; font-size: 12px; }
        .empty { color: #777; font-size: 14px; }
        .bottom-nav { position: fixed; bottom: 0; left: 0; right: 0; background: #2D2D2D; display: flex; justify-content: space-around; align-items: center; height: 65px; z-index: 999; }
        .nav-link { color: #fff; text-decoration: none; font-size: 12px; display: flex; flex-direction: column; align-items: center; gap: 4px; opacity: 0.8; flex: 1; position: relative; }
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
        <h2 class="title">Booking History</h2>
        <p class="subtitle">All bookings where planners hired your services, including payment and attendee rating context.</p>

        <?php if (empty($rows)): ?>
            <p class="empty">No booking history yet.</p>
        <?php else: ?>
            <?php foreach ($rows as $row): ?>
                <?php
                    $paymentStatus = strtolower((string)($row['payment_status'] ?? 'pending'));
                    $paymentDisplay = $paymentStatus === 'paid' ? 'confirmed' : 'pending';
                    $paymentClass = $paymentDisplay === 'confirmed' ? 'paid' : 'pending';
                ?>
                <div class="booking">
                    <div class="row"><strong><?php echo htmlspecialchars((string)$row['event_title']); ?></strong> (<?php echo htmlspecialchars((string)$row['event_date']); ?>)</div>
                    <div class="row"><span class="label">Service:</span> <?php echo htmlspecialchars((string)$row['service_name']); ?></div>
                    <div class="row"><span class="label">Planner:</span> <?php echo htmlspecialchars((string)$row['planner_name']); ?></div>
                    <div class="row"><span class="label">Booking Status:</span> <?php echo htmlspecialchars(ucfirst((string)$row['booking_status'])); ?></div>
                    <div class="row">
                        <span class="label">Payment:</span>
                        <span class="pill <?php echo $paymentClass; ?>"><?php echo htmlspecialchars(ucfirst($paymentDisplay)); ?></span>
                        &nbsp;KES <?php echo number_format((float)$row['payment_amount'], 2); ?>
                        <?php if ((string)($row['mpesa_code'] ?? '') !== ''): ?>
                            &nbsp;|&nbsp; Code: <?php echo htmlspecialchars((string)$row['mpesa_code']); ?>
                        <?php endif; ?>
                    </div>
                    <div class="rating">
                        <div class="row"><span class="label">Average Service Rating:</span> <?php echo number_format((float)$row['avg_rating'], 1); ?> / 5 (<?php echo (int)$row['rating_count']; ?> review<?php echo (int)$row['rating_count'] === 1 ? '' : 's'; ?>)</div>
                        <?php if (trim((string)($row['feedback_preview'] ?? '')) !== ''): ?>
                            <div class="feedback"><span class="label">Recent Reviews:</span>
<?php echo htmlspecialchars((string)$row['feedback_preview']); ?></div>
                        <?php else: ?>
                            <div class="feedback">No attendee review text yet for this service.</div>
                        <?php endif; ?>
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
    <a href="booking_history.php" class="nav-link active"><i class="fa-solid fa-clock-rotate-left"></i><span>History</span></a>
    <a href="payment_history.php" class="nav-link"><i class="fa-solid fa-money-bill-wave"></i><span>Payments</span></a>
    <a href="profile.php" class="nav-link"><i class="fa-solid fa-user"></i><span>Profile</span></a>
</nav>
</body>
</html>
