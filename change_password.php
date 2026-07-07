<?php
require_once 'config/db.php';
require_once 'includes/csrf.php';
require_once 'includes/password_policy.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$error = '';
$success = '';
$mustChange = !empty($_SESSION['force_password_change']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require_valid_post_token();

    $currentPassword = $_POST['current_password'] ?? '';
    $newPassword = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    try {
        $stmt = $pdo->prepare('SELECT email, full_name, password_hash, role FROM users WHERE user_id = ? LIMIT 1');
        $stmt->execute([$_SESSION['user_id']]);
        $user = $stmt->fetch();

        if (!$user) {
            $error = 'Account not found.';
        } elseif ($currentPassword === '' || $newPassword === '' || $confirmPassword === '') {
            $error = 'Please fill in all password fields.';
        } elseif (!password_verify($currentPassword, $user['password_hash'])) {
            $error = 'Current password is incorrect.';
        } elseif ($newPassword !== $confirmPassword) {
            $error = 'New passwords do not match.';
        } elseif (!password_policy_is_strong($newPassword, (string)$user['email'], (string)$user['full_name'])) {
            $error = password_policy_message();
        } else {
            $passwordHash = password_hash($newPassword, PASSWORD_BCRYPT);
            $stmt = $pdo->prepare('UPDATE users SET password_hash = ? WHERE user_id = ?');
            $stmt->execute([$passwordHash, $_SESSION['user_id']]);
            unset($_SESSION['force_password_change']);
            $_SESSION['flash_success'] = 'Password updated successfully.';

            switch ($user['role']) {
                case 'planner':
                    header('Location: admin/dashboard.php');
                    break;
                case 'vendor':
                    header('Location: vendor/dashboard.php');
                    break;
                case 'attendee':
                    header('Location: attendee/dashboard.php');
                    break;
                default:
                    header('Location: index.php');
            }
            exit;
        }
    } catch (Throwable $e) {
        $error = 'Unable to update password right now.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Planora - Change Password</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }
        body { min-height: 100vh; background: #F5F5F5; display: flex; align-items: center; justify-content: center; padding: 20px; color: #2D2D2D; }
        .card { width: 100%; max-width: 460px; background: #fff; border: 1px solid #E5E7EB; border-radius: 12px; padding: 32px; box-shadow: 0 8px 24px rgba(0,0,0,.06); }
        .brand { color: #6C63FF; font-size: 28px; font-weight: 800; text-align: center; margin-bottom: 8px; }
        .subtitle { color: #666; font-size: 14px; line-height: 1.5; text-align: center; margin-bottom: 22px; }
        .notice, .error { border-radius: 8px; padding: 10px 12px; margin-bottom: 14px; font-size: 13px; line-height: 1.45; }
        .notice { background: #EEF2FF; color: #3730A3; border: 1px solid #C7D2FE; }
        .error { background: #FFECEC; color: #9D2020; border: 1px solid #F6BDBD; }
        .field { margin-bottom: 14px; }
        label { display: block; font-size: 13px; font-weight: 700; margin-bottom: 6px; }
        input { width: 100%; border: 1px solid #D6D6D6; border-radius: 8px; padding: 12px 14px; font-size: 14px; }
        input:focus { outline: none; border-color: #6C63FF; }
        .btn { width: 100%; background: #6C63FF; color: #fff; border: none; border-radius: 8px; padding: 13px; font-size: 15px; font-weight: 700; cursor: pointer; }
        .btn:hover { background: #5A52E0; }
        .links { text-align: center; margin-top: 16px; font-size: 13px; }
        .links a { color: #6C63FF; text-decoration: none; font-weight: 700; }
    </style>
</head>
<body>
    <main class="card">
        <div class="brand">PLANORA</div>
        <p class="subtitle">Update your password using at least 8 characters with uppercase and lowercase letters, a number, and a special character.</p>
        <?php if ($mustChange): ?>
            <div class="notice">Your current password is weak. Please change it before continuing.</div>
        <?php endif; ?>
        <?php if ($error !== ''): ?>
            <div class="error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        <form method="POST">
            <?php echo csrf_input(); ?>
            <div class="field">
                <label for="current_password">Current Password</label>
                <input type="password" id="current_password" name="current_password" required>
            </div>
            <div class="field">
                <label for="new_password">New Password</label>
                <input type="password" id="new_password" name="new_password" required>
            </div>
            <div class="field">
                <label for="confirm_password">Confirm New Password</label>
                <input type="password" id="confirm_password" name="confirm_password" required>
            </div>
            <button class="btn" type="submit">Update Password</button>
        </form>
        <div class="links">
            <a href="forgot_password.php">Forgot current password?</a>
        </div>
    </main>
</body>
</html>
