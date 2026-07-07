<?php
require_once '../config/db.php';
require_once '../includes/audit.php';
require_once '../includes/daraja.php';

header('Content-Type: application/json');

function get_client_ip_address(): string
{
    $candidates = [
        $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '',
        $_SERVER['HTTP_X_REAL_IP'] ?? '',
        $_SERVER['REMOTE_ADDR'] ?? '',
    ];

    foreach ($candidates as $value) {
        if (!is_string($value) || trim($value) === '') {
            continue;
        }

        $parts = explode(',', $value);
        foreach ($parts as $part) {
            $ip = trim($part);
            if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }
    }

    return '';
}

function ensure_callback_request_is_allowed(PDO $pdo): void
{
    $expectedToken = trim((string)(getenv('DARAJA_CALLBACK_TOKEN') ?: ''));
    if ($expectedToken !== '') {
        $providedToken = trim((string)($_SERVER['HTTP_X_DARAJA_CALLBACK_TOKEN'] ?? ($_GET['token'] ?? '')));
        if (!hash_equals($expectedToken, $providedToken)) {
            audit_log(
                $pdo,
                null,
                'system',
                'payment.callback_failed',
                'daraja',
                null,
                ['reason' => 'invalid_callback_token']
            );
            callback_response(403, ['ok' => false, 'message' => 'Forbidden callback token']);
        }
    }

    $allowedIpsRaw = trim((string)(getenv('DARAJA_CALLBACK_ALLOWED_IPS') ?: getenv('DARAJA_ALLOWED_IPS') ?: ''));
    if ($allowedIpsRaw !== '') {
        $allowedIps = array_filter(array_map('trim', explode(',', $allowedIpsRaw)));
        $clientIp = get_client_ip_address();
        if ($clientIp === '' || !in_array($clientIp, $allowedIps, true)) {
            audit_log(
                $pdo,
                null,
                'system',
                'payment.callback_failed',
                'daraja',
                null,
                [
                    'reason' => 'callback_ip_not_allowed',
                    'client_ip' => $clientIp,
                ]
            );
            callback_response(403, ['ok' => false, 'message' => 'Forbidden callback source']);
        }
    }
}

function callback_response(int $statusCode, array $payload): void
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        callback_response(405, ['ok' => false, 'message' => 'Method not allowed']);
    }

    ensure_daraja_stk_requests_table($pdo);
    ensure_callback_request_is_allowed($pdo);

    $rawBody = file_get_contents('php://input');
    if (!is_string($rawBody) || trim($rawBody) === '') {
        callback_response(400, ['ok' => false, 'message' => 'Empty callback payload']);
    }

    $payload = json_decode($rawBody, true);
    if (!is_array($payload)) {
        callback_response(400, ['ok' => false, 'message' => 'Invalid JSON payload']);
    }

    $stk = $payload['Body']['stkCallback'] ?? null;
    if (!is_array($stk)) {
        callback_response(400, ['ok' => false, 'message' => 'Missing stkCallback object']);
    }

    $checkoutRequestId = (string)($stk['CheckoutRequestID'] ?? '');
    $merchantRequestId = (string)($stk['MerchantRequestID'] ?? '');
    $resultCode = (int)($stk['ResultCode'] ?? -1);
    $resultDesc = (string)($stk['ResultDesc'] ?? 'No result description');

    if ($checkoutRequestId === '') {
        callback_response(400, ['ok' => false, 'message' => 'Missing CheckoutRequestID']);
    }

    $items = $stk['CallbackMetadata']['Item'] ?? [];
    $metaMap = [];
    if (is_array($items)) {
        foreach ($items as $item) {
            if (!is_array($item) || empty($item['Name'])) {
                continue;
            }
            $metaMap[(string)$item['Name']] = $item['Value'] ?? null;
        }
    }

    $mpesaReceipt = isset($metaMap['MpesaReceiptNumber']) ? (string)$metaMap['MpesaReceiptNumber'] : null;
    $amountFromCallback = isset($metaMap['Amount']) ? (float)$metaMap['Amount'] : 0.0;

    $stmt = $pdo->prepare(
        "SELECT booking_id, planner_user_id, status
         FROM daraja_stk_requests
         WHERE checkout_request_id = ?
         LIMIT 1"
    );
    $stmt->execute([$checkoutRequestId]);
    $stkRequest = $stmt->fetch();

    if (!$stkRequest) {
        audit_log(
            $pdo,
            null,
            'system',
            'payment.callback_failed',
            'daraja',
            $checkoutRequestId,
            [
                'reason' => 'checkout_request_not_found',
                'merchant_request_id' => $merchantRequestId,
                'result_code' => $resultCode,
                'result_desc' => $resultDesc,
            ]
        );

        callback_response(404, ['ok' => false, 'message' => 'Unknown checkout request']);
    }

    $bookingId = (int)$stkRequest['booking_id'];
    $plannerUserId = isset($stkRequest['planner_user_id']) ? (int)$stkRequest['planner_user_id'] : null;
    $existingRequestStatus = strtolower((string)($stkRequest['status'] ?? 'requested'));

    $incomingStatus = $resultCode === 0 ? 'paid' : 'failed';
    if (in_array($existingRequestStatus, ['paid', 'failed'], true)) {
        if ($existingRequestStatus === $incomingStatus) {
            callback_response(200, [
                'ok' => true,
                'message' => 'Duplicate callback ignored',
                'checkout_request_id' => $checkoutRequestId,
                'payment_status' => $existingRequestStatus,
            ]);
        }

        audit_log(
            $pdo,
            $plannerUserId,
            'system',
            'payment.callback_failed',
            'booking',
            (string)$bookingId,
            [
                'reason' => 'conflicting_terminal_callback',
                'checkout_request_id' => $checkoutRequestId,
                'existing_status' => $existingRequestStatus,
                'incoming_status' => $incomingStatus,
                'result_code' => $resultCode,
                'result_desc' => $resultDesc,
            ]
        );

        callback_response(200, [
            'ok' => true,
            'message' => 'Conflicting callback ignored',
            'checkout_request_id' => $checkoutRequestId,
            'payment_status' => $existingRequestStatus,
        ]);
    }

    $stmt = $pdo->prepare(
        "SELECT b.booking_id, b.event_id, b.status AS booking_status, b.booked_price,
                e.planner_id,
                t.status AS payment_status
         FROM bookings b
         JOIN events e ON e.event_id = b.event_id
         LEFT JOIN transactions t ON t.booking_id = b.booking_id
         WHERE b.booking_id = ?
         LIMIT 1"
    );
    $stmt->execute([$bookingId]);
    $booking = $stmt->fetch();

    if (!$booking) {
        audit_log(
            $pdo,
            $plannerUserId,
            'system',
            'payment.callback_failed',
            'booking',
            (string)$bookingId,
            [
                'reason' => 'booking_not_found',
                'checkout_request_id' => $checkoutRequestId,
                'result_code' => $resultCode,
            ]
        );

        callback_response(404, ['ok' => false, 'message' => 'Booking not found']);
    }

    $oldBookingStatus = strtolower((string)$booking['booking_status']);
    $bookedPrice = (float)$booking['booked_price'];
    $paymentStatus = $incomingStatus;
    $newBookingStatus = $paymentStatus === 'paid' ? 'confirmed' : 'pending';
    $platformFee = $bookedPrice * 0.10;

    $transactionAmount = $amountFromCallback > 0 ? $amountFromCallback : $bookedPrice;

    $pdo->beginTransaction();

    $stmt = $pdo->prepare(
        "UPDATE daraja_stk_requests
         SET merchant_request_id = ?,
             status = ?,
             callback_payload = ?
         WHERE checkout_request_id = ?"
    );
    $stmt->execute([
        $merchantRequestId !== '' ? $merchantRequestId : null,
        $paymentStatus,
        json_encode($payload, JSON_UNESCAPED_SLASHES),
        $checkoutRequestId,
    ]);

    $stmt = $pdo->prepare('SELECT booking_id FROM transactions WHERE booking_id = ? LIMIT 1');
    $stmt->execute([$bookingId]);

    if ($stmt->fetch()) {
        $stmt = $pdo->prepare('UPDATE transactions SET mpesa_code = ?, amount = ?, status = ? WHERE booking_id = ?');
        $stmt->execute([$mpesaReceipt, $transactionAmount, $paymentStatus, $bookingId]);
    } else {
        $stmt = $pdo->prepare('INSERT INTO transactions (booking_id, mpesa_code, amount, status) VALUES (?, ?, ?, ?)');
        $stmt->execute([$bookingId, $mpesaReceipt, $transactionAmount, $paymentStatus]);
    }

    $stmt = $pdo->prepare('UPDATE bookings SET status = ?, platform_fee = ? WHERE booking_id = ?');
    $stmt->execute([$newBookingStatus, $newBookingStatus === 'confirmed' ? $platformFee : 0, $bookingId]);

    if ($newBookingStatus === 'confirmed' && $oldBookingStatus !== 'confirmed') {
        $stmt = $pdo->prepare('UPDATE events SET budget_committed = budget_committed + ? WHERE event_id = ?');
        $stmt->execute([$bookedPrice, (int)$booking['event_id']]);
    } elseif ($newBookingStatus !== 'confirmed' && $oldBookingStatus === 'confirmed') {
        $stmt = $pdo->prepare('UPDATE events SET budget_committed = GREATEST(0, budget_committed - ?) WHERE event_id = ?');
        $stmt->execute([$bookedPrice, (int)$booking['event_id']]);
    }

    $pdo->commit();

    audit_log(
        $pdo,
        $plannerUserId,
        'system',
        'payment.callback_processed',
        'booking',
        (string)$bookingId,
        [
            'checkout_request_id' => $checkoutRequestId,
            'merchant_request_id' => $merchantRequestId,
            'result_code' => $resultCode,
            'result_desc' => $resultDesc,
            'payment_status' => $paymentStatus,
            'booking_status' => $newBookingStatus,
            'mpesa_receipt' => $mpesaReceipt,
            'amount' => $transactionAmount,
        ]
    );

    callback_response(200, [
        'ok' => true,
        'message' => 'Callback processed',
        'checkout_request_id' => $checkoutRequestId,
        'booking_id' => $bookingId,
        'payment_status' => $paymentStatus,
    ]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    if (isset($pdo) && $pdo instanceof PDO) {
        audit_log(
            $pdo,
            null,
            'system',
            'payment.callback_failed',
            'daraja',
            null,
            ['reason' => 'exception', 'error' => $e->getMessage()]
        );
    }

    error_log('admin/mpesa_callback.php error: ' . $e->getMessage());
    callback_response(500, ['ok' => false, 'message' => 'Internal callback processing error']);
}
