<?php
// 啟動 session
session_start();

// --- 檢查使用者是否已登入的邏輯 ---
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    // 將使用者導向到登入頁面
    header('Location: login.php');
    exit(); // 確保重定向後停止執行
}

// 獲取登入者帳號，用於日誌記錄和顯示
$logged_in_username = $_SESSION['username'] ?? '未知使用者';

// --- 資料庫連線設定 ---
$servername = "localhost";
$username_db = "alumi136";
$password_db = "Alumi!36";
$dbname = "kaohsiung_port_db";

// --- 輔助函式定義 ---
function write_log($message) {
    $log_file = '/var/www/html/admin/abnormal_log.log'; // 獨立的日誌檔案
    $timestamp = date('Y-m-d H:i:s');
    $formatted_message = "[{$timestamp}] [User: {$GLOBALS['logged_in_username']}] " . $message . PHP_EOL;
    file_put_contents($log_file, $formatted_message, FILE_APPEND);
}

// --- 變數初始化 ---
$results = [];
$user_message = '';
$total_records = 0;
$total_pages = 1;
$current_page = 1;
$records_per_page = 50;
$base_query_params = [];

// 從 GET 參數獲取篩選條件
$status0_filter = $_GET['status0_filter'] ?? '';
$start_date = $_GET['start_date'] ?? '';
$end_date = $_GET['end_date'] ?? '';
$keyword = $_GET['keyword'] ?? '';
$current_page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($current_page - 1) * $records_per_page;

// --- 處理查詢請求 ---
if ($_SERVER["REQUEST_METHOD"] == "GET" && isset($_GET['query_report'])) {
    $base_query_params = $_GET;
    unset($base_query_params['page']);

    $conn = new mysqli($servername, $username_db, $password_db, $dbname);
    if ($conn->connect_error) {
        $user_message = "系統錯誤：無法連線到資料庫。";
    } else {
        $conn->set_charset("utf8mb4");

        $where_clauses = [];
        $params = [];
        $types = '';

        if (!empty($status0_filter)) {
            $where_clauses[] = "status0 = ?";
            $params[] = $status0_filter;
            $types .= 'i';
        }

        // 【*** 核心邏輯修正：當查詢類型為 "查扣" (5) 時，忽略日期範圍 ***】
        if ($status0_filter !== '5') {
            if (!empty($start_date)) {
                $where_clauses[] = "storage_in_datetime >= ?";
                $params[] = $start_date . " 00:00:00";
                $types .= 's';
            }
            if (!empty($end_date)) {
                $where_clauses[] = "storage_in_datetime <= ?";
                $params[] = $end_date . " 23:59:59";
                $types .= 's';
            }
        }
        
        if (!empty($keyword)) {
            $where_clauses[] = "(master_no LIKE ? OR house_no LIKE ? OR declaration_no LIKE ?)";
            $keyword_param = "%{$keyword}%";
            array_push($params, $keyword_param, $keyword_param, $keyword_param);
            $types .= 'sss';
        }

        if (empty($where_clauses)) {
            $user_message = "請至少選擇一個查詢條件。";
        } else {
            $where_sql = "WHERE " . implode(" AND ", $where_clauses);

            // 查詢總筆數
            $count_sql = "SELECT COUNT(*) FROM daily_outbound " . $where_sql;
            $stmt_count = $conn->prepare($count_sql);
            if ($stmt_count) {
                if (!empty($params)) $stmt_count->bind_param($types, ...$params);
                $stmt_count->execute();
                $total_records = $stmt_count->get_result()->fetch_row()[0];
                $total_pages = ceil($total_records / $records_per_page);
                $stmt_count->close();
            }

            // 查詢當頁資料
            $sql = "SELECT * FROM daily_outbound " . $where_sql . " ORDER BY id DESC LIMIT ? OFFSET ?";
            $params_page = $params;
            $params_page[] = $records_per_page;
            $params_page[] = $offset;
            $types_page = $types . 'ii';
            
            $stmt = $conn->prepare($sql);
            if ($stmt) {
                $stmt->bind_param($types_page, ...$params_page);
                $stmt->execute();
                $result = $stmt->get_result();
                while ($row = $result->fetch_assoc()) {
                    $results[] = $row;
                }
                $stmt->close();
                write_log("執行了異常件查詢。條件: " . http_build_query($_GET) . "，結果: {$total_records} 筆。");
            }
        }
        $conn->close();
    }
}

// --- 處理匯出 CSV ---
if (isset($_GET['export_csv'])) {
    $base_query_params = $_GET;
    unset($base_query_params['page'], $base_query_params['export_csv']);

    $conn = new mysqli($servername, $username_db, $password_db, $dbname);
    if (!$conn->connect_error) {
        $conn->set_charset("utf8mb4");
        $where_clauses = []; $params = []; $types = '';
        if (!empty($status0_filter)) { $where_clauses[] = "status0 = ?"; $params[] = $status0_filter; $types .= 'i'; }
        
        // 【*** 同步修改匯出邏輯 ***】
        if ($status0_filter !== '5') {
            if (!empty($start_date)) { $where_clauses[] = "storage_in_datetime >= ?"; $params[] = $start_date . " 00:00:00"; $types .= 's'; }
            if (!empty($end_date)) { $where_clauses[] = "storage_in_datetime <= ?"; $params[] = $end_date . " 23:59:59"; $types .= 's'; }
        }
        
        if (!empty($keyword)) { $where_clauses[] = "(master_no LIKE ? OR house_no LIKE ? OR declaration_no LIKE ?)"; $keyword_param = "%{$keyword}%"; array_push($params, $keyword_param, $keyword_param, $keyword_param); $types .= 'sss'; }
        
        if (!empty($where_clauses)) {
            $where_sql = "WHERE " . implode(" AND ", $where_clauses);
            $sql = "SELECT * FROM daily_outbound " . $where_sql . " ORDER BY id DESC";
            $stmt = $conn->prepare($sql);
            if($stmt) {
                if (!empty($params)) $stmt->bind_param($types, ...$params);
                $stmt->execute();
                $result = $stmt->get_result();

                header('Content-Type: text/csv; charset=utf-8');
                header('Content-Disposition: attachment; filename=abnormal_report_' . date('YmdHis') . '.csv');
                $output = fopen('php://output', 'w');
                fputs($output, $bom =( chr(0xEF) . chr(0xBB) . chr(0xBF) ));
                
                $headers = ['報單號碼', '主號', '分號', '總件數', '進倉件數', '出倉件數', '進倉時間', '出倉時間', '備註'];
                fputcsv($output, $headers);
                
                while ($row = $result->fetch_assoc()) {
                    fputcsv($output, [
                        $row['declaration_no'], $row['master_no'], $row['house_no'],
                        $row['total_packages'], $row['packages_in'], $row['packages_out'],
                        $row['storage_in_datetime'], $row['storage_out_datetime'], $row['remark']
                    ]);
                }
                fclose($output);
                $stmt->close();
            }
        }
        $conn->close();
        exit();
    }
}
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>異常件查詢報告</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        body { font-family: 'Noto Sans TC', sans-serif; }
        .form-input { @apply mt-1 block w-full px-3 py-2 bg-white border border-gray-300 rounded-md shadow-sm; }
        .btn { @apply inline-flex justify-center py-2 px-4 border border-transparent shadow-sm text-sm font-medium rounded-md; }
    </style>
</head>
<body class="bg-gray-100 p-6">
    <div class="container mx-auto bg-white p-8 rounded-lg shadow">
        <h1 class="text-2xl font-bold mb-6">異常件查詢報告</h1>

        <!-- 查詢表單 -->
        <div class="mb-6 p-4 bg-gray-50 rounded">
            <form action="abnormal.php" method="GET" class="space-y-4">
                <div class="flex flex-wrap -mx-2 space-y-4 md:space-y-0">
                    <div class="w-full md:w-1/4 px-2">
                        <label for="status0_filter" class="block text-sm font-medium text-gray-700">異常類型</label>
                        <select name="status0_filter" id="status0_filter" class="form-input">
                            <option value="">所有類型</option>
                            <option value="1" <?php if ($status0_filter === '1') echo 'selected'; ?>>轉正報</option>
                            <option value="2" <?php if ($status0_filter === '2') echo 'selected'; ?>>訂單截圖</option>
                            <option value="3" <?php if ($status0_filter === '3') echo 'selected'; ?>>漏刷</option>
                            <option value="5" <?php if ($status0_filter === '5') echo 'selected'; ?>>查扣</option>
                            <option value="6" <?php if ($status0_filter === '6') echo 'selected'; ?>>放棄</option>
                            <option value="7" <?php if ($status0_filter === '7') echo 'selected'; ?>>其他</option>
                        </select>
                    </div>
                    <div class="w-full md:w-1/4 px-2">
                        <label for="start_date" class="block text-sm font-medium text-gray-700">進倉起始日期</label>
                        <input type="date" name="start_date" id="start_date" value="<?php echo htmlspecialchars($start_date); ?>" class="form-input">
                    </div>
                    <div class="w-full md:w-1/4 px-2">
                        <label for="end_date" class="block text-sm font-medium text-gray-700">進倉結束日期</label>
                        <input type="date" name="end_date" id="end_date" value="<?php echo htmlspecialchars($end_date); ?>" class="form-input">
                    </div>
                    <div class="w-full md:w-1/4 px-2">
                        <label for="keyword" class="block text-sm font-medium text-gray-700">關鍵字</label>
                        <input type="text" name="keyword" id="keyword" value="<?php echo htmlspecialchars($keyword); ?>" placeholder="主號/分號/報單號" class="form-input">
                    </div>
                </div>
                <div class="text-right">
                    <button type="submit" name="query_report" value="1" class="btn text-white bg-blue-600 hover:bg-blue-700">執行查詢</button>
                </div>
            </form>
        </div>
        
        <?php if (!empty($user_message)): ?>
            <div class="mb-4 p-4 rounded-md bg-yellow-100 text-yellow-800">
                <?php echo $user_message; ?>
            </div>
        <?php endif; ?>

        <!-- 查詢結果 -->
        <?php if (!empty($results)): ?>
            <div>
                <div class="flex justify-between items-center mb-4">
                    <h2 class="text-xl font-semibold">查詢結果 (共 <?php echo $total_records; ?> 筆)</h2>
                    <a href="?<?php echo http_build_query(array_merge($base_query_params, ['export_csv' => 1])); ?>" class="btn text-white bg-green-600 hover:bg-green-700">匯出 CSV</a>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">報單號碼</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">主號</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">分號</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">總件數</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">進倉件數</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">出倉件數</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">進倉時間</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">出倉時間</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">備註</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php foreach ($results as $row): ?>
                            <tr>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900"><?php echo htmlspecialchars($row['declaration_no']); ?></td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900"><?php echo htmlspecialchars($row['master_no']); ?></td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900"><?php echo htmlspecialchars($row['house_no']); ?></td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900"><?php echo htmlspecialchars($row['total_packages']); ?></td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900"><?php echo htmlspecialchars($row['packages_in']); ?></td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900"><?php echo htmlspecialchars($row['packages_out']); ?></td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900"><?php echo htmlspecialchars($row['storage_in_datetime']); ?></td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900"><?php echo htmlspecialchars($row['storage_out_datetime']); ?></td>
                                <td class="px-6 py-4 text-sm text-gray-700"><?php echo htmlspecialchars($row['remark']); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <!-- 分頁 -->
                <div class="mt-4 flex justify-between items-center">
                    <div class="text-sm text-gray-600">
                        第 <?php echo $current_page; ?> / <?php echo $total_pages; ?> 頁
                    </div>
                    <div class="space-x-1">
                        <?php if ($current_page > 1): ?>
                            <a href="?<?php echo http_build_query(array_merge($base_query_params, ['page' => $current_page - 1])); ?>" class="btn bg-white border border-gray-300 text-gray-700 hover:bg-gray-50">
                                上一頁
                            </a>
                        <?php endif; ?>
                        <?php if ($current_page < $total_pages): ?>
                            <a href="?<?php echo http_build_query(array_merge($base_query_params, ['page' => $current_page + 1])); ?>" class="btn bg-white border border-gray-300 text-gray-700 hover:bg-gray-50">
                                下一頁
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php elseif ($_SERVER["REQUEST_METHOD"] == "GET" && isset($_GET['query_report']) && empty($user_message)): ?>
            <div class="bg-blue-100 text-blue-800 p-4 rounded text-center">
                <p>沒有找到符合您查詢條件的異常件資料。</p>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>

