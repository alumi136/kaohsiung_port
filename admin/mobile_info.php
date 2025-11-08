<?php
// 檔案: mobile_info.php
// 說明: 查詢由 mobilscan.php 或 update.php 執行過的中段操作紀錄 (以 daily_outbound.customer_name 為準)
// v3: 將所有 created_at 相關邏輯，全部改為 mobile_time

session_start();

// --- 檢查使用者是否已登入的邏輯 ---
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header('Location: login.php');
    exit();
}

$logged_in_username = $_SESSION['username'] ?? '未知使用者';

// --- 資料庫連線設定 ---
require_once 'config.php'; // 引用 config.php 以使用 $pdo

// --- 輔助函式定義 ---
function write_log($message) {
    $log_file = __DIR__ . '/mobile_info_log.log'; // 獨立的日誌
    $timestamp = date('Y-m-d H:i:s');
    $formatted_message = "[{$timestamp}] " . $message . PHP_EOL;
    file_put_contents($log_file, $formatted_message, FILE_APPEND);
}

// --- 查詢操作人列表 ---
$operators = [];
try {
    $stmt_op = $pdo->query("SELECT DISTINCT full_name FROM administrators WHERE full_name IS NOT NULL AND full_name != '' ORDER BY full_name");
    $operators = $stmt_op->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    write_log("無法獲取 administrators 列表: " . $e->getMessage());
}

// --- 全域變數初始化 ---
$user_message = '';
$message_type = '';
$report_results = [];

// --- 預設查詢當日 ---
$is_default_load = ($_SERVER["REQUEST_METHOD"] == "GET" && empty($_GET));
$start_date = $_GET['start_date'] ?? '';
$end_date = $_GET['end_date'] ?? '';
$selected_operator = $_GET['operator'] ?? ''; 

if ($is_default_load) {
    $start_date = date('Y-m-d');
    $end_date = date('Y-m-d');
}

// 分頁設定 (同 abnormal.php)
$records_per_page = 30;
$current_page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$offset = ($current_page - 1) * $records_per_page;
$total_records = 0;
$total_pages = 1;

if (!isset($pdo)) {
    die("系統錯誤：無法連線到資料庫。");
}

// --- 處理 CSV 匯出請求 ---
if (isset($_GET['export_csv']) && $_GET['export_csv'] == '1') {
    // 獲取查詢參數
    $start_date = $_GET['start_date'] ?? '';
    $end_date = $_GET['end_date'] ?? '';
    $selected_operator = $_GET['operator'] ?? '';

    // --- 驗證查詢條件 (與下方 HTML 查詢邏輯一致) ---
    if (empty($start_date) || empty($end_date)) {
        die("無效的匯出請求：必須提供起訖日期。");
    }
    $date_diff = strtotime($end_date) - strtotime($start_date);
    $days_diff = floor($date_diff / (60 * 60 * 24));
    if ($days_diff > 60) { die("無效的匯出請求：查詢範圍不能超過 60 天。"); }
    if ($days_diff < 0) { die("無效的匯出請求：起始日期不能晚於結束日期。"); }

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="中段操作查詢報告_' . date('Ymd_His') . '.csv"');
    header('Pragma: no-cache'); header('Expires: 0');

    $output = fopen('php://output', 'w');
    fputs($output, "\xEF\xBB\xBF"); // UTF-8 BOM

    // 【*** 邏輯修改 ***】 標籤 "建立時間" -> "操作時間"
    $headers = [
        '主號', '分號', '總件數', '已進倉件數', '已出倉件數',
        '通關方式', '進倉日期時間', '出倉日期時間', '操作時間',
        '操作人', '備註 (remark)', '狀態 (status0)'
    ];
    fputcsv($output, $headers);


    // --- 構建查詢條件 (與下方 HTML 查詢邏輯一致) ---
    // 【*** 邏輯修改 ***】 created_at -> mobile_time
    $where_clauses = ["customer_name IS NOT NULL AND customer_name != ''", "mobile_time IS NOT NULL"]; // 基本條件
    $params_for_where = [];

    // 條件 1: 日期 (必選)
    // 【*** 邏輯修改 ***】 created_at -> mobile_time
    $where_clauses[] = "mobile_time BETWEEN ? AND ?";
    $params_for_where[] = $start_date . " 00:00:00";
    $params_for_where[] = $end_date . " 23:59:59";

    // 條件 2: 操作人 (選填)
    if (!empty($selected_operator)) {
        $where_clauses[] = "customer_name = ?";
        $params_for_where[] = $selected_operator;
    }

    // --- 執行查詢 ---
    // 【*** 邏輯修改 ***】 created_at -> mobile_time
    $sql = "SELECT
                master_no, house_no, total_packages, packages_in, packages_out,
                clearance_method, storage_in_datetime, storage_out_datetime,
                mobile_time, customer_name, remark, status0
            FROM
                daily_outbound
            WHERE
                " . implode(' AND ', $where_clauses) . "
            ORDER BY
                mobile_time DESC, master_no ASC, house_no ASC";

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params_for_where);
        
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
             $csv_row = [
                $row['master_no'],
                $row['house_no'],
                $row['total_packages'],
                $row['packages_in'],
                $row['packages_out'],
                $row['clearance_method'],
                $row['storage_in_datetime'],
                $row['storage_out_datetime'],
                $row['mobile_time'], // 【*** 邏輯修改 ***】
                $row['customer_name'],
                $row['remark'],
                $row['status0']
             ];
             fputcsv($output, $csv_row);
        }
    } catch (PDOException $e) {
        write_log("CSV 匯出查詢失敗: " . $e->getMessage()); fclose($output); die("CSV 匯出失敗，請檢查日誌。");
    }
    fclose($output); exit();
}

// --- 處理查詢請求 (HTML 頁面顯示) ---
if (($_SERVER["REQUEST_METHOD"] == "GET" && isset($_GET['query_report'])) || $is_default_load) {
    
    // --- 驗證查詢條件 ---
    if (empty($start_date) || empty($end_date)) {
        $user_message = "請務必選擇起始日期和結束日期。"; $message_type = 'warn';
    } else {
        $date_diff = strtotime($end_date) - strtotime($start_date);
        $days_diff = floor($date_diff / (60 * 60 * 24));
        if ($days_diff > 60) {
            $user_message = "查詢範圍不能超過 60 天。"; $message_type = 'warn';
        } elseif ($days_diff < 0) {
            $user_message = "起始日期不能晚於結束日期。"; $message_type = 'warn';
        }
    }

    // 只有在驗證通過時才執行查詢
    if (empty($user_message)) {
        try {
            // --- 構建查詢條件 (與上方 CSV 匯出邏輯一致) ---
            // 【*** 邏輯修改 ***】 created_at -> mobile_time
            $where_clauses = ["customer_name IS NOT NULL AND customer_name != ''", "mobile_time IS NOT NULL"];
            $params_for_where = [];

            // 條件 1: 日期 (必選)
            // 【*** 邏輯修改 ***】 created_at -> mobile_time
            $where_clauses[] = "mobile_time BETWEEN ? AND ?";
            $params_for_where[] = $start_date . " 00:00:00";
            $params_for_where[] = $end_date . " 23:59:59";

            // 條件 2: 操作人 (選填)
            if (!empty($selected_operator)) {
                $where_clauses[] = "customer_name = ?";
                $params_for_where[] = $selected_operator;
            }

            // --- 執行 COUNT 查詢 (同 abnormal.php) ---
            $count_sql = "SELECT COUNT(*) FROM daily_outbound WHERE " . implode(' AND ', $where_clauses);
            $count_stmt = $pdo->prepare($count_sql);
            $count_stmt->execute($params_for_where);
            $total_records = $count_stmt->fetchColumn();

            // --- 計算分頁 ---
            $total_pages = ceil($total_records / $records_per_page);
            $current_page = min($current_page, $total_pages > 0 ? $total_pages : 1);
            $offset = ($current_page - 1) * $records_per_page; if ($offset < 0) $offset = 0;

            // --- 執行資料查詢 (同 abnormal.php) ---
            // 【*** 邏輯修改 ***】 created_at -> mobile_time
            $sql = "SELECT
                        master_no, house_no, total_packages, packages_in, packages_out,
                        clearance_method, storage_in_datetime, storage_out_datetime,
                        mobile_time, customer_name, remark, status0
                    FROM daily_outbound 
                    WHERE " . implode(' AND ', $where_clauses) . " 
                    ORDER BY mobile_time DESC, master_no ASC, house_no ASC 
                    LIMIT ? OFFSET ?";
            $stmt = $pdo->prepare($sql);
            $final_query_params = array_merge($params_for_where, [$records_per_page, $offset]);
            $stmt->execute($final_query_params);
            $report_results = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // 根據是否為預設載入修改提示訊息
            $query_source_log = $is_default_load ? "預設查詢當日" : json_encode($_GET);
            $query_source_msg = $is_default_load ? "預設顯示當日資料。" : "";

            if (count($report_results) > 0) {
                $user_message = "查詢完成，共找到 " . $total_records . " 筆資料，目前顯示第 {$current_page} 頁。{$query_source_msg}"; $message_type = 'success';
                write_log("[{$logged_in_username}] 查詢中段操作成功，條件: {$query_source_log}，共 {$total_records} 筆。");
            } else {
                $user_message = "在選定條件下沒有找到符合條件的操作資料。{$query_source_msg}"; $message_type = 'info';
                write_log("[{$logged_in_username}] 查詢中段操作，條件: {$query_source_log}，無資料。");
            }

        } catch (PDOException $e) {
            $user_message = "查詢過程中發生錯誤：" . $e->getMessage(); $message_type = 'error';
            write_log("[{$logged_in_username}] 查詢中段操作失敗: " . $e->getMessage());
        }
    } // end if validation passed
} // end if query_report or default_load

?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>中段操作查詢</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', 'Noto Sans TC', sans-serif; font-size: 1rem; }
        .text-xs { font-size: 0.75rem; } .text-sm { font-size: 0.875rem; } .text-base { font-size: 1rem; }
        .text-lg { font-size: 1.125rem; } .text-xl { font-size: 1.25rem; } .text-2xl { font-size: 1.5rem; }
        .text-3xl { font-size: 1.875rem; } .text-4xl { font-size: 2.25rem; }
        .form-input { @apply mt-1 block w-full px-3 py-2 bg-white border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent text-base; }
        .btn-primary { @apply inline-flex justify-center items-center py-2.5 px-5 border border-transparent shadow-md text-base font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 transition-all duration-300 transform hover:scale-105; }
        .btn-secondary { @apply inline-flex justify-center items-center py-2.5 px-5 border border-gray-300 shadow-md text-base font-medium rounded-md text-gray-800 bg-white hover:bg-gray-100 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 transition-all duration-300 transform hover:scale-105; }
        .table thead th { @apply px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider bg-gray-50 border-b-2 border-gray-200; }
        .table tbody td { @apply px-4 py-3 whitespace-nowrap text-sm text-gray-700 border-b border-gray-100; }
        .pagination-link { @apply px-3 py-1.5 border border-gray-300 rounded-md text-sm text-gray-700 hover:bg-gray-100; }
        .pagination-link.active { @apply bg-indigo-600 text-white border-indigo-600 hover:bg-indigo-700; }
    </style>
</head>
<body class="bg-gray-100 p-4">
    <div class="container mx-auto p-4 md:p-8 max-w-7xl bg-white rounded-xl shadow-lg">
        <header class="text-center mb-8">
            <h1 class="text-3xl font-bold text-blue-900">中段操作查詢</h1>
            <p class="text-base text-gray-600 mt-2">目前登入者: <span class="font-semibold text-blue-700"><?php echo htmlspecialchars($logged_in_username); ?></span></p>
        </header>
        <main>
            <?php if ($user_message): ?>
                <div class="mb-6 p-4 rounded-lg <?php
                    switch ($message_type) {
                        case 'success': echo 'bg-green-100 text-green-800'; break;
                        case 'error': echo 'bg-red-100 text-red-800'; break;
                        case 'warn': echo 'bg-yellow-100 text-yellow-800'; break;
                        case 'info': echo 'bg-blue-100 text-blue-800'; break;
                    }
                ?>">
                    <p class="text-base"><?php echo htmlspecialchars($user_message); ?></p>
                </div>
            <?php endif; ?>
            
            <div class="mb-8 p-6 bg-gray-50 rounded-lg shadow-xl">
                <h2 class="text-2xl font-bold text-gray-800 mb-6">查詢條件</h2>
                <form action="mobile_info.php" method="GET" class="grid grid-cols-1 md:grid-cols-4 gap-4 items-end">
                    <div>
                        <label for="start_date" class="block text-sm font-medium text-gray-700 mb-1">起始日期 (*)</label>
                        <input type="date" name="start_date" id="start_date" class="form-input" value="<?php echo htmlspecialchars($start_date); ?>" required>
                    </div>
                    <div>
                        <label for="end_date" class="block text-sm font-medium text-gray-700 mb-1">結束日期 (*)</label>
                        <input type="date" name="end_date" id="end_date" class="form-input" value="<?php echo htmlspecialchars($end_date); ?>" required>
                    </div>
                    <div>
                        <label for="operator" class="block text-sm font-medium text-gray-700 mb-1">操作人</label>
                        <select name="operator" id="operator" class="form-input">
                            <option value="">-- 所有操作人 --</option>
                            <?php foreach ($operators as $op): ?>
                                <option value="<?php echo htmlspecialchars($op['full_name']); ?>" <?php echo ($selected_operator == $op['full_name']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($op['full_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="flex flex-col space-y-3">
                        <button type="submit" name="query_report" value="1" class="btn-primary">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" /></svg>
                            查詢
                        </button>
                        <?php if (count($report_results) > 0 || (isset($_GET['query_report']) && $total_records > 0)): ?>
                        <button type="submit" name="export_csv" value="1" class="btn-secondary">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" /></svg>
                            匯出 CSV
                        </button>
                        <?php endif; ?>
                    </div>
                </form>
                 <p class="mt-4 text-sm text-gray-600">
                    <span class="font-semibold text-red-600">注意：</span>「起訖日期」為必選，查詢範圍最長為 60 天。「操作人」為選填。
                 </p>
            </div>

            <?php if (count($report_results) > 0): ?>
                <div class="bg-white rounded-lg shadow-lg p-6">
                    <h2 class="text-2xl font-bold text-gray-800 mb-6">查詢結果 (共 <?php echo $total_records; ?> 筆)</h2>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 table">
                            <thead>
                                <tr>
                                    <th>主號</th>
                                    <th>分號</th>
                                    <th>總件數</th>
                                    <th>已進倉件數</th>
                                    <th>已出倉件數</th>
                                    <th>通關方式</th>
                                    <th>進倉日期時間</th>
                                    <th>出倉日期時間</th>
                                    <th>操作時間</th>
                                    <th>操作人</th>
                                    <th>備註 (remark)</th>
                                    <th>狀態 (status0)</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                <?php foreach ($report_results as $row): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($row['master_no'] ?? ''); ?></td>
                                    <td><?php echo htmlspecialchars($row['house_no'] ?? ''); ?></td>
                                    <td><?php echo htmlspecialchars($row['total_packages'] ?? ''); ?></td>
                                    <td><?php echo htmlspecialchars($row['packages_in'] ?? ''); ?></td>
                                    <td><?php echo htmlspecialchars($row['packages_out'] ?? ''); ?></td>
                                    <td><?php echo htmlspecialchars($row['clearance_method'] ?? ''); ?></td>
                                    <td><?php echo htmlspecialchars($row['storage_in_datetime'] ?? ''); ?></td>
                                    <td><?php echo htmlspecialchars($row['storage_out_datetime'] ?? ''); ?></td>
                                    <td><?php echo htmlspecialchars($row['mobile_time'] ?? ''); ?></td>
                                    <td><?php echo htmlspecialchars($row['customer_name'] ?? ''); ?></td>
                                    <td class="whitespace-normal break-words max-w-xs"><?php echo nl2br(htmlspecialchars($row['remark'] ?? '')); ?></td>
                                    <td><?php echo htmlspecialchars($row['status0'] ?? ''); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="mt-8 flex justify-center items-center space-x-3 text-sm">
                        <?php if ($total_pages > 1): ?>
                            <?php
                                // 重新組合基礎 URL 參數
                                $base_query_params = ['query_report' => 1];
                                if (!empty($start_date)) $base_query_params['start_date'] = $start_date;
                                if (!empty($end_date)) $base_query_params['end_date'] = $end_date;
                                if (!empty($selected_operator)) $base_query_params['operator'] = $selected_operator;
                            ?>
                            <?php if ($current_page > 1): ?>
                                <a href="?<?php echo http_build_query(array_merge($base_query_params, ['page' => $current_page - 1])); ?>" class="pagination-link">上一頁</a>
                            <?php else: ?>
                                <span class="pagination-link opacity-50 cursor-not-allowed">上一頁</span>
                            <?php endif; ?>

                            <?php
                            // 計算分頁數字範圍
                            $start_page = max(1, $current_page - 2);
                            $end_page = min($total_pages, $current_page + 2);
                            if ($current_page <= 3) $end_page = min($total_pages, 5);
                            if ($current_page >= $total_pages - 2) $start_page = max(1, $total_pages - 4);
                            ?>

                            <?php if ($start_page > 1): ?>
                                <a href="?<?php echo http_build_query(array_merge($base_query_params, ['page' => 1])); ?>" class="pagination-link">1</a>
                                <?php if ($start_page > 2): ?> <span class="px-1.5">...</span> <?php endif; ?>
                            <?php endif; ?>

                            <?php for ($i = $start_page; $i <= $end_page; $i++): ?>
                                <a href="?<?php echo http_build_query(array_merge($base_query_params, ['page' => $i])); ?>" class="pagination-link <?php echo ($i == $current_page) ? 'active' : ''; ?>"><?php echo $i; ?></a>
                            <?php endfor; ?>

                             <?php if ($end_page < $total_pages): ?>
                                <?php if ($end_page < $total_pages - 1): ?> <span class="px-1.5">...</span> <?php endif; ?>
                                <a href="?<?php echo http_build_query(array_merge($base_query_params, ['page' => $total_pages])); ?>" class="pagination-link"><?php echo $total_pages; ?></a>
                            <?php endif; ?>


                            <?php if ($current_page < $total_pages): ?>
                                <a href="?<?php echo http_build_query(array_merge($base_query_params, ['page' => $current_page + 1])); ?>" class="pagination-link">下一頁</a>
                            <?php else: ?>
                                <span class="pagination-link opacity-50 cursor-not-allowed">下一頁</span>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
            <?php elseif ((isset($_GET['query_report']) || $is_default_load) && empty($user_message)): // 即使是預設載入，如果沒資料也顯示提示 ?>
                <div class="bg-blue-100 text-blue-800 p-6 rounded-lg text-base">
                    <p>沒有找到符合您查詢條件的操作資料。</p>
                </div>
            <?php endif; ?>
        </main>
    </div>
</body>
</html>