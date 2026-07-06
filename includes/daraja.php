<?php

if (!function_exists('daraja_config')) {
    function daraja_config(): array
    {
        $env = strtolower((string)(getenv('DARAJA_ENV') ?: 'sandbox'));
        $isLive = $env === 'live';

        return [
            'env' => $env,
            'consumer_key' => trim((string)(getenv('DARAJA_CONSUMER_KEY') ?: '')),
            'consumer_secret' => trim((string)(getenv('DARAJA_CONSUMER_SECRET') ?: '')),
            'shortcode' => trim((string)(getenv('DARAJA_SHORTCODE') ?: '')),
            'passkey' => trim((string)(getenv('DARAJA_PASSKEY') ?: '')),
            'callback_url' => trim((string)(getenv('DARAJA_CALLBACK_URL') ?: '')),
            'oauth_url' => $isLive
                ? 'https://api.safaricom.co.ke/oauth/v1/generate?grant_type=client_credentials'
                : 'https://sandbox.safaricom.co.ke/oauth/v1/generate?grant_type=client_credentials',
            'stk_push_url' => $isLive
                ? 'https://api.safaricom.co.ke/mpesa/stkpush/v1/processrequest'
                : 'https://sandbox.safaricom.co.ke/mpesa/stkpush/v1/processrequest',
        ];
    }
}

if (!function_exists('daraja_is_configured')) {
    function daraja_is_configured(): bool
    {
        $cfg = daraja_config();

        return $cfg['consumer_key'] !== ''
            && $cfg['consumer_secret'] !== ''
            && $cfg['shortcode'] !== ''
            && $cfg['passkey'] !== ''
            && $cfg['callback_url'] !== '';
    }
}

if (!function_exists('daraja_normalize_phone')) {
    function daraja_normalize_phone(string $raw): string
    {
        $digits = preg_replace('/\D+/', '', $raw);

        if ($digits === null) {
            return '';
        }

        if (strpos($digits, '254') === 0 && strlen($digits) === 12) {
            return $digits;
        }

        if (strpos($digits, '0') === 0 && strlen($digits) === 10) {
            return '254' . substr($digits, 1);
        }

        return '';
    }
}

if (!function_exists('daraja_get_access_token')) {
    function daraja_get_access_token(array $cfg): array
    {
        $auth = base64_encode($cfg['consumer_key'] . ':' . $cfg['consumer_secret']);

        $ch = curl_init($cfg['oauth_url']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Basic ' . $auth,
        ]);

        $raw = curl_exec($ch);
        $err = curl_error($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($raw === false || $err !== '') {
            return ['success' => false, 'message' => 'Failed to connect to Daraja token endpoint.'];
        }

        $payload = json_decode((string)$raw, true);
        if (!is_array($payload) || empty($payload['access_token'])) {
            return ['success' => false, 'message' => 'Unable to retrieve Daraja access token.'];
        }

        if ($httpCode >= 400) {
            return ['success' => false, 'message' => 'Daraja token request failed with HTTP ' . $httpCode . '.'];
        }

        return ['success' => true, 'token' => (string)$payload['access_token']];
    }
}

if (!function_exists('daraja_stk_push')) {
    function daraja_stk_push(string $phoneNumber, float $amount, string $accountReference, string $transactionDesc): array
    {
        $cfg = daraja_config();
        if (!daraja_is_configured()) {
            return ['success' => false, 'message' => 'Daraja is not configured. Set DARAJA_* environment variables.'];
        }

        $normalizedPhone = daraja_normalize_phone($phoneNumber);
        if ($normalizedPhone === '') {
            return ['success' => false, 'message' => 'Invalid phone number. Use format 07XXXXXXXX or 2547XXXXXXXX.'];
        }

        $amountInt = (int)round($amount);
        if ($amountInt <= 0) {
            return ['success' => false, 'message' => 'Amount must be greater than zero.'];
        }

        $timestamp = gmdate('YmdHis');
        $password = base64_encode($cfg['shortcode'] . $cfg['passkey'] . $timestamp);

        $tokenResult = daraja_get_access_token($cfg);
        if (empty($tokenResult['success'])) {
            return $tokenResult;
        }

        $requestBody = [
            'BusinessShortCode' => $cfg['shortcode'],
            'Password' => $password,
            'Timestamp' => $timestamp,
            'TransactionType' => 'CustomerPayBillOnline',
            'Amount' => $amountInt,
            'PartyA' => $normalizedPhone,
            'PartyB' => $cfg['shortcode'],
            'PhoneNumber' => $normalizedPhone,
            'CallBackURL' => $cfg['callback_url'],
            'AccountReference' => $accountReference,
            'TransactionDesc' => $transactionDesc,
        ];

        $ch = curl_init($cfg['stk_push_url']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($requestBody));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $tokenResult['token'],
        ]);

        $raw = curl_exec($ch);
        $err = curl_error($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($raw === false || $err !== '') {
            return ['success' => false, 'message' => 'Failed to reach Daraja STK endpoint.'];
        }

        $payload = json_decode((string)$raw, true);
        if (!is_array($payload)) {
            return ['success' => false, 'message' => 'Invalid response from Daraja STK endpoint.'];
        }

        $responseCode = (string)($payload['ResponseCode'] ?? '');
        if ($httpCode >= 400 || $responseCode !== '0') {
            $errorMessage = (string)($payload['errorMessage'] ?? $payload['ResponseDescription'] ?? 'Daraja STK request failed.');
            return ['success' => false, 'message' => $errorMessage, 'payload' => $payload];
        }

        return [
            'success' => true,
            'message' => (string)($payload['ResponseDescription'] ?? 'STK push sent.'),
            'checkout_request_id' => (string)($payload['CheckoutRequestID'] ?? ''),
            'merchant_request_id' => (string)($payload['MerchantRequestID'] ?? ''),
            'payload' => $payload,
        ];
    }
}

if (!function_exists('ensure_daraja_stk_requests_table')) {
    function ensure_daraja_stk_requests_table(PDO $pdo): void
    {
        static $ready = false;
        if ($ready) {
            return;
        }

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS daraja_stk_requests (
                request_id BIGINT AUTO_INCREMENT PRIMARY KEY,
                booking_id INT NOT NULL,
                planner_user_id INT NULL,
                checkout_request_id VARCHAR(120) NOT NULL,
                merchant_request_id VARCHAR(120) DEFAULT NULL,
                phone_number VARCHAR(20) DEFAULT NULL,
                amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                status ENUM('requested', 'paid', 'failed') NOT NULL DEFAULT 'requested',
                raw_response TEXT NULL,
                callback_payload TEXT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_daraja_checkout_request (checkout_request_id),
                INDEX idx_daraja_stk_booking_status (booking_id, status),
                CONSTRAINT fk_daraja_stk_booking FOREIGN KEY (booking_id) REFERENCES bookings(booking_id) ON DELETE CASCADE,
                CONSTRAINT fk_daraja_stk_planner FOREIGN KEY (planner_user_id) REFERENCES users(user_id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        $ready = true;
    }
}
