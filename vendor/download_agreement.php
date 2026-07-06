<?php
require_once '../includes/auth.php';
checkAuth();
requireRole('vendor');

$bookingId = filter_input(INPUT_GET, 'booking_id', FILTER_VALIDATE_INT);
if (!$bookingId) {
    $_SESSION['flash_error'] = 'Invalid booking selected.';
    header('Location: bookings.php');
    exit;
}

function ensureBookingDisplaySchema(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }

    $stmt = $pdo->query("SHOW COLUMNS FROM events LIKE 'venue'");
    if (!$stmt->fetch()) {
        $pdo->exec("ALTER TABLE events ADD COLUMN venue VARCHAR(190) DEFAULT NULL AFTER event_date");
    }

    $stmt = $pdo->query("SHOW COLUMNS FROM bookings LIKE 'booth_number'");
    if (!$stmt->fetch()) {
        $pdo->exec("ALTER TABLE bookings ADD COLUMN booth_number VARCHAR(64) DEFAULT NULL AFTER platform_fee");
    }

    $ready = true;
}

try {
    $stmt = $pdo->prepare('SELECT vendor_id, business_name FROM vendors WHERE user_id = ? LIMIT 1');
    $stmt->execute([$_SESSION['user_id']]);
    $vendor = $stmt->fetch();
    if (!$vendor) {
        throw new RuntimeException('Vendor profile not found.');
    }

    ensureBookingDisplaySchema($pdo);

    $stmt = $pdo->prepare(
        "SELECT b.booking_id, b.status, b.booked_price, b.platform_fee, b.booth_number,
                e.title AS event_title, e.event_date, COALESCE(e.venue, '') AS event_venue,
                s.name AS service_name,
                u.full_name AS organizer_name
         FROM bookings b
         JOIN services s ON s.service_id = b.service_id
         JOIN events e ON e.event_id = b.event_id
         JOIN users u ON u.user_id = e.planner_id
         WHERE b.booking_id = ? AND s.vendor_id = ?
         LIMIT 1"
    );
    $stmt->execute([$bookingId, (int)$vendor['vendor_id']]);
    $booking = $stmt->fetch();

    if (!$booking) {
        $_SESSION['flash_error'] = 'Booking not found.';
        header('Location: bookings.php');
        exit;
    }

    $gross = (float)$booking['booked_price'];
    $fee = (float)($booking['platform_fee'] ?? 0);
    $net = $gross - $fee;

    $lines = [];
    $lines[] = 'PLANORA VENDOR BOOKING AGREEMENT';
    $lines[] = str_repeat('=', 42);
    $lines[] = 'Agreement Date: ' . date('Y-m-d H:i:s');
    $lines[] = 'Vendor: ' . (string)($vendor['business_name'] ?? 'Vendor');
    $lines[] = 'Organizer: ' . (string)$booking['organizer_name'];
    $lines[] = 'Event: ' . (string)$booking['event_title'];
    $lines[] = 'Event Date: ' . (string)$booking['event_date'];
    $lines[] = 'Venue: ' . ((string)($booking['event_venue'] ?? '') !== '' ? (string)$booking['event_venue'] : 'Not specified');
    $lines[] = 'Service: ' . (string)$booking['service_name'];
    $lines[] = 'Booking Status: ' . ucfirst((string)$booking['status']);
    $lines[] = 'Booth Number: ' . ((string)($booking['booth_number'] ?? '') !== '' ? (string)$booking['booth_number'] : 'Not assigned');
    $lines[] = 'Gross Amount: KES ' . number_format($gross, 2);
    $lines[] = 'Fee: KES ' . number_format($fee, 2);
    $lines[] = 'Amount Payable: KES ' . number_format($net, 2);
    $lines[] = '';
    $lines[] = 'Terms:';
    $lines[] = '1. Vendor shall deliver the listed service on the event date.';
    $lines[] = '2. Any schedule or logistics updates should be communicated via Planora messaging.';
    $lines[] = '3. Payment processing follows the booking and transaction records in Planora.';

    $content = implode(PHP_EOL, $lines) . PHP_EOL;
    $filename = 'booking_agreement_' . (int)$bookingId . '_' . date('Ymd_His') . '.txt';

    header('Content-Type: text/plain; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($content));

    echo $content;
    exit;
} catch (Throwable $e) {
    error_log('vendor/download_agreement.php error: ' . $e->getMessage());
    $_SESSION['flash_error'] = 'Unable to generate agreement right now.';
    header('Location: bookings.php');
    exit;
}
