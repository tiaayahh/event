<?php
require_once '../includes/auth.php';
checkAuth();
requireRole('planner');

$flashError = '';
$vendors = [];
$showUnreadOnly = isset($_GET['unread']) && $_GET['unread'] === '1';
$selectedVendorUserId = filter_input(INPUT_GET, 'vendor_user_id', FILTER_VALIDATE_INT);
$conversation = [];

try {
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

    $stmt = $pdo->query("SHOW COLUMNS FROM messages LIKE 'is_read'");
    if (!$stmt->fetch()) {
        try {
            $pdo->exec("ALTER TABLE messages ADD COLUMN is_read TINYINT(1) NOT NULL DEFAULT 0");
        } catch (Throwable $e) {
            // Another request may have added this column first.
            if (stripos($e->getMessage(), 'Duplicate column name') === false) {
                throw $e;
            }
        }
    }

    $stmt = $pdo->prepare(
        "SELECT v.user_id AS vendor_user_id,
                v.business_name,
                u.full_name,
                MAX(m.created_at) AS last_message_at,
                SUM(CASE WHEN m.sender_role = 'vendor' AND m.is_read = 0 THEN 1 ELSE 0 END) AS unread_count,
                (
                    SELECT m2.message_text
                    FROM messages m2
                    WHERE m2.planner_user_id = ? AND m2.vendor_user_id = v.user_id
                    ORDER BY m2.created_at DESC, m2.message_id DESC
                    LIMIT 1
                ) AS last_message
         FROM (
                SELECT DISTINCT v2.user_id AS vendor_user_id
                FROM bookings b
                JOIN events e ON b.event_id = e.event_id
                JOIN services s ON b.service_id = s.service_id
                JOIN vendors v2 ON s.vendor_id = v2.vendor_id
                WHERE e.planner_id = ?

                UNION

                SELECT DISTINCT m3.vendor_user_id
                FROM messages m3
                WHERE m3.planner_user_id = ?
         ) cv
         JOIN vendors v ON v.user_id = cv.vendor_user_id
         JOIN users u ON v.user_id = u.user_id
         LEFT JOIN messages m
                ON m.planner_user_id = ?
               AND m.vendor_user_id = cv.vendor_user_id
         GROUP BY v.user_id, v.business_name, u.full_name
           ORDER BY CASE WHEN MAX(m.created_at) IS NULL THEN 1 ELSE 0 END,
                MAX(m.created_at) DESC,
                v.business_name ASC"
    );
    $stmt->execute([$_SESSION['user_id'], $_SESSION['user_id'], $_SESSION['user_id'], $_SESSION['user_id']]);
    $vendors = $stmt->fetchAll();

    if ($showUnreadOnly) {
        $vendors = array_values(array_filter($vendors, static function ($vendor) {
            return (int)($vendor['unread_count'] ?? 0) > 0;
        }));
    }

    $allowedVendorIds = array_map(static function ($v) {
        return (int)$v['vendor_user_id'];
    }, $vendors);

    if (!$selectedVendorUserId && !empty($vendors)) {
        foreach ($vendors as $vendor) {
            if ((int)($vendor['unread_count'] ?? 0) > 0) {
                $selectedVendorUserId = (int)$vendor['vendor_user_id'];
                break;
            }
        }

        if (!$selectedVendorUserId) {
            $selectedVendorUserId = (int)$vendors[0]['vendor_user_id'];
        }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $targetVendor = filter_input(INPUT_POST, 'vendor_user_id', FILTER_VALIDATE_INT);
        $messageText = trim($_POST['message_text'] ?? '');

        if (!$targetVendor || !in_array((int)$targetVendor, $allowedVendorIds, true)) {
            $flashError = 'Select a valid vendor conversation.';
        } elseif ($messageText === '') {
            $flashError = 'Message cannot be empty.';
        } else {
            $stmt = $pdo->prepare('INSERT INTO messages (planner_user_id, vendor_user_id, sender_role, message_text, is_read) VALUES (?, ?, ?, ?, 0)');
            $stmt->execute([$_SESSION['user_id'], $targetVendor, 'planner', $messageText]);

            audit_log(
                $pdo,
                (int)$_SESSION['user_id'],
                (string)$_SESSION['role'],
                'message.send',
                'conversation',
                (string)$targetVendor,
                ['recipient_role' => 'vendor']
            );

            header('Location: messages.php?vendor_user_id=' . (int)$targetVendor);
            exit;
        }

        if ($targetVendor) {
            $selectedVendorUserId = (int)$targetVendor;
        }
    }

    if ($selectedVendorUserId && in_array((int)$selectedVendorUserId, $allowedVendorIds, true)) {
        $stmt = $pdo->prepare(
            "UPDATE messages
             SET is_read = 1
             WHERE planner_user_id = ? AND vendor_user_id = ? AND sender_role = 'vendor' AND is_read = 0"
        );
        $stmt->execute([$_SESSION['user_id'], $selectedVendorUserId]);

        $stmt = $pdo->prepare(
            "SELECT sender_role, message_text, created_at
             FROM messages
             WHERE planner_user_id = ? AND vendor_user_id = ?
             ORDER BY created_at ASC, message_id ASC"
        );
        $stmt->execute([$_SESSION['user_id'], $selectedVendorUserId]);
        $conversation = $stmt->fetchAll();
    }
} catch (Throwable $e) {
    error_log('admin/messages.php error: ' . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="../assets/style.css">
    <title>Planora - Planner Messages</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; }
        body { background: #F5F5F5; color: #2D2D2D; min-height: 100vh; }
        .header { background: #6C63FF; color: #fff; padding: 14px 24px; display: flex; justify-content: space-between; align-items: center; }
        .brand { font-size: 22px; font-weight: 700; }
        .logout-btn { background: rgba(255,255,255,0.22); color: #fff; text-decoration: none; border-radius: 6px; padding: 7px 14px; font-size: 13px; }
        .container { max-width: 1100px; margin: 0 auto; padding: 20px; }
        .top-links { display: flex; gap: 10px; margin-bottom: 14px; }
        .top-link { text-decoration: none; background: #ece9ff; color: #3b3496; border-radius: 6px; padding: 8px 12px; font-size: 13px; }
        .layout { display: grid; grid-template-columns: 290px 1fr; gap: 14px; }
        .card { background: #fff; border-radius: 8px; padding: 16px; box-shadow: 0 1px 3px rgba(0,0,0,.05); }
        .title { font-size: 16px; font-weight: 700; margin-bottom: 10px; }
        .vendor-list { display: flex; flex-direction: column; gap: 8px; max-height: 68vh; overflow: auto; }
        .vendor-item { display: block; text-decoration: none; border: 1px solid #ececec; border-radius: 8px; padding: 10px; color: #2D2D2D; }
        .vendor-item.active { border-color: #6C63FF; background: #f7f6ff; }
        .vendor-row { display: flex; align-items: center; justify-content: space-between; gap: 8px; }
        .badge-unread { display: inline-block; min-width: 18px; height: 18px; border-radius: 999px; background: #e74c3c; color: #fff; font-size: 11px; font-weight: 700; line-height: 18px; text-align: center; padding: 0 5px; }
        .meta { font-size: 12px; color: #666; margin-top: 2px; }
        .preview { font-size: 12px; color: #777; margin-top: 6px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .preview-time { color: #999; margin-top: 4px; font-size: 11px; }
        .error { background: #ffecec; color: #9d2020; border: 1px solid #f6caca; border-radius: 6px; padding: 10px 12px; margin-bottom: 10px; font-size: 13px; }
        .messages { border: 1px solid #ececec; border-radius: 8px; padding: 10px; max-height: 52vh; overflow: auto; background: #fafafa; }
        .message { margin-bottom: 10px; display: flex; }
        .message.mine { justify-content: flex-end; }
        .bubble { max-width: 78%; border-radius: 10px; padding: 9px 11px; font-size: 13px; line-height: 1.4; }
        .mine .bubble { background: #6C63FF; color: #fff; }
        .theirs .bubble { background: #fff; border: 1px solid #ececec; }
        .time { display: block; font-size: 11px; margin-top: 4px; opacity: .8; }
        .composer { margin-top: 10px; display: grid; grid-template-columns: 1fr auto; gap: 8px; }
        .input { width: 100%; border: 1px solid #d6d6d6; border-radius: 8px; padding: 10px; font-size: 14px; }
        .btn { background: #6C63FF; color: #fff; border: none; border-radius: 8px; padding: 10px 14px; font-size: 13px; cursor: pointer; }
        .empty { color: #777; font-size: 14px; padding: 10px 0; }
        .hint { background: #eef3ff; color: #2c4ea0; border: 1px solid #d9e4ff; border-radius: 6px; padding: 9px 11px; margin-bottom: 10px; font-size: 12px; }
        @media (max-width: 900px) { .layout { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
<header class="header">
    <div class="brand">PLANORA</div>
    <a class="logout-btn" href="../logout.php">Logout</a>
</header>

<div class="container">
    <div class="top-links">
        <a class="top-link" href="dashboard.php"><i class="fa-solid fa-house"></i> Dashboard</a>
        <a class="top-link" href="browse_vendors.php"><i class="fa-solid fa-shop"></i> Browse Vendors</a>
    </div>

    <?php if ($flashError !== ''): ?>
        <div class="error"><?php echo htmlspecialchars($flashError); ?></div>
    <?php endif; ?>

    <?php if ($showUnreadOnly): ?>
        <div class="hint"><i class="fa-solid fa-filter"></i> Showing unread conversations first. <a href="messages.php">View all</a></div>
    <?php endif; ?>

    <div class="layout">
        <section class="card">
            <h2 class="title">Vendors</h2>
            <?php if (empty($vendors)): ?>
                <div class="empty">No vendor conversations available yet. Book a vendor first.</div>
            <?php else: ?>
                <div class="vendor-list">
                    <?php foreach ($vendors as $vendor): ?>
                        <?php $isActive = (int)$selectedVendorUserId === (int)$vendor['vendor_user_id']; ?>
                        <a class="vendor-item <?php echo $isActive ? 'active' : ''; ?>" href="messages.php?vendor_user_id=<?php echo (int)$vendor['vendor_user_id']; ?>">
                            <?php $itemUnread = $isActive ? 0 : (int)($vendor['unread_count'] ?? 0); ?>
                            <div class="vendor-row">
                                <strong><?php echo htmlspecialchars($vendor['business_name']); ?></strong>
                                <?php if ($itemUnread > 0): ?><span class="badge-unread"><?php echo $itemUnread; ?></span><?php endif; ?>
                            </div>
                            <div class="meta"><?php echo htmlspecialchars($vendor['full_name']); ?></div>
                            <div class="preview"><?php echo htmlspecialchars((string)($vendor['last_message'] ?? 'No messages yet')); ?></div>
                            <?php if (!empty($vendor['last_message_at'])): ?><div class="preview-time"><?php echo htmlspecialchars((string)$vendor['last_message_at']); ?></div><?php endif; ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <section class="card">
            <h2 class="title">Conversation</h2>
            <?php if (!$selectedVendorUserId): ?>
                <div class="empty">Select a vendor to start chatting.</div>
            <?php else: ?>
                <div class="messages">
                    <?php if (empty($conversation)): ?>
                        <div class="empty">No messages yet. Start the conversation below.</div>
                    <?php else: ?>
                        <?php foreach ($conversation as $msg): ?>
                            <?php $mine = ($msg['sender_role'] === 'planner'); ?>
                            <div class="message <?php echo $mine ? 'mine' : 'theirs'; ?>">
                                <div class="bubble">
                                    <?php echo nl2br(htmlspecialchars($msg['message_text'])); ?>
                                    <span class="time"><?php echo htmlspecialchars((string)$msg['created_at']); ?></span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <form method="POST" class="composer">
                    <?php echo csrf_input(); ?>
                    <input type="hidden" name="vendor_user_id" value="<?php echo (int)$selectedVendorUserId; ?>">
                    <input class="input" type="text" name="message_text" placeholder="Type a message to vendor..." maxlength="1000" required>
                    <button class="btn" type="submit"><i class="fa-solid fa-paper-plane"></i> Send</button>
                </form>
            <?php endif; ?>
        </section>
    </div>
</div>
</body>
</html>


