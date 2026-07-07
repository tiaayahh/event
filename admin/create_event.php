<?php
require_once '../includes/auth.php';
checkAuth();
requireRole('planner');

$flashSuccess = $_SESSION['flash_success'] ?? '';
$flashError = $_SESSION['flash_error'] ?? '';
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

$title = '';
$eventDate = '';
$venue = '';
$category = '';
$city = '';
$eventType = 'in_person';
$imageUrl = '';
$budgetTotal = '';
$ticketPrice = '0.00';
$ticketTypePrices = [
    'early_bird' => '0.00',
    'regular' => '0.00',
    'vip' => '0.00',
    'vvip' => '0.00',
];
$ticketTypeRemaining = [
    'early_bird' => '50',
    'regular' => '50',
    'vip' => '50',
    'vvip' => '50',
];
$ticketTypeDescriptions = [
    'early_bird' => '',
    'regular' => '',
    'vip' => '',
    'vvip' => '',
];
$ticketsAvailable = '200';
$expectedAttendees = '0';
$vendorContributionTarget = '0.00';
$stallPrice = '0.00';
$budgetItemNames = ['', '', ''];
$budgetItemAmounts = ['', '', ''];
$events = [];
$archivedEvents = [];
$eventOpsById = [];
$eventBudgetItemsByEventId = [];
$eventTicketTypesByEventId = [];
$eventsNeedingAttention = 0;
$eventView = strtolower(trim((string)($_GET['event_view'] ?? 'active')));
if (!in_array($eventView, ['active', 'archived', 'all'], true)) {
    $eventView = 'active';
}

function ensureEventBudgetPlanningSchema(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }

    $stmt = $pdo->query("SHOW COLUMNS FROM events LIKE 'attendee_contribution_target'");
    if (!$stmt->fetch()) {
        $pdo->exec("ALTER TABLE events ADD COLUMN attendee_contribution_target DECIMAL(12,2) NOT NULL DEFAULT 0.00");
    }

    $stmt = $pdo->query("SHOW COLUMNS FROM events LIKE 'vendor_contribution_target'");
    if (!$stmt->fetch()) {
        $pdo->exec("ALTER TABLE events ADD COLUMN vendor_contribution_target DECIMAL(12,2) NOT NULL DEFAULT 0.00");
    }

    $stmt = $pdo->query("SHOW COLUMNS FROM events LIKE 'venue'");
    if (!$stmt->fetch()) {
        $pdo->exec("ALTER TABLE events ADD COLUMN venue VARCHAR(190) DEFAULT NULL AFTER event_date");
    }

    $stmt = $pdo->query("SHOW COLUMNS FROM events LIKE 'category'");
    if (!$stmt->fetch()) {
        $pdo->exec("ALTER TABLE events ADD COLUMN category VARCHAR(64) DEFAULT NULL AFTER title");
    }

    $stmt = $pdo->query("SHOW COLUMNS FROM events LIKE 'city'");
    if (!$stmt->fetch()) {
        $pdo->exec("ALTER TABLE events ADD COLUMN city VARCHAR(120) DEFAULT NULL AFTER venue");
    }

    $stmt = $pdo->query("SHOW COLUMNS FROM events LIKE 'event_type'");
    if (!$stmt->fetch()) {
        $pdo->exec("ALTER TABLE events ADD COLUMN event_type ENUM('in_person','online') NOT NULL DEFAULT 'in_person' AFTER city");
    }

    $stmt = $pdo->query("SHOW COLUMNS FROM events LIKE 'image_url'");
    if (!$stmt->fetch()) {
        $pdo->exec("ALTER TABLE events ADD COLUMN image_url VARCHAR(500) DEFAULT NULL AFTER event_type");
    }

    $stmt = $pdo->query("SHOW COLUMNS FROM events LIKE 'tickets_available'");
    if (!$stmt->fetch()) {
        $pdo->exec("ALTER TABLE events ADD COLUMN tickets_available INT NOT NULL DEFAULT 200 AFTER ticket_price");
    }

    $stmt = $pdo->query("SHOW COLUMNS FROM events LIKE 'stall_price'");
    if (!$stmt->fetch()) {
        $pdo->exec("ALTER TABLE events ADD COLUMN stall_price DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER ticket_price");
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS event_budget_items (
            item_id BIGINT AUTO_INCREMENT PRIMARY KEY,
            event_id INT NOT NULL,
            item_name VARCHAR(190) NOT NULL,
            planned_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            spent_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            sort_order INT NOT NULL DEFAULT 0,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_event_budget_items_event_sort (event_id, sort_order),
            CONSTRAINT fk_event_budget_items_event FOREIGN KEY (event_id) REFERENCES events(event_id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $stmt = $pdo->query("SHOW COLUMNS FROM event_budget_items LIKE 'spent_amount'");
    if (!$stmt->fetch()) {
        $pdo->exec("ALTER TABLE event_budget_items ADD COLUMN spent_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER planned_amount");
    }

    $ready = true;
}

function storeEventBannerUpload(array $file, string &$errorMessage): ?string
{
    $uploadError = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($uploadError === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    if ($uploadError !== UPLOAD_ERR_OK) {
        $errorMessage = 'Banner upload failed. Please try again.';
        return null;
    }

    $originalName = (string)($file['name'] ?? '');
    $tmpPath = (string)($file['tmp_name'] ?? '');
    $size = (int)($file['size'] ?? 0);
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $allowedExt = ['jpg', 'jpeg', 'png', 'webp', 'gif'];

    if ($size <= 0 || $size > 5 * 1024 * 1024) {
        $errorMessage = 'Banner must be between 1 byte and 5MB.';
        return null;
    }

    if (!in_array($ext, $allowedExt, true)) {
        $errorMessage = 'Banner file type not allowed. Use JPG, PNG, WEBP, or GIF.';
        return null;
    }

    $uploadDir = dirname(__DIR__) . '/uploads/event_banners';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0775, true);
    }

    try {
        $randomPart = bin2hex(random_bytes(6));
    } catch (Throwable $e) {
        $randomPart = uniqid('', true);
    }

    $safeFile = 'event_banner_' . time() . '_' . str_replace('.', '', $randomPart) . '.' . $ext;
    $targetPath = $uploadDir . '/' . $safeFile;
    if (!move_uploaded_file($tmpPath, $targetPath)) {
        $errorMessage = 'Could not save banner file.';
        return null;
    }

    return 'uploads/event_banners/' . $safeFile;
}

function resolveEventBannerUrl(?string $rawUrl): string
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

function normalizeBannerInputUrl(string $value): ?string
{
    $url = trim($value);
    if ($url === '') {
        return null;
    }

    $url = str_replace('\\', '/', $url);

    if (preg_match('/^www\./i', $url) === 1) {
        $url = 'https://' . $url;
    }

    if (filter_var($url, FILTER_VALIDATE_URL)) {
        return $url;
    }

    if (preg_match('#^(\.\./)?/?uploads/#i', $url) === 1) {
        return preg_replace('#^(\.\./)?/?#', '', $url);
    }

    return null;
}

function pdfEscapeText(string $text): string
{
    $safe = preg_replace('/[^\x20-\x7E]/', '?', $text);
    if ($safe === null) {
        $safe = '';
    }
    return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $safe);
}

function buildSimplePdfDocument(array $lines): string
{
    $maxLines = 48;
    if (count($lines) > $maxLines) {
        $lines = array_slice($lines, 0, $maxLines - 1);
        $lines[] = '... truncated ...';
    }

    $y = 800;
    $stream = '';
    foreach ($lines as $line) {
        $escaped = pdfEscapeText((string)$line);
        $stream .= sprintf("BT /F1 11 Tf 50 %d Td (%s) Tj ET\n", $y, $escaped);
        $y -= 15;
    }

    $objects = [];
    $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
    $objects[2] = '<< /Type /Pages /Kids [3 0 R] /Count 1 >>';
    $objects[3] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 5 0 R >> >> /Contents 4 0 R >>';
    $objects[4] = "<< /Length " . strlen($stream) . " >>\nstream\n" . $stream . "endstream";
    $objects[5] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';

    $pdf = "%PDF-1.4\n";
    $offsets = [0];
    for ($i = 1; $i <= 5; $i++) {
        $offsets[$i] = strlen($pdf);
        $pdf .= $i . " 0 obj\n" . $objects[$i] . "\nendobj\n";
    }

    $xrefPos = strlen($pdf);
    $pdf .= "xref\n0 6\n";
    $pdf .= "0000000000 65535 f \n";
    for ($i = 1; $i <= 5; $i++) {
        $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
    }

    $pdf .= "trailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n" . $xrefPos . "\n%%EOF";
    return $pdf;
}

function ensureEventFinancialAdjustmentsSchema(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS event_financial_adjustments (
            adjustment_id BIGINT AUTO_INCREMENT PRIMARY KEY,
            event_id INT NOT NULL,
            entry_kind ENUM('committed_paid','cash_available') NOT NULL,
            amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            note VARCHAR(255) DEFAULT NULL,
            created_by INT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_event_fin_adj_event_kind (event_id, entry_kind),
            CONSTRAINT fk_event_fin_adj_event FOREIGN KEY (event_id) REFERENCES events(event_id) ON DELETE CASCADE,
            CONSTRAINT fk_event_fin_adj_user FOREIGN KEY (created_by) REFERENCES users(user_id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $ready = true;
}

function ensureEventSponsorshipsSchema(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS event_sponsorships (
            sponsorship_id BIGINT AUTO_INCREMENT PRIMARY KEY,
            event_id INT NOT NULL,
            sponsor_name VARCHAR(190) NOT NULL,
            contribution_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_event_sponsorships_event (event_id),
            CONSTRAINT fk_event_sponsorships_event FOREIGN KEY (event_id) REFERENCES events(event_id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $ready = true;
}

function ensureEventTicketTypesSchema(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS event_ticket_types (
            ticket_type_id BIGINT AUTO_INCREMENT PRIMARY KEY,
            event_id INT NOT NULL,
            ticket_type VARCHAR(32) NOT NULL,
            price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            description VARCHAR(255) DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_event_ticket_type (event_id, ticket_type),
            CONSTRAINT fk_event_ticket_types_event FOREIGN KEY (event_id) REFERENCES events(event_id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $stmt = $pdo->query("SHOW COLUMNS FROM event_ticket_types LIKE 'description'");
    if (!$stmt->fetch()) {
        $pdo->exec("ALTER TABLE event_ticket_types ADD COLUMN description VARCHAR(255) DEFAULT NULL AFTER price");
    }

    $stmt = $pdo->query("SHOW COLUMNS FROM event_ticket_types LIKE 'tickets_remaining'");
    if (!$stmt->fetch()) {
        $pdo->exec("ALTER TABLE event_ticket_types ADD COLUMN tickets_remaining INT NOT NULL DEFAULT 0 AFTER description");
    }

    $ready = true;
}

ensureEventBudgetPlanningSchema($pdo);
ensureEventFinancialAdjustmentsSchema($pdo);
ensureEventSponsorshipsSchema($pdo);
ensureEventTicketTypesSchema($pdo);

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['create_event'])) {
        $title = trim($_POST['title'] ?? '');
        $eventDate = trim($_POST['event_date'] ?? '');
        $venue = trim($_POST['venue'] ?? '');
        $category = trim($_POST['category'] ?? '');
        $city = trim($_POST['city'] ?? '');
        $eventType = strtolower(trim((string)($_POST['event_type'] ?? 'in_person')));
        $imageUrl = trim($_POST['image_url'] ?? '');
        $budgetTotal = trim($_POST['budget_total'] ?? '');
        $formTicketTypePrices = $_POST['ticket_type_price'] ?? [];
        $formTicketTypeRemaining = $_POST['ticket_type_remaining'] ?? [];
        $formTicketTypeDescriptions = $_POST['ticket_type_description'] ?? [];
        $ticketsAvailable = trim($_POST['tickets_available'] ?? '200');
        $expectedAttendees = trim($_POST['expected_attendees'] ?? '0');
        $vendorContributionTarget = trim($_POST['vendor_contribution_target'] ?? '0.00');
        $stallPrice = trim($_POST['stall_price'] ?? '0.00');
        $budgetItemNames = $_POST['budget_item_name'] ?? [];
        $budgetItemAmounts = $_POST['budget_item_amount'] ?? [];

        if (!is_array($budgetItemNames)) {
            $budgetItemNames = [];
        }
        if (!is_array($budgetItemAmounts)) {
            $budgetItemAmounts = [];
        }
        if (!is_array($formTicketTypePrices)) {
            $formTicketTypePrices = [];
        }
        if (!is_array($formTicketTypeRemaining)) {
            $formTicketTypeRemaining = [];
        }
        if (!is_array($formTicketTypeDescriptions)) {
            $formTicketTypeDescriptions = [];
        }

        $allowedTicketTypes = ['early_bird', 'regular', 'vip', 'vvip'];
        foreach ($allowedTicketTypes as $ticketType) {
            $rawPrice = trim((string)($formTicketTypePrices[$ticketType] ?? '0.00'));
            if ($rawPrice === '' || !is_numeric($rawPrice) || (float)$rawPrice < 0) {
                $flashError = 'Each ticket type price must be a valid non-negative amount.';
                break;
            }

            $rawRemaining = trim((string)($formTicketTypeRemaining[$ticketType] ?? '0'));
            if ($rawRemaining === '' || !ctype_digit($rawRemaining)) {
                $flashError = 'Each ticket type remaining value must be a valid non-negative whole number.';
                break;
            }

            $ticketTypePrices[$ticketType] = number_format((float)$rawPrice, 2, '.', '');
            $ticketTypeRemaining[$ticketType] = (string)((int)$rawRemaining);
            $ticketTypeDescriptions[$ticketType] = trim((string)($formTicketTypeDescriptions[$ticketType] ?? ''));
        }
        $ticketPrice = (string)($ticketTypePrices['regular'] ?? '0.00');

        $plannedItems = [];
        $plannedCommitted = 0.0;
        $itemCount = max(count($budgetItemNames), count($budgetItemAmounts));
        for ($i = 0; $i < $itemCount; $i++) {
            $name = trim((string)($budgetItemNames[$i] ?? ''));
            $amountRaw = trim((string)($budgetItemAmounts[$i] ?? ''));

            if ($name === '' && $amountRaw === '') {
                continue;
            }

            if ($name === '') {
                $flashError = 'Each budget item amount must include an item name.';
                break;
            }

            if ($amountRaw === '' || !is_numeric($amountRaw) || (float)$amountRaw < 0) {
                $flashError = 'Each budget item must have a valid non-negative amount.';
                break;
            }

            $amount = (float)$amountRaw;
            $plannedItems[] = [
                'name' => $name,
                'amount' => $amount,
            ];
            $plannedCommitted += $amount;
        }

        if ($flashError === '' && ($title === '' || $eventDate === '' || $budgetTotal === '' || !is_numeric($budgetTotal) || (float)$budgetTotal < 0)) {
            $flashError = 'Title, event date, and a valid non-negative budget are required.';
        }

        if ($flashError === '' && !in_array($eventType, ['in_person', 'online'], true)) {
            $flashError = 'Choose a valid event type.';
        }

        if ($flashError === '' && strlen($category) > 64) {
            $flashError = 'Category must be 64 characters or fewer.';
        }

        if ($flashError === '' && strlen($city) > 120) {
            $flashError = 'City must be 120 characters or fewer.';
        }

        if ($flashError === '' && ($ticketsAvailable === '' || !ctype_digit($ticketsAvailable) || (int)$ticketsAvailable < 0)) {
            $flashError = 'Tickets available must be a valid non-negative whole number.';
        }

        if ($flashError === '' && ($expectedAttendees === '' || !is_numeric($expectedAttendees) || (int)$expectedAttendees < 0)) {
            $flashError = 'Expected attendees must be a valid non-negative whole number.';
        }

        if ($flashError === '' && ($vendorContributionTarget === '' || !is_numeric($vendorContributionTarget) || (float)$vendorContributionTarget < 0)) {
            $flashError = 'Vendor contribution target must be a valid non-negative amount.';
        }

        if ($flashError === '' && ($stallPrice === '' || !is_numeric($stallPrice) || (float)$stallPrice < 0)) {
            $flashError = 'Stall price must be a valid non-negative amount.';
        }

        $hasBannerUrlInput = $imageUrl !== '';
        $hasBannerFileInput = isset($_FILES['banner_file'])
            && is_array($_FILES['banner_file'])
            && (int)($_FILES['banner_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;

        if ($flashError === '' && $hasBannerUrlInput && $hasBannerFileInput) {
            $flashError = 'Use either a banner URL or an uploaded banner file, not both.';
        }

        if ($flashError === '' && $imageUrl !== '') {
            $normalized = normalizeBannerInputUrl($imageUrl);
            if ($normalized === null) {
                $flashError = 'Banner URL must be a valid URL or an uploads path.';
            } else {
                $imageUrl = $normalized;
            }
        }

        $uploadedBannerPath = null;
        if ($flashError === '' && isset($_FILES['banner_file']) && is_array($_FILES['banner_file'])) {
            $uploadedBannerPath = storeEventBannerUpload($_FILES['banner_file'], $flashError);
        }

        $budgetTotalFloat = (float)$budgetTotal;
        $ticketPriceFloat = (float)$ticketPrice;
        $ticketsAvailableInt = max(0, array_sum(array_map(static fn(string $v): int => (int)$v, $ticketTypeRemaining)));
        $expectedAttendeesInt = max(0, (int)$expectedAttendees);
        $vendorContributionTargetFloat = (float)$vendorContributionTarget;
        $stallPriceFloat = (float)$stallPrice;
        $attendeeContributionTarget = $ticketPriceFloat * $expectedAttendeesInt;
        $finalImageUrl = $uploadedBannerPath ?? ($imageUrl !== '' ? $imageUrl : null);

        if ($flashError === '' && $plannedCommitted > $budgetTotalFloat) {
            $flashError = 'Planned budget items exceed the total budget. Reduce item amounts or increase budget.';
        }

        if ($flashError === '') {
            try {
                $pdo->beginTransaction();

                $stmt = $pdo->prepare(
                    'INSERT INTO events (planner_id, title, category, event_date, venue, city, event_type, image_url, budget_total, budget_committed, ticket_price, stall_price, tickets_available, ticket_revenue, attendee_contribution_target, vendor_contribution_target)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?)'
                );
                $stmt->execute([
                    $_SESSION['user_id'],
                    $title,
                    $category !== '' ? $category : null,
                    $eventDate,
                    $venue !== '' ? $venue : null,
                    $city !== '' ? $city : null,
                    $eventType,
                    $finalImageUrl,
                    $budgetTotalFloat,
                    $plannedCommitted,
                    $ticketPriceFloat,
                    $stallPriceFloat,
                    $ticketsAvailableInt,
                    $attendeeContributionTarget,
                    $vendorContributionTargetFloat,
                ]);

                $eventId = (int)$pdo->lastInsertId();

                if (!empty($plannedItems)) {
                    $itemStmt = $pdo->prepare(
                        'INSERT INTO event_budget_items (event_id, item_name, planned_amount, sort_order) VALUES (?, ?, ?, ?)'
                    );
                    foreach ($plannedItems as $index => $item) {
                        $itemStmt->execute([$eventId, $item['name'], $item['amount'], $index + 1]);
                    }
                }

                $ticketTypeStmt = $pdo->prepare(
                    'INSERT INTO event_ticket_types (event_id, ticket_type, price, description, tickets_remaining) VALUES (?, ?, ?, ?, ?)'
                );
                foreach ($allowedTicketTypes as $ticketType) {
                    $ticketTypeStmt->execute([
                        $eventId,
                        $ticketType,
                        (float)($ticketTypePrices[$ticketType] ?? 0),
                        $ticketTypeDescriptions[$ticketType] !== '' ? $ticketTypeDescriptions[$ticketType] : null,
                        (int)($ticketTypeRemaining[$ticketType] ?? 0),
                    ]);
                }

                $pdo->commit();

                audit_log(
                    $pdo,
                    (int)$_SESSION['user_id'],
                    (string)$_SESSION['role'],
                    'event.create',
                    'event',
                    (string)$eventId,
                    [
                        'title' => $title,
                        'event_date' => $eventDate,
                        'venue' => $venue,
                        'category' => $category,
                        'city' => $city,
                        'event_type' => $eventType,
                        'image_url' => $finalImageUrl,
                        'budget_total' => $budgetTotalFloat,
                        'budget_committed' => $plannedCommitted,
                        'tickets_available' => $ticketsAvailableInt,
                        'planned_item_count' => count($plannedItems),
                        'ticket_type_prices' => $ticketTypePrices,
                        'stall_price' => $stallPriceFloat,
                        'attendee_contribution_target' => $attendeeContributionTarget,
                        'vendor_contribution_target' => $vendorContributionTargetFloat,
                    ]
                );

                $_SESSION['flash_success'] = 'Event created successfully!';
                header('Location: create_event.php');
                exit;
            } catch (Throwable $e) {
                audit_log(
                    $pdo,
                    (int)$_SESSION['user_id'],
                    (string)$_SESSION['role'],
                    'event.create_failed',
                    'event',
                    null,
                    ['reason' => 'exception']
                );
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $flashError = 'Could not create event right now. Please try again.';
            }
        }
    } elseif (isset($_POST['update_event_banner'])) {
        $updateEventId = filter_input(INPUT_POST, 'event_id', FILTER_VALIDATE_INT);
        $newBannerUrl = trim((string)($_POST['image_url'] ?? ''));
        $hasBannerUrlInput = $newBannerUrl !== '';
        $hasBannerFileInput = isset($_FILES['banner_file'])
            && is_array($_FILES['banner_file'])
            && (int)($_FILES['banner_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;

        if (!$updateEventId) {
            $flashError = 'Invalid event selected for banner update.';
        } else {
            try {
                if ($hasBannerUrlInput && $hasBannerFileInput) {
                    $flashError = 'Use either a banner URL or an uploaded banner file, not both.';
                }

                $uploadedBannerPath = null;
                if (isset($_FILES['banner_file']) && is_array($_FILES['banner_file'])) {
                    $uploadedBannerPath = storeEventBannerUpload($_FILES['banner_file'], $flashError);
                }

                if ($flashError === '' && $newBannerUrl !== '') {
                    $normalized = normalizeBannerInputUrl($newBannerUrl);
                    if ($normalized === null) {
                        $flashError = 'Banner URL must be a valid URL or an uploads path.';
                    } else {
                        $newBannerUrl = $normalized;
                    }
                }

                if ($flashError === '' && $uploadedBannerPath === null && $newBannerUrl === '') {
                    $flashError = 'Provide a banner URL or upload a banner file.';
                }

                if ($flashError === '') {
                    $finalBanner = $uploadedBannerPath ?? $newBannerUrl;
                    $stmt = $pdo->prepare('UPDATE events SET image_url = ? WHERE event_id = ? AND planner_id = ? LIMIT 1');
                    $stmt->execute([$finalBanner, $updateEventId, $_SESSION['user_id']]);

                    if ($stmt->rowCount() > 0) {
                        audit_log(
                            $pdo,
                            (int)$_SESSION['user_id'],
                            (string)$_SESSION['role'],
                            'event.banner_update',
                            'event',
                            (string)$updateEventId,
                            ['image_url' => $finalBanner]
                        );
                        $_SESSION['flash_success'] = 'Event banner updated successfully.';
                        header('Location: create_event.php?event_view=' . urlencode($eventView) . '#event-views-anchor');
                        exit;
                    }

                    $flashError = 'Event not found or you do not have permission to update banner.';
                }
            } catch (Throwable $e) {
                $flashError = 'Could not update event banner right now. Please try again.';
            }
        }
    } elseif (isset($_POST['update_event_details'])) {
        $updateEventId = filter_input(INPUT_POST, 'event_id', FILTER_VALIDATE_INT);
        $newCategory = trim((string)($_POST['category'] ?? ''));
        $newVenue = trim((string)($_POST['venue'] ?? ''));
        $newCity = trim((string)($_POST['city'] ?? ''));
        $newStallPriceRaw = trim((string)($_POST['stall_price'] ?? '0.00'));

        if (!$updateEventId) {
            $flashError = 'Invalid event selected for details update.';
        } elseif (strlen($newCategory) > 64) {
            $flashError = 'Category must be 64 characters or fewer.';
        } elseif (strlen($newVenue) > 190) {
            $flashError = 'Venue must be 190 characters or fewer.';
        } elseif (strlen($newCity) > 120) {
            $flashError = 'City must be 120 characters or fewer.';
        } elseif ($newStallPriceRaw === '' || !is_numeric($newStallPriceRaw) || (float)$newStallPriceRaw < 0) {
            $flashError = 'Stall price must be a valid non-negative amount.';
        } else {
            try {
                $stmt = $pdo->prepare(
                    'UPDATE events SET category = ?, venue = ?, city = ?, stall_price = ? WHERE event_id = ? AND planner_id = ? AND archived_at IS NULL LIMIT 1'
                );
                $stmt->execute([
                    $newCategory !== '' ? $newCategory : null,
                    $newVenue !== '' ? $newVenue : null,
                    $newCity !== '' ? $newCity : null,
                    (float)$newStallPriceRaw,
                    $updateEventId,
                    $_SESSION['user_id'],
                ]);

                if ($stmt->rowCount() > 0) {
                    audit_log(
                        $pdo,
                        (int)$_SESSION['user_id'],
                        (string)$_SESSION['role'],
                        'event.details_update',
                        'event',
                        (string)$updateEventId,
                        [
                            'category' => $newCategory,
                            'venue' => $newVenue,
                            'city' => $newCity,
                            'stall_price' => (float)$newStallPriceRaw,
                        ]
                    );
                    $_SESSION['flash_success'] = 'Event details updated successfully.';
                    header('Location: create_event.php?event_view=' . urlencode($eventView) . '#event-views-anchor');
                    exit;
                }

                $flashError = 'Event not found or no details were changed.';
            } catch (Throwable $e) {
                $flashError = 'Could not update event details right now. Please try again.';
            }
        }
    } elseif (isset($_POST['update_event_ticket_types'])) {
        $updateEventId = filter_input(INPUT_POST, 'event_id', FILTER_VALIDATE_INT);
        $formTicketTypePrices = $_POST['ticket_type_price'] ?? [];
        $formTicketTypeRemaining = $_POST['ticket_type_remaining'] ?? [];
        $formTicketTypeDescriptions = $_POST['ticket_type_description'] ?? [];
        if (!is_array($formTicketTypePrices)) {
            $formTicketTypePrices = [];
        }
        if (!is_array($formTicketTypeRemaining)) {
            $formTicketTypeRemaining = [];
        }
        if (!is_array($formTicketTypeDescriptions)) {
            $formTicketTypeDescriptions = [];
        }

        $allowedTicketTypes = ['early_bird', 'regular', 'vip', 'vvip'];
        $normalizedTicketTypes = [];
        $normalizedTicketRemaining = [];
        $normalizedTicketTypeDescriptions = [];

        if (!$updateEventId) {
            $flashError = 'Invalid event selected for ticket update.';
        } else {
            foreach ($allowedTicketTypes as $ticketType) {
                $rawPrice = trim((string)($formTicketTypePrices[$ticketType] ?? '0.00'));
                if ($rawPrice === '' || !is_numeric($rawPrice) || (float)$rawPrice < 0) {
                    $flashError = 'Each ticket type price must be a valid non-negative amount.';
                    break;
                }

                $rawRemaining = trim((string)($formTicketTypeRemaining[$ticketType] ?? '0'));
                if ($rawRemaining === '' || !ctype_digit($rawRemaining)) {
                    $flashError = 'Each ticket type remaining value must be a valid non-negative whole number.';
                    break;
                }

                $normalizedTicketTypes[$ticketType] = (float)$rawPrice;
                $normalizedTicketRemaining[$ticketType] = (int)$rawRemaining;
                $normalizedTicketTypeDescriptions[$ticketType] = trim((string)($formTicketTypeDescriptions[$ticketType] ?? ''));
            }
        }

        if ($flashError === '') {
            try {
                $stmt = $pdo->prepare('SELECT event_id FROM events WHERE event_id = ? AND planner_id = ? AND archived_at IS NULL LIMIT 1');
                $stmt->execute([$updateEventId, $_SESSION['user_id']]);
                if (!$stmt->fetch()) {
                    $flashError = 'Event not found or cannot be updated.';
                } else {
                    $pdo->beginTransaction();

                    $stmt = $pdo->prepare('UPDATE events SET ticket_price = ? WHERE event_id = ? AND planner_id = ? LIMIT 1');
                    $stmt->execute([$normalizedTicketTypes['regular'] ?? 0, $updateEventId, $_SESSION['user_id']]);

                    $stmt = $pdo->prepare('UPDATE events SET tickets_available = ? WHERE event_id = ? AND planner_id = ? LIMIT 1');
                    $stmt->execute([array_sum($normalizedTicketRemaining), $updateEventId, $_SESSION['user_id']]);

                    $stmt = $pdo->prepare('DELETE FROM event_ticket_types WHERE event_id = ?');
                    $stmt->execute([$updateEventId]);

                    $typeStmt = $pdo->prepare('INSERT INTO event_ticket_types (event_id, ticket_type, price, description, tickets_remaining) VALUES (?, ?, ?, ?, ?)');
                    foreach ($allowedTicketTypes as $ticketType) {
                        $typeStmt->execute([
                            $updateEventId,
                            $ticketType,
                            $normalizedTicketTypes[$ticketType] ?? 0,
                            $normalizedTicketTypeDescriptions[$ticketType] !== '' ? $normalizedTicketTypeDescriptions[$ticketType] : null,
                            $normalizedTicketRemaining[$ticketType] ?? 0,
                        ]);
                    }

                    $pdo->commit();

                    audit_log(
                        $pdo,
                        (int)$_SESSION['user_id'],
                        (string)$_SESSION['role'],
                        'event.ticket_types_update',
                        'event',
                        (string)$updateEventId,
                        ['ticket_type_prices' => $normalizedTicketTypes]
                    );

                    $_SESSION['flash_success'] = 'Event ticket types updated successfully.';
                    header('Location: create_event.php?event_view=' . urlencode($eventView) . '#event-views-anchor');
                    exit;
                }
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $flashError = 'Could not update ticket types right now. Please try again.';
            }
        }
    } elseif (isset($_POST['delete_event'])) {
        $deleteEventId = filter_input(INPUT_POST, 'event_id', FILTER_VALIDATE_INT);

        if (!$deleteEventId) {
            $flashError = 'Invalid event selected for deletion.';
        } else {
            try {
                $stmt = $pdo->prepare('SELECT title FROM events WHERE event_id = ? AND planner_id = ? AND archived_at IS NULL LIMIT 1');
                $stmt->execute([$deleteEventId, $_SESSION['user_id']]);
                $eventToDelete = $stmt->fetch();

                if (!$eventToDelete) {
                    $flashError = 'Event not found or you do not have permission to delete it.';
                } else {
                    $stmt = $pdo->prepare('UPDATE events SET archived_at = NOW() WHERE event_id = ? AND planner_id = ? AND archived_at IS NULL');
                    $stmt->execute([$deleteEventId, $_SESSION['user_id']]);

                    audit_log(
                        $pdo,
                        (int)$_SESSION['user_id'],
                        (string)$_SESSION['role'],
                        'event.archive',
                        'event',
                        (string)$deleteEventId,
                        ['title' => (string)$eventToDelete['title']]
                    );

                    $_SESSION['flash_success'] = 'Event archived successfully.';
                    header('Location: create_event.php');
                    exit;
                }
            } catch (Throwable $e) {
                audit_log(
                    $pdo,
                    (int)$_SESSION['user_id'],
                    (string)$_SESSION['role'],
                    'event.archive_failed',
                    'event',
                    $deleteEventId ? (string)$deleteEventId : null,
                    ['reason' => 'exception']
                );
                $flashError = 'Could not archive event right now. Please try again.';
            }
        }
    } elseif (isset($_POST['permanently_delete_event'])) {
        $permanentDeleteEventId = filter_input(INPUT_POST, 'event_id', FILTER_VALIDATE_INT);

        if (!$permanentDeleteEventId) {
            $flashError = 'Invalid event selected for permanent deletion.';
        } else {
            try {
                $stmt = $pdo->prepare('SELECT title, archived_at FROM events WHERE event_id = ? AND planner_id = ? LIMIT 1');
                $stmt->execute([$permanentDeleteEventId, $_SESSION['user_id']]);
                $eventToDelete = $stmt->fetch();

                if (!$eventToDelete) {
                    $flashError = 'Event not found or you do not have permission to delete it.';
                } else {
                    $stmt = $pdo->prepare('DELETE FROM events WHERE event_id = ? AND planner_id = ? LIMIT 1');
                    $stmt->execute([$permanentDeleteEventId, $_SESSION['user_id']]);

                    audit_log(
                        $pdo,
                        (int)$_SESSION['user_id'],
                        (string)$_SESSION['role'],
                        'event.delete_permanent',
                        'event',
                        (string)$permanentDeleteEventId,
                        [
                            'title' => (string)$eventToDelete['title'],
                            'was_archived' => !empty($eventToDelete['archived_at']),
                        ]
                    );

                    $_SESSION['flash_success'] = 'Event permanently deleted.';
                    header('Location: create_event.php');
                    exit;
                }
            } catch (Throwable $e) {
                audit_log(
                    $pdo,
                    (int)$_SESSION['user_id'],
                    (string)$_SESSION['role'],
                    'event.delete_permanent_failed',
                    'event',
                    $permanentDeleteEventId ? (string)$permanentDeleteEventId : null,
                    ['reason' => 'exception']
                );
                $flashError = 'Could not permanently delete event right now. Please try again.';
            }
        }
    } elseif (isset($_POST['restore_event'])) {
        $restoreEventId = filter_input(INPUT_POST, 'event_id', FILTER_VALIDATE_INT);

        if (!$restoreEventId) {
            $flashError = 'Invalid event selected for restore.';
        } else {
            try {
                $stmt = $pdo->prepare('SELECT title FROM events WHERE event_id = ? AND planner_id = ? AND archived_at IS NOT NULL LIMIT 1');
                $stmt->execute([$restoreEventId, $_SESSION['user_id']]);
                $eventToRestore = $stmt->fetch();

                if (!$eventToRestore) {
                    $flashError = 'Archived event not found or you do not have permission to restore it.';
                } else {
                    $stmt = $pdo->prepare('UPDATE events SET archived_at = NULL WHERE event_id = ? AND planner_id = ? AND archived_at IS NOT NULL');
                    $stmt->execute([$restoreEventId, $_SESSION['user_id']]);

                    audit_log(
                        $pdo,
                        (int)$_SESSION['user_id'],
                        (string)$_SESSION['role'],
                        'event.restore',
                        'event',
                        (string)$restoreEventId,
                        ['title' => (string)$eventToRestore['title']]
                    );

                    $_SESSION['flash_success'] = 'Event restored successfully.';
                    header('Location: create_event.php');
                    exit;
                }
            } catch (Throwable $e) {
                audit_log(
                    $pdo,
                    (int)$_SESSION['user_id'],
                    (string)$_SESSION['role'],
                    'event.restore_failed',
                    'event',
                    $restoreEventId ? (string)$restoreEventId : null,
                    ['reason' => 'exception']
                );
                $flashError = 'Could not restore event right now. Please try again.';
            }
        }
    } elseif (isset($_POST['download_budget_preview'])) {
        $downloadEventId = filter_input(INPUT_POST, 'event_id', FILTER_VALIDATE_INT);

        if (!$downloadEventId) {
            $flashError = 'Invalid event selected for budget preview download.';
        } else {
            try {
                $stmt = $pdo->prepare(
                    "SELECT e.event_id, e.title, e.event_date, e.budget_total, e.budget_committed,
                            COALESCE(e.ticket_revenue, 0) AS ticket_revenue,
                            COALESCE(e.tickets_available, 200) AS tickets_available,
                            COUNT(DISTINCT a.attendance_id) AS attendee_count,
                            COALESCE(SUM(CASE WHEN b.status = 'confirmed' THEN b.platform_fee ELSE 0 END), 0) AS vendor_revenue_received,
                            COALESCE(SUM(CASE WHEN t.status = 'paid' THEN COALESCE(t.amount, 0) ELSE 0 END), 0) AS paid_transactions_total,
                            COALESCE((SELECT SUM(es.contribution_amount) FROM event_sponsorships es WHERE es.event_id = e.event_id), 0) AS sponsorship_received
                     FROM events e
                     LEFT JOIN attendances a ON a.event_id = e.event_id AND a.status IN ('registered', 'attended')
                     LEFT JOIN bookings b ON b.event_id = e.event_id
                     LEFT JOIN transactions t ON t.booking_id = b.booking_id
                     WHERE e.event_id = ? AND e.planner_id = ?
                     GROUP BY e.event_id, e.title, e.event_date, e.budget_total, e.budget_committed, e.ticket_revenue, e.tickets_available"
                );
                $stmt->execute([$downloadEventId, $_SESSION['user_id']]);
                $eventSummary = $stmt->fetch();

                if (!$eventSummary) {
                    $flashError = 'Event not found or you do not have permission to download its budget preview.';
                } else {
                    $stmt = $pdo->prepare(
                        "SELECT item_name, planned_amount, spent_amount
                         FROM event_budget_items
                         WHERE event_id = ?
                         ORDER BY sort_order ASC, item_id ASC"
                    );
                    $stmt->execute([$downloadEventId]);
                    $budgetItems = $stmt->fetchAll();

                    $moneyReceived = (float)($eventSummary['ticket_revenue'] ?? 0)
                        + (float)($eventSummary['vendor_revenue_received'] ?? 0)
                        + (float)($eventSummary['sponsorship_received'] ?? 0);
                    $moneySpent = (float)($eventSummary['paid_transactions_total'] ?? 0)
                        + (float)($eventSummary['sponsorship_received'] ?? 0);
                    foreach ($budgetItems as $budgetItem) {
                        $moneySpent += (float)($budgetItem['spent_amount'] ?? 0);
                    }
                    $cashLeft = max(0, $moneyReceived - $moneySpent);

                    $safeTitle = preg_replace('/[^a-zA-Z0-9_-]+/', '_', (string)($eventSummary['title'] ?? 'event'));
                    if ($safeTitle === null || $safeTitle === '') {
                        $safeTitle = 'event';
                    }

                    $pdfLines = [
                        'Event Budget Preview',
                        'Event ID: ' . (string)$eventSummary['event_id'],
                        'Title: ' . (string)$eventSummary['title'],
                        'Event Date: ' . (string)$eventSummary['event_date'],
                        'Ticket Capacity: ' . (string)$eventSummary['attendee_count'] . ' / ' . (string)$eventSummary['tickets_available'],
                        '',
                        'Budget Plan: KES ' . number_format((float)$eventSummary['budget_total'], 2, '.', ''),
                        'Planned Spend: KES ' . number_format((float)$eventSummary['budget_committed'], 2, '.', ''),
                        'Attendee Money: KES ' . number_format((float)$eventSummary['ticket_revenue'], 2, '.', ''),
                        'Vendor Money: KES ' . number_format((float)$eventSummary['vendor_revenue_received'], 2, '.', ''),
                        'Sponsorships (Assumed Paid): KES ' . number_format((float)$eventSummary['sponsorship_received'], 2, '.', ''),
                        'Cash at Use (Auto): KES ' . number_format($moneyReceived, 2, '.', ''),
                        'Money Received: KES ' . number_format($moneyReceived, 2, '.', ''),
                        'Money Spent (Incl Sponsorships): KES ' . number_format($moneySpent, 2, '.', ''),
                        'Cash Left: KES ' . number_format($cashLeft, 2, '.', ''),
                        '',
                        'Planned Budget Items',
                    ];

                    if (empty($budgetItems)) {
                        $pdfLines[] = 'No planned budget items found.';
                    } else {
                        foreach ($budgetItems as $budgetItem) {
                            $pdfLines[] = '- ' . (string)($budgetItem['item_name'] ?? '')
                                . ' | Planned: KES ' . number_format((float)($budgetItem['planned_amount'] ?? 0), 2, '.', '')
                                . ' | Spent: KES ' . number_format((float)($budgetItem['spent_amount'] ?? 0), 2, '.', '');
                        }
                    }

                    $pdfBytes = buildSimplePdfDocument($pdfLines);

                    header('Content-Type: application/pdf');
                    header('Content-Disposition: attachment; filename="budget_preview_' . $downloadEventId . '_' . $safeTitle . '.pdf"');
                    header('Content-Length: ' . strlen($pdfBytes));
                    echo $pdfBytes;
                    exit;
                }
            } catch (Throwable $e) {
                $flashError = 'Could not download budget preview right now. Please try again.';
            }
        }
    } elseif (isset($_POST['save_item_spent'])) {
        $entryEventId = filter_input(INPUT_POST, 'event_id', FILTER_VALIDATE_INT);
        $itemIds = $_POST['item_id'] ?? [];
        $spentAmounts = $_POST['item_spent_amount'] ?? [];

        if (!$entryEventId || !is_array($itemIds) || !is_array($spentAmounts) || count($itemIds) !== count($spentAmounts)) {
            $flashError = 'Invalid item spending request.';
        } else {
            try {
                $stmt = $pdo->prepare(
                    "SELECT e.event_id, e.ticket_revenue,
                            COALESCE(SUM(CASE WHEN b.status = 'confirmed' THEN b.platform_fee ELSE 0 END), 0) AS vendor_revenue_received,
                            COALESCE((SELECT SUM(es.contribution_amount) FROM event_sponsorships es WHERE es.event_id = e.event_id), 0) AS sponsorship_received
                     FROM events e
                     LEFT JOIN bookings b ON b.event_id = e.event_id
                     WHERE e.event_id = ? AND e.planner_id = ? AND e.archived_at IS NULL
                     GROUP BY e.event_id"
                );
                $stmt->execute([$entryEventId, $_SESSION['user_id']]);
                $eventRow = $stmt->fetch();

                if (!$eventRow) {
                    $flashError = 'Event not found or cannot be updated.';
                } else {
                    $stmt = $pdo->prepare(
                        "SELECT item_id, planned_amount
                         FROM event_budget_items
                         WHERE event_id = ?
                         ORDER BY sort_order ASC, item_id ASC"
                    );
                    $stmt->execute([$entryEventId]);
                    $items = $stmt->fetchAll();

                    $itemsById = [];
                    foreach ($items as $item) {
                        $itemsById[(int)$item['item_id']] = (float)$item['planned_amount'];
                    }

                    if (empty($itemsById)) {
                        $flashError = 'No planned budget items found for this event.';
                    } else {
                        $updates = [];
                        $totalItemSpent = 0.0;
                        foreach ($itemIds as $index => $itemIdRaw) {
                            $itemId = (int)$itemIdRaw;
                            $spentRaw = trim((string)($spentAmounts[$index] ?? '0'));
                            $spent = is_numeric($spentRaw) ? (float)$spentRaw : -1;

                            if (!isset($itemsById[$itemId])) {
                                continue;
                            }
                            $planned = $itemsById[$itemId];
                            if ($spent < 0 || $spent > $planned) {
                                $flashError = 'Spent amount per item must be between 0 and planned amount.';
                                break;
                            }
                            $updates[$itemId] = $spent;
                            $totalItemSpent += $spent;
                        }

                        if ($flashError === '') {
                            $stmt = $pdo->prepare(
                                "SELECT
                                    COALESCE(SUM(CASE WHEN t.status = 'paid' THEN COALESCE(t.amount, 0) ELSE 0 END), 0) AS paid_transactions_total
                                 FROM bookings b
                                 LEFT JOIN transactions t ON t.booking_id = b.booking_id
                                 WHERE b.event_id = ?"
                            );
                            $stmt->execute([$entryEventId]);
                            $paidTransactions = (float)$stmt->fetchColumn();

                            $moneyReceived = (float)($eventRow['ticket_revenue'] ?? 0)
                                + (float)($eventRow['vendor_revenue_received'] ?? 0)
                                + (float)($eventRow['sponsorship_received'] ?? 0);

                            $moneySpentCandidate = $paidTransactions
                                + $totalItemSpent
                                + (float)($eventRow['sponsorship_received'] ?? 0);
                            if ($moneySpentCandidate > $moneyReceived) {
                                $flashError = 'Cannot save item spending. Total money spent would be higher than money received.';
                            } else {
                                $pdo->beginTransaction();
                                $stmt = $pdo->prepare('UPDATE event_budget_items SET spent_amount = ? WHERE item_id = ? AND event_id = ?');
                                foreach ($updates as $itemId => $spent) {
                                    $stmt->execute([$spent, $itemId, $entryEventId]);
                                }
                                $pdo->commit();

                                audit_log(
                                    $pdo,
                                    (int)$_SESSION['user_id'],
                                    (string)$_SESSION['role'],
                                    'event.item_spent_updated',
                                    'event',
                                    (string)$entryEventId,
                                    ['item_count' => count($updates), 'total_item_spent' => $totalItemSpent]
                                );

                                $_SESSION['flash_success'] = 'Item spending updated.';
                                header('Location: create_event.php?event_view=' . urlencode($eventView) . '#event-views-anchor');
                                exit;
                            }
                        }
                    }
                }
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $flashError = 'Could not save item spending right now. Please try again.';
            }
        }
    } elseif (isset($_POST['add_committed_paid'])) {
        $flashError = 'Use the planned budget items section to record money spent per item.';
    } elseif (isset($_POST['add_cash_at_use'])) {
        $flashSuccess = 'Cash at Use is now auto-calculated from attendee payments, vendor payments, and sponsorships.';
    }
}

// Fetch existing events
try {
    $stmt = $pdo->prepare(
        "SELECT e.event_id, e.title, e.event_date, e.budget_total, e.budget_committed, e.ticket_revenue,
            e.ticket_price, COALESCE(e.stall_price, 0) AS stall_price, COALESCE(e.tickets_available, 200) AS tickets_available,
            COALESCE(e.category, '') AS category,
            COALESCE(e.venue, '') AS venue,
            COALESCE(e.city, '') AS city,
            COALESCE(e.event_type, 'in_person') AS event_type,
            COALESCE(e.image_url, '') AS image_url,
                COUNT(DISTINCT a.attendance_id) AS attendee_count,
                COALESCE(adj_sponsor.total_sponsorship_received, 0) AS sponsorship_received
         FROM events e
         LEFT JOIN attendances a ON a.event_id = e.event_id AND a.status IN ('registered', 'attended')
         LEFT JOIN (
             SELECT event_id, SUM(contribution_amount) AS total_sponsorship_received
             FROM event_sponsorships
             GROUP BY event_id
         ) adj_sponsor ON adj_sponsor.event_id = e.event_id
         WHERE e.planner_id = ? AND e.archived_at IS NULL
         GROUP BY e.event_id, e.title, e.event_date, e.budget_total, e.budget_committed, e.ticket_revenue, e.ticket_price, e.stall_price, e.tickets_available, e.category, e.venue, e.city, e.event_type, e.image_url, adj_sponsor.total_sponsorship_received
         ORDER BY e.event_date DESC"
    );
    $stmt->execute([$_SESSION['user_id']]);
    $events = $stmt->fetchAll();

    $stmt = $pdo->prepare('SELECT event_id, title, event_date, budget_total, COALESCE(category, "") AS category, COALESCE(venue, "") AS venue, COALESCE(city, "") AS city, COALESCE(event_type, "in_person") AS event_type, COALESCE(tickets_available, 200) AS tickets_available, COALESCE(image_url, "") AS image_url, archived_at FROM events WHERE planner_id = ? AND archived_at IS NOT NULL ORDER BY archived_at DESC');
    $stmt->execute([$_SESSION['user_id']]);
    $archivedEvents = $stmt->fetchAll();

    $stmt = $pdo->prepare(
        "SELECT
            e.event_id,
            COUNT(b.booking_id) AS total_bookings,
            SUM(CASE WHEN b.status = 'pending' THEN 1 ELSE 0 END) AS pending_bookings,
            SUM(CASE WHEN COALESCE(t.status, 'pending') = 'pending' THEN 1 ELSE 0 END) AS pending_payments,
            SUM(CASE WHEN t.status = 'failed' THEN 1 ELSE 0 END) AS failed_payments,
            COALESCE(SUM(CASE WHEN t.status = 'paid' THEN COALESCE(t.amount, 0) ELSE 0 END), 0) AS paid_transactions_total,
            COALESCE(SUM(CASE WHEN b.status = 'confirmed' THEN b.platform_fee ELSE 0 END), 0) AS vendor_revenue_received
         FROM events e
         LEFT JOIN bookings b ON b.event_id = e.event_id
         LEFT JOIN transactions t ON t.booking_id = b.booking_id
            WHERE e.planner_id = ? AND e.archived_at IS NULL
         GROUP BY e.event_id"
    );
    $stmt->execute([$_SESSION['user_id']]);
    foreach ($stmt->fetchAll() as $row) {
        $eventId = (int)$row['event_id'];
        $eventOpsById[$eventId] = [
            'total_bookings' => (int)($row['total_bookings'] ?? 0),
            'pending_bookings' => (int)($row['pending_bookings'] ?? 0),
            'pending_payments' => (int)($row['pending_payments'] ?? 0),
            'failed_payments' => (int)($row['failed_payments'] ?? 0),
            'paid_transactions_total' => (float)($row['paid_transactions_total'] ?? 0),
            'vendor_revenue_received' => (float)($row['vendor_revenue_received'] ?? 0),
            'unread_vendor_messages' => 0,
        ];
    }

    $stmt = $pdo->prepare(
        "SELECT
            b.event_id,
            COUNT(DISTINCT m.message_id) AS unread_vendor_messages
         FROM bookings b
         JOIN services s ON s.service_id = b.service_id
         JOIN vendors v ON v.vendor_id = s.vendor_id
         JOIN events e ON e.event_id = b.event_id
         JOIN messages m
           ON m.vendor_user_id = v.user_id
          AND m.planner_user_id = ?
          AND m.sender_role = 'vendor'
          AND m.is_read = 0
            WHERE e.planner_id = ? AND e.archived_at IS NULL
         GROUP BY b.event_id"
    );
    $stmt->execute([$_SESSION['user_id'], $_SESSION['user_id']]);
    foreach ($stmt->fetchAll() as $row) {
        $eventId = (int)$row['event_id'];
        if (!isset($eventOpsById[$eventId])) {
            $eventOpsById[$eventId] = [
                'total_bookings' => 0,
                'pending_bookings' => 0,
                'pending_payments' => 0,
                'failed_payments' => 0,
                'paid_transactions_total' => 0.0,
                'vendor_revenue_received' => 0.0,
                'unread_vendor_messages' => 0,
            ];
        }
        $eventOpsById[$eventId]['unread_vendor_messages'] = (int)($row['unread_vendor_messages'] ?? 0);
    }

    if (!empty($events)) {
        $eventIds = array_map(static fn ($e) => (int)$e['event_id'], $events);
        $placeholders = implode(',', array_fill(0, count($eventIds), '?'));
        $stmt = $pdo->prepare(
            "SELECT item_id, event_id, item_name, planned_amount, spent_amount
             FROM event_budget_items
             WHERE event_id IN ($placeholders)
             ORDER BY event_id ASC, sort_order ASC, item_id ASC"
        );
        $stmt->execute($eventIds);
        foreach ($stmt->fetchAll() as $row) {
            $eventId = (int)$row['event_id'];
            if (!isset($eventBudgetItemsByEventId[$eventId])) {
                $eventBudgetItemsByEventId[$eventId] = [];
            }
            $eventBudgetItemsByEventId[$eventId][] = [
                'item_id' => (int)$row['item_id'],
                'item_name' => (string)$row['item_name'],
                'planned_amount' => (float)$row['planned_amount'],
                'spent_amount' => (float)($row['spent_amount'] ?? 0),
            ];
        }

        $stmt = $pdo->prepare(
            "SELECT event_id, ticket_type, price, COALESCE(description, '') AS description, COALESCE(tickets_remaining, 0) AS tickets_remaining
             FROM event_ticket_types
             WHERE event_id IN ($placeholders)"
        );
        $stmt->execute($eventIds);
        foreach ($stmt->fetchAll() as $row) {
            $eventId = (int)$row['event_id'];
            if (!isset($eventTicketTypesByEventId[$eventId])) {
                $eventTicketTypesByEventId[$eventId] = [
                    'early_bird' => ['price' => 0.0, 'description' => '', 'remaining' => 0],
                    'regular' => ['price' => 0.0, 'description' => '', 'remaining' => 0],
                    'vip' => ['price' => 0.0, 'description' => '', 'remaining' => 0],
                    'vvip' => ['price' => 0.0, 'description' => '', 'remaining' => 0],
                ];
            }
            $typeKey = strtolower((string)$row['ticket_type']);
            if (isset($eventTicketTypesByEventId[$eventId][$typeKey])) {
                $eventTicketTypesByEventId[$eventId][$typeKey] = [
                    'price' => (float)($row['price'] ?? 0),
                    'description' => (string)($row['description'] ?? ''),
                    'remaining' => max(0, (int)($row['tickets_remaining'] ?? 0)),
                ];
            }
        }
    }

    foreach ($events as $event) {
        $eventId = (int)$event['event_id'];
        if (!isset($eventOpsById[$eventId])) {
            $eventOpsById[$eventId] = [
                'total_bookings' => 0,
                'pending_bookings' => 0,
                'pending_payments' => 0,
                'failed_payments' => 0,
                'paid_transactions_total' => 0.0,
                'vendor_revenue_received' => 0.0,
                'unread_vendor_messages' => 0,
            ];
        }

        $ops = $eventOpsById[$eventId];
        $availableFundsReceived = (float)$event['ticket_revenue']
            + (float)($ops['vendor_revenue_received'] ?? 0)
            + (float)($event['sponsorship_received'] ?? 0);
        $hasBudgetOverrun = $availableFundsReceived < (float)$event['budget_total'];
        if ($hasBudgetOverrun || $ops['pending_bookings'] > 0 || $ops['pending_payments'] > 0 || $ops['failed_payments'] > 0 || $ops['unread_vendor_messages'] > 0) {
            $eventsNeedingAttention++;
        }
    }
} catch (Throwable $e) {
    if ($flashError === '') $flashError = 'Unable to load events.';
}

$totalEvents = count($events);
$totalBudgetAll = array_sum(array_column($events, 'budget_total'));
$draftBudgetTotal = is_numeric($budgetTotal) ? (float)$budgetTotal : 0.00;
$draftRegularTicketPrice = is_numeric((string)($ticketTypePrices['regular'] ?? '0')) ? (float)$ticketTypePrices['regular'] : 0.00;
$draftExpectedAttendees = is_numeric($expectedAttendees) ? max(0, (int)$expectedAttendees) : 0;
$draftVendorContribution = is_numeric($vendorContributionTarget) ? (float)$vendorContributionTarget : 0.00;
$draftAttendeeContribution = $draftRegularTicketPrice * $draftExpectedAttendees;
$draftCommittedAtCreation = 0.00;
foreach ($budgetItemAmounts as $amountRaw) {
    if (is_numeric($amountRaw) && (float)$amountRaw >= 0) {
        $draftCommittedAtCreation += (float)$amountRaw;
    }
}
$draftAvailableAtCreation = max(0, $draftBudgetTotal - $draftCommittedAtCreation);
$draftProjectedFunds = $draftAttendeeContribution + $draftVendorContribution;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="../assets/style.css">
    <title>Planora &middot; Events</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,400;14..32,500;14..32,600;14..32,700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Inter', sans-serif; }
        body { background: #F5F5F5; color: #2D2D2D; min-height: 100vh; }
        .header { background: #6C63FF; padding: 16px 28px; display: flex; justify-content: space-between; align-items: center; color: #fff; }
        .brand { font-size: 1.5rem; font-weight: 700; letter-spacing: 0.5px; }
        .logout-btn { background: rgba(255,255,255,0.2); color: #fff; padding: 8px 16px; border-radius: 8px; text-decoration: none; font-weight: 500; transition: 0.2s; }
        .logout-btn:hover { background: rgba(255,255,255,0.35); }
        .nav-links { display: flex; gap: 12px; padding: 16px 28px; background: #fff; border-bottom: 1px solid #EAEAEA; flex-wrap: wrap; }
        .nav-links a { text-decoration: none; background: #F0EEFF; color: #6C63FF; padding: 8px 16px; border-radius: 20px; font-size: 0.9rem; font-weight: 500; transition: 0.2s; }
        .nav-links a:hover, .nav-links a.active { background: #6C63FF; color: #fff; }
        .container { max-width: 1100px; margin: 30px auto; padding: 0 20px; }
        .messages { margin-bottom: 20px; }
        .alert { padding: 12px 18px; border-radius: 10px; font-size: 0.9rem; margin-bottom: 12px; display: flex; align-items: center; gap: 10px; }
        .alert-success { background: #E8F5E9; color: #256029; border: 1px solid #B7E1B3; }
        .alert-error { background: #FFECEC; color: #A40000; border: 1px solid #F6BDBD; }
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 18px; margin-bottom: 30px; }
        .stat-card { background: #fff; border-radius: 16px; padding: 20px; box-shadow: 0 2px 8px rgba(0,0,0,0.04); display: flex; align-items: center; gap: 16px; }
        .stat-icon { width: 48px; height: 48px; border-radius: 50%; background: #F0EEFF; display: flex; align-items: center; justify-content: center; color: #6C63FF; font-size: 1.3rem; }
        .stat-value { font-size: 1.6rem; font-weight: 700; }
        .stat-label { font-size: 0.85rem; color: #777; }
        .ops-banner { background: #eef3ff; color: #2c4ea0; border: 1px solid #d9e4ff; border-radius: 10px; padding: 11px 14px; margin-bottom: 20px; font-size: 13px; }
        .card { background: #fff; border-radius: 20px; padding: 28px; margin-bottom: 30px; box-shadow: 0 4px 16px rgba(0,0,0,0.04); border: 1px solid #F0F0F0; }
        .card-title { font-size: 1.3rem; font-weight: 700; margin-bottom: 20px; display: flex; align-items: center; gap: 10px; }
        .form-grid { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 16px; align-items: end; }
        .form-group { display: flex; flex-direction: column; gap: 6px; }
        .form-group label { font-size: 0.85rem; font-weight: 600; color: #4B5563; }
        .form-input, .form-select { width: 100%; border: 1.5px solid #E0E0E0; border-radius: 10px; padding: 12px 14px; font-size: 0.95rem; transition: 0.2s; background: #FAFAFA; }
        .form-input:focus, .form-select:focus { outline: none; border-color: #6C63FF; background: #fff; }
        .btn { background: #6C63FF; color: #fff; border: none; border-radius: 10px; padding: 12px 24px; font-size: 0.95rem; font-weight: 600; cursor: pointer; transition: 0.2s; display: inline-flex; align-items: center; gap: 8px; }
        .btn:hover { background: #5A52E0; }
        .btn-outline { background: transparent; color: #6C63FF; border: 2px solid #6C63FF; }
        .btn-outline:hover { background: #6C63FF; color: #fff; }
        .btn-danger { background: #c62828; color: #fff; border: 2px solid #c62828; }
        .btn-danger:hover { background: #a61f1f; border-color: #a61f1f; }
        .budget-breakdown { margin-top: 18px; border: 1px solid #ECECEC; border-radius: 14px; background: #FAFAFF; padding: 16px; }
        .budget-breakdown-title { font-size: 1rem; font-weight: 700; color: #2D2D2D; margin-bottom: 10px; display: flex; align-items: center; gap: 8px; }
        .budget-breakdown-grid { display: grid; grid-template-columns: repeat(4, minmax(140px, 1fr)); gap: 10px; }
        .breakdown-item { background: #fff; border: 1px solid #EFEFFF; border-radius: 10px; padding: 10px 12px; }
        .breakdown-label { font-size: 12px; color: #666; margin-bottom: 4px; }
        .breakdown-value { font-size: 15px; font-weight: 700; color: #2D2D2D; }
        .breakdown-note { margin-top: 10px; font-size: 12px; color: #5B5B5B; }
        .budget-items { margin-top: 18px; border: 1px solid #ECECEC; border-radius: 14px; background: #FAFAFF; padding: 16px; }
        .budget-items-title { font-size: 1rem; font-weight: 700; margin-bottom: 10px; display: flex; align-items: center; gap: 8px; }
        .budget-item-row { display: grid; grid-template-columns: 2fr 1fr auto; gap: 10px; align-items: center; margin-bottom: 10px; }
        .budget-item-row:last-child { margin-bottom: 0; }
        .name-with-example { display: flex; flex-direction: column; gap: 4px; }
        .item-example-text { font-size: 11px; color: #6a6a6a; }
        .remove-item-btn { background: #fff; border: 1px solid #f1c2c2; color: #a13232; border-radius: 8px; padding: 10px 12px; cursor: pointer; }
        .add-item-row { margin-top: 8px; display: flex; justify-content: flex-start; gap: 10px; flex-wrap: wrap; }
        .event-list { display: flex; flex-direction: column; }
        .view-filters { display: flex; gap: 8px; margin-bottom: 16px; flex-wrap: wrap; }
        .view-filter { text-decoration: none; border: 1px solid #DCD8FF; color: #4A40BF; background: #F7F6FF; border-radius: 999px; padding: 6px 12px; font-size: 12px; font-weight: 600; }
        .view-filter.active { background: #6C63FF; color: #fff; border-color: #6C63FF; }
        .event-item { border-bottom: 1px solid #F0F0F0; }
        .event-item:last-child { border-bottom: none; }
        .event-header { display: flex; justify-content: space-between; align-items: center; padding: 16px 0; cursor: pointer; }
        .event-header:hover { background: #F9F9FF; margin: 0 -10px; padding: 16px 10px; border-radius: 10px; }
        .event-title { font-weight: 700; }
        .event-date { color: #666; font-size: 0.9rem; }
        .event-chips { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 6px; }
        .chip { font-size: 11px; font-weight: 700; border-radius: 999px; padding: 4px 9px; }
        .chip-risk { background: #ffe8e8; color: #9d2020; }
        .chip-warn { background: #fff4df; color: #a36500; }
        .chip-info { background: #ece9ff; color: #4a40bf; }
        .chip-good { background: #e8f9ef; color: #1c7a36; }
        .event-budget { font-weight: 600; }
        .event-chevron { transition: 0.3s; color: #6C63FF; }
        .event-details { display: none; padding: 0 0 16px 0; color: #555; font-size: 0.9rem; }
        .event-item.open .event-details { display: block; }
        .event-item.open .event-chevron { transform: rotate(180deg); }
        .detail-row { display: flex; gap: 40px; margin-bottom: 8px; flex-wrap: wrap; }
        .detail-label { font-weight: 600; color: #2D2D2D; }
        .event-actions-grid { display: flex; flex-direction: column; gap: 8px; margin-top: 8px; }
        .event-action-row { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; }
        .event-action-row .btn { margin: 0; }
        .event-action-row .form-input { max-width: 170px; padding: 8px 10px; }
        .adjustment-hint { flex-basis: 100%; font-size: 12px; color: #666; }
        .item-spend-box { border: 1px solid #ece8ff; border-radius: 10px; background: #faf9ff; padding: 10px; }
        .item-spend-title { font-size: 13px; font-weight: 700; color: #3f3a8f; margin-bottom: 8px; }
        .item-spend-grid { display: grid; grid-template-columns: 1.4fr 1fr 1fr; gap: 8px; align-items: center; }
        .item-spend-head { font-size: 12px; font-weight: 700; color: #626262; }
        .item-spend-grid .form-input { max-width: 100%; }
        .event-action-row-danger { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; }
        .event-action-row-danger form { margin: 0; }
        .btn[disabled] { opacity: 0.65; cursor: not-allowed; }
        .empty-state { text-align: center; padding: 30px; color: #888; }
        @media (max-width: 800px) {
            .form-grid { grid-template-columns: 1fr; }
            .budget-breakdown-grid { grid-template-columns: 1fr 1fr; }
            .budget-item-row { grid-template-columns: 1fr; }
            .event-header { flex-direction: column; align-items: flex-start; gap: 6px; }
            .event-action-row .form-input { max-width: 100%; }
            .item-spend-grid { grid-template-columns: 1fr; }
        }
        @media (max-width: 560px) {
            .budget-breakdown-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
<header class="header">
    <div class="brand">PLANORA</div>
    <a href="../logout.php" class="logout-btn"><i class="fa-solid fa-arrow-right-from-bracket"></i> Logout</a>
</header>

<div class="nav-links">
    <a href="dashboard.php"><i class="fa-solid fa-house"></i> Dashboard</a>
    <a href="browse_vendors.php"><i class="fa-solid fa-shop"></i> Browse Vendors</a>
    <a href="create_event.php" class="active"><i class="fa-solid fa-calendar-plus"></i> Events</a>
</div>

<div class="container">
    <div class="messages">
        <?php if ($flashSuccess): ?>
            <div class="alert alert-success"><i class="fa-solid fa-circle-check"></i> <?php echo htmlspecialchars($flashSuccess); ?></div>
        <?php endif; ?>
        <?php if ($flashError): ?>
            <div class="alert alert-error"><i class="fa-solid fa-circle-exclamation"></i> <?php echo htmlspecialchars($flashError); ?></div>
        <?php endif; ?>
    </div>

    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-icon"><i class="fa-solid fa-calendar-days"></i></div>
            <div>
                <div class="stat-value"><?php echo $totalEvents; ?></div>
                <div class="stat-label">Total Events</div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon"><i class="fa-solid fa-coins"></i></div>
            <div>
                <div class="stat-value">KES <?php echo number_format($totalBudgetAll); ?></div>
                <div class="stat-label">Total Budget</div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon"><i class="fa-solid fa-triangle-exclamation"></i></div>
            <div>
                <div class="stat-value"><?php echo (int)$eventsNeedingAttention; ?></div>
                <div class="stat-label">Events Needing Attention</div>
            </div>
        </div>
    </div>

    <div class="ops-banner"><i class="fa-solid fa-circle-info"></i> For each event, clear pending bookings, confirm vendor responses, and reconcile payments early so your budget stays accurate and delivery stays on schedule.</div>

    <!-- Create Event Form -->
    <section class="card">
        <h2 class="card-title"><i class="fa-solid fa-plus-circle" style="color:#6C63FF;"></i> Create New Event</h2>
        <form method="POST" class="form-grid" enctype="multipart/form-data">
            <?php echo csrf_input(); ?>
            <input type="hidden" name="create_event" value="1">
            <div class="form-group">
                <label for="title">Event Title</label>
                <input type="text" id="title" name="title" class="form-input" placeholder="e.g., Johnson Wedding" value="<?php echo htmlspecialchars($title); ?>" required>
            </div>
            <div class="form-group">
                <label for="event_date">Date</label>
                <input type="date" id="event_date" name="event_date" class="form-input" value="<?php echo htmlspecialchars($eventDate); ?>" required>
            </div>
            <div class="form-group">
                <label for="venue">Venue</label>
                <input type="text" id="venue" name="venue" class="form-input" placeholder="e.g., Sarit Expo Centre" value="<?php echo htmlspecialchars($venue); ?>">
            </div>
            <div class="form-group">
                <label for="category">Category</label>
                <input type="text" id="category" name="category" class="form-input" maxlength="64" placeholder="e.g., Conference, Wedding, Music" value="<?php echo htmlspecialchars($category); ?>">
            </div>
            <div class="form-group">
                <label for="city">City</label>
                <input type="text" id="city" name="city" class="form-input" maxlength="120" placeholder="e.g., Nairobi" value="<?php echo htmlspecialchars($city); ?>">
            </div>
            <div class="form-group">
                <label for="event_type">Event Type</label>
                <select id="event_type" name="event_type" class="form-select">
                    <option value="in_person" <?php echo $eventType === 'in_person' ? 'selected' : ''; ?>>In-person</option>
                    <option value="online" <?php echo $eventType === 'online' ? 'selected' : ''; ?>>Online</option>
                </select>
            </div>
            <div class="form-group">
                <label for="image_url">Banner Image URL</label>
                <input type="url" id="image_url" name="image_url" class="form-input" placeholder="https://example.com/banner.jpg" value="<?php echo htmlspecialchars($imageUrl); ?>">
                <div class="item-example-text">Use URL or Upload, not both.</div>
            </div>
            <div class="form-group">
                <label for="banner_file">Or Upload Banner</label>
                <input type="file" id="banner_file" name="banner_file" class="form-input" accept=".jpg,.jpeg,.png,.webp,.gif">
            </div>
            <div class="form-group">
                <label for="budget_total">Budget (KES)</label>
                <input type="number" id="budget_total" name="budget_total" step="0.01" min="0" class="form-input" placeholder="15000" value="<?php echo htmlspecialchars($budgetTotal); ?>" required>
            </div>
            <div class="form-group" style="grid-column: 1 / -1;">
                <label>Ticket Types (KES)</label>
                <div style="display:grid; grid-template-columns:repeat(4, minmax(140px, 1fr)); gap:10px;">
                    <input type="number" step="0.01" min="0" id="ticket_type_early_bird" name="ticket_type_price[early_bird]" class="form-input" placeholder="Early Bird" value="<?php echo htmlspecialchars((string)($ticketTypePrices['early_bird'] ?? '0.00')); ?>">
                    <input type="number" step="0.01" min="0" id="ticket_type_regular" name="ticket_type_price[regular]" class="form-input" placeholder="Regular" value="<?php echo htmlspecialchars((string)($ticketTypePrices['regular'] ?? '0.00')); ?>">
                    <input type="number" step="0.01" min="0" id="ticket_type_vip" name="ticket_type_price[vip]" class="form-input" placeholder="VIP" value="<?php echo htmlspecialchars((string)($ticketTypePrices['vip'] ?? '0.00')); ?>">
                    <input type="number" step="0.01" min="0" id="ticket_type_vvip" name="ticket_type_price[vvip]" class="form-input" placeholder="VVIP" value="<?php echo htmlspecialchars((string)($ticketTypePrices['vvip'] ?? '0.00')); ?>">
                </div>
                <div class="item-example-text">Attendee contribution preview uses the Regular ticket type.</div>
            </div>
            <div class="form-group" style="grid-column: 1 / -1;">
                <label>Ticket Remaining Per Type</label>
                <div style="display:grid; grid-template-columns:repeat(4, minmax(140px, 1fr)); gap:10px;">
                    <input type="number" step="1" min="0" name="ticket_type_remaining[early_bird]" class="form-input" placeholder="Early Bird Remaining" value="<?php echo htmlspecialchars((string)($ticketTypeRemaining['early_bird'] ?? '0')); ?>">
                    <input type="number" step="1" min="0" name="ticket_type_remaining[regular]" class="form-input" placeholder="Regular Remaining" value="<?php echo htmlspecialchars((string)($ticketTypeRemaining['regular'] ?? '0')); ?>">
                    <input type="number" step="1" min="0" name="ticket_type_remaining[vip]" class="form-input" placeholder="VIP Remaining" value="<?php echo htmlspecialchars((string)($ticketTypeRemaining['vip'] ?? '0')); ?>">
                    <input type="number" step="1" min="0" name="ticket_type_remaining[vvip]" class="form-input" placeholder="VVIP Remaining" value="<?php echo htmlspecialchars((string)($ticketTypeRemaining['vvip'] ?? '0')); ?>">
                </div>
                <div class="item-example-text">Total event ticket capacity is auto-calculated from these per-type remaining values.</div>
            </div>
            <div class="form-group">
                <label for="tickets_available">Tickets Available</label>
                <input type="number" step="1" min="0" id="tickets_available" name="tickets_available" class="form-input" placeholder="200" value="<?php echo htmlspecialchars($ticketsAvailable ?? '200'); ?>">
            </div>
            <div class="form-group">
                <label for="expected_attendees">Expected Attendees</label>
                <input type="number" step="1" min="0" id="expected_attendees" name="expected_attendees" class="form-input" placeholder="0" value="<?php echo htmlspecialchars($expectedAttendees ?? '0'); ?>">
            </div>
            <div class="form-group">
                <label for="vendor_contribution_target">Vendor Contribution Target (KES)</label>
                <input type="number" step="0.01" min="0" id="vendor_contribution_target" name="vendor_contribution_target" class="form-input" placeholder="0.00" value="<?php echo htmlspecialchars($vendorContributionTarget ?? '0.00'); ?>">
            </div>
            <div class="form-group">
                <label for="stall_price">Stall Price (KES) <small>(for Market events)</small></label>
                <input type="number" step="0.01" min="0" id="stall_price" name="stall_price" class="form-input" placeholder="0.00" value="<?php echo htmlspecialchars($stallPrice ?? '0.00'); ?>">
            </div>
            <div class="budget-items" style="grid-column: 1 / -1;">
                <div class="budget-items-title"><i class="fa-solid fa-list-check" style="color:#6C63FF;"></i> Planned Budget Line Items</div>
                <div id="budget_item_rows">
                    <?php
                        $rowCount = max(3, count($budgetItemNames), count($budgetItemAmounts));
                        for ($i = 0; $i < $rowCount; $i++):
                            $itemName = (string)($budgetItemNames[$i] ?? '');
                            $itemAmount = (string)($budgetItemAmounts[$i] ?? '');
                    ?>
                    <div class="budget-item-row">
                        <div class="name-with-example">
                            <input type="text" name="budget_item_name[]" class="form-input js-item-name" placeholder="Item name (e.g. Catering)" value="<?php echo htmlspecialchars($itemName); ?>">
                            <div class="item-example-text">Examples: Venue, Catering, Decor, Sound, Security</div>
                        </div>
                        <input type="number" name="budget_item_amount[]" class="form-input js-item-amount" step="0.01" min="0" placeholder="0.00" value="<?php echo htmlspecialchars($itemAmount); ?>">
                        <button type="button" class="remove-item-btn js-remove-item"><i class="fa-solid fa-xmark"></i></button>
                    </div>
                    <?php endfor; ?>
                </div>
                <div class="add-item-row">
                    <button type="button" class="btn btn-outline" id="add_budget_item"><i class="fa-solid fa-plus"></i> Add Item</button>
                    <button type="submit" class="btn"><i class="fa-solid fa-paper-plane"></i> Create Event</button>
                </div>
            </div>
        </form>

        <div class="budget-breakdown">
            <div class="budget-breakdown-title"><i class="fa-solid fa-chart-pie" style="color:#6C63FF;"></i> Budget Breakdown Preview</div>
            <div class="budget-breakdown-grid">
                <div class="breakdown-item">
                    <div class="breakdown-label">Planned Budget</div>
                    <div class="breakdown-value" id="draft_budget_total">KES <?php echo number_format($draftBudgetTotal, 2); ?></div>
                </div>
                <div class="breakdown-item">
                    <div class="breakdown-label">Committed At Creation</div>
                    <div class="breakdown-value" id="draft_committed_creation">KES <?php echo number_format($draftCommittedAtCreation, 2); ?></div>
                </div>
                <div class="breakdown-item">
                    <div class="breakdown-label">Available At Creation</div>
                    <div class="breakdown-value" id="draft_budget_available">KES <?php echo number_format($draftAvailableAtCreation, 2); ?></div>
                </div>
                <div class="breakdown-item">
                    <div class="breakdown-label">Ticket Price Per Attendee</div>
                    <div class="breakdown-value" id="draft_ticket_price">KES <?php echo number_format($draftRegularTicketPrice, 2); ?></div>
                </div>
                <div class="breakdown-item">
                    <div class="breakdown-label">Attendee Contribution (Projected)</div>
                    <div class="breakdown-value" id="draft_attendee_contribution">KES <?php echo number_format($draftAttendeeContribution, 2); ?></div>
                </div>
                <div class="breakdown-item">
                    <div class="breakdown-label">Vendor Contribution (Projected)</div>
                    <div class="breakdown-value" id="draft_vendor_contribution">KES <?php echo number_format($draftVendorContribution, 2); ?></div>
                </div>
                <div class="breakdown-item">
                    <div class="breakdown-label">Total Contributions/Revenue (Projected)</div>
                    <div class="breakdown-value" id="draft_projected_funds">KES <?php echo number_format($draftProjectedFunds, 2); ?></div>
                </div>
            </div>
            <div class="breakdown-note">Line items set initial committed budget. Contributions/revenue values are projections and actuals appear in Budget Breakdown after transactions.</div>
        </div>
    </section>

    <div id="event-views-anchor" style="position: relative; top: -10px;"></div>

    <!-- Existing Events List -->
    <?php if ($eventView === 'active' || $eventView === 'all'): ?>
    <section class="card">
        <h2 class="card-title"><i class="fa-regular fa-calendar-check" style="color:#6C63FF;"></i> My Events</h2>
        <div class="view-filters">
            <a class="view-filter <?php echo $eventView === 'active' ? 'active' : ''; ?>" href="create_event.php?event_view=active#event-views-anchor">Active</a>
            <a class="view-filter <?php echo $eventView === 'archived' ? 'active' : ''; ?>" href="create_event.php?event_view=archived#event-views-anchor">Archived</a>
            <a class="view-filter <?php echo $eventView === 'all' ? 'active' : ''; ?>" href="create_event.php?event_view=all#event-views-anchor">All</a>
        </div>
        <?php if (empty($events)): ?>
            <div class="empty-state">
                <i class="fa-regular fa-folder-open" style="font-size: 2rem; margin-bottom: 10px; display: block;"></i>
                No active events yet.
            </div>
        <?php else: ?>
            <div class="event-list">
                <?php foreach ($events as $event): ?>
                    <?php
                        $eventId = (int)$event['event_id'];
                        $ops = $eventOpsById[$eventId] ?? [
                            'total_bookings' => 0,
                            'pending_bookings' => 0,
                            'pending_payments' => 0,
                            'failed_payments' => 0,
                            'paid_transactions_total' => 0.0,
                            'vendor_revenue_received' => 0.0,
                            'unread_vendor_messages' => 0,
                        ];
                        $availableBudget = (float)$event['budget_total'] - (float)$event['budget_committed'];
                        $budgetItems = $eventBudgetItemsByEventId[$eventId] ?? [];
                        $itemSpentTotal = 0.0;
                        foreach ($budgetItems as $budgetItem) {
                            $itemSpentTotal += (float)($budgetItem['spent_amount'] ?? 0);
                        }
                        $ticketTypesForEvent = $eventTicketTypesByEventId[$eventId] ?? [
                            'early_bird' => ['price' => 0.0, 'description' => '', 'remaining' => 0],
                            'regular' => ['price' => (float)($event['ticket_price'] ?? 0), 'description' => '', 'remaining' => (int)($event['tickets_available'] ?? 0)],
                            'vip' => ['price' => 0.0, 'description' => '', 'remaining' => 0],
                            'vvip' => ['price' => 0.0, 'description' => '', 'remaining' => 0],
                        ];
                        $totalRemainingByType = (int)($ticketTypesForEvent['early_bird']['remaining'] ?? 0)
                            + (int)($ticketTypesForEvent['regular']['remaining'] ?? 0)
                            + (int)($ticketTypesForEvent['vip']['remaining'] ?? 0)
                            + (int)($ticketTypesForEvent['vvip']['remaining'] ?? 0);
                        $sponsorshipReceived = (float)($event['sponsorship_received'] ?? 0);
                        $committedPaid = (float)($ops['paid_transactions_total'] ?? 0)
                            + $itemSpentTotal
                            + $sponsorshipReceived;
                        $availableFundsReceived = (float)$event['ticket_revenue'] + (float)($ops['vendor_revenue_received'] ?? 0) + $sponsorshipReceived;
                        $cashAtUseAuto = $availableFundsReceived;
                        $cashLeftRaw = $availableFundsReceived - $committedPaid;
                        $cashLeft = max(0, $cashLeftRaw);
                        $isBudgetOverrun = $availableFundsReceived < (float)$event['budget_total'];
                        $isHealthy = !$isBudgetOverrun
                            && (int)$ops['pending_bookings'] === 0
                            && (int)$ops['pending_payments'] === 0
                            && (int)$ops['failed_payments'] === 0
                            && (int)$ops['unread_vendor_messages'] === 0;
                    ?>
                    <div class="event-item">
                        <div class="event-header" onclick="this.parentElement.classList.toggle('open')">
                            <div>
                                <div class="event-title"><?php echo htmlspecialchars($event['title']); ?></div>
                                <div class="event-date">
                                    <?php echo htmlspecialchars($event['event_date']); ?>
                                    <?php if ((string)($event['venue'] ?? '') !== ''): ?>
                                        &middot; <?php echo htmlspecialchars((string)$event['venue']); ?>
                                    <?php endif; ?>
                                    <?php if ((string)($event['city'] ?? '') !== ''): ?>
                                        &middot; <?php echo htmlspecialchars((string)$event['city']); ?>
                                    <?php endif; ?>
                                </div>
                                <div class="event-chips">
                                    <?php if ((string)($event['category'] ?? '') !== ''): ?><span class="chip chip-info"><?php echo htmlspecialchars((string)$event['category']); ?></span><?php endif; ?>
                                    <span class="chip chip-info"><?php echo ((string)($event['event_type'] ?? 'in_person') === 'online') ? 'Online' : 'In-person'; ?></span>
                                    <span class="chip chip-info">Total Remaining: <?php echo (int)$totalRemainingByType; ?></span>
                                    <?php if ($isBudgetOverrun): ?><span class="chip chip-risk">Budget Overrun</span><?php endif; ?>
                                    <?php if ((int)$ops['failed_payments'] > 0): ?><span class="chip chip-risk">Failed Payments: <?php echo (int)$ops['failed_payments']; ?></span><?php endif; ?>
                                    <?php if ((int)$ops['pending_payments'] > 0): ?><span class="chip chip-warn">Pending Payments: <?php echo (int)$ops['pending_payments']; ?></span><?php endif; ?>
                                    <?php if ((int)$ops['pending_bookings'] > 0): ?><span class="chip chip-info">Pending Bookings: <?php echo (int)$ops['pending_bookings']; ?></span><?php endif; ?>
                                    <?php if ((int)$ops['unread_vendor_messages'] > 0): ?><span class="chip chip-info">Unread Vendor Updates: <?php echo (int)$ops['unread_vendor_messages']; ?></span><?php endif; ?>
                                    <?php if ($isHealthy): ?><span class="chip chip-good">In Sync</span><?php endif; ?>
                                </div>
                            </div>
                            <div style="display:flex; align-items:center; gap:16px;">
                                <span class="event-budget">KES <?php echo number_format($event['budget_total'], 2); ?></span>
                                <i class="fa-solid fa-chevron-down event-chevron"></i>
                            </div>
                        </div>
                        <div class="event-details">
                            <div class="detail-row">
                                <?php $eventBannerUrl = resolveEventBannerUrl((string)($event['image_url'] ?? '')); ?>
                                <?php if ($eventBannerUrl !== ''): ?>
                                    <span style="grid-column: 1 / -1;"><img src="<?php echo htmlspecialchars($eventBannerUrl); ?>" alt="Event banner" style="max-width: 100%; max-height: 160px; border-radius: 8px; object-fit: cover;"></span>
                                <?php endif; ?>
                                <span><span class="detail-label">Category:</span> <?php echo htmlspecialchars((string)(($event['category'] ?? '') !== '' ? $event['category'] : 'Uncategorized')); ?></span>
                                <span><span class="detail-label">Venue:</span> <?php echo htmlspecialchars((string)(($event['venue'] ?? '') !== '' ? $event['venue'] : 'Not specified')); ?></span>
                                <span><span class="detail-label">City:</span> <?php echo htmlspecialchars((string)(($event['city'] ?? '') !== '' ? $event['city'] : 'Not specified')); ?></span>
                                <span><span class="detail-label">Type:</span> <?php echo ((string)($event['event_type'] ?? 'in_person') === 'online') ? 'Online' : 'In-person'; ?></span>
                                <span><span class="detail-label">Stall Price:</span> KES <?php echo number_format((float)($event['stall_price'] ?? 0), 2); ?></span>
                                <span><span class="detail-label">Ticket Type - Early Bird:</span> <?php echo ((float)$ticketTypesForEvent['early_bird']['price'] <= 0) ? 'Free' : ('KES ' . number_format((float)$ticketTypesForEvent['early_bird']['price'], 2)); ?></span>
                                <span><span class="detail-label">Early Bird Remaining:</span> <?php echo (int)($ticketTypesForEvent['early_bird']['remaining'] ?? 0); ?></span>
                                <span><span class="detail-label">Ticket Type - Regular:</span> <?php echo ((float)$ticketTypesForEvent['regular']['price'] <= 0) ? 'Free' : ('KES ' . number_format((float)$ticketTypesForEvent['regular']['price'], 2)); ?></span>
                                <span><span class="detail-label">Regular Remaining:</span> <?php echo (int)($ticketTypesForEvent['regular']['remaining'] ?? 0); ?></span>
                                <span><span class="detail-label">Ticket Type - VIP:</span> <?php echo ((float)$ticketTypesForEvent['vip']['price'] <= 0) ? 'Free' : ('KES ' . number_format((float)$ticketTypesForEvent['vip']['price'], 2)); ?></span>
                                <span><span class="detail-label">VIP Remaining:</span> <?php echo (int)($ticketTypesForEvent['vip']['remaining'] ?? 0); ?></span>
                                <span><span class="detail-label">Ticket Type - VVIP:</span> <?php echo ((float)$ticketTypesForEvent['vvip']['price'] <= 0) ? 'Free' : ('KES ' . number_format((float)$ticketTypesForEvent['vvip']['price'], 2)); ?></span>
                                <span><span class="detail-label">VVIP Remaining:</span> <?php echo (int)($ticketTypesForEvent['vvip']['remaining'] ?? 0); ?></span>
                                <span><span class="detail-label">Ticket Capacity:</span> <?php echo (int)$event['attendee_count']; ?> / <?php echo (int)$event['tickets_available']; ?></span>
                                <span><span class="detail-label">Budget Plan:</span> KES <?php echo number_format($event['budget_total'], 2); ?></span>
                                <span><span class="detail-label">Planned Spend:</span> KES <?php echo number_format($event['budget_committed'], 2); ?></span>
                                <span><span class="detail-label">Money Spent (Incl Sponsorships):</span> KES <?php echo number_format($committedPaid, 2); ?></span>
                                <span><span class="detail-label">Item Spend Total:</span> KES <?php echo number_format($itemSpentTotal, 2); ?></span>
                                <span><span class="detail-label">Cash at Use (Auto):</span> KES <?php echo number_format($cashAtUseAuto, 2); ?></span>
                                <span><span class="detail-label">Sponsorships (Assumed Paid):</span> KES <?php echo number_format($sponsorshipReceived, 2); ?></span>
                                <span><span class="detail-label">Budget Left (Plan):</span> KES <?php echo number_format($availableBudget, 2); ?></span>
                                <span><span class="detail-label">Money Received:</span> KES <?php echo number_format($availableFundsReceived, 2); ?></span>
                                <span><span class="detail-label">Cash Left:</span> KES <?php echo number_format($cashLeft, 2); ?></span>
                                <span><span class="detail-label">Bookings:</span> <?php echo (int)$ops['total_bookings']; ?></span>
                                <span><span class="detail-label">Pending Payments:</span> <?php echo (int)$ops['pending_payments']; ?></span>
                            </div>
                            <div class="event-actions-grid">
                                <div class="event-action-row">
                                    <form method="POST" enctype="multipart/form-data" class="event-action-row" style="width:100%;">
                                        <?php echo csrf_input(); ?>
                                        <input type="hidden" name="update_event_banner" value="1">
                                        <input type="hidden" name="event_id" value="<?php echo (int)$event['event_id']; ?>">
                                        <input type="url" name="image_url" class="form-input" placeholder="Banner URL" value="<?php echo htmlspecialchars((string)($event['image_url'] ?? '')); ?>">
                                        <input type="file" name="banner_file" class="form-input" accept=".jpg,.jpeg,.png,.webp,.gif">
                                        <span class="item-example-text" style="width:100%;">Use URL or Upload, not both.</span>
                                        <button type="submit" class="btn btn-outline"><i class="fa-regular fa-image"></i> Save Banner</button>
                                    </form>
                                </div>

                                <div class="event-action-row">
                                    <form method="POST" class="event-action-row" style="width:100%;">
                                        <?php echo csrf_input(); ?>
                                        <input type="hidden" name="update_event_details" value="1">
                                        <input type="hidden" name="event_id" value="<?php echo (int)$event['event_id']; ?>">
                                        <input type="text" name="category" class="form-input" placeholder="Category" maxlength="64" value="<?php echo htmlspecialchars((string)($event['category'] ?? '')); ?>">
                                        <input type="text" name="venue" class="form-input" placeholder="Venue" maxlength="190" value="<?php echo htmlspecialchars((string)($event['venue'] ?? '')); ?>">
                                        <input type="text" name="city" class="form-input" placeholder="City" maxlength="120" value="<?php echo htmlspecialchars((string)($event['city'] ?? '')); ?>">
                                        <input type="number" step="0.01" min="0" name="stall_price" class="form-input" placeholder="Stall Price" value="<?php echo htmlspecialchars(number_format((float)($event['stall_price'] ?? 0), 2, '.', '')); ?>">
                                        <button type="submit" class="btn btn-outline"><i class="fa-solid fa-pen"></i> Save Details</button>
                                    </form>
                                </div>

                                <div class="event-action-row">
                                    <form method="POST" class="event-action-row" style="width:100%;">
                                        <?php echo csrf_input(); ?>
                                        <input type="hidden" name="update_event_ticket_types" value="1">
                                        <input type="hidden" name="event_id" value="<?php echo (int)$event['event_id']; ?>">
                                        <input type="number" step="0.01" min="0" name="ticket_type_price[early_bird]" class="form-input" placeholder="Early Bird" value="<?php echo htmlspecialchars(number_format((float)$ticketTypesForEvent['early_bird']['price'], 2, '.', '')); ?>">
                                        <input type="number" step="0.01" min="0" name="ticket_type_price[regular]" class="form-input" placeholder="Regular" value="<?php echo htmlspecialchars(number_format((float)$ticketTypesForEvent['regular']['price'], 2, '.', '')); ?>">
                                        <input type="number" step="0.01" min="0" name="ticket_type_price[vip]" class="form-input" placeholder="VIP" value="<?php echo htmlspecialchars(number_format((float)$ticketTypesForEvent['vip']['price'], 2, '.', '')); ?>">
                                        <input type="number" step="0.01" min="0" name="ticket_type_price[vvip]" class="form-input" placeholder="VVIP" value="<?php echo htmlspecialchars(number_format((float)$ticketTypesForEvent['vvip']['price'], 2, '.', '')); ?>">
                                        <input type="number" step="1" min="0" name="ticket_type_remaining[early_bird]" class="form-input" placeholder="Early Bird Remaining" value="<?php echo htmlspecialchars((string)((int)($ticketTypesForEvent['early_bird']['remaining'] ?? 0))); ?>">
                                        <input type="number" step="1" min="0" name="ticket_type_remaining[regular]" class="form-input" placeholder="Regular Remaining" value="<?php echo htmlspecialchars((string)((int)($ticketTypesForEvent['regular']['remaining'] ?? 0))); ?>">
                                        <input type="number" step="1" min="0" name="ticket_type_remaining[vip]" class="form-input" placeholder="VIP Remaining" value="<?php echo htmlspecialchars((string)((int)($ticketTypesForEvent['vip']['remaining'] ?? 0))); ?>">
                                        <input type="number" step="1" min="0" name="ticket_type_remaining[vvip]" class="form-input" placeholder="VVIP Remaining" value="<?php echo htmlspecialchars((string)((int)($ticketTypesForEvent['vvip']['remaining'] ?? 0))); ?>">
                                        <input type="text" name="ticket_type_description[vip]" class="form-input" placeholder="VIP description" value="<?php echo htmlspecialchars((string)$ticketTypesForEvent['vip']['description']); ?>">
                                        <input type="text" name="ticket_type_description[vvip]" class="form-input" placeholder="VVIP description" value="<?php echo htmlspecialchars((string)$ticketTypesForEvent['vvip']['description']); ?>">
                                        <button type="submit" class="btn btn-outline"><i class="fa-solid fa-ticket"></i> Save Ticket Types</button>
                                    </form>
                                </div>

                                <div class="event-action-row">
                                    <a href="browse_vendors.php?event_id=<?php echo $event['event_id']; ?>" class="btn btn-outline">
                                        <i class="fa-solid fa-magnifying-glass"></i> Browse Vendors for This Event
                                    </a>
                                    <a href="mpesa_payments.php" class="btn btn-outline">
                                        <i class="fa-solid fa-money-check-dollar"></i> Open Payment Reconciliation
                                    </a>
                                    <a href="messages.php?unread=1" class="btn btn-outline">
                                        <i class="fa-solid fa-comments"></i> Review Vendor Updates
                                    </a>
                                    <a href="budget_breakdown.php?event_id=<?php echo (int)$event['event_id']; ?>" class="btn btn-outline">
                                        <i class="fa-solid fa-pen-to-square"></i> Edit Budget Breakdown
                                    </a>
                                    <form method="POST" style="display:inline-flex;">
                                        <?php echo csrf_input(); ?>
                                        <input type="hidden" name="download_budget_preview" value="1">
                                        <input type="hidden" name="event_id" value="<?php echo (int)$event['event_id']; ?>">
                                        <button type="submit" class="btn btn-outline"><i class="fa-solid fa-file-arrow-down"></i> Download Budget Preview (PDF)</button>
                                    </form>
                                </div>

                                <div class="event-action-row">
                                    <form method="POST" class="event-action-row" style="width:100%;">
                                        <?php echo csrf_input(); ?>
                                        <input type="hidden" name="save_item_spent" value="1">
                                        <input type="hidden" name="event_id" value="<?php echo (int)$event['event_id']; ?>">
                                        <div class="item-spend-box" style="width:100%;">
                                            <div class="item-spend-title">Planned Budget Items - Add Money Spent Per Item</div>
                                            <?php if (empty($budgetItems)): ?>
                                                <div class="adjustment-hint">No planned budget items yet. Add them in Edit Budget Breakdown first.</div>
                                            <?php else: ?>
                                                <div class="item-spend-grid item-spend-head">
                                                    <span>Item</span>
                                                    <span>Planned</span>
                                                    <span>Spent</span>
                                                </div>
                                                <?php foreach ($budgetItems as $budgetItem): ?>
                                                    <div class="item-spend-grid" style="margin-top:6px;">
                                                        <span><?php echo htmlspecialchars((string)$budgetItem['item_name']); ?></span>
                                                        <span>KES <?php echo number_format((float)$budgetItem['planned_amount'], 2); ?></span>
                                                        <input type="hidden" name="item_id[]" value="<?php echo (int)$budgetItem['item_id']; ?>">
                                                        <input type="number" step="0.01" min="0" max="<?php echo number_format((float)$budgetItem['planned_amount'], 2, '.', ''); ?>" name="item_spent_amount[]" class="form-input" value="<?php echo number_format((float)$budgetItem['spent_amount'], 2, '.', ''); ?>">
                                                    </div>
                                                <?php endforeach; ?>
                                                <div class="event-action-row" style="margin-top:10px;">
                                                    <button type="submit" class="btn btn-outline"><i class="fa-solid fa-floppy-disk"></i> Save Item Spending</button>
                                                    <span class="adjustment-hint">Total item spending cannot be more than money received.</span>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </form>
                                </div>

                                <div class="event-action-row">
                                    <span class="adjustment-hint">Cash at Use is auto-filled from attendee revenue, vendor payments, and sponsorships.</span>
                                </div>

                                <div class="event-action-row-danger">
                                    <form method="POST" onsubmit="return confirm('Archive this event? You can restore it later.');">
                                        <?php echo csrf_input(); ?>
                                        <input type="hidden" name="delete_event" value="1">
                                        <input type="hidden" name="event_id" value="<?php echo (int)$event['event_id']; ?>">
                                        <button type="submit" class="btn btn-danger"><i class="fa-solid fa-box-archive"></i> Archive Event</button>
                                    </form>
                                    <form method="POST" onsubmit="return confirm('Permanently delete this event? This cannot be undone and will remove related records.');">
                                        <?php echo csrf_input(); ?>
                                        <input type="hidden" name="permanently_delete_event" value="1">
                                        <input type="hidden" name="event_id" value="<?php echo (int)$event['event_id']; ?>">
                                        <button type="submit" class="btn btn-danger"><i class="fa-solid fa-trash"></i> Delete Permanently</button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
    <?php endif; ?>

    <?php if ($eventView === 'archived' || $eventView === 'all'): ?>
    <section class="card">
        <h2 class="card-title"><i class="fa-solid fa-box-archive" style="color:#6C63FF;"></i> Archived Events</h2>
        <div class="view-filters">
            <a class="view-filter <?php echo $eventView === 'active' ? 'active' : ''; ?>" href="create_event.php?event_view=active#event-views-anchor">Active</a>
            <a class="view-filter <?php echo $eventView === 'archived' ? 'active' : ''; ?>" href="create_event.php?event_view=archived#event-views-anchor">Archived</a>
            <a class="view-filter <?php echo $eventView === 'all' ? 'active' : ''; ?>" href="create_event.php?event_view=all#event-views-anchor">All</a>
        </div>
        <?php if (empty($archivedEvents)): ?>
            <div class="empty-state">No archived events.</div>
        <?php else: ?>
            <div class="event-list">
                <?php foreach ($archivedEvents as $event): ?>
                    <div class="event-item" style="padding: 14px 0;">
                        <div class="detail-row">
                            <?php $eventBannerUrl = resolveEventBannerUrl((string)($event['image_url'] ?? '')); ?>
                            <?php if ($eventBannerUrl !== ''): ?>
                                <span style="grid-column: 1 / -1;"><img src="<?php echo htmlspecialchars($eventBannerUrl); ?>" alt="Event banner" style="max-width: 100%; max-height: 160px; border-radius: 8px; object-fit: cover;"></span>
                            <?php endif; ?>
                            <span><span class="detail-label">Title:</span> <?php echo htmlspecialchars((string)$event['title']); ?></span>
                            <span><span class="detail-label">Date:</span> <?php echo htmlspecialchars((string)$event['event_date']); ?></span>
                            <span><span class="detail-label">Category:</span> <?php echo htmlspecialchars((string)(($event['category'] ?? '') !== '' ? $event['category'] : 'Uncategorized')); ?></span>
                            <span><span class="detail-label">Venue:</span> <?php echo htmlspecialchars((string)(($event['venue'] ?? '') !== '' ? $event['venue'] : 'Not specified')); ?></span>
                            <span><span class="detail-label">City:</span> <?php echo htmlspecialchars((string)(($event['city'] ?? '') !== '' ? $event['city'] : 'Not specified')); ?></span>
                            <span><span class="detail-label">Type:</span> <?php echo ((string)($event['event_type'] ?? 'in_person') === 'online') ? 'Online' : 'In-person'; ?></span>
                            <span><span class="detail-label">Ticket Capacity:</span> <?php echo (int)$event['tickets_available']; ?></span>
                            <span><span class="detail-label">Budget:</span> KES <?php echo number_format((float)$event['budget_total'], 2); ?></span>
                            <span><span class="detail-label">Archived At:</span> <?php echo htmlspecialchars((string)$event['archived_at']); ?></span>
                        </div>
                        <form method="POST" style="margin-top: 8px;">
                            <?php echo csrf_input(); ?>
                            <input type="hidden" name="restore_event" value="1">
                            <input type="hidden" name="event_id" value="<?php echo (int)$event['event_id']; ?>">
                            <button type="submit" class="btn btn-outline"><i class="fa-solid fa-rotate-left"></i> Restore Event</button>
                        </form>
                        <form method="POST" style="margin-top: 8px;" onsubmit="return confirm('Permanently delete this archived event? This cannot be undone and will remove related records.');">
                            <?php echo csrf_input(); ?>
                            <input type="hidden" name="permanently_delete_event" value="1">
                            <input type="hidden" name="event_id" value="<?php echo (int)$event['event_id']; ?>">
                            <button type="submit" class="btn btn-danger"><i class="fa-solid fa-trash"></i> Delete Permanently</button>
                        </form>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
    <?php endif; ?>
</div>

<script>
    (function () {
        const budgetInput = document.getElementById('budget_total');
        const regularTicketInput = document.getElementById('ticket_type_regular');
        const expectedAttendeesInput = document.getElementById('expected_attendees');
        const vendorContributionInput = document.getElementById('vendor_contribution_target');
        const rowsWrap = document.getElementById('budget_item_rows');
        const addItemBtn = document.getElementById('add_budget_item');

        const budgetTotalEl = document.getElementById('draft_budget_total');
        const budgetAvailableEl = document.getElementById('draft_budget_available');
        const ticketEl = document.getElementById('draft_ticket_price');
        const committedEl = document.getElementById('draft_committed_creation');
        const attendeeContributionEl = document.getElementById('draft_attendee_contribution');
        const vendorContributionEl = document.getElementById('draft_vendor_contribution');
        const projectedFundsEl = document.getElementById('draft_projected_funds');

        function toAmount(value) {
            const num = parseFloat(value);
            return Number.isFinite(num) && num >= 0 ? num : 0;
        }

        function toInt(value) {
            const num = parseInt(value, 10);
            return Number.isFinite(num) && num >= 0 ? num : 0;
        }

        function formatKes(amount) {
            return 'KES ' + amount.toLocaleString('en-KE', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            });
        }

        function getCommittedTotal() {
            if (!rowsWrap) {
                return 0;
            }
            const amountInputs = rowsWrap.querySelectorAll('.js-item-amount');
            let total = 0;
            amountInputs.forEach((input) => {
                const row = input.closest('.budget-item-row');
                const nameInput = row ? row.querySelector('.js-item-name') : null;
                const hasName = nameInput && nameInput.value.trim() !== '';
                const amount = toAmount(input.value);
                if (hasName && amount > 0) {
                    total += amount;
                }
            });
            return total;
        }

        function bindRowEvents(row) {
            const removeBtn = row.querySelector('.js-remove-item');
            if (removeBtn) {
                removeBtn.addEventListener('click', () => {
                    const rows = rowsWrap ? rowsWrap.querySelectorAll('.budget-item-row') : [];
                    if (rows.length <= 1) {
                        const nameInput = row.querySelector('.js-item-name');
                        const amountInput = row.querySelector('.js-item-amount');
                        if (nameInput) nameInput.value = '';
                        if (amountInput) amountInput.value = '';
                    } else {
                        row.remove();
                    }
                    updatePreview();
                });
            }

            const nameInput = row.querySelector('.js-item-name');
            const amountInput = row.querySelector('.js-item-amount');
            if (nameInput) {
                nameInput.addEventListener('input', updatePreview);
            }
            if (amountInput) {
                amountInput.addEventListener('input', updatePreview);
            }
        }

        function updatePreview() {
            const budget = toAmount(budgetInput ? budgetInput.value : '0');
            const ticket = toAmount(regularTicketInput ? regularTicketInput.value : '0');
            const expectedAttendees = toInt(expectedAttendeesInput ? expectedAttendeesInput.value : '0');
            const vendorContribution = toAmount(vendorContributionInput ? vendorContributionInput.value : '0');
            const committed = getCommittedTotal();
            const available = Math.max(0, budget - committed);
            const attendeeContribution = ticket * expectedAttendees;
            const projectedFunds = attendeeContribution + vendorContribution;

            if (budgetTotalEl) {
                budgetTotalEl.textContent = formatKes(budget);
            }
            if (committedEl) {
                committedEl.textContent = formatKes(committed);
            }
            if (budgetAvailableEl) {
                budgetAvailableEl.textContent = formatKes(available);
            }
            if (ticketEl) {
                ticketEl.textContent = formatKes(ticket);
            }
            if (attendeeContributionEl) {
                attendeeContributionEl.textContent = formatKes(attendeeContribution);
            }
            if (vendorContributionEl) {
                vendorContributionEl.textContent = formatKes(vendorContribution);
            }
            if (projectedFundsEl) {
                projectedFundsEl.textContent = formatKes(projectedFunds);
            }
        }

        if (rowsWrap) {
            rowsWrap.querySelectorAll('.budget-item-row').forEach(bindRowEvents);
        }

        if (addItemBtn && rowsWrap) {
            addItemBtn.addEventListener('click', () => {
                const row = document.createElement('div');
                row.className = 'budget-item-row';
                row.innerHTML =
                    '<div class="name-with-example">' +
                        '<input type="text" name="budget_item_name[]" class="form-input js-item-name" placeholder="Item name (e.g. Catering)">' +
                        '<div class="item-example-text">Examples: Venue, Catering, Decor, Sound, Security</div>' +
                    '</div>' +
                    '<input type="number" name="budget_item_amount[]" class="form-input js-item-amount" step="0.01" min="0" placeholder="0.00">' +
                    '<button type="button" class="remove-item-btn js-remove-item"><i class="fa-solid fa-xmark"></i></button>';
                rowsWrap.appendChild(row);
                bindRowEvents(row);
                updatePreview();
            });
        }

        if (budgetInput) {
            budgetInput.addEventListener('input', updatePreview);
        }
        if (regularTicketInput) {
            regularTicketInput.addEventListener('input', updatePreview);
        }
        if (expectedAttendeesInput) {
            expectedAttendeesInput.addEventListener('input', updatePreview);
        }
        if (vendorContributionInput) {
            vendorContributionInput.addEventListener('input', updatePreview);
        }

        updatePreview();
    })();
</script>
</body>
</html>

