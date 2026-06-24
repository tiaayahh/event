<?php
require_once '../includes/auth.php';
checkAuth();
requireRole('planner');

$fullName = $_SESSION['full_name'] ?? 'Planner';
$email = '';
$eventCount = 0;
$bookingCount = 0;
$flashSuccess = $_SESSION['flash_success'] ?? '';
$flashError = $_SESSION['flash_error'] ?? '';
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

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
                $_SESSION['full_name'] = $newFullName;
                $_SESSION['flash_success'] = 'Profile updated successfully.';
                header('Location: profile.php');
                exit;
            }
        } catch (Throwable $e) {
            $flashError = 'Unable to update profile right now.';
        }
    }
}

try {
    $stmt = $pdo->prepare('SELECT email FROM users WHERE user_id = ? LIMIT 1');
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch();
    if ($user) {
        $email = $user['email'];
    }

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM events WHERE planner_id = ?');
    $stmt->execute([$_SESSION['user_id']]);
    $eventCount = (int)$stmt->fetchColumn();

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM bookings b JOIN events e ON b.event_id = e.event_id WHERE e.planner_id = ?');
    $stmt->execute([$_SESSION['user_id']]);
    $bookingCount = (int)$stmt->fetchColumn();
} catch (Throwable $e) {
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="../assets/style.css">
    <title>Planora - Planner Profile</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
        }

        body {
            background: #F5F5F5;
            color: #2D2D2D;
            min-height: 100vh;
        }

        .header {
            background: #6C63FF;
            color: white;
            padding: 15px 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .brand {
            font-size: 22px;
            font-weight: 700;
        }

        .logout-btn {
            background: rgba(255,255,255,0.22);
            color: white;
            text-decoration: none;
            border-radius: 6px;
            padding: 7px 14px;
            font-size: 13px;
            font-weight: 500;
        }

        .container {
            max-width: 700px;
            margin: 28px auto;
            padding: 0 18px;
        }

        .card {
            background: #FFFFFF;
            border-radius: 14px;
            padding: 28px;
            box-shadow: 0 3px 12px rgba(0,0,0,0.05);
            text-align: center;
        }

        .avatar {
            width: 96px;
            height: 96px;
            border-radius: 50%;
            background: #B8A8FF;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 16px;
            font-size: 38px;
            color: white;
            font-weight: 700;
        }

        .name {
            font-size: 24px;
            font-weight: 700;
            margin-bottom: 6px;
        }

        .badge {
            display: inline-block;
            background: #F0EEFF;
            color: #6C63FF;
            border-radius: 999px;
            padding: 5px 14px;
            font-size: 12px;
            font-weight: 600;
            margin-bottom: 18px;
        }

        .info-row {
            display: flex;
            align-items: center;
            gap: 10px;
            text-align: left;
            border-top: 1px solid #ECECEC;
            padding: 12px 0;
        }

        .info-row:last-of-type {
            border-bottom: 1px solid #ECECEC;
        }

        .info-icon {
            width: 34px;
            height: 34px;
            border-radius: 50%;
            background: #F7F7F7;
            color: #6C63FF;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .info-label {
            font-size: 12px;
            color: #888;
            margin-bottom: 2px;
        }

        .info-value {
            font-size: 14px;
            font-weight: 600;
        }

        .stats {
            margin-top: 18px;
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 10px;
        }

        .stat {
            border: 1px solid #ECECEC;
            border-radius: 10px;
            padding: 14px 10px;
            background: #FAFAFA;
        }

        .stat-number {
            font-size: 28px;
            color: #6C63FF;
            font-weight: 700;
            line-height: 1;
        }

        .stat-label {
            font-size: 12px;
            color: #777;
            margin-top: 6px;
        }

        .back-btn {
            display: inline-block;
            margin-top: 18px;
            background: #6C63FF;
            color: #fff;
            text-decoration: none;
            font-weight: 600;
            padding: 10px 18px;
            border-radius: 8px;
            border: none;
            cursor: pointer;
            transition: background 0.2s;
        }

        .back-btn:hover {
            background: #5A52E0;
        }

        .edit-btn {
            background: #fff;
            color: #6C63FF;
            border: 2px solid #6C63FF;
            padding: 8px 16px;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            margin-top: 14px;
            transition: all 0.2s;
        }

        .edit-btn:hover {
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
            margin-top: 18px;
            border-top: 1px solid #ECECEC;
            padding-top: 16px;
            text-align: left;
        }

        .form-title {
            font-size: 15px;
            font-weight: 700;
            margin-bottom: 10px;
            color: #2D2D2D;
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

        .input {
            width: 100%;
            border: 1px solid #d6d6d6;
            border-radius: 6px;
            padding: 10px;
            font-size: 14px;
        }

        .actions {
            display: flex;
            gap: 10px;
            margin-top: 4px;
            flex-wrap: wrap;
        }

        @media (max-width: 520px) {
            .stats {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <header class="header">
        <div class="brand">PLANORA</div>
        <a class="logout-btn" href="../logout.php">Logout</a>
    </header>

    <main class="container">
        <?php if ($flashSuccess !== ''): ?>
            <div class="message message-success"><?php echo htmlspecialchars($flashSuccess); ?></div>
        <?php endif; ?>

        <?php if ($flashError !== ''): ?>
            <div class="message message-error"><?php echo htmlspecialchars($flashError); ?></div>
        <?php endif; ?>

        <section class="card">
            <div class="avatar"><?php echo strtoupper(substr($fullName, 0, 1)); ?></div>
            <h1 class="name"><?php echo htmlspecialchars($fullName); ?></h1>
            <span class="badge">Planner</span>

            <div class="info-row">
                <div class="info-icon"><i class="fa-solid fa-envelope"></i></div>
                <div>
                    <div class="info-label">Email</div>
                    <div class="info-value"><?php echo htmlspecialchars($email); ?></div>
                </div>
            </div>

            <div class="info-row">
                <div class="info-icon"><i class="fa-solid fa-user-shield"></i></div>
                <div>
                    <div class="info-label">Role</div>
                    <div class="info-value">Planner</div>
                </div>
            </div>

            <div class="stats">
                <div class="stat">
                    <div class="stat-number"><?php echo $eventCount; ?></div>
                    <div class="stat-label">Events</div>
                </div>
                <div class="stat">
                    <div class="stat-number"><?php echo $bookingCount; ?></div>
                    <div class="stat-label">Bookings</div>
                </div>
            </div>

            <!-- Edit button toggles the form -->
            <button type="button" class="edit-btn" onclick="toggleEditForm()" id="toggleEditBtn">
                <i class="fa-solid fa-pen-to-square"></i> Edit Profile
            </button>

            <!-- Edit form (hidden by default) -->
            <div id="editFormContainer" style="display: none;">
                <form method="POST" class="edit-form">
                    <?php echo csrf_input(); ?>
                    <h2 class="form-title">Edit Profile</h2>

                    <div class="field">
                        <label for="full_name">Full Name</label>
                        <input class="input" type="text" id="full_name" name="full_name" value="<?php echo htmlspecialchars($fullName); ?>" required>
                    </div>

                    <div class="field">
                        <label for="email">Email</label>
                        <input class="input" type="email" id="email" name="email" value="<?php echo htmlspecialchars($email); ?>" required>
                    </div>

                    <div class="actions">
                        <button type="submit" name="update_profile" value="1" class="back-btn">Save Changes</button>
                        <button type="button" class="edit-btn" onclick="toggleEditForm()">Cancel</button>
                    </div>
                </form>
            </div>

            <a class="back-btn" href="dashboard.php">Back to Dashboard</a>
        </section>
    </main>

    <script>
        function toggleEditForm() {
            const formContainer = document.getElementById('editFormContainer');
            const toggleBtn = document.getElementById('toggleEditBtn');

            if (formContainer.style.display === 'none') {
                formContainer.style.display = 'block';
                toggleBtn.style.display = 'none';   // hide the Edit button while editing
            } else {
                formContainer.style.display = 'none';
                toggleBtn.style.display = 'inline-block'; // show Edit button again
            }
        }
    </script>
</body>
</html>

