<?php
require_once '../includes/auth.php';
checkAuth();
requireRole('vendor');

$flashError = '';
$planners = [];
$showUnreadOnly = isset($_GET['unread']) && $_GET['unread'] === '1';
$selectedPlannerId = filter_input(INPUT_GET, 'planner_user_id', FILTER_VALIDATE_INT);
$conversation = [];
$newPendingCount = 0;
$boxFilter = strtolower(trim((string)($_GET['box'] ?? 'all')));
if (!in_array($boxFilter, ['all', 'inbox', 'sent'], true)) {
    $boxFilter = 'all';
}
$searchTerm = trim((string)($_GET['q'] ?? ''));
$announcementUnreadCount = 0;

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

    $stmt = $pdo->query("SHOW COLUMNS FROM messages LIKE 'message_kind'");
    if (!$stmt->fetch()) {
        $pdo->exec("ALTER TABLE messages ADD COLUMN message_kind ENUM('direct','announcement') NOT NULL DEFAULT 'direct' AFTER message_text");
    }

    $stmt = $pdo->query("SHOW COLUMNS FROM messages LIKE 'attachment_name'");
    if (!$stmt->fetch()) {
        $pdo->exec("ALTER TABLE messages ADD COLUMN attachment_name VARCHAR(255) DEFAULT NULL AFTER message_kind");
    }

    $stmt = $pdo->query("SHOW COLUMNS FROM messages LIKE 'attachment_path'");
    if (!$stmt->fetch()) {
        $pdo->exec("ALTER TABLE messages ADD COLUMN attachment_path VARCHAR(500) DEFAULT NULL AFTER attachment_name");
    }

    $stmt = $pdo->prepare('SELECT vendor_id FROM vendors WHERE user_id = ? LIMIT 1');
    $stmt->execute([$_SESSION['user_id']]);
    $vendor = $stmt->fetch();

    if (!$vendor) {
        $flashError = 'Vendor profile not found.';
    } else {
        $vendorId = (int)$vendor['vendor_id'];

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS vendor_notification_state (
                vendor_id INT NOT NULL PRIMARY KEY,
                last_seen_pending_bookings_at DATETIME NULL,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                CONSTRAINT fk_vendor_notification_state_vendor FOREIGN KEY (vendor_id) REFERENCES vendors(vendor_id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        $stmt = $pdo->prepare('SELECT last_seen_pending_bookings_at FROM vendor_notification_state WHERE vendor_id = ? LIMIT 1');
        $stmt->execute([$vendorId]);
        $lastSeenPendingAt = $stmt->fetchColumn();

        if ($lastSeenPendingAt) {
            $stmt = $pdo->prepare(
                "SELECT COUNT(*)
                 FROM bookings b
                 JOIN services s ON b.service_id = s.service_id
                 WHERE s.vendor_id = ? AND b.status = 'pending' AND b.created_at > ?"
            );
            $stmt->execute([$vendorId, $lastSeenPendingAt]);
        } else {
            $stmt = $pdo->prepare(
                "SELECT COUNT(*)
                 FROM bookings b
                 JOIN services s ON b.service_id = s.service_id
                 WHERE s.vendor_id = ? AND b.status = 'pending'"
            );
            $stmt->execute([$vendorId]);
        }
        $newPendingCount = (int)$stmt->fetchColumn();

        $stmt = $pdo->prepare(
            "SELECT COUNT(*)
             FROM messages
             WHERE vendor_user_id = ? AND sender_role = 'planner' AND is_read = 0 AND message_kind = 'announcement'"
        );
        $stmt->execute([$_SESSION['user_id']]);
        $announcementUnreadCount = (int)$stmt->fetchColumn();

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
             ORDER BY last_message_at DESC, p.full_name ASC"
        );
        $stmt->execute([$_SESSION['user_id'], $vendorId, $_SESSION['user_id'], $_SESSION['user_id']]);
        $planners = $stmt->fetchAll();

        if ($showUnreadOnly) {
            $planners = array_values(array_filter($planners, static function ($planner) {
                return (int)($planner['unread_count'] ?? 0) > 0;
            }));
        }

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
            $attachmentName = null;
            $attachmentPath = null;

            if (!$targetPlanner || !in_array((int)$targetPlanner, $allowedPlannerIds, true)) {
                $flashError = 'Select a valid planner conversation.';
            } elseif ($messageText === '' && (empty($_FILES['attachment_file']['name']) || !is_string($_FILES['attachment_file']['name']))) {
                $flashError = 'Message cannot be empty unless you attach a document.';
            } else {
                if (isset($_FILES['attachment_file']) && is_array($_FILES['attachment_file']) && (int)($_FILES['attachment_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                    if ((int)$_FILES['attachment_file']['error'] !== UPLOAD_ERR_OK) {
                        $flashError = 'Attachment upload failed. Please try again.';
                    } else {
                        $originalName = (string)($_FILES['attachment_file']['name'] ?? '');
                        $tmpPath = (string)($_FILES['attachment_file']['tmp_name'] ?? '');
                        $size = (int)($_FILES['attachment_file']['size'] ?? 0);
                        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
                        $allowedExt = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'txt', 'jpg', 'jpeg', 'png'];

                        if ($size <= 0 || $size > 5 * 1024 * 1024) {
                            $flashError = 'Attachment must be between 1 byte and 5MB.';
                        } elseif (!in_array($ext, $allowedExt, true)) {
                            $flashError = 'Attachment type not allowed. Use PDF, DOC, DOCX, XLS, XLSX, TXT, JPG, JPEG, or PNG.';
                        } else {
                            $uploadDir = dirname(__DIR__) . '/uploads/message_docs';
                            if (!is_dir($uploadDir)) {
                                mkdir($uploadDir, 0775, true);
                            }

                            $safeFile = 'msg_' . time() . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
                            $targetPath = $uploadDir . '/' . $safeFile;
                            if (!move_uploaded_file($tmpPath, $targetPath)) {
                                $flashError = 'Could not save attachment file.';
                            } else {
                                $attachmentName = $originalName;
                                $attachmentPath = 'uploads/message_docs/' . $safeFile;
                            }
                        }
                    }
                }
            }

            if ($flashError === '') {
                $stmt = $pdo->prepare('INSERT INTO messages (planner_user_id, vendor_user_id, sender_role, message_text, message_kind, attachment_name, attachment_path, is_read) VALUES (?, ?, ?, ?, ?, ?, ?, 0)');
                $stmt->execute([$targetPlanner, $_SESSION['user_id'], 'vendor', $messageText, 'direct', $attachmentName, $attachmentPath]);

                audit_log(
                    $pdo,
                    (int)$_SESSION['user_id'],
                    (string)$_SESSION['role'],
                    'message.send',
                    'conversation',
                    (string)$targetPlanner,
                    [
                        'recipient_role' => 'planner',
                        'message_kind' => 'direct',
                        'has_attachment' => $attachmentPath !== null,
                    ]
                );

                header('Location: messages.php?planner_user_id=' . (int)$targetPlanner . '&box=' . urlencode($boxFilter) . '&q=' . urlencode($searchTerm));
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
                "SELECT sender_role, message_text, created_at, message_kind, attachment_name, attachment_path
                 FROM messages
                 WHERE planner_user_id = ? AND vendor_user_id = ?"
            );
            $conversationParams = [$selectedPlannerId, $_SESSION['user_id']];
            if ($boxFilter === 'inbox') {
                $stmt = $pdo->prepare(
                    "SELECT sender_role, message_text, created_at, message_kind, attachment_name, attachment_path
                     FROM messages
                     WHERE planner_user_id = ? AND vendor_user_id = ? AND sender_role = 'planner'"
                );
            } elseif ($boxFilter === 'sent') {
                $stmt = $pdo->prepare(
                    "SELECT sender_role, message_text, created_at, message_kind, attachment_name, attachment_path
                     FROM messages
                     WHERE planner_user_id = ? AND vendor_user_id = ? AND sender_role = 'vendor'"
                );
            }

            if ($searchTerm !== '') {
                $querySql = (string)$stmt->queryString . " AND message_text LIKE ?";
                $stmt = $pdo->prepare($querySql);
                $conversationParams[] = '%' . $searchTerm . '%';
            }

            $stmt = $pdo->prepare((string)$stmt->queryString . " ORDER BY created_at ASC, message_id ASC");
            $stmt->execute($conversationParams);
            $conversation = $stmt->fetchAll();
        }
    }
} catch (Throwable $e) {
    error_log('vendor/messages.php error: ' . $e->getMessage());
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
        .planner-item.booking-alert { border-color: #dcd7ff; background: #f7f6ff; }
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
        .composer-tools { margin-top: 8px; display: flex; gap: 8px; flex-wrap: wrap; }
        .input { width: 100%; border: 1px solid #d6d6d6; border-radius: 8px; padding: 10px; font-size: 14px; }
        .file-input { border: 1px solid #d6d6d6; border-radius: 8px; padding: 8px; font-size: 13px; background: #fff; }
        .btn { background: #6C63FF; color: #fff; border: none; border-radius: 8px; padding: 10px 14px; font-size: 13px; cursor: pointer; }
        .empty { color: #777; font-size: 14px; padding: 10px 0; }
        .hint { background: #eef3ff; color: #2c4ea0; border: 1px solid #d9e4ff; border-radius: 6px; padding: 9px 11px; margin-bottom: 10px; font-size: 12px; }
        .filters { display: flex; gap: 8px; margin-bottom: 10px; flex-wrap: wrap; }
        .filter-link { text-decoration: none; background: #fafafa; color: #4b4b4b; border: 1px solid #e5e5e5; border-radius: 999px; padding: 6px 10px; font-size: 12px; font-weight: 600; }
        .filter-link.active { background: #ece9ff; border-color: #c9c2ff; color: #3f379f; }
        .announcement-tag { display: inline-block; margin-bottom: 5px; font-size: 10px; font-weight: 700; color: #2c4ea0; background: #e8efff; border: 1px solid #d2dfff; border-radius: 999px; padding: 2px 8px; }
        .attachment-link { display: inline-block; margin-top: 6px; color: inherit; text-decoration: underline; font-size: 12px; }
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
        <a class="top-link" href="bookings.php"><i class="fa-solid fa-book-open"></i> Bookings<?php if ($newPendingCount > 0): ?> (<?php echo $newPendingCount; ?> new)<?php endif; ?></a>
    </div>

    <?php if ($flashError !== ''): ?>
        <div class="error"><?php echo htmlspecialchars($flashError); ?></div>
    <?php endif; ?>

    <?php if ($showUnreadOnly): ?>
        <div class="hint"><i class="fa-solid fa-filter"></i> Showing unread conversations first. <a href="messages.php">View all</a></div>
    <?php endif; ?>

    <?php if ($announcementUnreadCount > 0): ?>
        <div class="hint"><i class="fa-solid fa-bullhorn"></i> New announcements: <strong><?php echo (int)$announcementUnreadCount; ?></strong></div>
    <?php endif; ?>

    <div class="layout">
        <section class="card">
            <h2 class="title">Planners</h2>
            <div class="planner-list">
                <a class="planner-item booking-alert" href="bookings.php?status=pending">
                    <div class="planner-row">
                        <strong><i class="fa-solid fa-book-open"></i> Pending Bookings</strong>
                        <?php if ($newPendingCount > 0): ?><span class="badge-unread"><?php echo $newPendingCount; ?></span><?php endif; ?>
                    </div>
                    <div class="meta">Booking notifications</div>
                    <div class="preview"><?php echo $newPendingCount > 0 ? 'You have new pending bookings to review.' : 'No new pending booking notifications.'; ?></div>
                </a>

                <?php if (empty($planners)): ?>
                    <div class="empty">No planner conversations available yet.</div>
                <?php else: ?>
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
                <?php endif; ?>
            </div>
        </section>

        <section class="card">
            <h2 class="title">Conversation</h2>
            <?php if (!$selectedPlannerId): ?>
                <div class="empty">Select a planner to start chatting.</div>
            <?php else: ?>
                <div class="messages">
                    <div class="filters">
                        <a class="filter-link <?php echo $boxFilter === 'all' ? 'active' : ''; ?>" href="messages.php?planner_user_id=<?php echo (int)$selectedPlannerId; ?>&box=all&q=<?php echo urlencode($searchTerm); ?>">All</a>
                        <a class="filter-link <?php echo $boxFilter === 'inbox' ? 'active' : ''; ?>" href="messages.php?planner_user_id=<?php echo (int)$selectedPlannerId; ?>&box=inbox&q=<?php echo urlencode($searchTerm); ?>">Inbox</a>
                        <a class="filter-link <?php echo $boxFilter === 'sent' ? 'active' : ''; ?>" href="messages.php?planner_user_id=<?php echo (int)$selectedPlannerId; ?>&box=sent&q=<?php echo urlencode($searchTerm); ?>">Sent</a>
                    </div>
                    <form method="GET" class="composer" style="margin-top:0; margin-bottom:10px;">
                        <input type="hidden" name="planner_user_id" value="<?php echo (int)$selectedPlannerId; ?>">
                        <input type="hidden" name="box" value="<?php echo htmlspecialchars($boxFilter); ?>">
                        <input class="input" type="text" name="q" value="<?php echo htmlspecialchars($searchTerm); ?>" aria-label="Search messages">
                        <button class="btn" type="submit"><i class="fa-solid fa-magnifying-glass"></i> Search</button>
                    </form>
                    <?php if (empty($conversation)): ?>
                        <div class="empty">No messages yet. Start the conversation below.</div>
                    <?php else: ?>
                        <?php foreach ($conversation as $msg): ?>
                            <?php $mine = ($msg['sender_role'] === 'vendor'); ?>
                            <div class="message <?php echo $mine ? 'mine' : 'theirs'; ?>">
                                <div class="bubble">
                                    <?php if (($msg['message_kind'] ?? 'direct') === 'announcement'): ?><span class="announcement-tag">Announcement</span><br><?php endif; ?>
                                    <?php echo nl2br(htmlspecialchars($msg['message_text'])); ?>
                                    <?php if (!empty($msg['attachment_path'])): ?>
                                        <a class="attachment-link" href="../<?php echo htmlspecialchars((string)$msg['attachment_path']); ?>" target="_blank" rel="noopener noreferrer">
                                            <i class="fa-solid fa-paperclip"></i> <?php echo htmlspecialchars((string)($msg['attachment_name'] ?: 'Attachment')); ?>
                                        </a>
                                    <?php endif; ?>
                                    <span class="time"><?php echo htmlspecialchars((string)$msg['created_at']); ?></span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <form method="POST" class="composer" enctype="multipart/form-data">
                    <?php echo csrf_input(); ?>
                    <input type="hidden" name="planner_user_id" value="<?php echo (int)$selectedPlannerId; ?>">
                    <input class="input" type="text" name="message_text" maxlength="1000">
                    <button class="btn" type="submit"><i class="fa-solid fa-paper-plane"></i> Send</button>
                    <div class="composer-tools" style="grid-column: 1 / -1;">
                        <input class="file-input" type="file" name="attachment_file" aria-label="Attach document">
                    </div>
                </form>
            <?php endif; ?>
        </section>
    </div>
</div>
</body>
</html>


