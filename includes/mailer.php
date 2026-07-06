<?php
require_once __DIR__ . '/../third_party/autoload.php';

use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\PHPMailer;

function env_load_once(): void
{
    static $loaded = false;
    if ($loaded) {
        return;
    }

    $candidates = [
        dirname(__DIR__) . '/.env',
        dirname(__DIR__) . '/.env.local',
    ];

    foreach ($candidates as $filePath) {
        if (!is_file($filePath) || !is_readable($filePath)) {
            continue;
        }

        $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($lines)) {
            continue;
        }

        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                continue;
            }

            $parts = explode('=', $trimmed, 2);
            if (count($parts) !== 2) {
                continue;
            }

            $key = trim($parts[0]);
            $value = trim($parts[1]);
            if ($key === '') {
                continue;
            }

            if ((str_starts_with($value, '"') && str_ends_with($value, '"')) || (str_starts_with($value, "'") && str_ends_with($value, "'"))) {
                $value = substr($value, 1, -1);
            }

            if (getenv($key) === false) {
                putenv($key . '=' . $value);
                $_ENV[$key] = $value;
                $_SERVER[$key] = $value;
            }
        }
    }

    $loaded = true;
}

function env_value(string $key, string $default = ''): string
{
    env_load_once();

    $fromGetenv = getenv($key);
    if ($fromGetenv !== false) {
        return (string)$fromGetenv;
    }

    if (isset($_ENV[$key])) {
        return (string)$_ENV[$key];
    }

    if (isset($_SERVER[$key])) {
        return (string)$_SERVER[$key];
    }

    return $default;
}

function mailer_config(): array
{
    $username = trim(env_value('MAIL_USERNAME'));
    $from = trim(env_value('MAIL_FROM'));
    if ($from === '' && $username !== '') {
        $from = $username;
    }

    return [
        'host' => trim(env_value('MAIL_HOST')),
        'port' => (int)(env_value('MAIL_PORT', '587')),
        'encryption' => strtolower(trim(env_value('MAIL_ENCRYPTION', 'tls'))),
        'username' => $username,
        'password' => env_value('MAIL_PASSWORD'),
        'from' => $from,
        'from_name' => trim(env_value('MAIL_FROM_NAME', 'Planora')),
    ];
}

function mailer_is_configured(): bool
{
    $cfg = mailer_config();

    return $cfg['host'] !== '' && $cfg['username'] !== '' && $cfg['password'] !== '' && $cfg['from'] !== '';
}

function send_platform_email(string $to, string $toName, string $subject, string $htmlBody, string $textBody = ''): array
{
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return ['success' => false, 'message' => 'Invalid recipient email address.'];
    }

    $cfg = mailer_config();
    if (!mailer_is_configured()) {
        $missing = [];
        if ($cfg['host'] === '') {
            $missing[] = 'MAIL_HOST';
        }
        if ($cfg['username'] === '') {
            $missing[] = 'MAIL_USERNAME';
        }
        if ($cfg['password'] === '') {
            $missing[] = 'MAIL_PASSWORD';
        }
        if ($cfg['from'] === '' && $cfg['username'] !== '') {
            $missing[] = 'MAIL_FROM';
        }

        return [
            'success' => false,
            'message' => 'Email service is not configured. Missing: ' . implode(', ', $missing),
        ];
    }

    $mail = new PHPMailer(true);

    try {
        $mail->isSMTP();
        $mail->Host = $cfg['host'];
        $mail->Port = $cfg['port'];
        $mail->SMTPAuth = true;
        $mail->Username = $cfg['username'];
        $mail->Password = $cfg['password'];

        if ($cfg['encryption'] === 'ssl') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        } else {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        }

        $mail->setFrom($cfg['from'], $cfg['from_name'] !== '' ? $cfg['from_name'] : 'Planora');
        $mail->addAddress($to, $toName !== '' ? $toName : $to);

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $htmlBody;
        $mail->AltBody = $textBody !== '' ? $textBody : trim(strip_tags($htmlBody));

        $mail->send();

        return ['success' => true, 'message' => 'Email sent successfully.'];
    } catch (Exception $e) {
        error_log('Mailer error: ' . $e->getMessage());
        return ['success' => false, 'message' => 'Unable to send email right now. Check SMTP settings and credentials.'];
    }
}
