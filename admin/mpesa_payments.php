<?php
require_once '../includes/auth.php';
require_once '../includes/daraja.php';
checkAuth();
requireRole('planner');

$flashSuccess = $_SESSION['flash_success'] ?? '';
$flashError = $_SESSION['flash_error'] ?? '';
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

$rows = [];
$attendeeRows = [];
$pendingSources = [];
$stats = [
    'total_bookings' => 0,
    'paid_count' => 0,
    'failed_count' => 0,
    'pending_count' => 0,
    'needs_action_count' => 0,
    'total_committed' => 0.0,
    'total_paid' => 0.0,
    'attendee_total' => 0,
    'attendee_paid_count' => 0,
    'attendee_failed_count' => 0,
    'attendee_pending_count' => 0,
    'attendee_total_paid' => 0.0,
];
$priorityRows = [];
$darajaConfigured = daraja_is_configured();
$darajaMissingFields = daraja_missing_required_fields();
$darajaStkConfigured = daraja_is_stk_configured();
$darajaMissingStkFields = daraja_missing_stk_fields();

try {
    $stmt = $pdo->prepare(
        "SELECT b.booking_id,
                b.status AS booking_status,
                b.booked_price,
                b.created_at,
                e.title AS event_title,
                e.event_date,
                s.name AS service_name,
                v.business_name,
                t.mpesa_code,
                t.status AS payment_status
         FROM bookings b
         JOIN events e ON b.event_id = e.event_id
         JOIN services s ON b.service_id = s.service_id
         JOIN vendors v ON s.vendor_id = v.vendor_id
         LEFT JOIN transactions t ON b.booking_id = t.booking_id
         WHERE e.planner_id = ? AND e.archived_at IS NULL
         ORDER BY b.created_at DESC"
    );
    $stmt->execute([$_SESSION['user_id']]);
    $rows = $stmt->fetchAll();

    $stats['total_bookings'] = count($rows);
    foreach ($rows as $row) {
        $amount = (float)$row['booked_price'];
        $stats['total_committed'] += $amount;

        $bookingPaymentStatus = strtolower((string)($row['payment_status'] ?? 'pending'));

        if ($bookingPaymentStatus === 'paid') {
            $stats['paid_count']++;
            $stats['total_paid'] += $amount;
        } elseif ($bookingPaymentStatus === 'failed') {
            $stats['failed_count']++;
            $stats['needs_action_count']++;
        } else {
            $stats['pending_count']++;
            $stats['needs_action_count']++;
            $pendingSources[] = [
                'source_type' => 'Booking vendor payment',
                'event_title' => (string)($row['event_title'] ?? ''),
                'source_name' => (string)($row['business_name'] ?? ''),
                'extra' => (string)($row['service_name'] ?? ''),
                'amount' => $amount,
                'status' => $bookingPaymentStatus,
                'created_at' => (string)($row['created_at'] ?? ''),
                'action_url' => 'initiate_payment.php?booking_id=' . (int)$row['booking_id'],
                'action_label' => 'Open Booking Payment',
            ];
        }

        if ((($row['payment_status'] ?? 'pending') !== 'paid') || (($row['booking_status'] ?? 'pending') !== 'confirmed')) {
            $priorityRows[] = $row;
        }
    }

    $stmt = $pdo->prepare(
        "SELECT atp.payment_id,
                atp.ticket_type,
                atp.amount,
                atp.status,
                atp.phone_number,
                atp.checkout_request_id,
                atp.created_at,
                atp.updated_at,
                e.event_id,
                e.title AS event_title,
                e.event_date,
                u.full_name AS attendee_name,
                u.email AS attendee_email
         FROM attendee_ticket_payments atp
         JOIN events e ON atp.event_id = e.event_id
         JOIN attendees a ON a.attendee_id = atp.attendee_id
         JOIN users u ON u.user_id = a.user_id
         WHERE e.planner_id = ?
           AND e.archived_at IS NULL
         ORDER BY atp.created_at DESC"
    );
    $stmt->execute([$_SESSION['user_id']]);
    $attendeeRows = $stmt->fetchAll();

    $stats['attendee_total'] = count($attendeeRows);
    foreach ($attendeeRows as $attendeeRow) {
        $attendeeAmount = (float)($attendeeRow['amount'] ?? 0);
        $attendeeStatus = strtolower((string)($attendeeRow['status'] ?? 'requested'));

        if ($attendeeStatus === 'paid') {
            $stats['attendee_paid_count']++;
            $stats['attendee_total_paid'] += $attendeeAmount;
        } elseif ($attendeeStatus === 'failed') {
            $stats['attendee_failed_count']++;
            $stats['needs_action_count']++;
        } else {
            $stats['attendee_pending_count']++;
            $stats['needs_action_count']++;
            $pendingSources[] = [
                'source_type' => 'Attendee ticket payment',
                'event_title' => (string)($attendeeRow['event_title'] ?? ''),
                'source_name' => (string)($attendeeRow['attendee_name'] ?? 'Attendee'),
                'extra' => strtoupper((string)($attendeeRow['ticket_type'] ?? 'regular')) . ' ticket',
                'amount' => $attendeeAmount,
                'status' => $attendeeStatus,
                'created_at' => (string)($attendeeRow['created_at'] ?? ''),
                'action_url' => '#attendee-payment-' . (int)($attendeeRow['payment_id'] ?? 0),
                'action_label' => 'Open Attendee Payment',
            ];
        }
    }

    usort($pendingSources, static function (array $a, array $b): int {
        return strcmp((string)($b['created_at'] ?? ''), (string)($a['created_at'] ?? ''));
    });
} catch (Throwable $e) {
    if ($flashError === '') {
        $flashError = 'Unable to load M-Pesa payments right now.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="../assets/style.css">
    <title>Planora - Booking Payments</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; }
        body { background: #f4f5fb; color: #2D2D2D; min-height: 100vh; }
        .header { background: #6C63FF; color: #fff; padding: 14px 24px; display: flex; justify-content: space-between; align-items: center; }
        .brand { font-size: 22px; font-weight: 700; }
        .logout-btn { background: rgba(255,255,255,0.22); color: #fff; text-decoration: none; border-radius: 6px; padding: 7px 14px; font-size: 13px; }
        .container { max-width: 1150px; margin: 0 auto; padding: 20px; }
        .links { display: flex; gap: 10px; margin-bottom: 14px; flex-wrap: wrap; }
        .link-btn { text-decoration: none; background: #ece9ff; color: #3b3496; border-radius: 10px; border: 1px solid #d8d4ff; padding: 8px 12px; font-size: 13px; }
        .message { border-radius: 6px; padding: 10px 12px; margin-bottom: 14px; font-size: 13px; }
        .message-success { background: #ecfff0; color: #1c7a36; border: 1px solid #c9f0d4; }
        .message-error { background: #ffecec; color: #9d2020; border: 1px solid #f6caca; }
        .message-info { background:#eef3ff; color:#2c4ea0; border:1px solid #d9e4ff; }
        .stats { display: grid; grid-template-columns: repeat(5, 1fr); gap: 12px; margin-bottom: 18px; }
        .stat { background: #fff; border-radius: 12px; border: 1px solid #ece9ff; padding: 14px; }
        .stat-value { font-size: 23px; font-weight: 700; color: #6C63FF; }
        .stat-label { font-size: 12px; color: #777; margin-top: 4px; }
        .ops-note { background: #eef3ff; color: #2c4ea0; border: 1px solid #d9e4ff; border-radius: 8px; padding: 10px 12px; font-size: 13px; margin-bottom: 14px; }
        .card { background: #fff; border-radius: 12px; border: 1px solid #ece9ff; padding: 18px; box-shadow: 0 1px 3px rgba(0,0,0,.05); }
        .title { font-size: 18px; font-weight: 700; margin-bottom: 12px; }
        .priority-list { display: flex; flex-direction: column; gap: 8px; margin-bottom: 16px; }
        .priority-item { border: 1px solid #ececec; border-radius: 8px; padding: 10px; background: #fafafa; }
        .priority-main { font-size: 13px; color: #2D2D2D; margin-bottom: 5px; }
        .priority-meta { font-size: 12px; color: #6b6b6b; }
        .priority-link { display: inline-block; margin-top: 6px; font-size: 12px; color: #3b3496; text-decoration: none; font-weight: 600; }
        .table-wrap { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 11px 10px; text-align: left; border-bottom: 1px solid #efefef; font-size: 13px; }
        th { background: #fafafa; color: #555; font-weight: 700; }
        .badge { display: inline-block; padding: 3px 9px; border-radius: 999px; font-size: 11px; font-weight: 700; }
        .badge-paid { background: #e8f9ef; color: #1c7a36; }
        .badge-pending { background: #fff4df; color: #a36500; }
        .badge-failed { background: #ffe8e8; color: #a22b2b; }
        .btn { text-decoration: none; background: #6C63FF; color: #fff; border-radius: 10px; padding: 7px 10px; font-size: 12px; display: inline-block; }
        .empty { color: #777; font-size: 14px; padding: 10px 0; }
        @media (max-width: 960px) { .stats { grid-template-columns: 1fr 1fr; } }
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
        <a class="link-btn" href="messages.php?unread=1"><i class="fa-solid fa-comments"></i> Unread Messages</a>
    </div>

    <?php if ($flashSuccess !== ''): ?><div class="message message-success"><?php echo htmlspecialchars($flashSuccess); ?></div><?php endif; ?>
    <?php if ($flashError !== ''): ?><div class="message message-error"><?php echo htmlspecialchars($flashError); ?></div><?php endif; ?>

    <?php if (!$darajaStkConfigured): ?>
        <div class="message message-info">Mpesa prompt is unavailable (missing: <?php echo htmlspecialchars(implode(', ', $darajaMissingStkFields)); ?>). Manual payment updates and reconciliation are still active.</div>
    <?php elseif (!$darajaConfigured): ?>
        <div class="message message-error">Daraja is not fully configured. Missing: <?php echo htmlspecialchars(implode(', ', $darajaMissingFields)); ?>.</div>
    <?php endif; ?>

    

    <section class="stats">
        <div class="stat"><div class="stat-value"><?php echo (int)$stats['total_bookings']; ?></div><div class="stat-label">Total Bookings</div></div>
        <div class="stat"><div class="stat-value"><?php echo (int)$stats['paid_count']; ?></div><div class="stat-label">Paid</div></div>
        <div class="stat"><div class="stat-value"><?php echo (int)$stats['pending_count']; ?></div><div class="stat-label">Pending/Unpaid</div></div>
        <div class="stat"><div class="stat-value"><?php echo (int)$stats['failed_count']; ?></div><div class="stat-label">Failed</div></div>
        <div class="stat"><div class="stat-value"><?php echo (int)$stats['needs_action_count']; ?></div><div class="stat-label">Needs Action</div></div>
        <div class="stat"><div class="stat-value">KES <?php echo number_format((float)$stats['total_paid'], 2); ?></div><div class="stat-label">Paid Amount</div></div>
        <div class="stat"><div class="stat-value"><?php echo (int)$stats['attendee_total']; ?></div><div class="stat-label">Attendee Payments</div></div>
        <div class="stat"><div class="stat-value"><?php echo (int)$stats['attendee_pending_count']; ?></div><div class="stat-label">Attendee Pending</div></div>
        <div class="stat"><div class="stat-value"><?php echo (int)$stats['attendee_failed_count']; ?></div><div class="stat-label">Attendee Failed</div></div>
        <div class="stat"><div class="stat-value">KES <?php echo number_format((float)$stats['attendee_total_paid'], 2); ?></div><div class="stat-label">Attendee Paid Amount</div></div>
    </section>

    <section class="card" style="margin-bottom:14px;">
        <h2 class="title">Pending Payment Sources</h2>
        <?php if (empty($pendingSources)): ?>
            <div class="empty">No pending payments right now.</div>
        <?php else: ?>
            <div class="priority-list">
                <?php foreach (array_slice($pendingSources, 0, 12) as $source): ?>
                    <div class="priority-item">
                        <div class="priority-main">
                            <strong><?php echo htmlspecialchars((string)$source['source_type']); ?></strong>
                            for <?php echo htmlspecialchars((string)$source['event_title']); ?>
                        </div>
                        <div class="priority-meta">
                            From: <?php echo htmlspecialchars((string)$source['source_name']); ?>
                            | Detail: <?php echo htmlspecialchars((string)$source['extra']); ?>
                            | Status: <?php echo htmlspecialchars(ucfirst((string)$source['status'])); ?>
                            | Amount: KES <?php echo number_format((float)$source['amount'], 2); ?>
                        </div>
                        <a class="priority-link" href="<?php echo htmlspecialchars((string)$source['action_url']); ?>"><?php echo htmlspecialchars((string)$source['action_label']); ?></a>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <section class="card">
        <h2 class="title">Booking Payments</h2>
       

        <?php if (!empty($priorityRows)): ?>
            <div class="priority-list">
                <?php foreach (array_slice($priorityRows, 0, 5) as $row): ?>
                    <div class="priority-item">
                        <div class="priority-main">
                            <strong><?php echo htmlspecialchars($row['business_name']); ?></strong> for <?php echo htmlspecialchars($row['event_title']); ?> requires payment follow-up.
                        </div>
                        <div class="priority-meta">
                            Booking: <?php echo htmlspecialchars(ucfirst((string)$row['booking_status'])); ?>
                            | Payment: <?php echo htmlspecialchars(ucfirst((string)($row['payment_status'] ?? 'pending'))); ?>
                            | Amount: KES <?php echo number_format((float)$row['booked_price'], 2); ?>
                        </div>
                        <a class="priority-link" href="initiate_payment.php?booking_id=<?php echo (int)$row['booking_id']; ?>">Open Booking Payment</a>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if (empty($rows)): ?>
            <div class="empty">No bookings found yet.</div>
        <?php else: ?>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Event</th>
                            <th>Vendor / Service</th>
                            <th>Amount</th>
                            <th>Booking</th>
                            <th>Payment</th>
                            <th>M-Pesa Code</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $row): ?>
                            <?php
                                $paymentStatus = strtolower((string)($row['payment_status'] ?? 'pending'));
                                $paymentClass = $paymentStatus === 'paid' ? 'badge-paid' : ($paymentStatus === 'failed' ? 'badge-failed' : 'badge-pending');
                            ?>
                            <tr>
                                <td><?php echo htmlspecialchars($row['event_title']); ?><br><small><?php echo htmlspecialchars((string)$row['event_date']); ?></small></td>
                                <td><?php echo htmlspecialchars($row['business_name']); ?><br><small><?php echo htmlspecialchars($row['service_name']); ?></small></td>
                                <td>KES <?php echo number_format((float)$row['booked_price'], 2); ?></td>
                                <td><span class="badge <?php echo strtolower((string)$row['booking_status']) === 'confirmed' ? 'badge-paid' : 'badge-pending'; ?>"><?php echo htmlspecialchars(ucfirst((string)$row['booking_status'])); ?></span></td>
                                <td><span class="badge <?php echo $paymentClass; ?>"><?php echo htmlspecialchars(ucfirst($paymentStatus)); ?></span></td>
                                <td><?php echo htmlspecialchars((string)($row['mpesa_code'] ?? '-')); ?></td>
                                <td><a class="btn" href="initiate_payment.php?booking_id=<?php echo (int)$row['booking_id']; ?>">Open Booking Payment</a></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>

    <section class="card" style="margin-top:14px;">
        <h2 class="title">Attendee Ticket Payments</h2>
        <?php if (empty($attendeeRows)): ?>
            <div class="empty">No attendee ticket payments found yet.</div>
        <?php else: ?>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Event</th>
                            <th>Attendee</th>
                            <th>Ticket Type</th>
                            <th>Amount</th>
                            <th>Status</th>
                            <th>Phone</th>
                            <th>Checkout ID</th>
                            <th>Updated</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($attendeeRows as $row): ?>
                            <?php
                                $status = strtolower((string)($row['status'] ?? 'requested'));
                                $statusClass = $status === 'paid' ? 'badge-paid' : ($status === 'failed' ? 'badge-failed' : 'badge-pending');
                            ?>
                            <tr id="attendee-payment-<?php echo (int)($row['payment_id'] ?? 0); ?>">
                                <td><?php echo htmlspecialchars((string)$row['event_title']); ?><br><small><?php echo htmlspecialchars((string)$row['event_date']); ?></small></td>
                                <td><?php echo htmlspecialchars((string)$row['attendee_name']); ?><br><small><?php echo htmlspecialchars((string)$row['attendee_email']); ?></small></td>
                                <td><?php echo htmlspecialchars(strtoupper((string)($row['ticket_type'] ?? 'regular'))); ?></td>
                                <td>KES <?php echo number_format((float)($row['amount'] ?? 0), 2); ?></td>
                                <td><span class="badge <?php echo $statusClass; ?>"><?php echo htmlspecialchars(ucfirst($status)); ?></span></td>
                                <td><?php echo htmlspecialchars((string)($row['phone_number'] ?? '-')); ?></td>
                                <td><?php echo htmlspecialchars((string)($row['checkout_request_id'] ?? '-')); ?></td>
                                <td><?php echo htmlspecialchars((string)($row['updated_at'] ?? '')); ?></td>
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
