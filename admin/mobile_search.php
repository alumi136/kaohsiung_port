<?php
// 檔案: mobile_search.php
// 說明: 提供手機掃描分號(house_no)並快速查詢其在 daily_outbound 的狀態。

session_start();
require_once 'config.php';

// 檢查登入狀態
if (!isset($_SESSION['user_id'])) {
    echo "<script>window.opener && window.opener.location.reload(); window.close();</script>";
    exit();
}

$search_results = [];
$error_message = '';
$scanned_value = ''; // 用於回填表單

// 處理查詢請求
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['scanned_value'])) {
    $scanned_value = trim($_POST['scanned_value']);

    if (empty($scanned_value)) {
        $error_message = '錯誤：掃描內容不能為空。';
    } else {
        try {
            // 準備查詢 (使用 search.php 的單筆查詢邏輯)
            $sql = "SELECT status, master_no, house_no, total_packages, packages_in, packages_out, storage_in_datetime, storage_out_datetime
                    FROM daily_outbound
                    WHERE house_no = ?";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$scanned_value]);
            $search_results = $stmt->fetchAll(PDO::FETCH_ASSOC); // 獲取所有符合的紀錄 (需求 #2)

            if (count($search_results) === 0) {
                $error_message = "找不到符合分號 '" . htmlspecialchars($scanned_value) . "' 的資料。";
            }

        } catch (PDOException $e) {
            $error_message = "資料庫查詢失敗: " . $e->getMessage();
            error_log("Mobile search failed: " . $e->getMessage());
        }
    }
}
?>

<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>手機查詢已申未進</title>
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
        /* 查詢結果的樣式 (仿 search.php) */
        .result-grid { display: grid; grid-template-columns: 120px 1fr; gap: 0.5rem; }
        .result-grid dt { font-weight: 600; color: #4A5568; text-align: right; padding-right: 0.5rem; }
        .result-grid dd { color: #1A202C; word-break: break-all; }
        .result-item:not(:last-child) { border-bottom: 1px dashed #e2e8f0; padding-bottom: 1rem; margin-bottom: 1rem; }
    </style>
</head>
<body class="bg-white text-black">

    <div class="w-full max-w-md mx-auto p-6 space-y-6">
        <h1 class="text-3xl font-bold text-center text-gray-800 mt-4">手機查詢作業</h1>

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

        <form id="main-form" action="mobile_search.php" method="POST" class="space-y-6">
            <div>
                <label for="scanned_value" class="block text-base font-medium text-gray-700 mb-2">分號 (House No.)</label>
                <textarea name="scanned_value" id="scanned_value" rows="3" class="form-input" placeholder="掃描結果會顯示於此..." required><?php echo htmlspecialchars($scanned_value); ?></textarea>
            </div>
            
            <div class="pt-4">
                <button type="submit" name="search_submit" class="btn bg-green-600 hover:bg-green-700">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 mr-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path></svg>
                    執行查詢
                </button>
            </div>
        </form>

        <div class="mt-8 pt-6 border-t">
            <?php if (!empty($search_results)): ?>
                <h3 class="text-xl font-semibold text-gray-900 mb-4">
                    查詢結果 (分號: <?php echo htmlspecialchars($scanned_value); ?>)
                    <?php if (count($search_results) > 1): ?>
                        <span class="text-red-600 font-normal text-base">(找到 <?php echo count($search_results); ?> 筆不同主號的資料)</span>
                    <?php endif; ?>
                </h3>
                
                <?php foreach ($search_results as $index => $row): ?>
                <div class="result-item p-4 bg-gray-50 rounded-lg">
                    <?php if (count($search_results) > 1): ?>
                        <h4 class="text-md font-semibold text-blue-800 mb-2">資料 <?php echo $index + 1; ?>:</h4>
                    <?php endif; ?>
                    
                    <dl class="result-grid text-sm">
                        <dt>狀態:</dt> 
                        <dd class="font-bold <?php echo (isset($row['status']) && $row['status'] == '已通關出倉') ? 'text-green-600' : 'text-red-600'; ?>">
                            <?php echo htmlspecialchars($row['status'] ?? 'N/A'); ?>
                        </dd>
                        
                        <dt>主號:</dt> <dd><?php echo htmlspecialchars($row['master_no'] ?? 'N/A'); ?></dd>
                        <dt>分號:</dt> <dd><?php echo htmlspecialchars($row['house_no'] ?? 'N/A'); ?></dd>
                        <dt>總件數:</dt> <dd><?php echo htmlspecialchars($row['total_packages'] ?? 'N/A'); ?></dd>
                        <dt>已進倉件數:</dt> <dd><?php echo htmlspecialchars($row['packages_in'] ?? 'N/A'); ?></dd>
                        <dt>已出倉件數:</dt> <dd><?php echo htmlspecialchars($row['packages_out'] ?? 'N/A'); ?></dd>
                        <dt>進倉日期時間:</dt> <dd><?php echo htmlspecialchars($row['storage_in_datetime'] ?? 'N/A'); ?></dd>
                        <dt>出倉日期時間:</dt> <dd><?php echo htmlspecialchars($row['storage_out_datetime'] ?? 'N/A'); ?></dd>
                    </dl>
                </div>
                <?php endforeach; ?>

            <?php elseif (!empty($error_message)): ?>
                <div id="message-box" class="p-4 rounded-lg text-center font-semibold bg-red-100 text-red-700">
                    <p><?php echo htmlspecialchars($error_message); ?></p>
                </div>
            <?php else: ?>
                <p class="text-center text-gray-500">請掃描分號條碼以進行查詢。</p>
            <?php endif; ?>
        </div>

    </div>

    <script type="text/javascript" src="https://unpkg.com/@zxing/library@latest/umd/index.min.js"></script>
    <script type="text/javascript">
        window.addEventListener('load', function () {
            const codeReader = new ZXing.BrowserMultiFormatReader();
            const startButton = document.getElementById('startButton');
            const startButtonText = document.getElementById('startButtonText');
            const scannerContainer = document.getElementById('scanner-container');
            const scannedValueTextarea = document.getElementById('scanned_value');
            const statusText = document.getElementById('status-text');
            const messageBox = document.getElementById('message-box');
            let isScanning = false;

            function startScan() {
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
                                // 掃描成功後立刻關閉相機
                                stopScan(); 
                                
                                scannedValueTextarea.value = result.text;
                                
                                statusText.innerHTML = `<span class="font-bold text-blue-600">掃描成功 (${result.text})</span>`;
                                
                                // 播放提示音
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
            
            // 檢查 HTTPS (同 mobilscan.php)
            if (window.location.protocol !== "https:" && window.location.hostname !== "localhost") {
                statusText.innerHTML = "<b>警告：</b>此功能需要在 HTTPS 安全連線下才能啟動相機。";
                statusText.classList.add('text-yellow-600');
                startButton.disabled = true;
            }
        });
    </script>
</body>
</html>