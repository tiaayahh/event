<?php
require_once '../includes/auth.php';
checkAuth();
requireRole('planner');

$flashSuccess = $_SESSION['flash_success'] ?? '';
$flashError = $_SESSION['flash_error'] ?? '';
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

$title = '';
$eventDate = '';
$budgetTotal = '';
$ticketPrice = '0.00';
$events = [];

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_event'])) {
    $title = trim($_POST['title'] ?? '');
    $eventDate = trim($_POST['event_date'] ?? '');
    $budgetTotal = trim($_POST['budget_total'] ?? '');
    $ticketPrice = trim($_POST['ticket_price'] ?? '0.00');

    if ($title === '' || $eventDate === '' || $budgetTotal === '' || !is_numeric($budgetTotal) || (float)$budgetTotal < 0) {
        $flashError = 'Title, event date, and a valid non-negative budget are required.';
    } else {
        try {
            $stmt = $pdo->prepare('INSERT INTO events (planner_id, title, event_date, budget_total, budget_committed, ticket_price, ticket_revenue) VALUES (?, ?, ?, ?, 0, ?, 0)');
            $stmt->execute([$_SESSION['user_id'], $title, $eventDate, (float)$budgetTotal, (float)$ticketPrice]);

            audit_log(
                $pdo,
                (int)$_SESSION['user_id'],
                (string)$_SESSION['role'],
                'event.create',
                'event',
                (string)$pdo->lastInsertId(),
                [
                    'title' => $title,
                    'event_date' => $eventDate,
                    'budget_total' => (float)$budgetTotal,
                ]
            );

            $_SESSION['flash_success'] = 'Event created successfully!';
            header('Location: create_event.php');
            exit;
        } catch (Throwable $e) {
            audit_log(
                $pdo,
                (int)$_SESSION['user_id'],
                (string)$_SESSION['role'],
                'event.create_failed',
                'event',
                null,
                ['reason' => 'exception']
            );
            $flashError = 'Could not create event right now. Please try again.';
        }
    }
}

// Fetch existing events
try {
    $stmt = $pdo->prepare('SELECT event_id, title, event_date, budget_total, budget_committed FROM events WHERE planner_id = ? ORDER BY event_date DESC');
    $stmt->execute([$_SESSION['user_id']]);
    $events = $stmt->fetchAll();
} catch (Throwable $e) {
    if ($flashError === '') $flashError = 'Unable to load events.';
}

$totalEvents = count($events);
$totalBudgetAll = array_sum(array_column($events, 'budget_total'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="../assets/style.css">
    <title>Planora &middot; Events</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,400;14..32,500;14..32,600;14..32,700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Inter', sans-serif; }
        body { background: #F5F5F5; color: #2D2D2D; min-height: 100vh; }
        .header { background: #6C63FF; padding: 16px 28px; display: flex; justify-content: space-between; align-items: center; color: #fff; }
        .brand { font-size: 1.5rem; font-weight: 700; letter-spacing: 0.5px; }
        .logout-btn { background: rgba(255,255,255,0.2); color: #fff; padding: 8px 16px; border-radius: 8px; text-decoration: none; font-weight: 500; transition: 0.2s; }
        .logout-btn:hover { background: rgba(255,255,255,0.35); }
        .nav-links { display: flex; gap: 12px; padding: 16px 28px; background: #fff; border-bottom: 1px solid #EAEAEA; flex-wrap: wrap; }
        .nav-links a { text-decoration: none; background: #F0EEFF; color: #6C63FF; padding: 8px 16px; border-radius: 20px; font-size: 0.9rem; font-weight: 500; transition: 0.2s; }
        .nav-links a:hover, .nav-links a.active { background: #6C63FF; color: #fff; }
        .container { max-width: 1100px; margin: 30px auto; padding: 0 20px; }
        .messages { margin-bottom: 20px; }
        .alert { padding: 12px 18px; border-radius: 10px; font-size: 0.9rem; margin-bottom: 12px; display: flex; align-items: center; gap: 10px; }
        .alert-success { background: #E8F5E9; color: #256029; border: 1px solid #B7E1B3; }
        .alert-error { background: #FFECEC; color: #A40000; border: 1px solid #F6BDBD; }
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 18px; margin-bottom: 30px; }
        .stat-card { background: #fff; border-radius: 16px; padding: 20px; box-shadow: 0 2px 8px rgba(0,0,0,0.04); display: flex; align-items: center; gap: 16px; }
        .stat-icon { width: 48px; height: 48px; border-radius: 50%; background: #F0EEFF; display: flex; align-items: center; justify-content: center; color: #6C63FF; font-size: 1.3rem; }
        .stat-value { font-size: 1.6rem; font-weight: 700; }
        .stat-label { font-size: 0.85rem; color: #777; }
        .card { background: #fff; border-radius: 20px; padding: 28px; margin-bottom: 30px; box-shadow: 0 4px 16px rgba(0,0,0,0.04); border: 1px solid #F0F0F0; }
        .card-title { font-size: 1.3rem; font-weight: 700; margin-bottom: 20px; display: flex; align-items: center; gap: 10px; }
        .form-grid { display: grid; grid-template-columns: 1fr 1fr 1fr auto; gap: 16px; align-items: end; }
        .form-group { display: flex; flex-direction: column; gap: 6px; }
        .form-group label { font-size: 0.85rem; font-weight: 600; color: #4B5563; }
        .form-input, .form-select { width: 100%; border: 1.5px solid #E0E0E0; border-radius: 10px; padding: 12px 14px; font-size: 0.95rem; transition: 0.2s; background: #FAFAFA; }
        .form-input:focus { outline: none; border-color: #6C63FF; background: #fff; }
        .btn { background: #6C63FF; color: #fff; border: none; border-radius: 10px; padding: 12px 24px; font-size: 0.95rem; font-weight: 600; cursor: pointer; transition: 0.2s; display: inline-flex; align-items: center; gap: 8px; }
        .btn:hover { background: #5A52E0; }
        .btn-outline { background: transparent; color: #6C63FF; border: 2px solid #6C63FF; }
        .btn-outline:hover { background: #6C63FF; color: #fff; }
        .event-list { display: flex; flex-direction: column; }
        .event-item { border-bottom: 1px solid #F0F0F0; }
        .event-item:last-child { border-bottom: none; }
        .event-header { display: flex; justify-content: space-between; align-items: center; padding: 16px 0; cursor: pointer; }
        .event-header:hover { background: #F9F9FF; margin: 0 -10px; padding: 16px 10px; border-radius: 10px; }
        .event-title { font-weight: 700; }
        .event-date { color: #666; font-size: 0.9rem; }
        .event-budget { font-weight: 600; }
        .event-chevron { transition: 0.3s; color: #6C63FF; }
        .event-details { display: none; padding: 0 0 16px 0; color: #555; font-size: 0.9rem; }
        .event-item.open .event-details { display: block; }
        .event-item.open .event-chevron { transform: rotate(180deg); }
        .detail-row { display: flex; gap: 40px; margin-bottom: 8px; flex-wrap: wrap; }
        .detail-label { font-weight: 600; color: #2D2D2D; }
        .empty-state { text-align: center; padding: 30px; color: #888; }
        @media (max-width: 800px) {
            .form-grid { grid-template-columns: 1fr; }
            .event-header { flex-direction: column; align-items: flex-start; gap: 6px; }
        }
    </style>
</head>
<body>
<header class="header">
    <div class="brand">PLANORA</div>
    <a href="../logout.php" class="logout-btn"><i class="fa-solid fa-arrow-right-from-bracket"></i> Logout</a>
</header>

<div class="nav-links">
    <a href="dashboard.php"><i class="fa-solid fa-house"></i> Dashboard</a>
    <a href="browse_vendors.php"><i class="fa-solid fa-shop"></i> Browse Vendors</a>
    <a href="create_event.php" class="active"><i class="fa-solid fa-calendar-plus"></i> Events</a>
</div>

<div class="container">
    <div class="messages">
        <?php if ($flashSuccess): ?>
            <div class="alert alert-success"><i class="fa-solid fa-circle-check"></i> <?php echo htmlspecialchars($flashSuccess); ?></div>
        <?php endif; ?>
        <?php if ($flashError): ?>
            <div class="alert alert-error"><i class="fa-solid fa-circle-exclamation"></i> <?php echo htmlspecialchars($flashError); ?></div>
        <?php endif; ?>
    </div>

    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-icon"><i class="fa-solid fa-calendar-days"></i></div>
            <div>
                <div class="stat-value"><?php echo $totalEvents; ?></div>
                <div class="stat-label">Total Events</div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon"><i class="fa-solid fa-coins"></i></div>
            <div>
                <div class="stat-value">KES <?php echo number_format($totalBudgetAll); ?></div>
                <div class="stat-label">Total Budget</div>
            </div>
        </div>
    </div>

    <!-- Create Event Form -->
    <section class="card">
        <h2 class="card-title"><i class="fa-solid fa-plus-circle" style="color:#6C63FF;"></i> Create New Event</h2>
        <form method="POST" class="form-grid">
            <?php echo csrf_input(); ?>
            <input type="hidden" name="create_event" value="1">
            <div class="form-group">
                <label for="title">Event Title</label>
                <input type="text" id="title" name="title" class="form-input" placeholder="e.g., Johnson Wedding" value="<?php echo htmlspecialchars($title); ?>" required>
            </div>
            <div class="form-group">
                <label for="event_date">Date</label>
                <input type="date" id="event_date" name="event_date" class="form-input" value="<?php echo htmlspecialchars($eventDate); ?>" required>
            </div>
            <div class="form-group">
                <label for="budget_total">Budget (KES)</label>
                <input type="number" id="budget_total" name="budget_total" step="0.01" min="0" class="form-input" placeholder="15000" value="<?php echo htmlspecialchars($budgetTotal); ?>" required>
            </div>
            <div class="form-group">
                <label for="ticket_price">Ticket Price (KES) <small>(per attendee)</small></label>
                <input type="number" step="0.01" min="0" id="ticket_price" name="ticket_price" class="form-input" placeholder="0.00" value="<?php echo htmlspecialchars($ticketPrice ?? '0.00'); ?>">
            </div>
            <button type="submit" class="btn"><i class="fa-solid fa-paper-plane"></i> Create Event</button>
        </form>
    </section>

    <!-- Existing Events List -->
    <section class="card">
        <h2 class="card-title"><i class="fa-regular fa-calendar-check" style="color:#6C63FF;"></i> My Events</h2>
        <?php if (empty($events)): ?>
            <div class="empty-state">
                <i class="fa-regular fa-folder-open" style="font-size: 2rem; margin-bottom: 10px; display: block;"></i>
                No events yet. Create your first event above.
            </div>
        <?php else: ?>
            <div class="event-list">
                <?php foreach ($events as $event): ?>
                    <div class="event-item">
                        <div class="event-header" onclick="this.parentElement.classList.toggle('open')">
                            <div>
                                <div class="event-title"><?php echo htmlspecialchars($event['title']); ?></div>
                                <div class="event-date"><?php echo htmlspecialchars($event['event_date']); ?></div>
                            </div>
                            <div style="display:flex; align-items:center; gap:16px;">
                                <span class="event-budget">KES <?php echo number_format($event['budget_total'], 2); ?></span>
                                <i class="fa-solid fa-chevron-down event-chevron"></i>
                            </div>
                        </div>
                        <div class="event-details">
                            <div class="detail-row">
                                <span><span class="detail-label">Total Budget:</span> KES <?php echo number_format($event['budget_total'], 2); ?></span>
                                <span><span class="detail-label">Committed:</span> KES <?php echo number_format($event['budget_committed'], 2); ?></span>
                                <span><span class="detail-label">Available:</span> KES <?php echo number_format($event['budget_total'] - $event['budget_committed'], 2); ?></span>
                            </div>
                            <a href="browse_vendors.php?event_id=<?php echo $event['event_id']; ?>" class="btn btn-outline" style="margin-top:8px;">
                                <i class="fa-solid fa-magnifying-glass"></i> Browse Vendors for This Event
                            </a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
</div>

<script>
    // Nothing extra needed - the onclick toggles the 'open' class on the parent .event-item
</script>
</body>
</html>

