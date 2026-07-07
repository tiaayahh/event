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
require_once 'two_factor_setup.php';
require_once 'includes/password_policy.php';
require_once 'includes/mailer.php';
require_once 'includes/totp.php';

const LOGIN_MAX_ATTEMPTS = 5;
const LOGIN_WINDOW_MINUTES = 15;
const LOGIN_TOTP_MAX_ATTEMPTS = 5;

function ensureLoginAttemptsTable(PDO $pdo): void
{
    static $initialized = false;
    if ($initialized) {
        return;
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS login_attempts (
            attempt_id BIGINT AUTO_INCREMENT PRIMARY KEY,
            email VARCHAR(190) NOT NULL,
            attempted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_login_attempts_email_time (email, attempted_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $initialized = true;
}

function sendLoginNotificationEmail(array $user): void
{
    $to = trim((string)($user['email'] ?? ''));
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return;
    }

    $appName = 'Planora';
    $loginTime = date('Y-m-d H:i:s');
    $ipAddress = (string)($_SERVER['REMOTE_ADDR'] ?? 'Unknown');
    $userAgent = (string)($_SERVER['HTTP_USER_AGENT'] ?? 'Unknown');

    $subject = $appName . ' login alert';
    $name = (string)($user['full_name'] ?? 'User');
    $html = '<p>Hello ' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . ',</p>'
        . '<p>A login to your ' . htmlspecialchars($appName, ENT_QUOTES, 'UTF-8') . ' account was detected.</p>'
        . '<p><strong>Time:</strong> ' . htmlspecialchars($loginTime, ENT_QUOTES, 'UTF-8') . '<br>'
        . '<strong>IP:</strong> ' . htmlspecialchars($ipAddress, ENT_QUOTES, 'UTF-8') . '<br>'
        . '<strong>Device:</strong> ' . htmlspecialchars($userAgent, ENT_QUOTES, 'UTF-8') . '</p>'
        . '<p>If this was not you, reset your password immediately.</p>'
        . '<p>- ' . htmlspecialchars($appName, ENT_QUOTES, 'UTF-8') . ' Security</p>';

    $text = "Hello " . $name . ",\n\n"
        . "A login to your " . $appName . " account was detected.\n\n"
        . "Time: " . $loginTime . "\n"
        . "IP: " . $ipAddress . "\n"
        . "Device: " . $userAgent . "\n\n"
        . "If this was not you, reset your password immediately.\n\n"
        . "- " . $appName . " Security";

    $result = send_platform_email($to, $name, $subject, $html, $text);
    if (empty($result['success'])) {
        error_log('login.php mail warning: failed to send login notification to ' . $to);
    }
}

function getPendingOtpContext(): array
{
    return [
        'user_id' => (int)($_SESSION['otp_user_id'] ?? 0),
        'email' => (string)($_SESSION['otp_email'] ?? ''),
        'role' => (string)($_SESSION['otp_role'] ?? ''),
        'full_name' => (string)($_SESSION['otp_full_name'] ?? ''),
        'method' => (string)($_SESSION['otp_method'] ?? 'email'),
        'totp_attempts_remaining' => (int)($_SESSION['otp_totp_attempts_remaining'] ?? LOGIN_TOTP_MAX_ATTEMPTS),
    ];
}

function clearPendingOtpContext(): void
{
    unset(
        $_SESSION['otp_user_id'],
        $_SESSION['otp_email'],
        $_SESSION['otp_role'],
        $_SESSION['otp_full_name'],
        $_SESSION['otp_method'],
        $_SESSION['otp_totp_attempts_remaining']
    );
}

function getUserTotpSettings(PDO $pdo, int $userId): ?array
{
    totp_ensure_table($pdo);

    $stmt = $pdo->prepare(
        'SELECT user_id, secret_key, is_enabled, verified_at FROM user_totp_auth WHERE user_id = ? LIMIT 1'
    );
    $stmt->execute([$userId]);
    $row = $stmt->fetch();

    return $row ?: null;
}

function completeLoginAndRedirect(array $user, PDO $pdo): void
{
    session_regenerate_id(true);
    $_SESSION['user_id']   = (int)$user['user_id'];
    $_SESSION['full_name'] = (string)$user['full_name'];
    $_SESSION['role']      = (string)$user['role'];
    $_SESSION['last_activity_at'] = time();

    clearPendingOtpContext();

    $clearAttempts = $pdo->prepare('DELETE FROM login_attempts WHERE email = ?');
    $clearAttempts->execute([(string)$user['email']]);

    audit_log(
        $pdo,
        (int)$user['user_id'],
        (string)$user['role'],
        'auth.login_success'
    );

    sendLoginNotificationEmail($user);

    switch ((string)$user['role']) {
        case 'planner':
            header('Location: admin/dashboard.php', true, 303);
            break;
        case 'vendor':
            header('Location: vendor/dashboard.php', true, 303);
            break;
        case 'attendee':
            header('Location: attendee/dashboard.php', true, 303);
            break;
        default:
            header('Location: login.php', true, 303);
            break;
    }
    exit;
}

$error = '';
$email = '';
$info = '';
$otpStep = isset($_SESSION['otp_user_id']);
$otpMethod = (string)($_SESSION['otp_method'] ?? 'email');

if ($otpStep && $otpMethod !== 'totp') {
    clearPendingOtpContext();
    $otpStep = false;
    $otpMethod = 'email';
}

if (isset($_SESSION['login_error'])) {
    $error = (string)$_SESSION['login_error'];
    unset($_SESSION['login_error']);
}
if (isset($_SESSION['login_email'])) {
    $email = (string)$_SESSION['login_email'];
    unset($_SESSION['login_email']);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    header('Cache-Control: private, max-age=120, must-revalidate');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($_POST)) {
        header('Location: login.php', true, 303);
        exit;
    }

    if (!csrf_validate_post_token()) {
        $_SESSION['login_email'] = strtolower(trim((string)($_POST['email'] ?? '')));
        header('Location: login.php', true, 303);
        exit;
    }

    $formStep = strtolower(trim((string)($_POST['step'] ?? 'credentials')));

    if ($formStep === 'verify_otp') {
        $otpCode = trim((string)($_POST['otp_code'] ?? ''));
        $otpContext = getPendingOtpContext();
        $pendingMethod = (string)($otpContext['method'] ?? 'email');

        if ($otpContext['user_id'] <= 0 || $otpContext['email'] === '') {
            $error = 'Verification session expired. Please log in again.';
            clearPendingOtpContext();
        } elseif (!preg_match('/^[0-9]{6}$/', $otpCode)) {
            $error = 'Enter the 6-digit verification code.';
            $otpStep = true;
            $otpMethod = $pendingMethod;
            $email = $otpContext['email'];
        } else {
            $totp = getUserTotpSettings($pdo, (int)$otpContext['user_id']);
            if (!$totp || empty($totp['is_enabled']) || empty($totp['secret_key'])) {
                $error = 'Authenticator app is not enabled for this account. Please log in again.';
                clearPendingOtpContext();
            } else {
                $verified = totp_verify_code((string)$totp['secret_key'], $otpCode, 1, 30, 6);

                if (!$verified) {
                    $remaining = max(0, ((int)$otpContext['totp_attempts_remaining']) - 1);
                    $_SESSION['otp_totp_attempts_remaining'] = $remaining;

                    audit_log(
                        $pdo,
                        (int)$otpContext['user_id'],
                        (string)$otpContext['role'],
                        'auth.totp_failed',
                        'user',
                        (string)$otpContext['email'],
                        ['attempts_remaining' => $remaining]
                    );

                    if ($remaining <= 0) {
                        $error = 'Too many invalid authenticator codes. Please log in again.';
                        clearPendingOtpContext();
                    } else {
                        $error = 'Invalid authenticator code. Attempts left: ' . $remaining . '.';
                        $otpStep = true;
                        $otpMethod = 'totp';
                        $email = $otpContext['email'];
                    }
                } else {
                    $userStmt = $pdo->prepare('SELECT * FROM users WHERE user_id = ? LIMIT 1');
                    $userStmt->execute([$otpContext['user_id']]);
                    $user = $userStmt->fetch();

                    if (!$user) {
                        $error = 'Account not found. Please log in again.';
                        clearPendingOtpContext();
                    } else {
                        audit_log(
                            $pdo,
                            (int)$otpContext['user_id'],
                            (string)$otpContext['role'],
                            'auth.totp_verified',
                            'user',
                            (string)$otpContext['email']
                        );
                        completeLoginAndRedirect($user, $pdo);
                    }
                }
            }
        }
    } else {
        $email = strtolower(trim((string)($_POST['email'] ?? '')));
        $password = (string)($_POST['password'] ?? '');

        if (empty($email) || empty($password)) {
            $error = 'Please fill in all fields.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid email address.';
        } else {
            ensureLoginAttemptsTable($pdo);

            $attemptCheck = $pdo->prepare(
                "SELECT COUNT(*) AS failed_attempts
                 FROM login_attempts
                 WHERE email = ?
                   AND attempted_at >= (NOW() - INTERVAL " . LOGIN_WINDOW_MINUTES . " MINUTE)"
            );
            $attemptCheck->execute([$email]);
            $failedAttempts = (int)($attemptCheck->fetch()['failed_attempts'] ?? 0);

            if ($failedAttempts >= LOGIN_MAX_ATTEMPTS) {
                audit_log(
                    $pdo,
                    null,
                    null,
                    'auth.login_blocked',
                    'user',
                    $email,
                    ['window_minutes' => LOGIN_WINDOW_MINUTES]
                );
                $error = 'Too many failed attempts. Please wait 15 minutes and try again.';
            } else {
                $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
                $stmt->execute([$email]);
                $user = $stmt->fetch();

                if ($user && password_verify($password, (string)$user['password_hash'])) {
                    $totp = getUserTotpSettings($pdo, (int)$user['user_id']);
                    $totpEnabled = $totp && !empty($totp['is_enabled']) && !empty($totp['secret_key']);

                    if ($totpEnabled) {
                        $_SESSION['otp_user_id'] = (int)$user['user_id'];
                        $_SESSION['otp_email'] = (string)$user['email'];
                        $_SESSION['otp_role'] = (string)$user['role'];
                        $_SESSION['otp_full_name'] = (string)$user['full_name'];
                        $_SESSION['otp_method'] = 'totp';
                        $_SESSION['otp_totp_attempts_remaining'] = LOGIN_TOTP_MAX_ATTEMPTS;

                        audit_log(
                            $pdo,
                            (int)$user['user_id'],
                            (string)$user['role'],
                            'auth.totp_challenge_started',
                            'user',
                            $email
                        );

                        $info = 'Enter the code from your authenticator app to continue.';
                        $otpStep = true;
                        $otpMethod = 'totp';
                    } else {
                        completeLoginAndRedirect($user, $pdo);
                    }
                } else {
                    $storeAttempt = $pdo->prepare('INSERT INTO login_attempts (email) VALUES (?)');
                    $storeAttempt->execute([$email]);

                    audit_log(
                        $pdo,
                        null,
                        null,
                        'auth.login_failed',
                        'user',
                        $email,
                        ['reason' => 'invalid_credentials']
                    );
                    $error = 'Invalid email or password.';
                }
            }
        }
    }

    if ($error !== '' && !$otpStep) {
        $_SESSION['login_error'] = $error;
        $_SESSION['login_email'] = $email;
        header('Location: login.php', true, 303);
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="assets/style.css">
    <title>Planora - Login</title>
    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
        }

        body {
            background-color: #f3f4f6;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
        }

        .login-container {
            background-color: #ffffff;
            padding: 40px;
            border-radius: 12px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
            width: 100%;
            max-width: 440px;
            border: 1px solid #e5e7eb;
        }

        .brand-title {
            color: #6C63FF;
            font-size: 28px;
            font-weight: 700;
            text-align: center;
            letter-spacing: 1px;
            margin-bottom: 30px;
            text-transform: uppercase;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            font-size: 14px;
            font-weight: 600;
            color: #2D2D2D;
            margin-bottom: 8px;
        }

        .form-group input {
            width: 100%;
            padding: 12px;
            border: 1px solid #cccccc;
            border-radius: 6px;
            font-size: 15px;
            outline: none;
            transition: border-color 0.2s;
        }

        .form-group input:focus {
            border-color: #6C63FF;
        }

        .login-btn {
            width: 100%;
            background-color: #6C63FF;
            color: #FFFFFF;
            border: none;
            padding: 14px;
            border-radius: 6px;
            font-size: 16px;
            font-weight: 500;
            cursor: pointer;
            transition: background-color 0.2s;
            margin-top: 8px;
        }

        .login-btn:hover {
            background-color: #5A52E0;
        }

        .register-text {
            text-align: center;
            margin-top: 20px;
            font-size: 14px;
            color: #2D2D2D;
        }

        .register-text a {
            color: #6C63FF;
            text-decoration: none;
            font-weight: 500;
        }

        .register-text a:hover {
            text-decoration: underline;
        }

        .error-msg {
            color: #d93025;
            font-size: 14px;
            text-align: center;
            margin-bottom: 15px;
        }

        .info-msg {
            color: #0f766e;
            font-size: 14px;
            text-align: center;
            margin-bottom: 15px;
        }

        .link-btn {
            background: transparent;
            border: none;
            color: #6C63FF;
            text-decoration: underline;
            cursor: pointer;
            font-size: 14px;
            padding: 0;
        }

        .link-btn[disabled] {
            color: #9ca3af;
            cursor: not-allowed;
            text-decoration: none;
        }

        .hint-msg {
            color: #6b7280;
            font-size: 13px;
            text-align: center;
            margin-top: 6px;
        }
    </style>
</head>
<body>

    <div class="login-container">
        <h1 class="brand-title">Planora</h1>

        <?php if (!empty($error)): ?>
            <div class="error-msg"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        <?php if (!empty($info)): ?>
            <div class="info-msg"><?php echo htmlspecialchars($info); ?></div>
        <?php endif; ?>

        <?php if ($otpStep): ?>
            <form action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>" method="POST">
                <?php echo csrf_input(); ?>
                <input type="hidden" name="step" value="verify_otp">

                <div class="form-group">
                    <label for="otp_code">Authenticator Code</label>
                    <input type="text" id="otp_code" name="otp_code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" placeholder="Enter 6-digit code" required>
                </div>

                <button type="submit" class="login-btn">Verify &amp; Continue</button>
            </form>

            <div class="register-text" style="margin-top: 10px;">
                Open your authenticator app and enter the current 6-digit code.
            </div>
            <div class="register-text" style="margin-top: 6px;">
                <a href="login.php">Start over</a>
            </div>
        <?php else: ?>
            <form action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>" method="POST">
                <?php echo csrf_input(); ?>
                <input type="hidden" name="step" value="credentials">
                <div class="form-group">
                    <label for="email">Email</label>
                    <input type="email" id="email" name="email" value="<?php echo htmlspecialchars($email); ?>" required>
                </div>

                <div class="form-group">
                    <label for="password">Password</label>
                    <input type="password" id="password" name="password" required>
                </div>

                <button type="submit" class="login-btn">Login</button>
            </form>
        <?php endif; ?>

        <div class="register-text" style="margin-top: 10px;">
            <a href="forgot_password.php">Forgot password?</a>
        </div>

        <div class="register-text" style="margin-top: 8px;">
            After login, set up Authenticator 2FA at <a href="two_factor_setup.php">2FA Setup</a>
        </div>

        <div class="register-text">
            Don't have an account? <a href="register.php">Register here</a>
        </div>
    </div>

</body>
</html>

