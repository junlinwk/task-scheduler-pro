<?php
require_once 'auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !is_valid_csrf_token($_POST['csrf_token'] ?? '')) {
    http_response_code(400);
    die('Invalid logout request.');
}

$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'] ?? '', (bool)$params['secure'], (bool)$params['httponly']);
}
session_destroy();

header('Location: index.php');
exit;
?>
