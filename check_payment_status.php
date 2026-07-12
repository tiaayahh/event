<?php
require_once 'includes/auth.php';
require_once 'includes/daraja.php';
checkAuth();

header('Content-Type: application/json');

function normalizePaymentStatus(string $raw): string
{
    $status = strtolower(trim($raw));
    if (in_array($status, ['paid', 'completed', 'success'], true)) {
        return 'paid';
    }
    if (in_array($status, ['failed', 'cancelled', 'canceled', 'error'], true)) {
        return 'failed';
    }

    return 'requested';
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        http_response_code(405);
        echo json_encode([
            'ok' => false,
            'message' => 'Method not allowed.',
        ], JSON_UNESCAPED_SLASHES);
        exit;
    }

    $checkoutRequestId = trim((string)($_GET['checkout_request_id'] ?? ''));
    if ($checkoutRequestId === '') {
        http_response_code(400);
        echo json_encode([
            'ok' => false,
            'message' => 'checkout_request_id is required.',
        ], JSON_UNESCAPED_SLASHES);
        exit;
    }

    $role = (string)($_SESSION['role'] ?? '');
    $userId = (int)($_SESSION['user_id'] ?? 0);

    if ($role === 'vendor') {
        $stmt = $pdo->prepare(
            "SELECT event_id, status, mpesa_code, updated_at
             FROM vendor_fee_payments
             WHERE checkout_request_id = ?
               AND vendor_user_id = ?
             LIMIT 1"
        );
        $stmt->execute([$checkoutRequestId, $userId]);
        $row = $stmt->fetch();

        if (!$row) {
            http_response_code(404);
            echo json_encode([
                'ok' => false,
                'message' => 'Payment record not found.',
            ], JSON_UNESCAPED_SLASHES);
            exit;
        }

        $status = normalizePaymentStatus((string)($row['status'] ?? 'requested'));

        echo json_encode([
            'ok' => true,
            'payment_type' => 'vendor_fee',
            'event_id' => (int)$row['event_id'],
            'checkout_request_id' => $checkoutRequestId,
            'status' => $status,
            'mpesa_code' => (string)($row['mpesa_code'] ?? ''),
            'updated_at' => (string)($row['updated_at'] ?? ''),
            'terminal' => in_array($status, ['paid', 'failed'], true),
        ], JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($role === 'attendee') {
        $stmt = $pdo->prepare('SELECT attendee_id FROM attendees WHERE user_id = ? LIMIT 1');
        $stmt->execute([$userId]);
        $attendeeId = (int)($stmt->fetchColumn() ?: 0);
        if ($attendeeId <= 0) {
            http_response_code(404);
            echo json_encode([
                'ok' => false,
                'message' => 'Attendee profile not found.',
            ], JSON_UNESCAPED_SLASHES);
            exit;
        }

        $stmt = $pdo->prepare(
            "SELECT payment_id, event_id, ticket_type, amount, status, mpesa_code, updated_at
             FROM attendee_ticket_payments
             WHERE checkout_request_id = ?
               AND attendee_id = ?
                         ORDER BY payment_id DESC
             LIMIT 1"
        );
        $stmt->execute([$checkoutRequestId, $attendeeId]);
        $row = $stmt->fetch();

        if (!$row) {
            http_response_code(404);
            echo json_encode([
                'ok' => false,
                'message' => 'Payment record not found.',
            ], JSON_UNESCAPED_SLASHES);
            exit;
        }

        $status = normalizePaymentStatus((string)($row['status'] ?? 'requested'));

        // Fallback when callback is delayed: query Daraja and reconcile attendee payment.
        if ($status === 'requested' && daraja_is_stk_configured()) {
            $queryResult = daraja_stk_query($checkoutRequestId);
            if (!empty($queryResult['success'])) {
                $queryStatus = normalizePaymentStatus((string)($queryResult['status'] ?? 'requested'));
                if (in_array($queryStatus, ['paid', 'failed'], true)) {
                    $pdo->beginTransaction();
                    try {
                        $stmt = $pdo->prepare(
                            "SELECT payment_id, event_id, attendee_id, ticket_type, amount, status
                             FROM attendee_ticket_payments
                             WHERE payment_id = ?
                             LIMIT 1
                             FOR UPDATE"
                        );
                        $stmt->execute([(int)$row['payment_id']]);
                        $locked = $stmt->fetch();

                        if ($locked) {
                            $existingStatus = normalizePaymentStatus((string)($locked['status'] ?? 'requested'));
                            if ($existingStatus === 'requested') {
                                $stmt = $pdo->prepare(
                                    "UPDATE attendee_ticket_payments
                                     SET status = ?, updated_at = CURRENT_TIMESTAMP
                                     WHERE payment_id = ?"
                                );
                                $stmt->execute([$queryStatus, (int)$locked['payment_id']]);

                                if ($queryStatus === 'paid') {
                                    $eventId = (int)$locked['event_id'];
                                    $ticketType = strtolower(trim((string)($locked['ticket_type'] ?? 'regular')));
                                    if ($ticketType === '') {
                                        $ticketType = 'regular';
                                    }
                                    $ticketAmount = (float)($locked['amount'] ?? 0);

                                    $stmt = $pdo->prepare(
                                        "SELECT attendance_id
                                         FROM attendances
                                         WHERE event_id = ? AND attendee_id = ?
                                         LIMIT 1
                                         FOR UPDATE"
                                    );
                                    $stmt->execute([$eventId, $attendeeId]);
                                    $attendanceExists = (bool)$stmt->fetchColumn();

                                    if (!$attendanceExists) {
                                        $stmt = $pdo->prepare('INSERT INTO attendances (event_id, attendee_id) VALUES (?, ?)');
                                        $stmt->execute([$eventId, $attendeeId]);
                                    }

                                    $stmt = $pdo->prepare(
                                        "UPDATE event_ticket_types
                                         SET tickets_remaining = GREATEST(0, tickets_remaining - 1)
                                         WHERE event_id = ? AND ticket_type = ?
                                         LIMIT 1"
                                    );
                                    $stmt->execute([$eventId, $ticketType]);

                                    $stmt = $pdo->prepare('UPDATE events SET tickets_available = GREATEST(0, tickets_available - 1) WHERE event_id = ?');
                                    $stmt->execute([$eventId]);

                                    if ($ticketAmount > 0) {
                                        $stmt = $pdo->prepare('UPDATE events SET ticket_revenue = ticket_revenue + ? WHERE event_id = ?');
                                        $stmt->execute([$ticketAmount, $eventId]);
                                    }
                                }
                            }
                        }

                        $pdo->commit();
                    } catch (Throwable $txe) {
                        if ($pdo->inTransaction()) {
                            $pdo->rollBack();
                        }
                    }

                    $stmt = $pdo->prepare(
                        "SELECT event_id, ticket_type, status, mpesa_code, updated_at
                         FROM attendee_ticket_payments
                         WHERE checkout_request_id = ?
                           AND attendee_id = ?
                                                 ORDER BY payment_id DESC
                         LIMIT 1"
                    );
                    $stmt->execute([$checkoutRequestId, $attendeeId]);
                    $updatedRow = $stmt->fetch();
                    if ($updatedRow) {
                        $row = array_merge($row, $updatedRow);
                        $status = normalizePaymentStatus((string)($updatedRow['status'] ?? 'requested'));
                    }
                }
            }
        }

        echo json_encode([
            'ok' => true,
            'payment_type' => 'attendee_ticket',
            'event_id' => (int)$row['event_id'],
            'ticket_type' => (string)($row['ticket_type'] ?? ''),
            'checkout_request_id' => $checkoutRequestId,
            'status' => $status,
            'mpesa_code' => (string)($row['mpesa_code'] ?? ''),
            'updated_at' => (string)($row['updated_at'] ?? ''),
            'terminal' => in_array($status, ['paid', 'failed'], true),
        ], JSON_UNESCAPED_SLASHES);
        exit;
    }

    http_response_code(403);
    echo json_encode([
        'ok' => false,
        'message' => 'Unsupported role for payment status checks.',
    ], JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'message' => 'Unable to check payment status right now.',
    ], JSON_UNESCAPED_SLASHES);
}
