<?php
session_start();

// --- 處理背景腳本執行的請求，並輸出為帶有樣式的 HTML ---
if (isset($_GET['action']) && $_GET['action'] === 'run_update') {
    // 1. 修改 HTTP 標頭為 text/html，告訴瀏覽器這是一個 HTML 頁面
    header('Content-Type: text/html; charset=utf-8');
    
    // 關閉輸出緩衝，確保 echo 的內容能即時送到瀏覽器
    @ob_end_flush();
    @ob_implicit_flush(true);

    // 2. 輸出一小段 HTML 和 CSS，設定頁面樣式
    echo <<<HTML
    <!DOCTYPE html>
    <html lang="zh-Hant">
    <head>
        <meta charset="UTF-8">
        <title>更新進度</title>
        <style>
            body {
                background-color: #1a202c; /* 深灰色背景 */
                color: #e2e8f0;           /* 淺灰色 (白色) 文字 */
                font-family: 'Courier New', Courier, monospace;
                font-size: 14px;
                line-height: 1.5;
                margin: 0;
                padding: 10px;
                white-space: pre-wrap;   /* 保留空白字元並自動換行 */
                word-wrap: break-word;
            }
        </style>
    </head>
    <body>
HTML;

    echo "==== 開始執行原始資料更新程序 ====\n";
    echo "腳本路徑: original_drive_files.php\n";
    // 【*** 錯誤修正 ***】移除了 "D"
    echo "開始時間: " . date('Y-m-d H:i:s') . "\n";
    echo "========================================\n\n";
    
    flush();

    $command = 'php /var/www/google-drive-importer/original_drive_files.php 2>&1';
    passthru($command, $return_code);
    
    echo "\n========================================\n";
    echo "程序執行完畢！\n";
    echo "結束時間: " . date('Y-m-d H:i:s') . "\n";
    echo "返回碼: " . $return_code . "\n";
    echo "==== 您現在可以關閉此視窗 ====\n";
    
    // 3. 輸出 HTML 結尾標籤
    echo '</body></html>';
    
    flush();
    exit();
}


// 引用 Composer 的 autoloader
require 'excel/vendor/autoload.php';
// 引用資料庫設定檔
require_once 'config.php';

// 使用 PhpSpreadsheet 相關類別
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date;

// 檢查使用者是否登入，否則導向到登入頁面
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$message = '';
$error = '';
$warning = '';
$import_errors = [];

// --- 查詢與分頁參數準備 ---
$where_clauses = [];
$params = [];

if (empty($_GET) || (!isset($_GET['search']) && !isset($_GET['page']) && !isset($_GET['download_csv']))) {
    $start_date = date('Y-m-d', strtotime('-2 days'));
    $end_date = date('Y-m-d', strtotime('+1 day'));
} else {
    $start_date = $_GET['start_date'] ?? '';
    $end_date = $_GET['end_date'] ?? '';
}
$keyword = $_GET['keyword'] ?? '';
$advanced_display = isset($_GET['advanced_display']);
$show_unclear_only = isset($_GET['show_unclear_only']);

if (!empty($start_date)) {
    $where_clauses[] = "arrival_date >= ?";
    $params[] = $start_date;
}
if (!empty($end_date)) {
    $where_clauses[] = "arrival_date <= ?";
    $params[] = $end_date;
}
if (!empty($keyword)) {
    $where_clauses[] = "(bl_number LIKE ? OR container_number LIKE ? OR vessel_name LIKE ?)";
    $keyword_param = "%{$keyword}%";
    array_push($params, $keyword_param, $keyword_param, $keyword_param);
}
if ($show_unclear_only) {
    $where_clauses[] = "status = 0";
}
$where_sql = count($where_clauses) > 0 ? 'WHERE ' . implode(' AND ', $where_clauses) : '';


// --- 下載查詢結果 (CSV) ---
if (isset($_GET['download_csv'])) {
    try {
        $data_sql = "SELECT * FROM daily_arrange $where_sql ORDER BY arrival_date ASC, id DESC";
        $data_stmt = $pdo->prepare($data_sql);
        $data_stmt->execute($params);
        
        $filename = "arrange_export_" . date('Y-m-d_H-i-s') . ".csv";
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');

        $output = fopen('php://output', 'w');
        fwrite($output, "\xEF\xBB\xBF");

        $headers = [
            '到港日', '主單號', '櫃號', '船掛', '船名', '總件數', '重量', '客戶配送別', '備註', 
            '領櫃', // 【修改】新增領櫃欄位
            '狀態', '已進已出', '已進未出', '已申報未進倉', '未申報', '銷倉率(%)'
        ];
        fputcsv($output, $headers);

        while ($row = $data_stmt->fetch(PDO::FETCH_ASSOC)) {
            $status_text = $row['status'] ? '已通關' : '未通關';
            $hin_text = $row['hin'] ? 'V' : ''; // 【修改】新增領櫃值
            $csv_row = [
                $row['arrival_date'], $row['bl_number'], $row['container_number'], $row['vessel_code'],
                $row['vessel_name'], $row['quantity'], $row['weight'], $row['warehouse'], $row['remarks'],
                $hin_text, // 【修改】新增領櫃值
                $status_text, $row['inandout'], $row['innoout'], $row['noin'], $row['nodeclare'], $row['scale']
            ];
            fputcsv($output, $csv_row);
        }
        
        fclose($output);
        exit();

    } catch (PDOException $e) {
        $_SESSION['download_error'] = "下載失敗：" . $e->getMessage();
        header("Location: arrange.php");
        exit();
    }
}
if (isset($_SESSION['download_error'])) {
    $error = $_SESSION['download_error'];
    unset($_SESSION['download_error']);
}


// --- 後端邏輯處理 (POST, DELETE) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $action = $_POST['action'] ?? '';
        if ($action === 'import' && isset($_FILES['csv_file']) && $_FILES['csv_file']['error'] == UPLOAD_ERR_OK) {
            $file_tmp_path = $_FILES['csv_file']['tmp_name'];
            $spreadsheet = IOFactory::load($file_tmp_path);
            $sheet = $spreadsheet->getActiveSheet();
            $highestRow = $sheet->getHighestRow();
            $pdo->beginTransaction();
            $check_stmt = $pdo->prepare("SELECT id FROM daily_arrange WHERE bl_number = ? AND container_number = ?");
            // 【修改】新增 hin 欄位
            $insert_stmt = $pdo->prepare("INSERT INTO daily_arrange (arrival_date, bl_number, container_number, vessel_code, vessel_name, quantity, weight, warehouse, remarks, status, hin) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $imported_count = 0; $skipped_count = 0;
            for ($row = 2; $row <= $highestRow; $row++) { 
                $rowData = $sheet->rangeToArray('A' . $row . ':' . $sheet->getHighestColumn() . $row, NULL, TRUE, FALSE)[0];
                if (count(array_filter($rowData)) == 0) continue;
                $arrival_date = !empty($rowData[0]) ? Date::excelToDateTimeObject($rowData[0])->format('Y-m-d') : null;
                $bl_number = $rowData[1] ?? null; $container_number = $rowData[2] ?? null; $quantity = $rowData[7] ?? null; $warehouse = $rowData[9] ?? null;
                $validation_error = '';
                if (empty($arrival_date)) $validation_error = '到港日為空';
                elseif (empty($bl_number)) $validation_error = '主單號(提單)為空';
                elseif (empty($container_number)) $validation_error = '櫃號為空';
                elseif (empty($quantity)) $validation_error = '數量為空';
                elseif (empty($warehouse)) $validation_error = '統倉(客戶配送別)為空';
                if ($validation_error) { $import_errors[] = "第 {$row} 行錯誤: {$validation_error}，該行已略過。"; $skipped_count++; continue; }
                $check_stmt->execute([$bl_number, $container_number]);
                if ($check_stmt->fetch()) { $import_errors[] = "第 {$row} 行錯誤: 主單號與櫃號組合已存在，該行已略過。"; $skipped_count++; continue; }
                $vessel_code = $rowData[4] ?? null; $vessel_name = $rowData[5] ?? null; $weight = $rowData[8] ?? 0; $remarks = $rowData[10] ?? null;
                // 【修改】execute 陣列加入 hin 的預設值 0
                $insert_stmt->execute([$arrival_date, $bl_number, $container_number, $vessel_code, $vessel_name, $quantity, $weight, $warehouse, $remarks, 0, 0]);
                $imported_count++;
            }
            $pdo->commit();
            $message = "成功匯入 {$imported_count} 筆資料。";
            if ($skipped_count > 0) { $error = "匯入過程中略過了 {$skipped_count} 筆資料... (詳情請見LOG)"; }
        } elseif ($action === 'add') {
            if (empty($_POST['arrival_date']) || empty($_POST['bl_number']) || empty($_POST['container_number']) || empty($_POST['quantity']) || empty($_POST['warehouse'])) { $error = '新增失敗：必填欄位未填寫！'; } 
            else {
                // 【修改】接收 hin 值
                $hin_value = isset($_POST['hin']) ? 1 : 0;
                // 【修改】INSERT 語句加入 hin
                $stmt = $pdo->prepare("INSERT INTO daily_arrange (arrival_date, bl_number, container_number, vessel_code, vessel_name, quantity, weight, warehouse, remarks, status, hin) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?)");
                // 【修改】execute 陣列加入 $hin_value
                $stmt->execute([$_POST['arrival_date'], $_POST['bl_number'], $_POST['container_number'], $_POST['vessel_code'], $_POST['vessel_name'], $_POST['quantity'], $_POST['weight'], $_POST['warehouse'], $_POST['remarks'], $hin_value]);
                $message = '排櫃資料新增成功！';
            }
        } elseif ($action === 'edit') {
            // 【修改】接收 hin 值
            $hin_value = isset($_POST['hin']) ? 1 : 0;
            // 【修改】UPDATE 語句加入 hin = ?
            $stmt = $pdo->prepare("UPDATE daily_arrange SET arrival_date = ?, bl_number = ?, container_number = ?, vessel_code = ?, vessel_name = ?, quantity = ?, weight = ?, warehouse = ?, remarks = ?, status = ?, hin = ? WHERE id = ?");
            // 【修改】execute 陣列加入 $hin_value
            $stmt->execute([$_POST['arrival_date'], $_POST['bl_number'], $_POST['container_number'], $_POST['vessel_code'], $_POST['vessel_name'], $_POST['quantity'], $_POST['weight'], $_POST['warehouse'], $_POST['remarks'], $_POST['status'], $hin_value, $_POST['id']]);
            $message = '排櫃資料修改成功！';
        }
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        $error = "操作失敗：" . $e->getMessage();
    }
}
if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['id'])) {
    try {
        $stmt = $pdo->prepare("DELETE FROM daily_arrange WHERE id = ?");
        $stmt->execute([$_GET['id']]);
        $message = '資料刪除成功！';
    } catch (PDOException $e) { $error = "刪除失敗：" . $e->getMessage(); }
}


// --- 查詢與分頁 ---
$records_per_page = 20;
$current_page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($current_page - 1) * $records_per_page;

$total_stmt = $pdo->prepare("SELECT COUNT(*) FROM daily_arrange $where_sql");
$total_stmt->execute($params);
$total_records = $total_stmt->fetchColumn();
$total_pages = ceil($total_records / $records_per_page);

$data_sql = "SELECT * FROM daily_arrange $where_sql ORDER BY arrival_date ASC, id DESC LIMIT $records_per_page OFFSET $offset";
$data_stmt = $pdo->prepare($data_sql);
$data_stmt->execute($params);
$results = $data_stmt->fetchAll(PDO::FETCH_ASSOC);

?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>排櫃總表操作</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        body { font-family: 'Noto Sans TC', sans-serif; }
        .modal { display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; overflow: auto; background-color: rgba(0,0,0,0.5); align-items: center; justify-content: center; }
        .modal.active { display: flex; }
        .modal-content { background-color: #fefefe; margin: auto; padding: 20px; border: 1px solid #888; width: 80%; max-width: 800px; border-radius: 10px; }
        .form-input { @apply mt-1 block w-full px-3 py-2 bg-white border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm; }
        .btn-primary { @apply inline-flex justify-center py-2 px-4 border border-transparent shadow-sm text-sm font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700; }
        .btn-secondary { @apply inline-flex justify-center py-2 px-4 border border-transparent shadow-sm text-sm font-medium rounded-md text-gray-700 bg-gray-200 hover:bg-gray-300; }
        .btn-success { @apply inline-flex justify-center py-2 px-4 border border-transparent shadow-sm text-sm font-medium rounded-md text-white bg-green-600 hover:bg-green-700; }
        .btn-danger { @apply inline-flex justify-center py-2 px-4 border border-transparent shadow-sm text-sm font-medium rounded-md text-white bg-red-600 hover:bg-red-700; }
    </style>
</head>
<body class="bg-gray-100 p-6">
<div class="container mx-auto bg-white p-8 rounded-lg shadow-lg">

    <div class="flex justify-between items-center mb-6">
        <h1 class="text-3xl font-bold text-gray-800">排櫃總表操作</h1>
        <button type="button" id="run-update-btn" onclick="runUpdateScript()" class="btn-primary">更新原始資料</button>
    </div>

    <?php if ($message): ?><div class="mb-4 p-4 bg-green-100 text-green-700 rounded-lg"><?php echo $message; ?></div><?php endif; ?>
    <?php if ($error): ?><div class="mb-4 p-4 bg-red-100 text-red-700 rounded-lg"><?php echo $error; ?></div><?php endif; ?>

    <div class="mb-6 p-4 bg-gray-50 rounded-lg">
        <form action="arrange.php" method="GET" class="grid grid-cols-1 md:grid-cols-4 gap-4 items-end">
            <div class="md:col-span-3 grid grid-cols-1 md:grid-cols-5 gap-4 items-end">
                 <div>
                    <label for="start_date" class="block text-sm font-medium text-gray-700 mb-1">起始日期</label>
                    <input type="date" name="start_date" id="start_date" class="form-input" value="<?php echo htmlspecialchars($start_date); ?>">
                </div>
                <div>
                    <label for="end_date" class="block text-sm font-medium text-gray-700 mb-1">結束日期</label>
                    <input type="date" name="end_date" id="end_date" class="form-input" value="<?php echo htmlspecialchars($end_date); ?>">
                </div>
                <div>
                    <label for="keyword" class="block text-sm font-medium text-gray-700 mb-1">關鍵字查詢</label>
                    <input type="text" name="keyword" id="keyword" class="form-input" placeholder="主單號 / 櫃號 / 船名" value="<?php echo htmlspecialchars($keyword); ?>">
                </div>
                <div class="flex items-center pb-2 self-center mt-4">
                    <input type="checkbox" name="advanced_display" id="advanced_display" value="1" class="h-4 w-4 text-indigo-600 focus:ring-indigo-500 border-gray-300 rounded" <?php if ($advanced_display) echo 'checked'; ?>>
                    <label for="advanced_display" class="ml-2 block text-sm text-gray-900">進階顯示</label>
                </div>
                <div class="flex items-center pb-2 self-center mt-4">
                    <input type="checkbox" name="show_unclear_only" id="show_unclear_only" value="1" class="h-4 w-4 text-indigo-600 focus:ring-indigo-500 border-gray-300 rounded" <?php if ($show_unclear_only) echo 'checked'; ?>>
                    <label for="show_unclear_only" class="ml-2 block text-sm text-gray-900">只顯示未通關</label>
                </div>
            </div>
            <div class="flex flex-col space-y-2 md:flex-row md:space-y-0 md:space-x-2">
                 <button type="submit" name="search" value="1" class="btn-primary w-full">查詢</button>
                 <button type="button" onclick="openModal('addModal')" class="btn-secondary w-full">新增</button>
                 <button type="button" onclick="openModal('importModal')" class="btn-secondary w-full">匯入</button>
                 <a href="?<?php echo http_build_query(array_merge($_GET, ['download_csv' => 1])); ?>" class="btn-success w-full text-center">下載查詢結果</a>
            </div>
        </form>
    </div>

    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th scope="col" class="px-3 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">到港日</th>
                    <th scope="col" class="px-3 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">主單號</th>
                    <th scope="col" class="px-3 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">櫃號</th>
                    <?php if (!$advanced_display): ?>
                    <th scope="col" class="px-3 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">船名</th>
                    <?php endif; ?>
                    <th scope="col" class="px-3 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">總件數</th>
                    <?php if ($advanced_display): ?>
                    <th scope="col" class="px-3 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">已進已出</th>
                    <th scope="col" class="px-3 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">已進未出</th>
                    <th scope="col" class="px-3 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">已申報未進倉</th>
                    <th scope="col" class="px-3 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">未申報</th>
                    <th scope="col" class="px-3 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">銷倉率</th>
                    <?php endif; ?>
                    <th scope="col" class="px-3 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">客戶配送別</th>
                    <?php if (!$advanced_display): ?>
                    <th scope="col" class="px-3 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">備註</th>
                    <?php endif; ?>
                    <th scope="col" class="px-3 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">領櫃</th> <th scope="col" class="px-3 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">狀態</th>
                    <th scope="col" class="px-3 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">操作</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                <?php foreach ($results as $row): ?>
                <tr class="hover:bg-gray-50">
                    <td class="px-3 py-4 whitespace-nowrap text-sm"><?php echo htmlspecialchars($row['arrival_date']); ?></td>
                    <td class="px-3 py-4 whitespace-nowrap text-sm"><?php echo htmlspecialchars($row['bl_number']); ?></td>
                    <td class="px-3 py-4 whitespace-nowrap text-sm"><?php echo htmlspecialchars($row['container_number']); ?></td>
                    <?php if (!$advanced_display): ?>
                    <td class="px-3 py-4 whitespace-nowrap text-sm"><?php echo htmlspecialchars($row['vessel_name']); ?></td>
                    <?php endif; ?>
                    <td class="px-3 py-4 whitespace-nowrap text-sm"><?php echo htmlspecialchars($row['quantity']); ?></td>
                    <?php if ($advanced_display): ?>
                    <td class="px-3 py-4 whitespace-nowrap text-sm text-blue-600 font-semibold"><?php echo htmlspecialchars($row['inandout']); ?></td>
                    <td class="px-3 py-4 whitespace-nowrap text-sm"><a href="innoout_details.php?bl_number=<?php echo urlencode($row['bl_number']); ?>" target="_blank" class="text-yellow-600 hover:text-yellow-800 underline font-bold"><?php echo htmlspecialchars($row['innoout']); ?></a></td>
                    <td class="px-3 py-4 whitespace-nowrap text-sm text-red-600 font-semibold"><?php echo htmlspecialchars($row['noin']); ?></td>
                    <td class="px-3 py-4 whitespace-nowrap text-sm text-gray-500 font-semibold"><?php echo htmlspecialchars($row['nodeclare']); ?></td>
                    <td class="px-3 py-4 whitespace-nowrap text-sm text-purple-600 font-bold"><?php echo htmlspecialchars($row['scale']); ?>%</td>
                    <?php endif; ?>
                    <td class="px-3 py-4 whitespace-nowrap text-sm"><?php echo htmlspecialchars($row['warehouse']); ?></td>
                    <?php if (!$advanced_display): ?>
                    <td class="px-3 py-4 whitespace-nowrap text-sm text-gray-600"><?php echo htmlspecialchars($row['remarks']); ?></td>
                    <?php endif; ?>
                    <td class="px-3 py-4 whitespace-nowrap text-sm text-center font-bold text-green-600"><?php if ($row['hin'] == 1) echo '✅'; ?></td>
                    <td class="px-3 py-4 whitespace-nowrap text-sm">
                        <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full <?php echo $row['status'] ? 'bg-green-100 text-green-800' : 'bg-yellow-100 text-yellow-800'; ?>"><?php echo $row['status'] ? '已通關' : '未通關'; ?></span>
                    </td>
                    <td class="px-3 py-4 whitespace-nowrap text-sm font-medium">
                        <button onclick='openEditModal(<?php echo json_encode($row); ?>)' class="text-indigo-600 hover:text-indigo-900">編輯</button>
                        <a href="?action=delete&id=<?php echo $row['id']; ?>&page=<?php echo $current_page; ?>&start_date=<?php echo $start_date; ?>&end_date=<?php echo $end_date; ?>&keyword=<?php echo $keyword; ?>" onclick="return confirm('確定要刪除這筆資料嗎？')" class="text-red-600 hover:text-red-900 ml-4">刪除</a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="mt-4 flex justify-between items-center">
        <div class="text-sm text-gray-700">共 <?php echo $total_records; ?> 筆資料，目前在第 <?php echo $current_page; ?> / <?php echo $total_pages; ?> 頁</div>
        <div>
            <?php if ($total_pages > 1): ?>
                <?php
                    $base_query_params = ['start_date' => $start_date, 'end_date' => $end_date, 'keyword' => $keyword, 'search' => 1];
                    if ($advanced_display) { $base_query_params['advanced_display'] = 1; }
                    if ($show_unclear_only) { $base_query_params['show_unclear_only'] = 1; }
                ?>
                <a href="?<?php echo http_build_query(array_merge($base_query_params, ['page' => 1])); ?>" class="px-3 py-1 border rounded-md text-sm hover:bg-gray-200">首頁</a>
                <?php if ($current_page > 1): ?><a href="?<?php echo http_build_query(array_merge($base_query_params, ['page' => $current_page - 1])); ?>" class="px-3 py-1 border rounded-md text-sm hover:bg-gray-200">上一頁</a><?php endif; ?>
                <?php if ($current_page < $total_pages): ?><a href="?<?php echo http_build_query(array_merge($base_query_params, ['page' => $current_page + 1])); ?>" class="px-3 py-1 border rounded-md text-sm hover:bg-gray-200">下一頁</a><?php endif; ?>
                <a href="?<?php echo http_build_query(array_merge($base_query_params, ['page' => $total_pages])); ?>" class="px-3 py-1 border rounded-md text-sm hover:bg-gray-200">末頁</a>
            <?php endif; ?>
        </div>
    </div>
</div>

<div id="addModal" class="modal">
    <div class="modal-content">
        <h2 class="text-2xl font-bold mb-4">新增排櫃資料</h2>
        <form action="arrange.php" method="POST">
            <input type="hidden" name="action" value="add">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div><label>到港日期 (*)</label><input type="date" name="arrival_date" class="form-input" required></div>
                <div><label>主單號 (*)</label><input type="text" name="bl_number" class="form-input" required></div>
                <div><label>櫃號 (*)</label><input type="text" name="container_number" class="form-input" required></div>
                <div><label>船掛</label><input type="text" name="vessel_code" class="form-input"></div>
                <div><label>船名</label><input type="text" name="vessel_name" class="form-input"></div>
                <div><label>總件數 (*)</label><input type="number" name="quantity" class="form-input" required></div>
                <div><label>重量</label><input type="text" name="weight" class="form-input"></div>
                <div><label>客戶配送別 (*)</label><input type="text" name="warehouse" class="form-input" required></div>
                <div class="md:col-span-2"><label>備註</label><textarea name="remarks" class="form-input"></textarea></div>
                <div class="md:col-span-2 flex items-center pt-2">
                    <input type="checkbox" name="hin" id="add-hin" value="1" class="h-4 w-4 text-indigo-600 focus:ring-indigo-500 border-gray-300 rounded">
                    <label for="add-hin" class="ml-2 block text-sm font-medium text-gray-900">領櫃</label>
                </div>
            </div>
            <div class="mt-6 flex justify-end space-x-2">
                <button type="button" onclick="closeModal('addModal')" class="btn-secondary">取消</button>
                <button type="submit" class="btn-primary">確定新增</button>
            </div>
        </form>
    </div>
</div>

<div id="importModal" class="modal">
    <div class="modal-content">
        <h2 class="text-2xl font-bold mb-4">從 Excel 匯入</h2>
        <form action="arrange.php" method="POST" enctype="multipart/form-data">
            <input type="hidden" name="action" value="import">
            <div>
                <label for="csv_file" class="block text-sm font-medium text-gray-700">選擇 Excel 檔案 (.xlsx, .xls)</label>
                <input type="file" name="csv_file" id="csv_file" required accept=".xlsx, .xls" class="mt-1 block w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-full file:border-0 file:text-sm file:font-semibold file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100">
                <p class="mt-2 text-xs text-gray-500">必填欄位: 到港日期, 主單號(提單), 櫃號, 數量, 統倉(客戶配送別)。</p>
            </div>
            <div class="mt-6 flex justify-end space-x-2">
                <button type="button" onclick="closeModal('importModal')" class="btn-secondary">取消</button>
                <button type="submit" class="btn-primary">開始匯入</button>
            </div>
        </form>
    </div>
</div>

<div id="editModal" class="modal">
    <div class="modal-content">
        <h2 class="text-2xl font-bold mb-4">修改排櫃資料</h2>
        <form action="arrange.php" method="POST">
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="id" id="edit-id">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div><label>到港日期 (*)</label><input type="date" name="arrival_date" id="edit-arrival_date" class="form-input" required></div>
                <div><label>主單號 (*)</label><input type="text" name="bl_number" id="edit-bl_number" class="form-input" required></div>
                <div><label>櫃號 (*)</label><input type="text" name="container_number" id="edit-container_number" class="form-input" required></div>
                <div><label>船掛</label><input type="text" name="vessel_code" id="edit-vessel_code" class="form-input"></div>
                <div><label>船名</label><input type="text" name="vessel_name" id="edit-vessel_name" class="form-input"></div>
                <div><label>總件數 (*)</label><input type="number" name="quantity" id="edit-quantity" class="form-input" required></div>
                <div><label>重量</label><input type="text" name="weight" id="edit-weight" class="form-input"></div>
                <div><label>客戶配送別 (*)</label><input type="text" name="warehouse" id="edit-warehouse" class="form-input" required></div>
                <div class="md:col-span-2"><label>備註</label><textarea name="remarks" id="edit-remarks" class="form-input"></textarea></div>
                <div class="md:col-span-2 flex items-center pt-2">
                    <input type="checkbox" name="hin" id="edit-hin" value="1" class="h-4 w-4 text-indigo-600 focus:ring-indigo-500 border-gray-300 rounded">
                    <label for="edit-hin" class="ml-2 block text-sm font-medium text-gray-900">領櫃</Vlabel>
                </div>
                <div class="md:col-span-2">
                    <label>狀態</label>
                    <select name="status" id="edit-status" class="form-input">
                        <option value="0">未通關</option>
                        <option value="1">已通關</option>
                    </select>
                </div>
            </div>
            <div class="mt-6 flex justify-end space-x-2">
                <button type="button" onclick="closeModal('editModal')" class="btn-secondary">取消</button>
                <button type="submit" class="btn-primary">確定修改</button>
            </div>
        </form>
    </div>
</div>


<div id="updateProgressModal" class="modal">
    <div class="modal-content">
        <h2 id="update-modal-title" class="text-2xl font-bold mb-4">正在更新原始資料...</h2>
        <p class="text-sm text-gray-600 mb-4">系統正在從 Google Drive 下載檔案並執行資料清理，此過程可能需要數分鐘，請勿關閉此視窗。</p>
        
        <iframe id="log-iframe" class="w-full h-96 border border-gray-300 rounded-md"></iframe>

        <div class="mt-6 flex justify-end">
            <button id="close-update-modal-btn" type="button" onclick="closeUpdateModal()" class="btn-primary" style="display: none;">完成並重整頁面</button>
        </div>
    </div>
</div>


<script>
    function openModal(modalId) { document.getElementById(modalId).classList.add('active'); }
    function closeModal(modalId) { document.getElementById(modalId).classList.remove('active'); }
    function openEditModal(data) {
        document.getElementById('edit-id').value = data.id;
        document.getElementById('edit-arrival_date').value = data.arrival_date;
        document.getElementById('edit-bl_number').value = data.bl_number;
        document.getElementById('edit-container_number').value = data.container_number;
        document.getElementById('edit-vessel_code').value = data.vessel_code;
        document.getElementById('edit-vessel_name').value = data.vessel_name;
        document.getElementById('edit-quantity').value = data.quantity;
        document.getElementById('edit-weight').value = data.weight;
        document.getElementById('edit-warehouse').value = data.warehouse;
        document.getElementById('edit-remarks').value = data.remarks;
        document.getElementById('edit-hin').checked = (data.hin == 1); // 【修改】設定領櫃 checkbox
        document.getElementById('edit-status').value = data.status;
        openModal('editModal');
    }

    const updateModal = document.getElementById('updateProgressModal');
    const updateBtn = document.getElementById('run-update-btn');
    const updateModalTitle = document.getElementById('update-modal-title');
    const closeUpdateBtn = document.getElementById('close-update-modal-btn');
    const logIframe = document.getElementById('log-iframe');

    function runUpdateScript() {
        if (!confirm('確定要執行原始資料更新嗎？\n此過程將會從雲端下載最新資料並覆蓋，可能需要較長時間。')) {
            return;
        }

        updateModal.classList.add('active');
        logIframe.src = 'arrange.php?action=run_update';
        
        updateBtn.disabled = true;
        updateBtn.textContent = '更新中...';
        updateBtn.classList.add('opacity-50', 'cursor-not-allowed');

        logIframe.onload = function() {
            updateModalTitle.textContent = '原始資料更新完成！';
            closeUpdateBtn.style.display = 'inline-flex';
            
            updateBtn.disabled = false;
            updateBtn.textContent = '更新原始資料';
            updateBtn.classList.remove('opacity-50', 'cursor-not-allowed');
        };
    }

    function closeUpdateModal() {
        updateModal.classList.remove('active');
        window.location.href = 'arrange.php';
    }
</script>
</body>
</html>