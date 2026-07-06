<?php

function totp_ensure_table(PDO $pdo): void
{
    static $initialized = false;
    if ($initialized) {
        return;
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS user_totp_auth (
            user_id INT NOT NULL PRIMARY KEY,
            secret_key VARCHAR(64) NOT NULL,
            is_enabled TINYINT(1) NOT NULL DEFAULT 0,
            verified_at DATETIME DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            CONSTRAINT fk_user_totp_auth_user FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $initialized = true;
}

function totp_generate_secret(int $length = 32): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $max = strlen($alphabet) - 1;
    $secret = '';

    for ($i = 0; $i < $length; $i++) {
        $secret .= $alphabet[random_int(0, $max)];
    }

    return $secret;
}

function totp_base32_decode(string $input): string
{
    $input = strtoupper(trim($input));
    $input = preg_replace('/[^A-Z2-7]/', '', $input) ?? '';

    if ($input === '') {
        return '';
    }

    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits = '';

    $length = strlen($input);
    for ($i = 0; $i < $length; $i++) {
        $char = $input[$i];
        $value = strpos($alphabet, $char);
        if ($value === false) {
            return '';
        }
        $bits .= str_pad(decbin((int)$value), 5, '0', STR_PAD_LEFT);
    }

    $binary = '';
    $bitsLength = strlen($bits);
    for ($i = 0; $i + 8 <= $bitsLength; $i += 8) {
        $binary .= chr(bindec(substr($bits, $i, 8)));
    }

    return $binary;
}

function totp_generate_code(string $secret, ?int $timestamp = null, int $period = 30, int $digits = 6): string
{
    $timestamp = $timestamp ?? time();
    $counter = (int)floor($timestamp / $period);

    $binarySecret = totp_base32_decode($secret);
    if ($binarySecret === '') {
        return '';
    }

    $counterBin = pack('N*', 0) . pack('N*', $counter);
    $hash = hash_hmac('sha1', $counterBin, $binarySecret, true);

    $offset = ord(substr($hash, -1)) & 0x0F;
    $truncated = substr($hash, $offset, 4);
    $value = unpack('N', $truncated)[1] & 0x7FFFFFFF;
    $mod = 10 ** $digits;
    $otp = (string)($value % $mod);

    return str_pad($otp, $digits, '0', STR_PAD_LEFT);
}

function totp_verify_code(string $secret, string $code, int $window = 1, int $period = 30, int $digits = 6): bool
{
    if (!preg_match('/^[0-9]{' . $digits . '}$/', $code)) {
        return false;
    }

    $time = time();
    for ($i = -$window; $i <= $window; $i++) {
        $candidate = totp_generate_code($secret, $time + ($i * $period), $period, $digits);
        if ($candidate !== '' && hash_equals($candidate, $code)) {
            return true;
        }
    }

    return false;
}

function totp_build_otpauth_uri(string $accountName, string $secret, string $issuer = 'Planora'): string
{
    $label = rawurlencode($issuer . ':' . $accountName);
    $issuerParam = rawurlencode($issuer);
    $secretParam = rawurlencode($secret);

    return 'otpauth://totp/' . $label . '?secret=' . $secretParam . '&issuer=' . $issuerParam . '&algorithm=SHA1&digits=6&period=30';
}
