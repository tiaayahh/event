<?php

if (!function_exists('daraja_config')) {
    function daraja_config(): array
    {
        $env = strtolower((string)(getenv('DARAJA_ENV') ?: 'sandbox'));
        $isLive = $env === 'live';
        $consumerKey = trim((string)(getenv('DARAJA_CONSUMER_KEY') ?: getenv('DARAJA_CUSTOMER_KEY') ?: ''));
        $consumerSecret = trim((string)(getenv('DARAJA_CONSUMER_SECRET') ?: getenv('DARAJA_CUSTOMER_SECRET') ?: ''));

        return [
            'env' => $env,
            'consumer_key' => $consumerKey,
            'consumer_secret' => $consumerSecret,
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
        return count(daraja_missing_required_fields()) === 0;
    }
}

if (!function_exists('daraja_missing_required_fields')) {
    function daraja_missing_required_fields(): array
    {
        $cfg = daraja_config();
        $required = [
            'consumer_key' => 'DARAJA_CONSUMER_KEY',
            'consumer_secret' => 'DARAJA_CONSUMER_SECRET',
            'shortcode' => 'DARAJA_SHORTCODE',
            'passkey' => 'DARAJA_PASSKEY',
            'callback_url' => 'DARAJA_CALLBACK_URL',
        ];

        $missing = [];
        foreach ($required as $key => $envName) {
            if (trim((string)($cfg[$key] ?? '')) === '') {
                $missing[] = $envName;
            }
        }

        return $missing;
    }
}

if (!function_exists('daraja_missing_stk_fields')) {
    function daraja_missing_stk_fields(): array
    {
        $cfg = daraja_config();
        $required = [
            'shortcode' => 'DARAJA_SHORTCODE',
            'passkey' => 'DARAJA_PASSKEY',
        ];

        $missing = [];
        foreach ($required as $key => $envName) {
            if (trim((string)($cfg[$key] ?? '')) === '') {
                $missing[] = $envName;
            }
        }

        return $missing;
    }
}

if (!function_exists('daraja_is_stk_configured')) {
    function daraja_is_stk_configured(): bool
    {
        return count(daraja_missing_stk_fields()) === 0;
    }
}

if (!function_exists('daraja_effective_stk_amount')) {
    function daraja_effective_stk_amount(float $intendedAmount): float
    {
        $cfg = daraja_config();
        if (($cfg['env'] ?? 'sandbox') !== 'sandbox') {
            return $intendedAmount;
        }

        $raw = trim((string)(getenv('DARAJA_SANDBOX_TEST_AMOUNT') ?: ''));
        if ($raw !== '' && is_numeric($raw)) {
            $parsed = (float)$raw;
            if ($parsed > 0) {
                return $parsed;
            }
        }

        return $intendedAmount;
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

if (!function_exists('daraja_apply_curl_network_options')) {
    function daraja_apply_curl_network_options($ch): void
    {
        // Prefer OS-native certificate trust when available (notably on Windows).
        if (defined('CURLOPT_SSL_OPTIONS') && defined('CURLSSLOPT_NATIVE_CA')) {
            curl_setopt($ch, CURLOPT_SSL_OPTIONS, CURLSSLOPT_NATIVE_CA);
        }

        $caBundle = trim((string)(getenv('DARAJA_CURL_CA_BUNDLE') ?: getenv('SSL_CERT_FILE') ?: ''));
        if ($caBundle !== '' && is_file($caBundle)) {
            curl_setopt($ch, CURLOPT_CAINFO, $caBundle);
        }

        $proxy = trim((string)(getenv('HTTPS_PROXY') ?: getenv('HTTP_PROXY') ?: ''));
        if ($proxy !== '') {
            curl_setopt($ch, CURLOPT_PROXY, $proxy);
        }
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
        daraja_apply_curl_network_options($ch);

        $raw = curl_exec($ch);
        $err = curl_error($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($raw === false || $err !== '') {
            $message = 'Failed to connect to Daraja token endpoint.';
            if ($err !== '') {
                $message .= ' ' . $err;
            }
            return ['success' => false, 'message' => $message];
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
        daraja_apply_curl_network_options($ch);

        $raw = curl_exec($ch);
        $err = curl_error($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($raw === false || $err !== '') {
            $message = 'Failed to reach Daraja STK endpoint.';
            if ($err !== '') {
                $message .= ' ' . $err;
            }
            return ['success' => false, 'message' => $message];
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
            'message' => (string)($payload['ResponseDescription'] ?? 'Mpesa prompt sent.'),
            'checkout_request_id' => (string)($payload['CheckoutRequestID'] ?? ''),
            'merchant_request_id' => (string)($payload['MerchantRequestID'] ?? ''),
            'payload' => $payload,
        ];
    }
}

if (!function_exists('daraja_stk_query')) {
    function daraja_stk_query(string $checkoutRequestId): array
    {
        $cfg = daraja_config();
        if (!daraja_is_configured()) {
            return ['success' => false, 'message' => 'Daraja is not configured. Set DARAJA_* environment variables.'];
        }

        $checkoutRequestId = trim($checkoutRequestId);
        if ($checkoutRequestId === '') {
            return ['success' => false, 'message' => 'CheckoutRequestID is required for STK status query.'];
        }

        $tokenResult = daraja_get_access_token($cfg);
        if (empty($tokenResult['success'])) {
            return $tokenResult;
        }

        $timestamp = gmdate('YmdHis');
        $password = base64_encode($cfg['shortcode'] . $cfg['passkey'] . $timestamp);
        $queryUrl = $cfg['env'] === 'live'
            ? 'https://api.safaricom.co.ke/mpesa/stkpushquery/v1/query'
            : 'https://sandbox.safaricom.co.ke/mpesa/stkpushquery/v1/query';

        $requestBody = [
            'BusinessShortCode' => $cfg['shortcode'],
            'Password' => $password,
            'Timestamp' => $timestamp,
            'CheckoutRequestID' => $checkoutRequestId,
        ];

        $ch = curl_init($queryUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($requestBody));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $tokenResult['token'],
        ]);
        daraja_apply_curl_network_options($ch);

        $raw = curl_exec($ch);
        $err = curl_error($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($raw === false || $err !== '') {
            $message = 'Failed to reach Daraja STK query endpoint.';
            if ($err !== '') {
                $message .= ' ' . $err;
            }
            return ['success' => false, 'message' => $message];
        }

        $payload = json_decode((string)$raw, true);
        if (!is_array($payload)) {
            return ['success' => false, 'message' => 'Invalid response from Daraja STK query endpoint.'];
        }

        if ($httpCode >= 400) {
            $faultString = (string)($payload['fault']['faultstring'] ?? 'Daraja STK query failed.');
            return ['success' => false, 'message' => $faultString, 'payload' => $payload];
        }

        $resultCode = (string)($payload['ResultCode'] ?? '');
        $resultDesc = (string)($payload['ResultDesc'] ?? '');
        $status = 'pending';
        if ($resultCode === '0') {
            $status = 'paid';
        } elseif ($resultCode !== '') {
            $status = 'failed';
        }

        return [
            'success' => true,
            'status' => $status,
            'result_code' => $resultCode,
            'result_desc' => $resultDesc,
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
