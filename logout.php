<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once 'config/db.php';
require_once 'includes/audit.php';

if (isset($_SESSION['user_id'])) {
    audit_log(
        $pdo,
        (int)$_SESSION['user_id'],
        $_SESSION['role'] ?? null,
        'auth.logout'
    );
}

$_SESSION = [];

if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
}

session_destroy();
header('Location: login.php');
exit;
