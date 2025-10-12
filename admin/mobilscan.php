<?php
// 檔案: mobilscan.php
// 說明: 提供手機掃描分號並快速標記異常件的功能，整合了 ZXing 條碼掃描器。

session_start();
// 引用資料庫設定檔
require_once 'config.php';

// 檢查使用者是否登入，否則導向到登入頁面
if (!isset($_SESSION['user_id'])) {
    // 為了在新視窗中能正確導向，使用 JavaScript
    echo "<script>window.location.href = 'login.php';</script>";
    exit();
}

$user_full_name = $_SESSION['user_full_name'] ?? '未知使用者';

$message = '';
$message_type = '';
$scanned_value_prefill = '';

// --- 處理表單提交 ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['scanned_value'])) {
    $scanned_value = trim($_POST['scanned_value']);
    $action_type = $_POST['action_type'] ?? '';
    $manual_remark = trim($_POST['manual_remark'] ?? '');

    if (empty($scanned_value) || empty($action_type)) {
        $message = '錯誤：掃描內容和處理方式不能為空。';
        $message_type = 'error';
    } else {
        try {
            $today = date('Y-m-d');
            $status0 = 0;
            $auto_remark = '';

            // 根據選項設定 status0 和自動產生的備註
            switch ($action_type) {
                case 'order_screenshot':
                    $status0 = 2;
                    $auto_remark = "{$today} 要提供訂單截圖";
                    break;
                case 'formal_declaration':
                    $status0 = 1;
                    $auto_remark = "{$today} 轉正報";
                    break;
                case 'missing_package':
                    $status0 = 8; // 新的狀態碼：漏件
                    $auto_remark = "{$today} 漏件";
                    break;
                case 'other':
                    $status0 = 7;
                    $auto_remark = $today;
                    break;
            }

            // 合併自動備註與手動備註
            $final_remark = $auto_remark;
            if (!empty($manual_remark)) {
                $final_remark .= (empty($final_remark) ? '' : '； ') . $manual_remark;
            }

            $pdo->beginTransaction();

            $stmt_check = $pdo->prepare("SELECT id FROM daily_outbound WHERE house_no = ?");
            $stmt_check->execute([$scanned_value]);
            $existing_record = $stmt_check->fetch();

            if ($existing_record) {
                $stmt_update = $pdo->prepare(
                    "UPDATE daily_outbound SET status0 = ?, remark = ?, customer_name = ? WHERE house_no = ?"
                );
                $stmt_update->execute([$status0, $final_remark, $user_full_name, $scanned_value]);
                $message = "分號 [{$scanned_value}] 的狀態已成功更新！";
                $message_type = 'success';
            } else {
                $stmt_insert = $pdo->prepare(
                    "INSERT INTO daily_outbound (house_no, status0, remark, customer_name) VALUES (?, ?, ?, ?)"
                );
                $stmt_insert->execute([$scanned_value, $status0, $final_remark, $user_full_name]);
                $message = "分號 [{$scanned_value}] 不存在，已為您新增此筆異常記錄！";
                $message_type = 'success';
            }

            $pdo->commit();

        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $message = "操作失敗：" . $e->getMessage();
            $message_type = 'error';
        }
        $scanned_value_prefill = $scanned_value;
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
        /* 【*** 全新 UI 設計 ***】 */
        .scanner-view {
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(0,0,0,0.8);
            display: flex;
            align-items: center;
            justify-content: center;
            flex-direction: column;
            opacity: 0;
            visibility: hidden;
            transition: opacity 0.3s, visibility 0.3s;
            z-index: 50;
        }
        .scanner-view.active { opacity: 1; visibility: visible; }
        #video-container {
            position: relative;
            width: 90%;
            max-width: 600px;
            padding-top: 50.625%; /* 16:9 Aspect Ratio */
            background: #222;
            border-radius: 1rem;
            overflow: hidden;
            box-shadow: 0 0 0 5px rgba(255,255,255,0.1);
        }
        #video {
            position: absolute;
            top: 0; left: 0;
            width: 100%; height: 100%;
            object-fit: cover;
            transform: scaleX(-1);
        }
        .laser {
            position: absolute; top: 50%; left: 10%; right: 10%;
            height: 3px; background: #ef4444; box-shadow: 0 0 15px #ef4444;
            animation: scanning 2s infinite linear;
        }
        @keyframes scanning { 0% { top: 15%; } 50% { top: 85%; } 100% { top: 15%; } }
        .form-input { 
            @apply block w-full px-4 py-3 bg-gray-700 border-2 border-gray-600 rounded-lg text-lg text-white placeholder-gray-400 focus:outline-none focus:bg-gray-600 focus:border-purple-500 transition-colors;
        }
        .btn { 
            @apply w-full px-4 py-4 text-xl font-bold text-white rounded-lg shadow-lg transform active:scale-95 transition-transform duration-200;
        }
    </style>
</head>
<body class="bg-gray-900 text-white">

    <!-- 主內容 -->
    <div class="w-full max-w-md mx-auto p-6 space-y-8 min-h-screen flex flex-col justify-center">
        <div>
            <h1 class="text-4xl font-bold text-center mb-2">手機掃碼</h1>
            <p class="text-center text-gray-400">掃描分號條碼以快速標記異常件</p>
        </div>

        <?php if ($message): ?>
            <div id="message-box" class="p-4 rounded-lg text-center font-semibold <?php echo ($message_type === 'success') ? 'bg-green-500/20 text-green-300' : 'bg-red-500/20 text-red-300'; ?>">
                <p><?php echo htmlspecialchars($message); ?></p>
            </div>
        <?php endif; ?>

        <!-- 表單 -->
        <form id="main-form" action="mobilscan.php" method="POST" class="space-y-6">
            <div>
                <label for="scanned_value" class="block text-base font-medium text-gray-400 mb-2">分號 (House No.)</label>
                <textarea name="scanned_value" id="scanned_value" rows="3" class="form-input" placeholder="點擊下方按鈕掃描或手動輸入..." required><?php echo htmlspecialchars($scanned_value_prefill); ?></textarea>
            </div>
            
            <div>
                <label for="action_type" class="block text-base font-medium text-gray-400 mb-2">處理方式</label>
                <select name="action_type" id="action_type" class="form-input" required>
                    <option value="" disabled selected>-- 請選擇異常類型 --</option>
                    <option value="order_screenshot">提供訂單截圖</option>
                    <option value="formal_declaration">轉正報</option>
                    <option value="missing_package">漏件</option>
                    <option value="other">其他</option>
                </select>
            </div>

            <div>
                <label for="manual_remark" class="block text-base font-medium text-gray-400 mb-2">備註 (可選填)</label>
                <input type="text" name="manual_remark" id="manual_remark" class="form-input" placeholder="可輸入額外說明..." maxlength="40">
            </div>

            <div class="pt-4">
                <button type="submit" class="btn bg-gradient-to-r from-green-500 to-emerald-600 hover:from-green-600 hover:to-emerald-700">執行處理</button>
            </div>
        </form>
    </div>

    <!-- 掃描懸浮按鈕 -->
    <button id="startButton" class="fixed bottom-8 right-8 w-20 h-20 bg-gradient-to-r from-purple-600 to-indigo-700 rounded-full shadow-2xl flex items-center justify-center text-white transform hover:scale-110 active:scale-100 transition-transform duration-200 animate-pulse">
        <svg xmlns="http://www.w3.org/2000/svg" class="h-10 w-10" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" /></svg>
    </button>
    
    <!-- 掃描器畫面 (預設隱藏) -->
    <div id="scanner-view" class="scanner-view">
        <div id="video-container">
            <video id="video" playsinline></video>
            <div class="laser"></div>
        </div>
        <p id="status-text" class="mt-4 text-center font-medium"></p>
        <button id="closeButton" class="mt-6 px-6 py-2 bg-gray-700 rounded-full hover:bg-gray-600 transition-colors">關閉</button>
    </div>

    <!-- 引入 ZXing Library -->
    <script type="text/javascript" src="https://unpkg.com/@zxing/library@latest/umd/index.min.js"></script>
    <script type="text/javascript">
        window.addEventListener('load', function () {
            const codeReader = new ZXing.BrowserMultiFormatReader();
            const startButton = document.getElementById('startButton');
            const closeButton = document.getElementById('closeButton');
            const scannerView = document.getElementById('scanner-view');
            const scannedValueTextarea = document.getElementById('scanned_value');
            const statusText = document.getElementById('status-text');
            const messageBox = document.getElementById('message-box');
            let selectedDeviceId;

            function startScan() {
                if(messageBox) messageBox.style.display = 'none';
                statusText.textContent = "正在請求相機權限...";
                scannerView.classList.add('active');
                
                codeReader.listVideoInputDevices()
                    .then((videoInputDevices) => {
                        if (videoInputDevices.length === 0) {
                            throw new Error("找不到任何攝影機裝置。");
                        }
                        const rearCamera = videoInputDevices.find(device => device.label.toLowerCase().includes('back') || device.label.toLowerCase().includes('後'));
                        selectedDeviceId = rearCamera ? rearCamera.deviceId : videoInputDevices[0].deviceId;

                        statusText.textContent = "請將條碼對準掃描框中央...";
                        
                        codeReader.decodeFromVideoDevice(selectedDeviceId, 'video', (result, err) => {
                            if (result) {
                                scannedValueTextarea.value = result.text;
                                statusText.innerHTML = `<span class="font-bold text-green-400">掃描成功!</span>`;
                                const audioContext = new (window.AudioContext || window.webkitAudioContext)();
                                const oscillator = audioContext.createOscillator();
                                oscillator.connect(audioContext.destination);
                                oscillator.type = 'sine';
                                oscillator.frequency.setValueAtTime(900, audioContext.currentTime);
                                oscillator.start();
                                oscillator.stop(audioContext.currentTime + 0.1);
                                stopScan();
                            }
                            if (err && !(err instanceof ZXing.NotFoundException)) {
                                console.error(err);
                                statusText.innerHTML = `<b class="text-red-400">掃描出錯:</b> ${err.message}`;
                            }
                        });
                    })
                    .catch((err) => {
                        console.error(err);
                        statusText.innerHTML = `<b class="text-red-400">無法啟動相機:</b> ${err.message}`;
                        if(err.name === "NotAllowedError") {
                           statusText.innerHTML = "<b>您已拒絕相機權限</b>，請至瀏覽器設定中手動開啟。";
                        }
                    });
            }

            function stopScan() {
                codeReader.reset();
                scannerView.classList.remove('active');
            }

            startButton.addEventListener('click', startScan);
            closeButton.addEventListener('click', stopScan);

            // 提示 HTTPS
            if (window.location.protocol !== "https:" && window.location.hostname !== "localhost") {
                statusText.innerHTML = "<b>警告：</b>此功能需要在 HTTPS 安全連線下才能啟動相機。";
                statusText.classList.add('text-yellow-400');
                startButton.disabled = true;
            }
        });
    </script>
</body>
</html>

