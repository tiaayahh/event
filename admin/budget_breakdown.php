<?php
require_once '../includes/auth.php';
checkAuth();
requireRole('planner');

$eventId = filter_input(INPUT_GET, 'event_id', FILTER_VALIDATE_INT);
if (!$eventId) {
    $_SESSION['flash_error'] = 'Invalid event selected for budget breakdown.';
    header('Location: dashboard.php');
    exit;
}

$event = null;
$lineItems = [];
$plannedItems = [];
$vendorContributions = [];
$ticketTypes = [
    'early_bird' => 0.0,
    'regular' => 0.0,
    'vip' => 0.0,
    'vvip' => 0.0,
];
$ticketTypeDescriptions = [
    'early_bird' => '',
    'regular' => '',
    'vip' => '',
    'vvip' => '',
];
$sponsorships = [];
$flashSuccess = (string)($_SESSION['flash_success'] ?? '');
$formPlannedItems = [];
$flashError = '';
unset($_SESSION['flash_success']);

$summary = [
    'items_total' => 0.0,
    'items_confirmed' => 0.0,
    'items_paid' => 0.0,
    'pending_balance' => 0.0,
];
$vendorRevenue = 0.0;
$manualCashAdded = 0.0;
$paidTransactionTotal = 0.0;
$sponsorshipTotal = 0.0;

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

    $stmt = $pdo->query("SHOW COLUMNS FROM events LIKE 'vendor_fee_amount'");
    if (!$stmt->fetch()) {
        $pdo->exec("ALTER TABLE events ADD COLUMN vendor_fee_amount DECIMAL(10,2) NOT NULL DEFAULT 100.00");
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
            price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
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
ensureEventTicketTypesSchema($pdo);
ensureEventSponsorshipsSchema($pdo);

try {
    $stmt = $pdo->prepare(
        "SELECT event_id, title, event_date, budget_total, budget_committed, ticket_price, ticket_revenue,
            attendee_contribution_target, vendor_fee_amount
         FROM events
         WHERE event_id = ? AND planner_id = ? AND archived_at IS NULL
         LIMIT 1"
    );
    $stmt->execute([$eventId, $_SESSION['user_id']]);
    $event = $stmt->fetch();

    if (!$event) {
        $_SESSION['flash_error'] = 'Event not found.';
        header('Location: dashboard.php');
        exit;
    }

    $stmt = $pdo->prepare(
        "SELECT item_name, planned_amount, spent_amount
         FROM event_budget_items
         WHERE event_id = ?
         ORDER BY sort_order ASC, item_id ASC"
    );
    $stmt->execute([$eventId]);
    $plannedItems = $stmt->fetchAll();

    $stmt = $pdo->prepare(
        "SELECT ticket_type, price, description
         FROM event_ticket_types
         WHERE event_id = ?"
    );
    $stmt->execute([$eventId]);
    foreach ($stmt->fetchAll() as $row) {
        $typeKey = strtolower((string)$row['ticket_type']);
        if (array_key_exists($typeKey, $ticketTypes)) {
            $ticketTypes[$typeKey] = (float)$row['price'];
            $ticketTypeDescriptions[$typeKey] = trim((string)($row['description'] ?? ''));
        }
    }

    $stmt = $pdo->prepare(
        "SELECT sponsor_name, contribution_amount
         FROM event_sponsorships
         WHERE event_id = ?
         ORDER BY sponsorship_id ASC"
    );
    $stmt->execute([$eventId]);
    $sponsorships = $stmt->fetchAll();
    if (empty($sponsorships)) {
        $sponsorships = [
            ['sponsor_name' => '', 'contribution_amount' => 0],
        ];
    }

    if ($ticketTypes['regular'] <= 0 && (float)$event['ticket_price'] > 0) {
        $ticketTypes['regular'] = (float)$event['ticket_price'];
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_planned_budget'])) {
        $formBudgetTotal = trim((string)($_POST['budget_total'] ?? ''));
        $formBudgetCommitted = trim((string)($_POST['budget_committed'] ?? ''));
        $formAttendeeContributionTarget = trim((string)($_POST['attendee_contribution_target'] ?? ''));
        $formVendorFeeAmount = trim((string)($_POST['vendor_fee_amount'] ?? ''));
        $formTicketTypes = $_POST['ticket_type_price'] ?? [];
        $formTicketTypeDescriptions = $_POST['ticket_type_description'] ?? [];
        $formSponsorshipNames = $_POST['sponsorship_name'] ?? [];
        $formSponsorshipAmounts = $_POST['sponsorship_amount'] ?? [];
        $formItemNames = $_POST['planned_item_name'] ?? [];
        $formItemAmounts = $_POST['planned_item_amount'] ?? [];

        if (!is_array($formItemNames)) {
            $formItemNames = [];
        }
        if (!is_array($formItemAmounts)) {
            $formItemAmounts = [];
        }
        if (!is_array($formTicketTypes)) {
            $formTicketTypes = [];
        }
        if (!is_array($formTicketTypeDescriptions)) {
            $formTicketTypeDescriptions = [];
        }
        if (!is_array($formSponsorshipNames)) {
            $formSponsorshipNames = [];
        }
        if (!is_array($formSponsorshipAmounts)) {
            $formSponsorshipAmounts = [];
        }

        if ($formBudgetTotal === '' || !is_numeric($formBudgetTotal) || (float)$formBudgetTotal < 0) {
            $flashError = 'Planned budget must be a valid non-negative amount.';
        } elseif ($formBudgetCommitted === '' || !is_numeric($formBudgetCommitted) || (float)$formBudgetCommitted < 0) {
            $flashError = 'Planned spend must be a valid non-negative amount.';
        } elseif ($formAttendeeContributionTarget === '' || !is_numeric($formAttendeeContributionTarget) || (float)$formAttendeeContributionTarget < 0) {
            $flashError = 'Attendee contribution target must be a valid non-negative amount.';
        } elseif ($formVendorFeeAmount === '' || !is_numeric($formVendorFeeAmount) || (float)$formVendorFeeAmount < 0) {
            $flashError = 'Vendor event fee must be a valid non-negative amount.';
        }

        $formPlannedItems = [];
        $plannedCommitted = 0.0;

        if ($flashError === '') {
            $rowCount = max(count($formItemNames), count($formItemAmounts));
            for ($i = 0; $i < $rowCount; $i++) {
                $name = trim((string)($formItemNames[$i] ?? ''));
                $amountRaw = trim((string)($formItemAmounts[$i] ?? ''));

                if ($name === '' && $amountRaw === '') {
                    continue;
                }

                if ($name === '') {
                    $flashError = 'Each planned amount must include an item name.';
                    break;
                }

                if ($amountRaw === '' || !is_numeric($amountRaw) || (float)$amountRaw < 0) {
                    $flashError = 'Each planned item must have a valid non-negative amount.';
                    break;
                }

                $amount = (float)$amountRaw;
                $formPlannedItems[] = [
                    'item_name' => $name,
                    'planned_amount' => $amount,
                ];
                $plannedCommitted += $amount;
            }
        }

        $formBudgetTotalFloat = is_numeric($formBudgetTotal) ? (float)$formBudgetTotal : 0.0;
        $formBudgetCommittedFloat = is_numeric($formBudgetCommitted) ? (float)$formBudgetCommitted : 0.0;
        $formAttendeeContributionTargetFloat = is_numeric($formAttendeeContributionTarget) ? (float)$formAttendeeContributionTarget : 0.0;
        $formVendorFeeAmountFloat = is_numeric($formVendorFeeAmount) ? (float)$formVendorFeeAmount : 0.0;

        if ($flashError === '' && $plannedCommitted > $formBudgetTotalFloat) {
            $flashError = 'Planned line items exceed planned budget.';
        } elseif ($flashError === '' && $formBudgetCommittedFloat > $formBudgetTotalFloat) {
            $flashError = 'Planned spend cannot be higher than budget plan.';
        }

        $normalizedTicketTypes = [
            'early_bird' => 0.0,
            'regular' => 0.0,
            'vip' => 0.0,
            'vvip' => 0.0,
        ];
        $normalizedTicketTypeDescriptions = [
            'early_bird' => '',
            'regular' => '',
            'vip' => '',
            'vvip' => '',
        ];
        if ($flashError === '') {
            foreach ($normalizedTicketTypes as $typeKey => $_default) {
                $priceRaw = trim((string)($formTicketTypes[$typeKey] ?? '0'));
                if ($priceRaw === '' || !is_numeric($priceRaw) || (float)$priceRaw < 0) {
                    $flashError = 'Each ticket type price must be a valid non-negative amount.';
                    break;
                }
                $normalizedTicketTypes[$typeKey] = (float)$priceRaw;
                $normalizedTicketTypeDescriptions[$typeKey] = trim((string)($formTicketTypeDescriptions[$typeKey] ?? ''));
            }

            if ($flashError === '' && ($normalizedTicketTypeDescriptions['vip'] === '' || $normalizedTicketTypeDescriptions['vvip'] === '')) {
                $flashError = 'VIP and VVIP ticket types must include descriptions.';
            }
        }

        $normalizedSponsorships = [];
        $normalizedSponsorshipTotal = 0.0;
        if ($flashError === '') {
            $rowCount = max(count($formSponsorshipNames), count($formSponsorshipAmounts));
            for ($i = 0; $i < $rowCount; $i++) {
                $name = trim((string)($formSponsorshipNames[$i] ?? ''));
                $amountRaw = trim((string)($formSponsorshipAmounts[$i] ?? ''));
                if ($name === '' && $amountRaw === '') {
                    continue;
                }
                if ($name === '') {
                    $flashError = 'Each sponsorship amount must include a sponsor name.';
                    break;
                }
                if ($amountRaw === '' || !is_numeric($amountRaw) || (float)$amountRaw < 0) {
                    $flashError = 'Each sponsorship amount must be a valid non-negative amount.';
                    break;
                }
                $amount = (float)$amountRaw;
                $normalizedSponsorships[] = [
                    'sponsor_name' => $name,
                    'contribution_amount' => $amount,
                ];
                $normalizedSponsorshipTotal += $amount;
            }
        }

        // Always derive Budget Plan from planned line items when saving.
        $formBudgetTotalFloat = $plannedCommitted;
        if ($formBudgetCommittedFloat > $formBudgetTotalFloat) {
            $flashError = 'Planned spend cannot be higher than budget plan.';
        }

        $formTicketPriceFloat = (float)($normalizedTicketTypes['regular'] ?? 0);

        if ($flashError === '') {
            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare(
                    "UPDATE events
                     SET budget_total = ?,
                         budget_committed = ?,
                         ticket_price = ?,
                         attendee_contribution_target = ?,
                         vendor_fee_amount = ?
                     WHERE event_id = ? AND planner_id = ?"
                );
                $stmt->execute([
                    $formBudgetTotalFloat,
                    $formBudgetCommittedFloat,
                    $formTicketPriceFloat,
                    $formAttendeeContributionTargetFloat,
                    $formVendorFeeAmountFloat,
                    $eventId,
                    $_SESSION['user_id'],
                ]);

                $stmt = $pdo->prepare('DELETE FROM event_budget_items WHERE event_id = ?');
                $stmt->execute([$eventId]);

                if (!empty($formPlannedItems)) {
                    $itemStmt = $pdo->prepare(
                        'INSERT INTO event_budget_items (event_id, item_name, planned_amount, spent_amount, sort_order) VALUES (?, ?, ?, 0, ?)'
                    );
                    foreach ($formPlannedItems as $index => $item) {
                        $itemStmt->execute([$eventId, $item['item_name'], $item['planned_amount'], $index + 1]);
                    }
                }

                $stmt = $pdo->prepare('DELETE FROM event_ticket_types WHERE event_id = ?');
                $stmt->execute([$eventId]);

                $ticketStmt = $pdo->prepare('INSERT INTO event_ticket_types (event_id, ticket_type, price, description) VALUES (?, ?, ?, ?)');
                foreach ($normalizedTicketTypes as $typeKey => $price) {
                    $ticketStmt->execute([$eventId, $typeKey, $price, $normalizedTicketTypeDescriptions[$typeKey] !== '' ? $normalizedTicketTypeDescriptions[$typeKey] : null]);
                }

                $stmt = $pdo->prepare('DELETE FROM event_sponsorships WHERE event_id = ?');
                $stmt->execute([$eventId]);

                if (!empty($normalizedSponsorships)) {
                    $sponsorStmt = $pdo->prepare(
                        'INSERT INTO event_sponsorships (event_id, sponsor_name, contribution_amount) VALUES (?, ?, ?)'
                    );
                    foreach ($normalizedSponsorships as $sponsorship) {
                        $sponsorStmt->execute([$eventId, $sponsorship['sponsor_name'], $sponsorship['contribution_amount']]);
                    }
                }

                $pdo->commit();

                audit_log(
                    $pdo,
                    (int)$_SESSION['user_id'],
                    (string)$_SESSION['role'],
                    'event.budget_plan_updated',
                    'event',
                    (string)$eventId,
                    [
                        'planned_budget_total' => $formBudgetTotalFloat,
                        'planned_budget_committed' => $formBudgetCommittedFloat,
                        'planned_items_total' => $plannedCommitted,
                        'planned_item_count' => count($formPlannedItems),
                        'ticket_type_prices' => $normalizedTicketTypes,
                        'ticket_type_descriptions' => $normalizedTicketTypeDescriptions,
                        'sponsorship_total' => $normalizedSponsorshipTotal,
                        'sponsorship_count' => count($normalizedSponsorships),
                        'attendee_contribution_target' => $formAttendeeContributionTargetFloat,
                        'vendor_fee_amount' => $formVendorFeeAmountFloat,
                    ]
                );

                $_SESSION['flash_success'] = 'Planned budget breakdown updated.';
                header('Location: budget_breakdown.php?event_id=' . $eventId, true, 303);
                exit;
            } catch (Throwable $txe) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $txe;
            }
        } else {
            $plannedItems = $formPlannedItems;
            $event['budget_total'] = $formBudgetTotalFloat;
            $event['ticket_price'] = $formTicketPriceFloat;
            $event['budget_committed'] = $formBudgetCommittedFloat;
            $event['attendee_contribution_target'] = $formAttendeeContributionTargetFloat;
            $event['vendor_fee_amount'] = $formVendorFeeAmountFloat;
            $ticketTypes = $normalizedTicketTypes;
            $ticketTypeDescriptions = $normalizedTicketTypeDescriptions;
            $sponsorships = $normalizedSponsorships;
            if (empty($sponsorships)) {
                $sponsorships = [
                    ['sponsor_name' => '', 'contribution_amount' => 0],
                ];
            }
        }
    }

    $stmt = $pdo->prepare(
        "SELECT b.booking_id,
                s.name AS service_name,
                v.business_name,
                b.booked_price,
                b.status AS booking_status,
                COALESCE(t.status, 'pending') AS payment_status,
                COALESCE(t.amount, 0) AS paid_amount,
                b.created_at
         FROM bookings b
         JOIN events e ON e.event_id = b.event_id
         JOIN services s ON s.service_id = b.service_id
         JOIN vendors v ON v.vendor_id = s.vendor_id
         LEFT JOIN transactions t ON t.booking_id = b.booking_id
         WHERE b.event_id = ? AND e.planner_id = ? AND e.archived_at IS NULL
         ORDER BY b.created_at DESC"
    );
    $stmt->execute([$eventId, $_SESSION['user_id']]);
    $lineItems = $stmt->fetchAll();

    foreach ($lineItems as $item) {
        $amount = (float)$item['booked_price'];
        $summary['items_total'] += $amount;

        if (strtolower((string)$item['booking_status']) === 'confirmed') {
            $summary['items_confirmed'] += $amount;
        }
        if (strtolower((string)$item['payment_status']) === 'paid') {
            $summary['items_paid'] += $amount;
        }
    }

    $summary['pending_balance'] = max(0, $summary['items_total'] - $summary['items_paid']);

    $stmt = $pdo->prepare(
        "SELECT v.vendor_id,
                v.business_name,
                COUNT(b.booking_id) AS item_count,
                COALESCE(SUM(b.booked_price), 0) AS total_contribution,
                COALESCE(SUM(CASE WHEN b.status = 'confirmed' THEN b.booked_price ELSE 0 END), 0) AS confirmed_contribution,
                COALESCE(SUM(CASE WHEN COALESCE(t.status, 'pending') = 'paid' THEN b.booked_price ELSE 0 END), 0) AS paid_contribution
         FROM bookings b
         JOIN events e ON e.event_id = b.event_id
         JOIN services s ON s.service_id = b.service_id
         JOIN vendors v ON v.vendor_id = s.vendor_id
         LEFT JOIN transactions t ON t.booking_id = b.booking_id
         WHERE b.event_id = ? AND e.planner_id = ? AND e.archived_at IS NULL
         GROUP BY v.vendor_id, v.business_name
         ORDER BY total_contribution DESC"
    );
    $stmt->execute([$eventId, $_SESSION['user_id']]);
    $vendorContributions = $stmt->fetchAll();

    $stmt = $pdo->prepare(
        "SELECT COALESCE(SUM(b.platform_fee), 0)
         FROM bookings b
         JOIN events e ON e.event_id = b.event_id
                 WHERE b.event_id = ?
           AND e.planner_id = ?
                     AND e.archived_at IS NULL
           AND b.status = 'confirmed'"
    );
    $stmt->execute([$eventId, $_SESSION['user_id']]);
    $vendorRevenue = (float)$stmt->fetchColumn();

    $stmt = $pdo->prepare(
        "SELECT COALESCE(SUM(amount), 0)
         FROM event_financial_adjustments
         WHERE event_id = ? AND entry_kind = 'cash_available'"
    );
    $stmt->execute([$eventId]);
    $manualCashAdded = (float)$stmt->fetchColumn();

    $stmt = $pdo->prepare(
        "SELECT COALESCE(SUM(contribution_amount), 0)
         FROM event_sponsorships
         WHERE event_id = ?"
    );
    $stmt->execute([$eventId]);
    $sponsorshipTotal = (float)$stmt->fetchColumn();

    $stmt = $pdo->prepare(
        "SELECT COALESCE(SUM(t.amount), 0)
         FROM bookings b
         LEFT JOIN transactions t ON t.booking_id = b.booking_id
         WHERE b.event_id = ? AND t.status = 'paid'"
    );
    $stmt->execute([$eventId]);
    $paidTransactionTotal = (float)$stmt->fetchColumn();
} catch (Throwable $e) {
    $flashError = 'Unable to load budget breakdown right now.';
    error_log('admin/budget_breakdown.php error: ' . $e->getMessage());
}

$budgetTotal = (float)($event['budget_total'] ?? 0);
$budgetCommitted = (float)($event['budget_committed'] ?? 0);
$ticketRevenue = (float)($event['ticket_revenue'] ?? 0);
$attendeeContributionTarget = (float)($event['attendee_contribution_target'] ?? 0);
$vendorFeeAmount = (float)($event['vendor_fee_amount'] ?? 100);
$plannedItemsTotal = 0.0;
$itemSpentTotal = 0.0;
foreach ($plannedItems as $pi) {
    $plannedItemsTotal += (float)($pi['planned_amount'] ?? 0);
    $itemSpentTotal += (float)($pi['spent_amount'] ?? 0);
}
$moneyReceived = $ticketRevenue + $vendorRevenue + $manualCashAdded + $sponsorshipTotal;
$moneySpent = $paidTransactionTotal + $itemSpentTotal;
$cashLeft = max(0, $moneyReceived - $moneySpent);
$utilizationPercent = $budgetTotal > 0 ? min(100, ($budgetCommitted / $budgetTotal) * 100) : 0;

$editBudgetTotal = $budgetTotal;
$editBudgetCommitted = $budgetCommitted;
$editAttendeeContributionTarget = $attendeeContributionTarget;
$editVendorFeeAmount = $vendorFeeAmount;
$editPlannedItems = $plannedItems;
if (empty($editPlannedItems)) {
    $editPlannedItems = [
        ['item_name' => '', 'planned_amount' => 0],
        ['item_name' => '', 'planned_amount' => 0],
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="../assets/style.css">
    <title>Planora - Event Budget Breakdown</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; }
        body { background: #F5F5F5; color: #2D2D2D; min-height: 100vh; }
        .header { background: #6C63FF; color: #fff; padding: 14px 24px; display: flex; justify-content: space-between; align-items: center; }
        .brand { font-size: 22px; font-weight: 700; }
        .logout-btn { background: rgba(255,255,255,0.22); color: #fff; text-decoration: none; border-radius: 6px; padding: 7px 14px; font-size: 13px; }
        .container { max-width: 1200px; margin: 0 auto; padding: 20px; }
        .links { display: flex; gap: 10px; margin-bottom: 14px; flex-wrap: wrap; }
        .link-btn { text-decoration: none; background: #ece9ff; color: #3b3496; border-radius: 6px; padding: 8px 12px; font-size: 13px; }
        .card { background: #fff; border-radius: 8px; border: 1px solid #E5E7EB; padding: 20px; box-shadow: 0 1px 3px rgba(0,0,0,.05); }
        .title { font-size: 20px; font-weight: 700; margin-bottom: 10px; }
        .subtitle { color: #666; font-size: 13px; margin-bottom: 16px; }
        .message-error { background: #ffecec; color: #9d2020; border: 1px solid #f6caca; border-radius: 6px; padding: 10px 12px; margin-bottom: 14px; font-size: 13px; }
        .message-success { background: #ecfdf3; color: #0f5132; border: 1px solid #b8ebcf; border-radius: 6px; padding: 10px 12px; margin-bottom: 14px; font-size: 13px; }
        .edit-grid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 10px; margin-bottom: 12px; }
        .edit-field label { display: block; font-size: 12px; color: #5a5a5a; margin-bottom: 6px; font-weight: 600; }
        .edit-field input { width: 100%; border: 1px solid #d6d6d6; border-radius: 6px; padding: 10px; font-size: 13px; }
        .edit-items-wrap { border: 1px solid #ECECEC; border-radius: 8px; padding: 12px; background: #fbfbff; margin-bottom: 12px; }
        .edit-item-row { display: grid; grid-template-columns: 2fr 1fr; gap: 8px; margin-bottom: 8px; align-items: center; }
        .edit-item-row:last-child { margin-bottom: 0; }
        .edit-item-row input { width: 100%; border: 1px solid #d6d6d6; border-radius: 6px; padding: 9px; font-size: 13px; }
        .name-with-example { display: flex; flex-direction: column; gap: 4px; }
        .item-example-text { font-size: 11px; color: #6a6a6a; }
        .sponsorship-row { display: grid; grid-template-columns: 2fr 1fr; gap: 8px; margin-bottom: 8px; align-items: center; }
        .sponsorship-row:last-child { margin-bottom: 0; }
        .sponsorship-row input { width: 100%; border: 1px solid #d6d6d6; border-radius: 6px; padding: 9px; font-size: 13px; }
        .small-btn { border: 1px solid #d0d0d0; border-radius: 6px; background: #fff; color: #444; font-size: 12px; padding: 8px 10px; cursor: pointer; }
        .small-btn-add { background: #198754; color: #fff; border-color: #198754; font-weight: 700; }
        .small-btn-add:hover { background: #157347; }
        .small-btn-remove { background: #c62828; color: #fff; border-color: #c62828; font-size: 11px; padding: 7px 10px; }
        .small-btn-remove:hover { background: #a61f1f; }
        .item-controls-row { margin-top: 8px; display: flex; gap: 8px; align-items: center; }
        .primary-btn { border: none; border-radius: 6px; background: #6C63FF; color: #fff; font-size: 13px; font-weight: 700; padding: 10px 14px; cursor: pointer; }
        .summary-grid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 12px; margin-bottom: 16px; }
        .summary-item { border: 1px solid #ECECEC; border-radius: 8px; padding: 12px; background: #FAFAFA; }
        .summary-value { color: #6C63FF; font-size: 22px; font-weight: 700; }
        .summary-label { color: #777; font-size: 12px; margin-top: 4px; }
        .budget-list { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 14px; }
        .budget-metric { font-size: 14px; color: #4B5563; }
        .budget-metric strong { color: #2D2D2D; }
        .progress-track { width: 100%; height: 8px; background: #E5E7EB; border-radius: 4px; overflow: hidden; margin-top: 12px; }
        .progress-fill { height: 100%; background: #6C63FF; }
        .section-title { font-size: 17px; font-weight: 700; margin: 18px 0 10px; }
        .table-wrap { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 10px; border-bottom: 1px solid #EFEFEF; text-align: left; font-size: 13px; }
        th { background: #FAFAFA; color: #555; font-weight: 700; }
        .badge { display: inline-block; padding: 3px 8px; border-radius: 999px; font-size: 11px; font-weight: 700; }
        .badge-paid { background: #e8f9ef; color: #1c7a36; }
        .badge-pending { background: #fff4df; color: #a36500; }
        .badge-failed { background: #ffe8e8; color: #a22b2b; }
        .empty { font-size: 14px; color: #777; padding: 8px 0; }
        @media (max-width: 1000px) {
            .edit-grid { grid-template-columns: 1fr 1fr; }
            .edit-item-row { grid-template-columns: 1fr; }
            .summary-grid { grid-template-columns: 1fr 1fr; }
            .budget-list { grid-template-columns: 1fr; }
        }
        @media (max-width: 620px) {
            .summary-grid { grid-template-columns: 1fr; }
        }
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
        <a class="link-btn" href="mpesa_payments.php"><i class="fa-solid fa-book-bookmark"></i> Payments</a>
    </div>

    <section class="card">
        <h1 class="title">Event Budget Breakdown</h1>
        <p class="subtitle">
            <?php if ($event): ?>
                <?php echo htmlspecialchars((string)$event['title']); ?>
                (<?php echo htmlspecialchars((string)$event['event_date']); ?>)
            <?php endif; ?>
        </p>

        <?php if ($flashError !== ''): ?>
            <div class="message-error"><?php echo htmlspecialchars($flashError); ?></div>
        <?php endif; ?>
        <?php if ($flashSuccess !== ''): ?>
            <div class="message-success"><?php echo htmlspecialchars($flashSuccess); ?></div>
        <?php endif; ?>

        <h2 class="section-title">Edit Planned Budget</h2>
        <form method="POST" id="planned-budget-form">
            <?php echo csrf_input(); ?>
            <input type="hidden" name="save_planned_budget" value="1">
            <div class="edit-grid">
                <div class="edit-field">
                    <label for="edit_budget_total">Budget Plan (KES)</label>
                    <input id="edit_budget_total" type="number" step="0.01" min="0" name="budget_total" value="<?php echo htmlspecialchars((string)$editBudgetTotal); ?>" required readonly>
                </div>
                <div class="edit-field">
                    <label for="edit_budget_committed">Planned Spend (KES)</label>
                    <input id="edit_budget_committed" type="number" step="0.01" min="0" name="budget_committed" value="<?php echo htmlspecialchars((string)$editBudgetCommitted); ?>" required>
                </div>
                <div class="edit-field">
                    <label for="edit_vendor_received">Vendor Money (Received) (KES)</label>
                    <input id="edit_vendor_received" type="number" step="0.01" min="0" value="<?php echo htmlspecialchars((string)$vendorRevenue); ?>" readonly>
                </div>
                <div class="edit-field">
                    <label for="edit_attendee_target">Attendee Contribution Target (KES)</label>
                    <input id="edit_attendee_target" type="number" step="0.01" min="0" name="attendee_contribution_target" value="<?php echo htmlspecialchars((string)$editAttendeeContributionTarget); ?>" required>
                </div>
                <div class="edit-field">
                    <label for="edit_vendor_fee_amount">Vendor Event Fee (KES)</label>
                    <input id="edit_vendor_fee_amount" type="number" step="0.01" min="0" name="vendor_fee_amount" value="<?php echo htmlspecialchars((string)$editVendorFeeAmount); ?>" required>
                </div>
            </div>

            <div class="edit-items-wrap" style="margin-top: 0;">
                <div style="font-size:13px; font-weight:700; margin-bottom:8px;">Sponsorships</div>
                <div id="sponsorship_rows">
                    <?php foreach ($sponsorships as $sponsorship): ?>
                        <div class="sponsorship-row">
                            <input type="text" name="sponsorship_name[]" placeholder="Sponsor name" value="<?php echo htmlspecialchars((string)($sponsorship['sponsor_name'] ?? '')); ?>">
                            <input type="number" name="sponsorship_amount[]" step="0.01" min="0" placeholder="Contribution amount" value="<?php echo htmlspecialchars((string)((float)($sponsorship['contribution_amount'] ?? 0))); ?>">
                        </div>
                    <?php endforeach; ?>
                </div>
                <div class="item-controls-row">
                    <button type="button" id="add_sponsorship_row" class="small-btn small-btn-add">Add Sponsorship</button>
                    <button type="button" id="remove_sponsorship_row" class="small-btn small-btn-remove">Remove Last</button>
                </div>
            </div>

            <div class="edit-items-wrap" style="margin-top: 0;">
                <div style="font-size:13px; font-weight:700; margin-bottom:8px;">Ticket Types (KES)</div>
                <div class="edit-grid" style="margin-bottom: 0;">
                    <div class="edit-field">
                        <label for="ticket_type_early_bird">Early Bird</label>
                        <input id="ticket_type_early_bird" type="number" step="0.01" min="0" name="ticket_type_price[early_bird]" value="<?php echo htmlspecialchars((string)$ticketTypes['early_bird']); ?>" required>
                    </div>
                    <div class="edit-field">
                        <label for="ticket_type_regular">Regular</label>
                        <input id="ticket_type_regular" type="number" step="0.01" min="0" name="ticket_type_price[regular]" value="<?php echo htmlspecialchars((string)$ticketTypes['regular']); ?>" required>
                    </div>
                    <div class="edit-field">
                        <label for="ticket_type_vip">VIP</label>
                        <input id="ticket_type_vip" type="number" step="0.01" min="0" name="ticket_type_price[vip]" value="<?php echo htmlspecialchars((string)$ticketTypes['vip']); ?>" required>
                        <input type="text" name="ticket_type_description[vip]" placeholder="VIP description" value="<?php echo htmlspecialchars((string)$ticketTypeDescriptions['vip']); ?>" style="margin-top:6px;" required>
                    </div>
                    <div class="edit-field">
                        <label for="ticket_type_vvip">VVIP</label>
                        <input id="ticket_type_vvip" type="number" step="0.01" min="0" name="ticket_type_price[vvip]" value="<?php echo htmlspecialchars((string)$ticketTypes['vvip']); ?>" required>
                        <input type="text" name="ticket_type_description[vvip]" placeholder="VVIP description" value="<?php echo htmlspecialchars((string)$ticketTypeDescriptions['vvip']); ?>" style="margin-top:6px;" required>
                    </div>
                </div>
            </div>

            <div class="edit-items-wrap">
                <div style="font-size:13px; font-weight:700; margin-bottom:8px;">Planned Line Items</div>
                <div id="edit_planned_item_rows">
                    <?php foreach ($editPlannedItems as $item): ?>
                        <div class="edit-item-row">
                            <div class="name-with-example">
                                <input type="text" name="planned_item_name[]" class="js-planned-name" placeholder="Item name" value="<?php echo htmlspecialchars((string)($item['item_name'] ?? '')); ?>">
                                <div class="item-example-text">Examples: Venue, Catering, Decor, Sound, Security</div>
                            </div>
                            <input type="number" name="planned_item_amount[]" class="js-planned-amount" step="0.01" min="0" placeholder="0.00" value="<?php echo htmlspecialchars((string)((float)($item['planned_amount'] ?? 0))); ?>">
                        </div>
                    <?php endforeach; ?>
                </div>
                <div class="item-controls-row">
                    <button type="button" id="add_planned_row" class="small-btn small-btn-add">Add Item</button>
                    <button type="button" id="remove_planned_row" class="small-btn small-btn-remove">Remove Last</button>
                </div>
            </div>

            <button type="submit" class="primary-btn">Save Planned Budget</button>
        </form>

        <div class="summary-grid">
            <div class="summary-item">
                <div class="summary-value">KES <?php echo number_format($summary['items_total'], 2); ?></div>
                <div class="summary-label">Total Item Cost</div>
            </div>
            <div class="summary-item">
                <div class="summary-value">KES <?php echo number_format($summary['items_confirmed'], 2); ?></div>
                <div class="summary-label">Confirmed Commitments</div>
            </div>
            <div class="summary-item">
                <div class="summary-value">KES <?php echo number_format($summary['items_paid'], 2); ?></div>
                <div class="summary-label">Paid Vendor Cost</div>
            </div>
            <div class="summary-item">
                <div class="summary-value">KES <?php echo number_format($summary['pending_balance'], 2); ?></div>
                <div class="summary-label">Pending Vendor Balance</div>
            </div>
        </div>

        <div class="budget-list">
            <div class="budget-metric">Budget Plan: <strong>KES <?php echo number_format($budgetTotal, 2); ?></strong></div>
            <div class="budget-metric">Planned Spend: <strong>KES <?php echo number_format($budgetCommitted, 2); ?></strong></div>
            <div class="budget-metric">Money Spent: <strong>KES <?php echo number_format($moneySpent, 2); ?></strong></div>
            <div class="budget-metric">Item Spend Total: <strong>KES <?php echo number_format($itemSpentTotal, 2); ?></strong></div>
            <div class="budget-metric">Attendee Money: <strong>KES <?php echo number_format($ticketRevenue, 2); ?></strong></div>
            <div class="budget-metric">Vendor Money: <strong>KES <?php echo number_format($vendorRevenue, 2); ?></strong></div>
            <div class="budget-metric">Sponsorships: <strong>KES <?php echo number_format($sponsorshipTotal, 2); ?></strong></div>
            <div class="budget-metric">Extra Cash: <strong>KES <?php echo number_format($manualCashAdded, 2); ?></strong></div>
            <div class="budget-metric">Money Received: <strong>KES <?php echo number_format($moneyReceived, 2); ?></strong></div>
            <div class="budget-metric">Cash Left: <strong>KES <?php echo number_format($cashLeft, 2); ?></strong></div>
            <div class="budget-metric">Attendee Money Target: <strong>KES <?php echo number_format($attendeeContributionTarget, 2); ?></strong></div>
            <div class="budget-metric">Planned Items Total: <strong>KES <?php echo number_format($plannedItemsTotal, 2); ?></strong></div>
            <div class="budget-metric">Vendor Event Fee: <strong>KES <?php echo number_format($vendorFeeAmount, 2); ?></strong></div>
        </div>
        <div class="progress-track"><div class="progress-fill" style="width: <?php echo $utilizationPercent; ?>%;"></div></div>

        <h2 class="section-title">Planned Budget Breakdown</h2>
        <?php if (empty($plannedItems)): ?>
            <div class="empty">No planned budget line items were added at event creation.</div>
        <?php else: ?>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Planned Item</th>
                            <th>Planned</th>
                            <th>Spent</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($plannedItems as $item): ?>
                            <tr>
                                <td><?php echo htmlspecialchars((string)$item['item_name']); ?></td>
                                <td>KES <?php echo number_format((float)$item['planned_amount'], 2); ?></td>
                                <td>KES <?php echo number_format((float)($item['spent_amount'] ?? 0), 2); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <h2 class="section-title">Required Event Items</h2>
        <?php if (empty($lineItems)): ?>
            <div class="empty">No booked services yet for this event.</div>
        <?php else: ?>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Vendor</th>
                            <th>Item / Service</th>
                            <th>Price</th>
                            <th>Booking</th>
                            <th>Payment</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($lineItems as $item): ?>
                            <?php
                                $paymentStatus = strtolower((string)$item['payment_status']);
                                $paymentBadge = $paymentStatus === 'paid' ? 'badge-paid' : ($paymentStatus === 'failed' ? 'badge-failed' : 'badge-pending');
                                $bookingConfirmed = strtolower((string)$item['booking_status']) === 'confirmed';
                            ?>
                            <tr>
                                <td><?php echo htmlspecialchars((string)$item['business_name']); ?></td>
                                <td><?php echo htmlspecialchars((string)$item['service_name']); ?></td>
                                <td>KES <?php echo number_format((float)$item['booked_price'], 2); ?></td>
                                <td>
                                    <span class="badge <?php echo $bookingConfirmed ? 'badge-paid' : 'badge-pending'; ?>">
                                        <?php echo htmlspecialchars(ucfirst((string)$item['booking_status'])); ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="badge <?php echo $paymentBadge; ?>">
                                        <?php echo htmlspecialchars(ucfirst($paymentStatus)); ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <h2 class="section-title">Vendor Contributions</h2>
        <?php if (empty($vendorContributions)): ?>
            <div class="empty">No vendor contribution data yet.</div>
        <?php else: ?>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Vendor</th>
                            <th>Items</th>
                            <th>Total Contribution</th>
                            <th>Confirmed</th>
                            <th>Paid</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($vendorContributions as $row): ?>
                            <tr>
                                <td><?php echo htmlspecialchars((string)$row['business_name']); ?></td>
                                <td><?php echo (int)$row['item_count']; ?></td>
                                <td>KES <?php echo number_format((float)$row['total_contribution'], 2); ?></td>
                                <td>KES <?php echo number_format((float)$row['confirmed_contribution'], 2); ?></td>
                                <td>KES <?php echo number_format((float)$row['paid_contribution'], 2); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
</div>
<script>
(() => {
    const rowsWrap = document.getElementById('edit_planned_item_rows');
    const addBtn = document.getElementById('add_planned_row');
    const removeBtn = document.getElementById('remove_planned_row');
    const sponsorshipWrap = document.getElementById('sponsorship_rows');
    const addSponsorshipBtn = document.getElementById('add_sponsorship_row');
    const removeSponsorshipBtn = document.getElementById('remove_sponsorship_row');
    const budgetPlanInput = document.getElementById('edit_budget_total');
    const form = document.getElementById('planned-budget-form');

    if (!rowsWrap || !addBtn || !removeBtn || !form) {
        return;
    }

    const recalcBudgetPlan = () => {
        if (!budgetPlanInput) {
            return;
        }
        let total = 0;
        rowsWrap.querySelectorAll('.js-planned-amount').forEach((input) => {
            const value = parseFloat(input.value);
            if (Number.isFinite(value) && value >= 0) {
                total += value;
            }
        });
        budgetPlanInput.value = total.toFixed(2);
    };

    rowsWrap.querySelectorAll('.js-planned-amount').forEach((input) => {
        input.addEventListener('input', recalcBudgetPlan);
    });

    addBtn.addEventListener('click', () => {
        const row = document.createElement('div');
        row.className = 'edit-item-row';
        row.innerHTML =
            '<div class="name-with-example">' +
            '<input type="text" name="planned_item_name[]" class="js-planned-name" placeholder="Item name">' +
            '<div class="item-example-text">Examples: Venue, Catering, Decor, Sound, Security</div>' +
            '</div>' +
            '<input type="number" name="planned_item_amount[]" class="js-planned-amount" step="0.01" min="0" placeholder="0.00">';
        rowsWrap.appendChild(row);
        const amountInput = row.querySelector('.js-planned-amount');
        if (amountInput) {
            amountInput.addEventListener('input', recalcBudgetPlan);
        }
        recalcBudgetPlan();
    });

    removeBtn.addEventListener('click', () => {
        const rows = rowsWrap.querySelectorAll('.edit-item-row');
        if (rows.length <= 1) {
            const nameInput = rows[0] ? rows[0].querySelector('.js-planned-name') : null;
            const amountInput = rows[0] ? rows[0].querySelector('.js-planned-amount') : null;
            if (nameInput) {
                nameInput.value = '';
            }
            if (amountInput) {
                amountInput.value = '';
            }
            recalcBudgetPlan();
            return;
        }
        rows[rows.length - 1].remove();
        recalcBudgetPlan();
    });

    form.addEventListener('submit', () => {
        recalcBudgetPlan();
    });

    if (sponsorshipWrap && addSponsorshipBtn && removeSponsorshipBtn) {
        addSponsorshipBtn.addEventListener('click', () => {
            const row = document.createElement('div');
            row.className = 'sponsorship-row';
            row.innerHTML =
                '<input type="text" name="sponsorship_name[]" placeholder="Sponsor name">' +
                '<input type="number" name="sponsorship_amount[]" step="0.01" min="0" placeholder="Contribution amount">';
            sponsorshipWrap.appendChild(row);
        });

        removeSponsorshipBtn.addEventListener('click', () => {
            const rows = sponsorshipWrap.querySelectorAll('.sponsorship-row');
            if (rows.length <= 1) {
                const nameInput = rows[0] ? rows[0].querySelector('input[name="sponsorship_name[]"]') : null;
                const amountInput = rows[0] ? rows[0].querySelector('input[name="sponsorship_amount[]"]') : null;
                if (nameInput) {
                    nameInput.value = '';
                }
                if (amountInput) {
                    amountInput.value = '';
                }
                return;
            }
            rows[rows.length - 1].remove();
        });
    }

    recalcBudgetPlan();
})();
</script>
</body>
</html>
