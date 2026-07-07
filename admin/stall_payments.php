<?php
require_once '../includes/auth.php';
checkAuth();
requireRole('planner');

$flashSuccess = $_SESSION['flash_success'] ?? '';
$flashError = $_SESSION['flash_error'] ?? '';
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

$statusFilter = strtolower(trim((string)($_GET['status'] ?? 'all')));
if (!in_array($statusFilter, ['all', 'paid', 'pending', 'failed', 'cancelled'], true)) {
    $statusFilter = 'all';
}

$rows = [];
$stats = [
    'total' => 0,
    'paid' => 0,
    'pending' => 0,
    'failed' => 0,
    'cancelled' => 0,
    'paid_total' => 0.0,
];

function ensureStallRentalsTable(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS stall_rentals (
            rental_id BIGINT AUTO_INCREMENT PRIMARY KEY,
            event_id INT NOT NULL,
            vendor_user_id INT NOT NULL,
            created_by_planner INT DEFAULT NULL,
            stall_label VARCHAR(80) DEFAULT NULL,
            amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            checkout_request_id VARCHAR(120) DEFAULT NULL,
            merchant_request_id VARCHAR(120) DEFAULT NULL,
            phone_number VARCHAR(20) DEFAULT NULL,
            mpesa_code VARCHAR(64) DEFAULT NULL,
            status ENUM('requested', 'paid', 'failed', 'cancelled') NOT NULL DEFAULT 'requested',
            payment_status ENUM('pending', 'paid', 'failed', 'cancelled') NOT NULL DEFAULT 'pending',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_stall_rentals_event_vendor (event_id, vendor_user_id),
            UNIQUE KEY uq_stall_rentals_checkout (checkout_request_id),
            INDEX idx_stall_rentals_event_status (event_id, payment_status),
            INDEX idx_stall_rentals_vendor (vendor_user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $stmt = $pdo->query("SHOW COLUMNS FROM stall_rentals LIKE 'payment_status'");
    if (!$stmt->fetch()) {
        $pdo->exec("ALTER TABLE stall_rentals ADD COLUMN payment_status ENUM('pending', 'paid', 'failed', 'cancelled') NOT NULL DEFAULT 'pending' AFTER mpesa_code");
    }

    $stmt = $pdo->query("SHOW COLUMNS FROM stall_rentals LIKE 'status'");
    if (!$stmt->fetch()) {
        $pdo->exec("ALTER TABLE stall_rentals ADD COLUMN status ENUM('requested', 'paid', 'failed', 'cancelled') NOT NULL DEFAULT 'requested' AFTER mpesa_code");
    }

    $pdo->exec(
        "UPDATE stall_rentals
         SET payment_status = CASE LOWER(COALESCE(status, 'requested'))
             WHEN 'paid' THEN 'paid'
             WHEN 'failed' THEN 'failed'
             WHEN 'cancelled' THEN 'cancelled'
             ELSE 'pending'
         END"
    );

    $ready = true;
}

try {
    ensureStallRentalsTable($pdo);

    $sql =
        "SELECT
            sr.rental_id,
            sr.event_id,
            sr.vendor_user_id,
            COALESCE(v.vendor_id, 0) AS vendor_id,
            e.title AS event_title,
            e.event_date,
            COALESCE(v.business_name, u.full_name, CONCAT('Vendor #', sr.vendor_user_id)) AS vendor_name,
            COALESCE(sr.stall_label, '') AS stall_label,
            sr.amount,
            sr.mpesa_code,
            sr.checkout_request_id,
            sr.updated_at,
            COALESCE(sr.payment_status,
                CASE LOWER(COALESCE(sr.status, 'requested'))
                    WHEN 'paid' THEN 'paid'
                    WHEN 'failed' THEN 'failed'
                    WHEN 'cancelled' THEN 'cancelled'
                    ELSE 'pending'
                END
            ) AS payment_status
         FROM stall_rentals sr
         JOIN events e ON e.event_id = sr.event_id
         LEFT JOIN users u ON u.user_id = sr.vendor_user_id
         LEFT JOIN vendors v ON v.user_id = sr.vendor_user_id
         WHERE e.planner_id = ? AND e.archived_at IS NULL";

    $params = [$_SESSION['user_id']];
    if ($statusFilter !== 'all') {
        $sql .= " AND COALESCE(sr.payment_status,
                    CASE LOWER(COALESCE(sr.status, 'requested'))
                        WHEN 'paid' THEN 'paid'
                        WHEN 'failed' THEN 'failed'
                        WHEN 'cancelled' THEN 'cancelled'
                        ELSE 'pending'
                    END
                 ) = ?";
        $params[] = $statusFilter;
    }

    $sql .= " ORDER BY sr.updated_at DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    $stats['total'] = count($rows);
    foreach ($rows as $row) {
        $status = strtolower((string)($row['payment_status'] ?? 'pending'));
        if ($status === 'paid') {
            $stats['paid']++;
            $stats['paid_total'] += (float)($row['amount'] ?? 0);
        } elseif ($status === 'failed') {
            $stats['failed']++;
        } elseif ($status === 'cancelled') {
            $stats['cancelled']++;
        } else {
            $stats['pending']++;
        }
    }
} catch (Throwable $e) {
    if ($flashError === '') {
        $flashError = 'Unable to load stall payments right now.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="../assets/style.css">
    <title>Planora - Stall Payments</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; }
        body { background: #f4f5fb; color: #2D2D2D; min-height: 100vh; }
        .header { background: #6C63FF; color: #fff; padding: 14px 24px; display: flex; justify-content: space-between; align-items: center; }
        .brand { font-size: 22px; font-weight: 700; }
        .logout-btn { background: rgba(255,255,255,0.22); color: #fff; text-decoration: none; border-radius: 6px; padding: 7px 14px; font-size: 13px; }
        .container { max-width: 1150px; margin: 0 auto; padding: 20px; }
        .links, .filters { display: flex; gap: 10px; margin-bottom: 14px; flex-wrap: wrap; }
        .link-btn { text-decoration: none; background: #ece9ff; color: #3b3496; border-radius: 10px; border: 1px solid #d8d4ff; padding: 8px 12px; font-size: 13px; }
        .link-btn.active { background: #6C63FF; color: #fff; border-color: #6C63FF; }
        .message { border-radius: 6px; padding: 10px 12px; margin-bottom: 14px; font-size: 13px; }
        .message-success { background: #ecfff0; color: #1c7a36; border: 1px solid #c9f0d4; }
        .message-error { background: #ffecec; color: #9d2020; border: 1px solid #f6caca; }
        .stats { display: grid; grid-template-columns: repeat(6, 1fr); gap: 12px; margin-bottom: 18px; }
        .stat { background: #fff; border-radius: 12px; border: 1px solid #ece9ff; padding: 14px; }
        .stat-value { font-size: 23px; font-weight: 700; color: #6C63FF; }
        .stat-label { font-size: 12px; color: #777; margin-top: 4px; }
        .card { background: #fff; border-radius: 12px; border: 1px solid #ece9ff; padding: 18px; box-shadow: 0 1px 3px rgba(0,0,0,.05); }
        .title { font-size: 18px; font-weight: 700; margin-bottom: 12px; }
        .table-wrap { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 11px 10px; text-align: left; border-bottom: 1px solid #efefef; font-size: 13px; }
        th { background: #fafafa; color: #555; font-weight: 700; }
        .badge { display: inline-block; padding: 3px 9px; border-radius: 999px; font-size: 11px; font-weight: 700; }
        .badge-paid { background: #e8f9ef; color: #1c7a36; }
        .badge-pending { background: #fff4df; color: #a36500; }
        .badge-failed { background: #ffe8e8; color: #a22b2b; }
        .badge-cancelled { background: #f1f1f1; color: #606060; }
        .btn { text-decoration: none; background: #6C63FF; color: #fff; border-radius: 10px; padding: 7px 10px; font-size: 12px; display: inline-block; }
        .empty { color: #777; font-size: 14px; padding: 10px 0; }
        @media (max-width: 980px) { .stats { grid-template-columns: 1fr 1fr 1fr; } }
        @media (max-width: 560px) { .stats { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
<header class="header">
    <div class="brand">PLANORA</div>
    <a class="logout-btn" href="../logout.php">Logout</a>
</header>

<div class="container">
    <div class="links">
        <a class="link-btn" href="dashboard.php"><i class="fa-solid fa-house"></i> Dashboard</a>
        <a class="link-btn" href="create_event.php"><i class="fa-solid fa-calendar-days"></i> Events</a>
        <a class="link-btn" href="browse_vendors.php"><i class="fa-solid fa-shop"></i> Vendors</a>
        <a class="link-btn" href="mpesa_payments.php"><i class="fa-solid fa-book-bookmark"></i> Booking Payments</a>
    </div>

    <?php if ($flashSuccess !== ''): ?><div class="message message-success"><?php echo htmlspecialchars($flashSuccess); ?></div><?php endif; ?>
    <?php if ($flashError !== ''): ?><div class="message message-error"><?php echo htmlspecialchars($flashError); ?></div><?php endif; ?>

    <div class="filters">
        <a class="link-btn <?php echo $statusFilter === 'all' ? 'active' : ''; ?>" href="stall_payments.php?status=all">All</a>
        <a class="link-btn <?php echo $statusFilter === 'paid' ? 'active' : ''; ?>" href="stall_payments.php?status=paid">Paid</a>
        <a class="link-btn <?php echo $statusFilter === 'pending' ? 'active' : ''; ?>" href="stall_payments.php?status=pending">Pending</a>
        <a class="link-btn <?php echo $statusFilter === 'failed' ? 'active' : ''; ?>" href="stall_payments.php?status=failed">Failed</a>
        <a class="link-btn <?php echo $statusFilter === 'cancelled' ? 'active' : ''; ?>" href="stall_payments.php?status=cancelled">Cancelled</a>
    </div>

    <section class="stats">
        <div class="stat"><div class="stat-value"><?php echo (int)$stats['total']; ?></div><div class="stat-label">Stall Rentals</div></div>
        <div class="stat"><div class="stat-value"><?php echo (int)$stats['paid']; ?></div><div class="stat-label">Paid</div></div>
        <div class="stat"><div class="stat-value"><?php echo (int)$stats['pending']; ?></div><div class="stat-label">Pending</div></div>
        <div class="stat"><div class="stat-value"><?php echo (int)$stats['failed']; ?></div><div class="stat-label">Failed</div></div>
        <div class="stat"><div class="stat-value"><?php echo (int)$stats['cancelled']; ?></div><div class="stat-label">Cancelled</div></div>
        <div class="stat"><div class="stat-value">KES <?php echo number_format((float)$stats['paid_total'], 2); ?></div><div class="stat-label">Paid Revenue</div></div>
    </section>

    <section class="card">
        <h2 class="title">Stall Payment Reconciliation</h2>

        <?php if (empty($rows)): ?>
            <div class="empty">No stall rental records found for this filter.</div>
        <?php else: ?>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Event</th>
                            <th>Vendor</th>
                            <th>Stall</th>
                            <th>Amount</th>
                            <th>Status</th>
                            <th>M-Pesa Code</th>
                            <th>Updated</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $row): ?>
                            <?php
                                $status = strtolower((string)($row['payment_status'] ?? 'pending'));
                                $badgeClass = 'badge-pending';
                                if ($status === 'paid') {
                                    $badgeClass = 'badge-paid';
                                } elseif ($status === 'failed') {
                                    $badgeClass = 'badge-failed';
                                } elseif ($status === 'cancelled') {
                                    $badgeClass = 'badge-cancelled';
                                }
                            ?>
                            <tr>
                                <td><?php echo htmlspecialchars((string)$row['event_title']); ?><br><small><?php echo htmlspecialchars((string)$row['event_date']); ?></small></td>
                                <td><?php echo htmlspecialchars((string)$row['vendor_name']); ?></td>
                                <td><?php echo htmlspecialchars((string)(($row['stall_label'] ?? '') !== '' ? $row['stall_label'] : '-')); ?></td>
                                <td>KES <?php echo number_format((float)($row['amount'] ?? 0), 2); ?></td>
                                <td><span class="badge <?php echo $badgeClass; ?>"><?php echo htmlspecialchars(ucfirst($status)); ?></span></td>
                                <td><?php echo htmlspecialchars((string)(($row['mpesa_code'] ?? '') !== '' ? $row['mpesa_code'] : '-')); ?></td>
                                <td><?php echo htmlspecialchars((string)($row['updated_at'] ?? '-')); ?></td>
                                <td>
                                    <?php if ((int)($row['vendor_id'] ?? 0) > 0): ?>
                                        <a class="btn" href="stall_rentals.php?vendor_id=<?php echo (int)$row['vendor_id']; ?>&event_id=<?php echo (int)$row['event_id']; ?>">Open Stall</a>
                                    <?php else: ?>
                                        <span style="font-size:12px; color:#777;">N/A</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
</div>
</body>
</html>
