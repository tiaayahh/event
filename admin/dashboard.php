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
$events = [];
$recentBookings = [];
$unreadMessages = 0;

try {
    $stmt = $pdo->prepare("SELECT * FROM events WHERE planner_id = ? ORDER BY event_date DESC");
    $stmt->execute([$_SESSION['user_id']]);
    $events = $stmt->fetchAll();

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

    foreach ($events as $e) {
        $totalBudget += $e['budget_total'];
        $totalCommitted += $e['budget_committed'];
        $totalTicketRevenue += $e['ticket_revenue'];
    }

    $stmt = $pdo->prepare("
        SELECT b.booking_id, b.status, b.booked_price, s.name AS service_name, v.business_name,
               e.title AS event_title
        FROM bookings b
        JOIN services s ON b.service_id = s.service_id
        JOIN vendors v ON s.vendor_id = v.vendor_id
        JOIN events e ON b.event_id = e.event_id
        WHERE e.planner_id = ?
        ORDER BY b.created_at DESC
        LIMIT 5
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $recentBookings = $stmt->fetchAll();
} catch (Throwable $e) {
    $flashError = 'Could not load dashboard data.';
}

$available = $totalBudget - $totalCommitted;
$percent = $totalBudget > 0 ? ($totalCommitted / $totalBudget) * 100 : 0;
$budgetColor = $percent > 80 ? 'status-danger' : ($percent > 50 ? 'status-warning' : 'status-good');
$budgetLabel = $percent > 80 ? 'Over Budget' : ($percent > 50 ? 'Caution' : 'Good');
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

        .status-badge { font-weight: 600; }
        .status-badge.confirmed { color: #2ecc71; }
        .status-badge.pending { color: #f39c12; }

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
            <li><a href="payment_history.php"><span class="menu-label"><i class="fa-solid fa-book-bookmark"></i> Bookings</span></a></li>
            <li><a href="profile.php"><span class="menu-label"><i class="fa-solid fa-user"></i> Profile</span></a></li>
        </ul>
    </aside>

    <div class="main-wrapper">
        <header class="header">
            <div class="header-brand-mobile">Planora</div>
            <a href="../logout.php" class="logout-btn">
                <i class="fa-solid fa-right-from-bracket"></i> Logout
            </a>
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
                        <i class="fa-regular fa-folder-open" style="color: #6C63FF;"></i> Budget Overview
                    </h3>
                    <div class="budget-metric">Total Budget: <strong>KES <?php echo number_format($totalBudget, 2); ?></strong></div>
                    <div class="budget-metric">Committed: <strong>KES <?php echo number_format($totalCommitted, 2); ?></strong></div>
                    <div class="budget-metric">Ticket Revenue: <strong>KES <?php echo number_format($totalTicketRevenue, 2); ?></strong></div>
                    <div class="budget-metric">Available: <strong>KES <?php echo number_format($available, 2); ?></strong> <span class="<?php echo $budgetColor; ?>">(<?php echo $budgetLabel; ?>)</span></div>
                    <div class="progress-bar-container">
                        <div class="progress-bar-fill" style="width: <?php echo $percent; ?>%;"></div>
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
                            <div class="list-item">
                                <span><?php echo htmlspecialchars($event['title']); ?> (<?php echo $event['event_date']; ?>) &ndash; Budget: <strong>KES <?php echo number_format($event['budget_total'], 2); ?></strong></span>
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
        <a href="payment_history.php" class="bottom-nav-item">
            <i class="fa-solid fa-book-bookmark"></i>
            <span>Bookings</span>
        </a>
        <a href="../logout.php" class="bottom-nav-item">
            <i class="fa-solid fa-right-from-bracket"></i>
            <span>Logout</span>
        </a>
    </nav>

</body>
</html>

