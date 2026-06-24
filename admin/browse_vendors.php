<?php
require_once '../includes/auth.php';
checkAuth();
requireRole('planner');

$vendors = [];
$events = [];
$flashSuccess = $_SESSION['flash_success'] ?? '';
$flashError = $_SESSION['flash_error'] ?? '';
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

function ensureServiceRatingsTable(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS service_ratings (
            rating_id INT AUTO_INCREMENT PRIMARY KEY,
            attendee_id INT NOT NULL,
            service_id INT NOT NULL,
            vendor_id INT NOT NULL,
            rating TINYINT NOT NULL,
            feedback VARCHAR(500) DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_attendee_service_rating (attendee_id, service_id),
            INDEX idx_service_ratings_service (service_id),
            INDEX idx_service_ratings_vendor (vendor_id),
            INDEX idx_service_ratings_attendee (attendee_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}

try {
    ensureServiceRatingsTable($pdo);

    $stmt = $pdo->prepare('SELECT event_id, title, event_date FROM events WHERE planner_id = ? ORDER BY event_date ASC');
    $stmt->execute([$_SESSION['user_id']]);
    $events = $stmt->fetchAll();

    $stmt = $pdo->query(
        "SELECT v.vendor_id, u.full_name, v.business_name, v.service_type,
                COUNT(DISTINCT s.service_id) AS total_services,
                COUNT(DISTINCT CASE WHEN s.availability = 1 THEN s.service_id END) AS available_services,
                COALESCE(AVG(sr.rating), 0) AS avg_rating,
                COUNT(sr.rating_id) AS ratings_count
         FROM vendors v
         JOIN users u ON v.user_id = u.user_id
         LEFT JOIN services s ON v.vendor_id = s.vendor_id
         LEFT JOIN service_ratings sr ON sr.service_id = s.service_id
         GROUP BY v.vendor_id, u.full_name, v.business_name, v.service_type
         ORDER BY v.business_name ASC"
    );
    $vendors = $stmt->fetchAll();
} catch (Throwable $e) {
    if ($flashError === '') {
        $flashError = 'Could not load vendors right now.';
    }
}

$selectedEventId = filter_input(INPUT_GET, 'event_id', FILTER_VALIDATE_INT);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="../assets/style.css">
    <title>Planora &middot; Browse Vendors</title>
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
        .card { background: #fff; border-radius: 20px; padding: 28px; margin-bottom: 30px; box-shadow: 0 4px 16px rgba(0,0,0,0.04); border: 1px solid #F0F0F0; }
        .card-title { font-size: 1.3rem; font-weight: 700; margin-bottom: 20px; display: flex; align-items: center; gap: 10px; }
        .event-selector { display: flex; align-items: flex-end; gap: 16px; flex-wrap: wrap; }
        .form-group { display: flex; flex-direction: column; gap: 6px; }
        .form-group label { font-size: 0.85rem; font-weight: 600; color: #4B5563; }
        .form-select { border: 1.5px solid #E0E0E0; border-radius: 10px; padding: 12px 14px; font-size: 0.95rem; background: #FAFAFA; min-width: 280px; transition: 0.2s; }
        .form-select:focus { outline: none; border-color: #6C63FF; background: #fff; }
        .btn { background: #6C63FF; color: #fff; border: none; border-radius: 10px; padding: 12px 24px; font-size: 0.95rem; font-weight: 600; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; gap: 8px; transition: 0.2s; }
        .btn:hover { background: #5A52E0; }
        .btn-outline { background: transparent; color: #6C63FF; border: 2px solid #6C63FF; }
        .btn-outline:hover { background: #6C63FF; color: #fff; }
        .vendor-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 20px; margin-top: 10px; }
        .vendor-card { background: #fff; border: 1px solid #F0F0F0; border-radius: 16px; padding: 20px; box-shadow: 0 2px 8px rgba(0,0,0,0.04); transition: 0.2s; }
        .vendor-card:hover { box-shadow: 0 6px 18px rgba(108,99,255,0.08); border-color: #D4CEFF; }
        .vendor-avatar { width: 48px; height: 48px; border-radius: 12px; background: #F0EEFF; color: #6C63FF; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 1.2rem; margin-bottom: 12px; }
        .vendor-name { font-weight: 700; font-size: 1.05rem; margin-bottom: 6px; }
        .vendor-meta { font-size: 0.85rem; color: #666; margin-bottom: 4px; display: flex; align-items: center; gap: 6px; }
        .vendor-services { margin: 12px 0; padding: 10px; background: #F9F9FF; border-radius: 10px; font-size: 0.85rem; color: #4B5563; }
        .vendor-services strong { color: #2D2D2D; }
        .vendor-actions { margin-top: 12px; }
        .disabled-text { color: #999; font-size: 0.85rem; font-style: italic; }
        .empty-state { text-align: center; padding: 40px 20px; color: #888; }
        .empty-state i { font-size: 2.5rem; margin-bottom: 15px; display: block; color: #CCC; }
        @media (max-width: 700px) {
            .event-selector { flex-direction: column; align-items: stretch; }
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
    <a href="browse_vendors.php" class="active"><i class="fa-solid fa-shop"></i> Browse Vendors</a>
    <a href="create_event.php"><i class="fa-solid fa-calendar-plus"></i> Events</a>
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

    <!-- Event Selector -->
    <section class="card">
        <h2 class="card-title"><i class="fa-solid fa-link" style="color:#6C63FF;"></i> Book a Vendor for an Event</h2>
        <?php if (empty($events)): ?>
            <div class="empty-state">
                <i class="fa-regular fa-calendar-xmark"></i>
                <p>You haven't created any events yet.</p>
                <a href="create_event.php" class="btn" style="margin-top:12px;"><i class="fa-solid fa-plus"></i> Create an Event</a>
            </div>
        <?php else: ?>
            <form method="GET" class="event-selector">
                <div class="form-group">
                    <label for="event_id">Select Event</label>
                    <select class="form-select" name="event_id" id="event_id" onchange="this.form.submit()">
                        <option value="">-- Choose an event --</option>
                        <?php foreach ($events as $event): ?>
                            <option value="<?php echo (int)$event['event_id']; ?>" <?php echo ($selectedEventId === (int)$event['event_id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($event['title']); ?> &middot; <?php echo htmlspecialchars((string)$event['event_date']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" class="btn"><i class="fa-solid fa-check"></i> Confirm Event</button>
            </form>
        <?php endif; ?>
    </section>

    <!-- Vendor Listing -->
    <section class="card">
        <h2 class="card-title"><i class="fa-solid fa-store" style="color:#6C63FF;"></i> Available Vendors</h2>
        <?php if (empty($vendors)): ?>
            <div class="empty-state">
                <i class="fa-regular fa-face-frown"></i>
                <p>No vendors registered yet.</p>
            </div>
        <?php else: ?>
            <div class="vendor-grid">
                <?php foreach ($vendors as $vendor): ?>
                    <div class="vendor-card">
                        <div class="vendor-avatar">
                            <?php echo strtoupper(substr($vendor['business_name'], 0, 1)); ?>
                        </div>
                        <div class="vendor-name"><?php echo htmlspecialchars($vendor['business_name']); ?></div>
                        <div class="vendor-meta"><i class="fa-regular fa-user"></i> <?php echo htmlspecialchars($vendor['full_name']); ?></div>
                        <div class="vendor-meta"><i class="fa-solid fa-tag"></i> <?php echo htmlspecialchars($vendor['service_type'] ?? 'General'); ?></div>
                        <div class="vendor-meta"><i class="fa-regular fa-star"></i> <?php echo number_format((float)$vendor['avg_rating'], 1); ?>/5 (<?php echo (int)$vendor['ratings_count']; ?>)</div>
                        <div class="vendor-services">
                            <i class="fa-solid fa-bell-concierge"></i>
                            <strong><?php echo (int)$vendor['available_services']; ?></strong> of
                            <strong><?php echo (int)$vendor['total_services']; ?></strong> services available
                        </div>
                        <div class="vendor-actions">
                            <?php if ($selectedEventId): ?>
                                <a class="btn btn-outline" href="book_vendor.php?vendor_id=<?php echo (int)$vendor['vendor_id']; ?>&event_id=<?php echo (int)$selectedEventId; ?>">
                                    <i class="fa-solid fa-eye"></i> View Services
                                </a>
                            <?php else: ?>
                                <span class="disabled-text"><i class="fa-solid fa-info-circle"></i> Select an event above to book.</span>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
</div>
</body>
</html>

