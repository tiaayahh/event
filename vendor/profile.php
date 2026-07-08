<?php
require_once '../includes/auth.php';
checkAuth();
requireRole('vendor');

$fullName = $_SESSION['full_name'] ?? 'Vendor';
$email = '';
$phoneNumber = '';
$businessName = '';
$serviceType = '';
$vendorType = 'service_provider';
$vendorDescription = '';
$serviceCount = 0;
$averageRating = 0.0;
$ratingCount = 0;
$recentRatings = [];
$newPendingCount = 0;
$flashSuccess = $_SESSION['flash_success'] ?? '';
$flashError = $_SESSION['flash_error'] ?? '';
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

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

function ensureVendorTypeSchema(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }

    $stmt = $pdo->query("SHOW COLUMNS FROM vendors LIKE 'vendor_type'");
    if (!$stmt->fetch()) {
        $pdo->exec("ALTER TABLE vendors ADD COLUMN vendor_type ENUM('service_provider','market_operator') NOT NULL DEFAULT 'service_provider' AFTER service_type");
    }

    $ready = true;
}

function ensureUsersPhoneSchema(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }

    $stmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'phone_number'");
    if (!$stmt->fetch()) {
        $pdo->exec("ALTER TABLE users ADD COLUMN phone_number VARCHAR(20) DEFAULT NULL AFTER email");
    }

    $ready = true;
}

function normalizeKenyanPhone(string $raw): string
{
    $digits = preg_replace('/\D+/', '', $raw);
    if ($digits === null) {
        return '';
    }

    if (strpos($digits, '254') === 0 && strlen($digits) === 12) {
        return $digits;
    }

    if (strpos($digits, '0') === 0 && strlen($digits) === 10) {
        return '254' . substr($digits, 1);
    }

    return '';
}

function vendorServiceTypeOptions(): array
{
    return [
        'DJ',
        'Entertainment',
        'Catering',
        'Photography',
        'Audio & Visual',
        'Decor',
        'MC / Host',
        'Security',
        'Makeup & Styling',
    ];
}

try {
    ensureServiceRatingsTable($pdo);
    ensureVendorTypeSchema($pdo);
    ensureUsersPhoneSchema($pdo);

    $stmt = $pdo->prepare("SELECT vendor_id FROM vendors WHERE user_id = ? LIMIT 1");
    $stmt->execute([$_SESSION['user_id']]);
    $vendor = $stmt->fetch();

    if ($vendor) {
        $vendorId = (int)$vendor['vendor_id'];

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS vendor_notification_state (
                vendor_id INT NOT NULL PRIMARY KEY,
                last_seen_pending_bookings_at DATETIME NULL,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                CONSTRAINT fk_vendor_notification_state_vendor FOREIGN KEY (vendor_id) REFERENCES vendors(vendor_id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        $stmt = $pdo->prepare('SELECT last_seen_pending_bookings_at FROM vendor_notification_state WHERE vendor_id = ? LIMIT 1');
        $stmt->execute([$vendorId]);
        $lastSeenPendingAt = $stmt->fetchColumn();

        if ($lastSeenPendingAt) {
            $stmt = $pdo->prepare(
                "SELECT COUNT(*)
                 FROM bookings b
                 JOIN services s ON b.service_id = s.service_id
                 WHERE s.vendor_id = ? AND b.status = 'pending' AND b.created_at > ?"
            );
            $stmt->execute([$vendorId, $lastSeenPendingAt]);
        } else {
            $stmt = $pdo->prepare(
                "SELECT COUNT(*)
                 FROM bookings b
                 JOIN services s ON b.service_id = s.service_id
                 WHERE s.vendor_id = ? AND b.status = 'pending'"
            );
            $stmt->execute([$vendorId]);
        }
        $newPendingCount = (int)$stmt->fetchColumn();

        // Handle Account Update
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_account_profile'])) {
            $newFullName = trim($_POST['full_name'] ?? '');
            $newEmail = trim($_POST['email'] ?? '');
            $newPhoneRaw = trim((string)($_POST['phone_number'] ?? ''));
            $newPhoneNumber = normalizeKenyanPhone($newPhoneRaw);

            if ($newFullName === '' || $newEmail === '') {
                $_SESSION['flash_error'] = 'Full name and email are required.';
            } elseif (!filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
                $_SESSION['flash_error'] = 'Please provide a valid email address.';
            } elseif ($newPhoneRaw !== '' && $newPhoneNumber === '') {
                $_SESSION['flash_error'] = 'Phone number format is invalid. Use 07XXXXXXXX or 2547XXXXXXXX.';
            } else {
                $stmt = $pdo->prepare('SELECT user_id FROM users WHERE email = ? AND user_id <> ? LIMIT 1');
                $stmt->execute([$newEmail, $_SESSION['user_id']]);

                if ($stmt->fetch()) {
                    $_SESSION['flash_error'] = 'That email is already in use by another account.';
                } else {
                    $stmt = $pdo->prepare('UPDATE users SET full_name = ?, email = ?, phone_number = ? WHERE user_id = ?');
                    $stmt->execute([$newFullName, $newEmail, $newPhoneNumber !== '' ? $newPhoneNumber : null, $_SESSION['user_id']]);

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
                    $_SESSION['flash_success'] = 'Account profile updated successfully.';
                }
            }

            header('Location: profile.php');
            exit;
        }

        // Handle Vendor Details Update
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_vendor_profile'])) {
            $newBusinessName = trim($_POST['business_name'] ?? '');
            $newServiceType = trim($_POST['service_type'] ?? '');
            $newVendorType = strtolower(trim((string)($_POST['vendor_type'] ?? 'service_provider')));
            $newDescription = trim($_POST['description'] ?? '');

            if (!in_array($newServiceType, vendorServiceTypeOptions(), true)) {
                $newServiceType = '';
            }
            if (!in_array($newVendorType, ['service_provider', 'market_operator'], true)) {
                $newVendorType = 'service_provider';
            }

            if ($newBusinessName === '' || $newServiceType === '') {
                $_SESSION['flash_error'] = 'Business name and service type are required.';
            } else {
                $stmt = $pdo->prepare('UPDATE vendors SET business_name = ?, service_type = ?, vendor_type = ?, description = ? WHERE vendor_id = ? AND user_id = ?');
                $stmt->execute([$newBusinessName, $newServiceType, $newVendorType, $newDescription, $vendorId, $_SESSION['user_id']]);

                audit_log(
                    $pdo,
                    (int)$_SESSION['user_id'],
                    (string)$_SESSION['role'],
                    'vendor_profile.update',
                    'vendor',
                    (string)$vendorId,
                    [
                        'business_name' => $newBusinessName,
                        'service_type' => $newServiceType,
                        'vendor_type' => $newVendorType,
                    ]
                );

                $_SESSION['flash_success'] = 'Vendor profile updated successfully.';
            }

            header('Location: profile.php');
            exit;
        }

        // Fetch current data
        $stmt = $pdo->prepare("SELECT v.business_name, v.service_type, COALESCE(v.vendor_type, 'service_provider') AS vendor_type, v.description, u.email, u.full_name, COALESCE(u.phone_number, '') AS phone_number FROM vendors v JOIN users u ON v.user_id = u.user_id WHERE v.user_id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $data = $stmt->fetch();
        if ($data) {
            $email = $data['email'];
            $fullName = $data['full_name'] ?? $fullName;
            $phoneNumber = $data['phone_number'] ?? '';
            $businessName = $data['business_name'];
            $serviceType = $data['service_type'] ?? '';
            $vendorType = $data['vendor_type'] ?? 'service_provider';
            $vendorDescription = $data['description'] ?? '';
        }

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM services WHERE vendor_id = ?");
        $stmt->execute([$vendorId]);
        $serviceCount = (int)$stmt->fetchColumn();

        $stmt = $pdo->prepare(
            "SELECT COALESCE(AVG(rating), 0) AS avg_rating, COUNT(*) AS rating_count
             FROM service_ratings
             WHERE vendor_id = ?"
        );
        $stmt->execute([$vendorId]);
        $ratingStats = $stmt->fetch();
        if ($ratingStats) {
            $averageRating = (float)$ratingStats['avg_rating'];
            $ratingCount = (int)$ratingStats['rating_count'];
        }

        $stmt = $pdo->prepare(
            "SELECT sr.rating, sr.feedback, sr.updated_at, u.full_name
             FROM service_ratings sr
             JOIN attendees a ON sr.attendee_id = a.attendee_id
             JOIN users u ON a.user_id = u.user_id
             WHERE sr.vendor_id = ?
             ORDER BY sr.updated_at DESC
             LIMIT 5"
        );
        $stmt->execute([$vendorId]);
        $recentRatings = $stmt->fetchAll();
    } else {
        if ($flashError === '') {
            $flashError = 'Vendor profile not found.';
        }
    }
} catch (Throwable $e) {
    error_log('vendor/profile.php error: ' . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="../assets/style.css">
    <title>Planora - My Profile</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
        }

        body {
            background-color: #F5F5F5;
            color: #2D2D2D;
            padding-bottom: 80px;
        }

        .header {
            background-color: #6C63FF;
            color: white;
            padding: 14px 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .brand-logo {
            font-size: 22px;
            font-weight: 700;
            letter-spacing: 0.5px;
        }

        .logout-btn {
            background-color: rgba(255, 255, 255, 0.25);
            color: white;
            border: 1px solid rgba(255, 255, 255, 0.3);
            padding: 8px 16px;
            border-radius: 6px;
            text-decoration: none;
            font-size: 14px;
            font-weight: 500;
            transition: background 0.2s;
        }

        .logout-btn:hover {
            background-color: rgba(255, 255, 255, 0.35);
        }

        .container {
            max-width: 600px;
            margin: 30px auto;
            padding: 0 20px;
        }

        .profile-card {
            background: #FFFFFF;
            border-radius: 16px;
            padding: 30px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
            text-align: center;
        }

        .avatar {
            width: 100px;
            height: 100px;
            border-radius: 50%;
            background-color: #B8A8FF;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px;
            font-size: 48px;
            color: #FFFFFF;
            font-weight: 600;
        }

        .profile-name {
            font-size: 24px;
            font-weight: 700;
            color: #1a1a1a;
            margin-bottom: 6px;
        }

        .profile-role {
            font-size: 14px;
            color: #6C63FF;
            font-weight: 600;
            background: #F0EEFF;
            display: inline-block;
            padding: 4px 16px;
            border-radius: 20px;
            margin-bottom: 20px;
        }

        .profile-details {
            background: #F9F9FF;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 25px;
            text-align: left;
        }

        .detail-row {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 0;
            border-bottom: 1px solid #EAEAEA;
            font-size: 14px;
        }

        .detail-row:last-child {
            border-bottom: none;
        }

        .detail-icon {
            width: 36px;
            height: 36px;
            background: #FFFFFF;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #6C63FF;
            font-size: 16px;
            box-shadow: 0 2px 6px rgba(0,0,0,0.03);
        }

        .detail-text {
            flex: 1;
        }

        .detail-label {
            font-size: 12px;
            color: #888;
            margin-bottom: 2px;
        }

        .detail-value {
            font-weight: 600;
            color: #2D2D2D;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 12px;
            margin-bottom: 25px;
        }

        .stat-card {
            background: #FFFFFF;
            border: 1px solid #F0F0F0;
            border-radius: 12px;
            padding: 16px;
            text-align: center;
        }

        .stat-number {
            font-size: 28px;
            font-weight: 700;
            color: #6C63FF;
            margin-bottom: 4px;
        }

        .stat-label {
            font-size: 12px;
            color: #888;
            font-weight: 500;
        }

        .btn-primary {
            display: inline-block;
            background: #6C63FF;
            color: #FFFFFF;
            border: none;
            padding: 12px 24px;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            text-decoration: none;
            cursor: pointer;
            transition: background 0.2s;
            margin-top: 12px;
        }

        .btn-primary:hover {
            background: #5A52E0;
        }

        .btn-outline {
            background: #fff;
            color: #6C63FF;
            border: 2px solid #6C63FF;
            padding: 8px 16px;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
        }

        .btn-outline:hover {
            background: #6C63FF;
            color: #fff;
        }

        .message {
            border-radius: 6px;
            padding: 10px 12px;
            margin-bottom: 14px;
            font-size: 13px;
        }

        .message-success {
            background: #ecfff0;
            color: #1c7a36;
            border: 1px solid #c9f0d4;
        }

        .message-error {
            background: #ffecec;
            color: #9d2020;
            border: 1px solid #f6caca;
        }

        .edit-form {
            margin-top: 20px;
            text-align: left;
            border-top: 1px solid #EAEAEA;
            padding-top: 16px;
        }

        .form-title {
            font-size: 16px;
            font-weight: 700;
            color: #2D2D2D;
            margin-bottom: 12px;
        }

        .field {
            margin-bottom: 10px;
        }

        .field label {
            display: block;
            font-size: 12px;
            color: #666;
            margin-bottom: 5px;
            font-weight: 600;
        }

        .input,
        .textarea {
            width: 100%;
            border: 1px solid #d6d6d6;
            border-radius: 6px;
            padding: 10px;
            font-size: 14px;
        }

        .textarea {
            resize: vertical;
            min-height: 70px;
        }

        .actions {
            display: flex;
            gap: 10px;
            margin-top: 4px;
            flex-wrap: wrap;
        }

        .ratings-panel {
            margin-top: 18px;
            text-align: left;
            border: 1px solid #EFEFEF;
            border-radius: 12px;
            padding: 14px;
            background: #FAFAFA;
        }

        .ratings-title {
            font-size: 14px;
            font-weight: 700;
            margin-bottom: 10px;
            color: #2D2D2D;
        }

        .rating-item {
            border-bottom: 1px solid #ECECEC;
            padding: 10px 0;
        }

        .rating-item:last-child {
            border-bottom: none;
            padding-bottom: 0;
        }

        .rating-top {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 8px;
            font-size: 13px;
        }

        .rating-author {
            font-weight: 600;
            color: #2D2D2D;
        }

        .rating-score {
            color: #F39C12;
            font-weight: 700;
        }

        .rating-note {
            font-size: 12px;
            color: #666;
            margin-top: 6px;
            line-height: 1.5;
        }

        .rating-time {
            font-size: 11px;
            color: #999;
            margin-top: 4px;
        }

        .rating-empty {
            font-size: 13px;
            color: #777;
        }

        .bottom-nav {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            background-color: #2D2D2D;
            display: flex;
            justify-content: space-around;
            align-items: center;
            height: 65px;
            box-shadow: 0 -2px 10px rgba(0,0,0,0.1);
            z-index: 999;
        }

        .nav-link {
            color: #ffffff;
            text-decoration: none;
            font-size: 12px;
            font-weight: 500;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 4px;
            opacity: 0.85;
            flex: 1;
            position: relative;
        }

        .nav-link i {
            font-size: 18px;
        }

        .nav-link.active {
            opacity: 1;
            background-color: rgba(255, 255, 255, 0.08);
        }

        .badge-unread {
            display: inline-block;
            min-width: 18px;
            height: 18px;
            border-radius: 999px;
            background: #e74c3c;
            color: #fff;
            font-size: 11px;
            font-weight: 700;
            line-height: 18px;
            text-align: center;
            padding: 0 5px;
            position: absolute;
            top: 8px;
            right: 12px;
        }

        @media (max-width: 500px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <header class="header">
        <div class="brand-logo">PLANORA</div>
        <a href="../logout.php" class="logout-btn">Logout</a>
    </header>

    <div class="container">
        <?php if ($flashSuccess !== ''): ?>
            <div class="message message-success"><?php echo htmlspecialchars($flashSuccess); ?></div>
        <?php endif; ?>

        <?php if ($flashError !== ''): ?>
            <div class="message message-error"><?php echo htmlspecialchars($flashError); ?></div>
        <?php endif; ?>

        <div class="profile-card">
            <div class="avatar">
                <?php echo strtoupper(substr($fullName, 0, 1)); ?>
            </div>

            <h1 class="profile-name"><?php echo htmlspecialchars($fullName); ?></h1>
            <span class="profile-role">Vendor</span>

            <div class="profile-details">
                <div class="detail-row">
                    <div class="detail-icon"><i class="fa-solid fa-envelope"></i></div>
                    <div class="detail-text">
                        <div class="detail-label">Email</div>
                        <div class="detail-value"><?php echo htmlspecialchars($email); ?></div>
                    </div>
                </div>

                <div class="detail-row">
                    <div class="detail-icon"><i class="fa-solid fa-phone"></i></div>
                    <div class="detail-text">
                        <div class="detail-label">Phone Number</div>
                        <div class="detail-value"><?php echo htmlspecialchars($phoneNumber !== '' ? $phoneNumber : 'Not set'); ?></div>
                    </div>
                </div>

                <?php if ($businessName !== ''): ?>
                <div class="detail-row">
                    <div class="detail-icon"><i class="fa-solid fa-shop"></i></div>
                    <div class="detail-text">
                        <div class="detail-label">Business Name</div>
                        <div class="detail-value"><?php echo htmlspecialchars($businessName); ?></div>
                    </div>
                </div>
                <?php endif; ?>

                <?php if ($serviceType !== ''): ?>
                <div class="detail-row">
                    <div class="detail-icon"><i class="fa-solid fa-tags"></i></div>
                    <div class="detail-text">
                        <div class="detail-label">Service Type</div>
                        <div class="detail-value"><?php echo htmlspecialchars($serviceType); ?></div>
                    </div>
                </div>
                <?php endif; ?>

                <div class="detail-row">
                    <div class="detail-icon"><i class="fa-solid fa-bell-concierge"></i></div>
                    <div class="detail-text">
                        <div class="detail-label">Active Services</div>
                        <div class="detail-value"><?php echo $serviceCount; ?></div>
                    </div>
                </div>
            </div>

            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-number"><?php echo $serviceCount; ?></div>
                    <div class="stat-label">Services</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><i class="fa-regular fa-star" style="color:#F39C12;"></i> <?php echo number_format($averageRating, 1); ?></div>
                    <div class="stat-label">Rating (<?php echo $ratingCount; ?> review<?php echo $ratingCount === 1 ? '' : 's'; ?>)</div>
                </div>
            </div>

            <div class="ratings-panel">
                <div class="ratings-title">Recent Attendee Ratings</div>
                <?php if (empty($recentRatings)): ?>
                    <div class="rating-empty">No attendee ratings yet.</div>
                <?php else: ?>
                    <?php foreach ($recentRatings as $rating): ?>
                        <div class="rating-item">
                            <div class="rating-top">
                                <span class="rating-author"><?php echo htmlspecialchars((string)$rating['full_name']); ?></span>
                                <span class="rating-score"><?php echo (int)$rating['rating']; ?>/5</span>
                            </div>
                            <?php if (!empty($rating['feedback'])): ?>
                                <div class="rating-note"><?php echo nl2br(htmlspecialchars((string)$rating['feedback'])); ?></div>
                            <?php endif; ?>
                            <div class="rating-time"><?php echo htmlspecialchars((string)$rating['updated_at']); ?></div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <!-- Toggle button for edit forms -->
            <button type="button" id="toggleEditBtn" class="btn-outline" onclick="toggleEditForms()">
                <i class="fa-solid fa-pen-to-square"></i> Edit Profile
            </button>

            <!-- Container for both edit forms (hidden by default) -->
            <div id="editFormsContainer" style="display: none;">
                <form method="POST" class="edit-form">
                    <?php echo csrf_input(); ?>
                    <h2 class="form-title">Update Account Details</h2>

                    <div class="field">
                        <label for="full_name">Full Name</label>
                        <input class="input" type="text" id="full_name" name="full_name" value="<?php echo htmlspecialchars($fullName); ?>" required>
                    </div>

                    <div class="field">
                        <label for="email">Email</label>
                        <input class="input" type="email" id="email" name="email" value="<?php echo htmlspecialchars($email); ?>" required>
                    </div>

                    <div class="field">
                        <label for="phone_number">Phone Number</label>
                        <input class="input" type="text" id="phone_number" name="phone_number" value="<?php echo htmlspecialchars($phoneNumber); ?>" placeholder="e.g. 0712345678">
                    </div>

                    <div class="actions">
                        <button type="submit" name="update_account_profile" value="1" class="btn-primary">Save Account</button>
                        <button type="button" class="btn-outline" onclick="toggleEditForms()">Cancel</button>
                    </div>
                </form>

                <form method="POST" class="edit-form">
                    <?php echo csrf_input(); ?>
                    <h2 class="form-title">Update Vendor Details</h2>

                    <div class="field">
                        <label for="business_name">Business Name</label>
                        <input class="input" type="text" id="business_name" name="business_name" value="<?php echo htmlspecialchars($businessName); ?>" required>
                    </div>

                    <div class="field">
                        <label for="service_type">Service Type</label>
                        <select class="input" id="service_type" name="service_type" required>
                            <option value="">-- Select service type --</option>
                            <?php foreach (vendorServiceTypeOptions() as $option): ?>
                                <option value="<?php echo htmlspecialchars($option); ?>" <?php echo $serviceType === $option ? 'selected' : ''; ?>><?php echo htmlspecialchars($option); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="field">
                        <label for="vendor_type">Vendor Type</label>
                        <select class="input" id="vendor_type" name="vendor_type" required>
                            <option value="service_provider" <?php echo $vendorType === 'service_provider' ? 'selected' : ''; ?>>Service Provider</option>
                            <option value="market_operator" <?php echo $vendorType === 'market_operator' ? 'selected' : ''; ?>>Market Operator</option>
                        </select>
                    </div>

                    <div class="field">
                        <label for="description">Description</label>
                        <textarea class="textarea" id="description" name="description" placeholder="Describe what you sell and your style."><?php echo htmlspecialchars($vendorDescription); ?></textarea>
                    </div>

                    <div class="actions">
                        <button type="submit" name="update_vendor_profile" value="1" class="btn-primary">Save Changes</button>
                    </div>
                </form>
            </div>

            <a href="dashboard.php" class="btn-primary" style="margin-top:16px;">Back to Dashboard</a>
        </div>
    </div>

    <nav class="bottom-nav">
        <a href="dashboard.php" class="nav-link">
            <i class="fa-solid fa-house"></i><span>Home</span>
        </a>
        <a href="services.php" class="nav-link">
            <i class="fa-solid fa-bell-concierge"></i><span>Services</span>
        </a>
        <a href="bookings.php" class="nav-link">
            <i class="fa-solid fa-book-open"></i><span>Bookings</span>
            <?php if ($newPendingCount > 0): ?><span class="badge-unread"><?php echo $newPendingCount; ?></span><?php endif; ?>
        </a>
        <?php if ($vendorType === 'market_operator'): ?>
            <a href="pay_fee.php" class="nav-link">
                <i class="fa-solid fa-wallet"></i><span>Fees</span>
            </a>
            <a href="schedule.php" class="nav-link">
                <i class="fa-solid fa-calendar-days"></i><span>Schedule</span>
            </a>
        <?php else: ?>
            <a href="booking_history.php" class="nav-link">
                <i class="fa-solid fa-clock-rotate-left"></i><span>History</span>
            </a>
            <a href="payment_history.php" class="nav-link">
                <i class="fa-solid fa-money-bill-wave"></i><span>Payments</span>
            </a>
        <?php endif; ?>
        <a href="profile.php" class="nav-link active">
            <i class="fa-solid fa-user"></i><span>Profile</span>
        </a>
    </nav>

    <script>
        function toggleEditForms() {
            const container = document.getElementById('editFormsContainer');
            const toggleBtn = document.getElementById('toggleEditBtn');

            if (container.style.display === 'none') {
                container.style.display = 'block';
                toggleBtn.style.display = 'none';   // hide the Edit button while editing
            } else {
                container.style.display = 'none';
                toggleBtn.style.display = 'inline-block'; // show Edit button again
            }
        }
    </script>
</body>
</html>

