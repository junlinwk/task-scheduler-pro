<?php
require_once __DIR__ . '/security.php';

// Session & authentication helpers
start_secure_session();

function is_logged_in() {
    return isset($_SESSION['user_id']) && ctype_digit((string)$_SESSION['user_id']);
}

function login_user($userId, $username) {
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int)$userId;
    $_SESSION['username'] = (string)$username;
}

function require_login() {
    if (!is_logged_in()) {
        header('Location: index.php');
        exit;
    }
}

function current_user_id() {
    return isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
}

function current_username() {
    return $_SESSION['username'] ?? null;
}
?>
