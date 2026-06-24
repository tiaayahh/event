<?php
require_once '../includes/auth.php';
checkAuth();
requireRole('planner');

$flashSuccess = $_SESSION['flash_success'] ?? '';
$flashError = $_SESSION['flash_error'] ?? '';
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

$rows = [];
$stats = [
	'total_bookings' => 0,
	'paid_count' => 0,
	'pending_count' => 0,
	'total_committed' => 0.0,
	'total_paid' => 0.0,
];

try {
	$stmt = $pdo->prepare(
		"SELECT b.booking_id,
				b.status AS booking_status,
				b.booked_price,
				b.created_at,
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
		 WHERE e.planner_id = ?
		 ORDER BY b.created_at DESC"
	);
	$stmt->execute([$_SESSION['user_id']]);
	$rows = $stmt->fetchAll();

	$stats['total_bookings'] = count($rows);
	foreach ($rows as $row) {
		$amount = (float)$row['booked_price'];
		$stats['total_committed'] += $amount;

		if (($row['payment_status'] ?? '') === 'paid') {
			$stats['paid_count']++;
			$stats['total_paid'] += $amount;
		} else {
			$stats['pending_count']++;
		}
	}
} catch (Throwable $e) {
	if ($flashError === '') {
		$flashError = 'Unable to load payment history right now.';
	}
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="../assets/style.css">
	<title>Planora - Payment History</title>
	<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
	<style>
		* { box-sizing: border-box; margin: 0; padding: 0; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; }
		body { background: #F5F5F5; color: #2D2D2D; min-height: 100vh; }
		.header { background: #6C63FF; color: #fff; padding: 14px 24px; display: flex; justify-content: space-between; align-items: center; }
		.brand { font-size: 22px; font-weight: 700; }
		.logout-btn { background: rgba(255,255,255,0.22); color: #fff; text-decoration: none; border-radius: 6px; padding: 7px 14px; font-size: 13px; }
		.container { max-width: 1150px; margin: 0 auto; padding: 20px; }
		.links { display: flex; gap: 10px; margin-bottom: 14px; flex-wrap: wrap; }
		.link-btn { text-decoration: none; background: #ece9ff; color: #3b3496; border-radius: 6px; padding: 8px 12px; font-size: 13px; }
		.message { border-radius: 6px; padding: 10px 12px; margin-bottom: 14px; font-size: 13px; }
		.message-success { background: #ecfff0; color: #1c7a36; border: 1px solid #c9f0d4; }
		.message-error { background: #ffecec; color: #9d2020; border: 1px solid #f6caca; }
		.stats { display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; margin-bottom: 18px; }
		.stat { background: #fff; border-radius: 10px; border: 1px solid #ededed; padding: 14px; }
		.stat-value { font-size: 23px; font-weight: 700; color: #6C63FF; }
		.stat-label { font-size: 12px; color: #777; margin-top: 4px; }
		.card { background: #fff; border-radius: 8px; padding: 18px; box-shadow: 0 1px 3px rgba(0,0,0,.05); }
		.title { font-size: 18px; font-weight: 700; margin-bottom: 12px; }
		.table-wrap { overflow-x: auto; }
		table { width: 100%; border-collapse: collapse; }
		th, td { padding: 11px 10px; text-align: left; border-bottom: 1px solid #efefef; font-size: 13px; }
		th { background: #fafafa; color: #555; font-weight: 700; }
		.badge { display: inline-block; padding: 3px 9px; border-radius: 999px; font-size: 11px; font-weight: 700; }
		.badge-paid { background: #e8f9ef; color: #1c7a36; }
		.badge-pending { background: #fff4df; color: #a36500; }
		.badge-failed { background: #ffe8e8; color: #a22b2b; }
		.btn { text-decoration: none; background: #6C63FF; color: #fff; border-radius: 6px; padding: 7px 10px; font-size: 12px; display: inline-block; }
		.empty { color: #777; font-size: 14px; padding: 10px 0; }
		@media (max-width: 960px) { .stats { grid-template-columns: 1fr 1fr; } }
		@media (max-width: 560px) { .stats { grid-template-columns: 1fr; } }
	</style>
</head>
<body>
<header class="header">
	<div class="brand">PLANORA</div>
	<a class="logout-btn" href="../logout.php">Logout</a>
</header>

<div class="container">
	<div class="links">
		<a class="link-btn" href="dashboard.php"><i class="fa-solid fa-house"></i> Dashboard</a>
		<a class="link-btn" href="create_event.php"><i class="fa-solid fa-calendar-days"></i> Events</a>
		<a class="link-btn" href="browse_vendors.php"><i class="fa-solid fa-shop"></i> Vendors</a>
	</div>

	<?php if ($flashSuccess !== ''): ?><div class="message message-success"><?php echo htmlspecialchars($flashSuccess); ?></div><?php endif; ?>
	<?php if ($flashError !== ''): ?><div class="message message-error"><?php echo htmlspecialchars($flashError); ?></div><?php endif; ?>

	<section class="stats">
		<div class="stat"><div class="stat-value"><?php echo (int)$stats['total_bookings']; ?></div><div class="stat-label">Total Bookings</div></div>
		<div class="stat"><div class="stat-value"><?php echo (int)$stats['paid_count']; ?></div><div class="stat-label">Paid</div></div>
		<div class="stat"><div class="stat-value"><?php echo (int)$stats['pending_count']; ?></div><div class="stat-label">Pending/Unpaid</div></div>
		<div class="stat"><div class="stat-value">KES <?php echo number_format((float)$stats['total_paid'], 2); ?></div><div class="stat-label">Paid Amount</div></div>
	</section>

	<section class="card">
		<h2 class="title">Payment History</h2>
		<?php if (empty($rows)): ?>
			<div class="empty">No bookings found yet.</div>
		<?php else: ?>
			<div class="table-wrap">
				<table>
					<thead>
						<tr>
							<th>Event</th>
							<th>Vendor / Service</th>
							<th>Amount</th>
							<th>Booking</th>
							<th>Payment</th>
							<th>M-Pesa Code</th>
							<th>Action</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ($rows as $row): ?>
							<?php
								$paymentStatus = strtolower((string)($row['payment_status'] ?? 'pending'));
								$paymentClass = $paymentStatus === 'paid' ? 'badge-paid' : ($paymentStatus === 'failed' ? 'badge-failed' : 'badge-pending');
							?>
							<tr>
								<td><?php echo htmlspecialchars($row['event_title']); ?><br><small><?php echo htmlspecialchars((string)$row['event_date']); ?></small></td>
								<td><?php echo htmlspecialchars($row['business_name']); ?><br><small><?php echo htmlspecialchars($row['service_name']); ?></small></td>
								<td>KES <?php echo number_format((float)$row['booked_price'], 2); ?></td>
								<td><span class="badge <?php echo strtolower((string)$row['booking_status']) === 'confirmed' ? 'badge-paid' : 'badge-pending'; ?>"><?php echo htmlspecialchars(ucfirst((string)$row['booking_status'])); ?></span></td>
								<td><span class="badge <?php echo $paymentClass; ?>"><?php echo htmlspecialchars(ucfirst($paymentStatus)); ?></span></td>
								<td><?php echo htmlspecialchars((string)($row['mpesa_code'] ?? '-')); ?></td>
								<td><a class="btn" href="initiate_payment.php?booking_id=<?php echo (int)$row['booking_id']; ?>">Update Payment</a></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		<?php endif; ?>
	</section>
</div>
</body>
</html>


