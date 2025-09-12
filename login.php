<?php
// 啟動 session，用於儲存認證碼
session_start();

// --- 認證碼生成邏輯 ---
// 定義認證碼字元集
$captcha_chars = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
$captcha_length = 5; // 認證碼長度
$captcha_code = '';
$chars_length = strlen($captcha_chars) - 1;
for ($i = 0; $i < $captcha_length; $i++) {
    $captcha_code .= $captcha_chars[rand(0, $chars_length)];
}

// 將生成的認證碼儲存在 session 中，以便後續驗證
$_SESSION['captcha_code'] = strtolower($captcha_code);

// --- 登入邏輯 (處理表單提交) ---
// 這部分邏輯通常會放在檔案頂部，或另一個獨立的檔案中
// 這裡為了教學目的，將其與 HTML 放在一起
$error_message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 這裡應加入連接資料庫並驗證使用者帳號、密碼和認證碼的完整邏輯
    // 範例：
     $username = $_POST['alumi136'];
     $password = $_POST['Alumi!36'];
     $captcha_input = strtolower($_POST['captcha']);
     if ($captcha_input !== $_SESSION['captcha_code']) {
         $error_message = '認證碼錯誤！';
     } else {
         // ... 連接資料庫查詢使用者 ...
         // ... 使用 password_verify() 驗證密碼 ...
         $error_message = '帳號或密碼錯誤！(此為範例提示)';
     }
}
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>高雄港海快後台管理系統 - 登入</title>
    <!-- 引入 Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    <!-- 引入 Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+TC:wght@400;500;700&display=swap" rel="stylesheet">
    <style>
        /* 使用 Noto Sans TC 作為預設字體 */
        body {
            font-family: 'Noto Sans TC', sans-serif;
        }
        /* 認證碼樣式 */
        .captcha-code {
            font-family: 'Courier New', Courier, monospace;
            letter-spacing: 5px;
            text-decoration: line-through;
            font-weight: bold;
            font-size: 1.5rem;
            color: #4A5568; /* gray-700 */
            background-color: #E2E8F0; /* gray-200 */
            border: 1px solid #CBD5E0; /* gray-400 */
            border-radius: 0.375rem; /* rounded-md */
            padding: 0.5rem 1rem;
            user-select: none; /* 防止使用者選取文字 */
            cursor: pointer; /* 提示可以點擊 */
        }
    </style>
</head>
<body class="bg-gray-100 flex items-center justify-center min-h-screen">

    <div class="w-full max-w-md bg-white rounded-lg shadow-xl p-8 mx-4">
        
        <!-- Header -->
        <div class="text-center mb-8">
            <img src="https://placehold.co/100x100/003366/FFFFFF?text=KHH" alt="Logo" class="mx-auto mb-4 rounded-full">
            <h1 class="text-2xl font-bold text-gray-800">高雄港海外管理系統</h1>
            <p class="text-gray-500">請登入以繼續</p>
        </div>

        <!-- 登入表單 -->
        <form action="login.php" method="POST">
            <!-- 帳號輸入框 -->
            <div class="mb-4">
                <label for="username" class="block text-gray-700 text-sm font-bold mb-2">帳號</label>
                <input type="text" id="username" name="username" required
                       class="shadow-sm appearance-none border rounded-lg w-full py-3 px-4 text-gray-700 leading-tight focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                       placeholder="請輸入您的帳號">
            </div>

            <!-- 密碼輸入框 -->
            <div class="mb-6">
                <label for="password" class="block text-gray-700 text-sm font-bold mb-2">密碼</label>
                <input type="password" id="password" name="password" required
                       class="shadow-sm appearance-none border rounded-lg w-full py-3 px-4 text-gray-700 mb-3 leading-tight focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                       placeholder="請輸入您的密碼">
            </div>

            <!-- 認證碼 -->
            <div class="mb-6">
                 <label for="captcha" class="block text-gray-700 text-sm font-bold mb-2">認證碼</label>
                <div class="flex items-center space-x-4">
                    <input type="text" id="captcha" name="captcha" required maxlength="5"
                           class="shadow-sm appearance-none border rounded-lg w-full py-3 px-4 text-gray-700 leading-tight focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                           placeholder="請輸入右方認證碼">
                    <div class="captcha-code" title="點擊刷新認證碼" onclick="location.reload()">
                        <?php echo htmlspecialchars($captcha_code); ?>
                    </div>
                </div>
            </div>

            <!-- 錯誤訊息顯示區 -->
            <?php if (!empty($error_message)): ?>
                <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-lg relative mb-4" role="alert">
                    <span class="block sm:inline"><?php echo htmlspecialchars($error_message); ?></span>
                </div>
            <?php endif; ?>

            <!-- 登入按鈕 -->
            <div class="flex items-center justify-between">
                <button type="submit"
                        class="w-full bg-blue-600 hover:bg-blue-700 text-white font-bold py-3 px-4 rounded-lg focus:outline-none focus:shadow-outline transition-colors duration-300">
                    登入
                </button>
            </div>
        </form>

        <!-- Footer -->
        <div class="text-center mt-8">
            <p class="text-xs text-gray-400">
                &copy; <?php echo date('Y'); ?> 漢騰供應鏈有限公司. All Rights Reserved.
            </p>
        </div>
    </div>

</body>
</html>

