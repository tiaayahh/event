<?php
require_once '../includes/auth.php';
checkAuth();
requireRole('attendee');

$events = [];
$reminders = [];
$agendaItems = [];
$errorMessage = '';

try {
    $stmt = $pdo->prepare('SELECT attendee_id FROM attendees WHERE user_id = ? LIMIT 1');
    $stmt->execute([$_SESSION['user_id']]);
    $attendeeId = (int)($stmt->fetchColumn() ?: 0);

    if ($attendeeId <= 0) {
        throw new RuntimeException('Attendee profile not found.');
    }

    $stmt = $pdo->prepare(
        "SELECT e.event_id, e.title, e.event_date, COALESCE(e.venue, '') AS venue
         FROM attendances a
         JOIN events e ON e.event_id = a.event_id
         WHERE a.attendee_id = ? AND a.status = 'registered' AND e.archived_at IS NULL
         ORDER BY e.event_date ASC"
    );
    $stmt->execute([$attendeeId]);
    $events = $stmt->fetchAll();

    foreach ($events as $event) {
        $eventDate = new DateTime((string)$event['event_date']);
        $today = new DateTime('today');
        $days = (int)$today->diff($eventDate)->format('%r%a');
        if ($days >= 0 && $days <= 2) {
            $reminders[] = (string)$event['title'] . ($days === 0 ? ' starts today.' : ' starts in ' . $days . ' day' . ($days === 1 ? '' : 's') . '.');
        }
    }

    foreach (array_slice($events, 0, 8) as $event) {
        $venueLabel = trim((string)($event['venue'] ?? ''));
        if ($venueLabel === '') {
            $venueLabel = 'Venue TBA';
        }
        $agendaItems[] = [
            'title' => (string)$event['title'],
            'event_date' => (string)$event['event_date'],
            'venue' => $venueLabel,
        ];
    }

    if (empty($reminders)) {
        $reminders[] = 'No urgent reminders right now.';
    }
} catch (Throwable $e) {
    $errorMessage = 'Unable to load schedule right now.';
}

$eventsByMonth = [];
foreach ($events as $event) {
    $monthKey = date('F Y', strtotime((string)$event['event_date']));
    if (!isset($eventsByMonth[$monthKey])) {
        $eventsByMonth[$monthKey] = [];
    }
    $eventsByMonth[$monthKey][] = $event;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="../assets/style.css">
    <title>Planora - Schedule</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; }
        body { background: #f4f5fb; color: #2d2d2d; padding-bottom: 76px; }
        .header { background: #6C63FF; color: #fff; padding: 14px 24px; display: flex; justify-content: space-between; align-items: center; }
        .brand { font-size: 22px; font-weight: 700; }
        .logout-btn { background: rgba(255,255,255,.22); color: #fff; text-decoration: none; border-radius: 6px; padding: 7px 14px; font-size: 13px; }
        .container { max-width: 980px; margin: 0 auto; padding: 20px; }
        .notice { background: #ffecec; color: #9d2020; border: 1px solid #f6caca; border-radius: 6px; padding: 10px 12px; margin-bottom: 14px; font-size: 13px; }
        .section { margin-bottom: 16px; }
        .section h2 { font-size: 18px; margin-bottom: 10px; }
        .month-card { background: #fff; border: 1px solid #ece9ff; border-radius: 12px; padding: 12px; margin-bottom: 10px; }
        .item { display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #f0efff; padding: 8px 0; font-size: 13px; }
        .item:last-child { border-bottom: none; }
        .agenda { background: #fff; border: 1px solid #ece9ff; border-radius: 12px; padding: 12px; }
        .agenda-line { padding: 7px 0; border-bottom: 1px solid #f0efff; font-size: 13px; }
        .agenda-line:last-child { border-bottom: none; }
        .reminder-list { list-style: none; display: grid; gap: 8px; }
        .reminder-list li { background: #fff; border: 1px solid #ece9ff; border-radius: 10px; padding: 10px; font-size: 13px; }
        .empty { color: #666; font-size: 13px; }
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
    <?php if ($errorMessage !== ''): ?><div class="notice"><?php echo htmlspecialchars($errorMessage); ?></div><?php endif; ?>

    <section class="section">
        <h2>Calendar View</h2>
        <?php if (empty($eventsByMonth)): ?>
            <p class="empty">No registered events in your schedule yet.</p>
        <?php else: ?>
            <?php foreach ($eventsByMonth as $month => $monthEvents): ?>
                <div class="month-card">
                    <h3><?php echo htmlspecialchars($month); ?></h3>
                    <?php foreach ($monthEvents as $event): ?>
                        <div class="item">
                            <span><?php echo htmlspecialchars((string)$event['title']); ?></span>
                            <span><?php echo htmlspecialchars((string)$event['event_date']); ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </section>

    <section class="section">
        <h2>Your Agenda</h2>
        <div class="agenda">
            <?php if (empty($agendaItems)): ?>
                <p class="empty">No agenda items yet. Register for events to build your agenda.</p>
            <?php else: ?>
                <?php foreach ($agendaItems as $item): ?>
                    <div class="agenda-line">
                        <strong><?php echo htmlspecialchars((string)$item['event_date']); ?></strong>
                        &middot;
                        <?php echo htmlspecialchars((string)$item['title']); ?>
                        &middot;
                        <?php echo htmlspecialchars((string)$item['venue']); ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </section>

    <section class="section">
        <h2>Reminders</h2>
        <ul class="reminder-list">
            <?php foreach ($reminders as $reminder): ?>
                <li><i class="fa-solid fa-bell"></i> <?php echo htmlspecialchars((string)$reminder); ?></li>
            <?php endforeach; ?>
        </ul>
    </section>
</div>

<nav class="bottom-nav">
    <a href="dashboard.php" class="nav-item"><i class="fa-solid fa-house"></i><span>Home</span></a>
    <a href="explore.php" class="nav-item"><i class="fa-solid fa-compass"></i><span>Explore</span></a>
    <a href="my_events.php" class="nav-item"><i class="fa-solid fa-ticket"></i><span>My Events</span></a>
    <a href="schedule.php" class="nav-item active"><i class="fa-solid fa-calendar-days"></i><span>Schedule</span></a>
    <a href="notifications.php" class="nav-item"><i class="fa-solid fa-bell"></i><span>Alerts</span></a>
    <a href="profile.php" class="nav-item"><i class="fa-solid fa-user"></i><span>Profile</span></a>
</nav>
</body>
</html>
