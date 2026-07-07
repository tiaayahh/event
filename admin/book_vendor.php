<?php
require_once '../includes/auth.php';
checkAuth();
requireRole('planner');

$vendorId = filter_input(INPUT_GET, 'vendor_id', FILTER_VALIDATE_INT);
$eventId = filter_input(INPUT_GET, 'event_id', FILTER_VALIDATE_INT);

if (!$vendorId || !$eventId) {
	$_SESSION['flash_error'] = 'Vendor and event are required.';
	header('Location: browse_vendors.php');
	exit;
}

$vendor = null;
$event = null;
$services = [];
$vendorAvgRating = 0.0;
$vendorRatingCount = 0;
$flashError = '';
$boothNumber = '';

function inferRequiredVendorTypeForCategory(?string $category): string
{
	$normalized = strtolower(trim((string)$category));
	return (strpos($normalized, 'market') !== false) ? 'market_operator' : 'service_provider';
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

function ensureEventVendorFeeSchema(PDO $pdo): void
{
	static $ready = false;
	if ($ready) {
		return;
	}

	$stmt = $pdo->query("SHOW COLUMNS FROM events LIKE 'vendor_fee_amount'");
	if (!$stmt->fetch()) {
		$pdo->exec("ALTER TABLE events ADD COLUMN vendor_fee_amount DECIMAL(10,2) NOT NULL DEFAULT 100.00");
	}

	$ready = true;
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

function ensureBookingBoothSchema(PDO $pdo): void
{
	static $ready = false;
	if ($ready) {
		return;
	}

	$stmt = $pdo->query("SHOW COLUMNS FROM bookings LIKE 'booth_number'");
	if (!$stmt->fetch()) {
		$pdo->exec("ALTER TABLE bookings ADD COLUMN booth_number VARCHAR(64) DEFAULT NULL AFTER platform_fee");
	}

	$ready = true;
}

try {
	ensureServiceRatingsTable($pdo);
	ensureEventVendorFeeSchema($pdo);
	ensureBookingBoothSchema($pdo);
	ensureVendorTypeSchema($pdo);

	$stmt = $pdo->prepare("SELECT vendor_id, user_id, business_name, COALESCE(vendor_type, 'service_provider') AS vendor_type FROM vendors WHERE vendor_id = ? LIMIT 1");
	$stmt->execute([$vendorId]);
	$vendor = $stmt->fetch();

	$stmt = $pdo->prepare('SELECT event_id, title, event_date, vendor_fee_amount, COALESCE(category, "") AS category FROM events WHERE event_id = ? AND planner_id = ? AND archived_at IS NULL LIMIT 1');
	$stmt->execute([$eventId, $_SESSION['user_id']]);
	$event = $stmt->fetch();

	$stmt = $pdo->prepare('SELECT COALESCE(AVG(rating), 0) AS avg_rating, COUNT(*) AS rating_count FROM service_ratings WHERE vendor_id = ?');
	$stmt->execute([$vendorId]);
	$ratingData = $stmt->fetch();
	if ($ratingData) {
		$vendorAvgRating = (float)$ratingData['avg_rating'];
		$vendorRatingCount = (int)$ratingData['rating_count'];
	}

	if (!$vendor || !$event) {
		$_SESSION['flash_error'] = 'Selected vendor or event was not found.';
		header('Location: browse_vendors.php');
		exit;
	}

	$requiredVendorType = inferRequiredVendorTypeForCategory((string)$event['category']);
	$actualVendorType = (string)($vendor['vendor_type'] ?? 'service_provider');
	if ($actualVendorType !== $requiredVendorType) {
		$_SESSION['flash_error'] = 'This vendor type cannot be booked for the selected event category.';
		header('Location: browse_vendors.php?event_id=' . (int)$eventId);
		exit;
	}
	if ($requiredVendorType === 'market_operator') {
		$_SESSION['flash_error'] = 'This is a market event. Use Stall Registration instead of service booking.';
		header('Location: stall_rentals.php?vendor_id=' . (int)$vendorId . '&event_id=' . (int)$eventId);
		exit;
	}

	if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['service_id'])) {
		csrf_require_valid_post_token();

		$serviceId = filter_input(INPUT_POST, 'service_id', FILTER_VALIDATE_INT);
		$boothNumber = trim((string)($_POST['booth_number'] ?? ''));
		if (strlen($boothNumber) > 64) {
			$boothNumber = substr($boothNumber, 0, 64);
		}

		if (!$serviceId) {
			$flashError = 'Please choose a valid service.';
		} else {
			$stmt = $pdo->prepare('SELECT service_id, name, price, availability FROM services WHERE service_id = ? AND vendor_id = ? LIMIT 1');
			$stmt->execute([$serviceId, $vendorId]);
			$service = $stmt->fetch();

			if (!$service) {
				$flashError = 'Service not found for this vendor.';
			} elseif ((int)$service['availability'] !== 1) {
				$flashError = 'That service is currently unavailable.';
			} else {
				$pdo->exec(
					"CREATE TABLE IF NOT EXISTS messages (
						message_id INT AUTO_INCREMENT PRIMARY KEY,
						planner_user_id INT NOT NULL,
						vendor_user_id INT NOT NULL,
						sender_role ENUM('planner','vendor') NOT NULL,
						message_text TEXT NOT NULL,
						is_read TINYINT(1) NOT NULL DEFAULT 0,
						created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
						INDEX idx_conversation (planner_user_id, vendor_user_id, created_at)
					) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
				);

				$stmt = $pdo->prepare('SELECT booking_id FROM bookings WHERE event_id = ? AND service_id = ? LIMIT 1');
				$stmt->execute([$eventId, $serviceId]);
				if ($stmt->fetch()) {
					$flashError = 'This service is already booked for the selected event.';
				} else {
					$pdo->beginTransaction();

					$eventVendorFeeAmount = (float)($event['vendor_fee_amount'] ?? 100);
					$stmt = $pdo->prepare('INSERT INTO bookings (event_id, service_id, status, booked_price, platform_fee, booth_number, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())');
					$stmt->execute([$eventId, $serviceId, 'pending', $service['price'], $eventVendorFeeAmount, $boothNumber !== '' ? $boothNumber : null]);
					$newBookingId = (int)$pdo->lastInsertId();

					$notificationText = sprintf(
						'You have a new booking: "%s" was booked for event "%s" scheduled on %s.',
						(string)$service['name'],
						(string)$event['title'],
						(string)$event['event_date']
					);

					$stmt = $pdo->prepare('INSERT INTO messages (planner_user_id, vendor_user_id, sender_role, message_text, is_read) VALUES (?, ?, ?, ?, 0)');
					$stmt->execute([$_SESSION['user_id'], (int)$vendor['user_id'], 'planner', $notificationText]);

					audit_log(
						$pdo,
						(int)$_SESSION['user_id'],
						(string)$_SESSION['role'],
						'booking.create',
						'booking',
						(string)$newBookingId,
						[
							'event_id' => (int)$eventId,
							'service_id' => (int)$serviceId,
							'vendor_id' => (int)$vendorId,
								'booth_number' => $boothNumber,
						]
					);

					$pdo->commit();

					$_SESSION['flash_success'] = 'Vendor service booked successfully.';
					header('Location: dashboard.php');
					exit;
				}
			}
		}
	}

	$stmt = $pdo->prepare(
		'SELECT s.service_id, s.name, s.description, s.price,
		        COALESCE(AVG(sr.rating), 0) AS avg_rating,
		        COUNT(sr.rating_id) AS rating_count
		 FROM services s
		 LEFT JOIN service_ratings sr ON sr.service_id = s.service_id
		 WHERE s.vendor_id = ? AND s.availability = 1
		 GROUP BY s.service_id, s.name, s.description, s.price
		 ORDER BY s.name ASC'
	);
	$stmt->execute([$vendorId]);
	$services = $stmt->fetchAll();
} catch (Throwable $e) {
	if ($pdo->inTransaction()) {
		$pdo->rollBack();
	}
	audit_log(
		$pdo,
		isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null,
		$_SESSION['role'] ?? null,
		'booking.create_failed',
		'booking',
		null,
		['reason' => 'exception']
	);
	$flashError = 'Unable to load booking details right now.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="../assets/style.css">
	<title>Planora - Book Vendor</title>
	<style>
		* { box-sizing: border-box; margin: 0; padding: 0; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; }
		body { background: #f6f6f6; color: #2D2D2D; min-height: 100vh; }
		.header { background: #6C63FF; color: #fff; padding: 14px 24px; display: flex; justify-content: space-between; align-items: center; }
		.brand { font-size: 22px; font-weight: 700; }
		.logout-btn { background: rgba(255,255,255,.25); color: #fff; text-decoration: none; padding: 7px 14px; border-radius: 5px; font-size: 13px; }
		.container { max-width: 860px; margin: 0 auto; padding: 20px; }
		.card { background: #fff; border-radius: 8px; padding: 20px; margin-bottom: 20px; box-shadow: 0 1px 3px rgba(0,0,0,.05); }
		.title { font-size: 18px; font-weight: 700; margin-bottom: 10px; }
		.meta { color: #666; font-size: 14px; margin-bottom: 8px; }
		.message-error { background: #ffecec; color: #9d2020; border: 1px solid #f6caca; border-radius: 6px; padding: 10px 12px; margin-bottom: 14px; font-size: 13px; }
		.service-row { display: flex; justify-content: space-between; align-items: center; gap: 10px; padding: 12px 0; border-bottom: 1px solid #eee; }
		.service-row:last-child { border-bottom: none; }
		.rating-line { color: #5a4fcf; font-size: 13px; margin-top: 4px; }
		.btn { background: #6C63FF; color: #fff; border: none; border-radius: 6px; padding: 9px 12px; font-size: 13px; cursor: pointer; }
		.link-btn { text-decoration: none; background: #ece9ff; color: #3b3496; border-radius: 6px; padding: 8px 12px; font-size: 13px; }
		.top-links { display: flex; gap: 10px; margin-bottom: 16px; }
		.empty { color: #777; font-size: 14px; }
		.desc { color: #666; font-size: 13px; margin-top: 4px; }
	</style>
</head>
<body>
<header class="header">
	<div class="brand">PLANORA</div>
	<a class="logout-btn" href="../logout.php">Logout</a>
</header>

<div class="container">
	<div class="top-links">
		<a class="link-btn" href="browse_vendors.php?event_id=<?php echo (int)$eventId; ?>">Back to Vendors</a>
		<a class="link-btn" href="dashboard.php">Dashboard</a>
	</div>

	<?php if ($flashError !== ''): ?><div class="message-error"><?php echo htmlspecialchars($flashError); ?></div><?php endif; ?>

	<section class="card">
		<h2 class="title">Book Vendor Service</h2>
		<div class="meta">Vendor: <strong><?php echo htmlspecialchars((string)($vendor['business_name'] ?? '')); ?></strong></div>
		<div class="meta">Vendor Type: <strong><?php echo (($vendor['vendor_type'] ?? 'service_provider') === 'market_operator') ? 'Market Operator' : 'Service Provider'; ?></strong></div>
		<div class="meta">Vendor Rating: <strong><?php echo number_format($vendorAvgRating, 1); ?>/5</strong> (<?php echo $vendorRatingCount; ?> ratings)</div>
		<div class="meta">Event: <strong><?php echo htmlspecialchars((string)($event['title'] ?? '')); ?></strong> (<?php echo htmlspecialchars((string)($event['event_date'] ?? '')); ?>)</div>
		<div class="meta">Event Category: <strong><?php echo htmlspecialchars((string)(($event['category'] ?? '') !== '' ? $event['category'] : 'General')); ?></strong></div>
	</section>

	<section class="card">
		<h2 class="title">Available Services</h2>
		<?php if (empty($services)): ?>
			<div class="empty">No available services for this vendor right now.</div>
		<?php else: ?>
			<?php foreach ($services as $service): ?>
				<div class="service-row">
					<div>
						<strong><?php echo htmlspecialchars($service['name']); ?></strong>
						<div class="desc">KES <?php echo htmlspecialchars(number_format((float)$service['price'], 2)); ?><?php if (($service['description'] ?? '') !== ''): ?> - <?php echo htmlspecialchars($service['description']); ?><?php endif; ?></div>
						<div class="rating-line">Rating: <?php echo number_format((float)$service['avg_rating'], 1); ?>/5 (<?php echo (int)$service['rating_count']; ?>)</div>
					</div>
					<form method="POST">
						<?php echo csrf_input(); ?>
						<input type="hidden" name="service_id" value="<?php echo (int)$service['service_id']; ?>">
						<input type="text" name="booth_number" class="form-input" style="margin-bottom:8px; min-width:180px;" placeholder="Booth number (optional)">
						<button type="submit" class="btn">Book Service</button>
					</form>
				</div>
			<?php endforeach; ?>
		<?php endif; ?>
	</section>
</div>
</body>
</html>


