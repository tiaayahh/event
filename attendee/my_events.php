<?php
require_once '../includes/auth.php';
require_once '../includes/csrf.php';
checkAuth();
requireRole('attendee');

$fullName = $_SESSION['full_name'] ?? 'Attendee';
$flashSuccess = (string)($_SESSION['flash_success'] ?? '');
$flashError = (string)($_SESSION['flash_error'] ?? '');
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

$errorMessage = '';
$attendeeId = 0;
$upcomingEvents = [];
$completedEvents = [];
$wishlistEvents = [];
$ticketCards = [];
$stats = ['registered' => 0, 'saved' => 0, 'completed' => 0];

function ensureAttendeeDiscoverySchema(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }

    $columns = [
        'venue' => "ALTER TABLE events ADD COLUMN venue VARCHAR(190) DEFAULT NULL AFTER event_date",
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
            ticket_type VARCHAR(32) NOT NULL DEFAULT 'regular',
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

    $stmt = $pdo->query("SHOW COLUMNS FROM attendee_ticket_payments LIKE 'ticket_type'");
    if (!$stmt->fetch()) {
        $pdo->exec("ALTER TABLE attendee_ticket_payments ADD COLUMN ticket_type VARCHAR(32) NOT NULL DEFAULT 'regular' AFTER attendee_id");
    }

    $ready = true;
}

function ensureEventTicketTypesSchema(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }

    $stmt = $pdo->query("SHOW COLUMNS FROM event_ticket_types LIKE 'tickets_remaining'");
    if (!$stmt->fetch()) {
        $pdo->exec("ALTER TABLE event_ticket_types ADD COLUMN tickets_remaining INT NOT NULL DEFAULT 0 AFTER description");
    }

    $ready = true;
}

function attendeeTicketTypeLabel(string $ticketType): string
{
    $labels = [
        'early_bird' => 'Early Bird',
        'regular' => 'Regular',
        'vip' => 'VIP',
        'vvip' => 'VVIP',
    ];

    $key = strtolower(trim($ticketType));
    if ($key === '') {
        return 'Regular';
    }

    return $labels[$key] ?? ucfirst(str_replace('_', ' ', $key));
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
    ensureAttendeeTicketPaymentsTable($pdo);
    ensureEventTicketTypesSchema($pdo);

    $stmt = $pdo->prepare('SELECT attendee_id FROM attendees WHERE user_id = ? LIMIT 1');
    $stmt->execute([$_SESSION['user_id']]);
    $attendeeId = (int)($stmt->fetchColumn() ?: 0);
    if ($attendeeId <= 0) {
        throw new RuntimeException('Attendee profile not found.');
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cancel_event_id'])) {
        csrf_require_valid_post_token();
        $cancelEventId = filter_input(INPUT_POST, 'cancel_event_id', FILTER_VALIDATE_INT);
        if ($cancelEventId) {
            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare(
                    "SELECT COALESCE(p.ticket_type, 'regular') AS ticket_type
                     FROM attendances a
                     LEFT JOIN attendee_ticket_payments p ON p.event_id = a.event_id AND p.attendee_id = a.attendee_id
                     JOIN events e ON e.event_id = a.event_id
                     WHERE a.attendee_id = ? AND a.event_id = ? AND a.status = 'registered' AND e.event_date >= CURDATE()
                     LIMIT 1"
                );
                $stmt->execute([$attendeeId, $cancelEventId]);
                $row = $stmt->fetch();

                if ($row) {
                    $ticketType = strtolower(trim((string)($row['ticket_type'] ?? 'regular')));

                    $stmt = $pdo->prepare(
                        "UPDATE attendances a
                         JOIN events e ON e.event_id = a.event_id
                         SET a.status = 'cancelled'
                         WHERE a.attendee_id = ? AND a.event_id = ? AND a.status = 'registered' AND e.event_date >= CURDATE()"
                    );
                    $stmt->execute([$attendeeId, $cancelEventId]);

                    if ($stmt->rowCount() > 0) {
                        $stmt = $pdo->prepare("UPDATE event_ticket_types SET tickets_remaining = tickets_remaining + 1 WHERE event_id = ? AND ticket_type = ? LIMIT 1");
                        $stmt->execute([$cancelEventId, $ticketType]);

                        $stmt = $pdo->prepare("UPDATE events SET tickets_available = tickets_available + 1 WHERE event_id = ?");
                        $stmt->execute([$cancelEventId]);

                        $_SESSION['flash_success'] = 'Registration cancelled.';
                    } else {
                        $_SESSION['flash_error'] = 'Cancellation is not allowed for this event.';
                    }
                } else {
                    $_SESSION['flash_error'] = 'Cancellation is not allowed for this event.';
                }

                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $_SESSION['flash_error'] = 'Unable to cancel registration right now.';
            }
        }
        header('Location: my_events.php');
        exit;
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

    $stmt = $pdo->prepare(
        "SELECT e.event_id, e.title, e.event_date, COALESCE(e.venue, '') AS venue,
            COALESCE(e.image_url, '') AS image_url,
                COALESCE(e.category, '') AS category,
                COALESCE(e.event_type, 'in_person') AS event_type,
                a.status, COALESCE(p.status, 'requested') AS payment_status, COALESCE(p.mpesa_code, '') AS mpesa_code,
                COALESCE(p.ticket_type, 'regular') AS attendee_ticket_type,
                COALESCE(p.amount, e.ticket_price) AS ticket_amount
         FROM attendances a
         JOIN events e ON e.event_id = a.event_id
         LEFT JOIN attendee_ticket_payments p ON p.event_id = a.event_id AND p.attendee_id = a.attendee_id
         WHERE a.attendee_id = ? AND e.archived_at IS NULL
         ORDER BY e.event_date ASC"
    );
    $stmt->execute([$attendeeId]);

    foreach ($stmt->fetchAll() as $row) {
        $eventDate = (string)$row['event_date'];
        $isPast = $eventDate < date('Y-m-d');
        if (!$isPast && strtolower((string)$row['status']) === 'registered') {
            $upcomingEvents[] = $row;
        } else {
            $completedEvents[] = $row;
        }

        if (strtolower((string)$row['status']) === 'registered') {
            $ticketCards[] = $row;
        }
    }

    $stmt = $pdo->prepare(
        "SELECT e.event_id, e.title, e.event_date, COALESCE(e.venue, '') AS venue, COALESCE(e.image_url, '') AS image_url, COALESCE(e.ticket_price, 0) AS ticket_price
         FROM attendee_saved_events s
         JOIN events e ON e.event_id = s.event_id
         WHERE s.attendee_id = ? AND e.archived_at IS NULL AND e.event_date >= CURDATE()
         ORDER BY e.event_date ASC"
    );
    $stmt->execute([$attendeeId]);
    $wishlistEvents = $stmt->fetchAll();
} catch (Throwable $e) {
    $errorMessage = 'Unable to load your events right now.';
}

function attendeePaymentLabel(string $status): string
{
    $normalized = strtolower(trim($status));
    if ($normalized === 'paid') {
        return 'Paid';
    }
    if ($normalized === 'failed') {
        return 'Failed';
    }

    return 'Not paid';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="../assets/style.css">
    <title>Planora - My Events</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; }
        body { background: #f4f5fb; color: #2d2d2d; padding-bottom: 76px; }
        .header { background: #6C63FF; color: #fff; padding: 14px 24px; display: flex; justify-content: space-between; align-items: center; }
        .brand { font-size: 22px; font-weight: 700; }
        .logout-btn { background: rgba(255,255,255,.22); color: #fff; text-decoration: none; border-radius: 6px; padding: 7px 14px; font-size: 13px; }
        .container { max-width: 1100px; margin: 0 auto; padding: 20px; }
        .notice { border-radius: 6px; padding: 10px 12px; margin-bottom: 14px; font-size: 13px; }
        .notice.ok { background: #ecfff0; color: #1c7a36; border: 1px solid #c9f0d4; }
        .notice.err { background: #ffecec; color: #9d2020; border: 1px solid #f6caca; }
        .stats-grid { display: grid; grid-template-columns: repeat(3, minmax(0,1fr)); gap: 10px; margin-bottom: 14px; }
        .stat { background: #fff; border: 1px solid #ece9ff; border-radius: 10px; padding: 12px; }
        .stat b { font-size: 19px; display: block; }
        .stat span { color: #666; font-size: 12px; }
        .section { margin-bottom: 16px; }
        .section h2 { font-size: 18px; margin-bottom: 10px; }
        .event-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(260px,1fr)); gap: 10px; }
        .card { background: #fff; border: 1px solid #ece9ff; border-radius: 12px; padding: 12px; }
        .thumb {
            width: 100%;
            height: 120px;
            border-radius: 10px;
            background: linear-gradient(135deg, #ece9ff, #d6d0ff);
            background-size: cover;
            background-position: center;
            margin-bottom: 8px;
        }
        .title { font-size: 15px; font-weight: 700; margin-bottom: 4px; }
        .meta { font-size: 12px; color: #666; margin-bottom: 4px; }
        .status-chip { display: inline-block; padding: 3px 9px; border-radius: 999px; font-size: 11px; font-weight: 700; background: #ece9ff; color: #4a40bf; }
        .status-chip.done { background: #ecfff0; color: #1c7a36; }
        .action-row { display: flex; gap: 6px; flex-wrap: wrap; margin-top: 8px; }
        .btn { border: none; border-radius: 8px; padding: 8px 11px; font-size: 12px; font-weight: 700; cursor: pointer; text-decoration: none; display: inline-block; }
        .btn-light { background: #ece9ff; color: #3f379f; }
        .btn-danger { background: #ffecec; color: #9d2020; }
        .ticket-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px,1fr)); gap: 10px; }
        .ticket { background: #1f1d35; color: #fff; border-radius: 12px; padding: 14px; }
        .qr-box { background: repeating-linear-gradient(45deg, #fff, #fff 4px, #e6e6ff 4px, #e6e6ff 8px); border-radius: 8px; color: #1f1d35; font-weight: 700; font-size: 12px; padding: 12px; margin: 8px 0; text-align: center; }
        .empty { color: #666; font-size: 13px; }
        .bottom-nav { position: fixed; bottom: 0; left: 0; right: 0; height: 62px; background: #6C63FF; display: flex; z-index: 999; }
        .nav-item { flex: 1; color: rgba(255,255,255,.76); text-decoration: none; font-size: 11px; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 4px; }
        .nav-item i { font-size: 15px; }
        .nav-item.active, .nav-item:hover { color: #fff; background: rgba(255,255,255,.1); }
        @media (max-width: 900px) { .stats-grid { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
<header class="header">
    <div class="brand">PLANORA</div>
    <a class="logout-btn" href="../logout.php">Logout</a>
</header>
<div class="container">
    <?php if ($flashSuccess !== ''): ?><div class="notice ok"><?php echo htmlspecialchars($flashSuccess); ?></div><?php endif; ?>
    <?php if ($flashError !== ''): ?><div class="notice err"><?php echo htmlspecialchars($flashError); ?></div><?php endif; ?>
    <?php if ($errorMessage !== ''): ?><div class="notice err"><?php echo htmlspecialchars($errorMessage); ?></div><?php endif; ?>

    <section class="stats-grid">
        <div class="stat"><b><?php echo (int)$stats['registered']; ?></b><span>Upcoming Registrations</span></div>
        <div class="stat"><b><?php echo (int)$stats['saved']; ?></b><span>Wishlist Events</span></div>
        <div class="stat"><b><?php echo (int)$stats['completed']; ?></b><span>Completed Events</span></div>
    </section>

    <section class="section">
        <h2>Upcoming</h2>
        <div class="event-grid">
            <?php if (empty($upcomingEvents)): ?>
                <p class="empty">No upcoming registered events.</p>
            <?php else: ?>
                <?php foreach ($upcomingEvents as $event): ?>
                    <?php $eventImage = resolveEventImageUrl((string)($event['image_url'] ?? '')); ?>
                    <div class="card">
                        <div class="thumb"<?php if ($eventImage !== ''): ?> style="background-image:url('<?php echo htmlspecialchars($eventImage); ?>');"<?php endif; ?>></div>
                        <div class="title"><?php echo htmlspecialchars((string)$event['title']); ?></div>
                        <div class="meta"><?php echo htmlspecialchars((string)$event['event_date']); ?> &middot; <?php echo htmlspecialchars((string)(($event['venue'] ?? '') !== '' ? $event['venue'] : 'Venue TBA')); ?></div>
                        <div class="meta">Ticket Tier: <?php echo htmlspecialchars(attendeeTicketTypeLabel((string)($event['attendee_ticket_type'] ?? 'regular'))); ?></div>
                        <div class="meta">Payment: <?php echo htmlspecialchars(attendeePaymentLabel((string)$event['payment_status'])); ?></div>
                        <span class="status-chip">Registered</span>
                        <div class="action-row">
                            <a class="btn btn-light" href="register_event.php?event_id=<?php echo (int)$event['event_id']; ?>">View Ticket</a>
                            <a class="btn btn-light" href="register_event.php?event_id=<?php echo (int)$event['event_id']; ?>">View Details</a>
                            <form method="POST" style="display:inline;" onsubmit="return confirm('Cancel this registration?');">
                                <?php echo csrf_input(); ?>
                                <input type="hidden" name="cancel_event_id" value="<?php echo (int)$event['event_id']; ?>">
                                <button class="btn btn-danger" type="submit">Cancel Registration</button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </section>

    <section class="section">
        <h2>Completed Events</h2>
        <div class="event-grid">
            <?php if (empty($completedEvents)): ?>
                <p class="empty">No completed events yet.</p>
            <?php else: ?>
                <?php foreach ($completedEvents as $event): ?>
                    <?php $eventImage = resolveEventImageUrl((string)($event['image_url'] ?? '')); ?>
                    <div class="card">
                        <div class="thumb"<?php if ($eventImage !== ''): ?> style="background-image:url('<?php echo htmlspecialchars($eventImage); ?>');"<?php endif; ?>></div>
                        <div class="title"><?php echo htmlspecialchars((string)$event['title']); ?></div>
                        <div class="meta"><?php echo htmlspecialchars((string)$event['event_date']); ?></div>
                        <div class="meta">Ticket Tier: <?php echo htmlspecialchars(attendeeTicketTypeLabel((string)($event['attendee_ticket_type'] ?? 'regular'))); ?></div>
                        <span class="status-chip done">Attended</span>
                        <div class="action-row">
                            <a class="btn btn-light" href="profile.php#rate-services">Rate Event</a>
                            <a class="btn btn-light" href="register_event.php?event_id=<?php echo (int)$event['event_id']; ?>">Download Certificate</a>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </section>

    <section class="section">
        <h2>Wishlist</h2>
        <div class="event-grid">
            <?php if (empty($wishlistEvents)): ?>
                <p class="empty">No saved events yet.</p>
            <?php else: ?>
                <?php foreach ($wishlistEvents as $event): ?>
                    <?php $eventImage = resolveEventImageUrl((string)($event['image_url'] ?? '')); ?>
                    <div class="card">
                        <div class="thumb"<?php if ($eventImage !== ''): ?> style="background-image:url('<?php echo htmlspecialchars($eventImage); ?>');"<?php endif; ?>></div>
                        <div class="title"><?php echo htmlspecialchars((string)$event['title']); ?></div>
                        <div class="meta"><?php echo htmlspecialchars((string)$event['event_date']); ?> &middot; <?php echo htmlspecialchars((string)(($event['venue'] ?? '') !== '' ? $event['venue'] : 'Venue TBA')); ?></div>
                        <a class="btn btn-light" href="register_event.php?event_id=<?php echo (int)$event['event_id']; ?>">View Details</a>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </section>

    <section class="section">
        <h2>Digital Tickets</h2>
        <div class="ticket-grid">
            <?php if (empty($ticketCards)): ?>
                <p class="empty">No active tickets yet.</p>
            <?php else: ?>
                <?php foreach ($ticketCards as $event): ?>
                    <?php $ticketCode = strtoupper(substr(sha1((string)$event['event_id'] . '-' . (string)$attendeeId . '-' . (string)$event['event_date']), 0, 14)); ?>
                    <?php $accessType = ((string)($event['event_type'] ?? 'in_person') === 'online') ? 'Online Access' : 'In-person'; ?>
                    <?php $tierType = attendeeTicketTypeLabel((string)($event['attendee_ticket_type'] ?? 'regular')); ?>
                    <?php $ticketCategory = trim((string)($event['category'] ?? '')); ?>
                    <?php $ticketAmount = (float)($event['ticket_amount'] ?? 0); ?>
                    <?php $isPaidTicket = strtolower(trim((string)($event['payment_status'] ?? ''))) === 'paid'; ?>
                    <div class="ticket">
                        <div><strong><?php echo htmlspecialchars((string)$event['title']); ?></strong></div>
                        <div style="font-size:12px; opacity:.85; margin-top:4px;">Access: <?php echo htmlspecialchars($accessType); ?></div>
                        <?php if ($isPaidTicket): ?>
                            <div style="font-size:12px; opacity:.85;">Ticket Tier: <?php echo htmlspecialchars($tierType); ?></div>
                        <?php endif; ?>
                        <div style="font-size:12px; opacity:.85;">Category: <?php echo htmlspecialchars($ticketCategory !== '' ? $ticketCategory : 'General'); ?></div>
                        <?php if ($isPaidTicket): ?>
                            <div style="font-size:12px; opacity:.85;">Amount Paid: <?php echo $ticketAmount > 0 ? 'KES ' . number_format($ticketAmount, 2) : 'Free'; ?></div>
                        <?php endif; ?>
                        <div style="font-size:12px; opacity:.85;">Payment: <?php echo htmlspecialchars(attendeePaymentLabel((string)$event['payment_status'])); ?></div>
                        <div class="qr-box">QR: <?php echo htmlspecialchars($ticketCode); ?></div>
                        <div style="font-size:12px; opacity:.9;">Valid on <?php echo htmlspecialchars((string)$event['event_date']); ?></div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </section>
</div>

<nav class="bottom-nav">
    <a href="dashboard.php" class="nav-item"><i class="fa-solid fa-house"></i><span>Home</span></a>
    <a href="explore.php" class="nav-item"><i class="fa-solid fa-compass"></i><span>Explore</span></a>
    <a href="my_events.php" class="nav-item active"><i class="fa-solid fa-ticket"></i><span>My Events</span></a>
    <a href="schedule.php" class="nav-item"><i class="fa-solid fa-calendar-days"></i><span>Schedule</span></a>
    <a href="notifications.php" class="nav-item"><i class="fa-solid fa-bell"></i><span>Alerts</span></a>
    <a href="profile.php" class="nav-item"><i class="fa-solid fa-user"></i><span>Profile</span></a>
</nav>
</body>
</html>
