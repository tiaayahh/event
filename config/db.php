<?php
$host = getenv('DB_HOST') ?: 'localhost';
$port = getenv('DB_PORT') ?: '3306';
$db   = getenv('DB_NAME') ?: 'event_planner_db';
$user = getenv('DB_USER') ?: 'root';
$pass = getenv('DB_PASS') ?: '1234';
$charset = 'utf8mb4';

if ($user === 'root') {
    error_log('Security notice: application is using DB root account. Use a least-privilege DB user in production.');
}

$dsn = "mysql:host=$host;port=$port;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (PDOException $e) {
    error_log('Database connection failed: ' . $e->getMessage());
    http_response_code(500);
    die('A database error occurred. Please try again later.');
}
?>