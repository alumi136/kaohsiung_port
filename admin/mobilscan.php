<?php
// 檔案: mobilscan.php
// 說明: 提供手機掃描分號並快速標記異常件的功能，整合了 ZXing 條碼掃描器。

session_start();
require_once 'config.php';

if (!isset($_SESSION['user_id'])) {
    echo "<script>window.opener && window.opener.location.reload(); window.close();</script>";
    exit();
}

$user_full_name = $_SESSION['user_full_name'] ?? '未知使用者';
$message = '';
$message_type = '';
$scanned_values_prefill = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['scanned_values'])) {
    $scanned_values_raw = trim($_POST['scanned_values']);
    $action_type = $_POST['action_type'] ?? '';
    $manual_remark = trim($_POST['manual_remark'] ?? '');

    if (empty($scanned_values_raw) || empty($action_type)) {
        $message = '錯誤：掃描內容和處理方式不能為空。';
        $message_type = 'error';
    } else {
        // 將多行分號分割成陣列
        $scanned_values = array_filter(array_map('trim', explode("\n", $scanned_values_raw)));
        $processed_count = 0;

        try {
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

            $stmt_check = $pdo->prepare("SELECT id FROM daily_outbound WHERE house_no = ?");
            $stmt_update = $pdo->prepare("UPDATE daily_outbound SET status0 = ?, remark = ?, customer_name = ? WHERE house_no = ?");
            $stmt_insert = $pdo->prepare("INSERT INTO daily_outbound (house_no, status0, remark, customer_name) VALUES (?, ?, ?, ?)");

            foreach($scanned_values as $scanned_value) {
                $stmt_check->execute([$scanned_value]);
                $existing_record = $stmt_check->fetch();

                if ($existing_record) {
                    $stmt_update->execute([$status0, $final_remark, $user_full_name, $scanned_value]);
                } else {
                    $stmt_insert->execute([$scanned_value, $status0, $final_remark, $user_full_name]);
                }
                $processed_count++;
            }

            $pdo->commit();
            $message = "操作成功！共處理了 {$processed_count} 筆分號。";
            $message_type = 'success';

        } catch (PDOException $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            $message = "操作失敗：" . $e->getMessage();
            $message_type = 'error';
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
        #video { width: 100%; height: auto; } /* 移除 transform，讓影像正常顯示 */
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
            <div id="message-box" class="p-4 rounded-lg text-center font-semibold <?php echo ($message_type === 'success') ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700'; ?>">
                <p><?php echo htmlspecialchars($message); ?></p>
            </div>
        <?php endif; ?>

        <!-- 掃描器介面 -->
        <div id="scanner-ui" class="space-y-4">
            <div id="scanner-container" class="hidden aspect-video bg-black rounded-lg shadow-inner">
                <video id="video" playsinline></video>
                <div class="laser"></div>
            </div>
            <button id="startButton" class="btn bg-blue-600 hover:bg-blue-700">
                <span id="startButtonText" class="flex items-center justify-center">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 mr-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" /></svg>
                    啟動相機
                </span>
            </button>
            <p id="status-text" class="text-center text-gray-600 font-medium h-6"></p>
        </div>

        <!-- 表單 -->
        <form id="main-form" action="mobilscan.php" method="POST" class="space-y-6">
            <div>
                <label for="scanned_values" class="block text-base font-medium text-gray-700 mb-2">分號 (House No.)</label>
                <textarea name="scanned_values" id="scanned_values" rows="5" class="form-input" placeholder="掃描結果會顯示於此..." required><?php echo htmlspecialchars($scanned_values_prefill); ?></textarea>
            </div>
            
            <div>
                <label for="action_type" class="block text-base font-medium text-gray-700 mb-2">處理方式</label>
                <select name="action_type" id="action_type" class="form-input" required>
                    <option value="" disabled selected>-- 請選擇異常類型 --</option>
                    <option value="order_screenshot">提供訂單截圖</option>
                    <option value="formal_declaration">轉正報</option>
                    <option value="missing_package">漏件</option>
                    <option value="other">其他</option>
                </select>
            </div>

            <div>
                <label for="manual_remark" class="block text-base font-medium text-gray-700 mb-2">備註 (可選填)</label>
                <input type="text" name="manual_remark" id="manual_remark" class="form-input" placeholder="可輸入額外說明..." maxlength="40">
            </div>

            <div class="pt-4">
                <button type="submit" class="btn bg-green-600 hover:bg-green-700">執行處理</button>
            </div>
        </form>
    </div>

    <!-- 引入 ZXing Library -->
    <script type="text/javascript" src="https://unpkg.com/@zxing/library@latest/umd/index.min.js"></script>
    <script type="text/javascript">
        window.addEventListener('load', function () {
            const codeReader = new ZXing.BrowserMultiFormatReader();
            const startButton = document.getElementById('startButton');
            const startButtonText = document.getElementById('startButtonText');
            const scannerContainer = document.getElementById('scanner-container');
            const videoElement = document.getElementById('video');
            const scannedValuesTextarea = document.getElementById('scanned_values');
            const statusText = document.getElementById('status-text');
            const messageBox = document.getElementById('message-box');
            let isScanning = false;

            function startScan() {
                if(messageBox) messageBox.style.display = 'none';
                statusText.textContent = "正在請求相機權限...";
                scannerContainer.classList.remove('hidden');
                isScanning = true;
                startButtonText.innerHTML = `
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 mr-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
                    關閉相機
                `;
                
                codeReader.listVideoInputDevices()
                    .then((videoInputDevices) => {
                        if (videoInputDevices.length === 0) throw new Error("找不到任何攝影機裝置。");
                        const rearCamera = videoInputDevices.find(device => device.label.toLowerCase().includes('back') || device.label.toLowerCase().includes('後'));
                        const selectedDeviceId = rearCamera ? rearCamera.deviceId : videoInputDevices[0].deviceId;

                        statusText.textContent = "請將條碼對準掃描線...";
                        
                        codeReader.decodeFromVideoDevice(selectedDeviceId, 'video', (result, err) => {
                            if (result) {
                                // 【*** 核心邏輯修正：連續掃描 ***】
                                const existingText = scannedValuesTextarea.value;
                                scannedValuesTextarea.value = existingText + (existingText ? '\n' : '') + result.text;
                                
                                statusText.innerHTML = `<span class="font-bold text-blue-600">掃描成功 (${result.text})</span>`;
                                
                                const audioContext = new (window.AudioContext || window.webkitAudioContext)();
                                const oscillator = audioContext.createOscillator();
                                oscillator.connect(audioContext.destination);
                                oscillator.type = 'sine';
                                oscillator.frequency.setValueAtTime(900, audioContext.currentTime);
                                oscillator.start();
                                oscillator.stop(audioContext.currentTime + 0.1);

                                // 1.5秒後清除成功訊息，準備掃描下一筆
                                setTimeout(() => {
                                    if(isScanning) statusText.textContent = "請將條碼對準掃描線...";
                                }, 1500);
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
                statusText.textContent = "";
                startButtonText.innerHTML = `
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 mr-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" /></svg>
                    啟動相機
                `;
            }

            startButton.addEventListener('click', () => {
                if (isScanning) {
                    stopScan();
                } else {
                    startScan();
                }
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

