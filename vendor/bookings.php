<?php
require_once '../includes/auth.php';
checkAuth();
requireRole('vendor');

$fullName = $_SESSION['full_name'] ?? 'Vendor';
$bookings = [];
$newPendingCount = 0;
$statusFilter = strtolower(trim((string)($_GET['status'] ?? 'all')));
if (!in_array($statusFilter, ['all', 'pending', 'confirmed'], true)) {
    $statusFilter = 'all';
}

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

        $stmt = $pdo->prepare(
            'INSERT INTO vendor_notification_state (vendor_id, last_seen_pending_bookings_at) VALUES (?, NOW())
             ON DUPLICATE KEY UPDATE last_seen_pending_bookings_at = NOW()'
        );
        $stmt->execute([$vendorId]);

        // Opening bookings marks current pending items as seen, so only future pending items are "new".
        $stmt = $pdo->prepare(
            "SELECT COUNT(*)
             FROM bookings b
             JOIN services s ON b.service_id = s.service_id
             JOIN vendor_notification_state vns ON vns.vendor_id = s.vendor_id
             WHERE s.vendor_id = ? AND b.status = 'pending' AND b.created_at > vns.last_seen_pending_bookings_at"
        );
        $stmt->execute([$vendorId]);
        $newPendingCount = (int)$stmt->fetchColumn();

        $sql = "SELECT b.booking_id, b.status AS booking_status, b.booked_price, b.platform_fee,
                    e.title AS event_title, e.event_date, s.name AS service_name,
                    t.mpesa_code, t.status AS payment_status
                FROM bookings b
                JOIN events e ON b.event_id = e.event_id
                JOIN services s ON b.service_id = s.service_id
                LEFT JOIN transactions t ON b.booking_id = t.booking_id
                WHERE s.vendor_id = ?";
        $params = [$vendorId];

        if ($statusFilter === 'pending' || $statusFilter === 'confirmed') {
            $sql .= " AND b.status = ?";
            $params[] = $statusFilter;
        } else {
            $sql .= " AND b.status IN ('pending', 'confirmed')";
        }

        $sql .= " ORDER BY e.event_date ASC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $bookings = $stmt->fetchAll();
    }
} catch (Throwable $e) {
    error_log('vendor/bookings.php error: ' . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="../assets/style.css">
    <title>Planora - Confirmed Bookings</title>
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
            margin-bottom: 20px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
        }
        .card-title {
            font-size: 18px;
            font-weight: 700;
            margin-bottom: 15px;
        }
        .booking-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 0;
            border-bottom: 1px solid #eee;
        }
        .booking-row:last-child { border-bottom: none; }
        .status-booking {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 999px;
            font-size: 11px;
            font-weight: 700;
            margin-left: 6px;
            vertical-align: middle;
        }
        .status-booking.confirmed { background: #e8f9ef; color: #1c7a36; }
        .status-booking.pending { background: #fff4df; color: #a36500; }
        .status-paid { color: #2ecc71; }
        .status-pending { color: #f39c12; }
        .filter-row {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            margin-bottom: 12px;
        }
        .filter-link {
            text-decoration: none;
            font-size: 12px;
            font-weight: 600;
            color: #4b4b4b;
            border: 1px solid #e5e5e5;
            background: #fafafa;
            padding: 6px 10px;
            border-radius: 999px;
        }
        .filter-link.active {
            background: #ece9ff;
            border-color: #c9c2ff;
            color: #3f379f;
        }
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
            <h2 class="card-title">Bookings</h2>
            <div class="filter-row">
                <a class="filter-link <?php echo $statusFilter === 'all' ? 'active' : ''; ?>" href="bookings.php?status=all">All</a>
                <a class="filter-link <?php echo $statusFilter === 'pending' ? 'active' : ''; ?>" href="bookings.php?status=pending">Pending</a>
                <a class="filter-link <?php echo $statusFilter === 'confirmed' ? 'active' : ''; ?>" href="bookings.php?status=confirmed">Confirmed</a>
            </div>
            <?php if (empty($bookings)): ?>
                <?php if ($statusFilter === 'pending'): ?>
                    <p>No pending bookings yet.</p>
                <?php elseif ($statusFilter === 'confirmed'): ?>
                    <p>No confirmed bookings yet.</p>
                <?php else: ?>
                    <p>No pending or confirmed bookings yet.</p>
                <?php endif; ?>
            <?php else: ?>
                <?php foreach ($bookings as $b): ?>
                    <div class="booking-row">
                        <div>
                            <strong><?php echo htmlspecialchars($b['service_name']); ?></strong> &mdash;
                            <?php echo htmlspecialchars($b['event_title']); ?> (<?php echo $b['event_date']; ?>)
                            <span class="status-booking <?php echo htmlspecialchars((string)$b['booking_status']); ?>"><?php echo htmlspecialchars(ucfirst((string)$b['booking_status'])); ?></span>
                        </div>
                        <div>
                            <?php
                            $gross = (float)$b['booked_price'];
                            $fee = (float)($b['platform_fee'] ?? 0);
                            $net = $gross - $fee;
                            ?>
                            <span>KES <?php echo number_format($net, 2); ?> (net)</span>
                            <br><small style="color:#777;">Fee: KES <?php echo number_format($fee, 2); ?></small>
                            <br><small class="<?php echo (($b['payment_status'] ?? 'pending') === 'paid') ? 'status-paid' : 'status-pending'; ?>">Payment: <?php echo htmlspecialchars(ucfirst((string)($b['payment_status'] ?? 'pending'))); ?></small>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <nav class="bottom-nav">
        <a href="dashboard.php" class="nav-link"><i class="fa-solid fa-house"></i><span>Home</span></a>
        <a href="services.php" class="nav-link"><i class="fa-solid fa-bell-concierge"></i><span>Services</span></a>
        <a href="bookings.php" class="nav-link active"><i class="fa-solid fa-book-open"></i><span>Bookings</span><?php if ($newPendingCount > 0): ?><span class="badge-unread"><?php echo $newPendingCount; ?></span><?php endif; ?></a>
        <a href="schedule.php" class="nav-link"><i class="fa-solid fa-calendar-days"></i><span>Schedule</span></a>
        <a href="profile.php" class="nav-link"><i class="fa-solid fa-user"></i><span>Profile</span></a>
    </nav>
</body>
</html>

