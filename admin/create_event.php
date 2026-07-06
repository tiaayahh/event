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
$budgetTotal = '';
$ticketPrice = '0.00';
$expectedAttendees = '0';
$vendorContributionTarget = '0.00';
$budgetItemNames = ['', '', ''];
$budgetItemAmounts = ['', '', ''];
$events = [];
$archivedEvents = [];
$eventOpsById = [];
$eventBudgetItemsByEventId = [];
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

ensureEventBudgetPlanningSchema($pdo);
ensureEventFinancialAdjustmentsSchema($pdo);
ensureEventSponsorshipsSchema($pdo);

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['create_event'])) {
        $title = trim($_POST['title'] ?? '');
        $eventDate = trim($_POST['event_date'] ?? '');
        $venue = trim($_POST['venue'] ?? '');
        $budgetTotal = trim($_POST['budget_total'] ?? '');
        $ticketPrice = trim($_POST['ticket_price'] ?? '0.00');
        $expectedAttendees = trim($_POST['expected_attendees'] ?? '0');
        $vendorContributionTarget = trim($_POST['vendor_contribution_target'] ?? '0.00');
        $budgetItemNames = $_POST['budget_item_name'] ?? [];
        $budgetItemAmounts = $_POST['budget_item_amount'] ?? [];

        if (!is_array($budgetItemNames)) {
            $budgetItemNames = [];
        }
        if (!is_array($budgetItemAmounts)) {
            $budgetItemAmounts = [];
        }

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

        if ($flashError === '' && ($ticketPrice === '' || !is_numeric($ticketPrice) || (float)$ticketPrice < 0)) {
            $flashError = 'Ticket price must be a valid non-negative amount.';
        }

        if ($flashError === '' && ($expectedAttendees === '' || !is_numeric($expectedAttendees) || (int)$expectedAttendees < 0)) {
            $flashError = 'Expected attendees must be a valid non-negative whole number.';
        }

        if ($flashError === '' && ($vendorContributionTarget === '' || !is_numeric($vendorContributionTarget) || (float)$vendorContributionTarget < 0)) {
            $flashError = 'Vendor contribution target must be a valid non-negative amount.';
        }

        $budgetTotalFloat = (float)$budgetTotal;
        $ticketPriceFloat = (float)$ticketPrice;
        $expectedAttendeesInt = max(0, (int)$expectedAttendees);
        $vendorContributionTargetFloat = (float)$vendorContributionTarget;
        $attendeeContributionTarget = $ticketPriceFloat * $expectedAttendeesInt;

        if ($flashError === '' && $plannedCommitted > $budgetTotalFloat) {
            $flashError = 'Planned budget items exceed the total budget. Reduce item amounts or increase budget.';
        } else {
            try {
                $pdo->beginTransaction();

                $stmt = $pdo->prepare(
                    'INSERT INTO events (planner_id, title, event_date, venue, budget_total, budget_committed, ticket_price, ticket_revenue, attendee_contribution_target, vendor_contribution_target)
                     VALUES (?, ?, ?, ?, ?, ?, ?, 0, ?, ?)'
                );
                $stmt->execute([
                    $_SESSION['user_id'],
                    $title,
                    $eventDate,
                    $venue !== '' ? $venue : null,
                    $budgetTotalFloat,
                    $plannedCommitted,
                    $ticketPriceFloat,
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
                        'budget_total' => $budgetTotalFloat,
                        'budget_committed' => $plannedCommitted,
                        'planned_item_count' => count($plannedItems),
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

                            $stmt = $pdo->prepare(
                                "SELECT COALESCE(SUM(amount), 0)
                                 FROM event_financial_adjustments
                                 WHERE event_id = ? AND entry_kind = 'cash_available'"
                            );
                            $stmt->execute([$entryEventId]);
                            $manualCash = (float)$stmt->fetchColumn();

                            $moneyReceived = (float)($eventRow['ticket_revenue'] ?? 0)
                                + (float)($eventRow['vendor_revenue_received'] ?? 0)
                                + (float)($eventRow['sponsorship_received'] ?? 0)
                                + $manualCash;

                            $moneySpentCandidate = $paidTransactions + $totalItemSpent;
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
        $entryKind = 'cash_available';
        $entryEventId = filter_input(INPUT_POST, 'event_id', FILTER_VALIDATE_INT);
        $entryAmountRaw = trim((string)($_POST['amount'] ?? ''));
        $entryNote = trim((string)($_POST['note'] ?? ''));

        if (!$entryEventId) {
            $flashError = 'Invalid event selected for financial adjustment.';
        } elseif ($entryAmountRaw === '' || !is_numeric($entryAmountRaw) || (float)$entryAmountRaw <= 0) {
            $flashError = 'Please enter a valid positive amount.';
        } else {
            try {
                $stmt = $pdo->prepare('SELECT title FROM events WHERE event_id = ? AND planner_id = ? AND archived_at IS NULL LIMIT 1');
                $stmt->execute([$entryEventId, $_SESSION['user_id']]);
                $entryEvent = $stmt->fetch();

                if (!$entryEvent) {
                    $flashError = 'Event not found or cannot be adjusted.';
                } else {
                    $entryAmount = (float)$entryAmountRaw;

                    if ($flashError === '') {
                    $stmt = $pdo->prepare(
                        'INSERT INTO event_financial_adjustments (event_id, entry_kind, amount, note, created_by) VALUES (?, ?, ?, ?, ?)'
                    );
                    $stmt->execute([
                        $entryEventId,
                        $entryKind,
                        $entryAmount,
                        $entryNote !== '' ? $entryNote : null,
                        (int)$_SESSION['user_id'],
                    ]);

                    audit_log(
                        $pdo,
                        (int)$_SESSION['user_id'],
                        (string)$_SESSION['role'],
                        'event.financial_adjustment_added',
                        'event',
                        (string)$entryEventId,
                        [
                            'entry_kind' => $entryKind,
                            'amount' => $entryAmount,
                            'note' => $entryNote,
                        ]
                    );

                    $_SESSION['flash_success'] = 'Extra cash added.';
                    header('Location: create_event.php?event_view=' . urlencode($eventView) . '#event-views-anchor');
                    exit;
                    }
                }
            } catch (Throwable $e) {
                audit_log(
                    $pdo,
                    (int)$_SESSION['user_id'],
                    (string)$_SESSION['role'],
                    'event.financial_adjustment_add_failed',
                    'event',
                    $entryEventId ? (string)$entryEventId : null,
                    ['entry_kind' => $entryKind, 'reason' => 'exception']
                );
                $flashError = 'Could not save financial adjustment right now. Please try again.';
            }
        }
    }
}

// Fetch existing events
try {
    $stmt = $pdo->prepare(
        "SELECT e.event_id, e.title, e.event_date, e.budget_total, e.budget_committed, e.ticket_revenue,
                COALESCE(adj_cash.total_cash_available, 0) AS manual_cash_available,
                COALESCE(adj_sponsor.total_sponsorship_received, 0) AS sponsorship_received
         FROM events e
         LEFT JOIN (
             SELECT event_id, SUM(amount) AS total_cash_available
             FROM event_financial_adjustments
             WHERE entry_kind = 'cash_available'
             GROUP BY event_id
         ) adj_cash ON adj_cash.event_id = e.event_id
         LEFT JOIN (
             SELECT event_id, SUM(contribution_amount) AS total_sponsorship_received
             FROM event_sponsorships
             GROUP BY event_id
         ) adj_sponsor ON adj_sponsor.event_id = e.event_id
         WHERE e.planner_id = ? AND e.archived_at IS NULL
         ORDER BY e.event_date DESC"
    );
    $stmt->execute([$_SESSION['user_id']]);
    $events = $stmt->fetchAll();

    $stmt = $pdo->prepare('SELECT event_id, title, event_date, budget_total, archived_at FROM events WHERE planner_id = ? AND archived_at IS NOT NULL ORDER BY archived_at DESC');
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
            + (float)($event['sponsorship_received'] ?? 0)
            + (float)($event['manual_cash_available'] ?? 0);
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
$draftTicketPrice = is_numeric($ticketPrice) ? (float)$ticketPrice : 0.00;
$draftExpectedAttendees = is_numeric($expectedAttendees) ? max(0, (int)$expectedAttendees) : 0;
$draftVendorContribution = is_numeric($vendorContributionTarget) ? (float)$vendorContributionTarget : 0.00;
$draftAttendeeContribution = $draftTicketPrice * $draftExpectedAttendees;
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
        .form-input:focus { outline: none; border-color: #6C63FF; background: #fff; }
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
        <form method="POST" class="form-grid">
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
                <label for="budget_total">Budget (KES)</label>
                <input type="number" id="budget_total" name="budget_total" step="0.01" min="0" class="form-input" placeholder="15000" value="<?php echo htmlspecialchars($budgetTotal); ?>" required>
            </div>
            <div class="form-group">
                <label for="ticket_price">Ticket Price (KES) <small>(per attendee)</small></label>
                <input type="number" step="0.01" min="0" id="ticket_price" name="ticket_price" class="form-input" placeholder="0.00" value="<?php echo htmlspecialchars($ticketPrice ?? '0.00'); ?>">
            </div>
            <div class="form-group">
                <label for="expected_attendees">Expected Attendees</label>
                <input type="number" step="1" min="0" id="expected_attendees" name="expected_attendees" class="form-input" placeholder="0" value="<?php echo htmlspecialchars($expectedAttendees ?? '0'); ?>">
            </div>
            <div class="form-group">
                <label for="vendor_contribution_target">Vendor Contribution Target (KES)</label>
                <input type="number" step="0.01" min="0" id="vendor_contribution_target" name="vendor_contribution_target" class="form-input" placeholder="0.00" value="<?php echo htmlspecialchars($vendorContributionTarget ?? '0.00'); ?>">
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
                    <div class="breakdown-value" id="draft_ticket_price">KES <?php echo number_format($draftTicketPrice, 2); ?></div>
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
                        $manualCashAvailable = (float)($event['manual_cash_available'] ?? 0);
                        $sponsorshipReceived = (float)($event['sponsorship_received'] ?? 0);
                        $committedPaid = (float)($ops['paid_transactions_total'] ?? 0) + $itemSpentTotal;
                        $availableFundsReceived = (float)$event['ticket_revenue'] + (float)($ops['vendor_revenue_received'] ?? 0) + $sponsorshipReceived + $manualCashAvailable;
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
                                <div class="event-date"><?php echo htmlspecialchars($event['event_date']); ?></div>
                                <div class="event-chips">
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
                                <span><span class="detail-label">Budget Plan:</span> KES <?php echo number_format($event['budget_total'], 2); ?></span>
                                <span><span class="detail-label">Planned Spend:</span> KES <?php echo number_format($event['budget_committed'], 2); ?></span>
                                <span><span class="detail-label">Money Spent:</span> KES <?php echo number_format($committedPaid, 2); ?></span>
                                <span><span class="detail-label">Item Spend Total:</span> KES <?php echo number_format($itemSpentTotal, 2); ?></span>
                                <span><span class="detail-label">Extra Cash:</span> KES <?php echo number_format($manualCashAvailable, 2); ?></span>
                                <span><span class="detail-label">Sponsorships:</span> KES <?php echo number_format($sponsorshipReceived, 2); ?></span>
                                <span><span class="detail-label">Budget Left (Plan):</span> KES <?php echo number_format($availableBudget, 2); ?></span>
                                <span><span class="detail-label">Money Received:</span> KES <?php echo number_format($availableFundsReceived, 2); ?></span>
                                <span><span class="detail-label">Cash Left:</span> KES <?php echo number_format($cashLeft, 2); ?></span>
                                <span><span class="detail-label">Bookings:</span> <?php echo (int)$ops['total_bookings']; ?></span>
                                <span><span class="detail-label">Pending Payments:</span> <?php echo (int)$ops['pending_payments']; ?></span>
                            </div>
                            <div class="event-actions-grid">
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
                                    <form method="POST" class="event-action-row">
                                        <?php echo csrf_input(); ?>
                                        <input type="hidden" name="add_cash_at_use" value="1">
                                        <input type="hidden" name="event_id" value="<?php echo (int)$event['event_id']; ?>">
                                        <input type="number" step="100" min="0" name="amount" class="form-input" placeholder="Cash at use" required>
                                        <input type="text" name="note" class="form-input" placeholder="Note (optional)">
                                        <button type="submit" class="btn btn-outline"><i class="fa-solid fa-plus"></i> Add Cash at Use</button>
                                    </form>
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
                            <span><span class="detail-label">Title:</span> <?php echo htmlspecialchars((string)$event['title']); ?></span>
                            <span><span class="detail-label">Date:</span> <?php echo htmlspecialchars((string)$event['event_date']); ?></span>
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
        const ticketInput = document.getElementById('ticket_price');
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
            const ticket = toAmount(ticketInput ? ticketInput.value : '0');
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
        if (ticketInput) {
            ticketInput.addEventListener('input', updatePreview);
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

