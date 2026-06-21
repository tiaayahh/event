<?php
session_start();
require_once 'config/db.php';

$error = '';
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = isset($_POST['email']) ? trim($_POST['email']) : '';
    $password = isset($_POST['password']) ? $_POST['password'] : '';

    if ($email === '' || $password === '') {
        $error = 'Please fill in all fields.';
    } else {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ? LIMIT 1");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password_hash'])) {
            $_SESSION['user_id'] = $user['user_id'];
            $_SESSION['full_name'] = $user['full_name'];
            $_SESSION['role'] = $user['role'];

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
    <title>Planora Login</title>
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
            margin-bottom: 22px;
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

        .btn-login {
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
            margin-bottom: 20px;
            transition: background-color 0.2s;
        }

        .btn-login:hover {
            background-color: #5249eb;
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

        .error-msg {
            color: #d93025;
            font-size: 14px;
            margin-bottom: 15px;
            text-align: center;
        }
    </style>
</head>
<body>
    <div class="window-container">
        <h1 class="brand-title">PLANORA</h1>
        <div class="form-box">
            <?php if (!empty($error)): ?>
                <div class="error-msg"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <form action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>" method="POST">
                <div class="form-group">
                    <label for="email">Email</label>
                    <input type="email" id="email" name="email" value="<?php echo htmlspecialchars($email); ?>" required>
                </div>

                <div class="form-group">
                    <label for="password">Password</label>
                    <input type="password" id="password" name="password" required>
                </div>

                <button type="submit" class="btn-login">Login</button>

                <div class="footer-text">
                    Don't have an account?<a href="register.php">Register here</a>
                </div>
                <div class="footer-text" style="margin-top:10px;">
                    Forgot your password?<a href="forgot_password.php">Reset </a>
                </div>
            </form>
        </div>
    </div>
</body>
</html>