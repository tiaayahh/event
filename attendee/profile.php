<?php
require_once '../includes/auth.php';
require_once '../includes/two_step.php';
checkAuth();
requireRole('attendee');

$fullName = $_SESSION['full_name'] ?? 'Attendee';
$email = '';
$registrationCount = 0;
$rateableServices = [];
$myServiceRatings = [];
$flashSuccess = $_SESSION['flash_success'] ?? '';
$flashError = $_SESSION['flash_error'] ?? '';
$emailOtpEnabled = false;
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

try {
    $emailOtpEnabled = two_step_email_otp_is_enabled($pdo, (int)$_SESSION['user_id']);
} catch (Throwable $e) {
    $emailOtpEnabled = false;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_email_2fa'])) {
    $toggleAction = strtolower(trim((string)($_POST['toggle_email_2fa'] ?? '')));
    $enable2fa = $toggleAction === 'enable';

    try {
        two_step_set_email_otp_enabled($pdo, (int)$_SESSION['user_id'], $enable2fa);

        audit_log(
            $pdo,
            (int)$_SESSION['user_id'],
            (string)$_SESSION['role'],
            $enable2fa ? 'auth.email_2fa_enabled' : 'auth.email_2fa_disabled',
            'user',
            (string)$_SESSION['user_id']
        );

        $_SESSION['flash_success'] = $enable2fa
            ? '2-step verification has been enabled.'
            : '2-step verification has been disabled.';
    } catch (Throwable $e) {
        $_SESSION['flash_error'] = 'Unable to update 2-step verification right now.';
    }

    header('Location: profile.php');
    exit;
}

function ensureServiceRatingsTable(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS service_ratings (
            rating_id INT AUTO_INCREMENT PRIMARY KEY,
            attendee_id INT NOT NULL,
            service_id INT NOT NULL,
            vendor_id INT NOT NULL,
            rating TINYINT NOT NULL,
            feedback VARCHAR(500) DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_attendee_service_rating (attendee_id, service_id),
            INDEX idx_service_ratings_service (service_id),
            INDEX idx_service_ratings_vendor (vendor_id),
            INDEX idx_service_ratings_attendee (attendee_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    $newFullName = trim($_POST['full_name'] ?? '');
    $newEmail = trim($_POST['email'] ?? '');

    if ($newFullName === '' || $newEmail === '') {
        $flashError = 'Full name and email are required.';
    } elseif (!filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
        $flashError = 'Please provide a valid email address.';
    } else {
        try {
            $stmt = $pdo->prepare('SELECT user_id FROM users WHERE email = ? AND user_id <> ? LIMIT 1');
            $stmt->execute([$newEmail, $_SESSION['user_id']]);
            if ($stmt->fetch()) {
                $flashError = 'That email is already in use by another account.';
            } else {
                $stmt = $pdo->prepare('UPDATE users SET full_name = ?, email = ? WHERE user_id = ?');
                $stmt->execute([$newFullName, $newEmail, $_SESSION['user_id']]);

                audit_log(
                    $pdo,
                    (int)$_SESSION['user_id'],
                    (string)$_SESSION['role'],
                    'profile.update',
                    'user',
                    (string)$_SESSION['user_id'],
                    ['email' => $newEmail]
                );

                $_SESSION['full_name'] = $newFullName;
                $_SESSION['flash_success'] = 'Profile updated successfully.';
                header('Location: profile.php');
                exit;
            }
        } catch (Throwable $e) {
            audit_log(
                $pdo,
                (int)$_SESSION['user_id'],
                (string)$_SESSION['role'],
                'profile.update_failed',
                'user',
                (string)$_SESSION['user_id'],
                ['reason' => 'exception']
            );
            $flashError = 'Unable to update profile right now.';
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['rate_service'])) {
    $serviceId = (int)($_POST['service_id'] ?? 0);
    $ratingValue = (int)($_POST['rating'] ?? 0);
    $feedback = trim($_POST['feedback'] ?? '');

    if ($serviceId <= 0) {
        $flashError = 'Please select a service to rate.';
    } elseif ($ratingValue < 1 || $ratingValue > 5) {
        $flashError = 'Please select a rating from 1 to 5.';
    } elseif (mb_strlen($feedback) > 500) {
        $flashError = 'Feedback must be 500 characters or fewer.';
    } else {
        try {
            ensureServiceRatingsTable($pdo);

            $stmt = $pdo->prepare('SELECT attendee_id FROM attendees WHERE user_id = ? LIMIT 1');
            $stmt->execute([$_SESSION['user_id']]);
            $attendee = $stmt->fetch();

            if (!$attendee) {
                $flashError = 'Attendee profile not found.';
            } else {
                $attendeeId = (int)$attendee['attendee_id'];

                $stmt = $pdo->prepare(
                    "SELECT s.service_id, v.vendor_id
                     FROM attendances a
                     JOIN bookings b ON b.event_id = a.event_id
                     JOIN services s ON s.service_id = b.service_id
                     JOIN vendors v ON v.vendor_id = s.vendor_id
                     WHERE a.attendee_id = ? AND s.service_id = ?
                     LIMIT 1"
                );
                $stmt->execute([$attendeeId, $serviceId]);
                $service = $stmt->fetch();

                if (!$service) {
                    $flashError = 'You can only rate services from events you attended.';
                } else {
                    $stmt = $pdo->prepare(
                        'INSERT INTO service_ratings (attendee_id, service_id, vendor_id, rating, feedback)
                         VALUES (?, ?, ?, ?, ?)
                         ON DUPLICATE KEY UPDATE rating = VALUES(rating), feedback = VALUES(feedback)'
                    );
                    $stmt->execute([
                        $attendeeId,
                        (int)$service['service_id'],
                        (int)$service['vendor_id'],
                        $ratingValue,
                        $feedback === '' ? null : $feedback
                    ]);

                    audit_log(
                        $pdo,
                        (int)$_SESSION['user_id'],
                        (string)$_SESSION['role'],
                        'rating.upsert',
                        'service',
                        (string)$serviceId,
                        [
                            'rating' => $ratingValue,
                            'vendor_id' => (int)$service['vendor_id'],
                        ]
                    );

                    $_SESSION['flash_success'] = 'Your service rating has been saved.';
                    header('Location: profile.php');
                    exit;
                }
            }
        } catch (Throwable $e) {
            audit_log(
                $pdo,
                (int)$_SESSION['user_id'],
                (string)$_SESSION['role'],
                'rating.upsert_failed',
                'service',
                $serviceId > 0 ? (string)$serviceId : null,
                ['reason' => 'exception']
            );
            $flashError = 'Unable to save your rating right now.';
        }
    }
}

try {
    ensureServiceRatingsTable($pdo);

    $stmt = $pdo->prepare("SELECT email FROM users WHERE user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch();
    if ($user) {
        $email = $user['email'];
    }

    $stmt = $pdo->prepare("SELECT attendee_id FROM attendees WHERE user_id = ? LIMIT 1");
    $stmt->execute([$_SESSION['user_id']]);
    $attendee = $stmt->fetch();

    if ($attendee) {
        $attendeeId = (int)$attendee['attendee_id'];

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM attendances WHERE attendee_id = ?");
        $stmt->execute([$attendeeId]);
        $registrationCount = (int)$stmt->fetchColumn();

        $stmt = $pdo->prepare(
            "SELECT DISTINCT s.service_id, s.name AS service_name, s.price, v.business_name,
                    COALESCE(AVG(sr.rating), 0) AS avg_rating,
                    COUNT(sr.rating_id) AS ratings_count
             FROM attendances a
             JOIN bookings b ON b.event_id = a.event_id
             JOIN services s ON s.service_id = b.service_id
             JOIN vendors v ON v.vendor_id = s.vendor_id
             LEFT JOIN service_ratings sr ON sr.service_id = s.service_id
             WHERE a.attendee_id = ?
             GROUP BY s.service_id, s.name, s.price, v.business_name
             ORDER BY v.business_name ASC, s.name ASC"
        );
        $stmt->execute([$attendeeId]);
        $rateableServices = $stmt->fetchAll();

        $stmt = $pdo->prepare('SELECT service_id, rating, feedback FROM service_ratings WHERE attendee_id = ?');
        $stmt->execute([$attendeeId]);
        foreach ($stmt->fetchAll() as $ratingRow) {
            $myServiceRatings[(int)$ratingRow['service_id']] = [
                'rating' => (int)$ratingRow['rating'],
                'feedback' => (string)($ratingRow['feedback'] ?? '')
            ];
        }
    }
} catch (Throwable $e) {}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
        }
        body {
            background: #F5F5F5;
            color: #2D2D2D;
            padding-bottom: 70px;
        }
        .header {
            background: #6C63FF;
            color: white;
            padding: 16px 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .header .logo { font-size: 22px; font-weight: 700; }
        .logout-btn {
            background: rgba(255,255,255,0.22);
            color: white;
            padding: 8px 16px;
            border-radius: 8px;
            text-decoration: none;
            font-size: 14px;
        }
        .container {
            max-width: 520px;
            margin: 30px auto;
            padding: 0 20px;
        }
        .profile-card {
            background: white;
            border-radius: 20px;
            padding: 32px 28px;
            text-align: center;
            box-shadow: 0 6px 18px rgba(108, 99, 255, 0.06);
            border: 1px solid #F0EEFF;
        }
        .avatar {
            width: 100px;
            height: 100px;
            border-radius: 50%;
            background: #B8A8FF;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 18px;
            font-size: 42px;
            color: white;
            font-weight: 700;
        }
        .name {
            font-size: 26px;
            font-weight: 700;
            margin-bottom: 4px;
            color: #1E1E2F;
        }
        .role-badge {
            display: inline-block;
            background: #F0EEFF;
            color: #6C63FF;
            padding: 6px 16px;
            border-radius: 30px;
            font-size: 13px;
            font-weight: 600;
            margin-bottom: 22px;
        }
        .info-row {
            display: flex;
            align-items: center;
            gap: 14px;
            padding: 14px 0;
            border-bottom: 1px solid #F0F0F0;
            text-align: left;
        }
        .info-row:last-child { border-bottom: none; }
        .info-icon {
            width: 42px;
            height: 42px;
            background: #F7F5FF;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #6C63FF;
            font-size: 18px;
        }
        .info-label { font-size: 12px; color: #888; margin-bottom: 3px; }
        .info-value { font-weight: 600; font-size: 15px; }
        .stats {
            display: flex;
            justify-content: center;
            gap: 24px;
            margin: 24px 0;
        }
        .stat-number {
            font-size: 32px;
            font-weight: 700;
            color: #6C63FF;
            line-height: 1;
        }
        .stat-label { font-size: 13px; color: #888; margin-top: 6px; }
        .btn {
            display: inline-block;
            background: #6C63FF;
            color: white;
            padding: 12px 22px;
            border-radius: 10px;
            text-decoration: none;
            font-weight: 600;
            font-size: 14px;
            border: none;
            cursor: pointer;
            transition: background 0.2s;
            margin-top: 12px;
        }
        .btn:hover { background: #5A52E0; }
        .btn-outline {
            background: transparent;
            color: #6C63FF;
            border: 2px solid #6C63FF;
            padding: 10px 20px;
            border-radius: 10px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
        }
        .btn-outline:hover {
            background: #6C63FF;
            color: white;
        }
        .message {
            border-radius: 10px;
            padding: 10px 14px;
            margin-bottom: 16px;
            font-size: 13px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .message-success {
            background: #ECFFF4;
            color: #1D7A34;
            border: 1px solid #B7E1C1;
        }
        .message-error {
            background: #FFECEC;
            color: #9D2020;
            border: 1px solid #F6BDBD;
        }
        .edit-form {
            margin-top: 20px;
            border-top: 1px solid #EAEAEA;
            padding-top: 18px;
            text-align: left;
        }
        .form-title {
            font-size: 16px;
            font-weight: 700;
            margin-bottom: 12px;
            color: #2D2D2D;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .field {
            margin-bottom: 12px;
        }
        .field label {
            display: block;
            font-size: 13px;
            color: #555;
            margin-bottom: 5px;
            font-weight: 600;
        }
        .input, select, .textarea {
            width: 100%;
            border: 1px solid #DDD;
            border-radius: 8px;
            padding: 11px 14px;
            font-size: 14px;
            background: #FAFAFA;
            transition: border-color 0.2s;
        }
        .input:focus, select:focus, .textarea:focus {
            outline: none;
            border-color: #6C63FF;
            background: white;
        }
        .textarea {
            resize: vertical;
            min-height: 80px;
        }
        .actions {
            display: flex;
            gap: 10px;
            margin-top: 6px;
            flex-wrap: wrap;
        }
        .rating-box {
            margin-top: 20px;
            border-top: 1px solid #EAEAEA;
            padding-top: 18px;
            text-align: left;
        }
        .helper {
            font-size: 12px;
            color: #777;
            margin-bottom: 12px;
        }
        .vendor-rating-list {
            margin-top: 14px;
            border: 1px solid #F0F0F0;
            border-radius: 10px;
            padding: 12px;
            background: #FAFAFA;
        }
        .vendor-rating-item {
            padding: 8px 0;
            border-bottom: 1px solid #ECECEC;
            font-size: 13px;
        }
        .vendor-rating-item:last-child { border-bottom: none; }
        .vendor-rating-name { font-weight: 700; color: #2D2D2D; }
        .vendor-rating-meta { color: #666; margin-top: 2px; }
        .bottom-nav {
            position: fixed;
            bottom: 0; left: 0; right: 0;
            background: #6C63FF;
            display: flex;
            height: 60px;
            z-index: 999;
            box-shadow: 0 -2px 10px rgba(0,0,0,0.05);
        }
        .nav-item {
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            color: rgba(255,255,255,0.8);
            text-decoration: none;
            font-size: 12px;
            gap: 5px;
        }
        .nav-item i { font-size: 18px; }
        .nav-item.active, .nav-item:hover { color: white; background: rgba(255,255,255,0.1); }
    </style>
</head>
<body>
    <header class="header">
        <div class="logo">Planora</div>
        <a href="../logout.php" class="logout-btn">Logout</a>
    </header>

    <div class="container">
        <?php if ($flashSuccess !== ''): ?>
            <div class="message message-success"><i class="fa-solid fa-circle-check"></i> <?php echo htmlspecialchars($flashSuccess); ?></div>
        <?php endif; ?>
        <?php if ($flashError !== ''): ?>
            <div class="message message-error"><i class="fa-solid fa-circle-exclamation"></i> <?php echo htmlspecialchars($flashError); ?></div>
        <?php endif; ?>

        <div class="profile-card">
            <div class="avatar"><?php echo strtoupper(substr($fullName, 0, 1)); ?></div>
            <div class="name"><?php echo htmlspecialchars($fullName); ?></div>
            <span class="role-badge">Attendee</span>

            <div class="info-row">
                <div class="info-icon"><i class="fa-solid fa-envelope"></i></div>
                <div>
                    <div class="info-label">Email</div>
                    <div class="info-value"><?php echo htmlspecialchars($email); ?></div>
                </div>
            </div>

            <div class="stats">
                <div>
                    <div class="stat-number"><?php echo $registrationCount; ?></div>
                    <div class="stat-label">Events Registered</div>
                </div>
            </div>

            <button type="button" class="btn-outline" id="toggleEditBtn" onclick="toggleEditForm()">
                <i class="fa-solid fa-pen-to-square"></i> Edit Profile
            </button>

            <div id="editFormContainer" style="display: none;">
                <form method="POST" class="edit-form">
                    <?php echo csrf_input(); ?>
                    <h2 class="form-title"><i class="fa-solid fa-user-pen"></i> Edit Profile</h2>
                    <div class="field">
                        <label for="full_name">Full Name</label>
                        <input class="input" type="text" id="full_name" name="full_name" value="<?php echo htmlspecialchars($fullName); ?>" required>
                    </div>
                    <div class="field">
                        <label for="email">Email</label>
                        <input class="input" type="email" id="email" name="email" value="<?php echo htmlspecialchars($email); ?>" required>
                    </div>
                    <div class="actions">
                        <button type="submit" name="update_profile" value="1" class="btn">Save Changes</button>
                        <button type="button" class="btn-outline" onclick="toggleEditForm()">Cancel</button>
                    </div>
                </form>
            </div>

            <form method="POST" class="rating-box">
                <?php echo csrf_input(); ?>
                <h2 class="form-title"><i class="fa-solid fa-star"></i> Rate Vendor Service</h2>
                <p class="helper">Share your experience with services from events you attended.</p>

                <?php if (empty($rateableServices)): ?>
                    <p class="helper">No services available to rate yet.</p>
                <?php else: ?>
                    <div class="field">
                        <label for="service_id">Vendor Service</label>
                        <select class="input" id="service_id" name="service_id" required>
                            <option value="">-- Select Service --</option>
                            <?php foreach ($rateableServices as $service): ?>
                                <option value="<?php echo (int)$service['service_id']; ?>">
                                    <?php echo htmlspecialchars($service['business_name']); ?> - <?php echo htmlspecialchars($service['service_name']); ?>
                                    (Avg <?php echo number_format((float)$service['avg_rating'], 1); ?>/5)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="field">
                        <label for="rating">Rating</label>
                        <select class="input" id="rating" name="rating" required>
                            <option value="">-- Select Rating --</option>
                            <option value="5">5 - Excellent</option>
                            <option value="4">4 - Very Good</option>
                            <option value="3">3 - Good</option>
                            <option value="2">2 - Fair</option>
                            <option value="1">1 - Poor</option>
                        </select>
                    </div>

                    <div class="field">
                        <label for="feedback">Optional Feedback</label>
                        <textarea class="textarea" id="feedback" name="feedback" maxlength="500" placeholder="Tell others about this service."></textarea>
                    </div>

                    <div class="actions">
                        <button type="submit" name="rate_service" value="1" class="btn">Submit Rating</button>
                    </div>

                    <?php if (!empty($myServiceRatings)): ?>
                        <div class="vendor-rating-list">
                            <?php foreach ($rateableServices as $service): ?>
                                <?php $serviceId = (int)$service['service_id']; ?>
                                <?php if (!isset($myServiceRatings[$serviceId])) continue; ?>
                                <div class="vendor-rating-item">
                                    <div class="vendor-rating-name"><?php echo htmlspecialchars($service['business_name']); ?> - <?php echo htmlspecialchars($service['service_name']); ?></div>
                                    <div class="vendor-rating-meta">Your rating: <?php echo (int)$myServiceRatings[$serviceId]['rating']; ?>/5</div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </form>

            <form method="POST" class="edit-form" style="margin-top:16px;">
                <?php echo csrf_input(); ?>
                <h2 class="form-title"><i class="fa-solid fa-shield-halved"></i> 2-Step Verification</h2>
                <p class="helper">Status: <strong><?php echo $emailOtpEnabled ? 'Enabled' : 'Disabled'; ?></strong></p>
                <input type="hidden" name="toggle_email_2fa" value="<?php echo $emailOtpEnabled ? 'disable' : 'enable'; ?>">
                <div class="actions">
                    <button type="submit" class="<?php echo $emailOtpEnabled ? 'btn-outline' : 'btn'; ?>">
                        <?php echo $emailOtpEnabled ? 'Turn Off 2FA' : 'Turn On 2FA'; ?>
                    </button>
                </div>
            </form>

            <a href="dashboard.php" class="btn" style="margin-top:18px;">Back to Dashboard</a>
        </div>
    </div>

    <nav class="bottom-nav">
        <a href="dashboard.php" class="nav-item"><i class="fa-solid fa-house"></i><span>Home</span></a>
        <a href="explore.php" class="nav-item"><i class="fa-solid fa-compass"></i><span>Explore</span></a>
        <a href="my_events.php" class="nav-item"><i class="fa-solid fa-ticket"></i><span>My Events</span></a>
        <a href="schedule.php" class="nav-item"><i class="fa-solid fa-calendar-days"></i><span>Schedule</span></a>
        <a href="notifications.php" class="nav-item"><i class="fa-solid fa-bell"></i><span>Alerts</span></a>
        <a href="profile.php" class="nav-item active"><i class="fa-solid fa-user"></i><span>Profile</span></a>
    </nav>

    <script>
        function toggleEditForm() {
            const formContainer = document.getElementById('editFormContainer');
            const toggleBtn = document.getElementById('toggleEditBtn');

            if (formContainer.style.display === 'none') {
                formContainer.style.display = 'block';
                toggleBtn.style.display = 'none';
            } else {
                formContainer.style.display = 'none';
                toggleBtn.style.display = 'inline-block';
            }
        }
    </script>
</body>
</html>