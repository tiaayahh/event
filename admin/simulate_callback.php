<?php
require_once '../includes/auth.php';
require_once '../includes/csrf.php';
require_once '../includes/audit.php';
require_once '../includes/daraja.php';

checkAuth();
requireRole('planner');

$enabled = trim((string)(getenv('ENABLE_CALLBACK_SIMULATION') ?: '')) === '1';
$message = '';
$error = '';

function ensureStallRentalsSimulationSchema(PDO $pdo): void
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

    $ready = true;
}

function ensureVendorFeeSimulationSchema(PDO $pdo): void
{
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
            UNIQUE KEY uq_vendor_fee_checkout (checkout_request_id),
            INDEX idx_vendor_fee_payment_event_status (event_id, status),
            INDEX idx_vendor_fee_payment_vendor (vendor_user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $ready = true;
}

try {
    ensure_daraja_stk_requests_table($pdo);
    ensureStallRentalsSimulationSchema($pdo);
    ensureVendorFeeSimulationSchema($pdo);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_require_valid_post_token();

        if (!$enabled) {
            throw new RuntimeException('Simulation is disabled. Set ENABLE_CALLBACK_SIMULATION=1 to use this page.');
        }

        $checkoutRequestId = trim((string)($_POST['checkout_request_id'] ?? ''));
        $result = strtolower(trim((string)($_POST['result'] ?? 'paid')));
        $mpesaCode = strtoupper(trim((string)($_POST['mpesa_code'] ?? 'SIMULATED')));
        $amountRaw = trim((string)($_POST['amount'] ?? ''));

        if ($checkoutRequestId === '') {
            throw new RuntimeException('Checkout Request ID is required.');
        }
        if (!in_array($result, ['paid', 'failed'], true)) {
            throw new RuntimeException('Result must be either paid or failed.');
        }
        $amount = ($amountRaw !== '' && is_numeric($amountRaw)) ? (float)$amountRaw : null;

        $pdo->beginTransaction();

        $stmt = $pdo->prepare('SELECT booking_id, planner_user_id, status FROM daraja_stk_requests WHERE checkout_request_id = ? LIMIT 1');
        $stmt->execute([$checkoutRequestId]);
        $bookingRequest = $stmt->fetch();

        if ($bookingRequest) {
            $bookingId = (int)$bookingRequest['booking_id'];
            $newPaymentStatus = $result;

            $stmt = $pdo->prepare('SELECT b.event_id, b.status AS booking_status, b.booked_price FROM bookings b WHERE b.booking_id = ? LIMIT 1');
            $stmt->execute([$bookingId]);
            $booking = $stmt->fetch();
            if (!$booking) {
                throw new RuntimeException('Linked booking not found for this checkout request.');
            }

            $oldBookingStatus = strtolower((string)$booking['booking_status']);
            $bookedPrice = (float)$booking['booked_price'];
            $finalAmount = $amount !== null ? $amount : $bookedPrice;
            $newBookingStatus = $newPaymentStatus === 'paid' ? 'confirmed' : 'pending';
            $platformFee = $newBookingStatus === 'confirmed' ? ($bookedPrice * 0.10) : 0.0;

            $stmt = $pdo->prepare('UPDATE daraja_stk_requests SET merchant_request_id = ?, status = ?, callback_payload = ? WHERE checkout_request_id = ?');
            $stmt->execute([
                'SIM-' . substr(sha1($checkoutRequestId . microtime(true)), 0, 16),
                $newPaymentStatus,
                json_encode(['simulated' => true, 'result' => $newPaymentStatus, 'amount' => $finalAmount], JSON_UNESCAPED_SLASHES),
                $checkoutRequestId,
            ]);

            $stmt = $pdo->prepare('SELECT booking_id FROM transactions WHERE booking_id = ? LIMIT 1');
            $stmt->execute([$bookingId]);
            if ($stmt->fetch()) {
                $stmt = $pdo->prepare('UPDATE transactions SET mpesa_code = ?, amount = ?, status = ? WHERE booking_id = ?');
                $stmt->execute([$mpesaCode, $finalAmount, $newPaymentStatus, $bookingId]);
            } else {
                $stmt = $pdo->prepare('INSERT INTO transactions (booking_id, mpesa_code, amount, status) VALUES (?, ?, ?, ?)');
                $stmt->execute([$bookingId, $mpesaCode, $finalAmount, $newPaymentStatus]);
            }

            $stmt = $pdo->prepare('UPDATE bookings SET status = ?, platform_fee = ? WHERE booking_id = ?');
            $stmt->execute([$newBookingStatus, $platformFee, $bookingId]);

            if ($newBookingStatus === 'confirmed' && $oldBookingStatus !== 'confirmed') {
                $stmt = $pdo->prepare('UPDATE events SET budget_committed = budget_committed + ? WHERE event_id = ?');
                $stmt->execute([$bookedPrice, (int)$booking['event_id']]);
            } elseif ($newBookingStatus !== 'confirmed' && $oldBookingStatus === 'confirmed') {
                $stmt = $pdo->prepare('UPDATE events SET budget_committed = GREATEST(0, budget_committed - ?) WHERE event_id = ?');
                $stmt->execute([$bookedPrice, (int)$booking['event_id']]);
            }

            $pdo->commit();

            audit_log($pdo, (int)$_SESSION['user_id'], (string)$_SESSION['role'], 'payment.callback_simulated', 'booking', (string)$bookingId, [
                'checkout_request_id' => $checkoutRequestId,
                'result' => $newPaymentStatus,
                'amount' => $finalAmount,
            ]);

            $message = 'Simulated callback applied to booking payment.';
        } else {
            $stmt = $pdo->prepare('SELECT rental_id FROM stall_rentals WHERE checkout_request_id = ? LIMIT 1');
            $stmt->execute([$checkoutRequestId]);
            $stall = $stmt->fetch();

            if ($stall) {
                $rentalId = (int)$stall['rental_id'];
                $stmt = $pdo->prepare('UPDATE stall_rentals SET merchant_request_id = ?, payment_status = ?, mpesa_code = ?, updated_at = CURRENT_TIMESTAMP WHERE rental_id = ?');
                $stmt->execute([
                    'SIM-' . substr(sha1($checkoutRequestId . microtime(true)), 0, 16),
                    $result,
                    $mpesaCode,
                    $rentalId,
                ]);

                $pdo->commit();

                audit_log($pdo, (int)$_SESSION['user_id'], (string)$_SESSION['role'], 'payment.callback_simulated', 'stall_rental', (string)$rentalId, [
                    'checkout_request_id' => $checkoutRequestId,
                    'result' => $result,
                    'amount' => $amount,
                ]);

                $message = 'Simulated callback applied to stall rental payment.';
            } else {
                $stmt = $pdo->prepare('SELECT payment_id FROM vendor_fee_payments WHERE checkout_request_id = ? LIMIT 1');
                $stmt->execute([$checkoutRequestId]);
                $vendorFee = $stmt->fetch();

                if ($vendorFee) {
                    $paymentId = (int)$vendorFee['payment_id'];
                    $stmt = $pdo->prepare('UPDATE vendor_fee_payments SET merchant_request_id = ?, status = ?, mpesa_code = ?, updated_at = CURRENT_TIMESTAMP WHERE payment_id = ?');
                    $stmt->execute([
                        'SIM-' . substr(sha1($checkoutRequestId . microtime(true)), 0, 16),
                        $result,
                        $mpesaCode,
                        $paymentId,
                    ]);

                    $pdo->commit();

                    audit_log($pdo, (int)$_SESSION['user_id'], (string)$_SESSION['role'], 'payment.callback_simulated', 'vendor_fee_payment', (string)$paymentId, [
                        'checkout_request_id' => $checkoutRequestId,
                        'result' => $result,
                        'amount' => $amount,
                    ]);

                    $message = 'Simulated callback applied to vendor fee payment.';
                } else {
                    $pdo->rollBack();
                    throw new RuntimeException('Checkout Request ID was not found in booking, stall, or vendor fee payment records.');
                }
            }
        }
    }
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $error = $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Simulate Callback - Planora</title>
    <link rel="stylesheet" href="../assets/style.css">
    <style>
        body { background:#f5f5f5; color:#2D2D2D; font-family:-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; }
        .container { max-width:760px; margin:24px auto; padding:0 16px; }
        .card { background:#fff; border:1px solid #ece9ff; border-radius:12px; padding:18px; box-shadow:0 1px 3px rgba(0,0,0,.05); }
        .title { font-size:20px; font-weight:700; margin-bottom:10px; }
        .sub { font-size:13px; color:#666; margin-bottom:14px; }
        .msg { border-radius:8px; padding:10px 12px; font-size:13px; margin-bottom:12px; }
        .ok { background:#ecfff0; border:1px solid #c9f0d4; color:#1c7a36; }
        .err { background:#ffecec; border:1px solid #f6caca; color:#9d2020; }
        .warn { background:#fff4df; border:1px solid #f2dcaa; color:#8a5a00; }
        .field { margin-bottom:12px; }
        label { display:block; font-size:13px; margin-bottom:6px; color:#4B5563; }
        input, select { width:100%; border:1px solid #d8d8e6; border-radius:8px; padding:10px; font-size:14px; }
        .row { display:grid; grid-template-columns:1fr 1fr; gap:10px; }
        .btn { border:0; background:#6C63FF; color:#fff; border-radius:8px; padding:10px 12px; font-weight:600; cursor:pointer; }
        .link { display:inline-block; margin-top:10px; color:#3b3496; text-decoration:none; font-size:13px; }
        @media (max-width:720px) { .row { grid-template-columns:1fr; } }
    </style>
</head>
<body>
<div class="container">
    <div class="card">
        <div class="title">Simulate M-Pesa Callback</div>
        <div class="sub">Testing utility for local/dev workflows. Updates booking, stall rental, or vendor fee payment records by Checkout Request ID.</div>

        <?php if (!$enabled): ?>
            <div class="msg warn">Simulation is disabled. Set <strong>ENABLE_CALLBACK_SIMULATION=1</strong> in your environment to use this page.</div>
        <?php endif; ?>

        <?php if ($message !== ''): ?><div class="msg ok"><?php echo htmlspecialchars($message); ?></div><?php endif; ?>
        <?php if ($error !== ''): ?><div class="msg err"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>

        <form method="post">
            <?php echo csrf_input(); ?>
            <div class="field">
                <label for="checkout_request_id">Checkout Request ID</label>
                <input id="checkout_request_id" name="checkout_request_id" placeholder="ws_CO_..." required>
            </div>

            <div class="row">
                <div class="field">
                    <label for="result">Result</label>
                    <select id="result" name="result">
                        <option value="paid">Paid</option>
                        <option value="failed">Failed</option>
                    </select>
                </div>
                <div class="field">
                    <label for="mpesa_code">M-Pesa Code</label>
                    <input id="mpesa_code" name="mpesa_code" value="SIMULATED">
                </div>
            </div>

            <div class="field">
                <label for="amount">Amount (optional override)</label>
                <input id="amount" name="amount" type="number" step="0.01" min="0" placeholder="Leave empty to use existing amount">
            </div>

            <button type="submit" class="btn">Run Simulation</button>
        </form>

        <a href="dashboard.php" class="link">Back to Dashboard</a>
    </div>
</div>
</body>
</html>
