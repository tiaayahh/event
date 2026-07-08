<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    $secureCookie = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $secureCookie,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

session_start();
require_once 'config/db.php';
require_once 'includes/csrf.php';
require_once 'includes/audit.php';

$error = '';

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

function isDisposableEmailDomain(string $email): bool
{
    $domain = strtolower((string)substr(strrchr($email, '@') ?: '', 1));
    if ($domain === '') {
        return false;
    }

    $blockedDomains = [
        '10minutemail.com',
        'guerrillamail.com',
        'mailinator.com',
        'tempmail.com',
        'yopmail.com',
    ];

    return in_array($domain, $blockedDomains, true);
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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require_valid_post_token();

    $fullName = trim($_POST['full_name']);
    $email    = strtolower(trim($_POST['email']));
    $rawPassword = $_POST['password'] ?? '';
    $role     = $_POST['role'] ?? '';
    $vendorTypeInput = strtolower(trim((string)($_POST['vendor_type'] ?? '')));
    $phoneRaw = trim((string)($_POST['phone_number'] ?? ''));
    $normalizedPhone = normalizeKenyanPhone($phoneRaw);

    if (!in_array($role, ['vendor', 'attendee', 'planner'], true)) {
        $error = 'Please select a valid role.';
    } elseif ($fullName === '' || $email === '' || $rawPassword === '') {
        $error = 'All required fields must be filled.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } elseif (strlen($email) > 190) {
        $error = 'Email address is too long.';
    } elseif (isDisposableEmailDomain($email)) {
        $error = 'Please use a permanent email address.';
    } elseif (!isStrongPassword($rawPassword)) {
        $error = 'Password must be at least 8 characters and include uppercase, lowercase, number, and symbol.';
    } elseif ($role === 'vendor' && $normalizedPhone === '') {
        $error = 'Vendor phone number is required. Use 07XXXXXXXX or 2547XXXXXXXX.';
    } elseif ($role === 'vendor' && !in_array($vendorTypeInput, ['service_provider', 'market_operator'], true)) {
        $error = 'Please choose whether you are a service provider or market operator.';
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
            ensureUsersPhoneSchema($pdo);

            $stmt = $pdo->prepare("INSERT INTO users (full_name, email, phone_number, password_hash, role) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$fullName, $email, $normalizedPhone !== '' ? $normalizedPhone : null, $password, $role]);
            $userId = $pdo->lastInsertId();

            if ($role === 'vendor') {
                ensureVendorTypeSchema($pdo);
                $businessName = trim($_POST['business_name'] ?? '');
                $serviceType  = trim($_POST['service_type'] ?? '');
                $vendorType = strtolower(trim((string)($_POST['vendor_type'] ?? '')));
                $desc         = trim($_POST['description'] ?? '');

                $allowedServiceTypes = vendorServiceTypeOptions();
                if (!in_array($serviceType, $allowedServiceTypes, true)) {
                    $serviceType = '';
                }

                if (!in_array($vendorType, ['service_provider', 'market_operator'], true)) {
                    throw new RuntimeException('Please choose whether you are a service provider or market operator.');
                }

                if ($businessName === '') {
                    throw new RuntimeException('Business name is required for vendors.');
                }

                if ($vendorType === 'service_provider') {
                    if ($serviceType === '') {
                        throw new RuntimeException('Service type is required for service providers.');
                    }
                } else {
                    if ($serviceType === '' || !in_array($serviceType, $allowedServiceTypes, true)) {
                        $serviceType = 'Market Stall Seller';
                    }
                }

                $stmt = $pdo->prepare("INSERT INTO vendors (user_id, business_name, service_type, vendor_type, description) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$userId, $businessName, $serviceType, $vendorType, $desc]);
            } elseif ($role === 'attendee') {
                $stmt = $pdo->prepare("INSERT INTO attendees (user_id) VALUES (?)");
                $stmt->execute([$userId]);
            }

            $pdo->commit();

            audit_log(
                $pdo,
                (int)$userId,
                $role,
                'auth.register_success'
            );

            header('Location: login.php');
            exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            audit_log(
                $pdo,
                null,
                $role ?: null,
                'auth.register_failed',
                'user',
                $email,
                ['reason' => 'exception']
            );
            $error = 'Registration failed. Please try again.';
            error_log('register.php error: ' . $e->getMessage());
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Planora · Register</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,400;14..32,500;14..32,600;14..32,700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #6C63FF;
            --primary-light: #B8A8FF;
            --bg: #F5F5F5;
            --text: #2D2D2D;
            --white: #FFFFFF;
            --error-bg: #ffecec;
            --error-text: #9d2020;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
        }

        body {
            background: linear-gradient(145deg, #F8F7FF 0%, #F5F5F5 100%);
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 20px;
        }

        .register-card {
            background: var(--white);
            border: 1px solid #e0e0e0;
            border-radius: 16px;
            width: 100%;
            max-width: 500px;
            padding: 40px 32px;
            box-shadow: 0 8px 28px rgba(108, 99, 255, 0.08);
        }

        .brand {
            color: var(--primary);
            font-size: 32px;
            font-weight: 700;
            text-align: center;
            letter-spacing: 0.5px;
            margin-bottom: 8px;
        }

        .subtitle {
            text-align: center;
            color: #666;
            font-size: 14px;
            margin-bottom: 28px;
        }

        .error-msg {
            background: var(--error-bg);
            color: var(--error-text);
            padding: 10px 14px;
            border-radius: 8px;
            font-size: 13px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .form-group {
            margin-bottom: 18px;
        }

        .form-group label {
            display: block;
            font-weight: 600;
            font-size: 13px;
            color: var(--text);
            margin-bottom: 6px;
        }

        .form-control {
            width: 100%;
            padding: 12px 14px;
            border: 1px solid #dcdcdc;
            border-radius: 8px;
            font-size: 14px;
            background: #fafafa;
            transition: border-color 0.2s;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            background: #fff;
        }

        .password-hint {
            font-size: 12px;
            color: #666;
            margin-top: 6px;
            line-height: 1.5;
        }

        select.form-control {
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%236C63FF' d='M10.293 3.293L6 7.586 1.707 3.293A1 1 0 00.293 4.707l5 5a1 1 0 001.414 0l5-5a1 1 0 10-1.414-1.414z'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 14px center;
            padding-right: 40px;
        }

        .vendor-fields {
            background: #F9F9FF;
            border-radius: 10px;
            padding: 16px;
            margin-top: 10px;
            display: none;
        }

        .vendor-fields label {
            font-size: 12px;
            margin-top: 8px;
        }

        .vendor-fields input,
        .vendor-fields textarea {
            margin-bottom: 4px;
        }

        .btn-register {
            width: 100%;
            background: var(--primary);
            color: white;
            border: none;
            padding: 14px;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            margin-top: 8px;
            transition: background 0.2s;
        }

        .btn-register:hover {
            background: #5A52E0;
        }

        .login-link {
            text-align: center;
            margin-top: 20px;
            font-size: 14px;
            color: var(--text);
        }

        .login-link a {
            color: var(--primary);
            text-decoration: none;
            font-weight: 600;
        }

        .login-link a:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>
    <div class="register-card">
        <div class="brand">PLANORA</div>
        <p class="subtitle">Create your account</p>

        <?php if ($error !== ''): ?>
            <div class="error-msg">
                <i class="fa-solid fa-circle-exclamation" style="color:#d93025;"></i>
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <form method="POST">
            <?php echo csrf_input(); ?>
            <div class="form-group">
                <label for="full_name">Full Name</label>
                <input type="text" id="full_name" name="full_name" class="form-control" placeholder="e.g. John Doe" required>
            </div>

            <div class="form-group">
                <label for="email">Email</label>
                <input type="email" id="email" name="email" class="form-control" placeholder="you@example.com" required>
            </div>

            <div class="form-group">
                <label for="phone_number">Phone Number</label>
                <input type="text" id="phone_number" name="phone_number" class="form-control" placeholder="e.g. 0712345678" value="<?php echo htmlspecialchars((string)($_POST['phone_number'] ?? '')); ?>">
                <p class="password-hint">Required for vendors to receive M-Pesa STK payments.</p>
            </div>

            <div class="form-group">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" class="form-control" placeholder="Use a strong password" required>
                <p class="password-hint">Use at least 8 characters with uppercase, lowercase, number, and symbol.</p>
            </div>

            <div class="form-group">
                <label for="roleSelect">I am a</label>
                <select name="role" id="roleSelect" class="form-control" required>
                    <option value="">-- Select your role --</option>
                    <option value="planner" <?php echo (($_POST['role'] ?? '') === 'planner') ? 'selected' : ''; ?>>Planner</option>
                    <option value="vendor" <?php echo (($_POST['role'] ?? '') === 'vendor') ? 'selected' : ''; ?>>Vendor</option>
                    <option value="attendee" <?php echo (($_POST['role'] ?? '') === 'attendee') ? 'selected' : ''; ?>>Attendee</option>
                </select>
            </div>

            <div id="vendorFields" class="vendor-fields">
                <div class="form-group">
                    <label for="vendor_type">Are you a</label>
                    <select id="vendor_type" name="vendor_type" class="form-control">
                        <option value="">-- Select vendor type --</option>
                        <option value="service_provider" <?php echo (($_POST['vendor_type'] ?? '') === 'service_provider') ? 'selected' : ''; ?>>Service Provider (DJ, Caterer, Photographer - hired by planners to do a job)</option>
                        <option value="market_operator" <?php echo (($_POST['vendor_type'] ?? '') === 'market_operator') ? 'selected' : ''; ?>>Market Operator (Small business selling goods - pays a stall fee to sell at events)</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="business_name">Business Name</label>
                    <input type="text" id="business_name" name="business_name" class="form-control" placeholder="e.g. DJ Brian Entertainments" value="<?php echo htmlspecialchars((string)($_POST['business_name'] ?? '')); ?>">
                </div>
                <div class="form-group">
                    <label for="service_type" id="serviceTypeLabel">Service Type</label>
                    <select id="service_type" name="service_type" class="form-control">
                        <option value="">-- Select service type --</option>
                        <?php foreach (vendorServiceTypeOptions() as $option): ?>
                            <option value="<?php echo htmlspecialchars($option); ?>" <?php echo (($_POST['service_type'] ?? '') === $option) ? 'selected' : ''; ?>><?php echo htmlspecialchars($option); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="description">Description</label>
                    <textarea id="description" name="description" class="form-control" rows="3" placeholder="Tell clients about your service..."><?php echo htmlspecialchars((string)($_POST['description'] ?? '')); ?></textarea>
                </div>
            </div>

            <button type="submit" class="btn-register">Register</button>
        </form>

        <div class="login-link">
            Already have an account? <a href="login.php">Login here</a>
        </div>
    </div>

    <script>
        const roleSelect = document.getElementById('roleSelect');
        const vendorFields = document.getElementById('vendorFields');
        const vendorTypeSelect = document.getElementById('vendor_type');
        const businessNameInput = document.getElementById('business_name');
        const serviceTypeSelect = document.getElementById('service_type');
        const serviceTypeLabel = document.getElementById('serviceTypeLabel');

        function syncVendorFieldRequirements() {
            const isVendor = roleSelect.value === 'vendor';
            const isServiceProvider = vendorTypeSelect.value === 'service_provider';

            vendorTypeSelect.required = isVendor;
            businessNameInput.required = isVendor;
            serviceTypeSelect.required = isVendor && isServiceProvider;

            serviceTypeLabel.textContent = isServiceProvider ? 'Service Type' : 'Service Type (optional for market operators)';
        }

        roleSelect.addEventListener('change', function () {
            vendorFields.style.display = this.value === 'vendor' ? 'block' : 'none';
            syncVendorFieldRequirements();
        });

        vendorTypeSelect.addEventListener('change', syncVendorFieldRequirements);

        // Show vendor fields on page load if vendor was pre-selected (e.g., after validation error)
        if (roleSelect.value === 'vendor') {
            vendorFields.style.display = 'block';
        }

        syncVendorFieldRequirements();
    </script>
</body>
</html>