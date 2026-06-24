<?php
require_once '../includes/auth.php';
checkAuth();
requireRole('attendee');

$eventId = filter_input(INPUT_GET, 'event_id', FILTER_VALIDATE_INT);

if (!$eventId) {
    $_SESSION['flash_error'] = 'Invalid event selected.';
    header('Location: my_events.php');
    exit;
}

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare('SELECT event_id, ticket_price FROM events WHERE event_id = ? LIMIT 1');
    $stmt->execute([$eventId]);
    $event = $stmt->fetch();

    if (!$event) {
        throw new RuntimeException('Event not found.');
    }

    $stmt = $pdo->prepare('SELECT attendee_id FROM attendees WHERE user_id = ? LIMIT 1');
    $stmt->execute([$_SESSION['user_id']]);
    $attendee = $stmt->fetch();

    if (!$attendee) {
        $stmt = $pdo->prepare('INSERT INTO attendees (user_id) VALUES (?)');
        $stmt->execute([$_SESSION['user_id']]);
        $attendeeId = (int)$pdo->lastInsertId();
    } else {
        $attendeeId = (int)$attendee['attendee_id'];
    }

    $stmt = $pdo->prepare('SELECT attendance_id FROM attendances WHERE event_id = ? AND attendee_id = ? LIMIT 1');
    $stmt->execute([$eventId, $attendeeId]);

    if ($stmt->fetch()) {
        throw new RuntimeException('You are already registered for this event.');
    }

    $stmt = $pdo->prepare('INSERT INTO attendances (event_id, attendee_id, status) VALUES (?, ?, ?)');
    $stmt->execute([$eventId, $attendeeId, 'registered']);

    $ticketPrice = (float)$event['ticket_price'];
    if ($ticketPrice > 0) {
        $stmt = $pdo->prepare('UPDATE events SET ticket_revenue = ticket_revenue + ? WHERE event_id = ?');
        $stmt->execute([$ticketPrice, $eventId]);
    }

    $pdo->commit();

    $_SESSION['flash_success'] = 'Registration completed successfully.';
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $_SESSION['flash_error'] = $e->getMessage();
}

header('Location: my_events.php');
exit;
?>
