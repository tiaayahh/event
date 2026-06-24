<?php
require_once '../includes/auth.php';
checkAuth();
requireRole('attendee');

$search = trim($_GET['q'] ?? '');
$events = [];
$errorMessage = '';

try {
    if ($search !== '') {
        $stmt = $pdo->prepare(
            "SELECT event_id, title, event_date, ticket_price
            FROM events
            WHERE event_date >= CURDATE() AND title LIKE ?
            ORDER BY event_date ASC
            LIMIT 20"
        );
        $stmt->execute(['%' . $search . '%']);
    } else {
        $stmt = $pdo->query(
            "SELECT event_id, title, event_date, ticket_price
            FROM events
            WHERE event_date >= CURDATE()
            ORDER BY event_date ASC
            LIMIT 20"
        );
    }
    $events = $stmt->fetchAll();
} catch (Throwable $e) {
    $errorMessage = 'Could not load events.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="../assets/style.css">
    <title>Planora &ndash; Explore Events</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
        }
        body {
            background: #fff;
            color: #2D2D2D;
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
        .logo { font-size: 20px; font-weight: 700; }
        .logout-btn {
            background: rgba(255,255,255,0.2);
            color: white;
            padding: 6px 16px;
            border-radius: 4px;
            text-decoration: none;
            font-size: 14px;
        }
        .container {
            max-width: 900px;
            margin: 30px auto;
            padding: 0 20px;
        }
        .page-title {
            font-size: 26px;
            font-weight: 700;
            margin-bottom: 20px;
        }
        .search-form {
            display: flex;
            gap: 10px;
            margin-bottom: 30px;
        }
        .search-input {
            flex: 1;
            padding: 12px 16px;
            font-size: 15px;
            border: 1px solid #B8A8FF;
            border-radius: 6px;
            outline: none;
        }
        .search-btn {
            background: #6C63FF;
            color: white;
            border: none;
            padding: 0 20px;
            border-radius: 6px;
            font-weight: 600;
            cursor: pointer;
        }
        .event-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
            gap: 16px;
        }
        .event-card {
            border: 1px solid #B8A8FF;
            border-radius: 8px;
            padding: 18px;
            background: white;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }
        .event-title {
            font-size: 16px;
            font-weight: 700;
            margin-bottom: 6px;
            color: #1a1a1a;
        }
        .event-date {
            font-size: 13px;
            color: #666;
            margin-bottom: 12px;
        }
        .register-link {
            background: #B8A8FF;
            color: #2D2D2D;
            text-align: center;
            padding: 10px;
            border-radius: 6px;
            text-decoration: none;
            font-weight: 600;
            font-size: 14px;
        }
        .register-link:hover {
            background: #A898F0;
        }
        .empty-state {
            text-align: center;
            color: #888;
            font-size: 14px;
            grid-column: 1 / -1;
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
        <div class="logo">PLANORA</div>
        <a href="../logout.php" class="logout-btn">Logout</a>
    </header>

    <div class="container">
        <h1 class="page-title">Explore Events</h1>

        <form method="GET" class="search-form">
            <input type="text" name="q" class="search-input" placeholder="Search events..." value="<?php echo htmlspecialchars($search); ?>">
            <button type="submit" class="search-btn">Search</button>
        </form>

        <?php if ($errorMessage): ?>
            <p class="empty-state"><?php echo htmlspecialchars($errorMessage); ?></p>
        <?php elseif (empty($events)): ?>
            <p class="empty-state">No upcoming events found.</p>
        <?php else: ?>
            <div class="event-grid">
                <?php foreach ($events as $event): ?>
                    <div class="event-card">
                        <div>
                            <div class="event-title"><?php echo htmlspecialchars($event['title']); ?></div>
                            <div class="event-date"><?php echo htmlspecialchars($event['event_date']); ?></div>
                            <div class="event-date">Price: KES <?php echo number_format((float)$event['ticket_price'], 2); ?></div>
                        </div>
                        <a href="register_event.php?event_id=<?php echo urlencode((string)$event['event_id']); ?>" class="register-link">Register</a>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <nav class="bottom-nav">
        <a href="dashboard.php" class="nav-item"><i class="fa-solid fa-house"></i><span>Home</span></a>
        <a href="explore.php" class="nav-item active"><i class="fa-solid fa-compass"></i><span>Explore</span></a>
        <a href="my_events.php" class="nav-item"><i class="fa-solid fa-list-check"></i><span>My Events</span></a>
        <a href="profile.php" class="nav-item"><i class="fa-solid fa-user"></i><span>Profile</span></a>
    </nav>
</body>
</html>

