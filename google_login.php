<?php
require_once 'google_auth.php';

// Redirect to Google
header('Location: ' . get_google_login_url());
exit;
?>