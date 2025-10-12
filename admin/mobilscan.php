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
    <style>
        body { font-family: 'Noto Sans TC', sans-serif; -webkit-tap-highlight-color: transparent; }
        #scanner-container { position: relative; overflow: hidden; border-radius: 0.5rem; }
        #video { width: 100%; height: auto; transform: scaleX(-1); }
        .laser {
            position: absolute;
            top: 50%;
            left: 0;
            right: 0;
            height: 2px;
            background: red;
            box-shadow: 0 0 10px red;
            animation: scanning 2s infinite linear;
        }
        @keyframes scanning {
            0% { top: 10%; }
            50% { top: 90%; }
            100% { top: 10%; }
        }
        .form-input { @apply block w-full px-4 py-3 bg-gray-100 border-2 border-gray-200 rounded-lg text-lg focus:outline-none focus:bg-white focus:border-blue-500; }
        .btn { @apply w-full px-4 py-4 text-lg font-bold text-white rounded-lg transition-colors duration-300; }
    </style>
</head>
<body class="bg-gray-50">
    <div class="w-full max-w-md mx-auto p-6 space-y-6">
        <h1 class="text-3xl font-bold text-center text-gray-800">手機掃碼作業</h1>

        <?php if ($message): ?>
            <div id="message-box" class="p-4 rounded-lg <?php echo ($message_type === 'success') ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'; ?>">
                <p><?php echo htmlspecialchars($message); ?></p>
            </div>
        <?php endif; ?>

        <!-- 掃描器介面 -->
        <div id="scanner-ui" class="space-y-4">
            <button id="startButton" class="btn bg-blue-600 hover:bg-blue-700">開始掃描</button>
            <div id="scanner-container" class="hidden aspect-video bg-black">
                <video id="video"></video>
                <div class="laser"></div>
            </div>
            <p id="status-text" class="text-center text-gray-500"></p>
        </div>

        <!-- 表單 -->
        <form id="main-form" action="mobilscan.php" method="POST" class="space-y-6">
            <div>
                <label for="scanned_value" class="block text-sm font-medium text-gray-700 mb-2">掃描結果 / 手動輸入</label>
                <textarea name="scanned_value" id="scanned_value" rows="3" class="form-input" placeholder="請掃描或手動輸入分號..." required><?php echo htmlspecialchars($scanned_value_prefill); ?></textarea>
            </div>
            
            <div>
                <label for="action_type" class="block text-sm font-medium text-gray-700 mb-2">處理方式</label>
                <select name="action_type" id="action_type" class="form-input" required>
                    <option value="" disabled selected>-- 請選擇 --</option>
                    <option value="order_screenshot">提供訂單截圖</option>
                    <option value="formal_declaration">轉正報</option>
                    <option value="missing_package">漏件</option>
                    <option value="other">其他</option>
                </select>
            </div>

            <div>
                <label for="manual_remark" class="block text-sm font-medium text-gray-700 mb-2">備註 (可選填)</label>
                <input type="text" name="manual_remark" id="manual_remark" class="form-input" placeholder="請輸入額外說明...">
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
            const scannerContainer = document.getElementById('scanner-container');
            const videoElement = document.getElementById('video');
            const scannedValueTextarea = document.getElementById('scanned_value');
            const statusText = document.getElementById('status-text');
            const messageBox = document.getElementById('message-box');
            let selectedDeviceId;

            // 提示 HTTPS
            if (window.location.protocol !== "https:" && window.location.hostname !== "localhost") {
                statusText.textContent = "警告：此功能需要在 HTTPS 安全連線下才能啟動相機。";
                statusText.classList.add('text-red-600');
                startButton.disabled = true;
            }

            // 點擊開始掃描
            startButton.addEventListener('click', () => {
                if(messageBox) messageBox.style.display = 'none';
                statusText.textContent = "正在請求相機權限...";
                scannerContainer.classList.remove('hidden');
                
                codeReader.listVideoInputDevices()
                    .then((videoInputDevices) => {
                        // 優先選擇後置鏡頭
                        const rearCamera = videoInputDevices.find(device => device.label.toLowerCase().includes('back') || device.label.toLowerCase().includes('後'));
                        selectedDeviceId = rearCamera ? rearCamera.deviceId : videoInputDevices[0].deviceId;

                        statusText.textContent = "請將條碼對準掃描框...";
                        
                        codeReader.decodeFromVideoDevice(selectedDeviceId, 'video', (result, err) => {
                            if (result) {
                                // 掃描成功
                                scannedValueTextarea.value = result.text;
                                statusText.textContent = `掃描成功: ${result.text}`;
                                
                                // 播放提示音
                                const audio = new Audio('data:audio/wav;base64,UklGRl9vT19XQVZFZm10IBAAAAABAAEAQB8AAEAfAAABAAgAZGF0YU'+Array(300).join('123'));
                                audio.play();

                                // 停止掃描並隱藏相機
                                codeReader.reset();
                                scannerContainer.classList.add('hidden');
                                startButton.textContent = "重新掃描";
                            }
                            if (err && !(err instanceof ZXing.NotFoundException)) {
                                console.error(err);
                                statusText.textContent = `掃描出錯: ${err.message}`;
                            }
                        });
                    })
                    .catch((err) => {
                        console.error(err);
                        statusText.textContent = `無法啟動相機: ${err.message}`;
                        if(err.name === "NotAllowedError") {
                           statusText.textContent = "您已拒絕相機權限，請至瀏覽器設定中手動開啟。";
                        }
                    });
            });
        });
    </script>
</body>
</html>

