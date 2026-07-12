<?php
require_once '../includes/auth.php';
checkAuth();
requireRole('attendee');

$eventId = filter_input(INPUT_GET, 'event_id', FILTER_VALIDATE_INT);
$paymentId = filter_input(INPUT_GET, 'payment_id', FILTER_VALIDATE_INT);
if (!$eventId) {
    http_response_code(400);
    exit('Invalid event id.');
}

$userId = (int)($_SESSION['user_id'] ?? 0);
if ($userId <= 0) {
    http_response_code(401);
    exit('Unauthorized.');
}

try {
    $stmt = $pdo->prepare('SELECT attendee_id FROM attendees WHERE user_id = ? LIMIT 1');
    $stmt->execute([$userId]);
    $attendeeId = (int)($stmt->fetchColumn() ?: 0);

    if ($attendeeId <= 0) {
        http_response_code(404);
        exit('Attendee profile not found.');
    }

        $stmt = $pdo->prepare(
                "SELECT e.event_id,
                                e.title,
                                e.event_date,
                                COALESCE(e.event_type, 'in_person') AS event_type,
                                COALESCE(e.category, 'General') AS category,
                                atp.payment_id,
                                COALESCE(atp.ticket_type, 'regular') AS ticket_type,
                                COALESCE(atp.amount, 0) AS amount,
                                COALESCE(atp.status, 'requested') AS payment_status,
                                COALESCE(atp.mpesa_code, '') AS mpesa_code,
                                COALESCE(a.status, '') AS attendance_status
                 FROM attendee_ticket_payments atp
                 JOIN events e ON e.event_id = atp.event_id
                 LEFT JOIN attendances a
                        ON a.event_id = e.event_id
                     AND a.attendee_id = atp.attendee_id
                 WHERE atp.event_id = ?
                     AND atp.attendee_id = ?
                     AND (? IS NULL OR atp.payment_id = ?)
                     AND e.archived_at IS NULL
                 ORDER BY atp.payment_id DESC
                 LIMIT 1"
        );
        $stmt->execute([$eventId, $attendeeId, $paymentId, $paymentId]);
    $row = $stmt->fetch();

    if (!$row) {
        http_response_code(404);
        exit('Event not found.');
    }

    $paymentStatus = strtolower(trim((string)($row['payment_status'] ?? 'requested')));
    $attendanceStatus = strtolower(trim((string)($row['attendance_status'] ?? '')));

    $isPaid = in_array($paymentStatus, ['paid', 'completed', 'success'], true);
    $isRegistered = in_array($attendanceStatus, ['registered', 'attended'], true);

    if (!$isPaid || !$isRegistered) {
        http_response_code(403);
        exit('Ticket is not available for download until payment and registration are confirmed.');
    }

    $resolvedPaymentId = (int)($row['payment_id'] ?? 0);
    $ticketCode = strtoupper(substr(sha1((string)$row['event_id'] . '-' . (string)$attendeeId . '-' . (string)$row['event_date'] . '-' . (string)$resolvedPaymentId), 0, 14));
    $ticketType = ucfirst(str_replace('_', ' ', strtolower((string)($row['ticket_type'] ?? 'regular'))));
    $accessType = strtolower((string)($row['event_type'] ?? 'in_person')) === 'online' ? 'Online Access' : 'In-person';
    $amount = (float)($row['amount'] ?? 0);
    $amountText = $amount > 0 ? 'KES ' . number_format($amount, 2) : 'Free';

    $content = [];
    $content[] = 'PLANORA DIGITAL TICKET';
    $content[] = str_repeat('=', 42);
    $content[] = 'Event: ' . (string)$row['title'];
    $content[] = 'Date: ' . (string)$row['event_date'];
    $content[] = 'Access: ' . $accessType;
    $content[] = 'Category: ' . (string)($row['category'] ?? 'General');
    $content[] = 'Ticket Tier: ' . $ticketType;
    $content[] = 'Amount Paid: ' . $amountText;
    $content[] = 'M-Pesa Code: ' . ((string)($row['mpesa_code'] ?? '') !== '' ? (string)$row['mpesa_code'] : 'N/A');
    $content[] = 'Ticket Code: ' . $ticketCode;
    $content[] = str_repeat('=', 42);
    $content[] = 'Present this ticket code at check-in.';

    $safeTitle = preg_replace('/[^A-Za-z0-9\-_]+/', '-', (string)$row['title']);
    if ($safeTitle === null || $safeTitle === '') {
        $safeTitle = 'event-ticket';
    }
    $filename = 'planora-ticket-' . strtolower($safeTitle) . '-' . (int)$row['event_id'] . '.txt';

    header('Content-Type: text/plain; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');

    echo implode(PHP_EOL, $content) . PHP_EOL;
} catch (Throwable $e) {
    http_response_code(500);
    exit('Unable to generate ticket right now.');
}
