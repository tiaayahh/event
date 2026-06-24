<?php
require_once '../includes/auth.php';
checkAuth();
requireRole('vendor');

$flashError = '';
$planners = [];
$selectedPlannerId = filter_input(INPUT_GET, 'planner_user_id', FILTER_VALIDATE_INT);
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
        $pdo->exec("ALTER TABLE messages ADD COLUMN is_read TINYINT(1) NOT NULL DEFAULT 0");
    }

    $stmt = $pdo->prepare('SELECT vendor_id FROM vendors WHERE user_id = ? LIMIT 1');
    $stmt->execute([$_SESSION['user_id']]);
    $vendor = $stmt->fetch();

    if (!$vendor) {
        $flashError = 'Vendor profile not found.';
    } else {
        $vendorId = (int)$vendor['vendor_id'];

        $stmt = $pdo->prepare(
            "SELECT p.user_id AS planner_user_id,
                    p.full_name,
                    MAX(m.created_at) AS last_message_at,
                    SUM(CASE WHEN m.sender_role = 'planner' AND m.is_read = 0 THEN 1 ELSE 0 END) AS unread_count,
                    (
                        SELECT m2.message_text
                        FROM messages m2
                        WHERE m2.planner_user_id = p.user_id AND m2.vendor_user_id = ?
                        ORDER BY m2.created_at DESC, m2.message_id DESC
                        LIMIT 1
                    ) AS last_message
             FROM (
                    SELECT DISTINCT e.planner_id AS planner_user_id
                    FROM bookings b
                    JOIN services s ON b.service_id = s.service_id
                    JOIN events e ON b.event_id = e.event_id
                    WHERE s.vendor_id = ?

                    UNION

                    SELECT DISTINCT m3.planner_user_id
                    FROM messages m3
                    WHERE m3.vendor_user_id = ?
             ) cp
             JOIN users p ON p.user_id = cp.planner_user_id
             LEFT JOIN messages m
                    ON m.planner_user_id = cp.planner_user_id
                   AND m.vendor_user_id = ?
             GROUP BY p.user_id, p.full_name
             ORDER BY (last_message_at IS NULL), last_message_at DESC, p.full_name ASC"
        );
        $stmt->execute([$_SESSION['user_id'], $vendorId, $_SESSION['user_id'], $_SESSION['user_id']]);
        $planners = $stmt->fetchAll();

        $allowedPlannerIds = array_map(static function ($p) {
            return (int)$p['planner_user_id'];
        }, $planners);

        if (!$selectedPlannerId && !empty($planners)) {
            foreach ($planners as $planner) {
                if ((int)($planner['unread_count'] ?? 0) > 0) {
                    $selectedPlannerId = (int)$planner['planner_user_id'];
                    break;
                }
            }

            if (!$selectedPlannerId) {
                $selectedPlannerId = (int)$planners[0]['planner_user_id'];
            }
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $targetPlanner = filter_input(INPUT_POST, 'planner_user_id', FILTER_VALIDATE_INT);
            $messageText = trim($_POST['message_text'] ?? '');

            if (!$targetPlanner || !in_array((int)$targetPlanner, $allowedPlannerIds, true)) {
                $flashError = 'Select a valid planner conversation.';
            } elseif ($messageText === '') {
                $flashError = 'Message cannot be empty.';
            } else {
                $stmt = $pdo->prepare('INSERT INTO messages (planner_user_id, vendor_user_id, sender_role, message_text, is_read) VALUES (?, ?, ?, ?, 0)');
                $stmt->execute([$targetPlanner, $_SESSION['user_id'], 'vendor', $messageText]);
                header('Location: messages.php?planner_user_id=' . (int)$targetPlanner);
                exit;
            }

            if ($targetPlanner) {
                $selectedPlannerId = (int)$targetPlanner;
            }
        }

        if ($selectedPlannerId && in_array((int)$selectedPlannerId, $allowedPlannerIds, true)) {
            $stmt = $pdo->prepare(
                "UPDATE messages
                 SET is_read = 1
                 WHERE planner_user_id = ? AND vendor_user_id = ? AND sender_role = 'planner' AND is_read = 0"
            );
            $stmt->execute([$selectedPlannerId, $_SESSION['user_id']]);

            $stmt = $pdo->prepare(
                "SELECT sender_role, message_text, created_at
                 FROM messages
                 WHERE planner_user_id = ? AND vendor_user_id = ?
                 ORDER BY created_at ASC, message_id ASC"
            );
            $stmt->execute([$selectedPlannerId, $_SESSION['user_id']]);
            $conversation = $stmt->fetchAll();
        }
    }
} catch (Throwable $e) {
    $flashError = 'Unable to load messages right now.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="../assets/style.css">
    <title>Planora - Vendor Messages</title>
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
        .planner-list { display: flex; flex-direction: column; gap: 8px; max-height: 68vh; overflow: auto; }
        .planner-item { display: block; text-decoration: none; border: 1px solid #ececec; border-radius: 8px; padding: 10px; color: #2D2D2D; }
        .planner-item.active { border-color: #6C63FF; background: #f7f6ff; }
        .planner-row { display: flex; align-items: center; justify-content: space-between; gap: 8px; }
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
        <a class="top-link" href="bookings.php"><i class="fa-solid fa-book-open"></i> Bookings</a>
    </div>

    <?php if ($flashError !== ''): ?>
        <div class="error"><?php echo htmlspecialchars($flashError); ?></div>
    <?php endif; ?>

    <div class="layout">
        <section class="card">
            <h2 class="title">Planners</h2>
            <?php if (empty($planners)): ?>
                <div class="empty">No planner conversations available yet.</div>
            <?php else: ?>
                <div class="planner-list">
                    <?php foreach ($planners as $planner): ?>
                        <?php $isActive = (int)$selectedPlannerId === (int)$planner['planner_user_id']; ?>
                        <a class="planner-item <?php echo $isActive ? 'active' : ''; ?>" href="messages.php?planner_user_id=<?php echo (int)$planner['planner_user_id']; ?>">
                            <?php $itemUnread = $isActive ? 0 : (int)($planner['unread_count'] ?? 0); ?>
                            <div class="planner-row">
                                <strong><?php echo htmlspecialchars($planner['full_name']); ?></strong>
                                <?php if ($itemUnread > 0): ?><span class="badge-unread"><?php echo $itemUnread; ?></span><?php endif; ?>
                            </div>
                            <div class="meta">Planner</div>
                            <div class="preview"><?php echo htmlspecialchars((string)($planner['last_message'] ?? 'No messages yet')); ?></div>
                            <?php if (!empty($planner['last_message_at'])): ?><div class="preview-time"><?php echo htmlspecialchars((string)$planner['last_message_at']); ?></div><?php endif; ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <section class="card">
            <h2 class="title">Conversation</h2>
            <?php if (!$selectedPlannerId): ?>
                <div class="empty">Select a planner to start chatting.</div>
            <?php else: ?>
                <div class="messages">
                    <?php if (empty($conversation)): ?>
                        <div class="empty">No messages yet. Start the conversation below.</div>
                    <?php else: ?>
                        <?php foreach ($conversation as $msg): ?>
                            <?php $mine = ($msg['sender_role'] === 'vendor'); ?>
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
                    <input type="hidden" name="planner_user_id" value="<?php echo (int)$selectedPlannerId; ?>">
                    <input class="input" type="text" name="message_text" placeholder="Type a message to planner..." maxlength="1000" required>
                    <button class="btn" type="submit"><i class="fa-solid fa-paper-plane"></i> Send</button>
                </form>
            <?php endif; ?>
        </section>
    </div>
</div>
</body>
</html>


