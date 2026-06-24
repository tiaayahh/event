<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!function_exists('csrf_token')) {
    function csrf_token(): string
    {
        if (empty($_SESSION['_csrf_token'])) {
            $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
        }

        return (string)$_SESSION['_csrf_token'];
    }
}

if (!function_exists('csrf_input')) {
    function csrf_input(): string
    {
        return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') . '">';
    }
}

if (!function_exists('csrf_validate_post_token')) {
    function csrf_validate_post_token(): bool
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            return true;
        }

        $submittedToken = $_POST['csrf_token'] ?? '';
        $sessionToken = $_SESSION['_csrf_token'] ?? '';

        return is_string($submittedToken)
            && is_string($sessionToken)
            && $submittedToken !== ''
            && $sessionToken !== ''
            && hash_equals($sessionToken, $submittedToken);
    }
}

if (!function_exists('csrf_require_valid_post_token')) {
    function csrf_require_valid_post_token(): void
    {
        if (!csrf_validate_post_token()) {
            http_response_code(403);
            exit('Invalid CSRF token. Please refresh and try again.');
        }
    }
}
