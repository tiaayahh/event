<?php
require_once '../includes/auth.php';
checkAuth();
requireRole('attendee');

$fullName = $_SESSION['full_name'] ?? 'Attendee';
$registrations = [];
$errorMessage = '';

try {
    $stmt = $pdo->prepare("SELECT attendee_id FROM attendees WHERE user_id = ? LIMIT 1");
    $stmt->execute([$_SESSION['user_id']]);
    $attendee = $stmt->fetch();

    if ($attendee) {
        $stmt = $pdo->prepare(
            "SELECT a.status, e.title, e.event_date, e.event_id
            FROM attendances a
            JOIN events e ON a.event_id = e.event_id
            WHERE a.attendee_id = ?
            ORDER BY e.event_date DESC"
        );
        $stmt->execute([$attendee['attendee_id']]);
        $registrations = $stmt->fetchAll();
    }
} catch (Throwable $e) {
    $errorMessage = 'Unable to load your events.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="../assets/style.css">
    <title>My Events</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
        }
        body {
            background: #ffffff;
            color: #333;
            padding-bottom: 70px;
        }
        .header {
            background: #6C63FF;
            color: white;
            padding: 15px 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .header .logo { font-size: 20px; font-weight: 700; }
        .logout-btn {
            background: rgba(255,255,255,0.2);
            color: white;
            padding: 6px 16px;
            border-radius: 4px;
            text-decoration: none;
            font-size: 14px;
        }
        .container {
            max-width: 600px;
            margin: 30px auto;
            padding: 0 20px;
        }
        .section-title {
            font-size: 20px;
            font-weight: 700;
            margin-bottom: 20px;
            color: #2d2d2d;
        }
        .event-card {
            border: 1px solid #c3c0f4;
            border-radius: 6px;
            padding: 16px;
            margin-bottom: 15px;
            background: #fff;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .event-info h3 {
            font-size: 16px;
            margin-bottom: 4px;
            color: #1a1a1a;
        }
        .event-date {
            font-size: 13px;
            color: #666;
        }
        .status-badge {
            font-size: 12px;
            font-weight: 600;
            padding: 4px 12px;
            border-radius: 20px;
            background: #ece9ff;
            color: #4a40bf;
        }
        .empty {
            text-align: center;
            color: #888;
            margin-top: 40px;
        }
        .bottom-nav {
            position: fixed;
            bottom: 0; left: 0; right: 0;
            background: #6C63FF;
            display: flex;
            height: 55px;
            z-index: 999;
        }
        .nav-item {
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            color: rgba(255,255,255,0.7);
            text-decoration: none;
            font-size: 12px;
            gap: 4px;
        }
        .nav-item i { font-size: 16px; }
        .nav-item.active, .nav-item:hover { color: white; background: rgba(255,255,255,0.1); }
    </style>
</head>
<body>
    <header class="header">
        <div class="logo">Planora</div>
        <a href="../logout.php" class="logout-btn">Logout</a>
    </header>

    <div class="container">
        <h1 class="section-title">My Events</h1>

        <?php if ($errorMessage): ?>
            <div class="empty"><?php echo htmlspecialchars($errorMessage); ?></div>
        <?php elseif (empty($registrations)): ?>
            <div class="empty">You have not registered for any events yet.</div>
        <?php else: ?>
            <?php foreach ($registrations as $reg): ?>
                <div class="event-card">
                    <div class="event-info">
                        <h3><?php echo htmlspecialchars($reg['title']); ?></h3>
                        <div class="event-date"><?php echo htmlspecialchars($reg['event_date']); ?></div>
                    </div>
                    <span class="status-badge"><?php echo ucfirst($reg['status']); ?></span>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <nav class="bottom-nav">
        <a href="dashboard.php" class="nav-item"><i class="fa-solid fa-house"></i><span>Home</span></a>
        <a href="explore.php" class="nav-item"><i class="fa-solid fa-compass"></i><span>Explore</span></a>
        <a href="my_events.php" class="nav-item active"><i class="fa-solid fa-list-check"></i><span>My Events</span></a>
        <a href="messages.php" class="nav-item"><i class="fa-solid fa-comments"></i><span>Messages</span></a>
        <a href="profile.php" class="nav-item"><i class="fa-solid fa-user"></i><span>Profile</span></a>
    </nav>
</body>
</html>


