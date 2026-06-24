<?php
session_start();
require_once 'config/db.php';
require_once 'includes/csrf.php';

$error = '';
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require_valid_post_token();

    $email = trim($_POST['email']);
    $password = $_POST['password'];

    if (empty($email) || empty($password)) {
        $error = 'Please fill in all fields.';
    } else {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password_hash'])) {
            $_SESSION['user_id']   = $user['user_id'];
            $_SESSION['full_name'] = $user['full_name'];
            $_SESSION['role']      = $user['role'];

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
            }
            exit;
        } else {
            $error = 'Invalid email or password.';
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

