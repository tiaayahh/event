<?php
require_once 'includes/auth.php';
checkAuth();
requireRole('vendor');

$stmt = $pdo->prepare("SELECT vendor_id FROM vendors WHERE user_id = ?");
$stmt->execute([$_SESSION['user_id']]);
$vendor = $stmt->fetch();
$vendorId = $vendor['vendor_id'];

$stmt = $pdo->prepare("
    SELECT b.booking_id, e.title AS event_title, e.event_date, s.name AS service_name, b.status
    FROM bookings b
    JOIN events e ON b.event_id = e.event_id
    JOIN services s ON b.service_id = s.service_id
    WHERE s.vendor_id = ? AND b.status = 'pending'
    ORDER BY b.created_at DESC
");
$stmt->execute([$vendorId]);
$pending_bookings = $stmt->fetchAll();
$has_new_alert = count($pending_bookings) > 0;
$is_available = false;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Planora - Vendor Dashboard</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            font-family: 'Inter', sans-serif;
        }

        body {
            background-color: #F5F5F5;
            min-height: 100vh;
            padding: 0;
        }

        .dashboard-wrapper {
            position: relative;
            width: 100%;
            min-height: 100vh;
        }


        .dashboard-container {
            background-color: #FFFFFF;
            border-radius: 0;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
            min-height: 100vh;
            padding-bottom: 80px;
            border: none;
        }

        .header-nav {
            background-color: #6C63FF;
            color: #FFFFFF;
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 18px 24px;
        }

        .header-nav h1 {
            font-size: 24px;
            font-weight: 700;
            letter-spacing: 0.5px;
        }

        .logout-btn {
            background-color: rgba(255, 255, 255, 0.25);
            color: #FFFFFF;
            border: none;
            padding: 8px 16px;
            border-radius: 6px;
            font-size: 14px;
            cursor: pointer;
            text-decoration: none;
            transition: background 0.2s;
            display: inline-block;
        }

        .logout-btn:hover {
            background-color: rgba(255, 255, 255, 0.35);
        }

        .content-section {
            background-color: #FFFFFF;
            margin: 20px 24px;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 6px rgba(0,0,0,0.02);
            border: 1px solid #f0f0f0;
        }

        .section-title {
            font-size: 16px;
            font-weight: 700;
            color: #2D2D2D;
            margin-bottom: 14px;
        }

        .alert-box {
            background-color: #B8A8FF;
            color: #2D2D2D;
            padding: 14px 18px;
            border-radius: 6px;
            font-weight: 600;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .availability-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .availability-row .section-title {
            margin-bottom: 0;
        }

        .switch {
            position: relative;
            display: inline-block;
            width: 44px;
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
            background-color: #FFFFFF;
            border: 2px solid #B8A8FF;
            transition: .3s;
            border-radius: 24px;
        }

        .slider:before {
            position: absolute;
            content: "";
            height: 14px;
            width: 14px;
            left: 3px;
            bottom: 3px;
            background-color: #B8A8FF;
            transition: .3s;
            border-radius: 50%;
        }

        input:checked + .slider {
            background-color: #6C63FF;
            border-color: #6C63FF;
        }

        input:checked + .slider:before {
            transform: translateX(20px);
            background-color: #FFFFFF;
        }

        .booking-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 14px 0;
            border-bottom: 1px solid #eaeaea;
            color: #2D2D2D;
            font-size: 14px;
        }

        .booking-item:last-child {
            border-bottom: none;
        }

        .status-icon {
            font-size: 16px;
            color: #2D2D2D;
        }

        .bottom-nav {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            background-color: #2D2D2D;
            display: flex;
            justify-content: space-around;
            padding: 10px 0;
            z-index: 20;
        }

        .nav-item {
            display: flex;
            flex-direction: column;
            align-items: center;
            color: #FFFFFF;
            text-decoration: none;
            font-size: 11px;
            gap: 6px;
            opacity: 0.8;
            transition: opacity 0.2s;
            width: 15%;
            text-align: center;
        }

        .nav-item:hover, .nav-item.active {
            opacity: 1;
        }

        .nav-icon {
            font-size: 18px;
        }

        @media (max-width: 768px) {
            .header-nav,
            .content-section {
                padding-left: 16px;
                padding-right: 16px;
                margin-left: 0;
                margin-right: 0;
            }
        }
    </style>
</head>
<body>

<div class="dashboard-wrapper">
    <div class="dashboard-container">
        <header class="header-nav">
            <h1>PLANORA</h1>
            <a href="logout.php" class="logout-btn">Logout</a>
        </header>

        <main class="content-section" id="alerts">
            <h2 class="section-title">Booking Alerts</h2>
            <?php if ($has_new_alert): ?>
                <div class="alert-box">
                    <span>🔔</span> New Pending Booking!
                </div>
            <?php else: ?>
                <div class="alert-box">
                    <span>✅</span> No new booking alerts.
                </div>
            <?php endif; ?>
        </main>

        <section class="content-section availability-row" id="availability">
            <h2 class="section-title">Availability</h2>
            <label class="switch">
                <input type="checkbox" <?php echo $is_available ? 'checked' : ''; ?>>
                <span class="slider"></span>
            </label>
        </section>

        <section class="content-section" id="bookings">
            <h2 class="section-title">Pending Bookings</h2>
            <div class="bookings-list">
                <?php foreach ($pending_bookings as $booking): ?>
                    <div class="booking-item">
                        <span><?php echo htmlspecialchars($booking['event_title']) . ', ' . htmlspecialchars($booking['service_name']) . ', ' . $booking['event_date'] . ', ' . ucfirst($booking['status']); ?></span>
                        <span class="status-icon">⌛</span>
                    </div>
                <?php endforeach; ?>
                <?php if (empty($pending_bookings)): ?>
                    <div class="booking-item"><span>No pending bookings.</span></div>
                <?php endif; ?>
            </div>
        </section>

        <nav class="bottom-nav">
            <a href="index.php" class="nav-item">
                <span class="nav-icon">🏠</span>
                <span>Home</span>
            </a>
            <a href="#availability" class="nav-item">
                <span class="nav-icon">🛎️</span>
                <span>Availability</span>
            </a>
            <a href="#bookings" class="nav-item active">
                <span class="nav-icon">📖</span>
                <span>Bookings</span>
            </a>
            <a href="#alerts" class="nav-item">
                <span class="nav-icon">📅</span>
                <span>Alerts</span>
            </a>
            <a href="logout.php" class="nav-item">
                <span class="nav-icon">👤</span>
                <span>Logout</span>
            </a>
        </nav>
    </div>
</div>
</body>
</html>