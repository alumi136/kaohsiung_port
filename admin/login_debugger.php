<?php
// --- 診斷工具設定 (請務必修改成您自己的設定) ---
define('DB_HOST', 'localhost');
define('DB_USER', 'alumi136');           // <-- 請修改
define('DB_PASS', 'Alumi!36');               // <-- 請修改
define('DB_NAME', 'kaohsiung_port_db'); // <-- 請修改

// --- 要測試的帳號和密碼 ---
$usernameToTest = 'alumi136';
$passwordToTest = 'louis136';

// --- 診斷流程開始 ---
header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="UTF-8">
    <title>登入流程診斷工具</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        body { font-family: 'Noto Sans TC', sans-serif; }
        .log-item { padding: 0.75rem; border-bottom: 1px solid #e5e7eb; }
        .log-item:last-child { border-bottom: none; }
        .status-success { color: #16a34a; font-weight: bold; }
        .status-fail { color: #dc2626; font-weight: bold; }
        .code { background-color: #f3f4f6; padding: 2px 6px; border-radius: 4px; font-family: monospace; }
        .hash-code { word-break: break-all; background-color: #1f2937; color: #f3f4f6; padding: 1rem; border-radius: 0.5rem; font-family: monospace; margin-top: 1rem; }
    </style>
</head>
<body class="bg-gray-100 p-8">
    <div class="max-w-3xl mx-auto bg-white rounded-lg shadow-lg">
        <div class="p-6 border-b">
            <h1 class="text-2xl font-bold text-gray-800">登入流程診斷報告</h1>
            <p class="text-gray-600">本工具將逐步驗證登入流程中的每個環節。</p>
        </div>
        <div class="divide-y divide-gray-200">
            <?php
            $pdo = null;
            $error = null;
            $user = null;

            // 步驟 1: 測試資料庫連線
            echo '<div class="log-item"><strong>步驟 1: 測試資料庫連線...</strong>';
            try {
                $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4";
                $pdo = new PDO($dsn, DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
                echo '<span class="status-success ml-2">成功！</span></div>';
            } catch (PDOException $e) {
                $error = $e->getMessage();
                echo '<span class="status-fail ml-2">失敗！</span><p class="text-red-600 mt-2">錯誤訊息: ' . htmlspecialchars($error) . '</p></div>';
            }

            // 步驟 2: 查詢使用者
            if ($pdo) {
                echo '<div class="log-item"><strong>步驟 2: 查詢使用者 <span class="code">' . $usernameToTest . '</span>...</strong>';
                try {
                    $stmt = $pdo->prepare("SELECT * FROM administrators WHERE username = ?");
                    $stmt->execute([$usernameToTest]);
                    $user = $stmt->fetch(PDO::FETCH_ASSOC);
                    if ($user) {
                        echo '<span class="status-success ml-2">成功找到使用者！</span></div>';
                    } else {
                        $error = "在 'administrators' 資料表中找不到使用者 '{$usernameToTest}'。";
                        echo '<span class="status-fail ml-2">找不到使用者！</span></div>';
                    }
                } catch (PDOException $e) {
                    $error = $e->getMessage();
                     echo '<span class="status-fail ml-2">查詢失敗！</span><p class="text-red-600 mt-2">錯誤訊息: ' . htmlspecialchars($error) . '</p></div>';
                }
            }

            // 步驟 3: 驗證密碼
            if ($user) {
                echo '<div class="log-item"><strong>步驟 3: 驗證密碼...</strong>';
                $storedHash = $user['password_hash'];
                echo '<p class="text-sm text-gray-500 mt-1">資料庫中的 Hash: <span class="code">' . htmlspecialchars($storedHash) . '</span></p>';
                echo '<p class="text-sm text-gray-500 mt-1">您輸入的密碼: <span class="code">' . $passwordToTest . '</span></p>';
                
                if (password_verify($passwordToTest, $storedHash)) {
                    echo '<p class="mt-2">驗證結果: <span class="status-success">密碼相符！</span></p></div>';
                } else {
                    $error = "密碼驗證失敗。資料庫中的雜湊值與輸入的密碼不匹配。";
                    echo '<p class="mt-2">驗證結果: <span class="status-fail">密碼不符！</span></p></div>';
                }
            }
            ?>
        </div>
        <div class="p-6 bg-gray-50 rounded-b-lg">
            <h2 class="text-xl font-bold text-gray-800">診斷結論</h2>
            <?php if (!$error && $user): ?>
                <div class="mt-2 text-green-700 bg-green-100 p-4 rounded-md">
                    <p>✅ **系統一切正常！** 您的設定、資料庫連線、使用者資料和密碼都正確無誤。如果您在 `login.php` 仍然遇到問題，請確認 `login.php` 和 `config.php` 的內容與教學完全一致。</p>
                </div>
            <?php else: ?>
                <div class="mt-2 text-red-700 bg-red-100 p-4 rounded-md">
                    <p>❌ **系統發現問題！**</p>
                    <p class="mt-2"><strong>根本原因:</strong> <?php echo htmlspecialchars($error); ?></p>
                    
                    <?php if (strpos($error, 'Access denied') !== false || strpos($error, 'Unknown database') !== false): ?>
                        <p class="mt-2"><strong>解決方案:</strong> 請檢查您在本檔案中設定的 `DB_USER`, `DB_PASS`, `DB_NAME` 是否正確。</p>
                    <?php elseif (strpos($error, '找不到使用者') !== false): ?>
                        <p class="mt-2"><strong>解決方案:</strong> 請確認您的 `administrators` 資料表中確實存在一筆 `username` 為 `louis136` 的資料。</p>
                    <?php elseif (strpos($error, '密碼驗證失敗') !== false): ?>
                        <p class="mt-2"><strong>解決方案:</strong> 這是最常見的問題。請複製以下由您目前 PHP 環境產生的 **全新雜湊值**，並手動更新到資料庫中 `louis136` 那一筆資料的 `password_hash` 欄位，即可解決問題。</p>
                        <div class="hash-code">
                            <?php echo htmlspecialchars(password_hash($passwordToTest, PASSWORD_DEFAULT)); ?>
                        </div>
                    <?php else: ?>
                         <p class="mt-2"><strong>解決方案:</strong> 請根據上方的錯誤訊息進行排查。</p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>

