<?php
session_start();
require_once 'config/db.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fullName = trim($_POST['full_name']);
    $email    = trim($_POST['email']);
    $rawPassword = $_POST['password'] ?? '';
    $role     = $_POST['role'] ?? '';

    if (!in_array($role, ['vendor', 'attendee', 'planner'], true)) {
        $error = 'Please select a valid role.';
    } elseif ($fullName === '' || $email === '' || $rawPassword === '') {
        $error = 'All required fields must be filled.';
    }

    if ($error === '') {
        $stmt = $pdo->prepare("SELECT user_id FROM users WHERE email = ? LIMIT 1");
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            $error = 'An account with this email already exists.';
        }
    }

    if ($error === '') {
        $password = password_hash($rawPassword, PASSWORD_BCRYPT);

        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare("INSERT INTO users (full_name, email, password_hash, role) VALUES (?, ?, ?, ?)");
            $stmt->execute([$fullName, $email, $password, $role]);
            $userId = $pdo->lastInsertId();

            if ($role === 'vendor') {
                $businessName = trim($_POST['business_name'] ?? '');
                $serviceType  = trim($_POST['service_type'] ?? '');
                $desc         = trim($_POST['description'] ?? '');

                if ($businessName === '' || $serviceType === '') {
                    throw new RuntimeException('Business name and service type are required for vendors.');
                }

                $stmt = $pdo->prepare("INSERT INTO vendors (user_id, business_name, service_type, description) VALUES (?, ?, ?, ?)");
                $stmt->execute([$userId, $businessName, $serviceType, $desc]);
            } elseif ($role === 'attendee') {
                $stmt = $pdo->prepare("INSERT INTO attendees (user_id) VALUES (?)");
                $stmt->execute([$userId]);
            }

            $pdo->commit();
            header('Location: login.php');
            exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Register</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
    <div class="login-container">
        <h2>Register</h2>
        <?php if ($error !== '') echo "<p class='error'>" . htmlspecialchars($error) . "</p>"; ?>
        <form method="POST">
            <label>Full Name:</label>
            <input type="text" name="full_name" required>
            <label>Email:</label>
            <input type="email" name="email" required>
            <label>Password:</label>
            <input type="password" name="password" required>
            <label>Role:</label>
            <select name="role" id="roleSelect" required>
                <option value="">-- Select --</option>
                <option value="vendor">Vendor</option>
                <option value="attendee">Attendee</option>
                <option value="planner">Planner</option>
            </select>
            <div id="vendorFields" style="display:none;">
                <label>Business Name:</label>
                <input type="text" name="business_name">
                <label>Service Type:</label>
                <input type="text" name="service_type">
                <label>Description:</label>
                <textarea name="description"></textarea>
            </div>
            <button type="submit">Register</button>
        </form>
        <p>Already have an account? <a href="login.php">Login</a></p>
    </div>
    <script>
        document.getElementById('roleSelect').addEventListener('change', function() {
            document.getElementById('vendorFields').style.display = this.value === 'vendor' ? 'block' : 'none';
        });
    </script>
</body>
</html>