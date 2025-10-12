<?php
// 檔案: mobilscan.php
// 說明: 提供手機掃描分號並快速標記異常件的功能。

session_start();
// 引用資料庫設定檔
require_once 'config.php';

// 檢查使用者是否登入，否則導向到登入頁面
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$user_full_name = $_SESSION['user_full_name'] ?? '未知使用者';

$message = '';
$message_type = '';

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

            // 檢查 house_no 是否已存在
            $stmt_check = $pdo->prepare("SELECT id FROM daily_outbound WHERE house_no = ?");
            $stmt_check->execute([$scanned_value]);
            $existing_record = $stmt_check->fetch();

            if ($existing_record) {
                // --- 更新現有資料 ---
                $stmt_update = $pdo->prepare(
                    "UPDATE daily_outbound SET status0 = ?, remark = ?, customer_name = ? WHERE house_no = ?"
                );
                $stmt_update->execute([$status0, $final_remark, $user_full_name, $scanned_value]);
                $message = "分號 [{$scanned_value}] 的狀態已成功更新！";
                $message_type = 'success';
            } else {
                // --- 新增一筆資料 ---
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
    }
}
?>

<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>手機掃碼作業</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        body { font-family: 'Noto Sans TC', sans-serif; }
        .form-input { @apply block w-full px-4 py-3 bg-gray-100 border-2 border-gray-200 rounded-lg text-lg focus:outline-none focus:bg-white focus:border-blue-500; }
        .btn { @apply w-full px-4 py-4 text-lg font-bold text-white rounded-lg transition-colors duration-300; }
        .btn-primary { @apply bg-blue-600 hover:bg-blue-700; }
    </style>
</head>
<body class="bg-gray-50">
    <div class="w-full max-w-md mx-auto p-6 md:p-8 mt-8">
        <h1 class="text-3xl font-bold text-center text-gray-800 mb-8">手機掃碼作業</h1>

        <?php if ($message): ?>
            <div class="mb-6 p-4 rounded-lg <?php echo ($message_type === 'success') ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'; ?>">
                <p><?php echo htmlspecialchars($message); ?></p>
            </div>
        <?php endif; ?>

        <form action="mobilscan.php" method="POST" class="space-y-6">
            <div>
                <label for="scanned_value" class="block text-sm font-medium text-gray-700 mb-2">掃描分號或手動輸入</label>
                <textarea name="scanned_value" id="scanned_value" rows="3" class="form-input" placeholder="請掃描條碼..." autofocus required></textarea>
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
                <button type="submit" class="btn btn-primary">執行處理</button>
            </div>
        </form>
    </div>
</body>
</html>

