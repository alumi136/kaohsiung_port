<?php
// 啟動 session
session_start();

// --- 檢查使用者是否已登入的邏輯 ---
// 如果 Session 中沒有 'loggedin' 變數，或者 'loggedin' 不為 true
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    // 將使用者導向到登入頁面
    header('Location: login.php');
    exit(); // 確保重定向後停止執行
}

// 獲取登入者帳號，用於日誌記錄和顯示
$logged_in_username = $_SESSION['username'] ?? '未知使用者';

// --- 資料庫連線設定 (請根據您的實際情況修改) ---
$servername = "localhost";
$username_db = "alumi136"; // 請替換為您的資料庫使用者名稱
$password_db = "Alumi!36"; // 請替換為您的資料庫密碼
$dbname = "kaohsiung_port_db"; // 請替換為您的資料庫名稱

// --- 輔助函式定義 ---
function write_log($message) {
    $log_file = '/var/www/html/admin/abnormal_log.log'; // 獨立的日誌檔案，用於查詢報告
    $timestamp = date('Y-m-d H:i:s');
    $formatted_message = "[{$timestamp}] " . $message . PHP_EOL;
    file_put_contents($log_file, $formatted_message, FILE_APPEND);
}

// 【*** 新增邏輯：增加新的異常件類型 ***】
$status0_types = [
    'ALL' => '所有異常件 (不含漏刷)', // ALL 不含漏刷
    '1' => '轉正報',
    '2' => '提供訂單截圖',
    '3' => '漏刷', // 漏刷仍可單獨選擇
    '5' => '查扣',
    '6' => '放棄',
    '7' => '其他',
    'LEAK_MISMATCH' => '漏放(筆數不符)',
    'LEAK_MISMATCH_NONZERO_OUT' => '漏放(部分出倉)',
    'DECLARED_NOT_IN' => '已申報未進倉',
    'RELEASED_NOT_OUT' => '已放行未出倉'
];

// --- 全域變數初始化 ---
$user_message = '';
$message_type = '';
$report_results = [];
$start_date = '';
$end_date = '';
$selected_status0 = $_GET['status0_type'] ?? 'ALL'; // 預設選擇所有異常件

// 分頁設定
$records_per_page = 30;
$current_page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$offset = ($current_page - 1) * $records_per_page;
$total_records = 0;
$total_pages = 1;

// --- 資料庫連線 ---
$conn = new mysqli($servername, $username_db, $password_db, $dbname);
if ($conn->connect_error) {
    die("系統錯誤：無法連線到資料庫。" . $conn->connect_error); // 嚴重錯誤，直接終止
}
$conn->set_charset("utf8mb4");

// --- 處理 CSV 匯出請求 ---
if (isset($_GET['export_csv']) && $_GET['export_csv'] == '1') {
    $start_date = $_GET['start_date'] ?? '';
    $end_date = $_GET['end_date'] ?? '';
    $selected_status0 = $_GET['status0_type'] ?? 'ALL';

    // 再次進行日期驗證，防止惡意請求
    if ($selected_status0 !== '5' && (empty($start_date) || empty($end_date) || !strtotime($start_date) || !strtotime($end_date) || strtotime($start_date) > strtotime($end_date) || floor((strtotime($end_date) - strtotime($start_date)) / (60 * 60 * 24)) > 90)) {
        die("無效的匯出請求或日期範圍。");
    }

    header('Content-Type: text/csv; charset=utf-8'); // 指定 UTF-8 編碼
    header('Content-Disposition: attachment; filename="異常件報告_' . date('Ymd_His') . '.csv"');
    header('Pragma: no-cache');
    header('Expires: 0');

    $output = fopen('php://output', 'w'); // 打開輸出流
    
    fputs($output, "\xEF\xBB\xBF");

    fputcsv($output, [
        '報關單號', '主號', '分號', '公斤', '總件數', '已進倉件數', '已出倉件數', 
        '通關方式', '進倉日期時間', '出倉日期時間', '備註 (remark)', '狀態 (status0)'
    ]);

    // 構建查詢條件
    $where_clauses = [];
    $params_for_where = [];
    $types_for_where = "";
    
    // 【*** 核心邏輯修正：智慧型日期欄位選擇 ***】
    $date_column_to_filter = 'storage_in_datetime';
    if ($selected_status0 === 'DECLARED_NOT_IN') {
        $date_column_to_filter = 'created_at';
    }

    if ($selected_status0 !== '5') {
        $where_clauses[] = "`{$date_column_to_filter}` BETWEEN ? AND ?";
        $params_for_where[] = $start_date . " 00:00:00";
        $params_for_where[] = $end_date . " 23:59:59";
        $types_for_where .= "ss";
    }

    if ($selected_status0 === 'ALL') {
        $where_clauses[] = "status0 >= 1 AND status0 != 3";
    } elseif ($selected_status0 === 'LEAK_MISMATCH') {
        $where_clauses[] = "(total_packages != packages_in OR packages_in != packages_out OR total_packages != packages_out)";
    } elseif ($selected_status0 === 'LEAK_MISMATCH_NONZERO_OUT') {
        $where_clauses[] = "(total_packages != packages_in OR packages_in != packages_out OR total_packages != packages_out) AND packages_out != 0";
    } elseif ($selected_status0 === 'DECLARED_NOT_IN') {
        $where_clauses[] = "master_no IS NOT NULL AND house_no IS NOT NULL AND storage_in_datetime IS NULL";
    } elseif ($selected_status0 === 'RELEASED_NOT_OUT') {
        $where_clauses[] = "release_datetime IS NOT NULL AND storage_in_datetime IS NOT NULL AND storage_out_datetime IS NULL";
    } else {
        $where_clauses[] = "status0 = ?";
        $params_for_where[] = intval($selected_status0);
        $types_for_where .= "i";
    }

    $sql = "SELECT 
                declaration_no, master_no, house_no, weight, total_packages, 
                packages_in, packages_out, clearance_method, storage_in_datetime, 
                storage_out_datetime, remark, status0
            FROM 
                daily_outbound
            WHERE 
                " . implode(' AND ', $where_clauses) . "
            ORDER BY 
                declaration_no ASC, master_no ASC, house_no ASC";
    
    $stmt = $conn->prepare($sql);
    if ($stmt === false) {
        die("CSV 匯出失敗。");
    }
    
    if(!empty($params_for_where)){
        $stmt->bind_param($types_for_where, ...$params_for_where);
    }
    
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        fputcsv($output, $row);
    }

    $stmt->close();
    $conn->close();
    fclose($output);
    exit();
}


// --- 處理查詢請求 (HTML 頁面顯示) ---
if ($_SERVER["REQUEST_METHOD"] == "GET" && isset($_GET['query_report'])) {
    $start_date = $_GET['start_date'] ?? '';
    $end_date = $_GET['end_date'] ?? '';
    $selected_status0 = $_GET['status0_type'] ?? 'ALL';

    $ignore_date_validation = ($selected_status0 === '5');

    if (!$ignore_date_validation && (empty($start_date) || empty($end_date))) {
        $user_message = "請輸入查詢的起訖日期。";
        $message_type = 'warn';
    } elseif (!$ignore_date_validation && (!strtotime($start_date) || !strtotime($end_date))) {
        $user_message = "日期格式不正確，請使用 YYYY-MM-DD 格式。";
        $message_type = 'warn';
    } elseif (!$ignore_date_validation && (strtotime($start_date) > strtotime($end_date))) {
        $user_message = "起始日期不能晚於結束日期。";
        $message_type = 'warn';
    } else {
        $date_diff = strtotime($end_date) - strtotime($start_date);
        $days_diff = floor($date_diff / (60 * 60 * 24));

        if (!$ignore_date_validation && $days_diff > 90) {
            $user_message = "查詢範圍不能超過 90 天。";
            $message_type = 'warn';
        } else {
            try {
                $where_clauses = [];
                $params_for_where = [];
                $types_for_where = "";

                // 【*** 核心邏輯修正：智慧型日期欄位選擇 ***】
                $date_column_to_filter = 'storage_in_datetime';
                if ($selected_status0 === 'DECLARED_NOT_IN') {
                    $date_column_to_filter = 'created_at';
                }

                if ($selected_status0 !== '5') {
                    $where_clauses[] = "`{$date_column_to_filter}` BETWEEN ? AND ?";
                    $params_for_where[] = $start_date . " 00:00:00";
                    $params_for_where[] = $end_date . " 23:59:59";
                    $types_for_where .= "ss";
                }

                if ($selected_status0 === 'ALL') {
                    $where_clauses[] = "status0 >= 1 AND status0 != 3";
                } elseif ($selected_status0 === 'LEAK_MISMATCH') {
                    $where_clauses[] = "(total_packages != packages_in OR packages_in != packages_out OR total_packages != packages_out)";
                } elseif ($selected_status0 === 'LEAK_MISMATCH_NONZERO_OUT') {
                    $where_clauses[] = "(total_packages != packages_in OR packages_in != packages_out OR total_packages != packages_out) AND packages_out != 0";
                } elseif ($selected_status0 === 'DECLARED_NOT_IN') {
                    $where_clauses[] = "master_no IS NOT NULL AND house_no IS NOT NULL AND storage_in_datetime IS NULL";
                } elseif ($selected_status0 === 'RELEASED_NOT_OUT') {
                    $where_clauses[] = "release_datetime IS NOT NULL AND storage_in_datetime IS NOT NULL AND storage_out_datetime IS NULL";
                } else {
                    $where_clauses[] = "status0 = ?";
                    $params_for_where[] = intval($selected_status0);
                    $types_for_where .= "i";
                }

                $count_sql = "SELECT COUNT(*) FROM daily_outbound WHERE " . implode(' AND ', $where_clauses);
                $count_stmt = $conn->prepare($count_sql);
                if ($count_stmt === false) throw new Exception("SQL 總數查詢準備失敗: " . $conn->error);
                
                if(!empty($params_for_where)){
                    $count_stmt->bind_param($types_for_where, ...$params_for_where);
                }
                $count_stmt->execute();
                $total_records = $count_stmt->get_result()->fetch_row()[0];
                $count_stmt->close();

                $total_pages = ceil($total_records / $records_per_page);
                $current_page = min($current_page, $total_pages > 0 ? $total_pages : 1);
                $offset = ($current_page - 1) * $records_per_page;
                if ($offset < 0) $offset = 0;

                $sql = "SELECT 
                            master_no, house_no, total_packages, 
                            packages_in, packages_out, clearance_method, storage_in_datetime, 
                            storage_out_datetime, remark, status0
                        FROM 
                            daily_outbound
                        WHERE 
                            " . implode(' AND ', $where_clauses) . "
                        ORDER BY 
                            master_no ASC, house_no ASC
                        LIMIT ? OFFSET ?";
                
                $stmt = $conn->prepare($sql);
                if ($stmt === false) throw new Exception("SQL 查詢準備失敗: " . $conn->error);
                
                $final_query_params = array_merge($params_for_where, [$records_per_page, $offset]);
                $final_query_types = $types_for_where . "ii";
                $stmt->bind_param($final_query_types, ...$final_query_params);
                
                $stmt->execute();
                $result = $stmt->get_result();

                if ($result->num_rows > 0) {
                    while ($row = $result->fetch_assoc()) {
                        $report_results[] = $row;
                    }
                    $user_message = "查詢完成，共找到 " . $total_records . " 筆資料，目前顯示第 {$current_page} 頁。";
                    $message_type = 'success';
                    write_log("[{$logged_in_username}] 查詢異常件報告成功，日期範圍: {$start_date} 至 {$end_date}，狀態: {$selected_status0}，共 {$total_records} 筆。");
                } else {
                    $user_message = "在選定條件下沒有找到符合條件的異常件資料。";
                    $message_type = 'info';
                    write_log("[{$logged_in_username}] 查詢異常件報告，日期範圍: {$start_date} 至 {$end_date}，狀態: {$selected_status0}，無資料。");
                }
                $stmt->close();

            } catch (Exception $e) {
                $user_message = "查詢過程中發生錯誤：" . $e->getMessage();
                $message_type = 'error';
                write_log("[{$logged_in_username}] 查詢異常件報告失敗: " . $e->getMessage());
            }
        }
    }
}

$conn->close();
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
        .table-header, .table-cell { border: 1px solid #e2e8f0; }
        .table-header { @apply px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider; }
        .table-cell { @apply px-6 py-4 whitespace-nowrap text-sm text-gray-900; }
        .pagination-link { @apply px-3 py-1.5 border border-gray-300 rounded-md text-sm text-gray-700 hover:bg-gray-100; }
        .pagination-link.active { @apply bg-indigo-600 text-white hover:bg-indigo-700; }
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
                    <p class="text-base"><?php echo $user_message; ?></p>
                </div>
            <?php endif; ?>
            <div class="mb-8 p-6 bg-gray-50 rounded-lg shadow-xl">
                <h2 class="text-2xl font-bold text-gray-800 mb-6">查詢條件</h2>
                <form action="abnormal.php" method="GET" class="grid grid-cols-1 md:grid-cols-4 gap-4 items-end">
                    <div>
                        <label for="start_date" class="block text-sm font-medium text-gray-700 mb-1">起始日期</label>
                        <input type="date" name="start_date" id="start_date" class="form-input" value="<?php echo htmlspecialchars($start_date); ?>" required>
                    </div>
                    <div>
                        <label for="end_date" class="block text-sm font-medium text-gray-700 mb-1">結束日期</label>
                        <input type="date" name="end_date" id="end_date" class="form-input" value="<?php echo htmlspecialchars($end_date); ?>" required>
                    </div>
                    <div>
                        <label for="status0_type" class="block text-sm font-medium text-gray-700 mb-1">異常件類型</label>
                        <select name="status0_type" id="status0_type" class="form-input">
                            <?php foreach ($status0_types as $value => $label): ?>
                                <option value="<?php echo htmlspecialchars($value); ?>" <?php echo ($selected_status0 == $value) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($label); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="flex flex-col space-y-3">
                        <button type="submit" name="query_report" class="btn-primary">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" /></svg>
                            查詢異常件
                        </button>
                        <?php if (!empty($report_results) || (isset($_GET['query_report']) && $total_records > 0)): ?>
                        <button type="submit" name="export_csv" value="1" class="btn-secondary">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m-3 3l-3-3m0 0V4m0 0h7a2 2 0 012 2v7a2 2 0 01-2 2h-7a2 2 0 01-2-2v-7a2 2 0 012-2z" /></svg>
                            匯出 CSV
                        </button>
                        <?php endif; ?>
                    </div>
                </form>
                <p class="mt-4 text-sm text-gray-600">
                    <span class="font-semibold text-gray-800">提示：</span>查詢範圍最長為 90 天。當選擇「已申報未進倉」時，日期範圍將比對資料建立時間(`created_at`)；其他選項則比對進倉時間(`storage_in_datetime`)。
                </p>
            </div>
            <?php if (!empty($report_results)): ?>
                <div class="bg-white rounded-lg shadow-lg p-6">
                    <h2 class="text-2xl font-bold text-gray-800 mb-6">查詢結果 (共 <?php echo $total_records; ?> 筆)</h2>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th scope="col" class="table-header">主號</th> <th scope="col" class="table-header">分號</th>
                                    <th scope="col" class="table-header">總件數</th> <th scope="col" class="table-header">已進倉件數</th>
                                    <th scope="col" class="table-header">已出倉件數</th> <th scope="col" class="table-header">通關方式</th>
                                    <th scope="col" class="table-header">進倉日期時間</th> <th scope="col" class="table-header">出倉日期時間</th>
                                    <th scope="col" class="table-header">備註 (remark)</th> <th scope="col" class="table-header">狀態 (status0)</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <?php foreach ($report_results as $row): ?>
                                <tr>
                                    <td class="table-cell"><?php echo htmlspecialchars($row['master_no']); ?></td>
                                    <td class="table-cell"><?php echo htmlspecialchars($row['house_no']); ?></td>
                                    <td class="table-cell"><?php echo htmlspecialchars($row['total_packages']); ?></td>
                                    <td class="table-cell"><?php echo htmlspecialchars($row['packages_in']); ?></td>
                                    <td class="table-cell"><?php echo htmlspecialchars($row['packages_out']); ?></td>
                                    <td class="table-cell"><?php echo htmlspecialchars($row['clearance_method']); ?></td>
                                    <td class="table-cell"><?php echo htmlspecialchars($row['storage_in_datetime']); ?></td>
                                    <td class="table-cell"><?php echo htmlspecialchars($row['storage_out_datetime']); ?></td>
                                    <td class="table-cell"><?php echo htmlspecialchars($row['remark']); ?></td>
                                    <td class="table-cell"><?php echo htmlspecialchars($row['status0']); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="mt-8 flex justify-center items-center space-x-3 text-sm">
                        <?php if ($total_pages > 1): ?>
                            <?php $base_query_params = ['query_report' => 1, 'start_date' => $start_date, 'end_date' => $end_date, 'status0_type' => $selected_status0]; ?>
                            <?php if ($current_page > 1): ?>
                                <a href="?<?php echo http_build_query(array_merge($base_query_params, ['page' => $current_page - 1])); ?>" class="pagination-link">上一頁</a>
                            <?php else: ?>
                                <span class="pagination-link opacity-50 cursor-not-allowed">上一頁</span>
                            <?php endif; ?>
                            <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                                <a href="?<?php echo http_build_query(array_merge($base_query_params, ['page' => $i])); ?>" class="pagination-link <?php echo ($i == $current_page) ? 'active' : ''; ?>"><?php echo $i; ?></a>
                            <?php endfor; ?>
                            <?php if ($current_page < $total_pages): ?>
                                <a href="?<?php echo http_build_query(array_merge($base_query_params, ['page' => $current_page + 1])); ?>" class="pagination-link">下一頁</a>
                            <?php else: ?>
                                <span class="pagination-link opacity-50 cursor-not-allowed">下一頁</span>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
            <?php elseif ($_SERVER["REQUEST_METHOD"] == "GET" && isset($_GET['query_report']) && empty($user_message)): ?>
                <div class="bg-blue-100 text-blue-800 p-6 rounded-lg text-base">
                    <p>沒有找到符合您查詢條件的異常件資料。</p>
                </div>
            <?php endif; ?>
        </main>
    </div>
</body>
</html>

