<?php
session_start();

// 檢查使用者是否登入，否則導向到登入頁面
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

// 登出邏輯
if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: login.php");
    exit();
}
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>高雄海運快遞管理系統 - 後台</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+TC:wght@400;500;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Noto Sans TC', sans-serif; }
        .sidebar-link {
            display: flex;
            align-items: center;
            padding: 0.75rem 1.25rem;
            color: #d1d5db; /* gray-300 */
            border-radius: 0.5rem;
            transition: all 0.2s ease-in-out;
        }
        .sidebar-link:hover {
            background-color: #374151; /* gray-700 */
            color: white;
        }
        .sidebar-link.active {
            background-color: #4f46e5; /* indigo-600 */
            color: white;
            font-weight: 500;
        }
        .sidebar-link .icon {
            margin-right: 0.75rem;
            width: 1.25rem;
            height: 1.25rem;
        }
    </style>
</head>
<body class="bg-gray-100">
    <div class="flex h-screen">
        <!-- Sidebar -->
        <aside class="w-64 bg-gray-800 text-white flex flex-col">
            <div class="h-16 flex items-center justify-center text-xl font-bold border-b border-gray-700">
                高雄海運快遞管理系統
            </div>
            <nav class="flex-1 p-4 space-y-2">
                <a href="dashboard.php" class="sidebar-link active" target="contentFrame">
                    <span class="icon">🏠</span> 儀表板
		</a>
		<a href="arrange.php" class="sidebar-link" target="contentFrame">
                    <span class="icon">📦</span> 排櫃操作
                </a>
                <a href="upload.php" class="sidebar-link" target="contentFrame">
                    <span class="icon">📤</span> 每日通關資料上傳
                </a>
                <a href="search.php" class="sidebar-link" target="contentFrame">
                    <span class="icon">🔍</span> 通關狀態查詢
                </a>
                <a href="update.php" class="sidebar-link" target="contentFrame">
                    <span class="icon">✏️</span> 異常件更新
                </a>
                <a href="abnormal.php" class="sidebar-link" target="contentFrame">
                    <span class="icon">✏️</span> 異常件查詢
                </a>
		<a href="account.php" class="sidebar-link" target="contentFrame">
                    <span class="icon">👤</span> 帳號管理
                </a>
            </nav>
        </aside>

        <!-- Main Content -->
        <div class="flex-1 flex flex-col">
            <header class="h-16 bg-white shadow-md flex items-center justify-between px-6">
                <div class="text-gray-600">歡迎使用本系統</div>
                <div class="flex items-center">
                    <span class="text-gray-700 mr-4">
                        你好, <?php echo htmlspecialchars($_SESSION['user_full_name']); ?>
                    </span>
                    <a href="?logout=1" class="text-sm text-red-500 hover:text-red-700">登出</a>
                </div>
            </header>
            <main class="flex-1 p-6">
                <iframe name="contentFrame" src="dashboard.php" class="w-full h-full border-0 rounded-lg bg-white shadow-inner"></iframe>
            </main>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const links = document.querySelectorAll('.sidebar-link');
            links.forEach(link => {
                link.addEventListener('click', function() {
                    // 移除所有連結的 active class
                    links.forEach(l => l.classList.remove('active'));
                    // 為被點擊的連結加上 active class
                    this.classList.add('active');
                });
            });
        });
    </script>
</body>
</html>

