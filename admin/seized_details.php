<?php
// 檔案：seized_details.php
// 說明：用於顯示來自 index.php 點擊的查扣件明細

session_start();
// 引用資料庫設定檔
require_once 'config.php';

// 安全性檢查：檢查是否登入
if (!isset($_SESSION['user_id'])) {
    die("錯誤：您沒有權限存取此頁面。請先登入系統。");
}

// 安全性檢查：檢查資料庫連線
if (!isset($pdo)) {
    die("系統錯誤：無法連線到資料庫。");
}

// 1. 獲取並驗證傳入的 range 參數
$range_days = $_GET['range'] ?? '0';
$days_interval = 0;
$title = "查扣件明細";

// 根據 range 參數設定 SQL 查詢條件和頁面標題
switch ($range_days) {
    case '2': // 當日查扣 (Today + Yesterday)
        $days_interval = 1; // (CURDATE() - INTERVAL 1 DAY)
        $title = "查扣件明細 (當日 + 前一日)";
        break;
    case '3': // 近3日
        $days_interval = 2; // (CURDATE() - INTERVAL 2 DAY)
        $title = "查扣件明細 (近3日)";
        break;
    case '5': // 近5日
        $days_interval = 4; // (CURDATE() - INTERVAL 4 DAY)
        $title = "查扣件明細 (近5日)";
        break;
    default:
        die("錯誤：無效的查詢範圍。");
}

// 2. 建立並執行 SQL 查詢
$results = [];
try {
    // 查詢邏輯與 index.php 完全一致
    // 條件: status0 = 5, 未出倉, 且在指定日期範圍內
    // 欄位: 依照使用者需求排序
    $sql = "SELECT
                master_no, house_no, total_packages, packages_in, packages_out,
                clearance_method, created_at, status0, remark
            FROM
                daily_outbound
            WHERE
                status0 = 5
                AND storage_out_datetime IS NULL
                AND created_at IS NOT NULL
                AND created_at >= (CURDATE() - INTERVAL :days_interval DAY)
            ORDER BY
                created_at DESC, master_no ASC, house_no ASC";

    $stmt = $pdo->prepare($sql);
    // 綁定參數以防止 SQL 注入
    $stmt->bindParam(':days_interval', $days_interval, PDO::PARAM_INT);
    $stmt->execute();
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    // 顯示資料庫錯誤
    die("查詢資料時發生嚴重錯誤：" . $e->getMessage());
}

// 3. 顯示 HTML 頁面
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($title); ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+TC:wght@400;500;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Noto Sans TC', sans-serif; }
        th { @apply px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider bg-gray-50; }
        td { @apply px-4 py-3 whitespace-nowrap text-sm text-gray-700; }
        .remark-cell { @apply whitespace-normal break-words max-w-xs; }
    </style>
</head>
<body class="bg-gray-100 p-4 md:p-8">
    <div class="container mx-auto max-w-6xl bg-white p-6 rounded-lg shadow-lg">
        
        <div class="flex justify-between items-center mb-4">
            <h1 class="text-2xl font-bold text-blue-900"><?php echo htmlspecialchars($title); ?></h1>
            <button onclick="window.print()" class="py-2 px-4 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-blue-600 hover:bg-blue-700">
                列印
            </button>
        </div>
        
        <p class="mb-4 text-gray-700">共找到 <?php echo count($results); ?> 筆資料。</p>
        
        <div class="overflow-x-auto border rounded-lg">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th>主號</th>
                        <th>分號</th>
                        <th>總件數</th>
                        <th>已進倉</th>
                        <th>已出倉</th>
                        <th>通關方式</th>
                        <th>建立時間</th>
                        <th>異常(status0)</th>
                        <th>備註</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php if (empty($results)): ?>
                        <tr>
                            <td colspan="9" class="text-center text-gray-500 py-6">在指定的時間範圍內，沒有找到符合條件的查扣件資料。</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($results as $row): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($row['master_no'] ?? ''); ?></td>
                                <td><?php echo htmlspecialchars($row['house_no'] ?? ''); ?></td>
                                <td><?php echo htmlspecialchars($row['total_packages'] ?? ''); ?></td>
                                <td><?php echo htmlspecialchars($row['packages_in'] ?? ''); ?></td>
                                <td><?php echo htmlspecialchars($row['packages_out'] ?? ''); ?></td>
                                <td><?php echo htmlspecialchars($row['clearance_method'] ?? ''); ?></td>
                                <td><?php echo htmlspecialchars($row['created_at'] ?? ''); ?></td>
                                <td class="font-medium text-red-600"><?php echo htmlspecialchars($row['status0'] ?? ''); ?></td>
                                <td class="remark-cell"><?php echo nl2br(htmlspecialchars($row['remark'] ?? '')); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</body>
</html>