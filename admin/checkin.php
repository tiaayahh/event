<?php
require_once '../includes/auth.php';
require_once '../includes/csrf.php';
require_once '../includes/audit.php';
require_once '../includes/ticket_qr.php';

checkAuth();
requireRole('planner');

$fullName = $_SESSION['full_name'] ?? 'Planner';
$flashSuccess = '';
$flashError = '';
$result = null;

function checkinEnsureAttendanceColumns(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }

    $stmt = $pdo->query("SHOW COLUMNS FROM attendances LIKE 'checked_in_at'");
    if (!$stmt->fetch()) {
        $pdo->exec("ALTER TABLE attendances ADD COLUMN checked_in_at TIMESTAMP NULL DEFAULT NULL AFTER created_at");
    }

    $stmt = $pdo->query("SHOW COLUMNS FROM attendances LIKE 'checkin_by_user_id'");
    if (!$stmt->fetch()) {
        $pdo->exec("ALTER TABLE attendances ADD COLUMN checkin_by_user_id INT NULL DEFAULT NULL AFTER checked_in_at");
    }

    $ready = true;
}

function checkinEnsureTicketPaymentColumns(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
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
        $pdo->exec("CREATE INDEX idx_attendee_ticket_payment_checkin ON attendee_ticket_payments (event_id, attendee_id, status, checked_in_at)");
    } catch (Throwable $e) {
        // Ignore if index already exists.
    }

    $ready = true;
}

function checkinFindTicket(PDO $pdo, string $token): ?array
{
    $payload = ticket_qr_parse_payload_token($token);
    if (!is_array($payload)) {
        return null;
    }

    $eventId = (int)($payload['event_id'] ?? 0);
    $attendeeId = (int)($payload['attendee_id'] ?? 0);
    $paymentId = (int)($payload['payment_id'] ?? 0);
    $ticketCode = strtoupper((string)($payload['ticket_code'] ?? ''));

    if ($eventId <= 0 || $attendeeId <= 0) {
        return null;
    }

    $stmt = $pdo->prepare(
        "SELECT
            e.event_id,
            e.title,
            e.event_date,
            e.planner_id,
            a.attendee_id,
            a.status,
            p.payment_id,
            p.checked_in_at,
            atd.full_name AS attendee_name,
            u.email AS attendee_email,
            COALESCE(p.status, 'requested') AS payment_status,
            COALESCE(p.ticket_type, 'regular') AS ticket_type,
            COALESCE(p.amount, 0) AS amount_paid
         FROM attendee_ticket_payments p
         JOIN attendances a ON a.event_id = p.event_id AND a.attendee_id = p.attendee_id
         JOIN events e ON e.event_id = p.event_id
         JOIN attendees atd ON atd.attendee_id = p.attendee_id
         JOIN users u ON u.user_id = atd.user_id
         WHERE p.event_id = ? AND p.attendee_id = ?
           AND (? = 0 OR p.payment_id = ?)
           AND p.status = 'paid'
         LIMIT 1"
    );
    $stmt->execute([$eventId, $attendeeId, $paymentId, $paymentId]);
    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }

    if ($ticketCode !== '') {
        $expectedCode = ticket_qr_build_code((int)$row['event_id'], (int)$row['attendee_id'], (string)$row['event_date'], (int)$row['payment_id']);
        if (!hash_equals($expectedCode, $ticketCode)) {
            return null;
        }
    }

    $row['token_payload'] = $payload;
    return $row;
}

checkinEnsureAttendanceColumns($pdo);
checkinEnsureTicketPaymentColumns($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require_valid_post_token();
    $token = trim((string)($_POST['ticket_token'] ?? ''));

    if ($token === '') {
        $flashError = 'Paste or scan a QR token to continue.';
    } else {
        try {
            $ticket = checkinFindTicket($pdo, $token);
            if (!$ticket) {
                throw new RuntimeException('Invalid or tampered QR ticket token.');
            }

            $plannerId = (int)($ticket['planner_id'] ?? 0);
            if ($plannerId !== (int)($_SESSION['user_id'] ?? 0)) {
                throw new RuntimeException('You can only check in attendees for your own events.');
            }

            $status = strtolower(trim((string)($ticket['status'] ?? '')));
            $paymentStatus = strtolower(trim((string)($ticket['payment_status'] ?? '')));
            if ($paymentStatus !== 'paid') {
                throw new RuntimeException('This ticket is not paid yet, check-in denied.');
            }

            $pdo->beginTransaction();
            try {
                if ($status !== 'registered' && $status !== 'attended') {
                    throw new RuntimeException('Ticket is not in a valid registration state for check-in.');
                } else {
                    $stmt = $pdo->prepare(
                        "UPDATE attendee_ticket_payments
                         SET checked_in_at = COALESCE(checked_in_at, NOW()),
                             checkin_by_user_id = COALESCE(checkin_by_user_id, ?)
                         WHERE payment_id = ? AND status = 'paid'"
                    );
                    $stmt->execute([(int)$_SESSION['user_id'], (int)$ticket['payment_id']]);

                    if ($stmt->rowCount() <= 0) {
                        $flashSuccess = 'Ticket already checked in earlier.';
                    } else {
                        $flashSuccess = 'Check-in successful. Ticket marked as used.';
                    }

                    $stmt = $pdo->prepare(
                        "SELECT COUNT(*)
                         FROM attendee_ticket_payments
                         WHERE event_id = ? AND attendee_id = ? AND status = 'paid' AND checked_in_at IS NOT NULL"
                    );
                    $stmt->execute([(int)$ticket['event_id'], (int)$ticket['attendee_id']]);
                    $paidCheckedIn = (int)$stmt->fetchColumn();

                    if ($paidCheckedIn > 0) {
                        $stmt = $pdo->prepare(
                            "UPDATE attendances
                             SET status = 'attended', checked_in_at = COALESCE(checked_in_at, NOW()), checkin_by_user_id = COALESCE(checkin_by_user_id, ?)
                             WHERE event_id = ? AND attendee_id = ? AND status = 'registered'"
                        );
                        $stmt->execute([(int)$_SESSION['user_id'], (int)$ticket['event_id'], (int)$ticket['attendee_id']]);
                    }
                }

                audit_log(
                    $pdo,
                    (int)($_SESSION['user_id'] ?? 0),
                    'planner',
                    'planner.attendee_checkin',
                    'attendee_ticket_payment',
                    (string)($ticket['payment_id'] ?? ''),
                    [
                        'event_id' => (int)$ticket['event_id'],
                        'attendee_id' => (int)$ticket['attendee_id'],
                        'payment_id' => (int)$ticket['payment_id'],
                        'ticket_type' => (string)$ticket['ticket_type'],
                        'already_checked_in' => !empty($ticket['checked_in_at']),
                    ]
                );

                $pdo->commit();
            } catch (Throwable $txe) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $txe;
            }

            $result = $ticket;
        } catch (Throwable $e) {
            $flashError = $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Planora - QR Check-in</title>
    <link rel="stylesheet" href="../assets/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { background: #f4f5fb; color: #2d2d2d; padding-bottom: 76px; }
        .header { background: #6C63FF; color: #fff; padding: 14px 24px; display: flex; justify-content: space-between; align-items: center; }
        .brand { font-size: 22px; font-weight: 700; }
        .logout-btn { background: rgba(255,255,255,.22); color: #fff; text-decoration: none; border-radius: 6px; padding: 7px 14px; font-size: 13px; }
        .wrap { max-width: 900px; margin: 18px auto; padding: 0 14px; }
        .card { background:#fff; border:1px solid #ece9ff; border-radius:12px; padding:16px; margin-bottom:14px; }
        .title { font-size: 23px; color:#1f1d35; margin-bottom: 4px; }
        .sub { font-size:13px; color:#666; margin-bottom: 12px; }
        .notice { border-radius: 8px; padding: 10px 12px; margin-bottom: 10px; font-size: 13px; }
        .notice.ok { background: #ecfff0; color:#1c7a36; border:1px solid #c9f0d4; }
        .notice.err { background: #ffecec; color:#9d2020; border:1px solid #f6caca; }
        .token-input { width:100%; min-height:90px; border:1px solid #ddd; border-radius:8px; padding:10px; font-size:13px; resize:vertical; }
        .actions { margin-top: 10px; display:flex; gap:8px; flex-wrap: wrap; }
        .btn { border:none; border-radius:8px; padding:9px 12px; font-size:12px; font-weight:700; cursor:pointer; text-decoration:none; display:inline-block; }
        .btn-primary { background:#6C63FF; color:#fff; }
        .btn-light { background:#ece9ff; color:#3f379f; }
        .grid { display:grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap:10px; margin-top: 8px; }
        .kpi { border:1px solid #ece9ff; border-radius:10px; padding:10px; background:#faf9ff; }
        .kpi .label { font-size:11px; color:#666; text-transform:uppercase; letter-spacing:.4px; }
        .kpi .value { font-size:14px; font-weight:700; color:#1f1d35; margin-top:4px; }
        .bottom-nav { position: fixed; bottom: 0; left: 0; right: 0; height: 62px; background: #6C63FF; display: flex; z-index: 999; }
        .bottom-nav-item { flex: 1; color: rgba(255,255,255,.76); text-decoration: none; font-size: 11px; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 4px; }
        .bottom-nav-item i { font-size: 15px; }
        .bottom-nav-item.active, .bottom-nav-item:hover { color: #fff; background: rgba(255,255,255,.1); }
        @media (max-width: 700px) {
            .title { font-size: 20px; }
            .header { padding: 12px 14px; }
        }
    </style>
</head>
<body>
<header class="header">
    <div class="brand">PLANORA</div>
    <a class="logout-btn" href="../logout.php">Logout</a>
</header>

<div class="wrap">
    <div class="card">
        <div class="title">QR Check-in</div>
        <div class="sub">Scan attendee QR code and paste the token below to verify and check in.</div>

        <?php if ($flashSuccess !== ''): ?><div class="notice ok"><?php echo htmlspecialchars($flashSuccess); ?></div><?php endif; ?>
        <?php if ($flashError !== ''): ?><div class="notice err"><?php echo htmlspecialchars($flashError); ?></div><?php endif; ?>

        <form method="POST">
            <?php echo csrf_input(); ?>
            <label for="ticket_token" style="font-size:12px; font-weight:700; color:#333;">QR Token</label>
            <textarea id="ticket_token" name="ticket_token" class="token-input" placeholder="Paste scanned QR token here" required><?php echo htmlspecialchars((string)($_POST['ticket_token'] ?? '')); ?></textarea>
            <div class="actions">
                <button class="btn btn-primary" type="submit"><i class="fa-solid fa-check"></i> Verify & Check In</button>
                <a class="btn btn-light" href="dashboard.php">Back to Dashboard</a>
            </div>
        </form>
    </div>

    <?php if (is_array($result)): ?>
        <div class="card">
            <div style="font-size:16px; font-weight:700; color:#1f1d35;">Ticket Details</div>
            <div class="grid">
                <div class="kpi"><div class="label">Event</div><div class="value"><?php echo htmlspecialchars((string)$result['title']); ?></div></div>
                <div class="kpi"><div class="label">Event Date</div><div class="value"><?php echo htmlspecialchars((string)$result['event_date']); ?></div></div>
                <div class="kpi"><div class="label">Attendee</div><div class="value"><?php echo htmlspecialchars((string)$result['attendee_name']); ?></div></div>
                <div class="kpi"><div class="label">Email</div><div class="value"><?php echo htmlspecialchars((string)$result['attendee_email']); ?></div></div>
                <div class="kpi"><div class="label">Ticket Type</div><div class="value"><?php echo htmlspecialchars((string)$result['ticket_type']); ?></div></div>
                <div class="kpi"><div class="label">Amount Paid</div><div class="value">KES <?php echo number_format((float)($result['amount_paid'] ?? 0), 2); ?></div></div>
                <div class="kpi"><div class="label">Attendance</div><div class="value"><?php echo htmlspecialchars(strtoupper((string)$result['status'])); ?></div></div>
                <div class="kpi"><div class="label">Checked In At</div><div class="value"><?php echo htmlspecialchars((string)($result['checked_in_at'] ?? 'Just now')); ?></div></div>
            </div>
        </div>
    <?php endif; ?>
</div>

<nav class="bottom-nav">
    <a href="dashboard.php" class="bottom-nav-item">
        <i class="fa-solid fa-house"></i>
        <span>Home</span>
    </a>
    <a href="create_event.php" class="bottom-nav-item">
        <i class="fa-solid fa-calendar-days"></i>
        <span>Events</span>
    </a>
    <a href="browse_vendors.php" class="bottom-nav-item">
        <i class="fa-solid fa-shop"></i>
        <span>Vendors</span>
    </a>
    <a href="messages.php" class="bottom-nav-item">
        <i class="fa-solid fa-comments"></i>
        <span>Messages</span>
    </a>
    <a href="checkin.php" class="bottom-nav-item active">
        <i class="fa-solid fa-qrcode"></i>
        <span>Check-in</span>
    </a>
    <a href="../logout.php" class="bottom-nav-item">
        <i class="fa-solid fa-right-from-bracket"></i>
        <span>Logout</span>
    </a>
</nav>
</body>
</html>
