<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/csrf.php';

const LOGIN_PATH = '../login.php';
const ADMIN_DASHBOARD_PATH = '../admin/dashboard.php';
const VENDOR_DASHBOARD_PATH = '../vendor/dashboard.php';
const ATTENDEE_DASHBOARD_PATH = '../attendee/dashboard.php';

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
        header('Location: ' . getDashboardPathByRole($_SESSION['role']));
        exit;
    }
}
?>
