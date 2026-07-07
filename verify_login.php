<?php
session_start();
require_once 'includes/csrf.php';
require_once 'includes/two_step.php';

$pending = two_step_pending_login();

if ($pending === null) {
    header('Location: login.php');
    exit;
}

$error = '';
$notice = 'We sent a verification code to ' . $pending['email'] . '.';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require_valid_post_token();

    $action = $_POST['action'] ?? 'verify';

    if ($action === 'resend') {
        $delivery = two_step_start_pending($pending, !empty($pending['password_needs_update']));

        if (!$delivery['email_sent']) {
            two_step_clear_pending();
            $error = 'We could not send a new verification code. Please log in again later.';
        } else {
            header('Location: verify_login.php');
            exit;
        }
    } else {
        $code = preg_replace('/\D+/', '', $_POST['verification_code'] ?? '');

        if (strlen($code) !== 6) {
            $error = 'Enter the 6-digit verification code.';
        } elseif (two_step_verify_code($code)) {
            if (!empty($_SESSION['force_password_change'])) {
                header('Location: change_password.php');
                exit;
            }
            header('Location: ' . two_step_dashboard_path($_SESSION['role']));
            exit;
        } elseif (two_step_pending_login() === null) {
            $error = 'The verification code expired or too many attempts were made. Please log in again.';
        } else {
            $error = 'Invalid verification code.';
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
    <title>Planora - Verify Login</title>
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
            padding: 20px;
        }

        .verify-container {
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
            margin-bottom: 10px;
            text-transform: uppercase;
        }

        .subtitle {
            color: #4b5563;
            font-size: 14px;
            line-height: 1.5;
            margin-bottom: 24px;
            text-align: center;
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
            padding: 14px;
            border: 1px solid #cccccc;
            border-radius: 6px;
            font-size: 20px;
            letter-spacing: 0;
            text-align: center;
            outline: none;
            transition: border-color 0.2s;
        }

        .form-group input:focus {
            border-color: #6C63FF;
        }

        .verify-btn,
        .resend-btn {
            width: 100%;
            border: none;
            padding: 14px;
            border-radius: 6px;
            font-size: 16px;
            font-weight: 500;
            cursor: pointer;
            transition: background-color 0.2s;
        }

        .verify-btn {
            background-color: #6C63FF;
            color: #FFFFFF;
            margin-top: 8px;
        }

        .verify-btn:hover {
            background-color: #5A52E0;
        }

        .resend-btn {
            background-color: transparent;
            color: #6C63FF;
            margin-top: 12px;
        }

        .resend-btn:hover {
            background-color: #f5f3ff;
        }

        .error-msg,
        .notice-msg {
            border-radius: 6px;
            font-size: 14px;
            line-height: 1.4;
            margin-bottom: 15px;
            padding: 10px 12px;
            text-align: center;
        }

        .error-msg {
            background: #fee2e2;
            color: #991b1b;
        }

        .notice-msg {
            background: #eef2ff;
            color: #3730a3;
        }

        .back-link {
            display: block;
            color: #6C63FF;
            font-size: 14px;
            margin-top: 18px;
            text-align: center;
            text-decoration: none;
        }

        .back-link:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>
    <div class="verify-container">
        <h1 class="brand-title">Planora</h1>
        <p class="subtitle">Enter the 6-digit code to finish signing in.</p>

        <?php if ($notice !== ''): ?>
            <div class="notice-msg"><?php echo htmlspecialchars($notice); ?></div>
        <?php endif; ?>

        <?php if ($error !== ''): ?>
            <div class="error-msg"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <form method="POST" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>">
            <?php echo csrf_input(); ?>
            <input type="hidden" name="action" value="verify">
            <div class="form-group">
                <label for="verification_code">Verification Code</label>
                <input type="text" id="verification_code" name="verification_code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code" required autofocus>
            </div>

            <button type="submit" class="verify-btn">Verify</button>
        </form>

        <form method="POST" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>">
            <?php echo csrf_input(); ?>
            <input type="hidden" name="action" value="resend">
            <button type="submit" class="resend-btn">Resend code</button>
        </form>

        <a href="logout.php" class="back-link">Cancel login</a>
    </div>
</body>
</html>
