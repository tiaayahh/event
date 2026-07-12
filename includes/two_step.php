<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/mailer.php';

const TWO_STEP_EXPIRY_SECONDS = 300;
const TWO_STEP_MAX_ATTEMPTS = 5;
const TWO_STEP_LOGIN_SEND_DEDUP_SECONDS = 25;
const TWO_STEP_TRUST_DAYS = 30;
const TWO_STEP_TRUST_COOKIE = 'planora_trusted_login';
const TWO_STEP_TRUST_SECRET = 'planora-local-demo-trust-key-change-for-production';

function two_step_ensure_settings_table(PDO $pdo): void
{
    static $initialized = false;
    if ($initialized) {
        return;
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS user_security_settings (
            user_id INT NOT NULL PRIMARY KEY,
            email_2fa_enabled TINYINT(1) NOT NULL DEFAULT 1,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            CONSTRAINT fk_user_security_settings_user FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $initialized = true;
}

function two_step_email_otp_is_enabled(PDO $pdo, int $userId): bool
{
    two_step_ensure_settings_table($pdo);

    $stmt = $pdo->prepare('SELECT email_2fa_enabled FROM user_security_settings WHERE user_id = ? LIMIT 1');
    $stmt->execute([$userId]);
    $row = $stmt->fetch();

    if ($row) {
        return !empty($row['email_2fa_enabled']);
    }

    $insert = $pdo->prepare('INSERT INTO user_security_settings (user_id, email_2fa_enabled) VALUES (?, 1)');
    $insert->execute([$userId]);
    return true;
}

function two_step_set_email_otp_enabled(PDO $pdo, int $userId, bool $enabled): void
{
    two_step_ensure_settings_table($pdo);

    $stmt = $pdo->prepare(
        'INSERT INTO user_security_settings (user_id, email_2fa_enabled)
         VALUES (?, ?)
         ON DUPLICATE KEY UPDATE email_2fa_enabled = VALUES(email_2fa_enabled)'
    );
    $stmt->execute([$userId, $enabled ? 1 : 0]);
}

function two_step_dashboard_path(string $role): string
{
    switch ($role) {
        case 'planner':
            return 'admin/dashboard.php';
        case 'vendor':
            return 'vendor/dashboard.php';
        case 'attendee':
            return 'attendee/dashboard.php';
        default:
            return 'login.php';
    }
}

function two_step_clear_pending(): void
{
    unset($_SESSION['pending_2fa']);
}

function two_step_start_pending(array $user, bool $passwordNeedsUpdate = false, bool $forceResend = false): array
{
    $existing = $_SESSION['pending_2fa'] ?? null;
    if (!$forceResend && is_array($existing)) {
        $sameUser = (int)($existing['user_id'] ?? 0) === (int)($user['user_id'] ?? 0);
        $notExpired = (int)($existing['expires_at'] ?? 0) >= time();
        $sentAt = (int)($existing['sent_at'] ?? 0);
        $sentRecently = $sentAt > 0 && (time() - $sentAt) <= TWO_STEP_LOGIN_SEND_DEDUP_SECONDS;

        if ($sameUser && $notExpired) {
            // Keep one active code per pending login unless user explicitly requests resend.
            $_SESSION['pending_2fa']['password_needs_update'] =
                !empty($existing['password_needs_update']) || $passwordNeedsUpdate;

            // Avoid rapid resend loops right after initial login attempt.
            if ($sentRecently) {
                return [
                    'email_sent' => true,
                    'error_message' => '',
                ];
            }

            return [
                'email_sent' => true,
                'error_message' => '',
            ];
        }
    }

    $code = (string) random_int(100000, 999999);

    $_SESSION['pending_2fa'] = [
        'user_id' => $user['user_id'],
        'full_name' => $user['full_name'],
        'email' => $user['email'],
        'role' => $user['role'],
        'code_hash' => password_hash($code, PASSWORD_BCRYPT),
        'sent_at' => time(),
        'expires_at' => time() + TWO_STEP_EXPIRY_SECONDS,
        'attempts' => 0,
        'password_needs_update' => $passwordNeedsUpdate,
    ];

    $sendResult = two_step_send_code((string) $user['email'], $code);

    return [
        'email_sent' => !empty($sendResult['success']),
        'error_message' => (string)($sendResult['message'] ?? ''),
    ];
}

function two_step_trust_value(int $userId): string
{
    return $userId . ':' . hash_hmac('sha256', (string)$userId, TWO_STEP_TRUST_SECRET);
}

function two_step_user_is_trusted(int $userId): bool
{
    $cookie = $_COOKIE[TWO_STEP_TRUST_COOKIE] ?? '';
    return is_string($cookie) && hash_equals(two_step_trust_value($userId), $cookie);
}

function two_step_trust_browser(int $userId): void
{
    setcookie(TWO_STEP_TRUST_COOKIE, two_step_trust_value($userId), [
        'expires' => time() + (TWO_STEP_TRUST_DAYS * 86400),
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function two_step_send_code(string $email, string $code): array
{
    $subject = 'Your Planora verification code';
    $htmlBody = '<p>Your Planora verification code is:</p>'
        . '<p style="font-size:24px;font-weight:700;letter-spacing:2px;">' . htmlspecialchars($code, ENT_QUOTES, 'UTF-8') . '</p>'
        . '<p>This code expires in 5 minutes.</p>';
    $textBody = "Your Planora verification code is: {$code}\n\nThis code expires in 5 minutes.";

    $result = send_platform_email($email, $email, $subject, $htmlBody, $textBody);
    if (empty($result['success'])) {
        $message = (string)($result['message'] ?? 'Unable to send email right now.');
        error_log('Two-step verification email failed: ' . $message);
        return [
            'success' => false,
            'message' => $message,
        ];
    }

    return [
        'success' => true,
        'message' => 'Verification code sent.',
    ];
}

function two_step_pending_login(): ?array
{
    $pending = $_SESSION['pending_2fa'] ?? null;

    if (!is_array($pending)) {
        return null;
    }

    if (($pending['expires_at'] ?? 0) < time()) {
        two_step_clear_pending();
        return null;
    }

    return $pending;
}

function two_step_verify_code(string $code): bool
{
    $pending = two_step_pending_login();

    if ($pending === null) {
        return false;
    }

    $_SESSION['pending_2fa']['attempts'] = ((int) ($pending['attempts'] ?? 0)) + 1;

    if ($_SESSION['pending_2fa']['attempts'] > TWO_STEP_MAX_ATTEMPTS) {
        two_step_clear_pending();
        return false;
    }

    if (!password_verify($code, (string) $pending['code_hash'])) {
        return false;
    }

    session_regenerate_id(true);

    $_SESSION['user_id'] = $pending['user_id'];
    $_SESSION['full_name'] = $pending['full_name'];
    $_SESSION['role'] = $pending['role'];

    if (!empty($pending['password_needs_update'])) {
        $_SESSION['force_password_change'] = true;
        $_SESSION['flash_error'] = 'Your password is weak. Please create a stronger password.';
    }

    two_step_trust_browser((int)$pending['user_id']);
    two_step_clear_pending();

    return true;
}
