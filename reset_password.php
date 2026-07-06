<?php
require_once 'config/db.php';
require_once 'includes/csrf.php';

$error = '';
$success = '';
$token = isset($_GET['token']) ? trim($_GET['token']) : '';

function ensurePasswordResetTable(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS password_resets (
            reset_id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            token_hash VARCHAR(255) NOT NULL,
            expires_at DATETIME NOT NULL,
            used_at DATETIME DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_password_resets_user (user_id),
            INDEX idx_password_resets_expires (expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}

function findActiveReset(PDO $pdo, string $token): ?array
{
    if ($token === '') {
        return null;
    }

    $stmt = $pdo->query(
        "SELECT reset_id, user_id, token_hash
         FROM password_resets
         WHERE used_at IS NULL
           AND expires_at > NOW()"
    );

    while ($row = $stmt->fetch()) {
        if (password_verify($token, $row['token_hash'])) {
            return $row;
        }
    }

    return null;
}

function isStrongPassword(string $password): bool
{
    if (strlen($password) < 8) {
        return false;
    }

    return preg_match('/[A-Z]/', $password)
        && preg_match('/[a-z]/', $password)
        && preg_match('/[0-9]/', $password)
        && preg_match('/[^A-Za-z0-9]/', $password);
}

try {
    ensurePasswordResetTable($pdo);
} catch (Throwable $e) {
    $error = 'Password reset service is not available right now.';
}

$resetRequest = null;
if ($error === '') {
    $resetRequest = findActiveReset($pdo, $token);
    if (!$resetRequest) {
        $error = 'This reset link is invalid or has expired.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $error === '') {
    csrf_require_valid_post_token();

    $newPassword = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    if ($newPassword === '' || $confirmPassword === '') {
        $error = 'Please fill in all fields.';
    } elseif (!isStrongPassword($newPassword)) {
        $error = 'Password must be at least 8 characters and include uppercase, lowercase, number, and symbol.';
    } elseif ($newPassword !== $confirmPassword) {
        $error = 'Passwords do not match.';
    } else {
        try {
            $passwordHash = password_hash($newPassword, PASSWORD_BCRYPT);

            $pdo->beginTransaction();

            $updateUser = $pdo->prepare('UPDATE users SET password_hash = ? WHERE user_id = ?');
            $updateUser->execute([$passwordHash, $resetRequest['user_id']]);

            $markUsed = $pdo->prepare('UPDATE password_resets SET used_at = NOW() WHERE reset_id = ?');
            $markUsed->execute([$resetRequest['reset_id']]);

            $pdo->commit();
            $success = 'Password updated successfully. You can now login.';
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = 'Unable to update password. Please try again.';
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
    <title>Planora - Reset Password</title>
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

        .password-hint {
            font-size: 12px;
            color: #666;
            margin-top: -10px;
            margin-bottom: 14px;
            line-height: 1.5;
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

            <?php if ($success === '' && $error === ''): ?>
                <form method="POST" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF'] . '?token=' . urlencode($token)); ?>">
                    <?php echo csrf_input(); ?>
                    <div class="form-group">
                        <label for="new_password">New Password</label>
                        <input type="password" id="new_password" name="new_password" required>
                    </div>

                    <p class="password-hint">Use at least 8 characters with uppercase, lowercase, number, and symbol.</p>

                    <div class="form-group">
                        <label for="confirm_password">Confirm Password</label>
                        <input type="password" id="confirm_password" name="confirm_password" required>
                    </div>

                    <button type="submit" class="btn-reset">Update Password</button>
                </form>
            <?php endif; ?>

            <div class="footer-text">
                Remembered it?<a href="login.php">Back to Login</a>
            </div>
        </div>
    </div>
</body>
</html>


