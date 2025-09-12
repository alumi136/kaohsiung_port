<?php
// 引用設定檔並啟動 session
require_once 'config.php';

// --- 登入邏輯 ---
$error_message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'];
    $password = $_POST['password'];

    if (empty($username) || empty($password)) {
        $error_message = '帳號和密碼不能為空！';
    } else {
        try {
            // --- 已修正 ---
            // 明確指定從 'administrators' 資料表查詢
            $stmt = $pdo->prepare("SELECT * FROM administrators WHERE username = ?");
            $stmt->execute([$username]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            // 使用 password_verify 驗證密碼
            if ($user && password_verify($password, $user['password_hash'])) {
                // 登入成功，設定 session
		$_SESSION['loggedin'] = true;    
		$_SESSION['user_id'] = $user['id'];
                $_SESSION['user_full_name'] = $user['full_name'];
                $_SESSION['username'] = $user['username']; 
                // 重新生成 session id 增加安全性
                session_regenerate_id(true);

                // 跳轉到後台主頁
                header("Location: index.php");
                exit();
            } else {
                $error_message = '帳號或密碼錯誤！';
            }
        } catch (PDOException $e) {
            // 為了安全，不在正式環境顯示詳細錯誤
            // error_log($e->getMessage()); // 可以將錯誤記錄到日誌
            $error_message = "系統發生錯誤，請稍後再試。";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>高雄港海運快遞後台管理系統 - 登入</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+TC:wght@400;500;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Noto Sans TC', sans-serif; }
    </style>
</head>
<body class="bg-gray-100 flex items-center justify-center min-h-screen">
    <div class="w-full max-w-md bg-white rounded-lg shadow-xl p-8 mx-4">
        <div class="text-center mb-8">
            <img src="https://placehold.co/100x100/003366/FFFFFF?text=KHH" alt="Logo" class="mx-auto mb-4 rounded-full">
            <h1 class="text-2xl font-bold text-gray-800">高雄港海運快遞管理系統</h1>
            <p class="text-gray-500">請登入以繼續</p>
        </div>

        <form action="login.php" method="POST">
            <div class="mb-4">
                <label for="username" class="block text-gray-700 text-sm font-bold mb-2">帳號</label>
                <input type="text" id="username" name="username" required class="shadow-sm appearance-none border rounded-lg w-full py-3 px-4 text-gray-700 leading-tight focus:outline-none focus:ring-2 focus:ring-blue-500" placeholder="請輸入您的帳號">
            </div>
            <div class="mb-6">
                <label for="password" class="block text-gray-700 text-sm font-bold mb-2">密碼</label>
                <input type="password" id="password" name="password" required class="shadow-sm appearance-none border rounded-lg w-full py-3 px-4 text-gray-700 mb-3 leading-tight focus:outline-none focus:ring-2 focus:ring-blue-500" placeholder="請輸入您的密碼">
            </div>
            
            <?php if (!empty($error_message)): ?>
                <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-lg relative mb-4" role="alert">
                    <span class="block sm:inline"><?php echo htmlspecialchars($error_message); ?></span>
                </div>
            <?php endif; ?>

            <div class="flex items-center justify-between mt-6">
                <button type="submit" class="w-full bg-blue-600 hover:bg-blue-700 text-white font-bold py-3 px-4 rounded-lg focus:outline-none focus:shadow-outline transition-colors duration-300">
                    登入
                </button>
            </div>
        </form>

        <div class="text-center mt-8">
            <p class="text-xs text-gray-400">
                &copy; <?php echo date('Y'); ?> 漢騰供應鏈有限公司. All Rights Reserved.
            </p>
        </div>
    </div>
</body>
</html>

