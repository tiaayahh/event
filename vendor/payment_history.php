<?php
require_once '../includes/auth.php';
checkAuth();
requireRole('vendor');
requireVendorType('service_provider');

$rows = [];
$newPendingCount = 0;
$totalPaid = 0.0;

try {
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
                t.transaction_id,
                t.booking_id,
                t.mpesa_code,
                t.amount,
                t.status,
                t.created_at,
                e.title AS event_title,
                e.event_date,
                s.name AS service_name,
                p.full_name AS planner_name
             FROM transactions t
             JOIN bookings b ON b.booking_id = t.booking_id
             JOIN services s ON s.service_id = b.service_id
             JOIN events e ON e.event_id = b.event_id
             JOIN users p ON p.user_id = e.planner_id
             WHERE s.vendor_id = ?
             ORDER BY t.created_at DESC, t.transaction_id DESC"
        );
        $stmt->execute([$vendorId]);
        $rows = $stmt->fetchAll();

        foreach ($rows as $row) {
            if (strtolower((string)($row['status'] ?? 'pending')) === 'paid') {
                $totalPaid += (float)($row['amount'] ?? 0);
            }
        }
    }
} catch (Throwable $e) {
    error_log('vendor/payment_history.php error: ' . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="../assets/style.css">
    <title>Planora - Payment History</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; }
        body { background: #F5F5F5; color: #2D2D2D; padding-bottom: 80px; }
        .header { background: #6C63FF; color: #fff; padding: 14px 24px; display: flex; justify-content: space-between; align-items: center; }
        .brand-logo { font-size: 22px; font-weight: 700; }
        .logout-btn { background: rgba(255,255,255,0.25); color: #fff; border: 1px solid rgba(255,255,255,0.3); padding: 6px 14px; border-radius: 4px; text-decoration: none; font-size: 13px; }
        .container { max-width: 980px; margin: 0 auto; padding: 20px; }
        .card { background: #fff; border-radius: 8px; padding: 18px; box-shadow: 0 1px 3px rgba(0,0,0,.05); }
        .title { font-size: 18px; font-weight: 700; margin-bottom: 8px; }
        .subtitle { color: #666; font-size: 13px; margin-bottom: 12px; }
        .total { background: #eef3ff; border: 1px solid #d9e4ff; color: #2c4ea0; border-radius: 8px; padding: 10px 12px; font-size: 13px; margin-bottom: 12px; }
        .row { border: 1px solid #ececec; border-radius: 8px; padding: 12px; margin-bottom: 10px; background: #fafafa; }
        .row:last-child { margin-bottom: 0; }
        .meta { font-size: 13px; margin-bottom: 4px; }
        .pill { display: inline-block; padding: 2px 8px; border-radius: 999px; font-size: 11px; font-weight: 700; }
        .pending { background: #fff4df; color: #a36500; }
        .paid { background: #e8f9ef; color: #1c7a36; }
        .failed { background: #ffe8e8; color: #a22b2b; }
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
        <h2 class="title">Payment History</h2>
        <p class="subtitle">Planner payments received for your booked services.</p>
        <div class="total">Total Paid: <strong>KES <?php echo number_format($totalPaid, 2); ?></strong></div>

        <?php if (empty($rows)): ?>
            <p class="empty">No payment transactions recorded yet.</p>
        <?php else: ?>
            <?php foreach ($rows as $row): ?>
                <?php
                    $status = strtolower((string)($row['status'] ?? 'pending'));
                    $statusClass = $status === 'paid' ? 'paid' : ($status === 'failed' ? 'failed' : 'pending');
                ?>
                <div class="row">
                    <div class="meta"><strong><?php echo htmlspecialchars((string)$row['event_title']); ?></strong> (<?php echo htmlspecialchars((string)$row['event_date']); ?>)</div>
                    <div class="meta">Service: <?php echo htmlspecialchars((string)$row['service_name']); ?></div>
                    <div class="meta">Planner: <?php echo htmlspecialchars((string)$row['planner_name']); ?></div>
                    <div class="meta">Booking ID: <?php echo (int)$row['booking_id']; ?> | Transaction ID: <?php echo (int)$row['transaction_id']; ?></div>
                    <div class="meta">Amount: KES <?php echo number_format((float)$row['amount'], 2); ?> | Status: <span class="pill <?php echo $statusClass; ?>"><?php echo htmlspecialchars(ucfirst($status)); ?></span></div>
                    <div class="meta">M-Pesa Code: <?php echo htmlspecialchars((string)((($row['mpesa_code'] ?? '') !== '') ? $row['mpesa_code'] : 'Not captured')); ?></div>
                    <div class="meta">Date: <?php echo htmlspecialchars((string)$row['created_at']); ?></div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<nav class="bottom-nav">
    <a href="dashboard.php" class="nav-link"><i class="fa-solid fa-house"></i><span>Home</span></a>
    <a href="services.php" class="nav-link"><i class="fa-solid fa-bell-concierge"></i><span>Services</span></a>
    <a href="bookings.php" class="nav-link"><i class="fa-solid fa-book-open"></i><span>Bookings</span><?php if ($newPendingCount > 0): ?><span class="badge-unread"><?php echo $newPendingCount; ?></span><?php endif; ?></a>
    <a href="booking_history.php" class="nav-link"><i class="fa-solid fa-clock-rotate-left"></i><span>History</span></a>
    <a href="payment_history.php" class="nav-link active"><i class="fa-solid fa-money-bill-wave"></i><span>Payments</span></a>
    <a href="profile.php" class="nav-link"><i class="fa-solid fa-user"></i><span>Profile</span></a>
</nav>
</body>
</html>
