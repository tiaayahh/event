<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$autoloadPaths = [
    __DIR__ . '/third_party/autoload.php',
    __DIR__ . '/vendor/autoload.php',
];

$autoloadLoaded = false;
foreach ($autoloadPaths as $autoloadPath) {
    if (is_file($autoloadPath)) {
        require_once $autoloadPath;
        $autoloadLoaded = true;
        break;
    }
}

if (!$autoloadLoaded) {
    throw new RuntimeException('Composer autoload file not found. Run composer install.');
}

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as MailException;

const TWO_STEP_EXPIRY_SECONDS = 300;
const TWO_STEP_MAX_ATTEMPTS = 5;
const TWO_STEP_TRUST_DAYS = 30;
const TWO_STEP_TRUST_COOKIE = 'planora_trusted_login';
const TWO_STEP_TRUST_SECRET = 'planora-local-demo-trust-key-change-for-production';

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

function two_step_start_pending(array $user, bool $passwordNeedsUpdate = false): array
{
    $code = (string) random_int(100000, 999999);

    $_SESSION['pending_2fa'] = [
        'user_id' => $user['user_id'],
        'full_name' => $user['full_name'],
        'email' => $user['email'],
        'role' => $user['role'],
        'code_hash' => password_hash($code, PASSWORD_BCRYPT),
        'expires_at' => time() + TWO_STEP_EXPIRY_SECONDS,
        'attempts' => 0,
        'password_needs_update' => $passwordNeedsUpdate,
    ];

    return [
        'email_sent' => two_step_send_code((string) $user['email'], $code),
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

function two_step_send_code(string $email, string $code): bool
{
    $config = require __DIR__ . '/config/mail.php';
    $mail = new PHPMailer(true);

    try {
        $mail->isSMTP();
        $mail->Host = (string) $config['host'];
        $mail->SMTPAuth = true;
        $mail->Username = (string) $config['username'];
        $mail->Password = (string) $config['password'];
        $mail->Port = (int) $config['port'];

        if (($config['encryption'] ?? '') === 'tls') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        } elseif (($config['encryption'] ?? '') === 'ssl') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        }

        $mail->setFrom((string) $config['from_email'], (string) $config['from_name']);
        $mail->addAddress($email);
        $mail->Subject = 'Your Planora verification code';
        $mail->Body = "Your Planora verification code is: {$code}\n\nThis code expires in 5 minutes.";
        $mail->AltBody = $mail->Body;

        return $mail->send();
    } catch (MailException $e) {
        error_log('Two-step verification email failed: ' . $e->getMessage());
        return false;
    }
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
?>