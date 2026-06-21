<?php
require_once 'config/db.php';

$error = '';
$success = '';
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = isset($_POST['email']) ? trim($_POST['email']) : '';
    $newPassword = isset($_POST['new_password']) ? $_POST['new_password'] : '';
    $confirmPassword = isset($_POST['confirm_password']) ? $_POST['confirm_password'] : '';

    if ($email === '' || $newPassword === '' || $confirmPassword === '') {
        $error = 'Please fill in all fields.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Enter a valid email address.';
    } elseif (strlen($newPassword) < 6) {
        $error = 'Password must be at least 6 characters.';
    } elseif ($newPassword !== $confirmPassword) {
        $error = 'Passwords do not match.';
    } else {
        $stmt = $pdo->prepare('SELECT user_id FROM users WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if (!$user) {
            $error = 'No account found with that email.';
        } else {
            $passwordHash = password_hash($newPassword, PASSWORD_BCRYPT);
            $update = $pdo->prepare('UPDATE users SET password_hash = ? WHERE user_id = ?');
            $update->execute([$passwordHash, $user['user_id']]);
            $success = 'Password updated successfully. You can now login.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Planora - Forgot Password</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
        }

        body {
            background-color: #f6f6f6;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            padding: 20px;
        }

        .window-container {
            background-color: #ffffff;
            border: 1px solid #e2e2e2;
            border-radius: 4px;
            width: 100%;
            max-width: 530px;
            padding: 50px 35px 35px 35px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.03);
        }

        .brand-title {
            color: #635BFF;
            font-size: 32px;
            font-weight: 700;
            text-align: center;
            letter-spacing: 1px;
            margin-bottom: 25px;
            text-transform: uppercase;
        }

        .form-box {
            border: 1px solid #dcdcdc;
            border-radius: 6px;
            padding: 30px 25px;
        }

        .form-group {
            margin-bottom: 18px;
        }

        .form-group label {
            display: block;
            font-size: 15px;
            font-weight: 700;
            color: #2D2D2D;
            margin-bottom: 8px;
        }

        .form-group input {
            width: 100%;
            padding: 14px 12px;
            border: 1px solid #cccccc;
            border-radius: 6px;
            font-size: 16px;
            color: #333333;
            outline: none;
            transition: border-color 0.2s;
        }

        .form-group input:focus {
            border-color: #635BFF;
        }

        .btn-reset {
            width: 100%;
            background-color: #635BFF;
            color: #ffffff;
            border: none;
            border-radius: 6px;
            padding: 14px;
            font-size: 18px;
            font-weight: 400;
            cursor: pointer;
            margin-top: 5px;
            margin-bottom: 16px;
            transition: background-color 0.2s;
        }

        .btn-reset:hover {
            background-color: #5249eb;
        }

        .message {
            font-size: 14px;
            margin-bottom: 12px;
            text-align: center;
        }

        .message.error {
            color: #d93025;
        }

        .message.success {
            color: #1e8e3e;
        }

        .footer-text {
            text-align: center;
            font-size: 15px;
            color: #2D2D2D;
        }

        .footer-text a {
            color: #635BFF;
            text-decoration: none;
            margin-left: 3px;
        }

        .footer-text a:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>
    <div class="window-container">
        <h1 class="brand-title">PLANORA</h1>
        <div class="form-box">
            <?php if ($error !== ''): ?>
                <div class="message error"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
            <?php if ($success !== ''): ?>
                <div class="message success"><?php echo htmlspecialchars($success); ?></div>
            <?php endif; ?>

            <form method="POST" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>">
                <div class="form-group">
                    <label for="email">Account Email</label>
                    <input type="email" id="email" name="email" value="<?php echo htmlspecialchars($email); ?>" required>
                </div>

                <div class="form-group">
                    <label for="new_password">New Password</label>
                    <input type="password" id="new_password" name="new_password" required>
                </div>

                <div class="form-group">
                    <label for="confirm_password">Confirm Password</label>
                    <input type="password" id="confirm_password" name="confirm_password" required>
                </div>

                <button type="submit" class="btn-reset">Update Password</button>

                <div class="footer-text">
                    Remembered it?<a href="login.php">Back to Login</a>
                </div>
            </form>
        </div>
    </div>
</body>
</html>
