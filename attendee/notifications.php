<?php
require_once '../includes/auth.php';
checkAuth();
requireRole('attendee');

$notifications = [];
$errorMessage = '';

try {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS attendee_ticket_payments (
            payment_id BIGINT AUTO_INCREMENT PRIMARY KEY,
            event_id INT NOT NULL,
            attendee_id INT NOT NULL,
            ticket_type VARCHAR(32) NOT NULL DEFAULT 'regular',
            checkout_request_id VARCHAR(120) DEFAULT NULL,
            merchant_request_id VARCHAR(120) DEFAULT NULL,
            phone_number VARCHAR(20) DEFAULT NULL,
            mpesa_code VARCHAR(64) DEFAULT NULL,
            amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            status ENUM('requested', 'paid', 'failed') NOT NULL DEFAULT 'requested',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            checked_in_at TIMESTAMP NULL DEFAULT NULL,
            checkin_by_user_id INT NULL DEFAULT NULL,
            INDEX idx_attendee_ticket_payment_event_status (event_id, status),
            INDEX idx_attendee_ticket_payment_attendee (attendee_id),
            INDEX idx_attendee_ticket_payment_checkout (checkout_request_id),
            INDEX idx_attendee_ticket_payment_checkin (event_id, attendee_id, status, checked_in_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    try {
        $pdo->exec("ALTER TABLE attendee_ticket_payments DROP INDEX uq_attendee_ticket_payment");
    } catch (Throwable $e) {
        // Ignore if key is absent.
    }

    $stmt = $pdo->prepare('SELECT attendee_id FROM attendees WHERE user_id = ? LIMIT 1');
    $stmt->execute([$_SESSION['user_id']]);
    $attendeeId = (int)($stmt->fetchColumn() ?: 0);

    if ($attendeeId <= 0) {
        throw new RuntimeException('Attendee profile not found.');
    }

    $stmt = $pdo->prepare(
        "SELECT e.title, p.updated_at
         FROM attendee_ticket_payments p
         JOIN events e ON e.event_id = p.event_id
         WHERE p.attendee_id = ? AND p.status = 'paid'
         ORDER BY p.updated_at DESC
         LIMIT 8"
    );
    $stmt->execute([$attendeeId]);
    foreach ($stmt->fetchAll() as $row) {
        $notifications[] = [
            'title' => 'Ticket Confirmed',
            'body' => 'Your ticket for ' . (string)$row['title'] . ' is confirmed.',
            'time' => (string)$row['updated_at'],
        ];
    }

    $stmt = $pdo->prepare(
        "SELECT e.title, e.event_date
         FROM attendances a
         JOIN events e ON e.event_id = a.event_id
         WHERE a.attendee_id = ? AND a.status = 'registered' AND e.archived_at IS NULL
         ORDER BY e.event_date ASC
         LIMIT 8"
    );
    $stmt->execute([$attendeeId]);
    foreach ($stmt->fetchAll() as $row) {
        $eventDate = new DateTime((string)$row['event_date']);
        $today = new DateTime('today');
        $days = (int)$today->diff($eventDate)->format('%r%a');
        if ($days >= 0 && $days <= 5) {
            $notifications[] = [
                'title' => 'Event Reminder',
                'body' => (string)$row['title'] . ($days === 0 ? ' starts today.' : ' starts in ' . $days . ' day' . ($days === 1 ? '' : 's') . '.'),
                'time' => (string)$row['event_date'],
            ];
        }
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS attendee_messages (
            message_id INT AUTO_INCREMENT PRIMARY KEY,
            planner_user_id INT NOT NULL,
            attendee_user_id INT NOT NULL,
            sender_role ENUM('planner','attendee') NOT NULL,
            message_text TEXT NOT NULL,
            message_kind ENUM('direct','announcement') NOT NULL DEFAULT 'direct',
            is_read TINYINT(1) NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $stmt = $pdo->prepare(
        "SELECT COUNT(*)
         FROM attendee_messages
         WHERE attendee_user_id = ? AND sender_role = 'planner' AND is_read = 0"
    );
    $stmt->execute([$_SESSION['user_id']]);
    $unreadMessages = (int)$stmt->fetchColumn();
    if ($unreadMessages > 0) {
        $notifications[] = [
            'title' => 'Unread Messages',
            'body' => 'You have ' . $unreadMessages . ' unread organizer message' . ($unreadMessages === 1 ? '' : 's') . '.',
            'time' => 'Now',
        ];
    }

    if (empty($notifications)) {
        $notifications[] = [
            'title' => 'No New Notifications',
            'body' => 'You are all caught up. Explore events to get more updates.',
            'time' => 'Now',
        ];
    }
} catch (Throwable $e) {
    $errorMessage = 'Unable to load notifications right now.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="../assets/style.css">
    <title>Planora - Notifications</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; }
        body { background: #f4f5fb; color: #2d2d2d; padding-bottom: 76px; }
        .header { background: #6C63FF; color: #fff; padding: 14px 24px; display: flex; justify-content: space-between; align-items: center; }
        .brand { font-size: 22px; font-weight: 700; }
        .logout-btn { background: rgba(255,255,255,.22); color: #fff; text-decoration: none; border-radius: 6px; padding: 7px 14px; font-size: 13px; }
        .container { max-width: 920px; margin: 0 auto; padding: 20px; }
        .notice { background: #ffecec; color: #9d2020; border: 1px solid #f6caca; border-radius: 6px; padding: 10px 12px; margin-bottom: 14px; font-size: 13px; }
        .title { font-size: 22px; margin-bottom: 12px; }
        .list { display: grid; gap: 10px; }
        .item { background: #fff; border: 1px solid #ece9ff; border-radius: 12px; padding: 12px; }
        .item h3 { font-size: 15px; margin-bottom: 4px; }
        .item p { font-size: 13px; color: #555; margin-bottom: 6px; }
        .meta { font-size: 11px; color: #888; }
        .quick-links { margin-top: 12px; display: flex; gap: 8px; flex-wrap: wrap; }
        .quick-link { text-decoration: none; background: #ece9ff; color: #3f379f; border-radius: 8px; padding: 8px 10px; font-size: 12px; font-weight: 700; }
        .bottom-nav { position: fixed; bottom: 0; left: 0; right: 0; height: 62px; background: #6C63FF; display: flex; z-index: 999; }
        .nav-item { flex: 1; color: rgba(255,255,255,.76); text-decoration: none; font-size: 11px; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 4px; }
        .nav-item i { font-size: 15px; }
        .nav-item.active, .nav-item:hover { color: #fff; background: rgba(255,255,255,.1); }
    </style>
</head>
<body>
<header class="header">
    <div class="brand">PLANORA</div>
    <a class="logout-btn" href="../logout.php">Logout</a>
</header>
<div class="container">
    <h1 class="title">Notifications</h1>
    <?php if ($errorMessage !== ''): ?><div class="notice"><?php echo htmlspecialchars($errorMessage); ?></div><?php endif; ?>

    <div class="list">
        <?php foreach ($notifications as $notification): ?>
            <div class="item">
                <h3><?php echo htmlspecialchars((string)$notification['title']); ?></h3>
                <p><?php echo htmlspecialchars((string)$notification['body']); ?></p>
                <div class="meta"><?php echo htmlspecialchars((string)$notification['time']); ?></div>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="quick-links">
        <a class="quick-link" href="messages.php">Open Messages</a>
        <a class="quick-link" href="my_events.php">View My Events</a>
        <a class="quick-link" href="explore.php">Discover Events</a>
    </div>
</div>

<nav class="bottom-nav">
    <a href="dashboard.php" class="nav-item"><i class="fa-solid fa-house"></i><span>Home</span></a>
    <a href="explore.php" class="nav-item"><i class="fa-solid fa-compass"></i><span>Explore</span></a>
    <a href="my_events.php" class="nav-item"><i class="fa-solid fa-ticket"></i><span>My Events</span></a>
    <a href="schedule.php" class="nav-item"><i class="fa-solid fa-calendar-days"></i><span>Schedule</span></a>
    <a href="notifications.php" class="nav-item active"><i class="fa-solid fa-bell"></i><span>Alerts</span></a>
    <a href="profile.php" class="nav-item"><i class="fa-solid fa-user"></i><span>Profile</span></a>
</nav>
</body>
</html>
