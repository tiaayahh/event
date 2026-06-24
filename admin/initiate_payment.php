<?php
require_once '../includes/auth.php';
checkAuth();
requireRole('planner');

define('PLATFORM_FEE_PERCENT', 0.10);

$bookingId = filter_input(INPUT_GET, 'booking_id', FILTER_VALIDATE_INT);
if (!$bookingId) {
	$_SESSION['flash_error'] = 'Invalid booking selected for payment.';
	header('Location: payment_history.php');
	exit;
}

$booking = null;
$flashError = '';

try {
	$stmt = $pdo->prepare(
		"SELECT b.booking_id,
				b.event_id,
				b.status AS booking_status,
				b.booked_price,
				e.title AS event_title,
				e.event_date,
				s.name AS service_name,
				v.business_name,
				t.mpesa_code,
				t.status AS payment_status
		 FROM bookings b
		 JOIN events e ON b.event_id = e.event_id
		 JOIN services s ON b.service_id = s.service_id
		 JOIN vendors v ON s.vendor_id = v.vendor_id
		 LEFT JOIN transactions t ON b.booking_id = t.booking_id
		 WHERE b.booking_id = ? AND e.planner_id = ?
		 LIMIT 1"
	);
	$stmt->execute([$bookingId, $_SESSION['user_id']]);
	$booking = $stmt->fetch();

	if (!$booking) {
		$_SESSION['flash_error'] = 'Booking not found.';
		header('Location: payment_history.php');
		exit;
	}

	if ($_SERVER['REQUEST_METHOD'] === 'POST') {
		csrf_require_valid_post_token();

		$mpesaCode = strtoupper(trim($_POST['mpesa_code'] ?? ''));
		$paymentStatus = strtolower(trim($_POST['payment_status'] ?? 'pending'));

		if (!in_array($paymentStatus, ['pending', 'paid', 'failed'], true)) {
			$flashError = 'Invalid payment status selected.';
		} elseif ($paymentStatus === 'paid' && $mpesaCode === '') {
			$flashError = 'M-Pesa code is required when marking payment as paid.';
		} else {
			$pdo->beginTransaction();

			$stmt = $pdo->prepare('SELECT booking_id FROM transactions WHERE booking_id = ? LIMIT 1');
			$stmt->execute([$bookingId]);

			if ($stmt->fetch()) {
				$stmt = $pdo->prepare('UPDATE transactions SET mpesa_code = ?, amount = ?, status = ? WHERE booking_id = ?');
				$stmt->execute([$mpesaCode, (float)$booking['booked_price'], $paymentStatus, $bookingId]);
			} else {
				$stmt = $pdo->prepare('INSERT INTO transactions (booking_id, mpesa_code, amount, status) VALUES (?, ?, ?, ?)');
				$stmt->execute([$bookingId, $mpesaCode, (float)$booking['booked_price'], $paymentStatus]);
			}

			$wasConfirmedBefore = strtolower((string)$booking['booking_status']) === 'confirmed';
			$newBookingStatus = $paymentStatus === 'paid' ? 'confirmed' : 'pending';
			$stmt = $pdo->prepare('UPDATE bookings SET status = ? WHERE booking_id = ?');
			$stmt->execute([$newBookingStatus, $bookingId]);

			if ($paymentStatus === 'paid') {
				$platformFee = (float)$booking['booked_price'] * PLATFORM_FEE_PERCENT;
				$stmt = $pdo->prepare('UPDATE bookings SET platform_fee = ? WHERE booking_id = ?');
				$stmt->execute([$platformFee, $bookingId]);

				if (!$wasConfirmedBefore) {
					$stmt = $pdo->prepare('UPDATE events SET budget_committed = budget_committed + ? WHERE event_id = ? AND planner_id = ?');
					$stmt->execute([(float)$booking['booked_price'], (int)$booking['event_id'], $_SESSION['user_id']]);
				}
			}

			$pdo->commit();

			$_SESSION['flash_success'] = 'Payment updated successfully.';
			header('Location: payment_history.php');
			exit;
		}
	}
} catch (Throwable $e) {
	if ($pdo->inTransaction()) {
		$pdo->rollBack();
	}
	$flashError = 'Unable to update payment right now.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="../assets/style.css">
	<title>Planora - Initiate Payment</title>
	<style>
		* { box-sizing: border-box; margin: 0; padding: 0; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; }
		body { background: #F5F5F5; color: #2D2D2D; min-height: 100vh; }
		.header { background: #6C63FF; color: #fff; padding: 14px 24px; display: flex; justify-content: space-between; align-items: center; }
		.brand { font-size: 22px; font-weight: 700; }
		.logout-btn { background: rgba(255,255,255,0.22); color: #fff; text-decoration: none; border-radius: 6px; padding: 7px 14px; font-size: 13px; }
		.container { max-width: 760px; margin: 0 auto; padding: 20px; }
		.link-btn { text-decoration: none; background: #ece9ff; color: #3b3496; border-radius: 6px; padding: 8px 12px; font-size: 13px; display: inline-block; margin-bottom: 14px; }
		.card { background: #fff; border-radius: 8px; padding: 20px; box-shadow: 0 1px 3px rgba(0,0,0,.05); }
		.title { font-size: 18px; font-weight: 700; margin-bottom: 14px; }
		.meta { color: #666; font-size: 14px; margin-bottom: 8px; }
		.field { margin-bottom: 12px; }
		label { display: block; font-size: 13px; margin-bottom: 6px; color: #555; font-weight: 600; }
		.input, .select { width: 100%; border: 1px solid #d6d6d6; border-radius: 6px; padding: 10px; font-size: 14px; }
		.message-error { background: #ffecec; color: #9d2020; border: 1px solid #f6caca; border-radius: 6px; padding: 10px 12px; margin-bottom: 12px; font-size: 13px; }
		.btn { background: #6C63FF; color: #fff; border: none; border-radius: 6px; padding: 10px 14px; font-size: 13px; cursor: pointer; }
	</style>
</head>
<body>
<header class="header">
	<div class="brand">PLANORA</div>
	<a class="logout-btn" href="../logout.php">Logout</a>
</header>

<div class="container">
	<a class="link-btn" href="payment_history.php">Back to Payment History</a>

	<section class="card">
		<h2 class="title">Update Payment</h2>

		<?php if ($flashError !== ''): ?>
			<div class="message-error"><?php echo htmlspecialchars($flashError); ?></div>
		<?php endif; ?>

		<p class="meta"><strong>Event:</strong> <?php echo htmlspecialchars((string)$booking['event_title']); ?> (<?php echo htmlspecialchars((string)$booking['event_date']); ?>)</p>
		<p class="meta"><strong>Vendor/Service:</strong> <?php echo htmlspecialchars((string)$booking['business_name']); ?> - <?php echo htmlspecialchars((string)$booking['service_name']); ?></p>
		<p class="meta"><strong>Amount:</strong> KES <?php echo number_format((float)$booking['booked_price'], 2); ?></p>
		<p class="meta"><strong>Booking Status:</strong> <?php echo htmlspecialchars(ucfirst((string)$booking['booking_status'])); ?></p>

		<form method="POST">
			<?php echo csrf_input(); ?>
			<div class="field">
				<label for="payment_status">Payment Status</label>
				<select class="select" id="payment_status" name="payment_status" required>
					<?php $currentStatus = strtolower((string)($booking['payment_status'] ?? 'pending')); ?>
					<option value="pending" <?php echo $currentStatus === 'pending' ? 'selected' : ''; ?>>Pending</option>
					<option value="paid" <?php echo $currentStatus === 'paid' ? 'selected' : ''; ?>>Paid</option>
					<option value="failed" <?php echo $currentStatus === 'failed' ? 'selected' : ''; ?>>Failed</option>
				</select>
			</div>

			<div class="field">
				<label for="mpesa_code">M-Pesa Code (required for Paid)</label>
				<input class="input" type="text" id="mpesa_code" name="mpesa_code" value="<?php echo htmlspecialchars((string)($booking['mpesa_code'] ?? '')); ?>" placeholder="e.g. QWE123RTY">
			</div>

			<button class="btn" type="submit">Save Payment</button>
		</form>
	</section>
</div>
</body>
</html>


