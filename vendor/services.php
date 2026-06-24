<?php
require_once '../includes/auth.php';
checkAuth();
requireRole('vendor');

$fullName = $_SESSION['full_name'] ?? 'Vendor';
$flashSuccess = $_SESSION['flash_success'] ?? '';
$flashError = $_SESSION['flash_error'] ?? '';
unset($_SESSION['flash_success'], $_SESSION['flash_error']);
$services = [];
$editServiceId = filter_input(INPUT_GET, 'edit', FILTER_VALIDATE_INT);
$editService = null;

try {
    $stmt = $pdo->prepare('SELECT vendor_id FROM vendors WHERE user_id = ? LIMIT 1');
    $stmt->execute([$_SESSION['user_id']]);
    $vendor = $stmt->fetch();

    if (!$vendor) {
        $flashError = 'Vendor profile not found.';
    } else {
        $vendorId = (int)$vendor['vendor_id'];

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_service'])) {
            $name = trim($_POST['name'] ?? '');
            $price = trim($_POST['price'] ?? '');
            $description = trim($_POST['description'] ?? '');

            if ($name === '' || $price === '' || !is_numeric($price)) {
                $_SESSION['flash_error'] = 'Service name and valid price are required.';
            } else {
                $stmt = $pdo->prepare('INSERT INTO services (vendor_id, name, description, price, availability) VALUES (?, ?, ?, ?, 1)');
                $stmt->execute([$vendorId, $name, $description, $price]);
                $_SESSION['flash_success'] = 'Service added successfully.';
            }
            header('Location: services.php');
            exit;
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_service'], $_POST['service_id'])) {
            $serviceId = filter_input(INPUT_POST, 'service_id', FILTER_VALIDATE_INT);
            $name = trim($_POST['name'] ?? '');
            $price = trim($_POST['price'] ?? '');
            $description = trim($_POST['description'] ?? '');

            if (!$serviceId || $name === '' || $price === '' || !is_numeric($price)) {
                $_SESSION['flash_error'] = 'Valid service name and price are required for update.';
            } else {
                $stmt = $pdo->prepare('UPDATE services SET name = ?, price = ?, description = ? WHERE service_id = ? AND vendor_id = ?');
                $stmt->execute([$name, $price, $description, $serviceId, $vendorId]);
                $_SESSION['flash_success'] = 'Service updated successfully.';
            }
            header('Location: services.php');
            exit;
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_service'], $_POST['service_id'])) {
            $serviceId = filter_input(INPUT_POST, 'service_id', FILTER_VALIDATE_INT);
            if (!$serviceId) {
                $_SESSION['flash_error'] = 'Invalid service selected for deletion.';
            } else {
                $stmt = $pdo->prepare('SELECT COUNT(*) FROM bookings WHERE service_id = ?');
                $stmt->execute([$serviceId]);
                $bookingCount = (int)$stmt->fetchColumn();

                if ($bookingCount > 0) {
                    $_SESSION['flash_error'] = 'Cannot delete a service that already has bookings.';
                } else {
                    $stmt = $pdo->prepare('DELETE FROM services WHERE service_id = ? AND vendor_id = ?');
                    $stmt->execute([$serviceId, $vendorId]);
                    $_SESSION['flash_success'] = 'Service deleted successfully.';
                }
            }
            header('Location: services.php');
            exit;
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_service'], $_POST['service_id'])) {
            $serviceId = filter_input(INPUT_POST, 'service_id', FILTER_VALIDATE_INT);
            $availability = isset($_POST['availability']) ? 1 : 0;
            if ($serviceId) {
                $stmt = $pdo->prepare('UPDATE services SET availability = ? WHERE service_id = ? AND vendor_id = ?');
                $stmt->execute([$availability, $serviceId, $vendorId]);
                $_SESSION['flash_success'] = 'Service updated.';
            }
            header('Location: services.php');
            exit;
        }

        $stmt = $pdo->prepare('SELECT service_id, name, description, price, availability FROM services WHERE vendor_id = ? ORDER BY name ASC');
        $stmt->execute([$vendorId]);
        $services = $stmt->fetchAll();

        if ($editServiceId) {
            $stmt = $pdo->prepare('SELECT service_id, name, description, price FROM services WHERE service_id = ? AND vendor_id = ? LIMIT 1');
            $stmt->execute([$editServiceId, $vendorId]);
            $editService = $stmt->fetch();
            if (!$editService) {
                $flashError = 'The selected service for editing was not found.';
                $editServiceId = null;
            }
        }
    }
} catch (Throwable $e) {
    $flashError = 'Unable to load services at the moment.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="../assets/style.css">
    <title>Planora - Services</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; }
        body { background: #f6f6f6; color: #333333; padding-bottom: 80px; }
        .header { background: #635bff; color: #fff; padding: 14px 24px; display: flex; justify-content: space-between; align-items: center; }
        .brand-logo { font-size: 22px; font-weight: 700; }
        .logout-btn { background: rgba(255,255,255,0.25); color: #fff; border: 1px solid rgba(255,255,255,0.3); padding: 6px 14px; border-radius: 4px; text-decoration: none; font-size: 13px; }
        .container { max-width: 1100px; margin: 0 auto; padding: 20px; }
        .dashboard-card { background: #fff; border-radius: 6px; padding: 20px; margin-bottom: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.05); }
        .card-title { font-size: 16px; font-weight: 700; margin-bottom: 14px; }
        .card-subtitle { color: #666; font-size: 13px; margin-bottom: 12px; }
        .message { border-radius: 6px; padding: 10px 12px; margin-bottom: 14px; font-size: 13px; }
        .message-success { background: #ecfff0; color: #1c7a36; border: 1px solid #c9f0d4; }
        .message-error { background: #ffecec; color: #9d2020; border: 1px solid #f6caca; }
        .grid { display: grid; grid-template-columns: 1fr 160px 1fr auto; gap: 10px; }
        .input, .textarea { width: 100%; border: 1px solid #d6d6d6; border-radius: 6px; padding: 10px; font-size: 14px; }
        .textarea { min-height: 44px; resize: vertical; }
        .save-btn { background: #635bff; color: #fff; border: none; padding: 10px 12px; border-radius: 4px; font-size: 12px; cursor: pointer; }
        .action-btn { border: none; border-radius: 4px; padding: 8px 10px; font-size: 12px; cursor: pointer; text-decoration: none; display: inline-block; }
        .edit-btn { background: #ece9ff; color: #3b3496; }
        .delete-btn { background: #ffecec; color: #9d2020; }
        .service-actions { display: flex; align-items: center; gap: 8px; }
        .service-item { display: flex; justify-content: space-between; align-items: center; gap: 12px; padding: 12px 0; border-bottom: 1px solid #eeeeee; }
        .service-name { font-size: 14px; font-weight: 600; }
        .service-meta { color: #666; font-size: 12px; margin-top: 4px; }
        .availability-form { display: flex; align-items: center; gap: 10px; }
        .switch { position: relative; display: inline-block; width: 46px; height: 24px; }
        .switch input { opacity: 0; width: 0; height: 0; }
        .slider { position: absolute; top: 0; left: 0; right: 0; bottom: 0; border: 1px solid #777; border-radius: 24px; background: #fff; }
        .slider:before { content: ""; position: absolute; height: 16px; width: 16px; left: 3px; bottom: 3px; background: #333; border-radius: 50%; transition: .3s; }
        input:checked + .slider { background: #635bff; border-color: #635bff; }
        input:checked + .slider:before { transform: translateX(22px); background: #fff; }
        .bottom-nav { position: fixed; bottom: 0; left: 0; right: 0; height: 65px; background: #2b2b2b; display: flex; }
        .nav-link { color: #fff; text-decoration: none; opacity: .85; flex: 1; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 6px; font-size: 12px; }
        .nav-link.active, .nav-link:hover { opacity: 1; background: rgba(255,255,255,0.08); }
        @media (max-width: 900px) { .grid { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
<header class="header">
    <div class="brand-logo">PLANORA</div>
    <a href="../logout.php" class="logout-btn">Logout</a>
</header>
<div class="container">
    <p class="card-subtitle">Signed in as <?php echo htmlspecialchars($fullName); ?>.</p>
    <?php if ($flashSuccess !== ''): ?><div class="message message-success"><?php echo htmlspecialchars($flashSuccess); ?></div><?php endif; ?>
    <?php if ($flashError !== ''): ?><div class="message message-error"><?php echo htmlspecialchars($flashError); ?></div><?php endif; ?>

    <div class="dashboard-card">
        <h2 class="card-title">Add Service</h2>
        <form method="POST" class="grid">
            <?php echo csrf_input(); ?>
            <input type="hidden" name="add_service" value="1">
            <input class="input" type="text" name="name" placeholder="Service name" required>
            <input class="input" type="number" step="0.01" min="0" name="price" placeholder="Price" required>
            <textarea class="textarea" name="description" placeholder="Description"></textarea>
            <button class="save-btn" type="submit">Add</button>
        </form>
    </div>

    <?php if ($editService): ?>
    <div class="dashboard-card">
        <h2 class="card-title">Edit Service</h2>
        <form method="POST" class="grid">
            <?php echo csrf_input(); ?>
            <input type="hidden" name="edit_service" value="1">
            <input type="hidden" name="service_id" value="<?php echo (int)$editService['service_id']; ?>">
            <input class="input" type="text" name="name" value="<?php echo htmlspecialchars($editService['name']); ?>" required>
            <input class="input" type="number" step="0.01" min="0" name="price" value="<?php echo htmlspecialchars((string)$editService['price']); ?>" required>
            <textarea class="textarea" name="description" placeholder="Description"><?php echo htmlspecialchars((string)($editService['description'] ?? '')); ?></textarea>
            <div style="display:flex; gap:8px;">
                <button class="save-btn" type="submit">Update</button>
                <a class="action-btn edit-btn" href="services.php">Cancel</a>
            </div>
        </form>
    </div>
    <?php endif; ?>

    <div class="dashboard-card">
        <h2 class="card-title">My Services</h2>
        <?php if (empty($services)): ?>
            <div class="service-item"><span>No services added yet.</span></div>
        <?php else: ?>
            <?php foreach ($services as $service): ?>
                <div class="service-item">
                    <div>
                        <div class="service-name"><?php echo htmlspecialchars($service['name']); ?></div>
                        <div class="service-meta">KES <?php echo htmlspecialchars(number_format((float)$service['price'], 2)); ?><?php if (($service['description'] ?? '') !== ''): ?> - <?php echo htmlspecialchars($service['description']); ?><?php endif; ?></div>
                    </div>
                    <form method="POST" class="availability-form">
                        <?php echo csrf_input(); ?>
                        <input type="hidden" name="toggle_service" value="1">
                        <input type="hidden" name="service_id" value="<?php echo (int)$service['service_id']; ?>">
                        <label class="switch">
                            <input type="checkbox" name="availability" <?php echo (int)$service['availability'] === 1 ? 'checked' : ''; ?>>
                            <span class="slider"></span>
                        </label>
                        <button class="save-btn" type="submit">Save</button>
                    </form>
                    <div class="service-actions">
                        <a class="action-btn edit-btn" href="services.php?edit=<?php echo (int)$service['service_id']; ?>">Edit</a>
                        <form method="POST" onsubmit="return confirm('Delete this service?');">
                            <?php echo csrf_input(); ?>
                            <input type="hidden" name="delete_service" value="1">
                            <input type="hidden" name="service_id" value="<?php echo (int)$service['service_id']; ?>">
                            <button class="action-btn delete-btn" type="submit">Delete</button>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>
<nav class="bottom-nav">
    <a href="dashboard.php" class="nav-link"><i class="fa-solid fa-house"></i><span>Home</span></a>
    <a href="services.php" class="nav-link active"><i class="fa-solid fa-bell-concierge"></i><span>Services</span></a>
    <a href="bookings.php" class="nav-link"><i class="fa-solid fa-book-open"></i><span>Bookings</span></a>
    <a href="schedule.php" class="nav-link"><i class="fa-solid fa-calendar-days"></i><span>Schedule</span></a>
    <a href="profile.php" class="nav-link"><i class="fa-solid fa-user"></i><span>Profile</span></a>
</nav>
</body>
</html>


