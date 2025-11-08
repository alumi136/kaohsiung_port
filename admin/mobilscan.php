<?php
// 檔案: mobilscan.php
// v3: 處理分號 (house_no) 對應多個主號 (master_no) 的情況

session_start();
require_once 'config.php';

if (!isset($_SESSION['user_id'])) {
    echo "<script>window.opener && window.opener.location.reload(); window.close();</script>";
    exit();
}

$user_full_name = $_SESSION['user_full_name'] ?? '未知使用者';
$message = '';
$message_type = '';

// --- 狀態變數初始化 (用於表單回填) ---
$scanned_value_prefill = '';
$action_type_prefill = '';
$manual_remark_prefill = '';
$master_no_choices = []; // 用於儲存多個主號的選項

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['scanned_value'])) {
    
    // --- 獲取所有 POST 資料 ---
    $scanned_value = trim($_POST['scanned_value']);
    $action_type = $_POST['action_type'] ?? '';
    $manual_remark = trim($_POST['manual_remark'] ?? '');
    
    // 【修改】獲取使用者選擇的特定主號 (如果有的話)
    $selected_master_no = $_POST['selected_master_no'] ?? null;

    if (empty($scanned_value) || empty($action_type)) {
        $message = '錯誤：掃描內容和處理方式不能為空。';
        $message_type = 'error';
        $scanned_value_prefill = $scanned_value; // 保留錯誤的輸入
    } else {
        try {
            // --- 準備資料 (這部分邏輯不變) ---
            $today = date('Y-m-d');
            $status0 = 0;
            $auto_remark = '';

            switch ($action_type) {
                case 'order_screenshot': $status0 = 2; $auto_remark = "{$today} 要提供訂單截圖"; break;
                case 'formal_declaration': $status0 = 1; $auto_remark = "{$today} 轉正報"; break;
                case 'missing_package': $status0 = 8; $auto_remark = "{$today} 漏件"; break;
                case 'other': $status0 = 7; $auto_remark = $today; break;
            }

            $final_remark = $auto_remark;
            if (!empty($manual_remark)) {
                $final_remark .= (empty($final_remark) ? '' : '； ') . $manual_remark;
            }

            $pdo->beginTransaction();
            $skip_commit = false;

            // --- 【*** 核心邏輯修改：檢查是否有 selected_master_no ***】 ---
            
            if (!empty($selected_master_no)) {
                
                // --- 階段 2: 使用者已選擇主號，直接更新 ---
                $stmt_update = $pdo->prepare(
                    "UPDATE daily_outbound 
                     SET status0 = ?, remark = ?, customer_name = ?, mobile_time = CURRENT_TIMESTAMP 
                     WHERE house_no = ? AND master_no = ?"
                );
                $stmt_update->execute([$status0, $final_remark, $user_full_name, $scanned_value, $selected_master_no]);
                $message = "操作成功：分號 [{$scanned_value}] (主號: {$selected_master_no}) 的狀態已更新。";
            
            } else {
                
                // --- 階段 1: 首次提交，檢查主號 ---
                $stmt_check = $pdo->prepare("SELECT DISTINCT master_no FROM daily_outbound WHERE house_no = ? AND master_no IS NOT NULL AND master_no != ''");
                $stmt_check->execute([$scanned_value]);
                $masters = $stmt_check->fetchAll(PDO::FETCH_COLUMN);

                if (count($masters) == 0) {
                    // C. 找不到：新增一筆
                    $stmt_insert = $pdo->prepare(
                        "INSERT INTO daily_outbound (house_no, status0, remark, customer_name, mobile_time) 
                         VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP)"
                    );
                    $stmt_insert->execute([$scanned_value, $status0, $final_remark, $user_full_name]);
                    $message = "操作成功：分號 [{$scanned_value}] 不存在，已為您新增此筆異常記錄。";
                
                } elseif (count($masters) == 1) {
                    // B. 找到 1 筆：直接更新
                    $master_no_to_update = $masters[0];
                    $stmt_update = $pdo->prepare(
                        "UPDATE daily_outbound 
                         SET status0 = ?, remark = ?, customer_name = ?, mobile_time = CURRENT_TIMESTAMP 
                         WHERE house_no = ? AND master_no = ?"
                    );
                    $stmt_update->execute([$status0, $final_remark, $user_full_name, $scanned_value, $master_no_to_update]);
                    $message = "操作成功：分號 [{$scanned_value}] (主號: {$master_no_to_update}) 的狀態已更新。";
                
                } else {
                    // A. 找到 2 筆 (或以上)：暫停，要求使用者選擇
                    $message = "發現多筆主號：分號 [{$scanned_value}] 存在於 " . count($masters) . " 個不同的主號下。請選擇您要更新的主號。";
                    $message_type = 'warn'; // 設定為警告
                    $master_no_choices = $masters; // 將主號列表傳給 HTML
                    $skip_commit = true; // 跳過 commit
                    
                    // 回填所有表單資料，以便第二階段提交
                    $scanned_value_prefill = $scanned_value;
                    $action_type_prefill = $action_type;
                    $manual_remark_prefill = $manual_remark;
                }
            }
            
            // --- 提交或回滾 ---
            if (!$skip_commit) {
                $pdo->commit();
                $message_type = 'success';
            } else {
                $pdo->rollBack(); // 雖然沒執行，但還是回滾
            }

        } catch (PDOException $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            $message = "操作失敗：" . $e->getMessage();
            $message_type = 'error';
            $scanned_value_prefill = $scanned_value; // 發生錯誤時保留分號
        }
    }
}
?>

<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>手機掃碼作業</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+TC:wght@400;500;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Noto Sans TC', sans-serif; -webkit-tap-highlight-color: transparent; }
        #scanner-container { position: relative; overflow: hidden; border-radius: 0.5rem; }
        #video { width: 100%; height: auto; }
        .laser {
            position: absolute; top: 50%; left: 10%; right: 10%;
            height: 2px; background: red; box-shadow: 0 0 10px red;
            animation: scanning 2s infinite ease-in-out;
        }
        @keyframes scanning { 0% { top: 20%; } 50% { top: 80%; } 100% { top: 20%; } }
        .form-input { 
            @apply block w-full px-4 py-3 bg-gray-100 border-2 border-gray-300 rounded-lg text-lg text-black placeholder-gray-500 focus:outline-none focus:bg-white focus:border-blue-500 transition-colors;
        }
        .btn { 
            @apply w-full px-4 py-4 text-xl font-bold text-white rounded-lg shadow-md transform active:scale-95 transition-all duration-200;
        }
    </style>
</head>
<body class="bg-white text-black">

    <div class="w-full max-w-md mx-auto p-6 space-y-6">
        <h1 class="text-3xl font-bold text-center text-gray-800 mt-4">手機掃碼作業</h1>

        <?php if ($message): ?>
            <div id="message-box" class="p-4 rounded-lg text-center font-semibold <?php 
                if ($message_type === 'success') echo 'bg-green-100 text-green-700';
                elseif ($message_type === 'error') echo 'bg-red-100 text-red-700';
                else echo 'bg-yellow-100 text-yellow-800'; // 'warn'
            ?>">
                <p><?php echo htmlspecialchars($message); ?></p>
            </div>
        <?php endif; ?>

        <div id="scanner-ui" class="space-y-4">
            <div id="scanner-container" class="hidden aspect-video bg-black rounded-lg shadow-inner">
                <video id="video" playsinline></video>
                <div class="laser"></div>
            </div>
            <button id="startButton" type="button" class="btn bg-blue-600 hover:bg-blue-700">
                <span id="startButtonText" class="flex items-center justify-center">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 mr-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" /></svg>
                    啟動相機
                </span>
            </button>
            <p id="status-text" class="text-center text-gray-600 font-medium h-6"></p>
        </div>

        <form id="main-form" action="mobilscan.php" method="POST" class="space-y-6">
            <div>
                <label for="scanned_value" class="block text-base font-medium text-gray-700 mb-2">分號 (House No.)</label>
                <textarea name="scanned_value" id="scanned_value" rows="3" class="form-input" placeholder="掃描結果會顯示於此..." required <?php if (!empty($master_no_choices)) echo 'readonly class="bg-gray-200"'; ?>><?php echo htmlspecialchars($scanned_value_prefill); ?></textarea>
            </div>
            
            <?php if (!empty($master_no_choices)): ?>
                <div id="master-choice-container" class="p-4 bg-yellow-100 border-2 border-yellow-300 rounded-lg">
                    <label class="block text-base font-medium text-yellow-900 mb-3">請選擇要更新的主號：</label>
                    <div class="space-y-2">
                        <?php foreach ($master_no_choices as $master): ?>
                        <label class="flex items-center p-3 bg-white rounded-lg border border-gray-300 shadow-sm cursor-pointer hover:bg-gray-50">
                            <input type="radio" name="selected_master_no" value="<?php echo htmlspecialchars($master); ?>" class="h-5 w-5 text-blue-600" required>
                            <span class="ml-3 text-lg font-bold text-gray-800"><?php echo htmlspecialchars($master); ?></span>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <input type="hidden" name="action_type" value="<?php echo htmlspecialchars($action_type_prefill); ?>">
                <input type="hidden" name="manual_remark" value="<?php echo htmlspecialchars($manual_remark_prefill); ?>">
            <?php endif; ?>
            <div id="action-selection-container" class="space-y-6 <?php if (!empty($master_no_choices)) echo 'hidden'; ?>">
                <div>
                    <label for="action_type" class="block text-base font-medium text-gray-700 mb-2">處理方式</label>
                    <select name="action_type" id="action_type" class="form-input" <?php if (!empty($master_no_choices)) echo 'disabled'; else echo 'required'; ?>>
                        <option value="" disabled <?php if(empty($action_type_prefill)) echo 'selected'; ?>>-- 請選擇異常類型 --</option>
                        <option value="order_screenshot" <?php echo ($action_type_prefill == 'order_screenshot') ? 'selected' : ''; ?>>提供訂單截圖</option>
                        <option value="formal_declaration" <?php echo ($action_type_prefill == 'formal_declaration') ? 'selected' : ''; ?>>轉正報</option>
                        <option value="missing_package" <?php echo ($action_type_prefill == 'missing_package') ? 'selected' : ''; ?>>漏件</option>
                        <option value="other" <?php echo ($action_type_prefill == 'other') ? 'selected' : ''; ?>>其他</option>
                    </select>
                </div>

                <div>
                    <label for="manual_remark" class="block text-base font-medium text-gray-700 mb-2">備註 (可選填)</label>
                    <input type="text" name="manual_remark" id="manual_remark" class="form-input" placeholder="可輸入額外說明..." maxlength="40" value="<?php echo htmlspecialchars($manual_remark_prefill); ?>" <?php if (!empty($master_no_choices)) echo 'disabled'; ?>>
                </div>
            </div>
            <div class="pt-4">
                <button type="submit" class="btn bg-green-600 hover:bg-green-700">
                    <?php echo (!empty($master_no_choices)) ? '確認並送出' : '執行處理'; ?>
                </button>
            </div>
        </form>
    </div>

    <script type="text/javascript" src="https://unpkg.com/@zxing/library@latest/umd/index.min.js"></script>
    <script type="text/javascript">
        window.addEventListener('load', function () {
            // ... (JS 邏輯與您提供的版本相同，此處未修改) ...
            const codeReader = new ZXing.BrowserMultiFormatReader();
            const startButton = document.getElementById('startButton');
            const startButtonText = document.getElementById('startButtonText');
            const scannerContainer = document.getElementById('scanner-container');
            const scannedValueTextarea = document.getElementById('scanned_value');
            const statusText = document.getElementById('status-text');
            const messageBox = document.getElementById('message-box');
            const form = document.getElementById('main-form');
            let isScanning = false;

            // 【*** 修改：如果正在選擇主號，禁止掃描 ***】
            const isChoosingMaster = <?php echo !empty($master_no_choices) ? 'true' : 'false'; ?>;
            if (isChoosingMaster) {
                startButton.disabled = true;
                startButton.classList.add('opacity-50', 'cursor-not-allowed');
                startButtonText.innerHTML = '請先完成主號選擇';
            }

            function startScan() {
                if (isChoosingMaster) return; // 如果在選擇主號，禁止啟動
                if(messageBox) messageBox.style.display = 'none';
                statusText.textContent = "正在請求相機權限...";
                scannerContainer.classList.remove('hidden');
                isScanning = true;
                startButtonText.innerHTML = `<svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 mr-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>關閉相機`;
                
                codeReader.listVideoInputDevices()
                    .then((videoInputDevices) => {
                        if (videoInputDevices.length === 0) throw new Error("找不到任何攝影機裝置。");
                        const rearCamera = videoInputDevices.find(device => device.label.toLowerCase().includes('back') || device.label.toLowerCase().includes('後'));
                        const selectedDeviceId = rearCamera ? rearCamera.deviceId : videoInputDevices[0].deviceId;

                        statusText.textContent = "請將條碼對準掃描線...";
                        
                        codeReader.decodeFromVideoDevice(selectedDeviceId, 'video', (result, err) => {
                            if (result) {
                                stopScan();
                                scannedValueTextarea.value = result.text;
                                statusText.innerHTML = `<span class="font-bold text-blue-600">掃描成功 (${result.text})</span>`;
                                
                                const audioContext = new (window.AudioContext || window.webkitAudioContext)();
                                const oscillator = audioContext.createOscillator();
                                oscillator.connect(audioContext.destination);
                                oscillator.type = 'sine';
                                oscillator.frequency.setValueAtTime(900, audioContext.currentTime);
                                oscillator.start();
                                oscillator.stop(audioContext.currentTime + 0.1);
                            }
                            if (err && !(err instanceof ZXing.NotFoundException)) {
                                console.error(err);
                                statusText.innerHTML = `<b class="text-red-600">掃描出錯:</b> ${err.message}`;
                            }
                        });
                    })
                    .catch((err) => {
                        console.error(err);
                        statusText.innerHTML = `<b class="text-red-600">無法啟動相機:</b> ${err.message}`;
                        if(err.name === "NotAllowedError") {
                           statusText.innerHTML = "<b>您已拒絕相機權限</b>，請至瀏覽器設定中手動開啟。";
                        }
                        stopScan();
                    });
            }

            function stopScan() {
                codeReader.reset();
                scannerContainer.classList.add('hidden');
                isScanning = false;
                startButtonText.innerHTML = `<svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 mr-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" /></svg>啟動相機`;
            }

            startButton.addEventListener('click', () => {
                if (isScanning) {
                    stopScan();
                } else {
                    startScan();
                }
            });

            form.addEventListener('submit', function() {
                setTimeout(() => {
                    if ('<?php echo $message_type; ?>' === 'success') {
                         scannedValueTextarea.value = '';
                         statusText.textContent = '已處理完成，可以開始下一次掃描。';
                    }
                }, 500);
            });

            if (window.location.protocol !== "https:" && window.location.hostname !== "localhost") {
                statusText.innerHTML = "<b>警告：</b>此功能需要在 HTTPS 安全連線下才能啟動相機。";
                statusText.classList.add('text-yellow-600');
                startButton.disabled = true;
            }
        });
    </script>
</body>
</html>