<?php
// ===================================================
// 檔案: config.php
// ===================================================

// --- 資料庫設定 (請修改成您自己的設定) ---
define('DB_HOST', 'localhost');      // 資料庫主機
define('DB_USER', 'alumi136');           // 資料庫使用者
define('DB_PASS', 'Alumi!36');               // 資料庫密碼
define('DB_NAME', 'kaohsiung_port_db'); // 資料庫名稱

// --- 啟用 Session ---
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// --- 建立資料庫連線 (使用 PDO) ---
try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4";
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];
    $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
} catch (PDOException $e) {
    // 在正式環境中，不應顯示詳細錯誤
    die("資料庫連線失敗: " . $e->getMessage());
}
