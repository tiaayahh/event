<?php
require_once '../includes/auth.php';
checkAuth();
requireRole('vendor');

$fullName = $_SESSION['full_name'] ?? 'Vendor';
$pendingBookings = [];
$services = [];
$hasNewAlert = false;
$unreadMessages = 0;
$flashSuccess = $_SESSION['flash_success'] ?? '';
$flashError = $_SESSION['flash_error'] ?? '';
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

try {
    $stmt = $pdo->prepare('SELECT vendor_id FROM vendors WHERE user_id = ? LIMIT 1');
    $stmt->execute([$_SESSION['user_id']]);
    $vendor = $stmt->fetch();

    if (!$vendor) {
        $flashError = 'Vendor profile not found. Please complete vendor setup first.';
    } else {
        $vendorId = (int)$vendor['vendor_id'];

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

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM messages WHERE vendor_user_id = ? AND sender_role = 'planner' AND is_read = 0");
        $stmt->execute([$_SESSION['user_id']]);
        $unreadMessages = (int)$stmt->fetchColumn();

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_service_availability'], $_POST['service_id'])) {
            $serviceId = filter_input(INPUT_POST, 'service_id', FILTER_VALIDATE_INT);

            if (!$serviceId) {
                $_SESSION['flash_error'] = 'Invalid service selected.';
            } else {
                $newAvailability = isset($_POST['availability']) ? 1 : 0;
                $stmt = $pdo->prepare('UPDATE services SET availability = ? WHERE service_id = ? AND vendor_id = ?');
                $stmt->execute([$newAvailability, $serviceId, $vendorId]);
                $_SESSION['flash_success'] = 'Service availability updated.';
            }

            header('Location: dashboard.php');
            exit;
        }

        $stmt = $pdo->prepare('SELECT service_id, name, price, availability FROM services WHERE vendor_id = ? ORDER BY name ASC');
        $stmt->execute([$vendorId]);
        $services = $stmt->fetchAll();

        $stmt = $pdo->prepare(
            "SELECT b.booking_id, e.title AS event_title, e.event_date, s.name AS service_name, b.status
            FROM bookings b
            JOIN events e ON b.event_id = e.event_id
            JOIN services s ON b.service_id = s.service_id
            WHERE s.vendor_id = ? AND b.status = 'pending'
            ORDER BY b.created_at DESC"
        );
        $stmt->execute([$vendorId]);
        $pendingBookings = $stmt->fetchAll();
        $hasNewAlert = count($pendingBookings) > 0;
    }
} catch (Throwable $e) {
    $flashError = 'Unable to load vendor dashboard data right now.';
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
            gap: 10px;
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
            margin-top: 2px;
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
                <i class="fa-solid fa-bell"></i>
                <?php echo $hasNewAlert ? 'New Pending Booking!' : 'No new pending bookings.'; ?>
            </div>
        </div>

        <div class="dashboard-card availability-row">
            <h2 class="card-title" style="margin-bottom: 0;">Service Availability</h2>
        </div>

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
            <h2 class="card-title">Pending Bookings</h2>

            <?php if (empty($pendingBookings)): ?>
                <div class="booking-item">
                    <span>No pending bookings.</span>
                </div>
            <?php else: ?>
                <?php foreach ($pendingBookings as $booking): ?>
                    <div class="booking-item">
                        <span>
                            <?php
                            echo htmlspecialchars($booking['event_title']) . ', ' .
                                 htmlspecialchars($booking['service_name']) . ', ' .
                                 htmlspecialchars($booking['event_date']) . ', ' .
                                 htmlspecialchars(ucfirst($booking['status']));
                            ?>
                        </span>
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
        <a href="messages.php" class="nav-link">
            <i class="fa-solid fa-comments"></i>
            <span>Messages</span>
            <?php if ($unreadMessages > 0): ?><span class="badge-unread"><?php echo $unreadMessages; ?></span><?php endif; ?>
        </a>
        <a href="services.php" class="nav-link">
            <i class="fa-solid fa-bell-concierge"></i>
            <span>Services</span>
        </a>
        <a href="bookings.php" class="nav-link">
            <i class="fa-solid fa-book-open"></i>
            <span>Bookings</span>
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

