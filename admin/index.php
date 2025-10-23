<?php
session_start();
// 引用資料庫設定檔以進行查詢
require_once 'config.php';

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

// --- 跑馬燈資料計算 ---
$marquee_text = '';
try {
    // 【最新修正】修改 SQL 邏輯，使用 BETWEEN 查詢特定區間，並排除無效日期
    // 計算「已進未出」的累計總和
    $stmt_innoout = $pdo->query("
        SELECT
            SUM(CASE WHEN arrival_date BETWEEN (CURDATE() - INTERVAL 4 DAY) AND CURDATE() THEN innoout ELSE 0 END) AS total_5,
            SUM(CASE WHEN arrival_date BETWEEN (CURDATE() - INTERVAL 6 DAY) AND CURDATE() THEN innoout ELSE 0 END) AS total_7,
            SUM(CASE WHEN arrival_date BETWEEN (CURDATE() - INTERVAL 13 DAY) AND CURDATE() THEN innoout ELSE 0 END) AS total_14
        FROM daily_arrange
        WHERE arrival_date IS NOT NULL AND arrival_date > '0000-00-00' -- 排除無效日期
    ");
    $innoout_totals = $stmt_innoout->fetch(PDO::FETCH_ASSOC);

    // 【最新修正】修改 SQL 邏輯，使用 BETWEEN 查詢特定區間，並排除無效日期
    // 計算「已申報未進倉」的累計總和
    $stmt_noin = $pdo->query("
        SELECT
            SUM(CASE WHEN arrival_date BETWEEN (CURDATE() - INTERVAL 4 DAY) AND CURDATE() THEN noin ELSE 0 END) AS total_5,
            SUM(CASE WHEN arrival_date BETWEEN (CURDATE() - INTERVAL 6 DAY) AND CURDATE() THEN noin ELSE 0 END) AS total_7,
            SUM(CASE WHEN arrival_date BETWEEN (CURDATE() - INTERVAL 13 DAY) AND CURDATE() THEN noin ELSE 0 END) AS total_14
        FROM daily_arrange
        WHERE arrival_date IS NOT NULL AND arrival_date > '0000-00-00' -- 排除無效日期
    ");
    $noin_totals = $stmt_noin->fetch(PDO::FETCH_ASSOC);

    // 組合跑馬燈文字
    $innoout_text = sprintf(
        "近5日已進未出共 %d 件； 近7日共 %d 件； 近14日共 %d 件", // 調整文字描述更精確
        $innoout_totals['total_5'] ?? 0,
        $innoout_totals['total_7'] ?? 0,
        $innoout_totals['total_14'] ?? 0
    );

    $noin_text = sprintf(
        "近5日已申報未進倉共 %d 件； 近7日共 %d 件； 近14日共 %d 件", // 調整文字描述更精確
        $noin_totals['total_5'] ?? 0,
        $noin_totals['total_7'] ?? 0,
        $noin_totals['total_14'] ?? 0
    );

    $marquee_text = $innoout_text . ' || ' . $noin_text; // 使用 || 分隔，更清晰

} catch (PDOException $e) {
    // 如果資料庫查詢失敗，顯示預設訊息
    $marquee_text = "歡迎使用本系統，警示資訊載入失敗。";
    // 可以選擇性地將錯誤記錄到日誌中
    // error_log("Marquee data query failed: " . $e->getMessage());
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
                <a href="mobilscan.php" class="sidebar-link" onclick="window.open(this.href, 'ScanWindow', 'width=500,height=800,scrollbars=yes,resizable=yes'); return false;">
                    <span class="icon">📱</span> 手機掃碼
                </a>
		<a href="account.php" class="sidebar-link" target="contentFrame">
                    <span class="icon">👤</span> 帳號管理
                </a>
            </nav>
        </aside>

        <!-- Main Content -->
        <div class="flex-1 flex flex-col">
            <header class="h-16 bg-white shadow-md flex items-center justify-between px-6">
                <div class="flex-1 text-red-600 font-semibold overflow-hidden">
                    <marquee behavior="scroll" direction="left" scrollamount="6">
                        <?php echo htmlspecialchars($marquee_text); ?>
                    </marquee>
                </div>
                <div class="flex items-center pl-4">
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
