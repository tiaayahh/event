<?php
require_once '../includes/auth.php';
require_once '../includes/csrf.php';
require_once '../includes/audit.php';
require_once '../includes/daraja.php';

checkAuth();
requireRole('attendee');

function ensureAttendeeTicketPaymentsTable(PDO $pdo): void {
    static $ready = false;
    if ($ready) {
        return;
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS attendee_ticket_payments (
            payment_id BIGINT AUTO_INCREMENT PRIMARY KEY,
            event_id INT NOT NULL,
            attendee_id INT NOT NULL,
            checkout_request_id VARCHAR(120) DEFAULT NULL,
            merchant_request_id VARCHAR(120) DEFAULT NULL,
            phone_number VARCHAR(20) DEFAULT NULL,
            mpesa_code VARCHAR(64) DEFAULT NULL,
            amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            status ENUM('requested', 'paid', 'failed') NOT NULL DEFAULT 'requested',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_attendee_ticket_payment (event_id, attendee_id),
            INDEX idx_attendee_ticket_payment_event_status (event_id, status),
            INDEX idx_attendee_ticket_payment_attendee (attendee_id),
            CONSTRAINT fk_attendee_ticket_payment_event FOREIGN KEY (event_id) REFERENCES events(event_id) ON DELETE CASCADE,
            CONSTRAINT fk_attendee_ticket_payment_attendee FOREIGN KEY (attendee_id) REFERENCES attendees(attendee_id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $ready = true;
}

$eventId = isset($_GET['event_id']) ? (int)$_GET['event_id'] : 0;
if ($eventId <= 0) {
    header('Location: explore.php');
    exit;
}

$attendeeUserId = (int)$_SESSION['user_id'];
$attendeeId = 0;
$error = '';
$success = '';
$event = null;
$payment = null;
$isRegistered = false;
$ticketAmount = 0.00;
$darajaConfigured = daraja_is_configured();

try {
    ensureAttendeeTicketPaymentsTable($pdo);

    $stmt = $pdo->prepare("SELECT event_id, title, event_date, ticket_price FROM events WHERE event_id = ? AND archived_at IS NULL");
    $stmt->execute([$eventId]);
    $event = $stmt->fetch();

    if (!$event) {
        throw new RuntimeException('Event not found.');
    }

    $ticketAmount = (float)($event['ticket_price'] ?? 0);
    if ($ticketAmount <= 0) {
        throw new RuntimeException('This event does not have a valid ticket amount.');
    }

    $stmt = $pdo->prepare('SELECT attendee_id FROM attendees WHERE user_id = ? LIMIT 1');
    $stmt->execute([$attendeeUserId]);
    $attendeeId = (int)($stmt->fetchColumn() ?: 0);
    if ($attendeeId <= 0) {
        throw new RuntimeException('Attendee profile not found. Please update your profile and try again.');
    }

    $stmt = $pdo->prepare("SELECT attendance_id FROM attendances WHERE event_id = ? AND attendee_id = ? LIMIT 1");
    $stmt->execute([$eventId, $attendeeId]);
    $isRegistered = (bool)$stmt->fetchColumn();

    $stmt = $pdo->prepare(
           "SELECT payment_id, checkout_request_id, merchant_request_id, phone_number, mpesa_code, amount, status, updated_at
            FROM attendee_ticket_payments
            WHERE event_id = ? AND attendee_id = ?
         LIMIT 1"
    );
    $stmt->execute([$eventId, $attendeeId]);
    $payment = $stmt->fetch() ?: null;

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
            throw new RuntimeException('Invalid CSRF token.');
        }

        $action = strtolower(trim((string)($_POST['action'] ?? '')));

        if ($action === 'stk_push') {
            $phoneNumber = trim((string)($_POST['phone_number'] ?? ''));
            $pushResult = daraja_stk_push(
                $phoneNumber,
                $ticketAmount,
                'ATT-' . $eventId . '-' . $attendeeId,
                'Planora attendee ticket payment'
            );

            if (empty($pushResult['success'])) {
                throw new RuntimeException((string)($pushResult['message'] ?? 'Unable to initiate M-Pesa STK push right now.'));
            }

            $stmt = $pdo->prepare(
                "INSERT INTO attendee_ticket_payments
                    (event_id, attendee_id, checkout_request_id, merchant_request_id, phone_number, amount, status)
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
                $attendeeId,
                (string)($pushResult['checkout_request_id'] ?? ''),
                (string)($pushResult['merchant_request_id'] ?? ''),
                $phoneNumber,
                $ticketAmount,
            ]);

            audit_log(
                $pdo,
                $attendeeUserId,
                'attendee',
                'attendee.ticket_stk_push_requested',
                'event',
                $eventId,
                [
                    'amount' => $ticketAmount,
                    'checkout_request_id' => (string)($pushResult['checkout_request_id'] ?? ''),
                ]
            );

            $success = 'M-Pesa STK push sent. Complete the prompt on your phone, then confirm payment.';
        } elseif ($action === 'confirm_paid') {
            $mpesaCode = strtoupper(trim((string)($_POST['mpesa_code'] ?? '')));
            if (!preg_match('/^[A-Z0-9]{6,20}$/', $mpesaCode)) {
                throw new RuntimeException('Enter a valid M-Pesa code (6-20 letters/numbers).');
            }

            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare(
                    "INSERT INTO attendee_ticket_payments
                        (event_id, attendee_id, mpesa_code, amount, status)
                     VALUES (?, ?, ?, ?, 'paid')
                     ON DUPLICATE KEY UPDATE
                        mpesa_code = VALUES(mpesa_code),
                        amount = VALUES(amount),
                        status = 'paid',
                        updated_at = CURRENT_TIMESTAMP"
                );
                $stmt->execute([$eventId, $attendeeId, $mpesaCode, $ticketAmount]);

                $stmt = $pdo->prepare("SELECT attendance_id FROM attendances WHERE event_id = ? AND attendee_id = ? LIMIT 1");
                $stmt->execute([$eventId, $attendeeId]);
                $attendanceExists = (bool)$stmt->fetchColumn();

                if (!$attendanceExists) {
                    $stmt = $pdo->prepare("INSERT INTO attendances (event_id, attendee_id) VALUES (?, ?)");
                    $stmt->execute([$eventId, $attendeeId]);

                    $stmt = $pdo->prepare("UPDATE events SET ticket_revenue = ticket_revenue + ? WHERE event_id = ?");
                    $stmt->execute([$ticketAmount, $eventId]);
                }

                audit_log(
                    $pdo,
                    $attendeeUserId,
                    'attendee',
                    'attendee.ticket_payment_confirmed',
                    'event',
                    $eventId,
                    [
                        'amount' => $ticketAmount,
                        'mpesa_code' => $mpesaCode,
                        'registered_now' => !$attendanceExists,
                    ]
                );

                $pdo->commit();
            } catch (Throwable $txe) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $txe;
            }

            $success = 'Ticket payment confirmed and registration completed.';
        }

        $stmt = $pdo->prepare("SELECT attendance_id FROM attendances WHERE event_id = ? AND attendee_id = ? LIMIT 1");
        $stmt->execute([$eventId, $attendeeId]);
        $isRegistered = (bool)$stmt->fetchColumn();

        $stmt = $pdo->prepare(
              "SELECT payment_id, checkout_request_id, merchant_request_id, phone_number, mpesa_code, amount, status, updated_at
               FROM attendee_ticket_payments
               WHERE event_id = ? AND attendee_id = ?
             LIMIT 1"
        );
        $stmt->execute([$eventId, $attendeeId]);
        $payment = $stmt->fetch() ?: null;
    }
} catch (Throwable $e) {
    if ($error === '') {
        $error = $e->getMessage();
    }
    error_log('Attendee register_event error: ' . $e->getMessage());
    if (isset($pdo) && $pdo instanceof PDO) {
        audit_log(
            $pdo,
            $attendeeUserId,
            'attendee',
            'attendee.ticket_payment_error',
            'event',
            $eventId > 0 ? $eventId : null,
            ['error' => $e->getMessage()]
        );
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ticket Payment - EventPro</title>
    <link rel="stylesheet" href="../assets/style.css">
</head>
<body>
    <div class="container" style="max-width: 720px; margin: 30px auto;">
        <h2>Ticket Payment (M-Pesa)</h2>

        <?php if ($error): ?>
            <div class="message error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="message success"><?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>

        <?php if (!$darajaConfigured): ?>
            <div class="message error">Daraja is not configured yet. Set DARAJA_CONSUMER_KEY, DARAJA_CONSUMER_SECRET, DARAJA_SHORTCODE, DARAJA_PASSKEY, and DARAJA_CALLBACK_URL.</div>
        <?php endif; ?>

        <?php if ($event): ?>
            <div class="event-card" style="padding: 20px; border-radius: 10px; margin-bottom: 16px;">
                <h3><?php echo htmlspecialchars($event['title']); ?></h3>
                <p><strong>Date:</strong> <?php echo htmlspecialchars($event['event_date']); ?></p>
                <p><strong>Ticket Amount:</strong> KES <?php echo number_format($ticketAmount, 2); ?></p>
                <p>
                    <strong>Registration:</strong>
                    <?php echo $isRegistered ? 'Confirmed' : 'Pending payment'; ?>
                </p>
                <p>
                    <strong>Payment Status:</strong>
                    <?php echo htmlspecialchars($payment['status'] ?? 'not started'); ?>
                </p>
                <?php if (!empty($payment['checkout_request_id'])): ?>
                    <p><strong>Checkout Request ID:</strong> <?php echo htmlspecialchars((string)$payment['checkout_request_id']); ?></p>
                <?php endif; ?>
                <?php if ($payment): ?>
                    <p><strong>M-Pesa Code:</strong> <?php echo htmlspecialchars($payment['mpesa_code'] ?? 'N/A'); ?></p>
                    <p><strong>Last Updated:</strong> <?php echo htmlspecialchars($payment['updated_at']); ?></p>
                <?php endif; ?>
            </div>

            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generate_csrf_token()); ?>">
                <input type="hidden" name="action" value="stk_push">

                <label for="phone_number">Phone Number (for STK Push)</label>
                <input
                    type="text"
                    id="phone_number"
                    name="phone_number"
                    maxlength="20"
                    placeholder="e.g. 0712345678"
                    required
                >

                <button type="submit" class="btn btn-primary" style="margin-top: 12px;">Send STK Push</button>
            </form>

            <form method="POST" style="margin-top: 12px;">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generate_csrf_token()); ?>">
                <input type="hidden" name="action" value="confirm_paid">

                <label for="mpesa_code">Confirm with M-Pesa Transaction Code</label>
                <input
                    type="text"
                    id="mpesa_code"
                    name="mpesa_code"
                    maxlength="20"
                    placeholder="e.g. QWE123RTY"
                    required
                >

                <button type="submit" class="btn btn-primary" style="margin-top: 12px;">Confirm Payment</button>
                <a class="btn" href="my_events.php" style="margin-left: 8px;">Back to My Events</a>
            </form>
        <?php endif; ?>
    </div>
</body>
</html>
