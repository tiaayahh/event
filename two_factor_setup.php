<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    $secureCookie = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $secureCookie,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

require_once 'config/db.php';
require_once 'includes/csrf.php';
require_once 'includes/audit.php';
require_once 'includes/totp.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php', true, 303);
    exit;
}

$userId = (int)$_SESSION['user_id'];
$userRole = (string)($_SESSION['role'] ?? '');
$userName = (string)($_SESSION['full_name'] ?? 'User');
$success = '';
$error = '';

function dashboard_path_for_role(string $role): string
{
    switch ($role) {
        case 'planner':
            return 'admin/dashboard.php';
        case 'vendor':
            return 'vendor/dashboard.php';
        case 'attendee':
            return 'attendee/dashboard.php';
        default:
            return 'index.php';
    }
}

totp_ensure_table($pdo);

$userStmt = $pdo->prepare('SELECT email FROM users WHERE user_id = ? LIMIT 1');
$userStmt->execute([$userId]);
$userRow = $userStmt->fetch();
$userEmail = (string)($userRow['email'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_validate_post_token()) {
        $error = 'Invalid session token. Refresh and try again.';
    } else {
        $action = strtolower(trim((string)($_POST['action'] ?? '')));

        $rowStmt = $pdo->prepare('SELECT user_id, secret_key, is_enabled FROM user_totp_auth WHERE user_id = ? LIMIT 1');
        $rowStmt->execute([$userId]);
        $totpRow = $rowStmt->fetch();

        if ($action === 'generate_secret') {
            $secret = totp_generate_secret();
            $upsert = $pdo->prepare(
                "INSERT INTO user_totp_auth (user_id, secret_key, is_enabled, verified_at)
                 VALUES (?, ?, 0, NULL)
                 ON DUPLICATE KEY UPDATE secret_key = VALUES(secret_key), is_enabled = 0, verified_at = NULL"
            );
            $upsert->execute([$userId, $secret]);

            audit_log($pdo, $userId, $userRole, 'auth.totp_secret_generated', 'user', (string)$userId);
            $success = 'Authenticator secret generated. Add it to your app, then verify a code to enable 2FA.';
        } elseif ($action === 'enable_totp') {
            $code = trim((string)($_POST['totp_code'] ?? ''));
            if (!$totpRow || empty($totpRow['secret_key'])) {
                $error = 'Generate a secret first.';
            } elseif (!totp_verify_code((string)$totpRow['secret_key'], $code, 1, 30, 6)) {
                $error = 'Invalid authenticator code. Try again.';
            } else {
                $enableStmt = $pdo->prepare('UPDATE user_totp_auth SET is_enabled = 1, verified_at = NOW() WHERE user_id = ?');
                $enableStmt->execute([$userId]);

                audit_log($pdo, $userId, $userRole, 'auth.totp_enabled', 'user', (string)$userId);
                $success = 'Authenticator 2FA is now enabled for your account.';
            }
        } elseif ($action === 'disable_totp') {
            $code = trim((string)($_POST['totp_code'] ?? ''));
            if (!$totpRow || empty($totpRow['secret_key']) || empty($totpRow['is_enabled'])) {
                $error = 'Authenticator 2FA is not enabled.';
            } elseif (!totp_verify_code((string)$totpRow['secret_key'], $code, 1, 30, 6)) {
                $error = 'Invalid authenticator code. Could not disable 2FA.';
            } else {
                $disableStmt = $pdo->prepare('UPDATE user_totp_auth SET is_enabled = 0 WHERE user_id = ?');
                $disableStmt->execute([$userId]);

                audit_log($pdo, $userId, $userRole, 'auth.totp_disabled', 'user', (string)$userId);
                $success = 'Authenticator 2FA has been disabled.';
            }
        }
    }
}

$totpStmt = $pdo->prepare('SELECT user_id, secret_key, is_enabled, verified_at FROM user_totp_auth WHERE user_id = ? LIMIT 1');
$totpStmt->execute([$userId]);
$totp = $totpStmt->fetch();

$secret = (string)($totp['secret_key'] ?? '');
$isEnabled = !empty($totp['is_enabled']);
$otpauthUri = $secret !== '' ? totp_build_otpauth_uri($userEmail !== '' ? $userEmail : (string)$userId, $secret, 'Planora') : '';
$qrImageUrl = $otpauthUri !== ''
    ? 'https://api.qrserver.com/v1/create-qr-code/?size=180x180&data=' . rawurlencode($otpauthUri)
    : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Planora - 2FA Setup</title>
    <link rel="stylesheet" href="assets/style.css">
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            background: #f3f4f6;
            margin: 0;
            padding: 24px;
        }
        .card {
            max-width: 640px;
            margin: 0 auto;
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            padding: 24px;
            box-shadow: 0 4px 18px rgba(0, 0, 0, 0.08);
        }
        .title {
            font-size: 24px;
            margin: 0 0 8px;
            color: #1f2937;
        }
        .subtitle {
            margin: 0 0 20px;
            color: #6b7280;
            font-size: 14px;
        }
        .message {
            padding: 10px 12px;
            border-radius: 8px;
            margin-bottom: 16px;
            font-size: 14px;
        }
        .success {
            background: #ecfdf5;
            color: #065f46;
            border: 1px solid #a7f3d0;
        }
        .error {
            background: #fef2f2;
            color: #991b1b;
            border: 1px solid #fecaca;
        }
        .status {
            margin-bottom: 14px;
            font-size: 14px;
        }
        .status strong {
            color: #111827;
        }
        .secret {
            font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
            font-size: 15px;
            background: #f9fafb;
            border: 1px dashed #d1d5db;
            border-radius: 8px;
            padding: 10px;
            margin-bottom: 14px;
            word-break: break-all;
        }
        .code-input {
            width: 100%;
            box-sizing: border-box;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            padding: 12px;
            margin-bottom: 12px;
            font-size: 15px;
        }
        .btn {
            background: #4f46e5;
            border: none;
            color: #fff;
            border-radius: 8px;
            padding: 10px 14px;
            cursor: pointer;
            font-weight: 600;
            margin-right: 8px;
        }
        .btn.secondary {
            background: #374151;
        }
        .btn.danger {
            background: #b91c1c;
        }
        .help {
            margin-top: 10px;
            color: #6b7280;
            font-size: 13px;
            line-height: 1.5;
        }
        .qr {
            margin: 14px 0;
        }
        .back-link {
            display: inline-block;
            margin-top: 18px;
            color: #4f46e5;
            text-decoration: none;
            font-weight: 600;
        }
    </style>
</head>
<body>
    <div class="card">
        <h1 class="title">Two-Factor Authentication</h1>
        <p class="subtitle">Hello <?php echo htmlspecialchars($userName); ?>. Configure Google Authenticator (or any TOTP app) for stronger login security.</p>

        <?php if ($success !== ''): ?>
            <div class="message success"><?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>
        <?php if ($error !== ''): ?>
            <div class="message error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <div class="status"><strong>Status:</strong> <?php echo $isEnabled ? 'Enabled' : 'Disabled'; ?></div>

        <?php if ($secret === ''): ?>
            <form method="POST">
                <?php echo csrf_input(); ?>
                <input type="hidden" name="action" value="generate_secret">
                <button class="btn" type="submit">Generate Authenticator Secret</button>
            </form>
        <?php else: ?>
            <div class="secret"><?php echo htmlspecialchars($secret); ?></div>

            <?php if ($qrImageUrl !== ''): ?>
                <div class="qr">
                    <img src="<?php echo htmlspecialchars($qrImageUrl); ?>" alt="Authenticator QR" width="180" height="180">
                </div>
            <?php endif; ?>

            <div class="help">
                1. Open Google Authenticator (or similar app).<br>
                2. Scan the QR code above or enter the secret manually.<br>
                3. Enter the current 6-digit app code below.
            </div>

            <?php if (!$isEnabled): ?>
                <form method="POST" style="margin-top: 14px;">
                    <?php echo csrf_input(); ?>
                    <input type="hidden" name="action" value="enable_totp">
                    <input class="code-input" type="text" name="totp_code" maxlength="6" pattern="[0-9]{6}" placeholder="Enter 6-digit code" required>
                    <button class="btn" type="submit">Enable 2FA</button>
                </form>
                <form method="POST" style="margin-top: 10px;">
                    <?php echo csrf_input(); ?>
                    <input type="hidden" name="action" value="generate_secret">
                    <button class="btn secondary" type="submit">Regenerate Secret</button>
                </form>
            <?php else: ?>
                <form method="POST" style="margin-top: 14px;">
                    <?php echo csrf_input(); ?>
                    <input type="hidden" name="action" value="disable_totp">
                    <input class="code-input" type="text" name="totp_code" maxlength="6" pattern="[0-9]{6}" placeholder="Enter current app code to disable" required>
                    <button class="btn danger" type="submit">Disable 2FA</button>
                </form>
            <?php endif; ?>
        <?php endif; ?>

        <a class="back-link" href="<?php echo htmlspecialchars(dashboard_path_for_role($userRole)); ?>">Back to dashboard</a>
    </div>
</body>
</html>
