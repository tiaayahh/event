<?php
require_once '../includes/auth.php';
require_once '../includes/daraja.php';
checkAuth();
requireRole('planner');

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

$bookingId = filter_input(INPUT_GET, 'booking_id', FILTER_VALIDATE_INT);
if (!$bookingId) {
	$_SESSION['flash_error'] = 'Invalid booking selected for payment.';
	header('Location: mpesa_payments.php');
	exit;
}

$booking = null;
$flashError = '';
$flashSuccess = '';
$paymentTimeline = [];
$darajaConfigured = daraja_is_configured();

try {
	ensureEventVendorFeeSchema($pdo);

	$stmt = $pdo->prepare(
		"SELECT b.booking_id,
				b.event_id,
				b.status AS booking_status,
				b.booked_price,
				b.platform_fee,
				e.vendor_fee_amount,
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
		 WHERE b.booking_id = ? AND e.planner_id = ? AND e.archived_at IS NULL
		 LIMIT 1"
	);
	$stmt->execute([$bookingId, $_SESSION['user_id']]);
	$booking = $stmt->fetch();

	if (!$booking) {
		$_SESSION['flash_error'] = 'Booking not found.';
		header('Location: mpesa_payments.php');
		exit;
	}

	if (function_exists('ensureAuditLogsTable')) {
		ensureAuditLogsTable($pdo);
	}
    ensure_daraja_stk_requests_table($pdo);

	if ($_SERVER['REQUEST_METHOD'] === 'POST') {
		csrf_require_valid_post_token();
		$action = strtolower(trim((string)($_POST['action'] ?? 'save')));

		$mpesaCode = strtoupper(trim($_POST['mpesa_code'] ?? ''));
		$paymentStatus = strtolower(trim($_POST['payment_status'] ?? 'pending'));
		$oldBookingStatus = strtolower((string)$booking['booking_status']);
		$newBookingStatus = $paymentStatus === 'paid' ? 'confirmed' : 'pending';
		$bookedPrice = (float)$booking['booked_price'];
		$platformFee = (float)($booking['vendor_fee_amount'] ?? 100);
		$mpesaCodeDb = $mpesaCode === '' ? null : $mpesaCode;

		if ($action === 'stk_push') {
			$phoneNumber = trim((string)($_POST['phone_number'] ?? ''));
			$pushResult = daraja_stk_push(
				$phoneNumber,
				$bookedPrice,
				'BOOKING-' . $bookingId,
				'Planora booking payment'
			);

			if (!empty($pushResult['success'])) {
				$checkoutRequestId = (string)($pushResult['checkout_request_id'] ?? '');
				$merchantRequestId = (string)($pushResult['merchant_request_id'] ?? '');
				$flashSuccess = 'Daraja STK push initiated successfully. Confirm payment on the customer phone.';

				if ($checkoutRequestId !== '') {
					$stmt = $pdo->prepare(
						"INSERT INTO daraja_stk_requests
							(booking_id, planner_user_id, checkout_request_id, merchant_request_id, phone_number, amount, status, raw_response)
						 VALUES (?, ?, ?, ?, ?, ?, 'requested', ?)
						 ON DUPLICATE KEY UPDATE
							booking_id = VALUES(booking_id),
							planner_user_id = VALUES(planner_user_id),
							merchant_request_id = VALUES(merchant_request_id),
							phone_number = VALUES(phone_number),
							amount = VALUES(amount),
							status = 'requested',
							raw_response = VALUES(raw_response)"
					);
					$stmt->execute([
						$bookingId,
						(int)$_SESSION['user_id'],
						$checkoutRequestId,
						$merchantRequestId !== '' ? $merchantRequestId : null,
						$phoneNumber !== '' ? $phoneNumber : null,
						$bookedPrice,
						json_encode($pushResult['payload'] ?? [], JSON_UNESCAPED_SLASHES),
					]);
				}

				audit_log(
					$pdo,
					(int)$_SESSION['user_id'],
					(string)$_SESSION['role'],
					'payment.stk_push_requested',
					'booking',
					(string)$bookingId,
					[
						'checkout_request_id' => $checkoutRequestId,
						'amount' => $bookedPrice,
					]
				);
			} else {
				$flashError = (string)($pushResult['message'] ?? 'Unable to initiate Daraja STK push right now.');
				audit_log(
					$pdo,
					(int)$_SESSION['user_id'],
					(string)$_SESSION['role'],
					'payment.stk_push_failed',
					'booking',
					(string)$bookingId,
					['reason' => $flashError]
				);
			}
		} elseif (!in_array($paymentStatus, ['pending', 'paid', 'failed'], true)) {
			$flashError = 'Invalid payment status selected.';
		} elseif ($paymentStatus === 'paid' && $mpesaCode === '') {
			$flashError = 'M-Pesa code is required when marking payment as paid.';
		} elseif ($mpesaCode !== '' && !preg_match('/^[A-Z0-9]{6,20}$/', $mpesaCode)) {
			$flashError = 'M-Pesa code format is invalid.';
		} else {
			$pdo->beginTransaction();

			$stmt = $pdo->prepare('SELECT booking_id FROM transactions WHERE booking_id = ? LIMIT 1');
			$stmt->execute([$bookingId]);

			if ($stmt->fetch()) {
				$stmt = $pdo->prepare('UPDATE transactions SET mpesa_code = ?, amount = ?, status = ? WHERE booking_id = ?');
				$stmt->execute([$mpesaCodeDb, $bookedPrice, $paymentStatus, $bookingId]);
			} else {
				$stmt = $pdo->prepare('INSERT INTO transactions (booking_id, mpesa_code, amount, status) VALUES (?, ?, ?, ?)');
				$stmt->execute([$bookingId, $mpesaCodeDb, $bookedPrice, $paymentStatus]);
			}

			$stmt = $pdo->prepare('UPDATE bookings SET status = ? WHERE booking_id = ?');
			$stmt->execute([$newBookingStatus, $bookingId]);

			if ($newBookingStatus === 'confirmed') {
				$stmt = $pdo->prepare('UPDATE bookings SET platform_fee = ? WHERE booking_id = ?');
				$stmt->execute([$platformFee, $bookingId]);

				if ($oldBookingStatus !== 'confirmed') {
					$stmt = $pdo->prepare('UPDATE events SET budget_committed = budget_committed + ? WHERE event_id = ? AND planner_id = ?');
					$stmt->execute([$bookedPrice, (int)$booking['event_id'], $_SESSION['user_id']]);
				}
			} else {
				$stmt = $pdo->prepare('UPDATE bookings SET platform_fee = 0 WHERE booking_id = ?');
				$stmt->execute([$bookingId]);

				if ($oldBookingStatus === 'confirmed') {
					$stmt = $pdo->prepare('UPDATE events SET budget_committed = GREATEST(0, budget_committed - ?) WHERE event_id = ? AND planner_id = ?');
					$stmt->execute([$bookedPrice, (int)$booking['event_id'], $_SESSION['user_id']]);
				}
			}

			$pdo->commit();

			audit_log(
				$pdo,
				(int)$_SESSION['user_id'],
				(string)$_SESSION['role'],
				'payment.update',
				'booking',
				(string)$bookingId,
				[
					'payment_status' => $paymentStatus,
					'booking_status' => $newBookingStatus,
					'amount' => (float)$booking['booked_price'],
				]
			);

			$_SESSION['flash_success'] = 'Payment updated successfully.';
			header('Location: mpesa_payments.php');
			exit;
		}
	}

	$stmt = $pdo->prepare(
		"SELECT action, role, metadata_json, created_at
		 FROM audit_logs
		 WHERE target_type = 'booking'
		   AND target_id = ?
		   AND action IN ('payment.update', 'payment.update_failed', 'payment.stk_push_requested', 'payment.stk_push_failed', 'payment.callback_processed', 'payment.callback_failed')
		 ORDER BY created_at DESC
		 LIMIT 20"
	);
	$stmt->execute([(string)$bookingId]);
	$paymentTimeline = $stmt->fetchAll();
} catch (Throwable $e) {
	if ($pdo->inTransaction()) {
		$pdo->rollBack();
	}
	audit_log(
		$pdo,
		isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null,
		$_SESSION['role'] ?? null,
		'payment.update_failed',
		'booking',
		$bookingId ? (string)$bookingId : null,
		['reason' => 'exception']
	);
	$flashError = 'Unable to update payment right now.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="../assets/style.css">
	<title>Planora - M-Pesa Reconciliation</title>
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
		.timeline { margin-top: 18px; border-top: 1px solid #efefef; padding-top: 14px; }
		.timeline-title { font-size: 15px; font-weight: 700; margin-bottom: 10px; }
		.timeline-list { list-style: none; display: flex; flex-direction: column; gap: 8px; }
		.timeline-item { border: 1px solid #ececec; border-radius: 8px; padding: 10px; background: #fafafa; }
		.timeline-item strong { display: inline-block; margin-bottom: 4px; }
		.timeline-meta { font-size: 12px; color: #666; margin-top: 4px; }
		.timeline-empty { font-size: 13px; color: #777; }
		.badge { display: inline-block; padding: 3px 8px; border-radius: 999px; font-size: 11px; font-weight: 700; margin-left: 6px; }
		.badge-paid { background: #e8f9ef; color: #1c7a36; }
		.badge-pending { background: #fff4df; color: #a36500; }
		.badge-failed { background: #ffe8e8; color: #a22b2b; }
		.badge-action-failed { background: #f5e8ff; color: #6a2ea1; }
	</style>
</head>
<body>
<header class="header">
	<div class="brand">PLANORA</div>
	<a class="logout-btn" href="../logout.php">Logout</a>
</header>

<div class="container">
	<a class="link-btn" href="mpesa_payments.php">Back to M-Pesa Payments</a>

	<section class="card">
		<h2 class="title">M-Pesa Reconciliation</h2>

		<?php if ($flashError !== ''): ?>
			<div class="message-error"><?php echo htmlspecialchars($flashError); ?></div>
		<?php endif; ?>

		<?php if ($flashSuccess !== ''): ?>
			<div class="message-error" style="background: #ecfff0; color: #1c7a36; border-color: #c9f0d4;"><?php echo htmlspecialchars($flashSuccess); ?></div>
		<?php endif; ?>

		<?php if (!$darajaConfigured): ?>
			<div class="message-error">Daraja is not configured yet. Set DARAJA_CONSUMER_KEY, DARAJA_CONSUMER_SECRET, DARAJA_SHORTCODE, DARAJA_PASSKEY, and DARAJA_CALLBACK_URL (for example: https://your-domain/admin/mpesa_callback.php).</div>
		<?php endif; ?>

		<div class="message-error" style="background: #eef3ff; color: #2c4ea0; border-color: #d9e4ff;">Optional callback hardening: set DARAJA_CALLBACK_TOKEN and DARAJA_CALLBACK_ALLOWED_IPS to verify callback authenticity and source.</div>

		<p class="meta"><strong>Event:</strong> <?php echo htmlspecialchars((string)$booking['event_title']); ?> (<?php echo htmlspecialchars((string)$booking['event_date']); ?>)</p>
		<p class="meta"><strong>Vendor/Service:</strong> <?php echo htmlspecialchars((string)$booking['business_name']); ?> - <?php echo htmlspecialchars((string)$booking['service_name']); ?></p>
		<p class="meta"><strong>Amount:</strong> KES <?php echo number_format((float)$booking['booked_price'], 2); ?></p>
		<p class="meta"><strong>Booking Status:</strong> <?php echo htmlspecialchars(ucfirst((string)$booking['booking_status'])); ?></p>

		<form method="POST">
			<?php echo csrf_input(); ?>
			<div class="field">
				<label for="phone_number">Customer Phone Number (for STK Push)</label>
				<input class="input" type="text" id="phone_number" name="phone_number" placeholder="e.g. 0712345678">
			</div>

			<button class="btn" type="submit" name="action" value="stk_push">Send STK Push (Daraja)</button>

			<div style="height: 10px;"></div>

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

			<button class="btn" type="submit" name="action" value="save">Save Payment</button>
		</form>

		<div class="timeline">
			<h3 class="timeline-title">Payment Activity Timeline</h3>
			<?php if (empty($paymentTimeline)): ?>
				<div class="timeline-empty">No payment updates yet for this booking.</div>
			<?php else: ?>
				<ul class="timeline-list">
					<?php foreach ($paymentTimeline as $entry): ?>
						<?php
							$meta = [];
							if (!empty($entry['metadata_json'])) {
								$decoded = json_decode((string)$entry['metadata_json'], true);
								if (is_array($decoded)) {
									$meta = $decoded;
								}
							}
							$paymentStatus = strtolower((string)($meta['payment_status'] ?? ''));
							$paymentBadgeClass = '';
							if ($paymentStatus === 'paid') {
								$paymentBadgeClass = 'badge-paid';
							} elseif ($paymentStatus === 'pending') {
								$paymentBadgeClass = 'badge-pending';
							} elseif ($paymentStatus === 'failed') {
								$paymentBadgeClass = 'badge-failed';
							}
							$statusPart = '';
							if (isset($meta['payment_status'])) {
								$statusPart = ' | Payment: ' . ucfirst((string)$meta['payment_status']);
							}
							if (isset($meta['booking_status'])) {
								$statusPart .= ' | Booking: ' . ucfirst((string)$meta['booking_status']);
							}
						?>
						<li class="timeline-item">
							<strong><?php echo htmlspecialchars((string)$entry['action']); ?></strong>
							<?php if ((string)$entry['action'] === 'payment.update_failed'): ?>
								<span class="badge badge-action-failed">Failed Update</span>
							<?php endif; ?>
							<?php if ($paymentBadgeClass !== ''): ?>
								<span class="badge <?php echo $paymentBadgeClass; ?>"><?php echo htmlspecialchars(ucfirst($paymentStatus)); ?></span>
							<?php endif; ?>
							<div class="timeline-meta">
								By role: <?php echo htmlspecialchars((string)($entry['role'] ?? 'unknown')); ?>
								| At: <?php echo htmlspecialchars((string)$entry['created_at']); ?>
								<?php echo htmlspecialchars($statusPart); ?>
							</div>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</div>
	</section>
</div>
</body>
</html>


