<?php
require_once '../includes/auth.php';
checkAuth();
requireRole('vendor');

$bookingId = filter_input(INPUT_GET, 'booking_id', FILTER_VALIDATE_INT);
if (!$bookingId) {
    $_SESSION['flash_error'] = 'Invalid booking selected.';
    header('Location: bookings.php');
    exit;
}

$booking = null;
$newPendingCount = 0;

function ensureBookingDisplaySchema(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }

    $stmt = $pdo->query("SHOW COLUMNS FROM events LIKE 'venue'");
    if (!$stmt->fetch()) {
        $pdo->exec("ALTER TABLE events ADD COLUMN venue VARCHAR(190) DEFAULT NULL AFTER event_date");
    }

    $stmt = $pdo->query("SHOW COLUMNS FROM bookings LIKE 'booth_number'");
    if (!$stmt->fetch()) {
        $pdo->exec("ALTER TABLE bookings ADD COLUMN booth_number VARCHAR(64) DEFAULT NULL AFTER platform_fee");
    }

    $ready = true;
}

try {
    $stmt = $pdo->prepare('SELECT vendor_id FROM vendors WHERE user_id = ? LIMIT 1');
    $stmt->execute([$_SESSION['user_id']]);
    $vendorId = (int)($stmt->fetchColumn() ?: 0);

    if ($vendorId <= 0) {
        throw new RuntimeException('Vendor profile not found.');
    }

    ensureBookingDisplaySchema($pdo);

    $stmt = $pdo->prepare(
        "SELECT b.booking_id, b.status AS booking_status, b.booked_price, b.platform_fee, b.booth_number,
                e.title AS event_title, e.event_date, COALESCE(e.venue, '') AS event_venue,
                s.name AS service_name,
                u.full_name AS organizer_name,
                COALESCE(t.status, 'pending') AS payment_status,
                COALESCE(t.amount, 0) AS paid_amount,
                t.mpesa_code
         FROM bookings b
         JOIN services s ON s.service_id = b.service_id
         JOIN events e ON e.event_id = b.event_id
         JOIN users u ON u.user_id = e.planner_id
         LEFT JOIN transactions t ON t.booking_id = b.booking_id
         WHERE b.booking_id = ? AND s.vendor_id = ?
         LIMIT 1"
    );
    $stmt->execute([$bookingId, $vendorId]);
    $booking = $stmt->fetch();

    if (!$booking) {
        $_SESSION['flash_error'] = 'Booking not found.';
        header('Location: bookings.php');
        exit;
    }

    $stmt = $pdo->prepare(
        "SELECT COUNT(*)
         FROM bookings b
         JOIN services s ON b.service_id = s.service_id
         WHERE s.vendor_id = ? AND b.status = 'pending'"
    );
    $stmt->execute([$vendorId]);
    $newPendingCount = (int)$stmt->fetchColumn();
} catch (Throwable $e) {
    error_log('vendor/event_details.php error: ' . $e->getMessage());
    $_SESSION['flash_error'] = 'Unable to load event details right now.';
    header('Location: bookings.php');
    exit;
}

$gross = (float)$booking['booked_price'];
$fee = (float)($booking['platform_fee'] ?? 0);
$net = $gross - $fee;
$rawPaymentStatus = strtolower((string)($booking['payment_status'] ?? 'pending'));
$paidAmount = (float)($booking['paid_amount'] ?? 0);
$displayPaymentStatus = 'Pending';
if ($rawPaymentStatus === 'paid' && $paidAmount > 0 && $paidAmount < $gross) {
    $displayPaymentStatus = 'Partially Paid';
} elseif ($rawPaymentStatus === 'paid') {
    $displayPaymentStatus = 'Paid';
} elseif ($rawPaymentStatus === 'failed') {
    $displayPaymentStatus = 'Failed';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="../assets/style.css">
    <title>Planora - Event Details</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; }
        body { background-color: #F5F5F5; color: #2D2D2D; padding-bottom: 80px; }
        .header { background-color: #6C63FF; color: white; padding: 14px 24px; display: flex; justify-content: space-between; align-items: center; }
        .brand-logo { font-size: 22px; font-weight: 700; }
        .logout-btn { background: rgba(255,255,255,0.25); color: white; padding: 6px 14px; border-radius: 4px; text-decoration: none; font-size: 13px; }
        .container { max-width: 800px; margin: 0 auto; padding: 20px; }
        .card { background: white; border-radius: 6px; padding: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.05); }
        .card-title { font-size: 18px; font-weight: 700; margin-bottom: 14px; }
        .row { padding: 10px 0; border-bottom: 1px solid #eee; display: flex; justify-content: space-between; gap: 10px; }
        .row:last-child { border-bottom: none; }
        .label { color: #666; font-size: 13px; }
        .value { font-weight: 600; font-size: 14px; text-align: right; }
        .actions { margin-top: 14px; display: flex; gap: 8px; flex-wrap: wrap; }
        .btn-link { text-decoration: none; font-size: 12px; font-weight: 700; color: #3f379f; border: 1px solid #c9c2ff; background: #ece9ff; padding: 7px 10px; border-radius: 999px; display: inline-block; }
        .bottom-nav { position: fixed; bottom: 0; left: 0; right: 0; background: #2D2D2D; display: flex; justify-content: space-around; align-items: center; height: 65px; z-index: 999; }
        .nav-link { color: white; text-decoration: none; font-size: 12px; display: flex; flex-direction: column; align-items: center; gap: 4px; opacity: 0.8; flex: 1; position: relative; }
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
        <h2 class="card-title">Event Booking Details</h2>
        <div class="row"><span class="label">Event Name</span><span class="value"><?php echo htmlspecialchars((string)$booking['event_title']); ?></span></div>
        <div class="row"><span class="label">Date</span><span class="value"><?php echo htmlspecialchars((string)$booking['event_date']); ?></span></div>
        <div class="row"><span class="label">Venue</span><span class="value"><?php echo htmlspecialchars((string)(($booking['event_venue'] ?? '') !== '' ? $booking['event_venue'] : 'Not specified')); ?></span></div>
        <div class="row"><span class="label">Organizer</span><span class="value"><?php echo htmlspecialchars((string)$booking['organizer_name']); ?></span></div>
        <div class="row"><span class="label">Service</span><span class="value"><?php echo htmlspecialchars((string)$booking['service_name']); ?></span></div>
        <div class="row"><span class="label">Booking Status</span><span class="value"><?php echo htmlspecialchars(ucfirst((string)$booking['booking_status'])); ?></span></div>
        <div class="row"><span class="label">Booth Number</span><span class="value"><?php echo htmlspecialchars((string)(($booking['booth_number'] ?? '') !== '' ? $booking['booth_number'] : 'Not assigned')); ?></span></div>
        <div class="row"><span class="label">Amount Payable</span><span class="value">KES <?php echo number_format($net, 2); ?></span></div>
        <div class="row"><span class="label">Payment Status</span><span class="value"><?php echo htmlspecialchars($displayPaymentStatus); ?></span></div>
        <div class="row"><span class="label">M-Pesa Code</span><span class="value"><?php echo htmlspecialchars((string)(($booking['mpesa_code'] ?? '') !== '' ? $booking['mpesa_code'] : 'N/A')); ?></span></div>

        <div class="actions">
            <a class="btn-link" href="bookings.php">Back to Bookings</a>
            <a class="btn-link" href="download_agreement.php?booking_id=<?php echo (int)$booking['booking_id']; ?>">Download Agreement</a>
        </div>
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
