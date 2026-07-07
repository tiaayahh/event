<?php
require_once '../includes/auth.php';
checkAuth();
requireRole('vendor');

$fullName = $_SESSION['full_name'] ?? 'Vendor';
$bookings = [];
$newPendingCount = 0;
$flashSuccess = (string)($_SESSION['flash_success'] ?? '');
$flashError = (string)($_SESSION['flash_error'] ?? '');
unset($_SESSION['flash_success'], $_SESSION['flash_error']);
$statusFilter = strtolower(trim((string)($_GET['status'] ?? 'all')));
if (!in_array($statusFilter, ['all', 'pending', 'confirmed', 'cancelled'], true)) {
    $statusFilter = 'all';
}
$isMarketOperator = false;
$marketEvents = [];

function ensureBookingDisplaySchema(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }

    $stmt = $pdo->query("SHOW COLUMNS FROM events LIKE 'venue'");
    if (!$stmt->fetch()) {
        $pdo->exec("ALTER TABLE events ADD COLUMN venue VARCHAR(190) DEFAULT NULL AFTER event_date");
    }

    $stmt = $pdo->query("SHOW COLUMNS FROM bookings LIKE 'booth_number'");
    if (!$stmt->fetch()) {
        $pdo->exec("ALTER TABLE bookings ADD COLUMN booth_number VARCHAR(64) DEFAULT NULL AFTER platform_fee");
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

function resolveEventImageUrl(?string $rawUrl): string
{
    $url = trim((string)$rawUrl);
    if ($url === '') {
        return '';
    }

    if (preg_match('/^(https?:)?\/\//i', $url) === 1 || stripos($url, 'data:') === 0) {
        return $url;
    }

    return '../' . ltrim($url, '/');
}

try {
    ensureVendorTypeSchema($pdo);

    $stmt = $pdo->prepare("SELECT vendor_id, COALESCE(vendor_type, 'service_provider') AS vendor_type FROM vendors WHERE user_id = ? LIMIT 1");
    $stmt->execute([$_SESSION['user_id']]);
    $vendor = $stmt->fetch();

    if ($vendor) {
        $vendorId = (int)$vendor['vendor_id'];
        $isMarketOperator = ((string)($vendor['vendor_type'] ?? 'service_provider')) === 'market_operator';

        ensureBookingDisplaySchema($pdo);

        if (!$isMarketOperator && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['booking_action'], $_POST['booking_id'])) {
            csrf_require_valid_post_token();

            $bookingId = filter_input(INPUT_POST, 'booking_id', FILTER_VALIDATE_INT);
            $bookingAction = strtolower(trim((string)($_POST['booking_action'] ?? '')));

            if (!$bookingId || !in_array($bookingAction, ['accept', 'decline'], true)) {
                $_SESSION['flash_error'] = 'Invalid booking action.';
                header('Location: bookings.php?status=' . urlencode($statusFilter));
                exit;
            }

            $stmt = $pdo->prepare(
                "SELECT b.booking_id, b.status, b.event_id, e.planner_id, e.title AS event_title, s.name AS service_name
                 FROM bookings b
                 JOIN services s ON s.service_id = b.service_id
                 JOIN events e ON e.event_id = b.event_id
                 WHERE b.booking_id = ? AND s.vendor_id = ?
                 LIMIT 1"
            );
            $stmt->execute([$bookingId, $vendorId]);
            $targetBooking = $stmt->fetch();

            if (!$targetBooking) {
                $_SESSION['flash_error'] = 'Booking not found.';
                header('Location: bookings.php?status=' . urlencode($statusFilter));
                exit;
            }

            $currentStatus = strtolower((string)($targetBooking['status'] ?? 'pending'));
            if ($bookingAction === 'accept' && $currentStatus !== 'pending') {
                $_SESSION['flash_error'] = 'Only pending bookings can be accepted.';
                header('Location: bookings.php?status=' . urlencode($statusFilter));
                exit;
            }
            if ($bookingAction === 'decline' && !in_array($currentStatus, ['pending', 'confirmed'], true)) {
                $_SESSION['flash_error'] = 'Only pending or confirmed bookings can be declined.';
                header('Location: bookings.php?status=' . urlencode($statusFilter));
                exit;
            }

            $newStatus = $bookingAction === 'accept' ? 'confirmed' : 'cancelled';

            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare('UPDATE bookings SET status = ? WHERE booking_id = ?');
                $stmt->execute([$newStatus, $bookingId]);

                // Keep transaction status consistent after vendor decision.
                $stmt = $pdo->prepare('SELECT booking_id FROM transactions WHERE booking_id = ? LIMIT 1');
                $stmt->execute([$bookingId]);
                $hasTransaction = (bool)$stmt->fetch();
                if ($hasTransaction) {
                    $stmt = $pdo->prepare('UPDATE transactions SET status = ? WHERE booking_id = ?');
                    $stmt->execute([$newStatus === 'cancelled' ? 'failed' : 'pending', $bookingId]);
                }

                $plannerId = (int)($targetBooking['planner_id'] ?? 0);
                if ($plannerId > 0) {
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

                    $msgText = $newStatus === 'confirmed'
                        ? ('Booking accepted for service "' . (string)$targetBooking['service_name'] . '" in event "' . (string)$targetBooking['event_title'] . '".')
                        : ('Booking declined for service "' . (string)$targetBooking['service_name'] . '" in event "' . (string)$targetBooking['event_title'] . '".');

                    $stmt = $pdo->prepare('INSERT INTO messages (planner_user_id, vendor_user_id, sender_role, message_text, is_read) VALUES (?, ?, ?, ?, 0)');
                    $stmt->execute([$plannerId, (int)$_SESSION['user_id'], 'vendor', $msgText]);
                }

                audit_log(
                    $pdo,
                    (int)$_SESSION['user_id'],
                    (string)$_SESSION['role'],
                    $newStatus === 'confirmed' ? 'booking.accept' : 'booking.decline',
                    'booking',
                    (string)$bookingId,
                    ['vendor_id' => $vendorId, 'new_status' => $newStatus]
                );

                $pdo->commit();
                $_SESSION['flash_success'] = $newStatus === 'confirmed' ? 'Booking accepted successfully.' : 'Booking declined successfully.';
            } catch (Throwable $txe) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $_SESSION['flash_error'] = 'Could not update booking right now. Please try again.';
            }

            header('Location: bookings.php?status=' . urlencode($statusFilter));
            exit;
        }

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS vendor_notification_state (
                vendor_id INT NOT NULL PRIMARY KEY,
                last_seen_pending_bookings_at DATETIME NULL,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                CONSTRAINT fk_vendor_notification_state_vendor FOREIGN KEY (vendor_id) REFERENCES vendors(vendor_id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        $stmt = $pdo->prepare(
            'INSERT INTO vendor_notification_state (vendor_id, last_seen_pending_bookings_at) VALUES (?, NOW())
             ON DUPLICATE KEY UPDATE last_seen_pending_bookings_at = NOW()'
        );
        $stmt->execute([$vendorId]);

        // Opening bookings marks current pending items as seen, so only future pending items are "new".
        $stmt = $pdo->prepare(
            "SELECT COUNT(*)
             FROM bookings b
             JOIN services s ON b.service_id = s.service_id
             JOIN vendor_notification_state vns ON vns.vendor_id = s.vendor_id
             WHERE s.vendor_id = ? AND b.status = 'pending' AND b.created_at > vns.last_seen_pending_bookings_at"
        );
        $stmt->execute([$vendorId]);
        $newPendingCount = (int)$stmt->fetchColumn();

        if ($isMarketOperator) {
            $stmt = $pdo->prepare(
                "SELECT
                    e.event_id,
                    e.title,
                    e.event_date,
                    COALESCE(e.venue, '') AS event_venue,
                    COALESCE(e.city, '') AS event_city,
                    COALESCE(e.stall_price, 0) AS event_fee,
                    COALESCE(sr.payment_status,
                        CASE LOWER(COALESCE(sr.status, 'requested'))
                            WHEN 'paid' THEN 'paid'
                            WHEN 'failed' THEN 'failed'
                            WHEN 'cancelled' THEN 'cancelled'
                            ELSE 'pending'
                        END
                    ) AS booking_status,
                    COALESCE(sr.checkout_request_id, '') AS checkout_request_id,
                    COALESCE(sr.mpesa_code, '') AS mpesa_code,
                    sr.updated_at
                 FROM events e
                 LEFT JOIN stall_rentals sr
                    ON sr.event_id = e.event_id
                   AND sr.vendor_user_id = ?
                 WHERE e.archived_at IS NULL
                   AND e.event_date >= CURDATE()
                   AND LOWER(COALESCE(e.category, '')) LIKE '%market%'
                 ORDER BY e.event_date ASC"
            );
            $stmt->execute([(int)$_SESSION['user_id']]);
            $marketEvents = $stmt->fetchAll();
        } else {
            $sql = "SELECT b.booking_id, b.status AS booking_status, b.booked_price, b.platform_fee, b.booth_number,
                        COALESCE(e.image_url, '') AS image_url,
                        e.title AS event_title, e.event_date, COALESCE(e.venue, '') AS event_venue, s.name AS service_name,
                        t.mpesa_code, COALESCE(t.status, 'pending') AS payment_status, COALESCE(t.amount, 0) AS paid_amount
                    FROM bookings b
                    JOIN events e ON b.event_id = e.event_id
                    JOIN services s ON b.service_id = s.service_id
                    LEFT JOIN transactions t ON b.booking_id = t.booking_id
                    WHERE s.vendor_id = ?";
            $params = [$vendorId];

            if ($statusFilter === 'pending' || $statusFilter === 'confirmed' || $statusFilter === 'cancelled') {
                $sql .= " AND b.status = ?";
                $params[] = $statusFilter;
            } else {
                $sql .= " AND b.status IN ('pending', 'confirmed', 'cancelled')";
            }

            $sql .= " ORDER BY e.event_date ASC";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $bookings = $stmt->fetchAll();
        }
    }
} catch (Throwable $e) {
    error_log('vendor/bookings.php error: ' . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="../assets/style.css">
    <title>Planora - Bookings</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
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
        .brand-logo { font-size: 22px; font-weight: 700; }
        .logout-btn {
            background: rgba(255,255,255,0.25);
            color: white;
            padding: 6px 14px;
            border-radius: 4px;
            text-decoration: none;
            font-size: 13px;
        }
        .container {
            max-width: 800px;
            margin: 0 auto;
            padding: 20px;
        }
        .card {
            background: white;
            border-radius: 6px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
        }
        .card-title {
            font-size: 18px;
            font-weight: 700;
            margin-bottom: 15px;
        }
        .booking-row {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            padding: 12px 0;
            border-bottom: 1px solid #eee;
            gap: 14px;
        }
        .booking-row:last-child { border-bottom: none; }
        .status-booking {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 999px;
            font-size: 11px;
            font-weight: 700;
            margin-left: 6px;
            vertical-align: middle;
        }
        .status-booking.confirmed { background: #e8f9ef; color: #1c7a36; }
        .status-booking.pending { background: #fff4df; color: #a36500; }
        .status-booking.cancelled { background: #ffecec; color: #9d2020; }
        .status-paid { color: #2ecc71; }
        .status-pending { color: #f39c12; }
        .status-partial { color: #d18a00; }
        .status-failed { color: #e74c3c; }
        .message { border-radius: 6px; padding: 10px 12px; margin-bottom: 14px; font-size: 13px; }
        .message-success { background: #ecfff0; color: #1c7a36; border: 1px solid #c9f0d4; }
        .message-error { background: #ffecec; color: #9d2020; border: 1px solid #f6caca; }
        .booking-main { flex: 1; }
        .booking-main-row {
            display: flex;
            align-items: flex-start;
            gap: 10px;
        }
        .booking-thumb {
            width: 62px;
            height: 62px;
            border-radius: 8px;
            background: linear-gradient(135deg, #ece9ff, #d6d0ff);
            background-size: cover;
            background-position: center;
            flex-shrink: 0;
        }
        .booking-meta { color: #666; font-size: 12px; margin-top: 4px; }
        .booking-actions { margin-top: 8px; display: flex; gap: 6px; flex-wrap: wrap; }
        .action-btn { border: none; border-radius: 4px; padding: 6px 9px; font-size: 12px; cursor: pointer; text-decoration: none; display: inline-block; }
        .accept-btn { background: #ecfff0; color: #1c7a36; border: 1px solid #bfeac9; }
        .decline-btn { background: #ffecec; color: #9d2020; border: 1px solid #f6caca; }
        .neutral-btn { background: #ece9ff; color: #3f379f; border: 1px solid #c9c2ff; }
        .action-form { display: inline; }
        .filter-row {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            margin-bottom: 12px;
        }
        .action-row {
            margin-bottom: 12px;
        }
        .action-link {
            text-decoration: none;
            font-size: 12px;
            font-weight: 700;
            color: #3f379f;
            border: 1px solid #c9c2ff;
            background: #ece9ff;
            padding: 6px 10px;
            border-radius: 999px;
            display: inline-block;
        }
        .filter-link {
            text-decoration: none;
            font-size: 12px;
            font-weight: 600;
            color: #4b4b4b;
            border: 1px solid #e5e5e5;
            background: #fafafa;
            padding: 6px 10px;
            border-radius: 999px;
        }
        .filter-link.active {
            background: #ece9ff;
            border-color: #c9c2ff;
            color: #3f379f;
        }
        .bottom-nav {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            background: #2D2D2D;
            display: flex;
            justify-content: space-around;
            align-items: center;
            height: 65px;
            z-index: 999;
        }
        .nav-link {
            color: white;
            text-decoration: none;
            font-size: 12px;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 4px;
            opacity: 0.8;
            flex: 1;
            position: relative;
        }
        .nav-link i { font-size: 18px; }
        .nav-link.active { opacity: 1; background: rgba(255,255,255,0.08); }
        .badge-unread { display: inline-block; min-width: 18px; height: 18px; border-radius: 999px; background: #e74c3c; color: #fff; font-size: 11px; font-weight: 700; line-height: 18px; text-align: center; padding: 0 5px; position: absolute; top: 8px; right: 12px; }
    </style>
</head>
<body>
    <header class="header">
        <div class="brand-logo">PLANORA</div>
        <a href="../logout.php" class="logout-btn">Logout</a>
    </header>

    <div class="container">
        <div class="card">
            <h2 class="card-title">Bookings</h2>
            <?php if ($flashSuccess !== ''): ?><div class="message message-success"><?php echo htmlspecialchars($flashSuccess); ?></div><?php endif; ?>
            <?php if ($flashError !== ''): ?><div class="message message-error"><?php echo htmlspecialchars($flashError); ?></div><?php endif; ?>
            <?php if ($isMarketOperator): ?>
                <div class="action-row">
                    <a class="action-link" href="stall_registration.php">Book Event &amp; Pay Fee</a>
                </div>

                <?php if (empty($marketEvents)): ?>
                    <p>No upcoming market events available right now.</p>
                <?php else: ?>
                    <?php foreach ($marketEvents as $event): ?>
                        <?php
                            $status = strtolower((string)($event['booking_status'] ?? 'pending'));
                            if (!in_array($status, ['paid', 'failed', 'cancelled', 'pending'], true)) {
                                $status = 'pending';
                            }
                        ?>
                        <div class="booking-row">
                            <div class="booking-main">
                                <strong><?php echo htmlspecialchars((string)$event['title']); ?></strong>
                                <div class="booking-meta">Date: <?php echo htmlspecialchars((string)$event['event_date']); ?></div>
                                <div class="booking-meta">Venue: <?php echo htmlspecialchars((string)(($event['event_venue'] ?? '') !== '' ? $event['event_venue'] : 'Not specified')); ?><?php if ((string)($event['event_city'] ?? '') !== ''): ?>, <?php echo htmlspecialchars((string)$event['event_city']); ?><?php endif; ?></div>
                                <div class="booking-meta">Event Fee: KES <?php echo number_format((float)($event['event_fee'] ?? 0), 2); ?></div>
                                <div class="booking-actions">
                                    <a class="action-btn neutral-btn" href="stall_registration.php?event_id=<?php echo (int)$event['event_id']; ?>#event-<?php echo (int)$event['event_id']; ?>">Open Booking &amp; Payment</a>
                                    <?php if ((string)($event['checkout_request_id'] ?? '') !== ''): ?>
                                        <span class="booking-meta">Checkout ID: <?php echo htmlspecialchars((string)$event['checkout_request_id']); ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div>
                                <span class="status-booking <?php echo htmlspecialchars($status); ?>"><?php echo htmlspecialchars(ucfirst($status)); ?></span>
                                <br><small style="color:#777;">Receipt: <?php echo htmlspecialchars((string)((($event['mpesa_code'] ?? '') !== '') ? $event['mpesa_code'] : 'Not provided')); ?></small>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            <?php else: ?>
                <div class="action-row">
                    <a class="action-link" href="pay_fee.php">Pay Vendor Selling Fee</a>
                </div>
                <div class="filter-row">
                    <a class="filter-link <?php echo $statusFilter === 'all' ? 'active' : ''; ?>" href="bookings.php?status=all">All</a>
                    <a class="filter-link <?php echo $statusFilter === 'pending' ? 'active' : ''; ?>" href="bookings.php?status=pending">Pending</a>
                    <a class="filter-link <?php echo $statusFilter === 'confirmed' ? 'active' : ''; ?>" href="bookings.php?status=confirmed">Confirmed</a>
                    <a class="filter-link <?php echo $statusFilter === 'cancelled' ? 'active' : ''; ?>" href="bookings.php?status=cancelled">Cancelled</a>
                </div>
                <?php if (empty($bookings)): ?>
                    <?php if ($statusFilter === 'pending'): ?>
                        <p>No pending bookings yet.</p>
                    <?php elseif ($statusFilter === 'confirmed'): ?>
                        <p>No confirmed bookings yet.</p>
                    <?php elseif ($statusFilter === 'cancelled'): ?>
                        <p>No cancelled bookings yet.</p>
                    <?php else: ?>
                        <p>No bookings yet.</p>
                    <?php endif; ?>
                <?php else: ?>
                    <?php foreach ($bookings as $b): ?>
                        <?php $bookingImage = resolveEventImageUrl((string)($b['image_url'] ?? '')); ?>
                        <div class="booking-row">
                            <div class="booking-main">
                                <div class="booking-main-row">
                                    <div class="booking-thumb"<?php if ($bookingImage !== ''): ?> style="background-image:url('<?php echo htmlspecialchars($bookingImage); ?>');"<?php endif; ?>></div>
                                    <div>
                                        <strong><?php echo htmlspecialchars($b['service_name']); ?></strong> &mdash;
                                        <?php echo htmlspecialchars($b['event_title']); ?> (<?php echo $b['event_date']; ?>)
                                        <span class="status-booking <?php echo htmlspecialchars((string)$b['booking_status']); ?>"><?php echo htmlspecialchars(ucfirst((string)$b['booking_status'])); ?></span>
                                        <div class="booking-meta">Venue: <?php echo htmlspecialchars((string)($b['event_venue'] !== '' ? $b['event_venue'] : 'Not specified')); ?></div>
                                        <div class="booking-meta">Booth Number: <?php echo htmlspecialchars((string)(($b['booth_number'] ?? '') !== '' ? $b['booth_number'] : 'Not assigned')); ?></div>
                                        <div class="booking-actions">
                                            <?php if (strtolower((string)$b['booking_status']) === 'pending'): ?>
                                                <form method="POST" class="action-form">
                                                    <?php echo csrf_input(); ?>
                                                    <input type="hidden" name="booking_action" value="accept">
                                                    <input type="hidden" name="booking_id" value="<?php echo (int)$b['booking_id']; ?>">
                                                    <button class="action-btn accept-btn" type="submit">Accept Booking</button>
                                                </form>
                                            <?php endif; ?>
                                            <?php if (in_array(strtolower((string)$b['booking_status']), ['pending', 'confirmed'], true)): ?>
                                                <form method="POST" class="action-form" onsubmit="return confirm('Decline this booking?');">
                                                    <?php echo csrf_input(); ?>
                                                    <input type="hidden" name="booking_action" value="decline">
                                                    <input type="hidden" name="booking_id" value="<?php echo (int)$b['booking_id']; ?>">
                                                    <button class="action-btn decline-btn" type="submit">Decline Booking</button>
                                                </form>
                                            <?php endif; ?>
                                            <a class="action-btn neutral-btn" href="event_details.php?booking_id=<?php echo (int)$b['booking_id']; ?>">View Event Details</a>
                                            <a class="action-btn neutral-btn" href="download_agreement.php?booking_id=<?php echo (int)$b['booking_id']; ?>">Download Agreement</a>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div>
                                <?php
                                $gross = (float)$b['booked_price'];
                                $fee = (float)($b['platform_fee'] ?? 0);
                                $net = $gross - $fee;
                                $rawPaymentStatus = strtolower((string)($b['payment_status'] ?? 'pending'));
                                $paidAmount = (float)($b['paid_amount'] ?? 0);
                                $displayPaymentStatus = 'Pending';
                                $paymentClass = 'status-pending';
                                if ($rawPaymentStatus === 'paid' && $paidAmount > 0 && $paidAmount < $gross) {
                                    $displayPaymentStatus = 'Partially Paid';
                                    $paymentClass = 'status-partial';
                                } elseif ($rawPaymentStatus === 'paid') {
                                    $displayPaymentStatus = 'Paid';
                                    $paymentClass = 'status-paid';
                                } elseif ($rawPaymentStatus === 'failed') {
                                    $displayPaymentStatus = 'Failed';
                                    $paymentClass = 'status-failed';
                                }
                                ?>
                                <span>KES <?php echo number_format($net, 2); ?> (amount payable)</span>
                                <br><small style="color:#777;">Fee: KES <?php echo number_format($fee, 2); ?></small>
                                <br><small class="<?php echo $paymentClass; ?>">Payment: <?php echo htmlspecialchars($displayPaymentStatus); ?></small>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

    <nav class="bottom-nav">
        <a href="dashboard.php" class="nav-link"><i class="fa-solid fa-house"></i><span>Home</span></a>
        <a href="services.php" class="nav-link"><i class="fa-solid fa-bell-concierge"></i><span>Services</span></a>
        <a href="bookings.php" class="nav-link active"><i class="fa-solid fa-book-open"></i><span>Bookings</span><?php if ($newPendingCount > 0): ?><span class="badge-unread"><?php echo $newPendingCount; ?></span><?php endif; ?></a>
        <a href="schedule.php" class="nav-link"><i class="fa-solid fa-calendar-days"></i><span>Schedule</span></a>
        <a href="profile.php" class="nav-link"><i class="fa-solid fa-user"></i><span>Profile</span></a>
    </nav>
</body>
</html>

