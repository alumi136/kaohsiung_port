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

// --- 統計資料計算 ---
$innoout_text = '已進未出資訊載入失敗'; // 預設值
$noin_text = 'ND件可進倉資訊載入失敗'; // 預設值
$seized_text = '查扣件數資訊載入失敗'; // 預設值

try {
    // 1. 計算「已進未出」的累計總和 (來自 daily_arrange)
    $stmt_innoout = $pdo->query("
        SELECT
            SUM(CASE WHEN arrival_date BETWEEN (CURDATE() - INTERVAL 4 DAY) AND CURDATE() THEN innoout ELSE 0 END) AS total_5,
            SUM(CASE WHEN arrival_date BETWEEN (CURDATE() - INTERVAL 6 DAY) AND CURDATE() THEN innoout ELSE 0 END) AS total_7,
            SUM(CASE WHEN arrival_date BETWEEN (CURDATE() - INTERVAL 13 DAY) AND CURDATE() THEN innoout ELSE 0 END) AS total_14
        FROM daily_arrange
       
    ");
    $innoout_totals = $stmt_innoout->fetch(PDO::FETCH_ASSOC);
    $innoout_text = sprintf(
        "已進未出：近5日共 %d 件； 近7日共 %d 件； 近14日共 %d 件",
        $innoout_totals['total_5'] ?? 0,
        $innoout_totals['total_7'] ?? 0,
        $innoout_totals['total_14'] ?? 0
    );

    // 2. 計算「ND件可進倉」的累計總和 (來自 daily_arrange, 且 status = 1)
    // 【最新修改】加入 status = 1 的條件
    $stmt_noin = $pdo->query("
        SELECT
            SUM(CASE WHEN arrival_date BETWEEN (CURDATE() - INTERVAL 4 DAY) AND CURDATE() THEN noin ELSE 0 END) AS total_5,
            SUM(CASE WHEN arrival_date BETWEEN (CURDATE() - INTERVAL 6 DAY) AND CURDATE() THEN noin ELSE 0 END) AS total_7,
            SUM(CASE WHEN arrival_date BETWEEN (CURDATE() - INTERVAL 13 DAY) AND CURDATE() THEN noin ELSE 0 END) AS total_14
        FROM daily_arrange
        WHERE status = 1 -- 只計算已通關的資料
         
    ");
    $noin_totals = $stmt_noin->fetch(PDO::FETCH_ASSOC);
    // 【最新修改】更改顯示標籤
    $noin_text = sprintf(
        "[ND件可進倉]：近5日共 %d 件； 近7日共 %d 件； 近14日共 %d 件",
        $noin_totals['total_5'] ?? 0,
        $noin_totals['total_7'] ?? 0,
        $noin_totals['total_14'] ?? 0
    );

    // 3. 計算「查扣」件數 (來自 daily_outbound, status0=5, 且未出倉)
    // 【最新修改】加入 storage_out_datetime IS NULL 的條件
    // 假設 created_at 是 DATETIME 或 TIMESTAMP
    $stmt_seized = $pdo->query("
        SELECT
            SUM(CASE WHEN created_at BETWEEN (CURDATE() - INTERVAL 2 DAY) AND CURDATE() THEN 1 ELSE 0 END) AS total_3,
            SUM(CASE WHEN created_at BETWEEN (CURDATE() - INTERVAL 4 DAY) AND CURDATE() THEN 1 ELSE 0 END) AS total_5,
            SUM(CASE WHEN created_at BETWEEN (CURDATE() - INTERVAL 6 DAY) AND CURDATE() THEN 1 ELSE 0 END) AS total_7
        FROM daily_outbound
        WHERE status0 = 5
          AND storage_out_datetime IS NULL -- 只計算尚未出倉的
          AND created_at IS NOT NULL -- 確保 created_at 有效
    ");
    $seized_totals = $stmt_seized->fetch(PDO::FETCH_ASSOC);
    $seized_text = sprintf(
        "查扣件數(未出倉)：近3日共 %d 件； 近5日共 %d 件； 近7日共 %d 件", // 標題加註(未出倉)更清晰
        $seized_totals['total_3'] ?? 0,
        $seized_totals['total_5'] ?? 0,
        $seized_totals['total_7'] ?? 0
    );

} catch (PDOException $e) {
    // 如果任何查詢失敗，保留預設的錯誤訊息，並可選擇記錄詳細錯誤
     $error_message = "警示資訊載入失敗！錯誤訊息：" . $e->getMessage();
     // 可以將 $error_message 顯示或記錄下來
     error_log("Statistics query failed: " . $e->getMessage());
     // 將所有文字設為錯誤提示
     $innoout_text = $error_message;
     $noin_text = ""; // 清空其他行，避免重複顯示錯誤
     $seized_text = "";
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
        /* 靜態統計文字的樣式 */
        .header-stats {
            font-size: 0.875rem; /* text-sm */
            line-height: 1.25rem;
            color: #4b5563; /* gray-600 */
            font-weight: 500; /* medium */
        }
        /* 查扣增加特殊顏色 */
        .seized-stats {
             color: #dc2626; /* red-600 */
        }
    </style>
</head>
<body class="bg-gray-100">
    <div class="flex h-screen">
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
                <a href="mobile_search.php" class="sidebar-link" onclick="window.open(this.href, 'SearchWindow', 'width=500,height=800,scrollbars=yes,resizable=yes'); return false;">
                    <span class="icon">📱</span> 手機查詢已申未進
                </a>
                <a href="account.php" class="sidebar-link" target="contentFrame">
                    <span class="icon">👤</span> 帳號管理
                </a>
            </nav>
        </aside>

        <div class="flex-1 flex flex-col">
            <header class="h-auto md:h-16 bg-white shadow-md flex flex-col md:flex-row items-center justify-between px-6 py-2 md:py-0">
                <div class="header-stats flex-1 mb-2 md:mb-0 md:mr-4">
                    <p><?php echo htmlspecialchars($innoout_text); ?></p>
                    <p><?php echo htmlspecialchars($noin_text); ?></p>
                    <p class="seized-stats font-bold"><?php echo htmlspecialchars($seized_text); ?></p>
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