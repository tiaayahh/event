<?php
require_once 'includes/auth.php';
checkAuth();
requireRole('attendee');

$fullName = $_SESSION['full_name'] ?? 'Attendee';

$stmt = $pdo->query("SELECT * FROM events WHERE event_date >= CURDATE() ORDER BY event_date LIMIT 10");
$events = $stmt->fetchAll();

$stmt = $pdo->prepare(
    "SELECT a.*, e.title, e.event_date
    FROM attendances a
    JOIN events e ON a.event_id = e.event_id
    JOIN attendees att ON a.attendee_id = att.attendee_id
    WHERE att.user_id = ?
    ORDER BY e.event_date DESC"
);
$stmt->execute([$_SESSION['user_id']]);
$registrations = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Planora - Attendee Dashboard</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
        }

        body {
            background: #f5f6fb;
            color: #2d2d2d;
            min-height: 100vh;
        }

        .topbar {
            background: #635bff;
            color: #fff;
            padding: 16px 22px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .brand {
            font-size: 22px;
            font-weight: 700;
            letter-spacing: 1px;
        }

        .logout {
            color: #fff;
            text-decoration: none;
            background: rgba(255, 255, 255, 0.22);
            padding: 8px 14px;
            border-radius: 8px;
            font-size: 14px;
        }

        .container {
            max-width: 980px;
            margin: 28px auto;
            padding: 0 16px;
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
        }

        .card {
            background: #fff;
            border: 1px solid #e5e8ef;
            border-radius: 10px;
            padding: 20px;
            box-shadow: 0 6px 18px rgba(0, 0, 0, 0.03);
        }

        .card.full {
            grid-column: 1 / -1;
        }

        h1 {
            font-size: 26px;
            margin-bottom: 8px;
        }

        h2 {
            font-size: 18px;
            margin-bottom: 10px;
            color: #27283a;
        }

        p, li {
            color: #5d6474;
            line-height: 1.45;
            font-size: 14px;
        }

        ul {
            padding-left: 18px;
        }

        .actions {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-top: 10px;
        }

        .btn {
            display: inline-block;
            text-decoration: none;
            border-radius: 8px;
            padding: 10px 14px;
            font-size: 14px;
            font-weight: 600;
        }

        .btn-primary {
            background: #635bff;
            color: #fff;
        }

        .btn-light {
            background: #ece9ff;
            color: #3e3788;
        }

        .list {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .list-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 10px;
            padding: 12px 0;
            border-bottom: 1px solid #edf0f6;
        }

        .list-item:last-child {
            border-bottom: none;
            padding-bottom: 0;
        }

        .item-title {
            color: #2d2d2d;
            font-size: 14px;
            line-height: 1.4;
        }

        .btn-small {
            padding: 8px 12px;
            font-size: 13px;
            white-space: nowrap;
        }

        .status-badge {
            background: #ece9ff;
            color: #3e3788;
            border-radius: 999px;
            padding: 6px 10px;
            font-size: 12px;
            font-weight: 600;
            text-transform: capitalize;
            white-space: nowrap;
        }

        @media (max-width: 768px) {
            .container {
                grid-template-columns: 1fr;
            }

            .list-item {
                align-items: flex-start;
                flex-direction: column;
            }
        }
    </style>
</head>
<body>
    <header class="topbar">
        <div class="brand">PLANORA</div>
        <a class="logout" href="logout.php">Logout</a>
    </header>

    <main class="container">
        <section class="card full">
            <h1>Welcome, <?php echo htmlspecialchars($fullName); ?></h1>
            <p>This is your attendee dashboard. It is intentionally simple, clear, and connected to the rest of your app.</p>
            <div class="actions">
                <a class="btn btn-primary" href="index.php">Home</a>
                <a class="btn btn-light" href="logout.php">Sign Out</a>
            </div>
        </section>

        <section class="card">
            <h2>Upcoming Events</h2>
            <div class="list">
                <?php if (empty($events)): ?>
                    <div class="list-item">
                        <p>No upcoming events at the moment.</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($events as $ev): ?>
                        <div class="list-item">
                            <p class="item-title">
                                <strong><?php echo htmlspecialchars($ev['title']); ?></strong>
                                - <?php echo date('M d, Y', strtotime($ev['event_date'])); ?>
                            </p>
                            <a href="register_event.php?event_id=<?php echo $ev['event_id']; ?>" class="btn btn-primary btn-small">Register</a>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </section>

        <section class="card">
            <h2>My Registrations</h2>
            <div class="list">
                <?php if (empty($registrations)): ?>
                    <div class="list-item">
                        <p>You have not registered for any events yet.</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($registrations as $r): ?>
                        <div class="list-item">
                            <p class="item-title">
                                <strong><?php echo htmlspecialchars($r['title']); ?></strong>
                                - <?php echo date('M d, Y', strtotime($r['event_date'])); ?>
                            </p>
                            <span class="status-badge"><?php echo htmlspecialchars($r['status']); ?></span>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </section>
    </main>
</body>
</html>
