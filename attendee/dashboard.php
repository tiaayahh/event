<?php
require_once '../includes/auth.php';
checkAuth();
requireRole('attendee');

$fullName = $_SESSION['full_name'] ?? 'Attendee';
$search = trim($_GET['q'] ?? '');
$events = [];
$registrations = [];
$errorMessage = '';
$flashSuccess = $_SESSION['flash_success'] ?? '';
$flashError = $_SESSION['flash_error'] ?? '';
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

try {
    if ($search !== '') {
        $stmt = $pdo->prepare(
            "SELECT event_id, title, event_date, ticket_price
            FROM events
            WHERE event_date >= CURDATE() AND title LIKE ?
            ORDER BY event_date ASC
            LIMIT 10"
        );
        $stmt->execute(['%' . $search . '%']);
    } else {
        $stmt = $pdo->query(
            "SELECT event_id, title, event_date, ticket_price
            FROM events
            WHERE event_date >= CURDATE()
            ORDER BY event_date ASC
            LIMIT 10"
        );
    }
    $events = $stmt->fetchAll();

    $stmt = $pdo->prepare(
        "SELECT attendee_id FROM attendees WHERE user_id = ? LIMIT 1"
    );
    $stmt->execute([$_SESSION['user_id']]);
    $attendee = $stmt->fetch();

    if ($attendee) {
        $stmt = $pdo->prepare(
            "SELECT a.status, e.title, e.event_date
            FROM attendances a
            JOIN events e ON a.event_id = e.event_id
            WHERE a.attendee_id = ?
            ORDER BY e.event_date DESC"
        );
        $stmt->execute([$attendee['attendee_id']]);
        $registrations = $stmt->fetchAll();
    }
} catch (Throwable $e) {
    $errorMessage = 'Unable to load dashboard data right now.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="../assets/style.css">
    <title>Attendee Dashboard</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
        }

        body {
            background-color: #ffffff;
            color: #333333;
            padding-bottom: 70px;
        }

        .header {
            background-color: #6C63FF;
            color: white;
            padding: 15px 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .system-name {
            font-size: 20px;
            font-weight: 700;
        }

        .logout-btn {
            background-color: rgba(255, 255, 255, 0.2);
            color: white;
            border: 1px solid rgba(255, 255, 255, 0.4);
            padding: 6px 16px;
            border-radius: 4px;
            text-decoration: none;
            font-size: 14px;
            transition: background 0.2s;
        }

        .logout-btn:hover {
            background-color: rgba(255, 255, 255, 0.3);
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
        }

        .search-container {
            margin-bottom: 25px;
        }

        .search-form {
            display: flex;
            gap: 10px;
        }

        .search-input {
            width: 100%;
            padding: 12px 16px;
            font-size: 15px;
            border: 1px solid #c3c0f4;
            border-radius: 6px;
            outline: none;
        }

        .search-btn {
            background-color: #6C63FF;
            color: #ffffff;
            border: none;
            border-radius: 6px;
            padding: 0 16px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
        }

        .welcome-text {
            margin-bottom: 18px;
            color: #444444;
            font-size: 14px;
        }

        .error-msg {
            background: #ffecec;
            color: #9d2020;
            border: 1px solid #f6caca;
            border-radius: 6px;
            padding: 10px 12px;
            margin-bottom: 14px;
            font-size: 13px;
        }

        .success-msg {
            background: #ecfff0;
            color: #1c7a36;
            border: 1px solid #c9f0d4;
            border-radius: 6px;
            padding: 10px 12px;
            margin-bottom: 14px;
            font-size: 13px;
        }

        .section-title {
            font-size: 18px;
            font-weight: 700;
            margin-bottom: 15px;
            color: #2d2d2d;
        }

        .event-card {
            border: 1px solid #c3c0f4;
            border-radius: 6px;
            padding: 16px;
            margin-bottom: 15px;
            background-color: #ffffff;
        }

        .event-name {
            font-size: 16px;
            font-weight: 700;
            color: #1a1a1a;
            margin-bottom: 6px;
        }

        .event-meta {
            font-size: 13px;
            color: #666666;
            margin-bottom: 12px;
        }

        .register-btn {
            background-color: #B8A8FF;
            color: #222222;
            border: none;
            padding: 8px 18px;
            border-radius: 4px;
            font-size: 14px;
            cursor: pointer;
            font-weight: 500;
            text-decoration: none;
            display: inline-block;
        }

        .register-btn:hover {
            background-color: #A898F0;
        }

        .status-chip {
            display: inline-block;
            margin-top: 6px;
            font-size: 12px;
            font-weight: 600;
            background: #ece9ff;
            color: #4a40bf;
            padding: 4px 10px;
            border-radius: 999px;
        }

        .empty-state {
            color: #666666;
            font-size: 14px;
        }

        .bottom-nav {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            background-color: #6C63FF;
            display: flex;
            justify-content: space-around;
            align-items: center;
            height: 55px;
            box-shadow: 0 -2px 10px rgba(0,0,0,0.1);
            z-index: 999;
        }

        .nav-item {
            color: rgba(255, 255, 255, 0.7);
            text-decoration: none;
            font-size: 12px;
            font-weight: 500;
            flex-grow: 1;
            text-align: center;
            height: 100%;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 4px;
            transition: color 0.2s, background-color 0.2s;
        }

        .nav-item i {
            font-size: 16px;
        }

        .nav-item:hover, .nav-item.active {
            color: #ffffff;
            background-color: rgba(255, 255, 255, 0.1);
        }
    </style>
</head>
<body>

    <header class="header">
        <div class="system-name">Planora</div>
        <a href="../logout.php" class="logout-btn">Logout</a>
    </header>

    <div class="container">
        <p class="welcome-text">Welcome, <?php echo htmlspecialchars($fullName); ?>.</p>

        <?php if ($flashSuccess !== ''): ?>
            <div class="success-msg"><?php echo htmlspecialchars($flashSuccess); ?></div>
        <?php endif; ?>

        <?php if ($flashError !== ''): ?>
            <div class="error-msg"><?php echo htmlspecialchars($flashError); ?></div>
        <?php endif; ?>

        <?php if ($errorMessage !== ''): ?>
            <div class="error-msg"><?php echo htmlspecialchars($errorMessage); ?></div>
        <?php endif; ?>

        <div class="search-container">
            <form method="GET" class="search-form">
                <input type="text" name="q" class="search-input" placeholder="Search Events" value="<?php echo htmlspecialchars($search); ?>">
                <button type="submit" class="search-btn">Search</button>
            </form>
        </div>

        <h2 class="section-title">Upcoming Events</h2>

        <?php if (empty($events)): ?>
            <div class="event-card empty-state">No upcoming events found.</div>
        <?php else: ?>
            <?php foreach ($events as $event): ?>
                <div class="event-card">
                    <div class="event-name"><?php echo htmlspecialchars($event['title']); ?></div>
                    <div class="event-meta">Date: <?php echo htmlspecialchars($event['event_date']); ?></div>
                    <div class="event-meta">Price: KES <?php echo number_format((float)$event['ticket_price'], 2); ?></div>
                    <a href="register_event.php?event_id=<?php echo urlencode((string)$event['event_id']); ?>" class="register-btn">Register</a>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>

        <h2 class="section-title" style="margin-top: 25px;">My Registrations</h2>

        <?php if (empty($registrations)): ?>
            <div class="event-card empty-state">You have no registrations yet.</div>
        <?php else: ?>
            <?php foreach ($registrations as $registration): ?>
                <div class="event-card">
                    <div class="event-name"><?php echo htmlspecialchars($registration['title']); ?></div>
                    <div class="event-meta" style="margin-bottom: 0;">Date: <?php echo htmlspecialchars($registration['event_date']); ?></div>
                    <span class="status-chip"><?php echo htmlspecialchars(ucfirst($registration['status'])); ?></span>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <nav class="bottom-nav">
        <a href="dashboard.php" class="nav-item active">
            <i class="fa-solid fa-house"></i>
            <span>Home</span>
        </a>
        <a href="explore.php" class="nav-item">
            <i class="fa-solid fa-compass"></i>
            <span>Explore</span>
        </a>
        <a href="my_events.php" class="nav-item">
            <i class="fa-solid fa-list-check"></i>
            <span>My Events</span>
        </a>
        <a href="profile.php" class="nav-item">
            <i class="fa-solid fa-user"></i>
            <span>Profile</span>
        </a>
    </nav>

</body>
</html>

