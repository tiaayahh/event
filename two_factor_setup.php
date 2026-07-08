<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    $secureCookie = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    session_cache_limiter('private_no_expire');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $secureCookie,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

session_start();

require_once 'config/db.php';
require_once 'includes/csrf.php';
require_once 'includes/audit.php';
require_once 'includes/two_step.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php', true, 303);
    exit;
}

function setup_return_path(string $role): string
{
    switch ($role) {
        case 'planner':
            return 'admin/profile.php';
        case 'vendor':
            return 'vendor/profile.php';
        case 'attendee':
            return 'attendee/profile.php';
        default:
            return 'login.php';
    }
}

$userId = (int)$_SESSION['user_id'];
$userRole = (string)($_SESSION['role'] ?? '');
$fullName = (string)($_SESSION['full_name'] ?? 'User');

$stmt = $pdo->prepare('SELECT email FROM users WHERE user_id = ? LIMIT 1');
$stmt->execute([$userId]);
$userEmail = (string)($stmt->fetchColumn() ?: '');
if ($userEmail === '') {
    header('Location: logout.php', true, 303);
    exit;
}

$flashSuccess = '';
$flashError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($_POST)) {
        header('Location: two_factor_setup.php', true, 303);
        exit;
    }

    if (!csrf_validate_post_token()) {
        $flashError = 'Your session token is invalid. Please refresh and try again.';
    } else {
        $action = (string)($_POST['action'] ?? '');

        if ($action === 'enable_email_otp') {
            two_step_set_email_otp_enabled($pdo, $userId, true);
            audit_log(
                $pdo,
                $userId,
                $userRole !== '' ? $userRole : null,
                'auth.email_2fa_enabled',
                'user',
                (string)$userId
            );
            $flashSuccess = 'Email 2-step verification has been enabled.';
        } elseif ($action === 'disable_email_otp') {
            two_step_set_email_otp_enabled($pdo, $userId, false);
            audit_log(
                $pdo,
                $userId,
                $userRole !== '' ? $userRole : null,
                'auth.email_2fa_disabled',
                'user',
                (string)$userId
            );
            $flashSuccess = 'Email 2-step verification has been disabled.';
        }
    }
}

$emailOtpEnabled = two_step_email_otp_is_enabled($pdo, $userId);
$returnPath = setup_return_path($userRole);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Planora - 2-Step Verification</title>
    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
        }

        body {
            background: #f3f4f6;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .card {
            width: 100%;
            max-width: 560px;
            background: #ffffff;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            box-shadow: 0 6px 24px rgba(0, 0, 0, 0.08);
            padding: 28px;
        }

        .title {
            color: #6c63ff;
            font-size: 28px;
            font-weight: 700;
            text-align: center;
            margin-bottom: 8px;
        }

        .subtitle {
            text-align: center;
            color: #4b5563;
            font-size: 14px;
            margin-bottom: 18px;
            line-height: 1.5;
        }

        .status {
            border-radius: 8px;
            padding: 12px;
            font-size: 14px;
            margin-bottom: 14px;
            line-height: 1.4;
        }

        .status-on {
            background: #ecfdf5;
            color: #065f46;
            border: 1px solid #a7f3d0;
        }

        .status-off {
            background: #fff7ed;
            color: #9a3412;
            border: 1px solid #fed7aa;
        }

        .message {
            border-radius: 8px;
            padding: 10px 12px;
            font-size: 14px;
            margin-bottom: 12px;
        }

        .success {
            background: #ecfff0;
            color: #1c7a36;
            border: 1px solid #c9f0d4;
        }

        .error {
            background: #ffecec;
            color: #9d2020;
            border: 1px solid #f6caca;
        }

        .panel {
            border: 1px solid #e5e7eb;
            border-radius: 10px;
            background: #fafafa;
            padding: 16px;
        }

        .panel p {
            color: #374151;
            font-size: 14px;
            line-height: 1.5;
            margin-bottom: 12px;
        }

        .btn-row {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .btn {
            border: none;
            border-radius: 8px;
            padding: 11px 14px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
        }

        .btn-primary {
            background: #6c63ff;
            color: #ffffff;
        }

        .btn-primary:hover {
            background: #5a52e0;
        }

        .btn-danger {
            background: #dc2626;
            color: #ffffff;
        }

        .btn-danger:hover {
            background: #b91c1c;
        }

        .footer-link {
            text-align: center;
            margin-top: 16px;
        }

        .footer-link a {
            color: #6c63ff;
            text-decoration: none;
            font-weight: 600;
        }

        .footer-link a:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>
    <div class="card">
        <h1 class="title">Planora 2-Step Verification</h1>
        <p class="subtitle">Hello <?php echo htmlspecialchars($fullName); ?>. Control whether Planora asks for an email code after password login.</p>

        <?php if ($flashSuccess !== ''): ?>
            <div class="message success"><?php echo htmlspecialchars($flashSuccess); ?></div>
        <?php endif; ?>

        <?php if ($flashError !== ''): ?>
            <div class="message error"><?php echo htmlspecialchars($flashError); ?></div>
        <?php endif; ?>

        <?php if ($emailOtpEnabled): ?>
            <div class="status status-on">Email 2-step verification is ON for <?php echo htmlspecialchars($userEmail); ?>.</div>
            <div class="panel">
                <p>After you enter the correct password, we send a 6-digit code to your email. Entering that code completes login.</p>
                <form method="POST" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>">
                    <?php echo csrf_input(); ?>
                    <input type="hidden" name="action" value="disable_email_otp">
                    <div class="btn-row">
                        <button type="submit" class="btn btn-danger">Turn Off Email 2-Step</button>
                    </div>
                </form>
            </div>
        <?php else: ?>
            <div class="status status-off">Email 2-step verification is OFF for <?php echo htmlspecialchars($userEmail); ?>.</div>
            <div class="panel">
                <p>Turn this on to require a 6-digit email code after password login.</p>
                <form method="POST" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>">
                    <?php echo csrf_input(); ?>
                    <input type="hidden" name="action" value="enable_email_otp">
                    <div class="btn-row">
                        <button type="submit" class="btn btn-primary">Turn On Email 2-Step</button>
                    </div>
                </form>
            </div>
        <?php endif; ?>

        <div class="footer-link">
            <a href="<?php echo htmlspecialchars($returnPath); ?>">Back to Profile</a>
        </div>
    </div>
</body>
</html>
