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
require __DIR__ . '/excel/vendor/autoload.php'; // 嘗試使用相對路徑

use PhpOffice\PhpSpreadsheet\Shared\Date; // 只有在您需要解析 Excel 日期時才需要

// --- 輔助函式定義 ---
function write_log($message) {
    // 獨立的日誌檔案，用於此異常件更新程式
    $log_file = __DIR__ . '/daily_outbound_abnormal_log.log'; // 嘗試使用相對路徑
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
    return null; // 改為回傳 null
}

// --- 全域變數初始化 ---
require_once 'config.php'; // 改為引用 config.php 獲取 $pdo

$user_message = '';
$message_type = '';
$process_results = [];
$active_tab = 'abnormal';
$input_limit = 50;

// --- 核心處理邏輯 ---
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    if (!isset($pdo)) {
        $user_message = "系統錯誤：無法讀取資料庫設定。";
        $message_type = 'error';
        write_log("無法讀取 PDO 設定。");
    } else {
        if (isset($_POST['abnormal_submit'])) {
            $house_nos_raw = trim($_POST['house_nos_abnormal']);
            $update_type = $_POST['update_type'] ?? '';
            $user_remark = trim($_POST['user_remark'] ?? '');
            $master_no_shortage = trim($_POST['master_no_shortage'] ?? '');
            $house_nos = array_filter(array_map('trim', preg_split('/\\r\\n|\\r|\\n/', $house_nos_raw)));

            // --- 輸入驗證 ---
            if (empty($update_type)) {
                $user_message = "請選擇一個異常件更新的類型。"; $message_type = 'warn';
            } elseif ($update_type === 'shortage' && empty($master_no_shortage)) {
                 $user_message = "選擇 [短卸] 類型時，必須填寫主號。"; $message_type = 'warn';
            } elseif (empty($house_nos)) {
                $user_message = "請輸入至少一個要更新的分號。"; $message_type = 'warn';
            } elseif (count($house_nos) > $input_limit) {
                $user_message = "一次最多只能處理 {$input_limit} 筆分號。"; $message_type = 'warn';
            } else {
                // --- 開始處理資料庫 ---
                $pdo->beginTransaction();
                try {
                    $today_str = date('Y-m-d');
                    $processed_count = 0;
                    $total_shortage_packages_to_deduct = 0; // 用於累加 *首次* 短卸的件數

                    foreach ($house_nos as $house_no) {
                        if ($update_type === 'shortage') {
                            // 1. 查詢資料是否存在，並獲取 total_packages, remark, status0
                            // 【最新修改】加入讀取 status0
                            $stmt_select = $pdo->prepare("SELECT id, total_packages, remark, status0 FROM daily_outbound WHERE master_no = :master_no AND house_no = :house_no FOR UPDATE");
                            $stmt_select->bindParam(':master_no', $master_no_shortage);
                            $stmt_select->bindParam(':house_no', $house_no);
                            $stmt_select->execute();
                            $result = $stmt_select->fetch(PDO::FETCH_ASSOC);

                            if ($result) {
                                $record_id = $result['id'];
                                $packages_to_deduct = (int)($result['total_packages'] ?? 0);
                                $existing_status0 = $result['status0']; // 【最新修改】取得目前的 status0
                                $existing_remark = $result['remark'] ?? '';
                                $remark_content = "短卸";

                                $new_remark_part = $today_str . " " . $remark_content . (!empty($user_remark) ? " - " . $user_remark : "");
                                $final_remark = !empty($existing_remark) ? $existing_remark . ';' . PHP_EOL . $new_remark_part : $new_remark_part;

                                // 2. 更新 daily_outbound (status0 設為 8, 更新 remark)
                                $stmt_update = $pdo->prepare("UPDATE daily_outbound SET status0 = 8, remark = :remark, created_at = CURRENT_TIMESTAMP WHERE id = :id");
                                $stmt_update->bindParam(':remark', $final_remark);
                                $stmt_update->bindParam(':id', $record_id);
                                $update_success = $stmt_update->execute();
                                $rows_affected = $stmt_update->rowCount();

                                if ($update_success) {
                                     // 【最新修改】加入判斷，只有當 status0 原本不是 8 時，才累加件數
                                    if ($existing_status0 != 8) {
                                        $total_shortage_packages_to_deduct += $packages_to_deduct;
                                        $process_results[] = ['house_no' => $house_no, 'status' => '成功', 'reason' => '短卸標記完成'];
                                        write_log("分號 {$house_no} (原 status0={$existing_status0}) 標記短卸，{$packages_to_deduct} 件加入扣除總數。");
                                    } else {
                                        // 雖然 status0 沒變，但 remark 可能有更新，仍算成功處理
                                        $process_results[] = ['house_no' => $house_no, 'status' => '成功', 'reason' => '短卸標記完成 (先前已標記)'];
                                        write_log("分號 {$house_no} (原 status0=8) 已處理過短卸，本次僅更新備註，不重複扣除件數。");
                                    }
                                     $processed_count++; // 只要更新成功就算處理一筆
                                } else {
                                    $process_results[] = ['house_no' => $house_no, 'status' => '失敗', 'reason' => '更新資料庫時發生錯誤(短卸)'];
                                    write_log("短卸更新失敗 for house_no {$house_no}: " . implode(", ", $stmt_update->errorInfo()));
                                }

                            } else {
                                $process_results[] = ['house_no' => $house_no, 'status' => '失敗', 'reason' => '找不到符合的主號與分號組合'];
                            }

                        } else {
                            // --- 原有的其他更新類型邏輯 (保持不變) ---
                            $stmt_select = $pdo->prepare("SELECT id, remark, storage_in_datetime, storage_out_datetime, packages_out, clearance_method, status0 FROM daily_outbound WHERE house_no = :house_no FOR UPDATE");
                            $stmt_select->bindParam(':house_no', $house_no);
                            $stmt_select->execute();
                            $result = $stmt_select->fetch(PDO::FETCH_ASSOC);

                            if ($result) {
                                if (!empty($result['storage_out_datetime'])) { $process_results[] = ['house_no' => $house_no, 'status' => '失敗', 'reason' => '此分提單號已經出庫，無法更新']; continue; }
                                $update_fields = []; $params = ['id' => $result['id']]; $update_status0 = true;
                                if (isset($result['status0']) && (int)$result['status0'] > 1) { $update_status0 = false; write_log("分號 {$house_no} 的 status0 值為 {$result['status0']}，不修改 status0。"); }
                                $remark_content = ''; $new_out_time = null;
                                switch ($update_type) {
                                    case 'missed_scan':
                                        $clearance_method = $result['clearance_method'];
                                        if ($result['storage_in_datetime']) {
                                            if ($clearance_method == 'C1') $new_out_time = date('Y-m-d H:i:s', strtotime($result['storage_in_datetime'] . ' +2 minutes 20 seconds'));
                                            elseif ($clearance_method == 'C3') $new_out_time = date('Y-m-d H:i:s');
                                            else { $process_results[] = ['house_no' => $house_no, 'status' => '失敗', 'reason' => '漏刷僅適用於C1/C3']; continue 2; }
                                        } else { $process_results[] = ['house_no' => $house_no, 'status' => '失敗', 'reason' => '漏刷缺進倉時間']; continue 2; }
                                        $update_fields[] = "storage_out_datetime = :storage_out_datetime"; $update_fields[] = "packages_out = packages_out + 1"; $params['storage_out_datetime'] = $new_out_time; $remark_content = "漏刷"; if ($update_status0) { $update_fields[] = "status0 = 3"; } break;
                                    case 'screenshot': $remark_content = "海關要求提供訂單截圖"; if ($update_status0) { $update_fields[] = "status0 = 2"; } break;
                                    case 'formal_declaration': $remark_content = "轉正報"; if ($update_status0) { $update_fields[] = "status0 = 1"; } break;
                                    case 'abandon': $remark_content = "放棄"; if ($update_status0) { $update_fields[] = "status0 = 6"; } break;
                                    case 'seized': $remark_content = "查扣"; if ($update_status0) { $update_fields[] = "status0 = 5"; } break;
                                    case 'wait_realname': $remark_content = "待實名"; if ($update_status0) { $update_fields[] = "status0 = 9"; } break; // 新增待實名邏輯
                                    case 'other': $remark_content = ""; if ($update_status0) { $update_fields[] = "status0 = 7"; } break;
                                }
                                $new_remark_part = $today_str . (!empty($remark_content) ? " " . $remark_content : "") . (!empty($user_remark) ? " - " . $user_remark : "");
                                $existing_remark = $result['remark'] ?? ''; $final_remark = !empty($existing_remark) ? $existing_remark . ';' . PHP_EOL . $new_remark_part : $new_remark_part;
                                $update_fields[] = "remark = :remark"; $update_fields[] = "created_at = CURRENT_TIMESTAMP"; $params['remark'] = $final_remark;
                                if (!empty($update_fields)) {
                                    $sql_update = "UPDATE daily_outbound SET " . implode(', ', $update_fields) . " WHERE id = :id"; $stmt_update = $pdo->prepare($sql_update); if ($stmt_update === false) throw new Exception("SQL 更新預備語句失敗: " . $pdo->errorInfo()[2]); $stmt_update->execute($params);
                                    if ($stmt_update->rowCount() > 0) { $process_results[] = ['house_no' => $house_no, 'status' => '成功', 'reason' => '更新完成']; $processed_count++; } else { $process_results[] = ['house_no' => $house_no, 'status' => '注意', 'reason' => '資料未變更或更新失敗']; }
                                } else { $process_results[] = ['house_no' => $house_no, 'status' => '注意', 'reason' => '無有效更新操作']; }
                            } else {
                                if ($update_type === 'missed_scan') { $process_results[] = ['house_no' => $house_no, 'status' => '失敗', 'reason' => '漏刷無法用於新增資料']; continue; }
                                $status0_to_set = null; $remark_content = '';
                                switch ($update_type) {
                                    case 'screenshot': $remark_content = "海關要求提供訂單截圖"; $status0_to_set = 2; break;
                                    case 'formal_declaration': $remark_content = "轉正報"; $status0_to_set = 1; break;
                                    case 'abandon': $remark_content = "放棄"; $status0_to_set = 6; break;
                                    case 'seized': $remark_content = "查扣"; $status0_to_set = 5; break;
                                    case 'wait_realname': $remark_content = "待實名"; $status0_to_set = 9; break; // 新增待實名邏輯
                                    case 'other': $remark_content = ""; $status0_to_set = 7; break;
                                }
                                $new_remark = $today_str . (!empty($remark_content) ? " " . $remark_content : "") . (!empty($user_remark) ? " - " . $user_remark : "");
                                $sql_insert = "INSERT INTO daily_outbound (house_no, status0, remark, created_at) VALUES (:house_no, :status0, :remark, CURRENT_TIMESTAMP)"; $stmt_insert = $pdo->prepare($sql_insert); if ($stmt_insert === false) throw new Exception("SQL 新增預備語句失敗: " . $pdo->errorInfo()[2]);
                                $stmt_insert->bindParam(':house_no', $house_no); $stmt_insert->bindParam(':status0', $status0_to_set, PDO::PARAM_INT); $stmt_insert->bindParam(':remark', $new_remark); $stmt_insert->execute();
                                if ($stmt_insert->rowCount() > 0) { $process_results[] = ['house_no' => $house_no, 'status' => '成功', 'reason' => '資料不存在，已新增']; $processed_count++; } else { $process_results[] = ['house_no' => $house_no, 'status' => '失敗', 'reason' => '新增資料時發生錯誤']; }
                            }
                        } // End else (非短卸類型)
                    } // End foreach house_no

                    // 在處理完所有分號後，如果類型是短卸且有 *需要扣除* 的件數，則更新 daily_arrange
                    if ($update_type === 'shortage' && $total_shortage_packages_to_deduct > 0) {
                        write_log("準備從 daily_arrange 的主號 {$master_no_shortage} 扣除 {$total_shortage_packages_to_deduct} 件 (僅首次標記者)。");
                        $stmt_update_arrange = $pdo->prepare("UPDATE daily_arrange SET quantity = quantity - :deduct_qty WHERE bl_number = :bl_number");
                        $stmt_update_arrange->bindParam(':deduct_qty', $total_shortage_packages_to_deduct, PDO::PARAM_INT);
                        $stmt_update_arrange->bindParam(':bl_number', $master_no_shortage);
                        $stmt_update_arrange->execute();

                        if ($stmt_update_arrange->rowCount() > 0) {
                             write_log("成功更新 daily_arrange 主號 {$master_no_shortage} 的數量。");
                        } else {
                             write_log("警告：更新 daily_arrange 主號 {$master_no_shortage} 的數量失敗或未找到對應主號。");
                             $user_message .= " (注意：更新總表數量失敗或主號不存在)";
                             $message_type = 'warn';
                        }
                    } elseif ($update_type === 'shortage' && $total_shortage_packages_to_deduct == 0 && $processed_count > 0) {
                        write_log("短卸操作處理 {$processed_count} 筆，但所有分號先前均已標記，無需更新 daily_arrange。");
                    }

                    $pdo->commit();
                    if(empty($user_message)){ // 避免覆蓋上面的警告
                        $user_message = "處理完成。共成功處理(更新/新增) {$processed_count} 筆分號。";
                        $message_type = 'success';
                        // 如果是短卸且有扣除，可以附加訊息
                        if ($update_type === 'shortage' && $total_shortage_packages_to_deduct > 0) {
                            $user_message .= " 並已從總表扣除 {$total_shortage_packages_to_deduct} 件。";
                        } elseif ($update_type === 'shortage' && $total_shortage_packages_to_deduct == 0 && $processed_count > 0) {
                             $user_message .= " (所有分號先前已標記短卸，總表數量未變更)";
                        }
                    }

                } catch (Exception $e) {
                    $pdo->rollBack();
                    $user_message = "處理過程中發生錯誤，所有操作已還原。錯誤訊息: " . $e->getMessage();
                    $message_type = 'error';
                    write_log("異常件處理失敗: " . $e->getMessage());
                }
            }
        } // End abnormal_submit
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
        #master-no-shortage-container { display: none; }
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
                        default: echo 'bg-blue-100 text-blue-800'; break;
                    }
                ?>">
                    <p><?php echo nl2br(htmlspecialchars($user_message)); ?></p>
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
                        <p id="line-count" class="mt-2 text-sm text-gray-500">目前行數: 0 (上限 <?php echo $input_limit; ?> 筆)</p>
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
                                    'wait_realname' => '待實名', // 新增待實名選項
                                    'shortage' => '短卸',
                                    'other' => '其他'
                                ];
                                foreach ($options as $value => $label):
                            ?>
                            <label class="flex items-center p-3 border border-gray-300 rounded-lg hover:bg-gray-50 cursor-pointer shadow-sm">
                                <input type="radio" name="update_type" value="<?php echo $value; ?>" class="h-4 w-4 text-indigo-600 focus:ring-indigo-500 border-gray-300 update-type-radio">
                                <span class="ml-3 text-sm font-medium text-gray-800"><?php echo $label; ?></span>
                            </label>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div id="master-no-shortage-container" class="mt-6 mb-6">
                        <label for="master_no_shortage" class="block text-lg font-semibold text-red-700 mb-3">輸入主號 (短卸專用)</label>
                        <input type="text" name="master_no_shortage" id="master_no_shortage" placeholder="請輸入對應的主號" class="mt-1 block w-full px-3 py-2 bg-white border border-red-300 rounded-md shadow-sm focus:outline-none focus:ring-red-500 focus:border-red-500">
                        <p class="mt-2 text-sm text-red-600 font-semibold">注意：只能填一個主號，請確認主號與上方分號列表對應。</p>
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
        const limit = <?php echo $input_limit; ?>;

        textarea.addEventListener('input', () => {
            const lines = textarea.value.split('\n').filter(line => line.trim() !== '');
            const count = lines.length;
            lineCountDisplay.textContent = `目前行數: ${count} (上限 ${limit} 筆)`;
            if (count > limit) { lineCountDisplay.classList.add('text-red-600', 'font-bold'); } else { lineCountDisplay.classList.remove('text-red-600', 'font-bold'); }
        });

        document.addEventListener('DOMContentLoaded', () => {
            const event = new Event('input');
            textarea.dispatchEvent(event);

            const updateTypeRadios = document.querySelectorAll('.update-type-radio');
            const masterNoContainer = document.getElementById('master-no-shortage-container');
            const masterNoInput = document.getElementById('master_no_shortage');

            updateTypeRadios.forEach(radio => {
                radio.addEventListener('change', function() {
                    if (this.value === 'shortage' && this.checked) {
                        masterNoContainer.style.display = 'block'; masterNoInput.required = true;
                    } else {
                        masterNoContainer.style.display = 'none'; masterNoInput.required = false; masterNoInput.value = '';
                    }
                });
            });

            const checkedRadio = document.querySelector('.update-type-radio:checked');
            if (checkedRadio && checkedRadio.value === 'shortage') {
                 masterNoContainer.style.display = 'block'; masterNoInput.required = true;
            }
        });
    </script>
</body>
</html>