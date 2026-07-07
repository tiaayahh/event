<?php
require_once '../includes/auth.php';
require_once '../includes/csrf.php';
require_once '../includes/audit.php';
require_once '../includes/daraja.php';

checkAuth();
requireRole('vendor');

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
            payment_status ENUM('pending', 'paid', 'failed', 'cancelled') NOT NULL DEFAULT 'pending',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_stall_rentals_event_vendor (event_id, vendor_user_id),
            UNIQUE KEY uq_stall_rentals_checkout (checkout_request_id),
            INDEX idx_stall_rentals_event_status (event_id, payment_status),
            INDEX idx_stall_rentals_vendor (vendor_user_id),
            CONSTRAINT fk_stall_rentals_event FOREIGN KEY (event_id) REFERENCES events(event_id) ON DELETE CASCADE,
            CONSTRAINT fk_stall_rentals_vendor_user FOREIGN KEY (vendor_user_id) REFERENCES users(user_id) ON DELETE CASCADE,
            CONSTRAINT fk_stall_rentals_planner_user FOREIGN KEY (created_by_planner) REFERENCES users(user_id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $stmt = $pdo->query("SHOW COLUMNS FROM stall_rentals LIKE 'checkout_request_id'");
    if (!$stmt->fetch()) {
        $pdo->exec("ALTER TABLE stall_rentals ADD COLUMN checkout_request_id VARCHAR(120) DEFAULT NULL AFTER amount");
    }

    $stmt = $pdo->query("SHOW COLUMNS FROM stall_rentals LIKE 'merchant_request_id'");
    if (!$stmt->fetch()) {
        $pdo->exec("ALTER TABLE stall_rentals ADD COLUMN merchant_request_id VARCHAR(120) DEFAULT NULL AFTER checkout_request_id");
    }

    $stmt = $pdo->query("SHOW COLUMNS FROM stall_rentals LIKE 'phone_number'");
    if (!$stmt->fetch()) {
        $pdo->exec("ALTER TABLE stall_rentals ADD COLUMN phone_number VARCHAR(20) DEFAULT NULL AFTER merchant_request_id");
    }

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

    try {
        $pdo->exec("CREATE UNIQUE INDEX uq_stall_rentals_checkout ON stall_rentals (checkout_request_id)");
    } catch (Throwable $e) {
        // Ignore if index already exists.
    }

    $ready = true;
}

$vendorUserId = (int)$_SESSION['user_id'];
$vendorId = 0;
$vendorType = 'service_provider';
$error = '';
$success = '';
$events = [];
$darajaConfigured = daraja_is_configured();
$darajaStkConfigured = daraja_is_stk_configured();
$darajaMissingStkFields = daraja_missing_stk_fields();

try {
    ensureStallRentalsTable($pdo);

    $stmt = $pdo->prepare("SELECT vendor_id, COALESCE(vendor_type, 'service_provider') AS vendor_type FROM vendors WHERE user_id = ? LIMIT 1");
    $stmt->execute([$vendorUserId]);
    $vendor = $stmt->fetch();

    if (!$vendor) {
        throw new RuntimeException('Vendor profile not found.');
    }

    $vendorId = (int)$vendor['vendor_id'];
    $vendorType = (string)$vendor['vendor_type'];

    if ($vendorType !== 'market_operator') {
        throw new RuntimeException('Stall registration is only available to market operators.');
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
            throw new RuntimeException('Invalid CSRF token.');
        }

        $eventId = (int)($_POST['event_id'] ?? 0);
        $action = strtolower(trim((string)($_POST['action'] ?? '')));

        if ($eventId <= 0) {
            throw new RuntimeException('Invalid event selected.');
        }

        $stmt = $pdo->prepare(
            "SELECT event_id, planner_id, title, event_date, COALESCE(stall_price, 0) AS stall_price
             FROM events
             WHERE event_id = ?
               AND archived_at IS NULL
               AND event_date >= CURDATE()
               AND LOWER(COALESCE(category, '')) LIKE '%market%'
             LIMIT 1"
        );
        $stmt->execute([$eventId]);
        $event = $stmt->fetch();

        if (!$event) {
            throw new RuntimeException('Market event not found or no longer available.');
        }

        $rentalAmount = (float)($event['stall_price'] ?? 0);
        if ($rentalAmount <= 0) {
            throw new RuntimeException('This market event does not have a valid stall price yet.');
        }

        if ($action === 'stk_push') {
            if (!$darajaStkConfigured) {
                throw new RuntimeException('STK push is unavailable. Missing: ' . implode(', ', $darajaMissingStkFields) . '.');
            }

            $phoneNumber = trim((string)($_POST['phone_number'] ?? ''));
            $stallLabel = trim((string)($_POST['stall_label'] ?? ''));
            if ($stallLabel !== '' && strlen($stallLabel) > 80) {
                throw new RuntimeException('Stall label must be 80 characters or fewer.');
            }

            $pushResult = daraja_stk_push(
                $phoneNumber,
                $rentalAmount,
                'STALL-' . $eventId . '-' . $vendorUserId,
                'Planora market stall rental payment'
            );

            if (empty($pushResult['success'])) {
                throw new RuntimeException((string)($pushResult['message'] ?? 'Unable to initiate M-Pesa STK push right now.'));
            }

            $normalizedPhone = daraja_normalize_phone($phoneNumber);
            $stmt = $pdo->prepare(
                "INSERT INTO stall_rentals
                    (event_id, vendor_user_id, created_by_planner, stall_label, amount, checkout_request_id, merchant_request_id, phone_number, payment_status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending')
                 ON DUPLICATE KEY UPDATE
                    created_by_planner = VALUES(created_by_planner),
                    stall_label = VALUES(stall_label),
                    amount = VALUES(amount),
                    checkout_request_id = VALUES(checkout_request_id),
                    merchant_request_id = VALUES(merchant_request_id),
                    phone_number = VALUES(phone_number),
                    mpesa_code = NULL,
                    payment_status = 'pending',
                    updated_at = CURRENT_TIMESTAMP"
            );
            $stmt->execute([
                (int)$eventId,
                $vendorUserId,
                (int)$event['planner_id'],
                $stallLabel !== '' ? $stallLabel : null,
                $rentalAmount,
                (string)($pushResult['checkout_request_id'] ?? ''),
                (string)($pushResult['merchant_request_id'] ?? ''),
                $normalizedPhone !== '' ? $normalizedPhone : $phoneNumber,
            ]);

            audit_log(
                $pdo,
                $vendorUserId,
                'vendor',
                'vendor.stall_stk_push_requested',
                'event',
                (string)$eventId,
                [
                    'amount' => $rentalAmount,
                    'checkout_request_id' => (string)($pushResult['checkout_request_id'] ?? ''),
                ]
            );

            $success = 'M-Pesa STK push sent. Complete the prompt on your phone. Status will update automatically after callback.';
        } elseif ($action === 'confirm_paid') {
            $mpesaCode = strtoupper(trim((string)($_POST['mpesa_code'] ?? '')));
            if (!preg_match('/^[A-Z0-9]{6,20}$/', $mpesaCode)) {
                throw new RuntimeException('Enter a valid M-Pesa code (6-20 letters/numbers).');
            }

            $stmt = $pdo->prepare(
                "INSERT INTO stall_rentals
                    (event_id, vendor_user_id, created_by_planner, amount, mpesa_code, payment_status)
                 VALUES (?, ?, ?, ?, ?, 'paid')
                 ON DUPLICATE KEY UPDATE
                    created_by_planner = VALUES(created_by_planner),
                    amount = VALUES(amount),
                    mpesa_code = VALUES(mpesa_code),
                    payment_status = 'paid',
                    updated_at = CURRENT_TIMESTAMP"
            );
            $stmt->execute([$eventId, $vendorUserId, (int)$event['planner_id'], $rentalAmount, $mpesaCode]);

            audit_log(
                $pdo,
                $vendorUserId,
                'vendor',
                'vendor.stall_payment_confirmed',
                'event',
                (string)$eventId,
                [
                    'amount' => $rentalAmount,
                    'mpesa_code' => $mpesaCode,
                ]
            );

            $success = 'Stall payment marked as paid.';
        }
    }

    $stmt = $pdo->prepare(
        "SELECT
            e.event_id,
            e.title,
            e.event_date,
            COALESCE(e.venue, '') AS venue,
            COALESCE(e.city, '') AS city,
            COALESCE(e.stall_price, 0) AS stall_price,
            sr.stall_label,
            sr.phone_number,
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
         FROM events e
         LEFT JOIN stall_rentals sr
            ON sr.event_id = e.event_id
           AND sr.vendor_user_id = ?
         WHERE e.archived_at IS NULL
           AND e.event_date >= CURDATE()
           AND LOWER(COALESCE(e.category, '')) LIKE '%market%'
         ORDER BY e.event_date ASC"
    );
    $stmt->execute([$vendorUserId]);
    $events = $stmt->fetchAll();
} catch (Throwable $e) {
    $error = $e->getMessage();
    error_log('vendor/stall_registration.php error: ' . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Vendor Market Stalls - Planora</title>
    <link rel="stylesheet" href="../assets/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; }
        body { background-color: #F5F5F5; color: #2D2D2D; padding-bottom: 90px; }
        .header { background-color: #6C63FF; color: white; padding: 14px 24px; display: flex; justify-content: space-between; align-items: center; }
        .brand-logo { font-size: 22px; font-weight: 700; }
        .logout-btn { background-color: rgba(255, 255, 255, 0.25); color: white; border: 1px solid rgba(255, 255, 255, 0.3); padding: 6px 14px; border-radius: 4px; text-decoration: none; font-size: 13px; }
        .container { max-width: 1100px; margin: 0 auto; padding: 20px; }
        .dashboard-card { background-color: #fff; border-radius: 8px; padding: 20px; margin-bottom: 20px; box-shadow: 0 1px 3px rgba(0,0,0,.05); }
        .card-title { font-size: 20px; font-weight: 700; margin-bottom: 8px; }
        .card-subtitle { color: #666; font-size: 13px; margin-bottom: 14px; }
        .message { border-radius: 6px; padding: 10px 12px; margin-bottom: 14px; font-size: 13px; }
        .message.error { background: #ffecec; color: #9d2020; border: 1px solid #f6caca; }
        .message.success { background: #ecfff0; color: #1c7a36; border: 1px solid #c9f0d4; }
        .event-card { border: 1px solid #ececf5; border-radius: 10px; padding: 16px; margin-bottom: 14px; background: #fff; }
        .event-title { font-size: 16px; font-weight: 700; margin-bottom: 8px; }
        .event-meta { font-size: 13px; color: #555; margin-bottom: 5px; }
        .status-badge { display: inline-block; padding: 3px 9px; border-radius: 999px; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .2px; margin-left: 6px; }
        .status-pending { background: #fff6e6; color: #8a5a00; }
        .status-paid { background: #ecfff0; color: #1c7a36; }
        .status-failed { background: #ffecec; color: #9d2020; }
        .status-cancelled { background: #f0f0f0; color: #555; }
        .form-row { display: grid; grid-template-columns: 1fr 1fr auto; gap: 8px; margin-top: 10px; align-items: end; }
        .form-row .form-group { margin: 0; }
        label { display: block; font-size: 12px; color: #4B5563; margin-bottom: 5px; }
        input[type="text"] { width: 100%; padding: 9px 10px; border: 1px solid #d6d6e7; border-radius: 6px; font-size: 13px; }
        .btn { border: none; border-radius: 8px; padding: 10px 12px; font-size: 12px; cursor: pointer; font-weight: 700; }
        .btn-primary { background: #6C63FF; color: #fff; }
        .btn-secondary { background: #ece9ff; color: #3f379f; }
        .action-row { display: flex; gap: 8px; margin-top: 10px; flex-wrap: wrap; }
        .note { font-size: 12px; color: #667085; margin-top: 8px; }
        .top-actions { display: flex; gap: 10px; margin-bottom: 12px; }
        .top-link { text-decoration: none; background: #ece9ff; color: #3b3496; border-radius: 6px; padding: 8px 12px; font-size: 13px; }
        @media (max-width: 780px) {
            .form-row { grid-template-columns: 1fr; }
            .container { padding: 14px; }
        }
    </style>
</head>
<body>
<header class="header">
    <div class="brand-logo">PLANORA</div>
    <a href="../logout.php" class="logout-btn">Logout</a>
</header>

<div class="container">
    <div class="top-actions">
        <a class="top-link" href="dashboard.php">Back to Dashboard</a>
    </div>

    <div class="dashboard-card">
        <h2 class="card-title">Register for Vendor Markets</h2>
        <p class="card-subtitle">Book your market stall and pay via M-Pesa STK push. Paid status updates automatically after callback.</p>

        <?php if ($error !== ''): ?>
            <div class="message error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <?php if ($success !== ''): ?>
            <div class="message success"><?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>

        <?php if (!$darajaConfigured): ?>
            <div class="message error">Daraja is not fully configured, but manual payment confirmation still works.</div>
        <?php endif; ?>

        <?php if (empty($events)): ?>
            <div class="event-meta">No upcoming market events available right now.</div>
        <?php else: ?>
            <?php foreach ($events as $event): ?>
                <?php
                    $rawStatus = strtolower((string)($event['payment_status'] ?? 'pending'));
                    $statusClass = in_array($rawStatus, ['paid', 'failed', 'cancelled'], true) ? $rawStatus : 'pending';
                    $statusLabel = ucfirst($statusClass);
                    $awaitingCallback = !empty($event['checkout_request_id']) && $statusClass === 'pending';
                ?>
                <article class="event-card">
                    <div class="event-title"><?php echo htmlspecialchars((string)$event['title']); ?></div>
                    <div class="event-meta">Date: <?php echo htmlspecialchars((string)$event['event_date']); ?></div>
                    <div class="event-meta">Location: <?php echo htmlspecialchars((string)(($event['venue'] ?? '') !== '' ? $event['venue'] : 'Venue TBA')); ?><?php if ((string)($event['city'] ?? '') !== ''): ?>, <?php echo htmlspecialchars((string)$event['city']); ?><?php endif; ?></div>
                    <div class="event-meta">Stall Price: <strong>KES <?php echo number_format((float)$event['stall_price'], 2); ?></strong></div>
                    <div class="event-meta">Payment Status: <span class="status-badge status-<?php echo htmlspecialchars($statusClass); ?>"><?php echo htmlspecialchars($statusLabel); ?></span></div>
                    <div class="event-meta">Receipt: <?php echo htmlspecialchars((string)(($event['mpesa_code'] ?? '') !== '' ? $event['mpesa_code'] : 'Not provided')); ?></div>
                    <div class="event-meta">Last Update: <?php echo htmlspecialchars((string)($event['updated_at'] ?? 'N/A')); ?></div>

                    <form method="post" class="form-row" style="margin-top:12px;">
                        <?php echo csrf_input(); ?>
                        <input type="hidden" name="event_id" value="<?php echo (int)$event['event_id']; ?>">
                        <input type="hidden" name="action" value="stk_push">
                        <div class="form-group">
                            <label>Phone Number (for STK Push)</label>
                            <input type="text" name="phone_number" maxlength="20" placeholder="e.g. 0712345678" value="<?php echo htmlspecialchars((string)($event['phone_number'] ?? '')); ?>" required>
                        </div>
                        <div class="form-group">
                            <label>Stall Label (optional)</label>
                            <input type="text" name="stall_label" maxlength="80" placeholder="e.g. B-14" value="<?php echo htmlspecialchars((string)($event['stall_label'] ?? '')); ?>">
                        </div>
                        <button type="submit" class="btn btn-primary">Pay via STK</button>
                    </form>

                    <?php if ($awaitingCallback): ?>
                        <div class="note">STK request sent. Waiting for M-Pesa callback to update status automatically.</div>
                    <?php else: ?>
                        <form method="post" class="action-row">
                            <?php echo csrf_input(); ?>
                            <input type="hidden" name="event_id" value="<?php echo (int)$event['event_id']; ?>">
                            <input type="hidden" name="action" value="confirm_paid">
                            <input type="text" name="mpesa_code" maxlength="20" placeholder="Enter M-Pesa code" required>
                            <button type="submit" class="btn btn-secondary">Manual Confirm</button>
                        </form>
                    <?php endif; ?>

                    <div class="note">If callback is configured, paid status is updated automatically after successful STK completion.</div>
                </article>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
