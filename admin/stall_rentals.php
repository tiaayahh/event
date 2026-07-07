<?php
require_once '../includes/auth.php';
require_once '../includes/csrf.php';
require_once '../includes/audit.php';
checkAuth();
requireRole('planner');

$vendorId = filter_input(INPUT_GET, 'vendor_id', FILTER_VALIDATE_INT);
$eventId = filter_input(INPUT_GET, 'event_id', FILTER_VALIDATE_INT);

if (!$vendorId || !$eventId) {
    $_SESSION['flash_error'] = 'Vendor and event are required for stall registration.';
    header('Location: browse_vendors.php');
    exit;
}

$flashError = '';
$vendor = null;
$event = null;
$rental = null;
$stallLabel = '';
$amount = '';
$mpesaCode = '';

function ensureVendorTypeSchema(PDO $pdo): void
{
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

try {
    ensureVendorTypeSchema($pdo);
    ensureStallRentalsTable($pdo);

    $stmt = $pdo->prepare("SELECT vendor_id, user_id, business_name, COALESCE(vendor_type, 'service_provider') AS vendor_type FROM vendors WHERE vendor_id = ? LIMIT 1");
    $stmt->execute([$vendorId]);
    $vendor = $stmt->fetch();

    $stmt = $pdo->prepare('SELECT event_id, title, event_date, COALESCE(category, "") AS category, COALESCE(stall_price, 0) AS stall_price FROM events WHERE event_id = ? AND planner_id = ? AND archived_at IS NULL LIMIT 1');
    $stmt->execute([$eventId, $_SESSION['user_id']]);
    $event = $stmt->fetch();

    if (!$vendor || !$event) {
        $_SESSION['flash_error'] = 'Selected vendor or event was not found.';
        header('Location: browse_vendors.php');
        exit;
    }

    $isMarketEvent = strpos(strtolower(trim((string)$event['category'])), 'market') !== false;
    if (!$isMarketEvent) {
        $_SESSION['flash_error'] = 'Stall Registration is only for market events.';
        header('Location: book_vendor.php?vendor_id=' . (int)$vendorId . '&event_id=' . (int)$eventId);
        exit;
    }

    if ((string)$vendor['vendor_type'] !== 'market_operator') {
        $_SESSION['flash_error'] = 'Only market operators can be registered for stalls.';
        header('Location: browse_vendors.php?event_id=' . (int)$eventId);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_require_valid_post_token();

        if (isset($_POST['save_stall_request'])) {
            $stallLabel = trim((string)($_POST['stall_label'] ?? ''));
            $defaultAmount = (float)($event['stall_price'] ?? 0);
            $amount = trim((string)($_POST['amount'] ?? ($defaultAmount > 0 ? (string)$defaultAmount : '')));

            if ($stallLabel !== '' && strlen($stallLabel) > 80) {
                $flashError = 'Stall label must be 80 characters or fewer.';
            } elseif ($amount === '' || !is_numeric($amount) || (float)$amount <= 0) {
                $flashError = 'Please enter a valid positive stall fee amount.';
            } else {
                $stmt = $pdo->prepare(
                    "INSERT INTO stall_rentals (event_id, vendor_user_id, created_by_planner, stall_label, amount, payment_status)
                            VALUES (?, ?, ?, ?, ?, 'pending')
                     ON DUPLICATE KEY UPDATE
                        stall_label = VALUES(stall_label),
                        amount = VALUES(amount),
                                payment_status = 'pending',
                        mpesa_code = NULL,
                                checkout_request_id = NULL,
                                merchant_request_id = NULL,
                        updated_at = CURRENT_TIMESTAMP"
                );
                $stmt->execute([
                    (int)$eventId,
                    (int)$vendor['user_id'],
                    (int)$_SESSION['user_id'],
                    $stallLabel !== '' ? $stallLabel : null,
                    (float)$amount,
                ]);

                audit_log(
                    $pdo,
                    (int)$_SESSION['user_id'],
                    (string)$_SESSION['role'],
                    'stall_rental.request_saved',
                    'event',
                    (string)$eventId,
                    [
                        'vendor_id' => (int)$vendorId,
                        'vendor_user_id' => (int)$vendor['user_id'],
                        'stall_label' => $stallLabel,
                        'amount' => (float)$amount,
                    ]
                );

                $_SESSION['flash_success'] = 'Stall registration request saved. Vendor can now pay the stall fee.';
                header('Location: stall_rentals.php?vendor_id=' . (int)$vendorId . '&event_id=' . (int)$eventId);
                exit;
            }
        } elseif (isset($_POST['confirm_stall_paid'])) {
            $mpesaCode = strtoupper(trim((string)($_POST['mpesa_code'] ?? '')));

            if ($mpesaCode === '' || !preg_match('/^[A-Z0-9]{6,20}$/', $mpesaCode)) {
                $flashError = 'Enter a valid M-Pesa code (6-20 letters/numbers).';
            } else {
                $stmt = $pdo->prepare(
                    "UPDATE stall_rentals
                     SET payment_status = 'paid', mpesa_code = ?, updated_at = CURRENT_TIMESTAMP
                     WHERE event_id = ? AND vendor_user_id = ?"
                );
                $stmt->execute([$mpesaCode, (int)$eventId, (int)$vendor['user_id']]);

                if ($stmt->rowCount() > 0) {
                    audit_log(
                        $pdo,
                        (int)$_SESSION['user_id'],
                        (string)$_SESSION['role'],
                        'stall_rental.payment_confirmed',
                        'event',
                        (string)$eventId,
                        [
                            'vendor_id' => (int)$vendorId,
                            'vendor_user_id' => (int)$vendor['user_id'],
                            'mpesa_code' => $mpesaCode,
                        ]
                    );

                    $_SESSION['flash_success'] = 'Stall rental marked as paid.';
                    header('Location: stall_rentals.php?vendor_id=' . (int)$vendorId . '&event_id=' . (int)$eventId);
                    exit;
                }

                $flashError = 'No stall request found. Save a stall request first.';
            }
        }
    }

    $stmt = $pdo->prepare('SELECT * FROM stall_rentals WHERE event_id = ? AND vendor_user_id = ? LIMIT 1');
    $stmt->execute([(int)$eventId, (int)$vendor['user_id']]);
    $rental = $stmt->fetch();
} catch (Throwable $e) {
    $flashError = 'Unable to process stall registration right now.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="../assets/style.css">
    <title>Planora - Stall Registration</title>
    <style>
        body { background: #f6f6f6; color: #2D2D2D; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; }
        .header { background: #6C63FF; color: #fff; padding: 14px 24px; display: flex; justify-content: space-between; align-items: center; }
        .brand { font-size: 22px; font-weight: 700; }
        .logout-btn { background: rgba(255,255,255,.25); color: #fff; text-decoration: none; padding: 7px 14px; border-radius: 5px; font-size: 13px; }
        .container { max-width: 900px; margin: 0 auto; padding: 20px; }
        .card { background: #fff; border-radius: 10px; padding: 20px; margin-bottom: 20px; box-shadow: 0 1px 3px rgba(0,0,0,.05); }
        .title { font-size: 18px; font-weight: 700; margin-bottom: 10px; }
        .meta { color: #666; font-size: 14px; margin-bottom: 6px; }
        .message-error { background: #ffecec; color: #9d2020; border: 1px solid #f6caca; border-radius: 6px; padding: 10px 12px; margin-bottom: 14px; font-size: 13px; }
        .message-success { background: #ecfdf3; color: #0f5132; border: 1px solid #b8ebcf; border-radius: 6px; padding: 10px 12px; margin-bottom: 14px; font-size: 13px; }
        .field { margin-bottom: 12px; }
        .field label { display: block; font-size: 13px; margin-bottom: 6px; color: #4B5563; }
        .input { width: 100%; border: 1px solid #d9d9d9; border-radius: 8px; padding: 10px 12px; font-size: 14px; }
        .btn { background: #6C63FF; color: #fff; border: none; border-radius: 8px; padding: 10px 14px; font-size: 13px; cursor: pointer; }
        .btn + .btn { margin-left: 8px; }
        .link-btn { text-decoration: none; background: #ece9ff; color: #3b3496; border-radius: 6px; padding: 8px 12px; font-size: 13px; }
        .top-links { display: flex; gap: 10px; margin-bottom: 16px; }
        .status-pill { display: inline-block; border-radius: 999px; padding: 3px 10px; font-size: 12px; font-weight: 700; text-transform: uppercase; background: #f2f2f2; color: #333; }
        .status-pill.paid { background: #ecfdf3; color: #0f5132; }
        .status-pill.requested { background: #fff4e5; color: #8a5a00; }
    </style>
</head>
<body>
<header class="header">
    <div class="brand">PLANORA</div>
    <a class="logout-btn" href="../logout.php">Logout</a>
</header>

<div class="container">
    <div class="top-links">
        <a class="link-btn" href="browse_vendors.php?event_id=<?php echo (int)$eventId; ?>">Back to Vendors</a>
        <a class="link-btn" href="dashboard.php">Dashboard</a>
    </div>

    <?php if (!empty($_SESSION['flash_success'])): ?>
        <div class="message-success"><?php echo htmlspecialchars((string)$_SESSION['flash_success']); ?></div>
        <?php unset($_SESSION['flash_success']); ?>
    <?php endif; ?>
    <?php if ($flashError !== ''): ?>
        <div class="message-error"><?php echo htmlspecialchars($flashError); ?></div>
    <?php endif; ?>

    <section class="card">
        <h2 class="title">Stall Registration</h2>
        <div class="meta">Event: <strong><?php echo htmlspecialchars((string)($event['title'] ?? '')); ?></strong> (<?php echo htmlspecialchars((string)($event['event_date'] ?? '')); ?>)</div>
        <div class="meta">Vendor: <strong><?php echo htmlspecialchars((string)($vendor['business_name'] ?? '')); ?></strong> (Market Operator)</div>
        <?php if ($rental): ?>
            <div class="meta">Current Status: <span class="status-pill <?php echo htmlspecialchars((string)($rental['payment_status'] ?? 'pending')); ?>"><?php echo htmlspecialchars((string)($rental['payment_status'] ?? 'pending')); ?></span></div>
            <div class="meta">Current Amount: KES <?php echo number_format((float)($rental['amount'] ?? 0), 2); ?></div>
            <div class="meta">Stall Label: <?php echo htmlspecialchars((string)(($rental['stall_label'] ?? '') !== '' ? $rental['stall_label'] : 'Not set')); ?></div>
            <div class="meta">M-Pesa Code: <?php echo htmlspecialchars((string)(($rental['mpesa_code'] ?? '') !== '' ? $rental['mpesa_code'] : 'Not provided')); ?></div>
        <?php endif; ?>
    </section>

    <section class="card">
        <h3 class="title">Create / Update Stall Fee Request</h3>
        <form method="POST">
            <?php echo csrf_input(); ?>
            <input type="hidden" name="save_stall_request" value="1">
            <div class="field">
                <label for="stall_label">Stall Label (optional)</label>
                <input class="input" type="text" id="stall_label" name="stall_label" maxlength="80" value="<?php echo htmlspecialchars((string)($rental['stall_label'] ?? $stallLabel)); ?>" placeholder="e.g. A12">
            </div>
            <div class="field">
                <label for="amount">Stall Fee Amount (KES)</label>
                <input class="input" type="number" step="0.01" min="1" id="amount" name="amount" value="<?php echo htmlspecialchars((string)($rental['amount'] ?? ($amount !== '' ? $amount : number_format((float)($event['stall_price'] ?? 0), 2, '.', '')))); ?>" required>
            </div>
            <button class="btn" type="submit">Save Stall Request</button>
        </form>
    </section>

    <section class="card">
        <h3 class="title">Confirm Vendor Payment</h3>
        <form method="POST">
            <?php echo csrf_input(); ?>
            <input type="hidden" name="confirm_stall_paid" value="1">
            <div class="field">
                <label for="mpesa_code">M-Pesa Code</label>
                <input class="input" type="text" id="mpesa_code" name="mpesa_code" maxlength="20" value="<?php echo htmlspecialchars($mpesaCode); ?>" placeholder="e.g. QWE123RTY" required>
            </div>
            <button class="btn" type="submit">Mark As Paid</button>
        </form>
    </section>
</div>
</body>
</html>
