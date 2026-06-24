<?php
session_start();

$isLoggedIn = isset($_SESSION['user_id']);
$fullName = $_SESSION['full_name'] ?? 'Guest';
$role = $_SESSION['role'] ?? '';

$dashboardPath = 'vendor/dashboard.php';
if ($role === 'planner') {
    $dashboardPath = 'admin/dashboard.php';
} elseif ($role === 'attendee') {
    $dashboardPath = 'attendee/dashboard.php';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="assets/style.css">
    <title>Planora - Plan, Manage, and Monitor Events</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,400;14..32,500;14..32,600;14..32,700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #6C63FF;
            --primary-light: #B8A8FF;
            --primary-soft: #F0EEFF;
            --bg: #F8F7FF;
            --text: #2D2D2D;
            --text-soft: #5A5A5A;
            --white: #FFFFFF;
            --card-border: #E8E6F0;
            --shadow-sm: 0 2px 8px rgba(108, 99, 255, 0.06);
            --shadow-md: 0 8px 28px rgba(108, 99, 255, 0.08);
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            font-family: 'Inter', sans-serif;
        }

        body {
            background: linear-gradient(160deg, #F5F3FF 0%, #F8F7FF 40%, #F5F5F5 100%);
            color: var(--text);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        
        .topbar {
            background: var(--primary);
            color: var(--white);
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 16px 28px;
            position: relative;
            box-shadow: 0 2px 12px rgba(108, 99, 255, 0.15);
        }

        .brand {
            font-size: 24px;
            font-weight: 700;
            letter-spacing: 1px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .brand i {
            font-size: 22px;
            opacity: 0.9;
        }

        .top-actions {
            position: absolute;
            right: 28px;
            top: 50%;
            transform: translateY(-50%);
            display: flex;
            gap: 10px;
        }

        .top-actions a {
            color: var(--white);
            text-decoration: none;
            background: rgba(255, 255, 255, 0.2);
            border: 1px solid rgba(255, 255, 255, 0.3);
            border-radius: 8px;
            padding: 8px 16px;
            font-size: 14px;
            font-weight: 500;
            transition: all 0.2s ease;
        }

        .top-actions a:hover {
            background: rgba(255, 255, 255, 0.35);
            border-color: rgba(255, 255, 255, 0.5);
        }

        
        .hero {
            max-width: 960px;
            margin: 50px auto 0;
            padding: 0 24px;
        }

        .hero-card {
            background: var(--white);
            border: 1px solid var(--card-border);
            border-radius: 20px;
            padding: 48px 40px;
            box-shadow: var(--shadow-md);
            text-align: center;
            position: relative;
            overflow: hidden;
        }

        .hero-card::before {
            content: '';
            position: absolute;
            top: -60px;
            right: -60px;
            width: 180px;
            height: 180px;
            background: radial-gradient(circle, rgba(108,99,255,0.08) 0%, transparent 70%);
            border-radius: 50%;
            pointer-events: none;
        }

        .hero-card h1 {
            font-size: 38px;
            font-weight: 700;
            margin-bottom: 16px;
            color: var(--text);
            line-height: 1.2;
            position: relative;
        }

        .hero-card p.subhead {
            font-size: 18px;
            color: var(--text-soft);
            margin-bottom: 30px;
            line-height: 1.6;
            max-width: 650px;
            margin-left: auto;
            margin-right: auto;
            position: relative;
        }

        .actions {
            display: flex;
            flex-wrap: wrap;
            justify-content: center;
            gap: 14px;
            margin-bottom: 24px;
            position: relative;
        }

        .btn {
            text-decoration: none;
            border-radius: 10px;
            padding: 13px 24px;
            font-size: 15px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.25s ease;
        }

        .btn-primary {
            background: var(--primary);
            color: var(--white);
            box-shadow: 0 4px 12px rgba(108, 99, 255, 0.25);
        }

        .btn-primary:hover {
            background: #5A52E0;
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(108, 99, 255, 0.35);
        }

        .btn-secondary {
            background: var(--primary-soft);
            color: var(--primary);
            border: 1px solid var(--primary-light);
        }

        .btn-secondary:hover {
            background: var(--primary-light);
            color: #2D2D2D;
            transform: translateY(-2px);
        }

        .status {
            margin-top: 24px;
            font-size: 14px;
            color: #4B5563;
            background: #F9F9FF;
            display: inline-block;
            padding: 8px 20px;
            border-radius: 30px;
            font-weight: 500;
        }

        .status i {
            margin-right: 6px;
            color: var(--primary);
        }

        /* ---------- Sections ---------- */
        .section {
            max-width: 960px;
            margin: 50px auto;
            padding: 0 24px;
        }

        .section-title {
            font-size: 30px;
            font-weight: 700;
            margin-bottom: 16px;
            text-align: center;
            color: var(--text);
        }

        .section-text {
            text-align: center;
            max-width: 750px;
            margin: 0 auto 35px;
            color: var(--text-soft);
            line-height: 1.7;
            font-size: 17px;
        }

        
        .features-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 24px;
            margin-top: 30px;
        }

        .feature-card {
            background: var(--white);
            border: 1px solid var(--card-border);
            border-radius: 16px;
            padding: 28px 24px;
            text-align: center;
            transition: all 0.25s ease;
            box-shadow: var(--shadow-sm);
        }

        .feature-card:hover {
            transform: translateY(-6px);
            box-shadow: var(--shadow-md);
            border-color: var(--primary-light);
        }

        .feature-card .icon {
            font-size: 40px;
            margin-bottom: 16px;
            display: block;
        }

        .feature-card h3 {
            margin-bottom: 10px;
            font-size: 18px;
            font-weight: 600;
            color: var(--text);
        }

        .feature-card p {
            color: var(--text-soft);
            font-size: 14px;
            line-height: 1.6;
        }

        
        .vendor-cta {
            background: linear-gradient(135deg, var(--primary) 0%, #7B73FF 100%);
            color: var(--white);
            padding: 48px 32px;
            border-radius: 20px;
            text-align: center;
            box-shadow: 0 8px 24px rgba(108, 99, 255, 0.2);
        }

        .vendor-cta h2 {
            font-size: 28px;
            margin-bottom: 12px;
        }

        .vendor-cta p {
            font-size: 16px;
            margin-bottom: 24px;
            color: rgba(255, 255, 255, 0.9);
            max-width: 600px;
            margin-left: auto;
            margin-right: auto;
        }

        .vendor-cta .btn {
            background: var(--white);
            color: var(--primary);
            font-weight: 600;
            padding: 14px 28px;
            border-radius: 10px;
        }

        .vendor-cta .btn:hover {
            background: var(--primary-soft);
            color: #2D2D2D;
            transform: translateY(-2px);
        }

        /* ---------- Footer ---------- */
        .footer {
            text-align: center;
            padding: 30px 20px;
            background: #EDECF5;
            color: #5A5A5A;
            font-size: 14px;
            margin-top: auto;
            border-top: 1px solid #E0DDF5;
        }

        .footer i {
            color: var(--primary);
            margin: 0 4px;
        }
    </style>
</head>
<body>
    <header class="topbar">
        <div class="brand">
            <i class="fa-solid fa-party-horn"></i> PLANORA
        </div>
        <?php if ($isLoggedIn): ?>
            <div class="top-actions">
                <a href="logout.php"><i class="fa-solid fa-arrow-right-from-bracket"></i> Logout</a>
            </div>
        <?php endif; ?>
    </header>

    <main>
        <section class="hero">
            <div class="hero-card">
                <h1>Plan, Manage, and Monitor Events<br>with Ease</h1>
                <p class="subhead">A complete platform for event organizers and vendors to collaborate, coordinate, and deliver successful events - all from one place.</p>
                <div class="actions">
                    <?php if ($isLoggedIn): ?>
                        <a class="btn btn-primary" href="<?php echo htmlspecialchars($dashboardPath); ?>">
                            <i class="fa-solid fa-rocket"></i> Open Dashboard
                        </a>
                        <a class="btn btn-secondary" href="logout.php">
                            <i class="fa-solid fa-right-from-bracket"></i> Logout
                        </a>
                    <?php else: ?>
                        <a class="btn btn-primary" href="register.php">
                            <i class="fa-solid fa-user-plus"></i> Register
                        </a>
                        <a class="btn btn-secondary" href="login.php">
                            <i class="fa-solid fa-sign-in-alt"></i> Login
                        </a>
                    <?php endif; ?>
                </div>
                <p class="status">
                    <i class="fa-solid <?php echo $isLoggedIn ? 'fa-circle-check' : 'fa-circle-user'; ?>"></i>
                    <?php if ($isLoggedIn): ?>
                        Signed in as <?php echo htmlspecialchars($fullName); ?><?php echo $role !== '' ? ' (' . htmlspecialchars($role) . ')' : ''; ?>.
                    <?php else: ?>
                        You are currently browsing as guest.
                    <?php endif; ?>
                </p>
            </div>
        </section>

        <section class="section">
            <h2 class="section-title">Why Choose Planora?</h2>
            <p class="section-text">We provide a comprehensive platform that helps individuals and organizations plan, manage, and monitor events from start to finish. Vendors can showcase their services, connect with clients, and grow their businesses through our marketplace.</p>
        </section>

        <section class="section">
            <h2 class="section-title">Everything You Need for Successful Events</h2>
            <div class="features-grid">
                <div class="feature-card">
                    <span class="icon"><i class="fa-regular fa-calendar"></i></span>
                    <h3>Event Planning</h3>
                    <p>Create and organize events with ease, set budgets, and manage timelines.</p>
                </div>
                <div class="feature-card">
                    <span class="icon"><i class="fa-solid fa-chart-column"></i></span>
                    <h3>Event Monitoring</h3>
                    <p>Track progress and stay updated in real time with live budget overviews.</p>
                </div>
                <div class="feature-card">
                    <span class="icon"><i class="fa-solid fa-handshake"></i></span>
                    <h3>Vendor Marketplace</h3>
                    <p>Discover trusted vendors for every occasion - DJs, caterers, photographers, and more.</p>
                </div>
                <div class="feature-card">
                    <span class="icon"><i class="fa-regular fa-comments"></i></span>
                    <h3>Collaboration Tools</h3>
                    <p>Communicate effectively with your team and vendors, all within one platform.</p>
                </div>
                <div class="feature-card">
                    <span class="icon"><i class="fa-solid fa-chart-line"></i></span>
                    <h3>Performance Insights</h3>
                    <p>Monitor event success through reports, payment history, and analytics.</p>
                </div>
            </div>
        </section>

        <section class="section">
            <div class="vendor-cta">
                <h2>Grow Your Business as a Vendor</h2>
                <p>Join our platform to showcase your services, receive event opportunities, and connect with potential clients looking for trusted event professionals.</p>
                <?php if (!$isLoggedIn || $role !== 'vendor'): ?>
                    <a href="register.php" class="btn"><i class="fa-solid fa-store"></i> Register as a Vendor</a>
                <?php else: ?>
                    <a href="vendor/dashboard.php" class="btn"><i class="fa-solid fa-arrow-right"></i> Go to Vendor Dashboard</a>
                <?php endif; ?>
            </div>
        </section>
    </main>

    <footer class="footer">
        <p><i class="fa-regular fa-heart"></i> Making Event Planning Simple. Where Great Events Begin. <i class="fa-regular fa-heart"></i></p>
    </footer>
</body>
</html>
