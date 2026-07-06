<?php
require_once '../includes/auth.php';
checkAuth();
requireRole('attendee');

$flashError = '';
$planners = [];
$showUnreadOnly = isset($_GET['unread']) && $_GET['unread'] === '1';
$selectedPlannerId = filter_input(INPUT_GET, 'planner_user_id', FILTER_VALIDATE_INT);
$conversation = [];
$boxFilter = strtolower(trim((string)($_GET['box'] ?? 'all')));
if (!in_array($boxFilter, ['all', 'inbox', 'sent'], true)) {
    $boxFilter = 'all';
}
$searchTerm = trim((string)($_GET['q'] ?? ''));
$announcementUnreadCount = 0;
$totalUnread = 0;

try {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS attendee_messages (
            message_id INT AUTO_INCREMENT PRIMARY KEY,
            planner_user_id INT NOT NULL,
            attendee_user_id INT NOT NULL,
            sender_role ENUM('planner','attendee') NOT NULL,
            message_text TEXT NOT NULL,
            message_kind ENUM('direct','announcement') NOT NULL DEFAULT 'direct',
            attachment_name VARCHAR(255) DEFAULT NULL,
            attachment_path VARCHAR(500) DEFAULT NULL,
            is_read TINYINT(1) NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_attendee_conversation (planner_user_id, attendee_user_id, created_at),
            INDEX idx_attendee_recipient_unread (attendee_user_id, is_read, sender_role),
            INDEX idx_attendee_planner_recipient (planner_user_id, attendee_user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $stmt = $pdo->prepare('SELECT attendee_id FROM attendees WHERE user_id = ? LIMIT 1');
    $stmt->execute([$_SESSION['user_id']]);
    $attendee = $stmt->fetch();

    if (!$attendee) {
        $flashError = 'Attendee profile not found.';
    } else {
        $attendeeId = (int)$attendee['attendee_id'];

        $stmt = $pdo->prepare(
            "SELECT p.user_id AS planner_user_id,
                    p.full_name,
                    MAX(m.created_at) AS last_message_at,
                    SUM(CASE WHEN m.sender_role = 'planner' AND m.is_read = 0 THEN 1 ELSE 0 END) AS unread_count,
                    (
                        SELECT m2.message_text
                        FROM attendee_messages m2
                        WHERE m2.planner_user_id = p.user_id AND m2.attendee_user_id = ?
                        ORDER BY m2.created_at DESC, m2.message_id DESC
                        LIMIT 1
                    ) AS last_message
             FROM (
                    SELECT DISTINCT e.planner_id AS planner_user_id
                    FROM attendances a
                    JOIN events e ON a.event_id = e.event_id
                    WHERE a.attendee_id = ? AND e.archived_at IS NULL

                    UNION

                    SELECT DISTINCT am.planner_user_id
                    FROM attendee_messages am
                    WHERE am.attendee_user_id = ?
             ) cp
             JOIN users p ON p.user_id = cp.planner_user_id
             LEFT JOIN attendee_messages m
                    ON m.planner_user_id = cp.planner_user_id
                   AND m.attendee_user_id = ?
             GROUP BY p.user_id, p.full_name
             ORDER BY CASE WHEN MAX(m.created_at) IS NULL THEN 1 ELSE 0 END,
                      MAX(m.created_at) DESC,
                      p.full_name ASC"
        );
        $stmt->execute([$_SESSION['user_id'], $attendeeId, $_SESSION['user_id'], $_SESSION['user_id']]);
        $planners = $stmt->fetchAll();

        foreach ($planners as $planner) {
            $totalUnread += (int)($planner['unread_count'] ?? 0);
        }

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
                $flashError = 'Select a valid organizer conversation.';
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
                $stmt = $pdo->prepare('INSERT INTO attendee_messages (planner_user_id, attendee_user_id, sender_role, message_text, message_kind, attachment_name, attachment_path, is_read) VALUES (?, ?, ?, ?, ?, ?, ?, 0)');
                $stmt->execute([$targetPlanner, $_SESSION['user_id'], 'attendee', $messageText, 'direct', $attachmentName, $attachmentPath]);

                audit_log(
                    $pdo,
                    (int)$_SESSION['user_id'],
                    (string)$_SESSION['role'],
                    'attendee.message.send',
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

        $stmt = $pdo->prepare(
            "SELECT COUNT(*)
             FROM attendee_messages
             WHERE attendee_user_id = ? AND sender_role = 'planner' AND is_read = 0 AND message_kind = 'announcement'"
        );
        $stmt->execute([$_SESSION['user_id']]);
        $announcementUnreadCount = (int)$stmt->fetchColumn();

        if ($selectedPlannerId && in_array((int)$selectedPlannerId, $allowedPlannerIds, true)) {
            $stmt = $pdo->prepare(
                "UPDATE attendee_messages
                 SET is_read = 1
                 WHERE planner_user_id = ? AND attendee_user_id = ? AND sender_role = 'planner' AND is_read = 0"
            );
            $stmt->execute([$selectedPlannerId, $_SESSION['user_id']]);

            $stmt = $pdo->prepare(
                "SELECT sender_role, message_text, created_at, message_kind, attachment_name, attachment_path
                 FROM attendee_messages
                 WHERE planner_user_id = ? AND attendee_user_id = ?"
            );
            $conversationParams = [$selectedPlannerId, $_SESSION['user_id']];

            if ($boxFilter === 'inbox') {
                $stmt = $pdo->prepare(
                    "SELECT sender_role, message_text, created_at, message_kind, attachment_name, attachment_path
                     FROM attendee_messages
                     WHERE planner_user_id = ? AND attendee_user_id = ? AND sender_role = 'planner'"
                );
            } elseif ($boxFilter === 'sent') {
                $stmt = $pdo->prepare(
                    "SELECT sender_role, message_text, created_at, message_kind, attachment_name, attachment_path
                     FROM attendee_messages
                     WHERE planner_user_id = ? AND attendee_user_id = ? AND sender_role = 'attendee'"
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
    error_log('attendee/messages.php error: ' . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="../assets/style.css">
    <title>Planora - Attendee Messages</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; }
        body { background: #F5F5F5; color: #2D2D2D; min-height: 100vh; padding-bottom: 70px; }
        .header { background: #6C63FF; color: #fff; padding: 14px 24px; display: flex; justify-content: space-between; align-items: center; }
        .brand { font-size: 22px; font-weight: 700; }
        .logout-btn { background: rgba(255,255,255,0.22); color: #fff; text-decoration: none; border-radius: 6px; padding: 7px 14px; font-size: 13px; }
        .container { max-width: 1100px; margin: 0 auto; padding: 20px; }
        .top-links { display: flex; gap: 10px; margin-bottom: 14px; flex-wrap: wrap; }
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
        .bottom-nav {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            background: #6C63FF;
            display: flex;
            justify-content: space-around;
            align-items: center;
            height: 55px;
            z-index: 999;
        }
        .nav-item {
            color: rgba(255,255,255,0.7);
            text-decoration: none;
            font-size: 12px;
            font-weight: 500;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 4px;
            flex-grow: 1;
            height: 100%;
        }
        .nav-item i { font-size: 16px; }
        .nav-item:hover, .nav-item.active { color: #fff; background: rgba(255,255,255,0.1); }
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
        <a class="top-link" href="my_events.php"><i class="fa-solid fa-list-check"></i> My Events</a>
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
            <h2 class="title">Organizers</h2>
            <div class="meta" style="margin-bottom:8px;">Unread updates: <strong><?php echo (int)$totalUnread; ?></strong></div>
            <?php if (empty($planners)): ?>
                <div class="empty">No organizer conversations available yet. Register for events to connect with organizers.</div>
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
                            <div class="meta">Organizer</div>
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
                <div class="empty">Select an organizer to start chatting.</div>
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
                            <?php $mine = ($msg['sender_role'] === 'attendee'); ?>
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

<nav class="bottom-nav">
    <a href="dashboard.php" class="nav-item"><i class="fa-solid fa-house"></i><span>Home</span></a>
    <a href="explore.php" class="nav-item"><i class="fa-solid fa-compass"></i><span>Explore</span></a>
    <a href="my_events.php" class="nav-item"><i class="fa-solid fa-list-check"></i><span>My Events</span></a>
    <a href="messages.php" class="nav-item active"><i class="fa-solid fa-comments"></i><span>Messages</span></a>
    <a href="profile.php" class="nav-item"><i class="fa-solid fa-user"></i><span>Profile</span></a>
</nav>
</body>
</html>
