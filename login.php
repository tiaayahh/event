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
}

session_start();
require_once 'config/db.php';
require_once 'includes/csrf.php';
require_once 'includes/audit.php';
require_once 'includes/two_step.php';
require_once 'includes/password_policy.php';

const LOGIN_MAX_ATTEMPTS = 5;
const LOGIN_WINDOW_MINUTES = 15;

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

$error = '';
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require_valid_post_token();

    $email = strtolower(trim($_POST['email']));
    $password = $_POST['password'];

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

        if ($user && password_verify($password, $user['password_hash'])) {
            $passwordNeedsUpdate = !password_policy_is_strong(
                $password,
                (string)$user['email'],
                (string)$user['full_name']
            );

            $clearAttempts = $pdo->prepare('DELETE FROM login_attempts WHERE email = ?');
            $clearAttempts->execute([$email]);

            if (!$passwordNeedsUpdate && two_step_user_is_trusted((int)$user['user_id'])) {
                session_regenerate_id(true);
                $_SESSION['user_id'] = $user['user_id'];
                $_SESSION['full_name'] = $user['full_name'];
                $_SESSION['role'] = $user['role'];
                $_SESSION['last_activity_at'] = time();

                audit_log(
                    $pdo,
                    (int)$user['user_id'],
                    (string)$user['role'],
                    'auth.login_success',
                    'user',
                    $email,
                    ['trusted_device' => true]
                );

                header('Location: ' . two_step_dashboard_path((string)$user['role']));
                exit;
            }

            $delivery = two_step_start_pending($user, $passwordNeedsUpdate);
            if (!$delivery['email_sent']) {
                two_step_clear_pending();
                $error = 'We could not send your verification code. Please contact support or try again later.';
            } else {
                audit_log(
                    $pdo,
                    (int)$user['user_id'],
                    (string)$user['role'],
                    'auth.login_challenge_sent',
                    'user',
                    $email
                );
                header('Location: verify_login.php');
                exit;
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
    </style>
</head>
<body>

    <div class="login-container">
        <h1 class="brand-title">Planora</h1>

        <?php if (!empty($error)): ?>
            <div class="error-msg"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <form action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>" method="POST">
            <?php echo csrf_input(); ?>
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

        <div class="register-text" style="margin-top: 10px;">
            <a href="forgot_password.php">Forgot password?</a>
        </div>

        <div class="register-text">
            Don't have an account? <a href="register.php">Register here</a>
        </div>
    </div>

</body>
</html>

