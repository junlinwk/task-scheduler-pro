<?php
require_once __DIR__ . '/vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->safeLoad();

$appTimezone = $_ENV['APP_TIMEZONE'] ?: 'Asia/Taipei';
date_default_timezone_set($appTimezone);

// db connection settings
$DB_HOST = $_ENV['DB_HOST'] ?: 'localhost';
$DB_USER = $_ENV['DB_USER'] ?: 'root';
$DB_PASS = $_ENV['DB_PASS'] ?: '';
$DB_NAME = $_ENV['DB_NAME'] ?: 'Scheduler';

$mysqli = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME);

if ($mysqli->connect_errno) {
    die('Failed to connect to MySQL: (' . $mysqli->connect_errno . ') ' . $mysqli->connect_error);
}

$mysqli->set_charset('utf8mb4');
?>