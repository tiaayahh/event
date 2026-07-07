<?php
require_once '../includes/auth.php';
require_once '../includes/csrf.php';
require_once '../includes/audit.php';
require_once '../includes/daraja.php';

checkAuth();
requireRole('vendor');

function ensureVendorFeePaymentsTable(PDO $pdo): void {
    static $ready = false;
    if ($ready) {
        return;
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS vendor_fee_payments (
            payment_id BIGINT AUTO_INCREMENT PRIMARY KEY,
            event_id INT NOT NULL,
            vendor_user_id INT NOT NULL,
            checkout_request_id VARCHAR(120) DEFAULT NULL,
            merchant_request_id VARCHAR(120) DEFAULT NULL,
            phone_number VARCHAR(20) DEFAULT NULL,
            mpesa_code VARCHAR(64) DEFAULT NULL,
            amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            status ENUM('requested', 'paid', 'failed') NOT NULL DEFAULT 'requested',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_vendor_fee_payment_unique (event_id, vendor_user_id),
            INDEX idx_vendor_fee_payment_event_status (event_id, status),
            INDEX idx_vendor_fee_payment_vendor (vendor_user_id),
            CONSTRAINT fk_vendor_fee_payment_event FOREIGN KEY (event_id) REFERENCES events(event_id) ON DELETE CASCADE,
            CONSTRAINT fk_vendor_fee_payment_user FOREIGN KEY (vendor_user_id) REFERENCES users(user_id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $ready = true;
}

function ensureEventVendorFeeSchema(PDO $pdo): void {
    static $ready = false;
    if ($ready) {
        return;
    }

    $stmt = $pdo->query("SHOW COLUMNS FROM events LIKE 'vendor_fee_amount'");
    if (!$stmt->fetch()) {
        $pdo->exec("ALTER TABLE events ADD COLUMN vendor_fee_amount DECIMAL(10,2) NOT NULL DEFAULT 100.00");
    }

    $ready = true;
}

function ensureVendorTypeSchema(PDO $pdo): void {
    static $ready = false;
    if ($ready) {
        return;
    }

    $stmt = $pdo->query("SHOW COLUMNS FROM vendors LIKE 'vendor_type'");
    if (!$stmt->fetch()) {
        $pdo->exec("ALTER TABLE vendors ADD COLUMN vendor_type ENUM('service_provider','market_operator') NOT NULL DEFAULT 'service_provider' AFTER service_type");
    }

    $ready = true;
}

$vendorUserId = (int)$_SESSION['user_id'];
$vendorId = 0;
$vendorType = 'service_provider';
$events = [];
$error = '';
$success = '';
$defaultFeeAmount = 100.00;
$selectedEventId = 0;
$darajaConfigured = daraja_is_configured();
$isServiceProvider = true;

try {
    ensureVendorTypeSchema($pdo);

    $stmt = $pdo->prepare("SELECT vendor_id, COALESCE(vendor_type, 'service_provider') AS vendor_type FROM vendors WHERE user_id = ? LIMIT 1");
    $stmt->execute([$vendorUserId]);
    $vendor = $stmt->fetch();
    $vendorId = (int)($vendor['vendor_id'] ?? 0);
    $vendorType = (string)($vendor['vendor_type'] ?? 'service_provider');
    $isServiceProvider = $vendorType === 'service_provider';

    if ($vendorId <= 0) {
        throw new RuntimeException('Vendor profile not found.');
    }

    ensureVendorFeePaymentsTable($pdo);
    ensureEventVendorFeeSchema($pdo);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
            throw new RuntimeException('Invalid CSRF token.');
        }

        if ($isServiceProvider) {
            throw new RuntimeException('Service providers are paid by planners. You do not pay vendor fees.');
        }

        $eventId = (int)($_POST['event_id'] ?? 0);
        $selectedEventId = $eventId;
        $action = strtolower(trim((string)($_POST['action'] ?? '')));

        if ($eventId <= 0) {
            throw new RuntimeException('Invalid event selected.');
        }

        $stmt = $pdo->prepare(
            "SELECT COUNT(*)
             FROM bookings b
             JOIN services s ON s.service_id = b.service_id
             WHERE b.event_id = ? AND s.vendor_id = ?"
        );
        $stmt->execute([$eventId, $vendorId]);
        $isLinked = (int)$stmt->fetchColumn() > 0;
        if (!$isLinked) {
            throw new RuntimeException('You can only pay fees for events linked to your bookings.');
        }

        if ($action === 'stk_push') {
            $phoneNumber = trim((string)($_POST['phone_number'] ?? ''));

            $stmt = $pdo->prepare('SELECT COALESCE(vendor_fee_amount, 100) FROM events WHERE event_id = ? AND archived_at IS NULL LIMIT 1');
            $stmt->execute([$eventId]);
            $feeAmount = (float)($stmt->fetchColumn() ?: $defaultFeeAmount);

            $pushResult = daraja_stk_push(
                $phoneNumber,
                $feeAmount,
                'VENDOR-FEE-' . $eventId . '-' . $vendorUserId,
                'Planora vendor event fee payment'
            );

            if (empty($pushResult['success'])) {
                throw new RuntimeException((string)($pushResult['message'] ?? 'Unable to initiate M-Pesa STK push right now.'));
            }

            $stmt = $pdo->prepare(
                "INSERT INTO vendor_fee_payments
                    (event_id, vendor_user_id, checkout_request_id, merchant_request_id, phone_number, amount, status)
                 VALUES (?, ?, ?, ?, ?, ?, 'requested')
                 ON DUPLICATE KEY UPDATE
                    checkout_request_id = VALUES(checkout_request_id),
                    merchant_request_id = VALUES(merchant_request_id),
                    phone_number = VALUES(phone_number),
                    amount = VALUES(amount),
                    status = 'requested',
                    updated_at = CURRENT_TIMESTAMP"
            );
            $stmt->execute([
                $eventId,
                $vendorUserId,
                (string)($pushResult['checkout_request_id'] ?? ''),
                (string)($pushResult['merchant_request_id'] ?? ''),
                $phoneNumber,
                $feeAmount,
            ]);

            audit_log(
                $pdo,
                $vendorUserId,
                'vendor',
                'vendor.fee_stk_push_requested',
                'event',
                $eventId,
                [
                    'amount' => $feeAmount,
                    'checkout_request_id' => (string)($pushResult['checkout_request_id'] ?? ''),
                ]
            );

            $success = 'M-Pesa STK push sent. Complete the prompt on your phone. Payment status will update automatically after callback.';
        } elseif ($action === 'confirm_paid') {
            $mpesaCode = strtoupper(trim((string)($_POST['mpesa_code'] ?? '')));
            if (!preg_match('/^[A-Z0-9]{6,20}$/', $mpesaCode)) {
                throw new RuntimeException('Enter a valid M-Pesa code (6-20 letters/numbers).');
            }

            $stmt = $pdo->prepare('SELECT COALESCE(vendor_fee_amount, 100) FROM events WHERE event_id = ? AND archived_at IS NULL LIMIT 1');
            $stmt->execute([$eventId]);
            $feeAmount = (float)($stmt->fetchColumn() ?: $defaultFeeAmount);

            $stmt = $pdo->prepare(
                "INSERT INTO vendor_fee_payments
                    (event_id, vendor_user_id, mpesa_code, amount, status)
                 VALUES (?, ?, ?, ?, 'paid')
                 ON DUPLICATE KEY UPDATE
                    mpesa_code = VALUES(mpesa_code),
                    amount = VALUES(amount),
                    status = 'paid',
                    updated_at = CURRENT_TIMESTAMP"
            );
            $stmt->execute([$eventId, $vendorUserId, $mpesaCode, $feeAmount]);

            audit_log(
                $pdo,
                $vendorUserId,
                'vendor',
                'vendor.fee_payment_confirmed',
                'event',
                $eventId,
                [
                    'amount' => $feeAmount,
                    'mpesa_code' => $mpesaCode,
                ]
            );

            $success = 'Vendor event fee marked as paid.';
        }
    }

        if (!$isServiceProvider) {
                $stmt = $pdo->prepare(
                        "SELECT e.event_id, e.title, e.event_date, COALESCE(e.vendor_fee_amount, 100) AS vendor_fee_amount,
                                        esp.status AS fee_status,
                                        esp.checkout_request_id,
                                        esp.mpesa_code,
                                        esp.updated_at
                         FROM events e
                         JOIN bookings b ON b.event_id = e.event_id
                         JOIN services s ON s.service_id = b.service_id AND s.vendor_id = ?
                             LEFT JOIN vendor_fee_payments esp
                                        ON esp.event_id = e.event_id
                                     AND esp.vendor_user_id = ?
                             WHERE e.archived_at IS NULL
                             GROUP BY e.event_id, e.title, e.event_date, esp.status, esp.checkout_request_id, esp.mpesa_code, esp.updated_at
                         ORDER BY e.event_date DESC"
                );
                $stmt->execute([$vendorId, $vendorUserId]);
                $events = $stmt->fetchAll();
        }
} catch (Throwable $e) {
    $error = $e->getMessage();
    error_log('vendor/pay_fee.php error: ' . $e->getMessage());
    if (isset($pdo) && $pdo instanceof PDO) {
        audit_log(
            $pdo,
            $vendorUserId,
            'vendor',
            'vendor.fee_payment_error',
            'event',
            null,
            ['error' => $e->getMessage()]
        );
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Vendor Selling Fee - Planora</title>
    <link rel="stylesheet" href="../assets/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
        }

        body {
            background-color: #F5F5F5;
            color: #2D2D2D;
            padding-bottom: 80px;
        }

        .header {
            background-color: #6C63FF;
            color: white;
            padding: 14px 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .brand-logo {
            font-size: 22px;
            font-weight: 700;
            letter-spacing: 0.5px;
        }

        .logout-btn {
            background-color: rgba(255, 255, 255, 0.25);
            color: white;
            border: 1px solid rgba(255, 255, 255, 0.3);
            padding: 6px 14px;
            border-radius: 4px;
            text-decoration: none;
            font-size: 13px;
            font-weight: 500;
            transition: background 0.2s;
        }

        .logout-btn:hover {
            background-color: rgba(255, 255, 255, 0.35);
        }

        .container {
            max-width: 1100px;
            margin: 0 auto;
            padding: 20px;
        }

        .dashboard-card {
            background-color: #ffffff;
            border-radius: 6px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
        }

        .card-title {
            font-size: 20px;
            font-weight: 700;
            margin-bottom: 8px;
            color: #1a1a1a;
        }

        .card-subtitle {
            color: #666666;
            font-size: 13px;
            margin-top: -2px;
            margin-bottom: 14px;
        }

        .top-actions {
            display: flex;
            justify-content: flex-end;
            margin-bottom: 12px;
        }

        .message {
            border-radius: 6px;
            padding: 10px 12px;
            margin-bottom: 14px;
            font-size: 13px;
        }

        .message.error {
            background: #ffecec;
            color: #9d2020;
            border: 1px solid #f6caca;
        }

        .message.success {
            background: #ecfff0;
            color: #1c7a36;
            border: 1px solid #c9f0d4;
        }

        .fee-item {
            border: 1px solid #ececec;
            border-radius: 8px;
            padding: 16px;
            margin-bottom: 14px;
            background: #ffffff;
        }

        .fee-title {
            font-size: 16px;
            font-weight: 700;
            margin-bottom: 8px;
        }

        .fee-meta {
            font-size: 13px;
            color: #555;
            margin-bottom: 5px;
        }

        .status-badge {
            display: inline-block;
            padding: 3px 9px;
            border-radius: 999px;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.2px;
            margin-left: 6px;
        }

        .status-paid {
            background: #e8f9ef;
            color: #1c7a36;
        }

        .status-pending {
            background: #fff4df;
            color: #a36500;
        }

        .status-failed {
            background: #ffecec;
            color: #9d2020;
        }

        .fee-form {
            margin-top: 10px;
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            align-items: end;
        }

        .field {
            display: flex;
            flex-direction: column;
            gap: 6px;
            min-width: 260px;
            flex: 1;
        }

        .field label {
            font-size: 12px;
            color: #555;
            font-weight: 600;
        }

        .field input {
            border: 1px solid #d8d8d8;
            border-radius: 4px;
            padding: 10px 12px;
            font-size: 14px;
            outline: none;
        }

        .field input:focus {
            border-color: #6C63FF;
            box-shadow: 0 0 0 3px rgba(108, 99, 255, 0.12);
        }

        .btn-primary {
            background-color: #6C63FF;
            color: #fff;
            border: none;
            border-radius: 4px;
            padding: 10px 14px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: background-color 0.2s;
        }

        .btn-primary:hover {
            background-color: #5e56de;
        }

        .btn-secondary {
            background: #ece9ff;
            color: #3f379f;
            border: 1px solid #c9c2ff;
            border-radius: 4px;
            padding: 10px 14px;
            font-size: 13px;
            font-weight: 600;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .actions-row {
            margin-top: 10px;
        }

        .payment-form {
            border: 1px solid #ececec;
            border-radius: 8px;
            padding: 16px;
            margin-bottom: 16px;
            background: #fafafa;
        }

        .payment-grid {
            display: grid;
            grid-template-columns: 1fr 1fr auto;
            gap: 10px;
            align-items: end;
        }

        .payment-grid select,
        .payment-grid input {
            border: 1px solid #d8d8d8;
            border-radius: 4px;
            padding: 10px 12px;
            font-size: 14px;
            outline: none;
        }

        .payment-grid select:focus,
        .payment-grid input:focus {
            border-color: #6C63FF;
            box-shadow: 0 0 0 3px rgba(108, 99, 255, 0.12);
        }

        .bottom-nav {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            background: #2D2D2D;
            display: flex;
            justify-content: space-around;
            align-items: center;
            height: 65px;
            z-index: 999;
        }

        .nav-link {
            color: #ffffff;
            text-decoration: none;
            font-size: 12px;
            font-weight: 500;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 4px;
            opacity: 0.8;
            flex: 1;
            height: 100%;
            justify-content: center;
            transition: background-color 0.2s, opacity 0.2s;
        }

        .nav-link i {
            font-size: 18px;
        }

        .nav-link.active,
        .nav-link:hover {
            opacity: 1;
            background: rgba(255,255,255,0.08);
        }

        @media (max-width: 700px) {
            .fee-form {
                flex-direction: column;
                align-items: stretch;
            }

            .payment-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <header class="header">
        <div class="brand-logo">PLANORA</div>
        <a href="../logout.php" class="logout-btn">Logout</a>
    </header>

    <div class="container">
        <div class="dashboard-card">
            <h2 class="card-title">Vendor Event Fee (M-Pesa)</h2>
            <?php if ($isServiceProvider): ?>
                <p class="card-subtitle">Service providers are paid by planners. No vendor fee payment is required.</p>
            <?php else: ?>
                <p class="card-subtitle">Pay event fee set by the planner for each event via M-Pesa to keep selling services.</p>
            <?php endif; ?>

            <?php if (!$darajaConfigured): ?>
                <div class="message error">Daraja is not configured yet. Set DARAJA_CONSUMER_KEY, DARAJA_CONSUMER_SECRET, DARAJA_SHORTCODE, DARAJA_PASSKEY, and DARAJA_CALLBACK_URL.</div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="message error"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="message success"><?php echo htmlspecialchars($success); ?></div>
            <?php endif; ?>

            <?php if ($isServiceProvider): ?>
                <div class="message success">No fee needed: your services are paid by planners through the normal booking and payment flow.</div>
            <?php elseif (empty($events)): ?>
                <div class="message">No events are linked to your bookings yet.</div>
            <?php else: ?>
                <form method="POST" class="payment-form">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generate_csrf_token()); ?>">
                    <input type="hidden" name="action" value="stk_push">
                    <div class="payment-grid">
                        <div class="field" style="min-width: 0;">
                            <label for="event_id">Select Event</label>
                            <select id="event_id" name="event_id" required>
                                <option value="">Choose event</option>
                                <?php foreach ($events as $eventOption): ?>
                                    <option value="<?php echo (int)$eventOption['event_id']; ?>" <?php echo $selectedEventId === (int)$eventOption['event_id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($eventOption['title']); ?> (<?php echo htmlspecialchars($eventOption['event_date']); ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="field" style="min-width: 0;">
                            <label for="phone_number">Phone Number (for STK Push)</label>
                            <input id="phone_number" type="text" name="phone_number" maxlength="20" placeholder="e.g. 0712345678" required>
                        </div>

                        <button type="submit" class="btn-primary">
                            <i class="fa-solid fa-credit-card"></i>
                            Send STK Push
                        </button>
                    </div>
                </form>

                <?php foreach ($events as $event): ?>
                    <?php
                    $status = strtolower((string)($event['fee_status'] ?? 'pending'));
                    $statusClass = 'status-pending';
                    if ($status === 'paid') {
                        $statusClass = 'status-paid';
                    } elseif ($status === 'failed') {
                        $statusClass = 'status-failed';
                    }
                    $awaitingCallback = !empty($event['checkout_request_id']) && in_array($status, ['requested', 'pending'], true);
                    ?>
                    <div class="fee-item">
                        <div class="fee-title"><?php echo htmlspecialchars($event['title']); ?></div>
                        <div class="fee-meta">Date: <?php echo htmlspecialchars($event['event_date']); ?></div>
                        <div class="fee-meta">Fee: KES <?php echo number_format((float)$event['vendor_fee_amount'], 2); ?></div>
                        <div class="fee-meta">
                            Status:
                            <span class="status-badge <?php echo $statusClass; ?>"><?php echo htmlspecialchars($event['fee_status'] ?? 'not paid'); ?></span>
                        </div>
                        <?php if (!empty($event['checkout_request_id'])): ?>
                            <div class="fee-meta">Checkout Request ID: <?php echo htmlspecialchars((string)$event['checkout_request_id']); ?></div>
                        <?php endif; ?>
                        <?php if (!empty($event['mpesa_code'])): ?>
                            <div class="fee-meta">M-Pesa Code: <?php echo htmlspecialchars($event['mpesa_code']); ?></div>
                        <?php endif; ?>

                        <?php if ($awaitingCallback): ?>
                            <div class="fee-meta" style="margin-top:10px; color:#2c4ea0;">
                                STK request sent. Waiting for M-Pesa callback to update status automatically.
                            </div>
                        <?php else: ?>
                            <form method="POST" class="fee-form" style="margin-top: 10px;">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generate_csrf_token()); ?>">
                                <input type="hidden" name="action" value="confirm_paid">
                                <input type="hidden" name="event_id" value="<?php echo (int)$event['event_id']; ?>">
                                <div class="field" style="min-width: 0;">
                                    <label for="mpesa_code_<?php echo (int)$event['event_id']; ?>">Confirm with M-Pesa Code</label>
                                    <input id="mpesa_code_<?php echo (int)$event['event_id']; ?>" type="text" name="mpesa_code" maxlength="20" placeholder="e.g. QWE123RTY" required>
                                </div>
                                <button type="submit" class="btn-primary"><i class="fa-solid fa-check"></i> Confirm Paid</button>
                            </form>
                        <?php endif; ?>

                    </div>
                <?php endforeach; ?>
            <?php endif; ?>

            <div class="actions-row">
                <a href="dashboard.php" class="btn-secondary">
                    <i class="fa-solid fa-arrow-left"></i>
                    Back to Vendor Dashboard
                </a>
            </div>
        </div>
    </div>

    <nav class="bottom-nav">
        <a href="dashboard.php" class="nav-link"><i class="fa-solid fa-house"></i><span>Home</span></a>
        <a href="services.php" class="nav-link"><i class="fa-solid fa-bell-concierge"></i><span>Services</span></a>
        <a href="bookings.php" class="nav-link"><i class="fa-solid fa-book-open"></i><span>Bookings</span></a>
        <a href="pay_fee.php" class="nav-link active"><i class="fa-solid fa-wallet"></i><span>Fees</span></a>
        <a href="schedule.php" class="nav-link"><i class="fa-solid fa-calendar-days"></i><span>Schedule</span></a>
        <a href="profile.php" class="nav-link"><i class="fa-solid fa-user"></i><span>Profile</span></a>
    </nav>
</body>
</html>
