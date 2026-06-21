<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../config/db.php';

const LOGIN_PATH = '/event/auth/login.php';
const ADMIN_DASHBOARD_PATH = '/event/admin/dashboard.php';
const VENDOR_DASHBOARD_PATH = '/event/vendor/dashboard.php';
const ATTENDEE_DASHBOARD_PATH = '/event/attendee/dashboard.php';

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
