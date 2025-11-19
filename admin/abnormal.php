<?php
// 啟動 session
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
    $log_file = __DIR__ . '/abnormal_log.log'; // 改為相對路徑
    $timestamp = date('Y-m-d H:i:s');
    $formatted_message = "[{$timestamp}] " . $message . PHP_EOL;
    file_put_contents($log_file, $formatted_message, FILE_APPEND);
}

// 【修改 #2】更新異常件類型列表，加入短卸 (此處無變動)
$status0_types = [
    'ALL' => '所有異常件 (不含漏刷)',
    '1' => '轉正報',
    '2' => '提供訂單截圖',
    '3' => '漏刷',
    '5' => '查扣',
    '6' => '放棄',
    '7' => '其他',
    '8' => '短卸',
    '9' => '待實名', // 【最新新增】待實名選項
    'LEAK_MISMATCH' => '漏放(筆數不符)',
    'LEAK_MISMATCH_NONZERO_OUT' => '漏放(部分出倉)',
    'DECLARED_NOT_IN' => '已申報未進倉',
    'RELEASED_NOT_OUT' => '已放行未出倉'
];

// --- 全域變數初始化 ---
$user_message = '';
$message_type = '';
$report_results = [];
$start_date = $_GET['start_date'] ?? ''; // 保留 GET 值以便表單回填
$end_date = $_GET['end_date'] ?? '';
$master_no_query = trim($_GET['master_no'] ?? ''); // 【修改 #1】新增主號查詢變數
$selected_status0 = $_GET['status0_type'] ?? ''; // 預設不選擇

// 分頁設定
$records_per_page = 30;
$current_page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$offset = ($current_page - 1) * $records_per_page;
$total_records = 0;
$total_pages = 1;

// --- 資料庫連線 (使用 $pdo from config.php) ---
if (!isset($pdo)) {
    die("系統錯誤：無法連線到資料庫。");
}

// --- 處理 CSV 匯出請求 ---
if (isset($_GET['export_csv']) && $_GET['export_csv'] == '1') {
    // 【修改 #1 & #2】獲取查詢參數
    $start_date = $_GET['start_date'] ?? '';
    $end_date = $_GET['end_date'] ?? '';
    $master_no_query = trim($_GET['master_no'] ?? '');
    $selected_status0 = $_GET['status0_type'] ?? '';

    // --- 驗證查詢條件 (與下方 HTML 查詢邏輯一致) ---
    if (empty($selected_status0)) {
        die("無效的匯出請求：請選擇異常件類型。");
    }
    $use_date_range = !empty($start_date) && !empty($end_date);
    $use_master_no = !empty($master_no_query);
    $requires_date_or_master = ($selected_status0 !== '5'); // 查扣類型 ('5') 允許都為空

    if ($requires_date_or_master && !$use_date_range && !$use_master_no) { die("無效的匯出請求：請提供日期區間或主號。"); }
    if ($use_date_range && $use_master_no) { die("無效的匯出請求：日期區間和主號請擇一輸入。"); }
    if ($use_date_range && (!strtotime($start_date) || !strtotime($end_date) || strtotime($start_date) > strtotime($end_date))) { die("無效的匯出請求：日期範圍錯誤。"); }
    // --- 驗證結束 ---

    // 【*** 需求 #1 & #2 修改 (CSV) ***】
    // 定義動態欄位旗標
    $show_created_at = ($selected_status0 === '8' || $selected_status0 === 'DECLARED_NOT_IN');
    $show_release_datetime = ($selected_status0 === 'RELEASED_NOT_OUT');

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="異常件報告_' . date('Ymd_His') . '.csv"');
    header('Pragma: no-cache'); header('Expires: 0');

    $output = fopen('php://output', 'w');
    fputs($output, "\xEF\xBB\xBF"); // UTF-8 BOM

    // 【*** 需求 #1 & #2 修改 (CSV) ***】
    // 動態建立 CSV 標頭
    $headers = [
        '報關單號', '主號', '分號', '公斤', '總件數', '已進倉件數', '已出倉件數',
        '通關方式'
    ];
    if ($show_release_datetime) {
        $headers[] = '放行時間';
    }
    $headers[] = '進倉日期時間';
    $headers[] = '出倉日期時間';
    if ($show_created_at) {
        $headers[] = '建立時間';
    }
    $headers[] = '備註 (remark)';
    $headers[] = '狀態 (status0)';
    fputcsv($output, $headers);


    // --- 構建查詢條件 (與下方 HTML 查詢邏輯一致) ---
    $where_clauses = [];
    $params_for_where = [];

    // 條件 1: 異常件類型 (必選)
    // 【*** 需求 #2 (上次修改) ***】
    if ($selected_status0 === 'ALL') { $where_clauses[] = "remark IS NOT NULL AND remark != '' AND status0 != 3"; }
    elseif ($selected_status0 === 'LEAK_MISMATCH') { $where_clauses[] = "(total_packages != packages_in OR packages_in != packages_out OR total_packages != packages_out)"; }
    elseif ($selected_status0 === 'LEAK_MISMATCH_NONZERO_OUT') { $where_clauses[] = "(total_packages != packages_in OR packages_in != packages_out OR total_packages != packages_out) AND packages_out != 0"; }
    elseif ($selected_status0 === 'DECLARED_NOT_IN') { $where_clauses[] = "master_no IS NOT NULL AND house_no IS NOT NULL AND storage_in_datetime IS NULL"; }
    elseif ($selected_status0 === 'RELEASED_NOT_OUT') { $where_clauses[] = "release_datetime IS NOT NULL AND storage_in_datetime IS NOT NULL AND storage_out_datetime IS NULL"; }
    else { $where_clauses[] = "status0 = ?"; $params_for_where[] = $selected_status0; }

    // 條件 2: 日期區間 或 主號 (擇一，但查扣 '5' 可皆無)
    if ($use_master_no) {
        $where_clauses[] = "master_no = ?";
        $params_for_where[] = $master_no_query;
    } elseif ($use_date_range) {
        $date_column_to_filter = 'storage_in_datetime';
        // 【*** 核心修改 ***】加入 '5' (查扣) 條件
        if ($selected_status0 === 'DECLARED_NOT_IN' || $selected_status0 === '8' || $selected_status0 === '5') { $date_column_to_filter = 'created_at'; }
        $where_clauses[] = "`{$date_column_to_filter}` BETWEEN ? AND ?";
        $params_for_where[] = $start_date . " 00:00:00";
        $params_for_where[] = $end_date . " 23:59:59";
    }

    // --- 執行查詢 ---
    // 【*** 需求 #2 修改 (CSV) ***】
    // 加入 release_datetime 欄位
    $sql = "SELECT
                declaration_no, master_no, house_no, weight, total_packages,
                packages_in, packages_out, clearance_method, 
                release_datetime, -- <== 修正：加入放行時間
                storage_in_datetime,
                storage_out_datetime, created_at, remark, status0
            FROM
                daily_outbound
            WHERE
                " . implode(' AND ', $where_clauses) . "
            ORDER BY
                master_no ASC, house_no ASC";

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params_for_where);
        
        // 【*** 需求 #1 & #2 修改 (CSV) ***】
        // 動態建立 CSV 資料列
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
             $csv_row = [
                $row['declaration_no'], $row['master_no'], $row['house_no'], $row['weight'], $row['total_packages'],
                $row['packages_in'], $row['packages_out'], $row['clearance_method']
             ];
             
             if ($show_release_datetime) {
                $csv_row[] = $row['release_datetime'];
             }
             $csv_row[] = $row['storage_in_datetime'];
             $csv_row[] = $row['storage_out_datetime'];
             
             if ($show_created_at) {
                $csv_row[] = $row['created_at'];
             }
             
             $csv_row[] = $row['remark'];
             $csv_row[] = $row['status0'];
             
             fputcsv($output, $csv_row);
        }
    } catch (PDOException $e) {
        write_log("CSV 匯出查詢失敗: " . $e->getMessage()); fclose($output); die("CSV 匯出失敗，請檢查日誌。");
    }
    fclose($output); exit();
}

// --- 處理查詢請求 (HTML 頁面顯示) ---
if ($_SERVER["REQUEST_METHOD"] == "GET" && isset($_GET['query_report'])) {
    // 【修改 #1 & #2】獲取查詢參數
    $start_date = $_GET['start_date'] ?? '';
    $end_date = $_GET['end_date'] ?? '';
    $master_no_query = trim($_GET['master_no'] ?? '');
    $selected_status0 = $_GET['status0_type'] ?? '';

    // --- 驗證查詢條件 ---
    if (empty($selected_status0)) {
        $user_message = "請選擇異常件類型。"; $message_type = 'warn';
    } else {
        $use_date_range = !empty($start_date) && !empty($end_date);
        $use_master_no = !empty($master_no_query);
        $requires_date_or_master = ($selected_status0 !== '5');

        if ($requires_date_or_master && !$use_date_range && !$use_master_no) { $user_message = "請至少輸入日期區間或主號其中一項。"; $message_type = 'warn'; }
        elseif ($use_date_range && $use_master_no) { $user_message = "日期區間和主號請擇一輸入，不可同時使用。"; $message_type = 'warn'; }
        elseif ($use_date_range && (!strtotime($start_date) || !strtotime($end_date))) { $user_message = "日期格式不正確。"; $message_type = 'warn'; }
        elseif ($use_date_range && (strtotime($start_date) > strtotime($end_date))) { $user_message = "起始日期不能晚於結束日期。"; $message_type = 'warn'; }
        else {
            if ($use_date_range) { // 僅在選擇日期時檢查範圍
                $date_diff = strtotime($end_date) - strtotime($start_date);
                $days_diff = floor($date_diff / (60 * 60 * 24));
                if ($days_diff > 90) { $user_message = "查詢範圍不能超過 90 天。"; $message_type = 'warn'; }
            }
        }

        // 只有在驗證通過時才執行查詢
        if (empty($user_message)) {
            try {
                // --- 構建查詢條件 (與上方 CSV 匯出邏輯一致) ---
                $where_clauses = [];
                $params_for_where = [];

                // 條件 1: 異常件類型
                // 【*** 需求 #2 (上次修改) ***】
                if ($selected_status0 === 'ALL') { $where_clauses[] = "remark IS NOT NULL AND remark != '' AND status0 != 3"; }
                elseif ($selected_status0 === 'LEAK_MISMATCH') { $where_clauses[] = "(total_packages != packages_in OR packages_in != packages_out OR total_packages != packages_out)"; }
                elseif ($selected_status0 === 'LEAK_MISMATCH_NONZERO_OUT') { $where_clauses[] = "(total_packages != packages_in OR packages_in != packages_out OR total_packages != packages_out) AND packages_out != 0"; }
                elseif ($selected_status0 === 'DECLARED_NOT_IN') { $where_clauses[] = "master_no IS NOT NULL AND house_no IS NOT NULL AND storage_in_datetime IS NULL"; }
                elseif ($selected_status0 === 'RELEASED_NOT_OUT') { $where_clauses[] = "release_datetime IS NOT NULL AND storage_in_datetime IS NOT NULL AND storage_out_datetime IS NULL"; }
                else { $where_clauses[] = "status0 = ?"; $params_for_where[] = $selected_status0; }

                // 條件 2: 日期區間 或 主號
                if ($use_master_no) {
                    $where_clauses[] = "master_no = ?";
                    $params_for_where[] = $master_no_query;
                } elseif ($use_date_range) {
                    $date_column_to_filter = 'storage_in_datetime';
                     // 【*** 核心修改 ***】加入 '5' (查扣) 條件
                    if ($selected_status0 === 'DECLARED_NOT_IN' || $selected_status0 === '8' || $selected_status0 === '5') { $date_column_to_filter = 'created_at'; }
                    $where_clauses[] = "`{$date_column_to_filter}` BETWEEN ? AND ?";
                    $params_for_where[] = $start_date . " 00:00:00";
                    $params_for_where[] = $end_date . " 23:59:59";
                }

                // --- 執行 COUNT 查詢 ---
                $count_sql = "SELECT COUNT(*) FROM daily_outbound WHERE " . implode(' AND ', $where_clauses);
                $count_stmt = $pdo->prepare($count_sql);
                $count_stmt->execute($params_for_where);
                $total_records = $count_stmt->fetchColumn();

                // --- 計算分頁 ---
                $total_pages = ceil($total_records / $records_per_page);
                $current_page = min($current_page, $total_pages > 0 ? $total_pages : 1);
                $offset = ($current_page - 1) * $records_per_page; if ($offset < 0) $offset = 0;

                // --- 執行資料查詢 ---
                $sql = "SELECT * FROM daily_outbound WHERE " . implode(' AND ', $where_clauses) . " ORDER BY master_no ASC, house_no ASC LIMIT ? OFFSET ?";
                $stmt = $pdo->prepare($sql);
                $final_query_params = array_merge($params_for_where, [$records_per_page, $offset]);
                $stmt->execute($final_query_params);
                $report_results = $stmt->fetchAll(PDO::FETCH_ASSOC);

                if (count($report_results) > 0) {
                    $user_message = "查詢完成，共找到 " . $total_records . " 筆資料，目前顯示第 {$current_page} 頁。"; $message_type = 'success';
                    write_log("[{$logged_in_username}] 查詢異常件報告成功，條件: " . json_encode($_GET) . "，共 {$total_records} 筆。");
                } else {
                    $user_message = "在選定條件下沒有找到符合條件的異常件資料。"; $message_type = 'info';
                    write_log("[{$logged_in_username}] 查詢異常件報告，條件: " . json_encode($_GET) . "，無資料。");
                }

            } catch (PDOException $e) {
                $user_message = "查詢過程中發生錯誤：" . $e->getMessage(); $message_type = 'error';
                write_log("[{$logged_in_username}] 查詢異常件報告失敗: " . $e->getMessage());
            }
        } // end if validation passed
    } // end else check status0_type selected
} // end if query_report

?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>異常件查詢報告</title>
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
            <h1 class="text-3xl font-bold text-blue-900">異常件查詢報告</h1>
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
                <form action="abnormal.php" method="GET" class="grid grid-cols-1 md:grid-cols-5 gap-4 items-end">
                    <div>
                        <label for="start_date" class="block text-sm font-medium text-gray-700 mb-1">起始日期</label>
                        <input type="date" name="start_date" id="start_date" class="form-input" value="<?php echo htmlspecialchars($start_date); ?>">
                    </div>
                    <div>
                        <label for="end_date" class="block text-sm font-medium text-gray-700 mb-1">結束日期</label>
                        <input type="date" name="end_date" id="end_date" class="form-input" value="<?php echo htmlspecialchars($end_date); ?>">
                    </div>
                     <div>
                        <label for="master_no" class="block text-sm font-medium text-gray-700 mb-1">主號 (擇一)</label>
                        <input type="text" name="master_no" id="master_no" class="form-input" placeholder="輸入完整主號" value="<?php echo htmlspecialchars($master_no_query); ?>">
                    </div>
                    <div>
                        <label for="status0_type" class="block text-sm font-medium text-gray-700 mb-1">異常件類型 (*)</label>
                        <select name="status0_type" id="status0_type" class="form-input" required>
                            <option value="">-- 請選擇類型 --</option>
                            <?php foreach ($status0_types as $value => $label): ?>
                                <option value="<?php echo htmlspecialchars($value); ?>" <?php echo ($selected_status0 == $value) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($label); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="flex flex-col space-y-3">
                        <button type="submit" name="query_report" value="1" class="btn-primary">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" /></svg>
                            查詢異常件
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
                    <span class="font-semibold text-red-600">注意：</span>「異常件類型」為必選。請選擇「日期區間」或「主號」其中一項作為查詢條件。<br>
                    <span class="font-semibold text-gray-800">提示：</span>日期範圍最長為 90 天。「查扣」類型可不選日期或主號以查詢全部。「查扣」、「已申報未進倉」及「短卸」使用資料建立時間篩選，其餘類型使用進倉時間。
                </p>
            </div>
            <?php if (count($report_results) > 0): ?>
                <div class="bg-white rounded-lg shadow-lg p-6">
                    <h2 class="text-2xl font-bold text-gray-800 mb-6">查詢結果 (共 <?php echo $total_records; ?> 筆)</h2>
                    <?php
                        // 【*** 需求 #1 & #3 修改 (HTML) ***】
                        // 根據選擇的類型，決定是否顯示特定欄位
                        $show_created_at = ($selected_status0 === '8' || $selected_status0 === 'DECLARED_NOT_IN');
                        $show_release_datetime = ($selected_status0 === 'RELEASED_NOT_OUT');
                    ?>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 table">
                            <thead>
                                <tr>
                                    <th>報關單號</th>
                                    <th>主號</th>
                                    <th>分號</th>
                                    <th>總件數</th>
                                    <th>已進倉件數</th>
                                    <th>已出倉件數</th>
                                    <th>通關方式</th>
                                    <?php if ($show_release_datetime): // 需求 #3 ?>
                                    <th>放行時間</th>
                                    <?php endif; ?>
                                    <th>進倉日期時間</th>
                                    <th>出倉日期時間</th>
                                    <?php if ($show_created_at): // 需求 #1 ?>
                                    <th>建立時間</th>
                                    <?php endif; ?>
                                    <th>備註 (remark)</th>
                                    <th>狀態 (status0)</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                <?php foreach ($report_results as $row): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($row['declaration_no'] ?? ''); ?></td>
                                    <td><?php echo htmlspecialchars($row['master_no'] ?? ''); ?></td>
                                    <td><?php echo htmlspecialchars($row['house_no'] ?? ''); ?></td>
                                    <td><?php echo htmlspecialchars($row['total_packages'] ?? ''); ?></td>
                                    <td><?php echo htmlspecialchars($row['packages_in'] ?? ''); ?></td>
                                    <td><?php echo htmlspecialchars($row['packages_out'] ?? ''); ?></td>
                                    <td><?php echo htmlspecialchars($row['clearance_method'] ?? ''); ?></td>
                                    <?php if ($show_release_datetime): // 需求 #3 ?>
                                    <td><?php echo htmlspecialchars($row['release_datetime'] ?? ''); ?></td>
                                    <?php endif; ?>
                                    <td><?php echo htmlspecialchars($row['storage_in_datetime'] ?? ''); ?></td>
                                    <td><?php echo htmlspecialchars($row['storage_out_datetime'] ?? ''); ?></td>
                                    <?php if ($show_created_at): // 需求 #1 ?>
                                    <td><?php echo htmlspecialchars($row['created_at'] ?? ''); ?></td>
                                    <?php endif; ?>
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
                                // 重新組合基礎 URL 參數，確保分頁連結正確
                                $base_query_params = ['query_report' => 1];
                                if (!empty($start_date)) $base_query_params['start_date'] = $start_date;
                                if (!empty($end_date)) $base_query_params['end_date'] = $end_date;
                                if (!empty($master_no_query)) $base_query_params['master_no'] = $master_no_query;
                                if (!empty($selected_status0)) $base_query_params['status0_type'] = $selected_status0;
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
            <?php elseif (isset($_GET['query_report']) && empty($user_message)): ?>
                <div class="bg-blue-100 text-blue-800 p-6 rounded-lg text-base">
                    <p>沒有找到符合您查詢條件的異常件資料。</p>
                </div>
            <?php endif; ?>
        </main>
    </div>
</body>
</html>