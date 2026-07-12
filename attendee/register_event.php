<?php
require_once '../includes/auth.php';
require_once '../includes/csrf.php';
require_once '../includes/audit.php';
require_once '../includes/daraja.php';
require_once '../includes/ticket_qr.php';

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
            ticket_type VARCHAR(32) NOT NULL DEFAULT 'regular',
            checkout_request_id VARCHAR(120) DEFAULT NULL,
            merchant_request_id VARCHAR(120) DEFAULT NULL,
            phone_number VARCHAR(20) DEFAULT NULL,
            mpesa_code VARCHAR(64) DEFAULT NULL,
            amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            status ENUM('requested', 'paid', 'failed') NOT NULL DEFAULT 'requested',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            checked_in_at TIMESTAMP NULL DEFAULT NULL,
            checkin_by_user_id INT NULL DEFAULT NULL,
            INDEX idx_attendee_ticket_payment_event_status (event_id, status),
            INDEX idx_attendee_ticket_payment_attendee (attendee_id),
            INDEX idx_attendee_ticket_payment_checkin (event_id, attendee_id, status, checked_in_at),
            CONSTRAINT fk_attendee_ticket_payment_event FOREIGN KEY (event_id) REFERENCES events(event_id) ON DELETE CASCADE,
            CONSTRAINT fk_attendee_ticket_payment_attendee FOREIGN KEY (attendee_id) REFERENCES attendees(attendee_id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $stmt = $pdo->query("SHOW COLUMNS FROM attendee_ticket_payments LIKE 'ticket_type'");
    if (!$stmt->fetch()) {
        $pdo->exec("ALTER TABLE attendee_ticket_payments ADD COLUMN ticket_type VARCHAR(32) NOT NULL DEFAULT 'regular' AFTER attendee_id");
    }

    try {
        $pdo->exec("CREATE INDEX idx_attendee_ticket_payment_checkout ON attendee_ticket_payments (checkout_request_id)");
    } catch (Throwable $e) {
        // Ignore when index already exists.
    }

    $stmt = $pdo->query("SHOW COLUMNS FROM attendee_ticket_payments LIKE 'checked_in_at'");
    if (!$stmt->fetch()) {
        $pdo->exec("ALTER TABLE attendee_ticket_payments ADD COLUMN checked_in_at TIMESTAMP NULL DEFAULT NULL AFTER updated_at");
    }

    $stmt = $pdo->query("SHOW COLUMNS FROM attendee_ticket_payments LIKE 'checkin_by_user_id'");
    if (!$stmt->fetch()) {
        $pdo->exec("ALTER TABLE attendee_ticket_payments ADD COLUMN checkin_by_user_id INT NULL DEFAULT NULL AFTER checked_in_at");
    }

    try {
        $pdo->exec("ALTER TABLE attendee_ticket_payments DROP INDEX uq_attendee_ticket_payment");
    } catch (Throwable $e) {
        // Ignore when unique key is absent.
    }

    try {
        $pdo->exec("CREATE INDEX idx_attendee_ticket_payment_event_attendee ON attendee_ticket_payments (event_id, attendee_id)");
    } catch (Throwable $e) {
        // Ignore when index already exists.
    }

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

function ensureEventTicketTypesSchema(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS event_ticket_types (
            ticket_type_id BIGINT AUTO_INCREMENT PRIMARY KEY,
            event_id INT NOT NULL,
            ticket_type VARCHAR(32) NOT NULL,
            price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            description VARCHAR(255) DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_event_ticket_type (event_id, ticket_type),
            CONSTRAINT fk_event_ticket_types_event FOREIGN KEY (event_id) REFERENCES events(event_id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $stmt = $pdo->query("SHOW COLUMNS FROM event_ticket_types LIKE 'description'");
    if (!$stmt->fetch()) {
        $pdo->exec("ALTER TABLE event_ticket_types ADD COLUMN description VARCHAR(255) DEFAULT NULL AFTER price");
    }

    $stmt = $pdo->query("SHOW COLUMNS FROM event_ticket_types LIKE 'tickets_remaining'");
    if (!$stmt->fetch()) {
        $pdo->exec("ALTER TABLE event_ticket_types ADD COLUMN tickets_remaining INT NOT NULL DEFAULT 0 AFTER description");
    }

    $ready = true;
}

function attendee_ticket_type_label(string $ticketType): string
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

function load_event_ticket_types(PDO $pdo, int $eventId, float $fallbackPrice, int $fallbackRemaining): array
{
    $stmt = $pdo->prepare(
        "SELECT ticket_type, price, description, COALESCE(tickets_remaining, 0) AS tickets_remaining
         FROM event_ticket_types
         WHERE event_id = ?
         ORDER BY FIELD(ticket_type, 'early_bird', 'regular', 'vip', 'vvip'), ticket_type"
    );
    $stmt->execute([$eventId]);

    $types = [];
    foreach ($stmt->fetchAll() as $row) {
        $key = strtolower(trim((string)($row['ticket_type'] ?? '')));
        if ($key === '') {
            continue;
        }

        $types[$key] = [
            'label' => attendee_ticket_type_label($key),
            'price' => (float)($row['price'] ?? 0),
            'description' => trim((string)($row['description'] ?? '')),
            'remaining' => max(0, (int)($row['tickets_remaining'] ?? 0)),
        ];
    }

    if (empty($types)) {
        $types['regular'] = [
            'label' => 'Regular',
            'price' => $fallbackPrice,
            'description' => '',
            'remaining' => max(0, $fallbackRemaining),
        ];
    } else {
        $remainingSum = 0;
        foreach ($types as $meta) {
            $remainingSum += max(0, (int)($meta['remaining'] ?? 0));
        }
        if ($remainingSum <= 0 && isset($types['regular'])) {
            $types['regular']['remaining'] = max(0, $fallbackRemaining);
        }
    }

    return $types;
}

function assertTicketTypeHasCapacity(PDO $pdo, int $eventId, int $attendeeId, string $ticketType, int $ticketUnits = 1): void
{
    $ticketUnits = max(1, (int)$ticketUnits);

    $stmt = $pdo->prepare(
    "SELECT COALESCE(ett.tickets_remaining, 0) AS tickets_remaining
     FROM event_ticket_types ett
     WHERE ett.event_id = ? AND ett.ticket_type = ?
     FOR UPDATE"
    );
    $stmt->execute([$eventId, $ticketType]);
    $row = $stmt->fetch();

    if (!$row) {
        throw new RuntimeException('Selected ticket type was not found for this event.');
    }

    $ticketsRemaining = max(0, (int)($row['tickets_remaining'] ?? 0));

    if ($ticketsRemaining < $ticketUnits) {
        throw new RuntimeException('Registration is closed for this ticket type. No tickets remain.');
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
$isAjaxRequest = strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';
$lastAction = '';
$lastCheckoutRequestId = '';
$event = null;
$payment = null;
$isRegistered = false;
$ticketAmount = 0.00;
$ticketTypeOptions = [];
$selectedTicketType = strtolower(trim((string)($_GET['ticket_type'] ?? '')));
$selectedTicketsRemaining = 0;
$ticketsAvailable = 0;
$registeredCount = 0;
$ticketsRemaining = 0;
$isFreeEvent = false;
$paymentStatus = 'not started';
$paymentStatusLabel = 'Not paid';
$isPaymentComplete = false;
$registrationLabel = 'Not paid';
$paidTickets = [];
$hasPaidTickets = false;
$paidTicketUnitsDelta = 0;
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

    return 'Not paid';
}

try {
    ensureAttendeeTicketPaymentsTable($pdo);
    ensureEventTicketsSchema($pdo);
    ensureEventTicketTypesSchema($pdo);

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

    $ticketTypeOptions = load_event_ticket_types(
        $pdo,
        $eventId,
        (float)($event['ticket_price'] ?? 0),
        max(0, (int)($event['tickets_available'] ?? 0))
    );
    if (!isset($ticketTypeOptions[$selectedTicketType])) {
        $selectedTicketType = '';
    }

    if ($selectedTicketType !== '') {
        $ticketAmount = (float)($ticketTypeOptions[$selectedTicketType]['price'] ?? 0);
            $paymentStatus = strtolower((string)($payment['status'] ?? 'not started'));
            $paymentStatusLabel = attendee_payment_status_label($paymentStatus);
            $isPaymentComplete = $isFreeEvent ? $isRegistered : ($paymentStatus === 'paid');
            if ($isPaymentComplete) {
                $registrationLabel = 'Confirmed';
            } elseif ($paymentStatus === 'requested') {
                $registrationLabel = 'Pending confirmation';
            } else {
                $registrationLabel = 'Not paid';
            }
        $isFreeEvent = false;
        $selectedTicketsRemaining = 0;
    }
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

        $stmt = $pdo->prepare(
                            "SELECT payment_id, ticket_type, checkout_request_id, merchant_request_id, phone_number, mpesa_code, amount, status, updated_at
                        FROM attendee_ticket_payments
                        WHERE event_id = ? AND attendee_id = ?
                        ORDER BY payment_id DESC
                 LIMIT 1"
        );
    $stmt->execute([$eventId, $attendeeId]);
    $payment = $stmt->fetch() ?: null;
    if (!empty($payment['ticket_type'])) {
        $storedTicketType = strtolower((string)$payment['ticket_type']);
        if (isset($ticketTypeOptions[$storedTicketType])) {
            $selectedTicketType = $storedTicketType;
            $ticketAmount = (float)($ticketTypeOptions[$selectedTicketType]['price'] ?? $ticketAmount);
            $isFreeEvent = $ticketAmount <= 0;
            $selectedTicketsRemaining = max(0, (int)($ticketTypeOptions[$selectedTicketType]['remaining'] ?? 0));
        }
    }

    $paymentStatus = strtolower((string)($payment['status'] ?? 'not started'));
    $paymentStatusLabel = attendee_payment_status_label($paymentStatus);
    $isPaymentComplete = $isFreeEvent ? $isRegistered : ($paymentStatus === 'paid');
    if ($isPaymentComplete) {
        $registrationLabel = 'Confirmed';
    } elseif ($paymentStatus === 'requested') {
        $registrationLabel = 'Pending confirmation';
    } else {
        $registrationLabel = 'Not paid';
    }

    $stmt = $pdo->prepare(
        "SELECT payment_id, ticket_type, mpesa_code, amount, status, updated_at, checked_in_at
         FROM attendee_ticket_payments
         WHERE event_id = ? AND attendee_id = ? AND status = 'paid'
         ORDER BY payment_id DESC"
    );
    $stmt->execute([$eventId, $attendeeId]);
    $paidTickets = $stmt->fetchAll();
    $hasPaidTickets = count($paidTickets) > 0;
    $isPaymentComplete = $isFreeEvent ? $isRegistered : $hasPaidTickets;
    if ($isPaymentComplete) {
        $registrationLabel = 'Confirmed';
    } elseif ($paymentStatus === 'requested') {
        $registrationLabel = 'Pending confirmation';
    } else {
        $registrationLabel = 'Not paid';
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
            throw new RuntimeException('Invalid CSRF token.');
        }

        $action = strtolower(trim((string)($_POST['action'] ?? '')));
        $lastAction = $action;
        $postedTicketType = strtolower(trim((string)($_POST['ticket_type'] ?? $selectedTicketType)));
        if ($postedTicketType === '' || !isset($ticketTypeOptions[$postedTicketType])) {
            throw new RuntimeException('Please choose a valid ticket type.');
        }

        $selectedTicketType = $postedTicketType;
        $ticketAmount = (float)($ticketTypeOptions[$selectedTicketType]['price'] ?? 0);
        $isFreeEvent = $ticketAmount <= 0;
        $selectedTicketsRemaining = max(0, (int)($ticketTypeOptions[$selectedTicketType]['remaining'] ?? 0));

        if ($action === 'stk_push') {
            if ($isFreeEvent) {
                throw new RuntimeException('This is a free event. Use free registration below.');
            }

            if (!$darajaStkConfigured) {
                throw new RuntimeException('Mpesa prompt is unavailable. Missing: ' . implode(', ', $darajaMissingStkFields) . '.');
            }

            $phoneNumber = trim((string)($_POST['phone_number'] ?? ''));
            $chargeAmount = daraja_effective_stk_amount($ticketAmount);
            $ticketUnits = max(1, (int)round($chargeAmount));
            assertTicketTypeHasCapacity($pdo, $eventId, $attendeeId, $selectedTicketType, $ticketUnits);

            $pushResult = daraja_stk_push(
                $phoneNumber,
                $chargeAmount,
                'ATT-' . $eventId . '-' . $attendeeId,
                'Planora attendee ticket payment'
            );

            if (empty($pushResult['success'])) {
                throw new RuntimeException((string)($pushResult['message'] ?? 'Unable to initiate Mpesa prompt right now.'));
            }

            $lastCheckoutRequestId = trim((string)($pushResult['checkout_request_id'] ?? ''));
            $merchantRequestId = trim((string)($pushResult['merchant_request_id'] ?? ''));

            $pdo->beginTransaction();
            try {
                assertTicketTypeHasCapacity($pdo, $eventId, $attendeeId, $selectedTicketType, $ticketUnits);

                $stmt = $pdo->prepare("SELECT attendance_id FROM attendances WHERE event_id = ? AND attendee_id = ? LIMIT 1 FOR UPDATE");
                $stmt->execute([$eventId, $attendeeId]);
                $attendanceExists = (bool)$stmt->fetchColumn();

                if (!$attendanceExists) {
                    $stmt = $pdo->prepare("INSERT INTO attendances (event_id, attendee_id) VALUES (?, ?)");
                    $stmt->execute([$eventId, $attendeeId]);
                }

                $paymentInsert = $pdo->prepare(
                    "INSERT INTO attendee_ticket_payments
                              (event_id, attendee_id, ticket_type, checkout_request_id, merchant_request_id, phone_number, mpesa_code, amount, status)
                          VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'paid')"
                );

                for ($i = 0; $i < $ticketUnits; $i++) {
                    $paymentInsert->execute([
                        $eventId,
                        $attendeeId,
                        $selectedTicketType,
                        $lastCheckoutRequestId,
                        $merchantRequestId,
                        $phoneNumber,
                        $lastCheckoutRequestId !== '' ? ('PROMPT-' . $lastCheckoutRequestId) : 'PROMPT-PAID',
                        $ticketAmount,
                    ]);
                }

                $stmt = $pdo->prepare(
                    "UPDATE event_ticket_types
                     SET tickets_remaining = GREATEST(0, tickets_remaining - ?)
                     WHERE event_id = ? AND ticket_type = ?
                     LIMIT 1"
                );
                $stmt->execute([$ticketUnits, $eventId, $selectedTicketType]);

                $stmt = $pdo->prepare("UPDATE events SET tickets_available = GREATEST(0, tickets_available - ?) WHERE event_id = ?");
                $stmt->execute([$ticketUnits, $eventId]);

                if ($ticketAmount > 0) {
                    $stmt = $pdo->prepare("UPDATE events SET ticket_revenue = ticket_revenue + ? WHERE event_id = ?");
                    $stmt->execute([$ticketAmount * $ticketUnits, $eventId]);
                }

                $pdo->commit();
            } catch (Throwable $txe) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $txe;
            }

            $paidTicketUnitsDelta = $ticketUnits;

            audit_log(
                $pdo,
                $attendeeUserId,
                'attendee',
                'attendee.ticket_stk_push_requested',
                'event',
                $eventId,
                [
                    'amount' => $ticketAmount,
                    'charged_amount' => $chargeAmount,
                    'ticket_units' => $ticketUnits,
                    'ticket_type' => $selectedTicketType,
                    'checkout_request_id' => (string)($pushResult['checkout_request_id'] ?? ''),
                ]
            );

            $success = sprintf(
                'Mpesa prompt accepted. %d ticket(s) marked as paid immediately (prompt charge KES %s).',
                $ticketUnits,
                number_format($chargeAmount, 2),
                $chargeAmount !== $ticketAmount ? ' in demo mode' : ''
            );

        } elseif ($action === 'register_free') {
            if (!$isFreeEvent) {
                throw new RuntimeException('Free registration is only available for free events.');
            }

            $pdo->beginTransaction();
            try {
                assertTicketTypeHasCapacity($pdo, $eventId, $attendeeId, $selectedTicketType);

                $stmt = $pdo->prepare("SELECT attendance_id FROM attendances WHERE event_id = ? AND attendee_id = ? LIMIT 1");
                $stmt->execute([$eventId, $attendeeId]);
                $attendanceExists = (bool)$stmt->fetchColumn();

                if (!$attendanceExists) {
                    $stmt = $pdo->prepare("INSERT INTO attendances (event_id, attendee_id) VALUES (?, ?)");
                    $stmt->execute([$eventId, $attendeeId]);

                    $stmt = $pdo->prepare("UPDATE event_ticket_types SET tickets_remaining = GREATEST(0, tickets_remaining - 1) WHERE event_id = ? AND ticket_type = ? LIMIT 1");
                    $stmt->execute([$eventId, $selectedTicketType]);

                    $stmt = $pdo->prepare("UPDATE events SET tickets_available = GREATEST(0, tickets_available - 1) WHERE event_id = ?");
                    $stmt->execute([$eventId]);
                }

                $stmt = $pdo->prepare(
                    "INSERT INTO attendee_ticket_payments
                                (event_id, attendee_id, ticket_type, mpesa_code, amount, status)
                            VALUES (?, ?, ?, ?, 0, 'paid')"
                );
                     $stmt->execute([$eventId, $attendeeId, $selectedTicketType, 'FREE-' . $eventId . '-' . $attendeeId . '-' . (string)time()]);

                audit_log(
                    $pdo,
                    $attendeeUserId,
                    'attendee',
                    'attendee.free_registration_confirmed',
                    'event',
                    $eventId,
                    ['amount' => 0, 'ticket_type' => $selectedTicketType, 'registered_now' => !$attendanceExists]
                );

                $pdo->commit();
            } catch (Throwable $txe) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $txe;
            }

            $paidTicketUnitsDelta = 1;

            $success = 'Free registration confirmed successfully.';

        } else {
            throw new RuntimeException('Unsupported payment action.');
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
                            "SELECT payment_id, ticket_type, checkout_request_id, merchant_request_id, phone_number, mpesa_code, amount, status, updated_at
                             FROM attendee_ticket_payments
                             WHERE event_id = ? AND attendee_id = ?
                             ORDER BY payment_id DESC
                         LIMIT 1"
                );
        $stmt->execute([$eventId, $attendeeId]);
        $payment = $stmt->fetch() ?: null;
        if (!empty($payment['ticket_type'])) {
            $storedTicketType = strtolower((string)$payment['ticket_type']);
            if (isset($ticketTypeOptions[$storedTicketType])) {
                $selectedTicketType = $storedTicketType;
                $ticketAmount = (float)($ticketTypeOptions[$selectedTicketType]['price'] ?? $ticketAmount);
                $isFreeEvent = $ticketAmount <= 0;
                $selectedTicketsRemaining = max(0, (int)($ticketTypeOptions[$selectedTicketType]['remaining'] ?? 0));
            }
        }
        $paymentStatus = strtolower((string)($payment['status'] ?? 'not started'));
        $paymentStatusLabel = attendee_payment_status_label($paymentStatus);
        $isPaymentComplete = $isFreeEvent ? $isRegistered : ($paymentStatus === 'paid');
        if ($isPaymentComplete) {
            $registrationLabel = 'Confirmed';
        } elseif ($paymentStatus === 'requested') {
            $registrationLabel = 'Pending confirmation';
        } else {
            $registrationLabel = 'Not paid';
        }

        $stmt = $pdo->prepare(
            "SELECT payment_id, ticket_type, mpesa_code, amount, status, updated_at, checked_in_at
             FROM attendee_ticket_payments
             WHERE event_id = ? AND attendee_id = ? AND status = 'paid'
             ORDER BY payment_id DESC"
        );
        $stmt->execute([$eventId, $attendeeId]);
        $paidTickets = $stmt->fetchAll();
        $hasPaidTickets = count($paidTickets) > 0;
        $isPaymentComplete = $isFreeEvent ? $isRegistered : $hasPaidTickets;
        if ($isPaymentComplete) {
            $registrationLabel = 'Confirmed';
        } elseif ($paymentStatus === 'requested') {
            $registrationLabel = 'Pending confirmation';
        } else {
            $registrationLabel = 'Not paid';
        }
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

if ($isAjaxRequest && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $response = [
        'ok' => $error === '',
        'message' => $error !== '' ? $error : ($success !== '' ? $success : 'Request processed.'),
        'action' => $lastAction,
        'checkout_request_id' => $lastCheckoutRequestId,
        'paid_ticket_units' => $paidTicketUnitsDelta,
        'event_id' => $eventId,
        'payment' => [
            'status' => strtolower((string)($payment['status'] ?? 'not started')),
            'checkout_request_id' => (string)($payment['checkout_request_id'] ?? ''),
            'mpesa_code' => (string)($payment['mpesa_code'] ?? ''),
            'updated_at' => (string)($payment['updated_at'] ?? ''),
        ],
    ];

    header('Content-Type: application/json');
    echo json_encode($response, JSON_UNESCAPED_SLASHES);
    exit;
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
        .ticket-type-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 10px;
            margin-top: 12px;
        }
        .ticket-type-card {
            position: relative;
            border: 1px solid #dad6ff;
            border-radius: 12px;
            padding: 12px;
            background: #fbfaff;
            cursor: pointer;
            transition: border-color .15s ease, box-shadow .15s ease, transform .15s ease;
        }
        .ticket-type-card:hover {
            border-color: #6C63FF;
            box-shadow: 0 6px 18px rgba(108, 99, 255, 0.14);
            transform: translateY(-1px);
        }
        .ticket-type-card.active {
            border-color: #6C63FF;
            background: #f2f0ff;
            box-shadow: 0 6px 18px rgba(108, 99, 255, 0.16);
        }
        .ticket-type-card.sold-out {
            opacity: 0.55;
            cursor: not-allowed;
            border-color: #d2d2d2;
            background: #f7f7f7;
            box-shadow: none;
        }
        .ticket-type-card.sold-out:hover {
            transform: none;
            border-color: #d2d2d2;
            box-shadow: none;
        }
        .ticket-type-card input[type="radio"] {
            position: absolute;
            opacity: 0;
            pointer-events: none;
        }
        .ticket-type-name { font-size: 14px; font-weight: 700; color: #2a2463; }
        .ticket-type-price { font-size: 13px; margin-top: 4px; color: #403aa8; font-weight: 700; }
        .ticket-type-desc { font-size: 12px; margin-top: 6px; color: #666; min-height: 16px; }
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
        .ticket-card {
            margin-top: 10px;
            background: linear-gradient(135deg, #6C63FF 0%, #4338ca 100%);
            color: #fff;
            border-radius: 12px;
            padding: 12px;
            border: 1px solid rgba(255,255,255,.2);
        }
        .ticket-card h4 { font-size: 15px; margin-bottom: 7px; }
        .ticket-row { display: flex; gap: 12px; align-items: stretch; }
        .ticket-info { flex: 1; min-width: 0; }
        .ticket-qr-wrap { width: 185px; max-width: 100%; }
        .ticket-collection { margin-top: 14px; }
        .ticket-collection-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(230px, 1fr)); gap: 10px; }
        .ticket-meta { font-size: 11px; opacity: .95; margin-bottom: 4px; }
        .qr-box {
            margin-top: 10px;
            border-radius: 10px;
            padding: 10px;
            text-align: center;
            font-weight: 700;
            color: #1f1d35;
            background: repeating-linear-gradient(45deg, #fff, #fff 4px, #e6e6ff 4px, #e6e6ff 8px);
        }
        .ticket-qr-image {
            width: 120px;
            max-width: 100%;
            height: auto;
            border-radius: 8px;
            border: 1px solid #dcd8ff;
            background: #fff;
            padding: 8px;
        }
        .ticket-qr-actions {
            display: flex;
            justify-content: center;
            gap: 8px;
            margin-top: 8px;
            flex-wrap: wrap;
        }
        .btn-qr-download {
            background: #ffffff;
            color: #2f2a86;
            border: 1px solid #d6d1ff;
            padding: 7px 10px;
            font-size: 12px;
            border-radius: 8px;
            text-decoration: none;
            display: inline-block;
        }
        .hidden { display: none; }
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
            .ticket-row { flex-direction: column; }
            .ticket-qr-wrap { width: 100%; }
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
            <div class="notice">Mpesa prompt is unavailable (missing: <?php echo htmlspecialchars(implode(', ', $darajaMissingStkFields)); ?>). Please contact support to complete payment.</div>
        <?php elseif (!$darajaConfigured): ?>
            <div class="notice err">Daraja is not fully configured. Missing: <?php echo htmlspecialchars(implode(', ', $darajaMissingFields)); ?>.</div>
        <?php endif; ?>

        <?php if ($event): ?>
            <div class="summary-card">
                <h3><?php echo htmlspecialchars($event['title']); ?></h3>
                <div class="summary-grid" id="payment_summary" data-event-id="<?php echo (int)$event['event_id']; ?>" data-checkout-id="<?php echo htmlspecialchars((string)($payment['checkout_request_id'] ?? '')); ?>" data-payment-status="<?php echo htmlspecialchars((string)$paymentStatus); ?>" data-paid-count="<?php echo (int)count($paidTickets); ?>">
                    <p><strong>Date:</strong> <?php echo htmlspecialchars($event['event_date']); ?></p>
                    <p><strong>Ticket Type:</strong> <span id="selected_ticket_type_label"><?php echo $selectedTicketType !== '' ? htmlspecialchars(attendee_ticket_type_label($selectedTicketType)) : 'Select a ticket type'; ?></span></p>
                    <p><strong>Ticket Amount:</strong> <span id="selected_ticket_amount_label"><?php echo $selectedTicketType === '' ? '--' : ($isFreeEvent ? 'Free' : ('KES ' . number_format($ticketAmount, 2))); ?></span></p>
                    <p><strong>Tickets Remaining:</strong> <span id="selected_ticket_remaining_label"><?php echo $selectedTicketType === '' ? '--' : (string)$selectedTicketsRemaining; ?></span></p>
                    <p><strong>Registration:</strong> <span id="registration_chip" class="chip <?php echo $isPaymentComplete ? 'chip-ok' : 'chip-pending'; ?>"><?php echo htmlspecialchars($registrationLabel); ?></span></p>
                    <p><strong>Paid Tickets:</strong> <span id="paid_tickets_count"><?php echo count($paidTickets); ?></span></p>
                    <?php if (!empty($payment['checkout_request_id'])): ?>
                        <p><strong>Checkout Request ID:</strong> <?php echo htmlspecialchars((string)$payment['checkout_request_id']); ?></p>
                    <?php endif; ?>
                    <?php if ($payment): ?>
                        <p id="mpesa_code_row"><strong>M-Pesa Code:</strong> <span id="mpesa_code_value"><?php echo htmlspecialchars($payment['mpesa_code'] ?? 'N/A'); ?></span></p>
                        <p id="payment_updated_row"><strong>Last Updated:</strong> <span id="payment_updated_value"><?php echo htmlspecialchars($payment['updated_at']); ?></span></p>
                    <?php else: ?>
                        <p id="mpesa_code_row" style="display:none;"><strong>M-Pesa Code:</strong> <span id="mpesa_code_value"></span></p>
                        <p id="payment_updated_row" style="display:none;"><strong>Last Updated:</strong> <span id="payment_updated_value"></span></p>
                    <?php endif; ?>
                </div>

                <?php
                    $activePaymentId = (int)($payment['payment_id'] ?? 0);
                    $ticketCode = ticket_qr_build_code((int)$event['event_id'], (int)$attendeeId, (string)$event['event_date'], $activePaymentId);
                    $ticketToken = ticket_qr_build_payload_token((int)$event['event_id'], (int)$attendeeId, (string)$event['event_date'], $activePaymentId);
                    $ticketQrDataUri = ticket_qr_render_data_uri($ticketToken);
                    $ticketQrExt = str_starts_with($ticketQrDataUri, 'data:image/svg+xml') ? 'svg' : 'png';
                    $isTicketActive = $isPaymentComplete;
                ?>
                <div id="digital_ticket_card" class="ticket-card <?php echo $isTicketActive ? '' : 'hidden'; ?>">
                    <h4>Digital Ticket</h4>
                    <div class="ticket-row">
                        <div class="ticket-info">
                            <div class="ticket-meta">Event: <?php echo htmlspecialchars((string)$event['title']); ?></div>
                            <div class="ticket-meta">Date: <?php echo htmlspecialchars((string)$event['event_date']); ?></div>
                            <div class="ticket-meta">Tier: <span id="ticket_tier_value"><?php echo $selectedTicketType !== '' ? htmlspecialchars(attendee_ticket_type_label($selectedTicketType)) : 'Regular'; ?></span></div>
                            <div class="ticket-meta">Payment: <span id="ticket_payment_label">Paid</span></div>
                            <div class="ticket-meta" id="ticket_mpesa_row" <?php echo !empty($payment['mpesa_code']) ? '' : 'style="display:none;"'; ?>>M-Pesa Code: <span id="ticket_mpesa_value"><?php echo htmlspecialchars((string)($payment['mpesa_code'] ?? '')); ?></span></div>
                        </div>
                        <div class="ticket-qr-wrap">
                            <div class="qr-box">
                                <?php if ($ticketQrDataUri !== ''): ?>
                                    <img src="<?php echo htmlspecialchars($ticketQrDataUri); ?>" alt="Ticket QR" class="ticket-qr-image">
                                <?php else: ?>
                                    QR unavailable right now
                                <?php endif; ?>
                                <div style="margin-top:8px;">Ticket Code: <span id="digital_ticket_code"><?php echo htmlspecialchars($ticketCode); ?></span></div>
                                <div style="font-size:11px; opacity:.82; margin-top:4px;">Show this QR at check-in</div>
                                <?php if ($ticketQrDataUri !== ''): ?>
                                    <div class="ticket-qr-actions">
                                        <a class="btn-qr-download" href="<?php echo htmlspecialchars($ticketQrDataUri); ?>" download="ticket-qr-<?php echo (int)$activePaymentId; ?>.<?php echo htmlspecialchars($ticketQrExt); ?>">Download QR</a>
                                        <a class="btn-qr-download" href="download_ticket.php?event_id=<?php echo (int)$event['event_id']; ?>&payment_id=<?php echo (int)$activePaymentId; ?>">Download Ticket</a>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <?php if (!empty($paidTickets)): ?>
                    <div class="ticket-collection">
                        <h4 style="margin-top:12px; margin-bottom:8px; color:#1f1d35;">Your Digital Tickets (<?php echo count($paidTickets); ?>)</h4>
                        <div class="ticket-collection-grid">
                            <?php foreach ($paidTickets as $paidTicket): ?>
                                <?php $paidPaymentId = (int)($paidTicket['payment_id'] ?? 0); ?>
                                <?php $paidTicketCode = ticket_qr_build_code((int)$event['event_id'], (int)$attendeeId, (string)$event['event_date'], $paidPaymentId); ?>
                                <?php $paidTicketToken = ticket_qr_build_payload_token((int)$event['event_id'], (int)$attendeeId, (string)$event['event_date'], $paidPaymentId); ?>
                                <?php $paidTicketQrDataUri = ticket_qr_render_data_uri($paidTicketToken); ?>
                                <?php $paidTicketQrExt = str_starts_with($paidTicketQrDataUri, 'data:image/svg+xml') ? 'svg' : 'png'; ?>
                                <div class="ticket-card" style="margin-top:0;">
                                    <h4 style="margin-bottom:6px;">Digital Ticket #<?php echo $paidPaymentId; ?></h4>
                                    <div class="ticket-meta">Tier: <?php echo htmlspecialchars(attendee_ticket_type_label((string)($paidTicket['ticket_type'] ?? 'regular'))); ?></div>
                                    <div class="ticket-meta">Amount: <?php echo ((float)($paidTicket['amount'] ?? 0) > 0) ? ('KES ' . number_format((float)$paidTicket['amount'], 2)) : 'Free'; ?></div>
                                    <div class="ticket-meta">M-Pesa: <?php echo htmlspecialchars((string)($paidTicket['mpesa_code'] ?? 'N/A')); ?></div>
                                    <div class="ticket-meta">Paid At: <?php echo htmlspecialchars((string)($paidTicket['updated_at'] ?? '')); ?></div>
                                    <div class="ticket-meta">Status: <?php echo !empty($paidTicket['checked_in_at']) ? 'Checked in' : 'Valid'; ?></div>
                                    <div class="qr-box">
                                        <?php if ($paidTicketQrDataUri !== ''): ?>
                                            <img src="<?php echo htmlspecialchars($paidTicketQrDataUri); ?>" alt="Ticket QR" class="ticket-qr-image">
                                        <?php else: ?>
                                            QR unavailable right now
                                        <?php endif; ?>
                                        <div style="margin-top:8px;">Ticket Code: <?php echo htmlspecialchars($paidTicketCode); ?></div>
                                        <?php if ($paidTicketQrDataUri !== ''): ?>
                                            <div class="ticket-qr-actions">
                                                <a class="btn-qr-download" href="<?php echo htmlspecialchars($paidTicketQrDataUri); ?>" download="ticket-qr-<?php echo (int)$paidPaymentId; ?>.<?php echo htmlspecialchars($paidTicketQrExt); ?>">Download QR</a>
                                                <a class="btn-qr-download" href="download_ticket.php?event_id=<?php echo (int)$event['event_id']; ?>&payment_id=<?php echo (int)$paidPaymentId; ?>">Download Ticket</a>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <div style="margin-top:12px;">
                    <div style="font-size:13px; color:#555; margin-bottom:6px;"><strong>Select Ticket Type</strong></div>
                    <div class="ticket-type-grid" id="ticket_type_selector">
                        <?php foreach ($ticketTypeOptions as $typeKey => $typeMeta): ?>
                            <?php $typeRemaining = max(0, (int)($typeMeta['remaining'] ?? 0)); ?>
                            <label class="ticket-type-card <?php echo $selectedTicketType === $typeKey ? 'active' : ''; ?> <?php echo $typeRemaining <= 0 ? 'sold-out' : ''; ?>" data-ticket-type="<?php echo htmlspecialchars($typeKey); ?>" data-sold-out="<?php echo $typeRemaining <= 0 ? '1' : '0'; ?>">
                                <input
                                    type="radio"
                                    name="ticket_type_choice"
                                    value="<?php echo htmlspecialchars($typeKey); ?>"
                                    <?php echo $typeRemaining <= 0 ? 'disabled' : ''; ?>
                                    <?php echo $selectedTicketType === $typeKey ? 'checked' : ''; ?>
                                >
                                <div class="ticket-type-name"><?php echo htmlspecialchars((string)$typeMeta['label']); ?></div>
                                <div class="ticket-type-price"><?php echo (float)$typeMeta['price'] <= 0 ? 'Free' : ('KES ' . number_format((float)$typeMeta['price'], 2)); ?></div>
                                <div class="ticket-type-desc"><?php echo htmlspecialchars((string)$typeMeta['description']); ?></div>
                                <?php if ($typeRemaining <= 0): ?>
                                    <div class="ticket-type-desc" style="color:#9d2020; font-weight:700;">Sold Out</div>
                                <?php endif; ?>
                            </label>
                        <?php endforeach; ?>
                    </div>

                    <div style="font-size:12px; color:#666; margin-top:8px;">Choose one ticket type, then proceed to pay via M-Pesa.</div>
                    <div id="sold_out_notice" class="notice err hidden" style="margin-top:8px;">Selected ticket type is sold out. Choose another ticket type to continue.</div>
                </div>
            </div>

            <div id="free_registration_wrapper" class="<?php echo ($selectedTicketType !== '' && $isFreeEvent) ? '' : 'hidden'; ?>">
                <form method="POST" class="form-card">
                    <div class="form-title">Free Registration</div>
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generate_csrf_token()); ?>">
                    <input type="hidden" name="action" value="register_free">
                    <input type="hidden" name="ticket_type" class="js-ticket-type-input" value="<?php echo htmlspecialchars($selectedTicketType); ?>">

                    <p style="font-size:13px; color:#555; margin-bottom:10px;">This event is free. Click below to reserve your slot.</p>

                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary">Confirm Free Registration</button>
                    </div>
                </form>
            </div>

            <?php if ($darajaStkConfigured): ?>
                <div id="pay_forms_wrapper" class="<?php echo ($selectedTicketType !== '' && !$isFreeEvent) ? '' : 'hidden'; ?>">
                <form method="POST" class="form-card" id="stk_payment_form">
                    <div class="form-title">Mpesa Payment</div>
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generate_csrf_token()); ?>">
                    <input type="hidden" name="action" value="stk_push">
                    <input type="hidden" name="ticket_type" class="js-ticket-type-input" value="<?php echo htmlspecialchars($selectedTicketType); ?>">

                    <label for="phone_number">Phone Number </label>
                    <input
                        type="text"
                        id="phone_number"
                        name="phone_number"
                        maxlength="20"
                        placeholder="e.g. 0712345678"
                        required
                    >

                    <div class="form-actions">
                        <button type="submit" id="stk_pay_btn" class="btn btn-primary">Confirm Payment via M-Pesa (KES <?php echo number_format($ticketAmount, 2); ?>)</button>
                    </div>
                </form>
                </div>
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

    <?php if ($event): ?>
        <script>
            (function () {
                const ticketData = <?php echo json_encode($ticketTypeOptions, JSON_UNESCAPED_SLASHES); ?>;
                const cards = Array.from(document.querySelectorAll('.ticket-type-card'));
                const radios = Array.from(document.querySelectorAll('input[name="ticket_type_choice"]'));
                const typeInputs = Array.from(document.querySelectorAll('.js-ticket-type-input'));
                const ticketTypeLabel = document.getElementById('selected_ticket_type_label');
                const ticketAmountLabel = document.getElementById('selected_ticket_amount_label');
                const ticketRemainingLabel = document.getElementById('selected_ticket_remaining_label');
                const freeWrap = document.getElementById('free_registration_wrapper');
                const payWrap = document.getElementById('pay_forms_wrapper');
                const stkBtn = document.getElementById('stk_pay_btn');
                const phoneInput = document.getElementById('phone_number');
                const soldOutNotice = document.getElementById('sold_out_notice');
                const stkForm = document.getElementById('stk_payment_form');
                const summary = document.getElementById('payment_summary');
                const paidTicketsCountNode = document.getElementById('paid_tickets_count');
                const registrationChip = document.getElementById('registration_chip');
                const mpesaCodeRow = document.getElementById('mpesa_code_row');
                const mpesaCodeValue = document.getElementById('mpesa_code_value');
                const paymentUpdatedRow = document.getElementById('payment_updated_row');
                const paymentUpdatedValue = document.getElementById('payment_updated_value');
                const digitalTicketCard = document.getElementById('digital_ticket_card');
                const ticketMpesaRow = document.getElementById('ticket_mpesa_row');
                const ticketMpesaValue = document.getElementById('ticket_mpesa_value');
                const ticketToken = <?php echo json_encode($ticketToken ?? '', JSON_UNESCAPED_SLASHES); ?>;
                let paidTicketsCount = summary ? Math.max(0, parseInt(summary.getAttribute('data-paid-count') || '0', 10)) : 0;
                let pollTimer = null;

                function showNotice(type, message) {
                    if (!message) {
                        return;
                    }
                    const node = document.createElement('div');
                    node.className = 'notice ' + (type === 'error' ? 'err' : 'ok');
                    node.textContent = message;
                    const shell = document.querySelector('.payment-shell');
                    if (!shell) {
                        return;
                    }
                    const title = shell.querySelector('.page-title');
                    if (title && title.nextSibling) {
                        shell.insertBefore(node, title.nextSibling);
                    } else {
                        shell.appendChild(node);
                    }
                    setTimeout(function () {
                        if (node.parentNode) {
                            node.parentNode.removeChild(node);
                        }
                    }, 5000);
                }

                function setRegistrationState(state) {
                    const normalized = String(state || '').toLowerCase();
                    const shouldConfirm = normalized === 'paid' || normalized === 'confirmed' || paidTicketsCount > 0;
                    const isPending = normalized === 'pending' || normalized === 'requested';
                    if (!registrationChip) {
                        return;
                    }
                    registrationChip.classList.toggle('chip-ok', shouldConfirm);
                    registrationChip.classList.toggle('chip-pending', !shouldConfirm);
                    registrationChip.textContent = shouldConfirm ? 'Confirmed' : (isPending ? 'Pending confirmation' : 'Not paid');

                    if (digitalTicketCard) {
                        digitalTicketCard.classList.toggle('hidden', !shouldConfirm);
                    }
                }

                function stopPolling() {
                    if (pollTimer) {
                        clearInterval(pollTimer);
                        pollTimer = null;
                    }
                }

                function applyPolledStatus(status, mpesaCode, updatedAt, checkoutId) {
                    const raw = String(status || '').toLowerCase();
                    const normalized = (raw === 'paid' || raw === 'completed' || raw === 'success')
                        ? 'paid'
                        : ((raw === 'failed' || raw === 'cancelled' || raw === 'canceled' || raw === 'error') ? 'failed' : 'requested');
                    if (summary) {
                        summary.setAttribute('data-payment-status', normalized || 'requested');
                        if (checkoutId) {
                            summary.setAttribute('data-checkout-id', checkoutId);
                        }
                    }

                    if (normalized === 'paid') {
                        setRegistrationState('paid');
                    } else if (normalized === 'failed') {
                        setRegistrationState('failed');
                    } else {
                        setRegistrationState('pending');
                    }

                    if (mpesaCodeRow && mpesaCodeValue) {
                        if (mpesaCode) {
                            mpesaCodeRow.style.display = '';
                            mpesaCodeValue.textContent = String(mpesaCode);
                        }
                    }

                    if (ticketMpesaRow && ticketMpesaValue) {
                        if (mpesaCode) {
                            ticketMpesaRow.style.display = '';
                            ticketMpesaValue.textContent = String(mpesaCode);
                        }
                    }

                    if (paymentUpdatedRow && paymentUpdatedValue && updatedAt) {
                        paymentUpdatedRow.style.display = '';
                        paymentUpdatedValue.textContent = String(updatedAt);
                    }
                }

                function startPolling(checkoutRequestId) {
                    const checkoutId = String(checkoutRequestId || '').trim();
                    if (!checkoutId) {
                        return;
                    }

                    if (summary) {
                        summary.setAttribute('data-checkout-id', checkoutId);
                    }

                    stopPolling();

                    const pollOnce = function () {
                        fetch('../check_payment_status.php?checkout_request_id=' + encodeURIComponent(checkoutId), {
                            method: 'GET',
                            headers: {
                                'Accept': 'application/json'
                            }
                        })
                            .then(function (response) { return response.json(); })
                            .then(function (data) {
                                if (!data || !data.ok) {
                                    return;
                                }

                                const status = String(data.status || '').toLowerCase();
                                applyPolledStatus(status, String(data.mpesa_code || ''), String(data.updated_at || ''), checkoutId);

                                if (status === 'paid' || status === 'completed' || status === 'success') {
                                    paidTicketsCount += 1;
                                    if (summary) {
                                        summary.setAttribute('data-paid-count', String(paidTicketsCount));
                                    }
                                    if (paidTicketsCountNode) {
                                        paidTicketsCountNode.textContent = String(paidTicketsCount);
                                    }
                                    showNotice('success', 'Payment confirmed successfully.');
                                    if (digitalTicketCard) {
                                        digitalTicketCard.scrollIntoView({ behavior: 'smooth', block: 'center' });
                                    }
                                    stopPolling();
                                    setTimeout(function () {
                                        window.location.reload();
                                    }, 1200);
                                } else if (status === 'failed' || status === 'cancelled' || status === 'canceled' || status === 'error') {
                                    showNotice('error', 'Payment failed. Please try again.');
                                    stopPolling();
                                }
                            })
                            .catch(function () {
                                // Keep polling silently for transient network issues.
                            });
                    };

                    pollOnce();
                    pollTimer = setInterval(pollOnce, 5000);
                }

                function formatKES(amount) {
                    if (amount <= 0) {
                        return 'Free';
                    }
                    return 'KES ' + Number(amount).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                }

                function navigateToPay(isFree) {
                    const target = isFree ? freeWrap : payWrap;
                    if (target) {
                        target.scrollIntoView({ behavior: 'smooth', block: 'start' });
                    }

                    if (!isFree && phoneInput && payWrap && !payWrap.classList.contains('hidden')) {
                        phoneInput.focus();
                    }
                }

                function updateSelection(typeKey) {
                    if (!ticketData[typeKey]) {
                        return;
                    }

                    const meta = ticketData[typeKey];
                    const amount = Number(meta.price || 0);
                    const isFree = amount <= 0;
                    const remaining = Number(meta.remaining !== undefined ? meta.remaining : 0);
                    const soldOut = remaining <= 0;

                    cards.forEach(function (card) {
                        card.classList.toggle('active', card.getAttribute('data-ticket-type') === typeKey);
                    });
                    radios.forEach(function (radio) {
                        radio.checked = radio.value === typeKey;
                    });

                    typeInputs.forEach(function (input) {
                        input.value = typeKey;
                    });

                    if (ticketTypeLabel) {
                        ticketTypeLabel.textContent = String(meta.label || typeKey);
                    }
                    if (ticketAmountLabel) {
                        ticketAmountLabel.textContent = formatKES(amount);
                    }
                    if (ticketRemainingLabel) {
                        ticketRemainingLabel.textContent = String(remaining);
                    }

                    if (soldOutNotice) {
                        soldOutNotice.classList.toggle('hidden', !soldOut);
                    }

                    if (freeWrap) {
                        freeWrap.classList.toggle('hidden', !isFree || soldOut);
                    }
                    if (payWrap) {
                        payWrap.classList.toggle('hidden', isFree || soldOut);
                    }

                    if (stkBtn) {
                        stkBtn.textContent = isFree ? 'Send Mpesa prompt' : ('Confirm Payment via M-Pesa (' + formatKES(amount) + ')');
                    }

                    navigateToPay(isFree);
                }

                radios.forEach(function (radio) {
                    radio.addEventListener('change', function () {
                        updateSelection(radio.value);
                    });
                });

                cards.forEach(function (card) {
                    card.addEventListener('click', function () {
                        if (card.getAttribute('data-sold-out') === '1') {
                            if (soldOutNotice) {
                                soldOutNotice.classList.remove('hidden');
                            }
                            return;
                        }
                        const selectedType = card.getAttribute('data-ticket-type') || '';
                        if (selectedType !== '') {
                            updateSelection(selectedType);
                        }
                    });
                });

                const initiallyChecked = radios.find(function (radio) { return radio.checked; });
                if (initiallyChecked) {
                    updateSelection(initiallyChecked.value);
                } else {
                    if (ticketTypeLabel) {
                        ticketTypeLabel.textContent = 'Select a ticket type';
                    }
                    if (ticketAmountLabel) {
                        ticketAmountLabel.textContent = '--';
                    }
                    if (ticketRemainingLabel) {
                        ticketRemainingLabel.textContent = '--';
                    }
                }

                if (stkForm) {
                    stkForm.addEventListener('submit', function (event) {
                        event.preventDefault();

                        if (!stkBtn) {
                            return;
                        }

                        const originalText = stkBtn.textContent;
                        stkBtn.disabled = true;
                        stkBtn.textContent = 'Processing...';

                        fetch(window.location.pathname + window.location.search, {
                            method: 'POST',
                            headers: {
                                'X-Requested-With': 'XMLHttpRequest'
                            },
                            body: new FormData(stkForm)
                        })
                            .then(function (response) { return response.json(); })
                            .then(function (data) {
                                const ok = !!(data && data.ok);
                                showNotice(ok ? 'success' : 'error', String((data && data.message) || (ok ? 'Request processed.' : 'Request failed.')));

                                const checkoutId = String((data && data.checkout_request_id) || (data && data.payment && data.payment.checkout_request_id) || '');
                                const paymentStatus = String((data && data.payment && data.payment.status) || '').toLowerCase();
                                const paidUnits = Math.max(0, parseInt((data && data.paid_ticket_units) || '0', 10) || 0);

                                if (ok && paidUnits > 0) {
                                    paidTicketsCount += paidUnits;
                                    if (summary) {
                                        summary.setAttribute('data-paid-count', String(paidTicketsCount));
                                    }
                                    if (paidTicketsCountNode) {
                                        paidTicketsCountNode.textContent = String(paidTicketsCount);
                                    }
                                }

                                if (ok && paymentStatus === 'paid') {
                                    applyPolledStatus('paid', String((data && data.payment && data.payment.mpesa_code) || ''), String((data && data.payment && data.payment.updated_at) || ''), checkoutId);
                                    setTimeout(function () {
                                        window.location.reload();
                                    }, 900);
                                } else if (ok && checkoutId !== '') {
                                    setRegistrationState('pending');
                                    startPolling(checkoutId);
                                }

                                if (ok && phoneInput) {
                                    phoneInput.value = '';
                                }
                            })
                            .catch(function () {
                                showNotice('error', 'Unable to initiate payment right now. Please try again.');
                            })
                            .finally(function () {
                                stkBtn.disabled = false;
                                stkBtn.textContent = originalText;
                            });
                    });
                }

                if (summary) {
                    const existingCheckoutId = String(summary.getAttribute('data-checkout-id') || '').trim();
                    const existingStatus = String(summary.getAttribute('data-payment-status') || '').toLowerCase();
                    if (existingCheckoutId !== '' && (existingStatus === 'requested' || existingStatus === 'pending')) {
                        startPolling(existingCheckoutId);
                    }
                }
            })();
        </script>
    <?php endif; ?>
</body>
</html>
