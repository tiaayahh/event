<?php
require_once '../includes/auth.php';
require_once '../includes/csrf.php';
checkAuth();
requireRole('attendee');

$q = trim((string)($_GET['q'] ?? ''));
$categoryFilter = trim((string)($_GET['category'] ?? ''));
$dateFrom = trim((string)($_GET['date_from'] ?? ''));
$dateTo = trim((string)($_GET['date_to'] ?? ''));
$priceFilter = strtolower(trim((string)($_GET['price'] ?? 'all')));
$locationFilter = trim((string)($_GET['location'] ?? ''));
$typeFilter = strtolower(trim((string)($_GET['event_type'] ?? 'all')));
$errorMessage = '';
$events = [];
$featuredOrganizers = [];
$popularVenues = [];
$savedEventIds = [];
$attendeeId = 0;

function ensureAttendeeDiscoverySchema(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }

    $columns = [
        'venue' => "ALTER TABLE events ADD COLUMN venue VARCHAR(190) DEFAULT NULL AFTER event_date",
        'category' => "ALTER TABLE events ADD COLUMN category VARCHAR(64) DEFAULT NULL AFTER title",
        'city' => "ALTER TABLE events ADD COLUMN city VARCHAR(120) DEFAULT NULL AFTER venue",
        'event_type' => "ALTER TABLE events ADD COLUMN event_type ENUM('in_person','online') NOT NULL DEFAULT 'in_person' AFTER city",
        'image_url' => "ALTER TABLE events ADD COLUMN image_url VARCHAR(500) DEFAULT NULL AFTER event_type",
        'tickets_available' => "ALTER TABLE events ADD COLUMN tickets_available INT NOT NULL DEFAULT 200 AFTER ticket_price",
    ];

    foreach ($columns as $name => $alterSql) {
        $stmt = $pdo->query("SHOW COLUMNS FROM events LIKE '" . $name . "'");
        if (!$stmt->fetch()) {
            $pdo->exec($alterSql);
        }
    }

    $ready = true;
}

function ensureAttendeeWishlistSchema(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS attendee_saved_events (
            save_id BIGINT AUTO_INCREMENT PRIMARY KEY,
            attendee_id INT NOT NULL,
            event_id INT NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_attendee_saved_event (attendee_id, event_id),
            INDEX idx_attendee_saved_events_attendee (attendee_id),
            INDEX idx_attendee_saved_events_event (event_id),
            CONSTRAINT fk_attendee_saved_events_attendee FOREIGN KEY (attendee_id) REFERENCES attendees(attendee_id) ON DELETE CASCADE,
            CONSTRAINT fk_attendee_saved_events_event FOREIGN KEY (event_id) REFERENCES events(event_id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $ready = true;
}

function resolveEventImageUrl(?string $rawUrl): string
{
    $url = trim((string)$rawUrl);
    if ($url === '') {
        return '';
    }

    if (preg_match('/^(https?:)?\/\//i', $url) === 1 || stripos($url, 'data:') === 0) {
        return $url;
    }

    return '../' . ltrim($url, '/');
}

try {
    ensureAttendeeDiscoverySchema($pdo);
    ensureAttendeeWishlistSchema($pdo);

    $stmt = $pdo->prepare('SELECT attendee_id FROM attendees WHERE user_id = ? LIMIT 1');
    $stmt->execute([$_SESSION['user_id']]);
    $attendeeId = (int)($stmt->fetchColumn() ?: 0);
    if ($attendeeId <= 0) {
        throw new RuntimeException('Attendee profile not found.');
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_event_id'])) {
        csrf_require_valid_post_token();
        $saveEventId = filter_input(INPUT_POST, 'save_event_id', FILTER_VALIDATE_INT);
        if ($saveEventId) {
            $stmt = $pdo->prepare('INSERT IGNORE INTO attendee_saved_events (attendee_id, event_id) VALUES (?, ?)');
            $stmt->execute([$attendeeId, $saveEventId]);
            $_SESSION['flash_success'] = 'Event saved.';
        }
        header('Location: explore.php?' . http_build_query($_GET));
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['unsave_event_id'])) {
        csrf_require_valid_post_token();
        $unsaveEventId = filter_input(INPUT_POST, 'unsave_event_id', FILTER_VALIDATE_INT);
        if ($unsaveEventId) {
            $stmt = $pdo->prepare('DELETE FROM attendee_saved_events WHERE attendee_id = ? AND event_id = ?');
            $stmt->execute([$attendeeId, $unsaveEventId]);
            $_SESSION['flash_success'] = 'Event removed from wishlist.';
        }
        header('Location: explore.php?' . http_build_query($_GET));
        exit;
    }

    $stmt = $pdo->prepare('SELECT event_id FROM attendee_saved_events WHERE attendee_id = ?');
    $stmt->execute([$attendeeId]);
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $eid) {
        $savedEventIds[(int)$eid] = true;
    }

    $sql =
        "SELECT e.event_id, e.title, e.event_date, COALESCE(e.venue, '') AS venue,
                COALESCE(e.city, '') AS city, COALESCE(e.category, '') AS category,
                COALESCE(e.event_type, 'in_person') AS event_type,
                COALESCE(e.image_url, '') AS image_url,
                COALESCE(e.tickets_available, 200) AS tickets_available,
                COALESCE(e.ticket_price, 0) AS ticket_price,
                u.full_name AS organizer_name,
                COUNT(a.attendance_id) AS attendees_count
         FROM events e
         JOIN users u ON u.user_id = e.planner_id
         LEFT JOIN attendances a ON a.event_id = e.event_id AND a.status IN ('registered','attended')
         WHERE e.archived_at IS NULL AND e.event_date >= CURDATE()";

    $params = [];

    if ($q !== '') {
        $sql .= " AND (e.title LIKE ? OR COALESCE(e.category, '') LIKE ? OR COALESCE(e.city, '') LIKE ? OR COALESCE(e.venue, '') LIKE ? OR u.full_name LIKE ?)";
        $like = '%' . $q . '%';
        array_push($params, $like, $like, $like, $like, $like);
    }

    if ($categoryFilter !== '') {
        $sql .= " AND COALESCE(e.category, '') = ?";
        $params[] = $categoryFilter;
    }

    if ($locationFilter !== '') {
        $sql .= " AND (COALESCE(e.city, '') LIKE ? OR COALESCE(e.venue, '') LIKE ?)";
        $likeLoc = '%' . $locationFilter . '%';
        array_push($params, $likeLoc, $likeLoc);
    }

    if ($dateFrom !== '') {
        $sql .= " AND e.event_date >= ?";
        $params[] = $dateFrom;
    }

    if ($dateTo !== '') {
        $sql .= " AND e.event_date <= ?";
        $params[] = $dateTo;
    }

    if ($priceFilter === 'free') {
        $sql .= " AND COALESCE(e.ticket_price, 0) <= 0";
    } elseif ($priceFilter === 'paid') {
        $sql .= " AND COALESCE(e.ticket_price, 0) > 0";
    }

    if (in_array($typeFilter, ['online', 'in_person'], true)) {
        $sql .= " AND COALESCE(e.event_type, 'in_person') = ?";
        $params[] = $typeFilter;
    }

    $sql .=
        " GROUP BY e.event_id, e.title, e.event_date, e.venue, e.city, e.category, e.event_type, e.image_url, e.tickets_available, e.ticket_price, u.full_name
          ORDER BY e.event_date ASC
          LIMIT 40";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $events = $stmt->fetchAll();

    $stmt = $pdo->query(
        "SELECT u.full_name AS organizer_name, COUNT(e.event_id) AS events_count
         FROM events e
         JOIN users u ON u.user_id = e.planner_id
         WHERE e.archived_at IS NULL AND e.event_date >= CURDATE()
         GROUP BY u.user_id, u.full_name
         ORDER BY events_count DESC, u.full_name ASC
         LIMIT 6"
    );
    $featuredOrganizers = $stmt->fetchAll();

    $stmt = $pdo->query(
        "SELECT COALESCE(e.venue, 'Venue TBA') AS venue_name, COUNT(*) AS events_count
         FROM events e
         WHERE e.archived_at IS NULL AND e.event_date >= CURDATE()
         GROUP BY COALESCE(e.venue, 'Venue TBA')
         ORDER BY events_count DESC, venue_name ASC
         LIMIT 6"
    );
    $popularVenues = $stmt->fetchAll();
} catch (Throwable $e) {
    $errorMessage = 'Could not load events right now.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="../assets/style.css">
    <title>Planora - Explore Events</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }
        body { background: #f4f5fb; color: #2d2d2d; padding-bottom: 76px; }
        .header { background: #6C63FF; color: #fff; padding: 14px 24px; display: flex; justify-content: space-between; align-items: center; }
        .brand { font-size: 22px; font-weight: 700; }
        .logout-btn { background: rgba(255,255,255,.22); color: #fff; text-decoration: none; border-radius: 6px; padding: 7px 14px; font-size: 13px; }
        .container { max-width: 1120px; margin: 0 auto; padding: 20px; }
        .page-title { font-size: 25px; margin-bottom: 14px; }
        .filters { background: #fff; border: 1px solid #ece9ff; border-radius: 12px; padding: 12px; margin-bottom: 14px; display: grid; grid-template-columns: repeat(6, minmax(0,1fr)); gap: 8px; }
        .input { border: 1px solid #cdc8ff; border-radius: 8px; padding: 9px 10px; font-size: 13px; width: 100%; }
        .btn { border: none; border-radius: 8px; padding: 9px 11px; font-size: 12px; font-weight: 700; cursor: pointer; text-decoration: none; display: inline-block; }
        .btn-primary { background: #6C63FF; color: #fff; }
        .btn-light { background: #ece9ff; color: #3f379f; }
        .event-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(260px,1fr)); gap: 10px; margin-bottom: 18px; }
        .card { background: #fff; border: 1px solid #ece9ff; border-radius: 12px; padding: 12px; }
        .thumb { height: 98px; border-radius: 8px; background: linear-gradient(145deg, #6C63FF, #B8A8FF); margin-bottom: 8px; }
        .title { font-size: 15px; font-weight: 700; margin-bottom: 4px; }
        .meta { font-size: 12px; color: #666; margin-bottom: 3px; }
        .action-row { display: flex; gap: 6px; flex-wrap: wrap; margin-top: 8px; }
        .aux-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
        .list { list-style: none; display: grid; gap: 8px; }
        .list li { background: #fff; border: 1px solid #ece9ff; border-radius: 10px; padding: 10px; font-size: 13px; }
        .empty { color: #666; font-size: 13px; }
        .bottom-nav { position: fixed; bottom: 0; left: 0; right: 0; height: 62px; background: #6C63FF; display: flex; z-index: 999; }
        .nav-item { flex: 1; color: rgba(255,255,255,.76); text-decoration: none; font-size: 11px; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 4px; }
        .nav-item i { font-size: 15px; }
        .nav-item.active, .nav-item:hover { color: #fff; background: rgba(255,255,255,.1); }
        @media (max-width: 980px) { .filters { grid-template-columns: repeat(2, minmax(0,1fr)); } .aux-grid { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
<header class="header">
    <div class="brand">PLANORA</div>
    <a class="logout-btn" href="../logout.php">Logout</a>
</header>
<div class="container">
    <h1 class="page-title">Explore Events</h1>

    <?php if ($errorMessage !== ''): ?><p class="empty"><?php echo htmlspecialchars($errorMessage); ?></p><?php endif; ?>

    <form class="filters" method="GET">
        <input class="input" type="text" name="q" value="<?php echo htmlspecialchars($q); ?>" placeholder="Search name, organizer, city...">
        <input class="input" type="text" name="category" value="<?php echo htmlspecialchars($categoryFilter); ?>" placeholder="Category">
        <input class="input" type="text" name="location" value="<?php echo htmlspecialchars($locationFilter); ?>" placeholder="Location">
        <select class="input" name="price">
            <option value="all" <?php echo $priceFilter === 'all' ? 'selected' : ''; ?>>Price: All</option>
            <option value="free" <?php echo $priceFilter === 'free' ? 'selected' : ''; ?>>Free</option>
            <option value="paid" <?php echo $priceFilter === 'paid' ? 'selected' : ''; ?>>Paid</option>
        </select>
        <select class="input" name="event_type">
            <option value="all" <?php echo $typeFilter === 'all' ? 'selected' : ''; ?>>Type: All</option>
            <option value="in_person" <?php echo $typeFilter === 'in_person' ? 'selected' : ''; ?>>In-person</option>
            <option value="online" <?php echo $typeFilter === 'online' ? 'selected' : ''; ?>>Online</option>
        </select>
        <button class="btn btn-primary" type="submit"><i class="fa-solid fa-filter"></i> Apply</button>
        <input class="input" type="date" name="date_from" value="<?php echo htmlspecialchars($dateFrom); ?>">
        <input class="input" type="date" name="date_to" value="<?php echo htmlspecialchars($dateTo); ?>">
    </form>

    <div class="event-grid">
        <?php if (empty($events)): ?>
            <p class="empty">No events match your current filters.</p>
        <?php else: ?>
            <?php foreach ($events as $event): ?>
                <?php
                $eid = (int)$event['event_id'];
                $price = (float)$event['ticket_price'];
                $availableTickets = max(0, (int)$event['tickets_available'] - (int)$event['attendees_count']);
                $score = min(5.0, 3.0 + ((int)$event['attendees_count'] / 20.0));
                $eventImage = resolveEventImageUrl((string)($event['image_url'] ?? ''));
                ?>
                <div class="card">
                    <div class="thumb"<?php if ($eventImage !== ''): ?> style="background-image: url('<?php echo htmlspecialchars($eventImage); ?>'); background-size: cover; background-position: center;"<?php endif; ?>></div>
                    <div class="title"><?php echo htmlspecialchars((string)$event['title']); ?></div>
                    <div class="meta"><?php echo htmlspecialchars((string)$event['event_date']); ?> &middot; <?php echo htmlspecialchars((string)(($event['venue'] ?? '') !== '' ? $event['venue'] : 'Venue TBA')); ?></div>
                    <div class="meta">Category: <?php echo htmlspecialchars((string)(($event['category'] ?? '') !== '' ? $event['category'] : 'General')); ?> &middot; <?php echo htmlspecialchars((string)(($event['event_type'] ?? 'in_person') === 'online' ? 'Online' : 'In-person')); ?></div>
                    <div class="meta">Price: <?php echo $price <= 0 ? 'Free' : ('KES ' . number_format($price, 2)); ?></div>
                    <div class="meta">Available Tickets: <?php echo $availableTickets; ?></div>
                    <div class="meta">Rating: <?php echo number_format($score, 1); ?> / 5</div>
                    <div class="action-row">
                        <a class="btn btn-light" href="register_event.php?event_id=<?php echo $eid; ?>">View Details</a>
                        <a class="btn btn-primary" href="register_event.php?event_id=<?php echo $eid; ?>">Register</a>
                        <?php if (!isset($savedEventIds[$eid])): ?>
                            <form method="POST" style="display:inline;">
                                <?php echo csrf_input(); ?>
                                <input type="hidden" name="save_event_id" value="<?php echo $eid; ?>">
                                <button class="btn" type="submit">Save</button>
                            </form>
                        <?php else: ?>
                            <form method="POST" style="display:inline;">
                                <?php echo csrf_input(); ?>
                                <input type="hidden" name="unsave_event_id" value="<?php echo $eid; ?>">
                                <button class="btn" type="submit">Unsave</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <section class="aux-grid">
        <div>
            <h2>Featured Organizers</h2>
            <ul class="list">
                <?php if (empty($featuredOrganizers)): ?>
                    <li>No organizer highlights yet.</li>
                <?php else: ?>
                    <?php foreach ($featuredOrganizers as $org): ?>
                        <li><?php echo htmlspecialchars((string)$org['organizer_name']); ?> (<?php echo (int)$org['events_count']; ?> events)</li>
                    <?php endforeach; ?>
                <?php endif; ?>
            </ul>
        </div>
        <div>
            <h2>Popular Venues</h2>
            <ul class="list">
                <?php if (empty($popularVenues)): ?>
                    <li>No venues available yet.</li>
                <?php else: ?>
                    <?php foreach ($popularVenues as $venue): ?>
                        <li><?php echo htmlspecialchars((string)$venue['venue_name']); ?> (<?php echo (int)$venue['events_count']; ?> events)</li>
                    <?php endforeach; ?>
                <?php endif; ?>
            </ul>
        </div>
    </section>
</div>

<nav class="bottom-nav">
    <a href="dashboard.php" class="nav-item"><i class="fa-solid fa-house"></i><span>Home</span></a>
    <a href="explore.php" class="nav-item active"><i class="fa-solid fa-compass"></i><span>Explore</span></a>
    <a href="my_events.php" class="nav-item"><i class="fa-solid fa-ticket"></i><span>My Events</span></a>
    <a href="schedule.php" class="nav-item"><i class="fa-solid fa-calendar-days"></i><span>Schedule</span></a>
    <a href="notifications.php" class="nav-item"><i class="fa-solid fa-bell"></i><span>Alerts</span></a>
    <a href="profile.php" class="nav-item"><i class="fa-solid fa-user"></i><span>Profile</span></a>
</nav>
</body>
</html>
