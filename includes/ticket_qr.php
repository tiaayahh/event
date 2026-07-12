<?php

if (!function_exists('ticket_qr_base64url_encode')) {
    function ticket_qr_base64url_encode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}

if (!function_exists('ticket_qr_base64url_decode')) {
    function ticket_qr_base64url_decode(string $value): ?string
    {
        $normalized = strtr($value, '-_', '+/');
        $padding = strlen($normalized) % 4;
        if ($padding > 0) {
            $normalized .= str_repeat('=', 4 - $padding);
        }

        $decoded = base64_decode($normalized, true);
        return $decoded === false ? null : $decoded;
    }
}

if (!function_exists('ticket_qr_secret')) {
    function ticket_qr_secret(): string
    {
        static $secret = null;
        if ($secret !== null) {
            return $secret;
        }

        $fromEnv = trim((string)(getenv('APP_KEY') ?: ''));
        if ($fromEnv !== '') {
            $secret = $fromEnv;
            return $secret;
        }

        $fallback = trim((string)(getenv('DB_PASS') ?: ''));
        if ($fallback !== '') {
            $secret = $fallback;
            return $secret;
        }

        $secret = 'planora-ticket-secret-fallback';
        return $secret;
    }
}

if (!function_exists('ticket_qr_build_code')) {
    function ticket_qr_build_code(int $eventId, int $attendeeId, string $eventDate, int $paymentId = 0): string
    {
        return strtoupper(substr(sha1((string)$eventId . '-' . (string)$attendeeId . '-' . $eventDate . '-' . (string)$paymentId), 0, 14));
    }
}

if (!function_exists('ticket_qr_build_payload_token')) {
    function ticket_qr_build_payload_token(int $eventId, int $attendeeId, string $eventDate, int $paymentId = 0): string
    {
        // v2 keeps the signed payload compact to reduce QR density and improve scanner reliability.
        $payload = [
            'v' => 2,
            'eid' => $eventId,
            'aid' => $attendeeId,
            'pid' => $paymentId,
        ];

        $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
        if (!is_string($json) || $json === '') {
            return '';
        }

        $encodedPayload = ticket_qr_base64url_encode($json);
        $signature = substr(hash_hmac('sha256', $encodedPayload, ticket_qr_secret(), true), 0, 16);
        $encodedSignature = ticket_qr_base64url_encode($signature);

        return $encodedPayload . '.' . $encodedSignature;
    }
}

if (!function_exists('ticket_qr_parse_payload_token')) {
    function ticket_qr_parse_payload_token(string $token): ?array
    {
        $token = trim($token);
        if ($token === '' || strpos($token, '.') === false) {
            return null;
        }

        [$encodedPayload, $encodedSignature] = explode('.', $token, 2);
        if ($encodedPayload === '' || $encodedSignature === '') {
            return null;
        }

        $expectedRaw = hash_hmac('sha256', $encodedPayload, ticket_qr_secret(), true);
        $expectedRawShort = substr($expectedRaw, 0, 16);
        $providedRaw = ticket_qr_base64url_decode($encodedSignature);
        if ($providedRaw === null || (!hash_equals($expectedRaw, $providedRaw) && !hash_equals($expectedRawShort, $providedRaw))) {
            return null;
        }

        $json = ticket_qr_base64url_decode($encodedPayload);
        if ($json === null || $json === '') {
            return null;
        }

        $payload = json_decode($json, true);
        if (!is_array($payload)) {
            return null;
        }

        $version = (int)($payload['v'] ?? 1);
        if ($version >= 2) {
            $eventId = (int)($payload['eid'] ?? 0);
            $attendeeId = (int)($payload['aid'] ?? 0);
            $paymentId = (int)($payload['pid'] ?? 0);

            if ($eventId <= 0 || $attendeeId <= 0 || $paymentId <= 0) {
                return null;
            }

            return [
                'v' => $version,
                'event_id' => $eventId,
                'attendee_id' => $attendeeId,
                'payment_id' => $paymentId,
                'event_date' => '',
                'ticket_code' => '',
            ];
        }

        $eventId = (int)($payload['event_id'] ?? 0);
        $attendeeId = (int)($payload['attendee_id'] ?? 0);
        $paymentId = (int)($payload['payment_id'] ?? 0);
        $eventDate = trim((string)($payload['event_date'] ?? ''));
        $ticketCode = strtoupper(trim((string)($payload['ticket_code'] ?? '')));

        if ($eventId <= 0 || $attendeeId <= 0 || $eventDate === '' || $ticketCode === '') {
            return null;
        }

        $payload['event_id'] = $eventId;
        $payload['attendee_id'] = $attendeeId;
        $payload['payment_id'] = $paymentId;
        $payload['event_date'] = $eventDate;
        $payload['ticket_code'] = $ticketCode;

        return $payload;
    }
}

if (!function_exists('ticket_qr_render_data_uri')) {
    function ticket_qr_render_data_uri(string $token): string
    {
        $token = trim($token);
        if ($token === '') {
            return '';
        }

        $autoloadPath = dirname(__DIR__) . '/third_party/autoload.php';
        if (!is_file($autoloadPath)) {
            return '';
        }

        try {
            $previousErrorReporting = error_reporting();
            $ignoreMask = E_DEPRECATED | E_USER_DEPRECATED;
            error_reporting($previousErrorReporting & (~$ignoreMask));

            $deprecationHandler = static function (int $severity, string $message, string $file): bool {
                if (($severity === E_DEPRECATED || $severity === E_USER_DEPRECATED)
                    && strpos(str_replace('\\', '/', $file), '/third_party/chillerlan/') !== false) {
                    return true;
                }

                return false;
            };

            set_error_handler($deprecationHandler, E_DEPRECATED | E_USER_DEPRECATED);

            require_once $autoloadPath;

            if (!class_exists('chillerlan\\QRCode\\QRCode') || !class_exists('chillerlan\\QRCode\\QROptions')) {
                restore_error_handler();
                error_reporting($previousErrorReporting);
                return '';
            }

            $output = '';

            try {
                $options = new chillerlan\QRCode\QROptions([
                    'outputType' => chillerlan\QRCode\QRCode::OUTPUT_IMAGE_PNG,
                    'eccLevel' => chillerlan\QRCode\QRCode::ECC_M,
                    'scale' => 10,
                    'addQuietzone' => true,
                    'quietzoneSize' => 4,
                    'imageBase64' => true,
                ]);

                $output = (new chillerlan\QRCode\QRCode($options))->render($token);
            } catch (Throwable $pngError) {
                $svgOptions = new chillerlan\QRCode\QROptions([
                    'outputType' => chillerlan\QRCode\QRCode::OUTPUT_MARKUP_SVG,
                    'eccLevel' => chillerlan\QRCode\QRCode::ECC_M,
                    'scale' => 10,
                    'addQuietzone' => true,
                    'quietzoneSize' => 4,
                ]);

                $svg = (new chillerlan\QRCode\QRCode($svgOptions))->render($token);
                if (is_string($svg) && $svg !== '') {
                    if (str_starts_with($svg, 'data:image/')) {
                        $output = $svg;
                    } else {
                        $output = 'data:image/svg+xml;base64,' . base64_encode($svg);
                    }
                }
            }
            restore_error_handler();
            error_reporting($previousErrorReporting);

            if (!is_string($output) || $output === '') {
                return '';
            }

            if (str_starts_with($output, 'data:image/')) {
                return $output;
            }

            return 'data:image/png;base64,' . base64_encode($output);
        } catch (Throwable $e) {
            restore_error_handler();
            if (isset($previousErrorReporting)) {
                error_reporting($previousErrorReporting);
            }
            error_log('ticket_qr_render_data_uri error: ' . $e->getMessage());
            return '';
        }
    }
}
