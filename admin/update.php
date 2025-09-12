<?php
session_start();

// 檢查使用者是否登入，否則導向到登入頁面
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

// 登出邏輯 (儘管此程式已獨立，但保留此邏輯以防需要)
if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: login.php");
    exit();
}
?>
<?php
// --- 資源優化設定 ---
//ini_set('memory_limit', '1024M');
//set_time_limit(900);

// 引入 Composer 的 autoloader (根據您的專案結構，請確認路徑是否正確)
// 建議在生產環境使用絕對路徑以避免因文件位置變動導致錯誤。
require '/var/www/html/excel/vendor/autoload.php'; 

use PhpOffice\PhpSpreadsheet\Shared\Date; // 只有在您需要解析 Excel 日期時才需要

// --- 輔助函式定義 ---
function write_log($message) {
    // 獨立的日誌檔案，用於此異常件更新程式
    $log_file = '/var/www/html/daily_outbound_abnormal_log.log'; 
    $timestamp = date('Y-m-d H:i:s');
    $formatted_message = "[{$timestamp}] " . $message . PHP_EOL;
    file_put_contents($log_file, $formatted_message, FILE_APPEND);
}

// 這個函式在這個獨立的程式中可能不是必需的，因為主要處理更新而非解析 Excel
// 但為了保持與原邏輯的一致性，我將其保留。
function parse_date_value($value) {
    if (empty($value)) return null;
    if (is_numeric($value)) return Date::excelToDateTimeObject($value)->format('Y-m-d H:i:s');
    if (is_string($value)) {
        $timestamp = strtotime($value);
        if ($timestamp !== false) return date('Y-m-d H:i:s', $timestamp);
    }
    throw new Exception("無法辨識的日期格式");
}

// --- 全域變數初始化 ---
$servername = "localhost";
$username = "alumi136";
$password = "Alumi!36";
$dbname = "kaohsiung_port_db";
$user_message = '';
$message_type = '';
$process_results = [];
$active_tab = 'abnormal'; // 這個程式只有異常件更新，所以預設為此分頁

// --- 核心處理邏輯 ---
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $conn = new mysqli($servername, $username, $password, $dbname);
    if ($conn->connect_error) {
        $user_message = "系統錯誤：無法連線到資料庫。";
        $message_type = 'error';
        write_log("資料庫連線失敗: " . $conn->connect_error);
    } else {
        $conn->set_charset("utf8mb4");

        // --- 異常件更新處理邏輯 (此為此程式的唯一功能) ---
        if (isset($_POST['abnormal_submit'])) {
            $house_nos_raw = trim($_POST['house_nos_abnormal']);
            $update_type = $_POST['update_type'] ?? '';
            $user_remark = trim($_POST['user_remark'] ?? ''); 
            $house_nos = array_filter(array_map('trim', preg_split('/\\r\\n|\\r|\\n/', $house_nos_raw)));
            
            if (empty($update_type)) {
                $user_message = "請選擇一個異常件更新的類型。";
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
                    $processed_count = 0;

                    foreach ($house_nos as $house_no) {
                        // 1. 查詢資料是否存在
                        $stmt_select = $conn->prepare("SELECT id, remark, storage_in_datetime, storage_out_datetime, packages_out, clearance_method, status0 FROM daily_outbound WHERE house_no = ? FOR UPDATE");
                        if ($stmt_select === false) throw new Exception("SQL SELECT 預備語句失敗: " . $conn->error);
                        $stmt_select->bind_param("s", $house_no);
                        $stmt_select->execute();
                        $result = $stmt_select->get_result()->fetch_assoc();
                        $stmt_select->close();

                        // 【*** 核心邏輯修改：根據是否存在資料，決定要更新還是新增 ***】
                        if ($result) {
                            // ----- 資料存在，執行 UPDATE (更新) 邏輯 -----

                            if (!empty($result['storage_out_datetime'])) {
                                $process_results[] = ['house_no' => $house_no, 'status' => '失敗', 'reason' => '此分提單號已經出庫，無法更新'];
                                continue;
                            }

                            $sql_update_parts = ["created_at = CURRENT_TIMESTAMP"];
                            $params = [];
                            $types = '';
                            $update_status0 = true;
                            if (isset($result['status0']) && (int)$result['status0'] > 1) {
                                $update_status0 = false;
                                write_log("分號 {$house_no} 的 status0 值為 {$result['status0']} (大於 1)，因此本次更新將不修改 status0。");
                            }
                            
                            switch ($update_type) {
                                // (此處 switch 內容與前一版完全相同)
                                case 'missed_scan':
                                    $clearance_method = $result['clearance_method'];
                                    if ($clearance_method == 'C1') $new_out_time = date('Y-m-d H:i:s', strtotime($result['storage_in_datetime'] . ' +2 minutes 20 seconds'));
                                    elseif ($clearance_method == 'C3') $new_out_time = date('Y-m-d H:i:s');
                                    else {
                                        $process_results[] = ['house_no' => $house_no, 'status' => '失敗', 'reason' => '漏刷僅適用於C1/C3通關方式'];
                                        continue 2;
                                    }
                                    $sql_update_parts[] = "storage_out_datetime = ?";
                                    $sql_update_parts[] = "packages_out = packages_out + 1";
                                    $remark_content = "漏刷";
                                    if ($update_status0) $sql_update_parts[] = "status0 = 3";
                                    array_push($params, $new_out_time);
                                    $types .= 's';
                                    break;
                                case 'screenshot': $remark_content = "海關要求提供訂單截圖"; if ($update_status0) $sql_update_parts[] = "status0 = 2"; break;
                                case 'formal_declaration': $remark_content = "轉正報"; if ($update_status0) $sql_update_parts[] = "status0 = 1"; break;
                                case 'abandon': $remark_content = "放棄"; if ($update_status0) $sql_update_parts[] = "status0 = 6"; break;
                                case 'seized': $remark_content = "查扣"; if ($update_status0) $sql_update_parts[] = "status0 = 5"; break;
                                case 'other': $remark_content = ""; if ($update_status0) $sql_update_parts[] = "status0 = 7"; break;
                            }
                            
                            $new_remark_part = $today_str . (!empty($remark_content) ? " " . $remark_content : "") . (!empty($user_remark) ? " - " . $user_remark : "");
                            $existing_remark = $result['remark'] ?? '';
                            $final_remark = !empty($existing_remark) ? $existing_remark . ';' . PHP_EOL . $new_remark_part : $new_remark_part;
                            
                            $sql_update_parts[] = "remark = ?";
                            array_push($params, $final_remark);
                            $types .= 's';

                            $sql_update = "UPDATE daily_outbound SET " . implode(', ', $sql_update_parts) . " WHERE id = ?";
                            array_push($params, $result['id']);
                            $types .= 'i';

                            $stmt_update = $conn->prepare($sql_update);
                            if ($stmt_update === false) throw new Exception("SQL 更新預備語句失敗: " . $conn->error);
                            $stmt_update->bind_param($types, ...$params);
                            $stmt_update->execute();
                            
                            if ($stmt_update->affected_rows > 0) {
                                $process_results[] = ['house_no' => $house_no, 'status' => '成功', 'reason' => '更新完成'];
                                $processed_count++;
                            } else {
                                $process_results[] = ['house_no' => $house_no, 'status' => '注意', 'reason' => '資料未變更或更新失敗'];
                            }
                            $stmt_update->close();

                        } else {
                            // ----- 資料不存在，執行 INSERT (新增) 邏輯 -----

                            // **特別處理**：漏刷無法在新增時使用，因為缺少必要資訊
                            if ($update_type === 'missed_scan') {
                                $process_results[] = ['house_no' => $house_no, 'status' => '失敗', 'reason' => '漏刷操作無法用於新增資料，因缺少進倉時間'];
                                continue; // 跳過此分號
                            }

                            $status0_to_set = null;
                            $remark_content = '';
                            switch ($update_type) {
                                case 'screenshot': $remark_content = "海關要求提供訂單截圖"; $status0_to_set = 2; break;
                                case 'formal_declaration': $remark_content = "轉正報"; $status0_to_set = 1; break;
                                case 'abandon': $remark_content = "放棄"; $status0_to_set = 6; break;
                                case 'seized': $remark_content = "查扣"; $status0_to_set = 5; break;
                                case 'other': $remark_content = ""; $status0_to_set = 7; break;
                            }

                            // 組合新紀錄的備註 (不需疊加)
                            $new_remark = $today_str . (!empty($remark_content) ? " " . $remark_content : "") . (!empty($user_remark) ? " - " . $user_remark : "");

                            $sql_insert = "INSERT INTO daily_outbound (house_no, status0, remark, created_at) VALUES (?, ?, ?, CURRENT_TIMESTAMP)";
                            $stmt_insert = $conn->prepare($sql_insert);
                            if ($stmt_insert === false) throw new Exception("SQL 新增預備語句失敗: " . $conn->error);
                            
                            $stmt_insert->bind_param("sis", $house_no, $status0_to_set, $new_remark);
                            $stmt_insert->execute();

                            if ($stmt_insert->affected_rows > 0) {
                                $process_results[] = ['house_no' => $house_no, 'status' => '成功', 'reason' => '資料不存在，已新增一筆'];
                                $processed_count++;
                            } else {
                                $process_results[] = ['house_no' => $house_no, 'status' => '失敗', 'reason' => '新增資料時發生錯誤'];
                            }
                            $stmt_insert->close();
                        }
                    }
                    
                    $conn->commit();
                    $user_message = "處理完成。共成功處理(更新/新增) {$processed_count} 筆資料。";
                    $message_type = 'success';

                } catch (Exception $e) {
                    $conn->rollback();
                    $user_message = "處理過程中發生錯誤，所有操作已還原。錯誤訊息: " . $e->getMessage();
                    $message_type = 'error';
                    write_log("異常件處理失敗: " . $e->getMessage());
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
    <title>高雄港萬海倉海運快遞異常件管理</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', 'Noto Sans TC', sans-serif; }
    </style>
</head>
<body class="bg-gray-100 p-4">
    <div class="container mx-auto p-4 md:p-8 max-w-4xl bg-white rounded-xl shadow-lg">
        <header class="text-center mb-10">
            <h1 class="text-3xl md:text-4xl font-bold text-blue-900">高雄港萬海倉海運快遞異常件管理系統</h1>
        </header>

        <main>
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
                    <div class="mt-4 max-h-48 overflow-y-auto border border-gray-200 rounded-md p-2 bg-white">
                        <h4 class="font-bold mb-2 text-gray-700">詳細處理狀態：</h4>
                        <ul class="list-disc list-inside text-sm text-gray-600">
                        <?php foreach ($process_results as $res): ?>
                            <li>分號 [<?php echo htmlspecialchars($res['house_no']); ?>]: <span class="<?php 
                                if ($res['status'] === '成功') echo 'text-green-700';
                                elseif ($res['status'] === '失敗') echo 'text-red-700';
                                elseif ($res['status'] === '注意') echo 'text-yellow-700';
                            ?>"><?php echo htmlspecialchars($res['status']); ?></span> - <?php echo htmlspecialchars($res['reason']); ?></li>
                        <?php endforeach; ?>
                        </ul>
                    </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <div id="content-abnormal">
                 <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post" class="bg-white rounded-xl p-6 md:p-8">
                    <div class="mb-6">
                        <label for="house_nos_abnormal" class="block text-lg font-semibold text-gray-700 mb-3">輸入多筆分號 (每筆一行)</label>
                        <textarea name="house_nos_abnormal" id="house_nos_abnormal" rows="8" class="mt-1 block w-full px-3 py-2 bg-white border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 placeholder-gray-400" placeholder="請在此輸入分號，每行一個"></textarea>
                        <p id="line-count" class="mt-2 text-sm text-gray-500">目前行數: 0 (上限 30 筆)</p>
                    </div>
                    <div class="mt-6 mb-6">
                        <label class="block text-lg font-semibold text-gray-700 mb-3">選擇更新類型</label>
                        <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-4">
                            <?php 
                                $options = [
                                    'missed_scan' => '漏刷',
                                    'screenshot' => '提供訂單截圖',
                                    'formal_declaration' => '轉正報',
                                    'abandon' => '放棄',
                                    'seized' => '查扣',
                                    'other' => '其他'
                                ];
                                foreach ($options as $value => $label):
                            ?>
                            <label class="flex items-center p-3 border border-gray-300 rounded-lg hover:bg-gray-50 cursor-pointer shadow-sm">
                                <input type="radio" name="update_type" value="<?php echo $value; ?>" class="h-4 w-4 text-indigo-600 focus:ring-indigo-500 border-gray-300">
                                <span class="ml-3 text-sm font-medium text-gray-800"><?php echo $label; ?></span>
                            </label>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div id="user-remark-container" class="mt-6">
                        <label for="user_remark" class="block text-lg font-semibold text-gray-700 mb-3">備註 (可選)</label>
                        <input type="text" name="user_remark" id="user_remark" placeholder="請輸入額外備註內容" class="mt-1 block w-full px-3 py-2 bg-white border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500">
                    </div>

                    <div class="mt-8">
                        <button type="submit" name="abnormal_submit" class="w-full flex justify-center py-3 px-4 border border-transparent rounded-md shadow-sm text-base font-medium text-white bg-red-600 hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-red-500">執行處理</button>
                    </div>
                </form>
            </div>
        </main>
    </div>

    <script>
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

        document.addEventListener('DOMContentLoaded', () => {
            const event = new Event('input');
            textarea.dispatchEvent(event);
        });
    </script>
</body>
</html>
