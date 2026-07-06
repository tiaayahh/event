<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    $secureCookie = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $secureCookie,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/audit.php';

const LOGIN_PATH = '../login.php';
const ADMIN_DASHBOARD_PATH = '../admin/dashboard.php';
const VENDOR_DASHBOARD_PATH = '../vendor/dashboard.php';
const ATTENDEE_DASHBOARD_PATH = '../attendee/dashboard.php';
const SESSION_IDLE_TIMEOUT_SECONDS = 0;

function ensureEventsArchiveColumn(PDO $pdo): void {
    static $ready = false;
    if ($ready) {
        return;
    }

    $stmt = $pdo->query("SHOW COLUMNS FROM events LIKE 'archived_at'");
    if (!$stmt->fetch()) {
        $pdo->exec("ALTER TABLE events ADD COLUMN archived_at DATETIME NULL DEFAULT NULL");
        $pdo->exec("CREATE INDEX idx_events_archived_at ON events (archived_at)");
    }

    $ready = true;
}

function getDashboardPathByRole($role) {
    switch ($role) {
        case 'planner':
            return ADMIN_DASHBOARD_PATH;
        case 'vendor':
            return VENDOR_DASHBOARD_PATH;
        case 'attendee':
            return ATTENDEE_DASHBOARD_PATH;
        default:
            return LOGIN_PATH;
    }
}

function checkAuth() {
    if (!isset($_SESSION['user_id'])) {
        header('Location: ' . LOGIN_PATH);
        exit;
    }

    $now = time();
    $lastActivityAt = (int)($_SESSION['last_activity_at'] ?? 0);
    if (SESSION_IDLE_TIMEOUT_SECONDS > 0 && $lastActivityAt > 0 && ($now - $lastActivityAt) > SESSION_IDLE_TIMEOUT_SECONDS) {
        audit_log(
            $GLOBALS['pdo'],
            isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null,
            $_SESSION['role'] ?? null,
            'auth.session_timeout'
        );

        $_SESSION = [];
        session_destroy();
        header('Location: ' . LOGIN_PATH . '?session=expired');
        exit;
    }
    $_SESSION['last_activity_at'] = $now;
    ensureEventsArchiveColumn($GLOBALS['pdo']);

    // Prevent cached authenticated pages from replaying stale CSRF tokens.
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_require_valid_post_token();
    }
}

function requireRole($role) {
    if (!isset($_SESSION['role'])) {
        header('Location: ' . LOGIN_PATH);
        exit;
    }

    if ($_SESSION['role'] !== $role) {
        $_SESSION['flash_error'] = 'Access denied. This page requires ' . $role . ' role.';
        audit_log(
            $GLOBALS['pdo'],
            isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null,
            $_SESSION['role'] ?? null,
            'auth.role_denied',
            'route',
            $_SERVER['REQUEST_URI'] ?? null,
            ['required_role' => $role]
        );
        header('Location: ' . getDashboardPathByRole($_SESSION['role']));
        exit;
    }
}
?>
