<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/daraja.php';

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

function ensure_stall_rentals_callback_schema(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS stall_rentals (
            rental_id BIGINT AUTO_INCREMENT PRIMARY KEY,
            event_id INT NOT NULL,
            vendor_user_id INT NOT NULL,
            created_by_planner INT DEFAULT NULL,
            stall_label VARCHAR(80) DEFAULT NULL,
            amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            checkout_request_id VARCHAR(120) DEFAULT NULL,
            merchant_request_id VARCHAR(120) DEFAULT NULL,
            phone_number VARCHAR(20) DEFAULT NULL,
            mpesa_code VARCHAR(64) DEFAULT NULL,
            payment_status ENUM('pending', 'paid', 'failed', 'cancelled') NOT NULL DEFAULT 'pending',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_stall_rentals_event_vendor (event_id, vendor_user_id),
            UNIQUE KEY uq_stall_rentals_checkout (checkout_request_id),
            INDEX idx_stall_rentals_event_status (event_id, payment_status),
            INDEX idx_stall_rentals_vendor (vendor_user_id),
            CONSTRAINT fk_stall_rentals_event FOREIGN KEY (event_id) REFERENCES events(event_id) ON DELETE CASCADE,
            CONSTRAINT fk_stall_rentals_vendor_user FOREIGN KEY (vendor_user_id) REFERENCES users(user_id) ON DELETE CASCADE,
            CONSTRAINT fk_stall_rentals_planner_user FOREIGN KEY (created_by_planner) REFERENCES users(user_id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $stmt = $pdo->query("SHOW COLUMNS FROM stall_rentals LIKE 'checkout_request_id'");
    if (!$stmt->fetch()) {
        $pdo->exec("ALTER TABLE stall_rentals ADD COLUMN checkout_request_id VARCHAR(120) DEFAULT NULL AFTER amount");
    }

    $stmt = $pdo->query("SHOW COLUMNS FROM stall_rentals LIKE 'merchant_request_id'");
    if (!$stmt->fetch()) {
        $pdo->exec("ALTER TABLE stall_rentals ADD COLUMN merchant_request_id VARCHAR(120) DEFAULT NULL AFTER checkout_request_id");
    }

    $stmt = $pdo->query("SHOW COLUMNS FROM stall_rentals LIKE 'phone_number'");
    if (!$stmt->fetch()) {
        $pdo->exec("ALTER TABLE stall_rentals ADD COLUMN phone_number VARCHAR(20) DEFAULT NULL AFTER merchant_request_id");
    }

    $stmt = $pdo->query("SHOW COLUMNS FROM stall_rentals LIKE 'payment_status'");
    if (!$stmt->fetch()) {
        $pdo->exec("ALTER TABLE stall_rentals ADD COLUMN payment_status ENUM('pending', 'paid', 'failed', 'cancelled') NOT NULL DEFAULT 'pending' AFTER mpesa_code");
    }

    $stmt = $pdo->query("SHOW COLUMNS FROM stall_rentals LIKE 'status'");
    if (!$stmt->fetch()) {
        $pdo->exec("ALTER TABLE stall_rentals ADD COLUMN status ENUM('requested', 'paid', 'failed', 'cancelled') NOT NULL DEFAULT 'requested' AFTER mpesa_code");
    }

    $pdo->exec(
        "UPDATE stall_rentals
         SET payment_status = CASE LOWER(COALESCE(status, 'requested'))
             WHEN 'paid' THEN 'paid'
             WHEN 'failed' THEN 'failed'
             WHEN 'cancelled' THEN 'cancelled'
             ELSE 'pending'
         END"
    );

    try {
        $pdo->exec("CREATE UNIQUE INDEX uq_stall_rentals_checkout ON stall_rentals (checkout_request_id)");
    } catch (Throwable $e) {
        // Ignore if index already exists.
    }

    $ready = true;
}

function ensure_vendor_fee_payments_callback_schema(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS vendor_fee_payments (
            payment_id BIGINT AUTO_INCREMENT PRIMARY KEY,
            event_id INT NOT NULL,
            vendor_user_id INT NOT NULL,
            checkout_request_id VARCHAR(120) DEFAULT NULL,
            merchant_request_id VARCHAR(120) DEFAULT NULL,
            phone_number VARCHAR(20) DEFAULT NULL,
            mpesa_code VARCHAR(64) DEFAULT NULL,
            amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            status ENUM('requested', 'paid', 'failed') NOT NULL DEFAULT 'requested',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_vendor_fee_payment_unique (event_id, vendor_user_id),
            UNIQUE KEY uq_vendor_fee_checkout (checkout_request_id),
            INDEX idx_vendor_fee_payment_event_status (event_id, status),
            INDEX idx_vendor_fee_payment_vendor (vendor_user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    try {
        $pdo->exec("CREATE UNIQUE INDEX uq_vendor_fee_checkout ON vendor_fee_payments (checkout_request_id)");
    } catch (Throwable $e) {
        // Ignore if index already exists.
    }

    $ready = true;
}

function ensure_attendee_ticket_payments_callback_schema(PDO $pdo): void
{
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
            UNIQUE KEY uq_attendee_ticket_payment (event_id, attendee_id),
            INDEX idx_attendee_ticket_payment_event_status (event_id, status),
            INDEX idx_attendee_ticket_payment_attendee (attendee_id),
            INDEX idx_attendee_ticket_payment_checkout (checkout_request_id),
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
        // Ignore if index already exists.
    }

    $ready = true;
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        callback_response(405, ['ok' => false, 'message' => 'Method not allowed']);
    }

    ensure_daraja_stk_requests_table($pdo);
    ensure_stall_rentals_callback_schema($pdo);
    ensure_vendor_fee_payments_callback_schema($pdo);
    ensure_attendee_ticket_payments_callback_schema($pdo);
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
        $stmt = $pdo->prepare(
            "SELECT sr.rental_id, sr.event_id, sr.vendor_user_id,
                    COALESCE(sr.payment_status,
                        CASE LOWER(COALESCE(sr.status, 'requested'))
                            WHEN 'paid' THEN 'paid'
                            WHEN 'failed' THEN 'failed'
                            WHEN 'cancelled' THEN 'cancelled'
                            ELSE 'pending'
                        END
                    ) AS payment_status,
                    e.planner_id
             FROM stall_rentals sr
             JOIN events e ON e.event_id = sr.event_id
             WHERE sr.checkout_request_id = ?
             LIMIT 1"
        );
        $stmt->execute([$checkoutRequestId]);
        $stallRequest = $stmt->fetch();

        if (!$stallRequest) {
            $stmt = $pdo->prepare(
                "SELECT vfp.payment_id, vfp.event_id, vfp.vendor_user_id, vfp.status, e.planner_id
                 FROM vendor_fee_payments vfp
                 JOIN events e ON e.event_id = vfp.event_id
                 WHERE vfp.checkout_request_id = ?
                 LIMIT 1"
            );
            $stmt->execute([$checkoutRequestId]);
            $vendorFeeRequest = $stmt->fetch();

            if (!$vendorFeeRequest) {
                $stmt = $pdo->prepare(
                    "SELECT atp.payment_id, atp.event_id, atp.attendee_id, atp.ticket_type, atp.amount, atp.status,
                            e.planner_id
                     FROM attendee_ticket_payments atp
                     JOIN events e ON e.event_id = atp.event_id
                     WHERE atp.checkout_request_id = ?
                     LIMIT 1"
                );
                $stmt->execute([$checkoutRequestId]);
                $attendeePaymentRequest = $stmt->fetch();

                if (!$attendeePaymentRequest) {
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

                $attendeePaymentId = (int)$attendeePaymentRequest['payment_id'];
                $plannerUserId = isset($attendeePaymentRequest['planner_id']) ? (int)$attendeePaymentRequest['planner_id'] : null;
                $existingStatus = strtolower((string)($attendeePaymentRequest['status'] ?? 'requested'));
                $incomingStatus = $resultCode === 0 ? 'paid' : 'failed';

                if (in_array($existingStatus, ['paid', 'failed'], true)) {
                    if ($existingStatus === $incomingStatus) {
                        callback_response(200, [
                            'ok' => true,
                            'message' => 'Duplicate callback ignored',
                            'checkout_request_id' => $checkoutRequestId,
                            'payment_status' => $existingStatus,
                        ]);
                    }

                    audit_log(
                        $pdo,
                        $plannerUserId,
                        'system',
                        'payment.callback_failed',
                        'attendee_ticket_payment',
                        (string)$attendeePaymentId,
                        [
                            'reason' => 'conflicting_terminal_callback',
                            'checkout_request_id' => $checkoutRequestId,
                            'existing_status' => $existingStatus,
                            'incoming_status' => $incomingStatus,
                            'result_code' => $resultCode,
                            'result_desc' => $resultDesc,
                        ]
                    );

                    callback_response(200, [
                        'ok' => true,
                        'message' => 'Conflicting callback ignored',
                        'checkout_request_id' => $checkoutRequestId,
                        'payment_status' => $existingStatus,
                    ]);
                }

                $eventId = (int)$attendeePaymentRequest['event_id'];
                $attendeeId = (int)$attendeePaymentRequest['attendee_id'];
                $ticketType = strtolower(trim((string)($attendeePaymentRequest['ticket_type'] ?? 'regular')));
                if ($ticketType === '') {
                    $ticketType = 'regular';
                }
                $ticketAmount = (float)($attendeePaymentRequest['amount'] ?? 0);

                $pdo->beginTransaction();

                $stmt = $pdo->prepare(
                    "UPDATE attendee_ticket_payments
                     SET merchant_request_id = ?,
                         status = ?,
                         mpesa_code = ?,
                         updated_at = CURRENT_TIMESTAMP
                     WHERE payment_id = ?"
                );
                $stmt->execute([
                    $merchantRequestId !== '' ? $merchantRequestId : null,
                    $incomingStatus,
                    $mpesaReceipt,
                    $attendeePaymentId,
                ]);

                $registeredNow = false;
                if ($incomingStatus === 'paid') {
                    $stmt = $pdo->prepare("SELECT attendance_id FROM attendances WHERE event_id = ? AND attendee_id = ? LIMIT 1 FOR UPDATE");
                    $stmt->execute([$eventId, $attendeeId]);
                    $attendanceExists = (bool)$stmt->fetchColumn();

                    if (!$attendanceExists) {
                        $stmt = $pdo->prepare("INSERT INTO attendances (event_id, attendee_id) VALUES (?, ?)");
                        $stmt->execute([$eventId, $attendeeId]);
                        $registeredNow = true;

                        $stmt = $pdo->prepare("UPDATE event_ticket_types SET tickets_remaining = GREATEST(0, tickets_remaining - 1) WHERE event_id = ? AND ticket_type = ? LIMIT 1");
                        $stmt->execute([$eventId, $ticketType]);

                        $stmt = $pdo->prepare("UPDATE events SET tickets_available = GREATEST(0, tickets_available - 1) WHERE event_id = ?");
                        $stmt->execute([$eventId]);
                    }

                    if ($existingStatus !== 'paid' && $ticketAmount > 0) {
                        $stmt = $pdo->prepare("UPDATE events SET ticket_revenue = ticket_revenue + ? WHERE event_id = ?");
                        $stmt->execute([$ticketAmount, $eventId]);
                    }
                }

                $pdo->commit();

                audit_log(
                    $pdo,
                    $plannerUserId,
                    'system',
                    'payment.callback_processed',
                    'attendee_ticket_payment',
                    (string)$attendeePaymentId,
                    [
                        'checkout_request_id' => $checkoutRequestId,
                        'merchant_request_id' => $merchantRequestId,
                        'result_code' => $resultCode,
                        'result_desc' => $resultDesc,
                        'payment_status' => $incomingStatus,
                        'mpesa_receipt' => $mpesaReceipt,
                        'charged_amount' => $amountFromCallback,
                        'ticket_amount' => $ticketAmount,
                        'registered_now' => $registeredNow,
                        'ticket_type' => $ticketType,
                    ]
                );

                callback_response(200, [
                    'ok' => true,
                    'message' => 'Attendee ticket callback processed',
                    'checkout_request_id' => $checkoutRequestId,
                    'payment_id' => $attendeePaymentId,
                    'payment_status' => $incomingStatus,
                ]);
            }

            $vendorFeePaymentId = (int)$vendorFeeRequest['payment_id'];
            $plannerUserId = isset($vendorFeeRequest['planner_id']) ? (int)$vendorFeeRequest['planner_id'] : null;
            $existingStatus = strtolower((string)($vendorFeeRequest['status'] ?? 'requested'));
            $incomingStatus = $resultCode === 0 ? 'paid' : 'failed';

            if (in_array($existingStatus, ['paid', 'failed'], true)) {
                if ($existingStatus === $incomingStatus) {
                    callback_response(200, [
                        'ok' => true,
                        'message' => 'Duplicate callback ignored',
                        'checkout_request_id' => $checkoutRequestId,
                        'payment_status' => $existingStatus,
                    ]);
                }

                audit_log(
                    $pdo,
                    $plannerUserId,
                    'system',
                    'payment.callback_failed',
                    'vendor_fee_payment',
                    (string)$vendorFeePaymentId,
                    [
                        'reason' => 'conflicting_terminal_callback',
                        'checkout_request_id' => $checkoutRequestId,
                        'existing_status' => $existingStatus,
                        'incoming_status' => $incomingStatus,
                        'result_code' => $resultCode,
                        'result_desc' => $resultDesc,
                    ]
                );

                callback_response(200, [
                    'ok' => true,
                    'message' => 'Conflicting callback ignored',
                    'checkout_request_id' => $checkoutRequestId,
                    'payment_status' => $existingStatus,
                ]);
            }

            $pdo->beginTransaction();

            $stmt = $pdo->prepare(
                "UPDATE vendor_fee_payments
                 SET merchant_request_id = ?,
                     status = ?,
                     mpesa_code = ?,
                     updated_at = CURRENT_TIMESTAMP
                 WHERE payment_id = ?"
            );
            $stmt->execute([
                $merchantRequestId !== '' ? $merchantRequestId : null,
                $incomingStatus,
                $mpesaReceipt,
                $vendorFeePaymentId,
            ]);

            $pdo->commit();

            audit_log(
                $pdo,
                $plannerUserId,
                'system',
                'payment.callback_processed',
                'vendor_fee_payment',
                (string)$vendorFeePaymentId,
                [
                    'checkout_request_id' => $checkoutRequestId,
                    'merchant_request_id' => $merchantRequestId,
                    'result_code' => $resultCode,
                    'result_desc' => $resultDesc,
                    'payment_status' => $incomingStatus,
                    'mpesa_receipt' => $mpesaReceipt,
                    'amount' => $amountFromCallback,
                ]
            );

            callback_response(200, [
                'ok' => true,
                'message' => 'Vendor fee callback processed',
                'checkout_request_id' => $checkoutRequestId,
                'payment_id' => $vendorFeePaymentId,
                'payment_status' => $incomingStatus,
            ]);
        }

        $rentalId = (int)$stallRequest['rental_id'];
        $plannerUserId = isset($stallRequest['planner_id']) ? (int)$stallRequest['planner_id'] : null;
        $existingStatus = strtolower((string)($stallRequest['payment_status'] ?? 'pending'));
        $incomingStatus = $resultCode === 0 ? 'paid' : 'failed';

        if (in_array($existingStatus, ['paid', 'failed'], true)) {
            if ($existingStatus === $incomingStatus) {
                callback_response(200, [
                    'ok' => true,
                    'message' => 'Duplicate callback ignored',
                    'checkout_request_id' => $checkoutRequestId,
                    'payment_status' => $existingStatus,
                ]);
            }

            audit_log(
                $pdo,
                $plannerUserId,
                'system',
                'payment.callback_failed',
                'stall_rental',
                (string)$rentalId,
                [
                    'reason' => 'conflicting_terminal_callback',
                    'checkout_request_id' => $checkoutRequestId,
                    'existing_status' => $existingStatus,
                    'incoming_status' => $incomingStatus,
                    'result_code' => $resultCode,
                    'result_desc' => $resultDesc,
                ]
            );

            callback_response(200, [
                'ok' => true,
                'message' => 'Conflicting callback ignored',
                'checkout_request_id' => $checkoutRequestId,
                'payment_status' => $existingStatus,
            ]);
        }

        $pdo->beginTransaction();

        $stmt = $pdo->prepare(
            "UPDATE stall_rentals
             SET merchant_request_id = ?,
                 payment_status = ?,
                 mpesa_code = ?,
                 updated_at = CURRENT_TIMESTAMP
             WHERE rental_id = ?"
        );
        $stmt->execute([
            $merchantRequestId !== '' ? $merchantRequestId : null,
            $incomingStatus,
            $mpesaReceipt,
            $rentalId,
        ]);

        $pdo->commit();

        audit_log(
            $pdo,
            $plannerUserId,
            'system',
            'payment.callback_processed',
            'stall_rental',
            (string)$rentalId,
            [
                'checkout_request_id' => $checkoutRequestId,
                'merchant_request_id' => $merchantRequestId,
                'result_code' => $resultCode,
                'result_desc' => $resultDesc,
                'payment_status' => $incomingStatus,
                'mpesa_receipt' => $mpesaReceipt,
                'amount' => $amountFromCallback,
            ]
        );

        callback_response(200, [
            'ok' => true,
            'message' => 'Stall callback processed',
            'checkout_request_id' => $checkoutRequestId,
            'rental_id' => $rentalId,
            'payment_status' => $incomingStatus,
        ]);
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
