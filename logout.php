<?php
require_once 'auth.php';
session_unset();
session_destroy();
header('Location: index.php');
exit;
?>
