<?php
define('DB_HOST', 'localhost');
define('DB_USER', 'alumi136');
define('DB_PASS', 'Alumi!36');
define('DB_NAME', 'kaohsiung_port_db');
define('SITE_URL', 'http://www.galloptek.com');

try {
    $pdo = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4", DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("資料庫連線失敗: " . $e->getMessage());
}

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
