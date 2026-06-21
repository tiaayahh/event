<?php
session_start();

$isLoggedIn = isset($_SESSION['user_id']);
$fullName = $_SESSION['full_name'] ?? 'Guest';
$role = $_SESSION['role'] ?? '';

$dashboardPath = 'vendor.php';
if ($role === 'planner') {
    $dashboardPath = 'systemadmn.php';
} elseif ($role === 'attendee') {
    $dashboardPath = 'attendee.php';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Planora Home</title>
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
            background: linear-gradient(145deg, #f4f5f7, #eef0f4);
            min-height: 100vh;
            color: #2d2d2d;
        }

        .topbar {
            background: #635bff;
            color: #ffffff;
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 16px 22px;
        }

        .brand {
            font-size: 22px;
            font-weight: 700;
            letter-spacing: 1px;
        }

        .top-actions a {
            color: #ffffff;
            text-decoration: none;
            background: rgba(255, 255, 255, 0.18);
            border-radius: 8px;
            padding: 9px 14px;
            font-size: 14px;
            margin-left: 8px;
        }

        .hero {
            max-width: 980px;
            margin: 34px auto;
            padding: 0 18px;
        }

        .hero-card {
            background: #ffffff;
            border: 1px solid #e5e8ef;
            border-radius: 12px;
            padding: 28px;
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.04);
        }

        .hero-card h1 {
            font-size: 30px;
            margin-bottom: 10px;
            color: #1e1e1e;
        }

        .hero-card p {
            color: #5a6270;
            line-height: 1.5;
            margin-bottom: 20px;
        }

        .actions {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
        }

        .btn {
            text-decoration: none;
            border-radius: 8px;
            padding: 11px 16px;
            font-size: 14px;
            font-weight: 600;
            display: inline-block;
        }

        .btn-primary {
            background: #635bff;
            color: #ffffff;
        }

        .btn-secondary {
            background: #ece9ff;
            color: #39327f;
        }

        .status {
            margin-top: 16px;
            font-size: 14px;
            color: #4b5563;
        }
    </style>
</head>
<body>
    <header class="topbar">
        <div class="brand">PLANORA</div>
        <div class="top-actions">
            <?php if ($isLoggedIn): ?>
                <a href="logout.php">Logout</a>
            <?php else: ?>
                <a href="login.php">Login</a>
                <a href="register.php">Register</a>
            <?php endif; ?>
        </div>
    </header>

    <main class="hero">
        <section class="hero-card">
            <h1>Welcome to Planora</h1>
            <p>Plan, manage, and monitor your events from one place. This home page now acts as the central entry for login, registration, and dashboard access.</p>

            <div class="actions">
                <?php if ($isLoggedIn): ?>
                    <a class="btn btn-primary" href="<?php echo htmlspecialchars($dashboardPath); ?>">Open Dashboard</a>
                    <a class="btn btn-secondary" href="logout.php">Sign Out</a>
                <?php else: ?>
                    <a class="btn btn-primary" href="login.php">Sign In</a>
                    <a class="btn btn-secondary" href="register.php">Create Account</a>
                <?php endif; ?>
            </div>

            <p class="status">
                <?php if ($isLoggedIn): ?>
                    Signed in as <?php echo htmlspecialchars($fullName); ?><?php echo $role !== '' ? ' (' . htmlspecialchars($role) . ')' : ''; ?>.
                <?php else: ?>
                    You are currently browsing as guest.
                <?php endif; ?>
            </p>
        </section>
    </main>
</body>
</html>
