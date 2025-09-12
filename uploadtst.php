<?php
// --- 資源優化設定 ---
ini_set('memory_limit', '1024M');
set_time_limit(900);

// 引入 Composer 的 autoloader
require 'excel/vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date;

// --- 類別與函式定義 ---
class ChunkReadFilter implements \PhpOffice\PhpSpreadsheet\Reader\IReadFilter {
    private int $startRow = 0;
    private int $endRow = 0;
    public function setRows(int $startRow, int $chunkSize): void {
        $this->startRow = $startRow;
        $this->endRow = $startRow + $chunkSize;
    }
    public function readCell($columnAddress, $row, $worksheetName = ''): bool {
        return ($row >= $this->startRow && $row < $this->endRow);
    }
}

function write_log($message) {
    $log_file = '/var/www/html/daily_outbound.log';
    $timestamp = date('Y-m-d H:i:s');
    $formatted_message = "[{$timestamp}] " . $message . PHP_EOL;
    file_put_contents($log_file, $formatted_message, FILE_APPEND);
}

function parse_date_value($value) {
    if (empty($value)) return null;
    if (is_numeric($value)) return Date::excelToDateTimeObject($value)->format('Y-m-d H:i:s');
    if (is_string($value)) {
        $timestamp = strtotime($value);
        if ($timestamp !== false) return date('Y-m-d H:i:s', $timestamp);
    }
    throw new Exception("無法辨識的日期格式");
}

function batch_insert(mysqli $conn, array $data): int {
    if (empty($data)) return 0;
    $sql = "INSERT INTO daily_outbound (declaration_no, master_no, house_no, weight, total_packages, packages_in, packages_out, clearance_method, declaration_type, carrier_id, route, storage_in_datetime, storage_out_datetime, status, customer_name) VALUES ";
    $placeholders = [];
    $params = [];
    $types = '';
    foreach ($data as $row) {
        $placeholders[] = '(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
        array_push($params, ...array_values($row));
        $types .= 'sssdiisssssssss';
    }
    $sql .= implode(', ', $placeholders);
    $stmt = $conn->prepare($sql);
    if ($stmt === false) throw new Exception("SQL 批次新增預備語句失敗: " . $conn->error);
    $stmt->bind_param($types, ...$params);
    if (!$stmt->execute()) throw new Exception("批次新增執行失敗: " . $stmt->error);
    $affected_rows = $stmt->affected_rows;
    $stmt->close();
    return $affected_rows;
}

// --- 全域變數初始化 ---
$servername = "localhost";
$username = "alumi136";
$password = "Alumi!36";
$dbname = "kaohsiung_port_db";
$user_message = '';
$message_type = '';
$process_results = [];
$active_tab = 'upload'; // 預設分頁

// --- 核心處理邏輯 ---
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $conn = new mysqli($servername, $username, $password, $dbname);
    if ($conn->connect_error) {
        $user_message = "系統錯誤：無法連線到資料庫。";
        $message_type = 'error';
        write_log("資料庫連線失敗: " . $conn->connect_error);
    } else {
        $conn->set_charset("utf8mb4");

        // --- 檔案上傳處理邏輯 ---
        if (isset($_POST['upload_submit'])) {
            $active_tab = 'upload';
            // ... (此處省略與之前版本相同的檔案上傳程式碼，以保持簡潔) ...
        }
        
        // --- 異常件更新處理邏輯 ---
        if (isset($_POST['abnormal_submit'])) {
            $active_tab = 'abnormal'; // 設定當前分頁為異常件更新
            $house_nos_raw = trim($_POST['house_nos_abnormal']);
            $update_type = $_POST['update_type'] ?? '';
            $other_remark = $_POST['other_remark'] ?? ''; // 新增的自訂字元變數
            $house_nos = array_filter(array_map('trim', preg_split('/\\r\\n|\\r|\\n/', $house_nos_raw)));
            
            if (empty($update_type)) {
                $user_message = "請選擇一個異常件更新的類型。";
                $message_type = 'warn';
            } elseif ($update_type === 'other' && empty($other_remark)) { // 新增的檢查
                $user_message = "請輸入自訂的備註字元。";
                $message_type = 'warn';
            } elseif (empty($house_nos)) {
                $user_message = "請輸入至少一個要更新的分號。";
                $message_type = 'warn';
            } elseif (count($house_nos) > 30) {
                $user_message = "一次最多只能處理 30 筆分號。";
                $message_type = 'warn';
            } else {
                $conn->begin_transaction();
                try {
                    $today_str = date('Y-m-d');
                    $updated_count = 0;

                    foreach ($house_nos as $house_no) {
                        // 1. 查詢資料，並加入 clearance_method
                        $stmt_select = $conn->prepare("SELECT id, storage_in_datetime, storage_out_datetime, packages_out, clearance_method FROM daily_outbound WHERE house_no = ? FOR UPDATE");
                        $stmt_select->bind_param("s", $house_no);
                        $stmt_select->execute();
                        $result = $stmt_select->get_result()->fetch_assoc();
                        $stmt_select->close();

                        // 2. 通用邏輯判斷 (對所有選項生效)
                        if (!$result) {
                            $process_results[] = ['house_no' => $house_no, 'status' => '失敗', 'reason' => '系統沒有相同的分提單號碼'];
                            continue;
                        }
                        if (!empty($result['storage_out_datetime'])) {
                            $process_results[] = ['house_no' => $house_no, 'status' => '失敗', 'reason' => '此分提單號已經出庫'];
                            continue;
                        }

                        // 3. 準備更新語句
                        $sql_update = "UPDATE daily_outbound SET created_at = CURRENT_TIMESTAMP, ";
                        $params = [];
                        $types = '';
                        
                        switch ($update_type) {
                            case 'missed_scan': // 漏刷
                                $clearance_method = $result['clearance_method'];
                                $new_out_time = null;

                                if ($clearance_method == 'C1') {
                                    $new_out_time = date('Y-m-d H:i:s', strtotime($result['storage_in_datetime'] . ' +2 minutes 20 seconds'));
                                } elseif ($clearance_method == 'C3') {
                                    $new_out_time = date('Y-m-d H:i:s');
                                } else {
                                    $process_results[] = ['house_no' => $house_no, 'status' => '失敗', 'reason' => '漏刷僅適用於C1/C3通關方式'];
                                    continue 2; // 跳到下一個 house_no
                                }
                                
                                $sql_update .= "storage_out_datetime = ?, packages_out = packages_out + 1, remark = '漏刷', status0 = 3";
                                array_push($params, $new_out_time);
                                $types .= 's';
                                break;
                            case 'screenshot': // 提供訂單截圖
                                $sql_update .= "remark = ?, status0 = 2";
                                array_push($params, "{$today_str} 海關要求提供訂單截圖");
                                $types .= 's';
                                break;
                            case 'formal_declaration': // 轉正報
                                $sql_update .= "remark = ?, status0 = 1";
                                array_push($params, "{$today_str} 轉正報");
                                $types .= 's';
                                break;
                            case 'abandon': // 放棄
                                $sql_update .= "remark = ?, status0 = 6";
                                array_push($params, "{$today_str} 放棄");
                                $types .= 's';
                                break;
                            case 'seized': // 查扣
                                $sql_update .= "remark = ?, status0 = 5";
                                array_push($params, "{$today_str} 查扣");
                                $types .= 's';
                                break;
                            case 'other': // 新增的選項：其他
                                $formatted_remark = "{$today_str} {$other_remark}";
                                $sql_update .= "remark = ?, status0 = 7"; // 假設 status0 = 7 為其他狀態
                                array_push($params, $formatted_remark);
                                $types .= 's';
                                break;
                        }
                        
                        $sql_update .= " WHERE id = ?";
                        array_push($params, $result['id']);
                        $types .= 'i';

                        // 4. 執行更新
                        $stmt_update = $conn->prepare($sql_update);
                        $stmt_update->bind_param($types, ...$params);
                        $stmt_update->execute();
                        
                        if ($stmt_update->affected_rows > 0) {
                            $process_results[] = ['house_no' => $house_no, 'status' => '成功', 'reason' => '更新完成'];
                            $updated_count++;
                        } else {
                            // 即使沒有更新，也記錄一個狀態，讓使用者知道程式有處理
                             $process_results[] = ['house_no' => $house_no, 'status' => '注意', 'reason' => '資料未變更或更新失敗'];
                        }
                        $stmt_update->close();
                    }
                    
                    $conn->commit();
                    $user_message = "異常件處理完成。共成功更新 {$updated_count} 筆資料。";
                    $message_type = 'success';

                } catch (Exception $e) {
                    $conn->rollback();
                    $user_message = "處理過程中發生錯誤，所有操作已還原。";
                    $message_type = 'error';
                    write_log("異常件更新失敗: " . $e->getMessage());
                }
            }
        }
        $conn->close();
    }
}
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>高雄港萬海倉海運快遞管理系統</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', 'Noto Sans TC', sans-serif; }
        .tab-button { transition: all 0.3s; }
        .tab-button.active { border-color: #4f46e5; color: #4f46e5; background-color: #eef2ff; }
    </style>
</head>
<body class="bg-gray-100">
    <div class="container mx-auto p-4 md:p-8 max-w-4xl">
        <header class="text-center mb-10">
            <h1 class="text-3xl md:text-4xl font-bold text-blue-900">高雄港萬海倉海運快遞管理系統</h1>
        </header>

        <div class="mb-8 border-b border-gray-200">
            <nav class="flex space-x-4" aria-label="Tabs">
                <button id="tab-upload" class="tab-button <?php if ($active_tab === 'upload') echo 'active'; ?> text-gray-500 hover:text-gray-700 px-3 py-2 font-medium text-sm rounded-t-md border-b-2 border-transparent">資料上傳</button>
                <button id="tab-abnormal" class="tab-button <?php if ($active_tab === 'abnormal') echo 'active'; ?> text-gray-500 hover:text-gray-700 px-3 py-2 font-medium text-sm rounded-t-md border-b-2 border-transparent">異常件更新</button>
            </nav>
        </div>

        <main>
            <!-- 結果訊息顯示區 -->
            <?php if ($user_message): ?>
                <div class="mb-6 p-4 rounded-lg <?php 
                    switch ($message_type) {
                        case 'success': echo 'bg-green-100 text-green-800'; break;
                        case 'error': echo 'bg-red-100 text-red-800'; break;
                        case 'warn': echo 'bg-yellow-100 text-yellow-800'; break;
                    }
                ?>">
                    <p><?php echo $user_message; ?></p>
                    <?php if (!empty($process_results)): ?>
                    <div class="mt-4 max-h-48 overflow-y-auto">
                        <h4 class="font-bold mb-2">詳細處理狀態：</h4>
                        <ul class="list-disc list-inside text-sm">
                        <?php foreach ($process_results as $res): ?>
                            <li>分號 [<?php echo htmlspecialchars($res['house_no']); ?>]: <?php echo htmlspecialchars($res['status']); ?> - <?php echo htmlspecialchars($res['reason']); ?></li>
                        <?php endforeach; ?>
                        </ul>
                    </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <!-- 資料上傳區塊 -->
            <div id="content-upload" class="<?php if ($active_tab !== 'upload') echo 'hidden'; ?>">
                <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post" enctype="multipart/form-data" class="bg-white rounded-xl shadow-lg p-6 md:p-8">
                    <div class="p-6 border border-gray-200 rounded-lg">
                        <label for="import_export_file" class="block text-lg font-semibold text-gray-700 mb-3">每日進出口資料上傳 (.xlsx, .xls, .csv)</label>
                        <p class="text-sm text-gray-500 mb-4">請選擇檔案，系統會將資料批次匯入資料庫。</p>
                        <input type="file" name="import_export_file" id="import_export_file" accept=".xlsx,.xls,.csv" class="block w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-full file:border-0 file:text-sm file:font-semibold file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100">
                    </div>
                    <div class="mt-6">
                        <button type="submit" name="upload_submit" class="w-full flex justify-center py-3 px-4 border border-transparent rounded-md shadow-sm text-base font-medium text-white bg-blue-600 hover:bg-blue-700">上傳並匯入資料</button>
                    </div>
                </form>
            </div>

            <!-- 異常件更新區塊 -->
            <div id="content-abnormal" class="<?php if ($active_tab !== 'abnormal') echo 'hidden'; ?>">
                 <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post" class="bg-white rounded-xl shadow-lg p-6 md:p-8">
                    <div>
                        <label for="house_nos_abnormal" class="block text-lg font-semibold text-gray-700 mb-3">輸入多筆分號 (每筆一行)</label>
                        <textarea name="house_nos_abnormal" id="house_nos_abnormal" rows="8" class="mt-1 block w-full px-3 py-2 bg-white border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500"></textarea>
                        <p id="line-count" class="mt-2 text-sm text-gray-500">目前行數: 0 (上限 30 筆)</p>
                    </div>
                    <div class="mt-6">
                        <label class="block text-lg font-semibold text-gray-700 mb-3">選擇更新類型</label>
                        <div class="grid grid-cols-2 md:grid-cols-3 gap-4">
                            <?php 
                                $options = [
                                    'missed_scan' => '漏刷',
                                    'screenshot' => '提供訂單截圖',
                                    'formal_declaration' => '轉正報',
                                    'abandon' => '放棄',
                                    'seized' => '查扣',
                                    'other' => '其他' // 新增的選項
                                ];
                                foreach ($options as $value => $label):
                            ?>
                            <label class="flex items-center p-3 border rounded-lg hover:bg-gray-50 cursor-pointer">
                                <input type="radio" name="update_type" value="<?php echo $value; ?>" class="h-4 w-4 text-indigo-600 focus:ring-indigo-500 border-gray-300">
                                <span class="ml-3 text-sm font-medium text-gray-800"><?php echo $label; ?></span>
                            </label>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- 新增的自訂文字輸入框 -->
                    <div id="other-remark-container" class="mt-6 hidden">
                        <label for="other_remark" class="block text-lg font-semibold text-gray-700 mb-3">自訂備註</label>
                        <input type="text" name="other_remark" id="other_remark" placeholder="請輸入備註內容" class="mt-1 block w-full px-3 py-2 bg-white border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500">
                    </div>
                     <div class="mt-8">
                        <button type="submit" name="abnormal_submit" class="w-full flex justify-center py-3 px-4 border border-transparent rounded-md shadow-sm text-base font-medium text-white bg-red-600 hover:bg-red-700">執行異常件更新</button>
                    </div>
                </form>
            </div>
        </main>
    </div>

    <script>
        // Tab 切換邏輯
        const tabUpload = document.getElementById('tab-upload');
        const tabAbnormal = document.getElementById('tab-abnormal');
        const contentUpload = document.getElementById('content-upload');
        const contentAbnormal = document.getElementById('content-abnormal');
        const otherRemarkContainer = document.getElementById('other-remark-container');
        const updateTypeRadios = document.querySelectorAll('input[name="update_type"]');

        tabUpload.addEventListener('click', () => {
            tabUpload.classList.add('active');
            tabAbnormal.classList.remove('active');
            contentUpload.classList.remove('hidden');
            contentAbnormal.classList.add('hidden');
        });

        tabAbnormal.addEventListener('click', () => {
            tabAbnormal.classList.add('active');
            tabUpload.classList.remove('active');
            contentAbnormal.classList.remove('hidden');
            contentUpload.classList.add('hidden');
        });

        // 多筆分號輸入框計數邏輯
        const textarea = document.getElementById('house_nos_abnormal');
        const lineCountDisplay = document.getElementById('line-count');
        const limit = 30;

        textarea.addEventListener('input', () => {
            const lines = textarea.value.split('\n').filter(line => line.trim() !== '');
            const count = lines.length;
            
            lineCountDisplay.textContent = `目前行數: ${count} (上限 ${limit} 筆)`;
            
            if (count > limit) {
                lineCountDisplay.classList.add('text-red-600', 'font-bold');
            } else {
                lineCountDisplay.classList.remove('text-red-600', 'font-bold');
            }
        });

        // 監聽 Radio Button 的變動
        updateTypeRadios.forEach(radio => {
            radio.addEventListener('change', () => {
                if (radio.value === 'other') {
                    otherRemarkContainer.classList.remove('hidden');
                } else {
                    otherRemarkContainer.classList.add('hidden');
                }
            });
        });

    </script>
</body>
</html>

