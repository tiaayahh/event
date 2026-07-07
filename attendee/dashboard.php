<?php
require_once '../includes/auth.php';
require_once '../includes/csrf.php';
checkAuth();
requireRole('attendee');

$fullName = $_SESSION['full_name'] ?? 'Attendee';
$search = trim((string)($_GET['q'] ?? ''));
$flashSuccess = (string)($_SESSION['flash_success'] ?? '');
$flashError = (string)($_SESSION['flash_error'] ?? '');
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

$errorMessage = '';
$attendeeId = 0;
$heroEvents = [];
$trendingEvents = [];
$recommendedEvents = [];
$upcomingEvents = [];
$notificationsPreview = [];
$categoryTiles = [];
$registeredEventIds = [];
$savedEventIds = [];
$stats = [
    'registered' => 0,
    'saved' => 0,
    'completed' => 0,
];

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

function ensureAttendeeTicketPaymentsTable(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS attendee_ticket_payments (
            payment_id BIGINT AUTO_INCREMENT PRIMARY KEY,
            event_id INT NOT NULL,
            attendee_id INT NOT NULL,
            mpesa_code VARCHAR(64) DEFAULT NULL,
            amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            status ENUM('requested', 'paid', 'failed') NOT NULL DEFAULT 'requested',
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_attendee_ticket_payment (event_id, attendee_id),
            INDEX idx_attendee_ticket_payment_attendee (attendee_id),
            CONSTRAINT fk_attendee_ticket_payment_event FOREIGN KEY (event_id) REFERENCES events(event_id) ON DELETE CASCADE,
            CONSTRAINT fk_attendee_ticket_payment_attendee FOREIGN KEY (attendee_id) REFERENCES attendees(attendee_id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $ready = true;
}

function inferCategory(string $title, ?string $dbCategory): string
{
    $dbCategory = trim((string)$dbCategory);
    if ($dbCategory !== '') {
        return ucfirst(strtolower($dbCategory));
    }

    $text = strtolower($title);
    $map = [
        'Music' => ['music', 'concert', 'jazz', 'festival', 'dj'],
        'Business' => ['business', 'summit', 'startup', 'expo', 'conference'],
        'Education' => ['education', 'workshop', 'training', 'class', 'bootcamp'],
        'Sports' => ['sport', 'marathon', 'run', 'fitness', 'football'],
        'Arts' => ['art', 'culture', 'gallery', 'creative'],
        'Food' => ['food', 'chef', 'culinary', 'dining'],
        'Family' => ['family', 'kids', 'children'],
        'Charity' => ['charity', 'fundraiser', 'donation', 'cause'],
    ];

    foreach ($map as $category => $keywords) {
        foreach ($keywords as $keyword) {
            if (strpos($text, $keyword) !== false) {
                return $category;
            }
        }
    }

    return 'General';
}

function resolveEventImageUrl(?string $rawUrl): string
{
    $url = trim((string)$rawUrl);
    if ($url === '') {
        return '';
    }

    $url = str_replace('\\', '/', $url);

    if (preg_match('/^(https?:)?\/\//i', $url) === 1 || stripos($url, 'data:') === 0) {
        return $url;
    }

    if (strpos($url, '../') === 0 || strpos($url, './') === 0) {
        return $url;
    }

    if (strpos($url, '/') === 0) {
        return '..' . $url;
    }

    return '../' . ltrim($url, '/');
}

try {
    ensureAttendeeDiscoverySchema($pdo);
    ensureAttendeeWishlistSchema($pdo);
    ensureAttendeeTicketPaymentsTable($pdo);

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
            $_SESSION['flash_success'] = 'Event saved to your wishlist.';
        }
        header('Location: dashboard.php?q=' . urlencode($search));
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['unsave_event_id'])) {
        csrf_require_valid_post_token();
        $unsaveEventId = filter_input(INPUT_POST, 'unsave_event_id', FILTER_VALIDATE_INT);
        if ($unsaveEventId) {
            $stmt = $pdo->prepare('DELETE FROM attendee_saved_events WHERE attendee_id = ? AND event_id = ?');
            $stmt->execute([$attendeeId, $unsaveEventId]);
            $_SESSION['flash_success'] = 'Event removed from your wishlist.';
        }
        header('Location: dashboard.php?q=' . urlencode($search));
        exit;
    }

    $stmt = $pdo->prepare('SELECT event_id FROM attendances WHERE attendee_id = ? AND status IN (\'registered\', \'attended\')');
    $stmt->execute([$attendeeId]);
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $eid) {
        $registeredEventIds[(int)$eid] = true;
    }

    $stmt = $pdo->prepare('SELECT event_id FROM attendee_saved_events WHERE attendee_id = ?');
    $stmt->execute([$attendeeId]);
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $eid) {
        $savedEventIds[(int)$eid] = true;
    }

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM attendances WHERE attendee_id = ? AND status = \'registered\'');
    $stmt->execute([$attendeeId]);
    $stats['registered'] = (int)$stmt->fetchColumn();

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM attendee_saved_events WHERE attendee_id = ?');
    $stmt->execute([$attendeeId]);
    $stats['saved'] = (int)$stmt->fetchColumn();

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM attendances WHERE attendee_id = ? AND status = \'attended\'');
    $stmt->execute([$attendeeId]);
    $stats['completed'] = (int)$stmt->fetchColumn();

    $stmt = $pdo->query(
        "SELECT e.event_id, e.title, e.event_date, e.ticket_price, COALESCE(e.venue, '') AS venue,
                COALESCE(e.city, '') AS city, COALESCE(e.category, '') AS category, COALESCE(e.image_url, '') AS image_url,
                u.full_name AS organizer_name,
                COUNT(a.attendance_id) AS interest_count
         FROM events e
         JOIN users u ON u.user_id = e.planner_id
         LEFT JOIN attendances a ON a.event_id = e.event_id AND a.status IN ('registered', 'attended')
         WHERE e.archived_at IS NULL AND e.event_date >= CURDATE()
         GROUP BY e.event_id, e.title, e.event_date, e.ticket_price, e.venue, e.city, e.category, e.image_url, u.full_name
         ORDER BY CASE WHEN COALESCE(e.image_url, '') = '' THEN 1 ELSE 0 END ASC,
                  interest_count DESC,
                  e.event_date ASC
         LIMIT 6"
    );
    $heroEvents = $stmt->fetchAll();

    $trendingEvents = $heroEvents;

    $upcomingSql =
        "SELECT e.event_id, e.title, e.event_date, e.ticket_price, COALESCE(e.venue, '') AS venue,
                COALESCE(e.image_url, '') AS image_url,
                COALESCE(e.city, '') AS city, COALESCE(e.category, '') AS category,
                u.full_name AS organizer_name,
                COUNT(a.attendance_id) AS interest_count
         FROM events e
         JOIN users u ON u.user_id = e.planner_id
         LEFT JOIN attendances a ON a.event_id = e.event_id AND a.status IN ('registered', 'attended')
         WHERE e.archived_at IS NULL AND e.event_date >= CURDATE()";
    $upcomingParams = [];

    if ($search !== '') {
        $upcomingSql .= " AND (e.title LIKE ? OR COALESCE(e.category, '') LIKE ? OR COALESCE(e.city, '') LIKE ? OR u.full_name LIKE ? OR COALESCE(e.venue, '') LIKE ? )";
        $like = '%' . $search . '%';
        $upcomingParams = [$like, $like, $like, $like, $like];
    }

    $upcomingSql .= " GROUP BY e.event_id, e.title, e.event_date, e.ticket_price, e.venue, e.image_url, e.city, e.category, u.full_name ORDER BY e.event_date ASC LIMIT 10";
    $stmt = $pdo->prepare($upcomingSql);
    $stmt->execute($upcomingParams);
    $upcomingEvents = $stmt->fetchAll();

    $stmt = $pdo->prepare(
        "SELECT COALESCE(NULLIF(TRIM(e.category), ''), 'General') AS category_name, COUNT(*) AS total_events
         FROM events e
         WHERE e.archived_at IS NULL AND e.event_date >= CURDATE()
         GROUP BY COALESCE(NULLIF(TRIM(e.category), ''), 'General')
         ORDER BY total_events DESC, category_name ASC
         LIMIT 8"
    );
    $stmt->execute();
    $categoryTiles = $stmt->fetchAll();

    $stmt = $pdo->prepare(
        "SELECT e.title, e.category
         FROM attendances a
         JOIN events e ON e.event_id = a.event_id
         WHERE a.attendee_id = ? AND a.status IN ('registered', 'attended')"
    );
    $stmt->execute([$attendeeId]);

    $preferredCategories = [];
    foreach ($stmt->fetchAll() as $row) {
        $cat = inferCategory((string)$row['title'], (string)($row['category'] ?? ''));
        $preferredCategories[$cat] = ($preferredCategories[$cat] ?? 0) + 1;
    }
    arsort($preferredCategories);
    $preferredCategoryNames = array_slice(array_keys($preferredCategories), 0, 3);

    if (!empty($preferredCategoryNames)) {
        $stmt = $pdo->query(
                "SELECT e.event_id, e.title, e.event_date, e.ticket_price, COALESCE(e.venue, '') AS venue,
                    COALESCE(e.image_url, '') AS image_url,
                    COALESCE(e.city, '') AS city, COALESCE(e.category, '') AS category, u.full_name AS organizer_name
             FROM events e
             JOIN users u ON u.user_id = e.planner_id
             WHERE e.archived_at IS NULL AND e.event_date >= CURDATE()
             ORDER BY e.event_date ASC
             LIMIT 40"
        );

        foreach ($stmt->fetchAll() as $row) {
            $categoryName = inferCategory((string)$row['title'], (string)($row['category'] ?? ''));
            if (in_array($categoryName, $preferredCategoryNames, true) && !isset($registeredEventIds[(int)$row['event_id']])) {
                $recommendedEvents[] = $row;
            }
            if (count($recommendedEvents) >= 6) {
                break;
            }
        }
    }

    $stmt = $pdo->prepare(
        "SELECT e.title, p.updated_at
         FROM attendee_ticket_payments p
         JOIN events e ON e.event_id = p.event_id
         WHERE p.attendee_id = ? AND p.status = 'paid'
         ORDER BY p.updated_at DESC
         LIMIT 2"
    );
    $stmt->execute([$attendeeId]);
    foreach ($stmt->fetchAll() as $row) {
        $notificationsPreview[] = 'Your ticket for ' . (string)$row['title'] . ' has been confirmed.';
    }

    $stmt = $pdo->prepare(
        "SELECT e.title, e.event_date
         FROM attendances a
         JOIN events e ON e.event_id = a.event_id
         WHERE a.attendee_id = ? AND a.status = 'registered' AND e.archived_at IS NULL
           AND e.event_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 2 DAY)
         ORDER BY e.event_date ASC"
    );
    $stmt->execute([$attendeeId]);
    foreach ($stmt->fetchAll() as $row) {
        $days = (int)((new DateTime())->diff(new DateTime((string)$row['event_date']))->format('%r%a'));
        $notificationsPreview[] = (string)$row['title'] . ($days <= 0 ? ' starts today.' : ' starts in ' . $days . ' day' . ($days === 1 ? '' : 's') . '.');
    }

    if (empty($notificationsPreview)) {
        $notificationsPreview[] = 'No new alerts right now. Explore events to get personalized reminders.';
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
    <title>Planora - Attendee Home</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; }
        body { background: #f4f5fb; color: #2d2d2d; padding-bottom: 76px; }
        .header { background: #6C63FF; color: #fff; padding: 14px 24px; display: flex; justify-content: space-between; align-items: center; }
        .brand { font-size: 22px; font-weight: 700; }
        .logout-btn { background: rgba(255,255,255,.24); color: #fff; text-decoration: none; border-radius: 6px; padding: 7px 14px; font-size: 13px; }
        .container { max-width: 1100px; margin: 0 auto; padding: 20px; }
        .welcome { font-size: 16px; font-weight: 700; margin-bottom: 14px; }
        .notice { border-radius: 6px; padding: 10px 12px; margin-bottom: 14px; font-size: 13px; }
        .notice.ok { background: #ecfff0; color: #1c7a36; border: 1px solid #c9f0d4; }
        .notice.err { background: #ffecec; color: #9d2020; border: 1px solid #f6caca; }
        .hero-wrap {
            margin-bottom: 16px;
        }
        .hero-banner-link {
            display: block;
            border-radius: 14px;
            overflow: hidden;
            border: 1px solid #ece9ff;
            background: #fff;
            text-decoration: none;
        }
        .hero-banner {
            width: 100%;
            height: 260px;
            background: linear-gradient(140deg, #6C63FF, #1f1d35);
            position: relative;
            overflow: hidden;
        }
        .hero-banner img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
        }
        .hero-caption {
            background: #fff;
            padding: 12px 14px;
            color: #2d2d2d;
            display: flex;
            justify-content: space-between;
            gap: 10px;
            align-items: center;
        }
        .hero-caption-main {
            min-width: 0;
        }
        .hero-sub { color: #5f58c8; font-size: 12px; font-weight: 700; margin-bottom: 3px; }
        .hero-title { font-size: 17px; font-weight: 800; margin-bottom: 2px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .hero-meta { font-size: 12px; color: #666; }
        .hero-btn { display: inline-block; text-decoration: none; color: #fff; background: #6C63FF; border-radius: 999px; padding: 8px 12px; font-size: 12px; font-weight: 700; white-space: nowrap; }
        .search-form { display: flex; gap: 10px; margin-bottom: 16px; }
        .search-input { flex: 1; border: 1px solid #cdc8ff; border-radius: 10px; padding: 11px 13px; font-size: 14px; }
        .btn { border: none; border-radius: 10px; padding: 10px 13px; cursor: pointer; font-weight: 700; font-size: 13px; text-decoration: none; display: inline-block; }
        .btn-primary { background: #6C63FF; color: #fff; }
        .stats-grid { display: grid; grid-template-columns: repeat(3, minmax(0,1fr)); gap: 10px; margin-bottom: 18px; }
        .stat { background: #fff; border: 1px solid #ece9ff; border-radius: 10px; padding: 12px; }
        .stat b { font-size: 20px; display: block; }
        .stat span { font-size: 12px; color: #666; }
        .section { margin-bottom: 18px; }
        .section h2 { font-size: 18px; margin-bottom: 10px; }
        .categories { display: grid; grid-template-columns: repeat(4, minmax(0,1fr)); gap: 8px; }
        .cat-card { background: #fff; border: 1px solid #ece9ff; border-radius: 10px; padding: 10px; text-align: center; text-decoration: none; color: #2d2d2d; font-size: 12px; font-weight: 700; }
        .cards { display: grid; grid-template-columns: repeat(auto-fill, minmax(240px,1fr)); gap: 10px; }
        .event-card { background: #fff; border: 1px solid #ece9ff; border-radius: 12px; padding: 12px; }
        .thumb { height: 88px; border-radius: 8px; background: linear-gradient(130deg, #6C63FF, #B8A8FF); margin-bottom: 9px; overflow: hidden; }
        .thumb img { width: 100%; height: 100%; object-fit: cover; display: block; }
        .title { font-size: 15px; font-weight: 700; margin-bottom: 4px; }
        .meta { font-size: 12px; color: #666; margin-bottom: 4px; }
        .action-row { display: flex; gap: 6px; flex-wrap: wrap; margin-top: 8px; }
        .btn-light { background: #ece9ff; color: #3f379f; }
        .btn-ghost { background: #f5f5f5; color: #454545; }
        .note-list { list-style: none; display: grid; gap: 8px; }
        .note-list li { background: #fff; border: 1px solid #ece9ff; border-radius: 10px; padding: 10px; font-size: 13px; }
        .empty { color: #666; font-size: 13px; }
        .bottom-nav { position: fixed; bottom: 0; left: 0; right: 0; height: 62px; background: #6C63FF; display: flex; z-index: 999; }
        .nav-item { flex: 1; color: rgba(255,255,255,.76); text-decoration: none; font-size: 11px; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 4px; }
        .nav-item i { font-size: 15px; }
        .nav-item.active, .nav-item:hover { color: #fff; background: rgba(255,255,255,.1); }
        @media (max-width: 900px) { .categories { grid-template-columns: repeat(2, minmax(0,1fr)); } .stats-grid { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
<header class="header">
    <div class="brand">PLANORA</div>
    <a class="logout-btn" href="../logout.php">Logout</a>
</header>
<div class="container">
    <p class="welcome">Welcome, <?php echo htmlspecialchars($fullName); ?>.</p>

    <?php if ($flashSuccess !== ''): ?><div class="notice ok"><?php echo htmlspecialchars($flashSuccess); ?></div><?php endif; ?>
    <?php if ($flashError !== ''): ?><div class="notice err"><?php echo htmlspecialchars($flashError); ?></div><?php endif; ?>
    <?php if ($errorMessage !== ''): ?><div class="notice err"><?php echo htmlspecialchars($errorMessage); ?></div><?php endif; ?>

    <?php
    $featuredHeroEvent = !empty($heroEvents) ? $heroEvents[0] : ['title' => 'Discover More Events', 'event_date' => date('Y-m-d'), 'venue' => 'Multiple locations', 'event_id' => 0, 'image_url' => ''];
    $featuredHeroImage = resolveEventImageUrl((string)($featuredHeroEvent['image_url'] ?? ''));
    $featuredHeroHref = ((int)($featuredHeroEvent['event_id'] ?? 0) > 0)
        ? ('register_event.php?event_id=' . (int)$featuredHeroEvent['event_id'])
        : 'explore.php';
    ?>
    <section class="hero-wrap">
        <a class="hero-banner-link" href="<?php echo htmlspecialchars($featuredHeroHref); ?>">
            <div class="hero-banner">
                <?php if ($featuredHeroImage !== ''): ?>
                    <img src="<?php echo htmlspecialchars($featuredHeroImage); ?>" alt="Featured event banner">
                <?php endif; ?>
            </div>
            <div class="hero-caption">
                <div class="hero-caption-main">
                    <div class="hero-sub">Featured Event Banner</div>
                    <div class="hero-title"><?php echo htmlspecialchars((string)$featuredHeroEvent['title']); ?></div>
                    <div class="hero-meta"><?php echo htmlspecialchars((string)$featuredHeroEvent['event_date']); ?><?php if ((string)($featuredHeroEvent['venue'] ?? '') !== ''): ?> &middot; <?php echo htmlspecialchars((string)$featuredHeroEvent['venue']); ?><?php endif; ?></div>
                </div>
                <span class="hero-btn">View Event</span>
            </div>
        </a>
    </section>

    <form class="search-form" method="GET">
        <input class="search-input" type="text" name="q" value="<?php echo htmlspecialchars($search); ?>" placeholder="Search by event, category, city, organizer...">
        <button class="btn btn-primary" type="submit"><i class="fa-solid fa-magnifying-glass"></i> Search</button>
    </form>

    <section class="stats-grid">
        <div class="stat"><b><?php echo (int)$stats['registered']; ?></b><span>Registered Events</span></div>
        <div class="stat"><b><?php echo (int)$stats['saved']; ?></b><span>Saved Events</span></div>
        <div class="stat"><b><?php echo (int)$stats['completed']; ?></b><span>Completed Events</span></div>
    </section>

    <section class="section">
        <h2>Event Categories</h2>
        <div class="categories">
            <?php if (empty($categoryTiles)): ?>
                <p class="empty">No category data yet. Create events to populate this view.</p>
            <?php else: ?>
                <?php foreach ($categoryTiles as $category): ?>
                    <?php $categoryName = (string)($category['category_name'] ?? 'General'); ?>
                    <a class="cat-card" href="explore.php?category=<?php echo urlencode($categoryName); ?>">
                        <?php echo htmlspecialchars($categoryName); ?>
                    </a>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </section>

    <section class="section">
        <h2>Trending Events</h2>
        <div class="cards">
            <?php if (empty($trendingEvents)): ?>
                <p class="empty">No trending events yet.</p>
            <?php else: ?>
                <?php foreach (array_slice($trendingEvents, 0, 6) as $event): ?>
                    <?php $eid = (int)$event['event_id']; ?>
                    <?php $eventImage = resolveEventImageUrl((string)($event['image_url'] ?? '')); ?>
                    <div class="event-card">
                        <div class="thumb">
                            <?php if ($eventImage !== ''): ?>
                                <img src="<?php echo htmlspecialchars($eventImage); ?>" alt="Event banner">
                            <?php endif; ?>
                        </div>
                        <div class="title"><?php echo htmlspecialchars((string)$event['title']); ?></div>
                        <div class="meta"><?php echo htmlspecialchars((string)$event['event_date']); ?> &middot; <?php echo htmlspecialchars((string)($event['venue'] ?: 'Venue TBA')); ?></div>
                        <div class="meta">Price: KES <?php echo number_format((float)$event['ticket_price'], 2); ?> &middot; Interest: <?php echo (int)($event['interest_count'] ?? 0); ?></div>
                        <div class="action-row">
                            <a class="btn btn-light" href="register_event.php?event_id=<?php echo $eid; ?>">Register</a>
                            <?php if (!isset($savedEventIds[$eid])): ?>
                                <form method="POST" style="display:inline;">
                                    <?php echo csrf_input(); ?>
                                    <input type="hidden" name="save_event_id" value="<?php echo $eid; ?>">
                                    <button class="btn btn-ghost" type="submit">Save</button>
                                </form>
                            <?php else: ?>
                                <form method="POST" style="display:inline;">
                                    <?php echo csrf_input(); ?>
                                    <input type="hidden" name="unsave_event_id" value="<?php echo $eid; ?>">
                                    <button class="btn btn-ghost" type="submit">Unsave</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </section>

    <section class="section">
        <h2>Recommended for You</h2>
        <div class="cards">
            <?php if (empty($recommendedEvents)): ?>
                <p class="empty">Register for a few events to get smarter recommendations.</p>
            <?php else: ?>
                <?php foreach ($recommendedEvents as $event): ?>
                    <div class="event-card">
                        <div class="title"><?php echo htmlspecialchars((string)$event['title']); ?></div>
                        <div class="meta"><?php echo htmlspecialchars((string)inferCategory((string)$event['title'], (string)($event['category'] ?? ''))); ?> &middot; <?php echo htmlspecialchars((string)$event['event_date']); ?></div>
                        <div class="meta">Because you attended similar events.</div>
                        <a class="btn btn-light" href="register_event.php?event_id=<?php echo (int)$event['event_id']; ?>">View Event</a>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </section>

    <section class="section">
        <h2>Upcoming Events</h2>
        <div class="cards">
            <?php if (empty($upcomingEvents)): ?>
                <p class="empty">No upcoming events found.</p>
            <?php else: ?>
                <?php foreach ($upcomingEvents as $event): ?>
                    <div class="event-card">
                        <div class="title"><?php echo htmlspecialchars((string)$event['title']); ?></div>
                        <div class="meta"><?php echo htmlspecialchars((string)$event['event_date']); ?> &middot; <?php echo htmlspecialchars((string)(($event['venue'] ?? '') !== '' ? $event['venue'] : 'Venue TBA')); ?></div>
                        <div class="meta">Organizer: <?php echo htmlspecialchars((string)$event['organizer_name']); ?></div>
                        <a class="btn btn-light" href="register_event.php?event_id=<?php echo (int)$event['event_id']; ?>">View Details</a>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </section>

    <section class="section">
        <h2>Notifications Preview</h2>
        <ul class="note-list">
            <?php foreach ($notificationsPreview as $note): ?>
                <li><i class="fa-solid fa-bell"></i> <?php echo htmlspecialchars((string)$note); ?></li>
            <?php endforeach; ?>
        </ul>
    </section>
</div>

<nav class="bottom-nav">
    <a href="dashboard.php" class="nav-item active"><i class="fa-solid fa-house"></i><span>Home</span></a>
    <a href="explore.php" class="nav-item"><i class="fa-solid fa-compass"></i><span>Explore</span></a>
    <a href="my_events.php" class="nav-item"><i class="fa-solid fa-ticket"></i><span>My Events</span></a>
    <a href="schedule.php" class="nav-item"><i class="fa-solid fa-calendar-days"></i><span>Schedule</span></a>
    <a href="notifications.php" class="nav-item"><i class="fa-solid fa-bell"></i><span>Alerts</span></a>
    <a href="profile.php" class="nav-item"><i class="fa-solid fa-user"></i><span>Profile</span></a>
</nav>
</body>
</html>
