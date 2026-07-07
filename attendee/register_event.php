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

function ensureEventTicketsSchema(PDO $pdo): void {
    static $ready = false;
    if ($ready) {
        return;
    }

    $stmt = $pdo->query("SHOW COLUMNS FROM events LIKE 'tickets_available'");
    if (!$stmt->fetch()) {
        $pdo->exec("ALTER TABLE events ADD COLUMN tickets_available INT NOT NULL DEFAULT 200 AFTER ticket_price");
    }

    $ready = true;
}

function assertEventHasCapacity(PDO $pdo, int $eventId, int $attendeeId): void
{
    $stmt = $pdo->prepare(
    "SELECT COALESCE(e.tickets_available, 200) AS tickets_available,
        (SELECT COUNT(*) FROM attendances a WHERE a.event_id = e.event_id AND a.status IN ('registered', 'attended')) AS used_tickets,
        (SELECT COUNT(*) FROM attendances a WHERE a.event_id = e.event_id AND a.attendee_id = ? AND a.status IN ('registered', 'attended')) AS attendee_has_ticket
     FROM events e
     WHERE e.event_id = ?
     FOR UPDATE"
    );
    $stmt->execute([$attendeeId, $eventId]);
    $row = $stmt->fetch();

    if (!$row) {
        throw new RuntimeException('Event not found.');
    }

    $ticketsAvailable = max(0, (int)($row['tickets_available'] ?? 0));
    $usedTickets = max(0, (int)($row['used_tickets'] ?? 0));
    $attendeeHasTicket = (int)($row['attendee_has_ticket'] ?? 0) > 0;

    if (!$attendeeHasTicket && $usedTickets >= $ticketsAvailable) {
        throw new RuntimeException('Registration is closed. This event is sold out.');
    }
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
$ticketsAvailable = 0;
$registeredCount = 0;
$ticketsRemaining = 0;
$isFreeEvent = false;
$paymentStatus = 'not started';
$paymentStatusLabel = 'Pending';
$isPaymentComplete = false;
$registrationLabel = 'Pending payment';
$darajaConfigured = daraja_is_configured();
$darajaMissingFields = daraja_missing_required_fields();
$darajaStkConfigured = daraja_is_stk_configured();
$darajaMissingStkFields = daraja_missing_stk_fields();

function attendee_payment_status_label(string $rawStatus): string
{
    $status = strtolower(trim($rawStatus));
    if ($status === 'paid') {
        return 'Paid';
    }
    if ($status === 'failed') {
        return 'Failed';
    }

    return 'Pending';
}

try {
    ensureAttendeeTicketPaymentsTable($pdo);
    ensureEventTicketsSchema($pdo);

    $stmt = $pdo->prepare(
        "SELECT e.event_id, e.title, e.event_date,
                COALESCE(e.ticket_price, 0) AS ticket_price,
                COALESCE(e.tickets_available, 200) AS tickets_available,
                SUM(CASE WHEN a.status IN ('registered', 'attended') THEN 1 ELSE 0 END) AS registered_count
         FROM events e
         LEFT JOIN attendances a ON a.event_id = e.event_id
         WHERE e.event_id = ? AND e.archived_at IS NULL
         GROUP BY e.event_id, e.title, e.event_date, e.ticket_price, e.tickets_available"
    );
    $stmt->execute([$eventId]);
    $event = $stmt->fetch();

    if (!$event) {
        throw new RuntimeException('Event not found.');
    }

    $ticketAmount = (float)($event['ticket_price'] ?? 0);
    $isFreeEvent = $ticketAmount <= 0;
    $ticketsAvailable = max(0, (int)($event['tickets_available'] ?? 0));
    $registeredCount = max(0, (int)($event['registered_count'] ?? 0));
    $ticketsRemaining = max(0, $ticketsAvailable - $registeredCount);

    $stmt = $pdo->prepare('SELECT attendee_id FROM attendees WHERE user_id = ? LIMIT 1');
    $stmt->execute([$attendeeUserId]);
    $attendeeId = (int)($stmt->fetchColumn() ?: 0);
    if ($attendeeId <= 0) {
        throw new RuntimeException('Attendee profile not found. Please update your profile and try again.');
    }

    $stmt = $pdo->prepare("SELECT attendance_id FROM attendances WHERE event_id = ? AND attendee_id = ? LIMIT 1");
    $stmt->execute([$eventId, $attendeeId]);
    $isRegistered = (bool)$stmt->fetchColumn();

    if (!$isRegistered && $ticketsRemaining <= 0) {
        throw new RuntimeException('Registration is closed. This event is sold out.');
    }

    $stmt = $pdo->prepare(
           "SELECT payment_id, checkout_request_id, merchant_request_id, phone_number, mpesa_code, amount, status, updated_at
            FROM attendee_ticket_payments
            WHERE event_id = ? AND attendee_id = ?
         LIMIT 1"
    );
    $stmt->execute([$eventId, $attendeeId]);
    $payment = $stmt->fetch() ?: null;
    $paymentStatus = strtolower((string)($payment['status'] ?? 'not started'));
    $paymentStatusLabel = attendee_payment_status_label($paymentStatus);
    $isPaymentComplete = $isFreeEvent ? $isRegistered : ($paymentStatus === 'paid');
    $registrationLabel = $isPaymentComplete ? 'Confirmed' : 'Pending payment';

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
            throw new RuntimeException('Invalid CSRF token.');
        }

        $action = strtolower(trim((string)($_POST['action'] ?? '')));

        if ($action === 'stk_push') {
            if ($isFreeEvent) {
                throw new RuntimeException('This is a free event. Use free registration below.');
            }

            assertEventHasCapacity($pdo, $eventId, $attendeeId);

            if (!$darajaStkConfigured) {
                throw new RuntimeException('STK push is unavailable. Missing: ' . implode(', ', $darajaMissingStkFields) . '. Use manual payment confirmation below.');
            }

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

        } elseif ($action === 'register_free') {
            if (!$isFreeEvent) {
                throw new RuntimeException('Free registration is only available for free events.');
            }

            $pdo->beginTransaction();
            try {
                assertEventHasCapacity($pdo, $eventId, $attendeeId);

                $stmt = $pdo->prepare("SELECT attendance_id FROM attendances WHERE event_id = ? AND attendee_id = ? LIMIT 1");
                $stmt->execute([$eventId, $attendeeId]);
                $attendanceExists = (bool)$stmt->fetchColumn();

                if (!$attendanceExists) {
                    $stmt = $pdo->prepare("INSERT INTO attendances (event_id, attendee_id) VALUES (?, ?)");
                    $stmt->execute([$eventId, $attendeeId]);
                }

                $stmt = $pdo->prepare(
                    "INSERT INTO attendee_ticket_payments
                        (event_id, attendee_id, mpesa_code, amount, status)
                     VALUES (?, ?, ?, 0, 'paid')
                     ON DUPLICATE KEY UPDATE
                        mpesa_code = VALUES(mpesa_code),
                        amount = 0,
                        status = 'paid',
                        updated_at = CURRENT_TIMESTAMP"
                );
                $stmt->execute([$eventId, $attendeeId, 'FREE-' . $eventId . '-' . $attendeeId]);

                audit_log(
                    $pdo,
                    $attendeeUserId,
                    'attendee',
                    'attendee.free_registration_confirmed',
                    'event',
                    $eventId,
                    ['amount' => 0, 'registered_now' => !$attendanceExists]
                );

                $pdo->commit();
            } catch (Throwable $txe) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $txe;
            }

            $success = 'Free registration confirmed successfully.';

        } elseif ($action === 'confirm_paid') {
            if ($isFreeEvent) {
                throw new RuntimeException('This is a free event. Use free registration below.');
            }

            $mpesaCode = strtoupper(trim((string)($_POST['mpesa_code'] ?? '')));
            if (!preg_match('/^[A-Z0-9]{6,20}$/', $mpesaCode)) {
                throw new RuntimeException('Enter a valid M-Pesa code (6-20 letters/numbers).');
            }

            $wasPaid = strtolower((string)($payment['status'] ?? '')) === 'paid';

            $pdo->beginTransaction();
            try {
                assertEventHasCapacity($pdo, $eventId, $attendeeId);

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
                }

                if (!$wasPaid) {
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
            "SELECT COALESCE(e.tickets_available, 200) AS tickets_available,
                SUM(CASE WHEN a.status IN ('registered', 'attended') THEN 1 ELSE 0 END) AS registered_count
             FROM events e
             LEFT JOIN attendances a ON a.event_id = e.event_id
             WHERE e.event_id = ?
             GROUP BY e.event_id, e.tickets_available"
        );
        $stmt->execute([$eventId]);
        $capacityRow = $stmt->fetch() ?: [];
        $ticketsAvailable = max(0, (int)($capacityRow['tickets_available'] ?? $ticketsAvailable));
        $registeredCount = max(0, (int)($capacityRow['registered_count'] ?? $registeredCount));
        $ticketsRemaining = max(0, $ticketsAvailable - $registeredCount);

        $stmt = $pdo->prepare(
              "SELECT payment_id, checkout_request_id, merchant_request_id, phone_number, mpesa_code, amount, status, updated_at
               FROM attendee_ticket_payments
               WHERE event_id = ? AND attendee_id = ?
             LIMIT 1"
        );
        $stmt->execute([$eventId, $attendeeId]);
        $payment = $stmt->fetch() ?: null;
        $paymentStatus = strtolower((string)($payment['status'] ?? 'not started'));
        $paymentStatusLabel = attendee_payment_status_label($paymentStatus);
        $isPaymentComplete = $isFreeEvent ? $isRegistered : ($paymentStatus === 'paid');
        $registrationLabel = $isPaymentComplete ? 'Confirmed' : 'Pending payment';
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
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { background: #f4f5fb; color: #2d2d2d; padding-bottom: 76px; }
        .header { background: #6C63FF; color: #fff; padding: 14px 24px; display: flex; justify-content: space-between; align-items: center; }
        .brand { font-size: 22px; font-weight: 700; }
        .header-actions { display: flex; align-items: center; gap: 8px; }
        .dashboard-btn { background: rgba(255,255,255,.18); color: #fff; text-decoration: none; border-radius: 6px; padding: 7px 12px; font-size: 13px; }
        .logout-btn { background: rgba(255,255,255,.24); color: #fff; text-decoration: none; border-radius: 6px; padding: 7px 14px; font-size: 13px; }
        .payment-shell { max-width: 760px; margin: 20px auto 30px; padding: 0 14px; }
        .page-title { font-size: 24px; margin-bottom: 14px; color: #1f1d35; }
        .notice.ok { background: #ecfff0; color: #1c7a36; border: 1px solid #c9f0d4; }
        .notice.err { background: #ffecec; color: #9d2020; border: 1px solid #f6caca; }
        .notice {
            border-radius: 10px;
            padding: 11px 12px;
            margin-bottom: 12px;
            border: 1px solid #d9e4ff;
            background: #eef3ff;
            color: #2c4ea0;
            font-size: 13px;
        }
        .summary-card {
            background: #fff;
            border: 1px solid #ece9ff;
            border-radius: 12px;
            padding: 18px;
            margin-bottom: 14px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.04);
        }
        .summary-card h3 { font-size: 22px; margin-bottom: 10px; }
        .summary-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 8px 12px;
            font-size: 14px;
        }
        .chip {
            display: inline-block;
            padding: 4px 9px;
            border-radius: 999px;
            font-size: 11px;
            font-weight: 700;
        }
        .chip-ok { background: #e8f9ef; color: #1c7a36; }
        .chip-pending { background: #fff4df; color: #a36500; }
        .form-card {
            background: #fff;
            border: 1px solid #ece9ff;
            border-radius: 12px;
            padding: 16px;
            margin-bottom: 12px;
        }
        .form-title { font-size: 16px; font-weight: 700; margin-bottom: 10px; }
        label { display: block; font-size: 13px; font-weight: 600; margin-bottom: 6px; color: #444; }
        input[type="text"] {
            width: 100%;
            border: 1px solid #d8d4ff;
            border-radius: 10px;
            padding: 10px 12px;
            font-size: 14px;
            background: #fff;
        }
        .btn {
            border: none;
            border-radius: 10px;
            padding: 10px 13px;
            cursor: pointer;
            font-weight: 700;
            font-size: 13px;
            text-decoration: none;
            display: inline-block;
            background: #ece9ff;
            color: #3f379f;
        }
        .btn-primary { background: #6C63FF; color: #fff; }
        .form-actions { display: flex; align-items: center; gap: 8px; margin-top: 12px; flex-wrap: wrap; }
        .bottom-nav {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            height: 62px;
            background: #6C63FF;
            display: flex;
            z-index: 999;
        }
        .nav-item {
            flex: 1;
            color: rgba(255,255,255,.76);
            text-decoration: none;
            font-size: 11px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 4px;
        }
        .nav-item i { font-size: 15px; }
        .nav-item.active, .nav-item:hover { color: #fff; background: rgba(255,255,255,.1); }
        @media (max-width: 680px) {
            .summary-grid { grid-template-columns: 1fr; }
            .page-title { font-size: 21px; }
        }
    </style>
</head>
<body>
    <header class="header">
        <div class="brand">PLANORA</div>
        <div class="header-actions">
            <a class="dashboard-btn" href="dashboard.php">Back to Dashboard</a>
            <a class="logout-btn" href="../logout.php">Logout</a>
        </div>
    </header>

    <div class="payment-shell">
        <h2 class="page-title">Ticket Payment (M-Pesa)</h2>

        <?php if ($error): ?>
            <div class="notice err"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="notice ok"><?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>

        <?php if (!$darajaStkConfigured): ?>
            <div class="notice">STK push is unavailable (missing: <?php echo htmlspecialchars(implode(', ', $darajaMissingStkFields)); ?>). You can still complete payment by entering an M-Pesa code below.</div>
        <?php elseif (!$darajaConfigured): ?>
            <div class="notice err">Daraja is not fully configured. Missing: <?php echo htmlspecialchars(implode(', ', $darajaMissingFields)); ?>.</div>
        <?php endif; ?>

        <?php if ($event): ?>
            <div class="summary-card">
                <h3><?php echo htmlspecialchars($event['title']); ?></h3>
                <div class="summary-grid">
                    <p><strong>Date:</strong> <?php echo htmlspecialchars($event['event_date']); ?></p>
                    <p><strong>Ticket Amount:</strong> <?php echo $isFreeEvent ? 'Free' : ('KES ' . number_format($ticketAmount, 2)); ?></p>
                    <p><strong>Tickets Remaining:</strong> <?php echo (int)$ticketsRemaining; ?> / <?php echo (int)$ticketsAvailable; ?></p>
                    <p><strong>Registration:</strong> <span class="chip <?php echo $isPaymentComplete ? 'chip-ok' : 'chip-pending'; ?>"><?php echo htmlspecialchars($registrationLabel); ?></span></p>
                    <p><strong>Payment Status:</strong> <?php echo htmlspecialchars($paymentStatusLabel); ?></p>
                    <?php if (!empty($payment['checkout_request_id'])): ?>
                        <p><strong>Checkout Request ID:</strong> <?php echo htmlspecialchars((string)$payment['checkout_request_id']); ?></p>
                    <?php endif; ?>
                    <?php if ($payment): ?>
                        <p><strong>M-Pesa Code:</strong> <?php echo htmlspecialchars($payment['mpesa_code'] ?? 'N/A'); ?></p>
                        <p><strong>Last Updated:</strong> <?php echo htmlspecialchars($payment['updated_at']); ?></p>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($isFreeEvent): ?>
                <form method="POST" class="form-card">
                    <div class="form-title">Free Registration</div>
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generate_csrf_token()); ?>">
                    <input type="hidden" name="action" value="register_free">

                    <p style="font-size:13px; color:#555; margin-bottom:10px;">This event is free. Click below to reserve your slot.</p>

                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary">Confirm Free Registration</button>
                    </div>
                </form>
            <?php elseif ($darajaStkConfigured): ?>
                <form method="POST" class="form-card">
                    <div class="form-title">STK Push</div>
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

                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary">Send STK Push</button>
                    </div>
                </form>
            <?php endif; ?>

            <?php if (!$isFreeEvent): ?>
                <form method="POST" class="form-card">
                    <div class="form-title">Manual Payment Confirmation</div>
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

                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary">Confirm Payment</button>
                        <a class="btn" href="my_events.php">Back to My Events</a>
                    </div>
                </form>
            <?php endif; ?>
        <?php endif; ?>
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
