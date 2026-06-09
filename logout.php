<?php
require __DIR__ . '/app/bootstrap.php';

if (is_logged_in()) {
    audit_log($pdo, 'logout', 'user', (int)(current_user()['id'] ?? 0));
}

$_SESSION = [];

if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], (bool)$params['secure'], (bool)$params['httponly']);
}

session_destroy();

header('Location: login.php');
exit;
