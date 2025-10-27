<?php
// 啟動 session
session_start();

// --- 檢查使用者是否已登入的邏輯 ---
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header('Location: login.php');
    exit();
}

// --- 資料庫連線設定 (使用 config.php 的 $pdo) ---
require_once 'config.php';
if (!isset($pdo)) {
    die("系統錯誤：無法連線到資料庫。");
}

// --- 多筆查詢與 CSV 下載邏輯 ---
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['multi_query'])) {

    $house_nos_raw = trim($_POST['house_nos_multi']);
    if (empty($house_nos_raw)) {
        // 如果為空，可以選擇返回或顯示錯誤，這裡選擇不執行任何操作
        // header('Location: search.php?error=multinone'); // 可選：重定向並帶錯誤碼
        // exit();
        // 或者直接結束，讓頁面正常顯示
    } else {
        $house_nos_input = preg_split('/\\r\\n|\\r|\\n/', $house_nos_raw);
        $house_nos_input = array_filter(array_map('trim', $house_nos_input)); // 過濾空行並去除空白

        if (count($house_nos_input) > 50) {
            // die() 會直接中止，不太友好，可以考慮重定向或顯示錯誤訊息
            $_SESSION['search_error'] = "查詢筆數超過 50 筆上限，請重新操作。";
            header('Location: search.php');
            exit();
        }

        if (!empty($house_nos_input)) {
            try {
                // --- 【修改 #3】CSV 查詢邏輯調整 (此處為原註解，保留) ---
                
                // 1. 準備 SQL 查詢所有可能的資料
                $placeholders = implode(',', array_fill(0, count($house_nos_input), '?'));
                $sql = "SELECT master_no, house_no, storage_in_datetime, storage_out_datetime, status, remark
                        FROM daily_outbound
                        WHERE house_no IN ($placeholders)";
                $stmt = $pdo->prepare($sql);
                $stmt->execute($house_nos_input);
                $all_db_results = $stmt->fetchAll(PDO::FETCH_ASSOC); // 獲取所有符合的資料

                // --- 準備 CSV 輸出 ---
                header('Content-Type: text/csv; charset=utf-8');
                header('Content-Disposition: attachment; filename="通關狀態查詢結果_' . date('YmdHis') . '.csv"');
                $output = fopen('php://output', 'w');
                fputs($output, "\xEF\xBB\xBF"); // UTF-8 BOM

                fputcsv($output, ['主號', '分號', '進倉日期時間', '出倉日期時間', '狀態', '備註']);

                // --- 【核心修改】依照使用者輸入順序輸出 CSV ---

                // 1. 將所有資料庫結果映射到以 house_no 為 key 的陣列
                //    value 是一個陣列，因為一個分號可能對應多筆資料
                $results_map = [];
                foreach ($all_db_results as $row) {
                    $house_no = $row['house_no'];
                    if (!isset($results_map[$house_no])) {
                        $results_map[$house_no] = [];
                    }
                    $results_map[$house_no][] = $row;
                }

                // 2. 遍歷使用者輸入的列表 ($house_nos_input)，確保輸出順序
                foreach ($house_nos_input as $input_house_no) {
                    // 檢查是否在映射中有找到資料
                    if (isset($results_map[$input_house_no])) {
                        // 找到了，遍歷該分號的所有資料並寫入
                        foreach ($results_map[$input_house_no] as $row) {
                            fputcsv($output, [
                                $row['master_no'],
                                $row['house_no'],
                                $row['storage_in_datetime'],
                                $row['storage_out_datetime'],
                                $row['status'],
                                $row['remark']
                            ]);
                        }
                    } else {
                        // 沒找到，寫入 "未進倉" 記錄
                        fputcsv($output, ['', $input_house_no, '', '', '未進倉', '']);
                    }
                }
                // --- CSV 邏輯修改結束 ---

                fclose($output);
                // $stmt->close(); // PDOStatement 不需 close
                // $conn->close(); // PDO 不需要手動 close
                exit();

            } catch (PDOException $e) {
                 // 記錄錯誤，並提示使用者
                 error_log("Multi-query CSV export failed: " . $e->getMessage());
                 $_SESSION['search_error'] = "匯出 CSV 時發生資料庫錯誤，請稍後再試。";
                 header('Location: search.php');
                 exit();
            }
        } else {
             $_SESSION['search_error'] = "請輸入至少一個有效的分號進行多筆查詢。";
             header('Location: search.php');
             exit();
        }
    } // end else empty house_nos_raw
} // end multi_query POST

// --- 處理 Session 中的錯誤訊息 ---
$error_message = '';
if (isset($_SESSION['search_error'])) {
    $error_message = $_SESSION['search_error'];
    unset($_SESSION['search_error']); // 顯示後清除
}

// --- 單筆查詢的變數初始化 ---
$single_results = []; // 【修改 #2】改為陣列以儲存多筆結果
$house_no_single = '';
// 【修改 #1】預設勾選進階視圖
// 如果是 POST 請求，則根據表單提交的值；否則預設為 true
$advanced_view = $_SERVER["REQUEST_METHOD"] == "POST" ? isset($_POST['advanced_view']) : true;


// --- 單筆查詢邏輯 ---
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['single_query'])) {
    $house_no_single = trim($_POST['house_no_single']);
    // $advanced_view 已在上面根據 POST 值設定

    if (!empty($house_no_single)) {
        try {
            // --- 【修改 #2】查詢邏輯調整 ---
            // 統一查詢所有可能需要的欄位，無論是否勾選進階，後續再決定顯示哪些
            $sql = "SELECT master_no, house_no, declaration_no, weight, total_packages, packages_in, packages_out, clearance_method, storage_in_datetime, storage_out_datetime, status, remark
                    FROM daily_outbound
                    WHERE house_no = ?";

            $stmt = $pdo->prepare($sql);
            $stmt->execute([$house_no_single]);
            $single_results = $stmt->fetchAll(PDO::FETCH_ASSOC); // 獲取所有符合的紀錄

            if (count($single_results) === 0) {
                $error_message = "找不到符合分號 '" . htmlspecialchars($house_no_single) . "' 的資料，若是我司申報,目前尚未通關,請再耐心等候。";
            }
            // 不需要特別處理 count > 1 的情況，直接在 HTML 中迴圈顯示即可
            // --- 查詢邏輯調整結束 ---

        } catch (PDOException $e) {
            $error_message = "資料庫查詢失敗: " . $e->getMessage();
            error_log("Single query failed: " . $e->getMessage()); // 記錄詳細錯誤
        }
    } else {
        $error_message = "請輸入要查詢的分號。";
    }
}
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>高雄海快通關狀態查詢</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', 'Noto Sans TC', sans-serif; }
        .result-grid { display: grid; grid-template-columns: 150px 1fr; gap: 0.75rem; }
        .result-grid dt { font-weight: 600; color: #4A5568; text-align: right; padding-right: 1rem; }
        .result-grid dd { color: #1A202C; }
        /* 為多筆結果增加分隔線 */
        .result-item:not(:last-child) { border-bottom: 1px dashed #e2e8f0; padding-bottom: 1rem; margin-bottom: 1rem; }
    </style>
</head>
<body class="bg-gray-100">

    <div class="container mx-auto p-4 md:p-8 max-w-4xl">
        <header class="text-center mb-10">
            <h1 class="text-3xl md:text-4xl font-bold text-blue-900">
                高雄海快通關狀態查詢
            </h1>
        </header>

        <?php // 在頂部顯示 Session 錯誤訊息 (來自 CSV 匯出失敗等)
        if ($error_message && $_SERVER["REQUEST_METHOD"] != "POST"): ?>
            <div class="mb-6 p-4 rounded-lg bg-red-100 text-red-800">
                <?php echo htmlspecialchars($error_message); ?>
            </div>
        <?php endif; ?>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-8">

            <div class="bg-white rounded-xl shadow-lg p-6 md:p-8">
                <h2 class="text-2xl font-bold text-gray-800 mb-6 border-b pb-3">單筆查詢</h2>
                <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post" class="space-y-6">
                    <div>
                        <label for="house_no_single" class="block text-sm font-medium text-gray-700 mb-1">輸入分號 (House No.)</label>
                        <input type="text" name="house_no_single" id="house_no_single" value="<?php echo htmlspecialchars($house_no_single); ?>" class="mt-1 block w-full px-3 py-2 bg-white border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500">
                    </div>
                    <div class="flex items-center">
                        <input type="checkbox" name="advanced_view" id="advanced_view" class="h-4 w-4 text-indigo-600 focus:ring-indigo-500 border-gray-300 rounded" <?php if ($advanced_view) echo 'checked'; ?>>
                        <label for="advanced_view" class="ml-2 block text-sm text-gray-900">顯示進階資訊</label>
                    </div>
                    <div>
                        <button type="submit" name="single_query" class="w-full flex justify-center py-2 px-4 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500">
                            查詢
                        </button>
                    </div>
                </form>

                <?php // --- 【修改 #2】單筆查詢結果顯示邏輯調整 --- ?>
                <?php if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['single_query'])): // 只在執行單筆查詢後顯示結果或錯誤 ?>
                    <?php if (!empty($single_results)): ?>
                    <div class="mt-8 pt-6 border-t">
                        <h3 class="text-lg font-semibold text-gray-900 mb-4">
                            查詢結果 (分號: <?php echo htmlspecialchars($house_no_single); ?>)：
                            <?php if (count($single_results) > 1): ?>
                                <span class="text-red-600 font-normal">(找到 <?php echo count($single_results); ?> 筆不同主號的資料)</span>
                            <?php endif; ?>
                        </h3>
                        <?php foreach ($single_results as $index => $result_item): ?>
                        <div class="result-item">
                            <?php if (count($single_results) > 1): ?>
                                <h4 class="text-md font-semibold text-blue-800 mb-2">資料 <?php echo $index + 1; ?>:</h4>
                            <?php endif; ?>
                            <dl class="result-grid">
                                <dt>主號:</dt> <dd><?php echo htmlspecialchars($result_item['master_no'] ?? 'N/A'); ?></dd>
                                <dt>分號:</dt> <dd><?php echo htmlspecialchars($result_item['house_no'] ?? 'N/A'); ?></dd>
                                <?php if ($advanced_view): // 根據 $advanced_view 決定是否顯示進階欄位 ?>
                                <dt>報單號碼:</dt> <dd><?php echo htmlspecialchars($result_item['declaration_no'] ?? 'N/A'); ?></dd>
                                <dt>重量 (KG):</dt> <dd><?php echo htmlspecialchars($result_item['weight'] ?? 'N/A'); ?></dd>
                                <dt>總件數:</dt> <dd><?php echo htmlspecialchars($result_item['total_packages'] ?? 'N/A'); ?></dd>
                                <dt>已進倉件數:</dt> <dd><?php echo htmlspecialchars($result_item['packages_in'] ?? 'N/A'); ?></dd>
                                <dt>已出倉件數:</dt> <dd><?php echo htmlspecialchars($result_item['packages_out'] ?? 'N/A'); ?></dd>
                                <dt>通關方式:</dt> <dd><?php echo htmlspecialchars($result_item['clearance_method'] ?? 'N/A'); ?></dd>
                                <?php endif; ?>
                                <dt>進倉日期時間:</dt> <dd><?php echo htmlspecialchars($result_item['storage_in_datetime'] ?? 'N/A'); ?></dd>
                                <dt>出倉日期時間:</dt> <dd><?php echo htmlspecialchars($result_item['storage_out_datetime'] ?? 'N/A'); ?></dd>
                                <dt>狀態:</dt> <dd class="font-bold <?php echo (isset($result_item['status']) && $result_item['status'] == '已出倉') ? 'text-green-600' : 'text-red-600'; ?>"><?php echo htmlspecialchars($result_item['status'] ?? 'N/A'); ?></dd>
                                <?php if ($advanced_view): ?>
                                <dt>備註:</dt> <dd><?php echo nl2br(htmlspecialchars($result_item['remark'] ?? 'N/A')); // 使用 nl2br 處理換行 ?></dd>
                                <?php endif; ?>
                            </dl>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php elseif (!empty($error_message)): // 只有在查詢後且無結果時顯示錯誤 ?>
                    <div class="mt-8 pt-6 border-t">
                         <p class="text-red-600"><?php echo htmlspecialchars($error_message); ?></p>
                    </div>
                    <?php endif; ?>
                 <?php endif; // End if single_query POST ?>
                 <?php // --- 單筆查詢結果顯示邏輯調整結束 --- ?>
            </div>

            <div class="bg-white rounded-xl shadow-lg p-6 md:p-8">
                <h2 class="text-2xl font-bold text-gray-800 mb-6 border-b pb-3">多筆查詢 (下載CSV)</h2>
                <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post" class="space-y-6">
                    <div>
                        <label for="house_nos_multi" class="block text-sm font-medium text-gray-700 mb-1">輸入多筆分號 (每筆一行)</label>
                        <textarea name="house_nos_multi" id="house_nos_multi" rows="10" class="mt-1 block w-full px-3 py-2 bg-white border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500" placeholder="在此輸入分號，每行一個..."></textarea>
                        <p class="mt-2 text-sm text-gray-500">最多 50 筆資料。結果將會直接下載為 CSV 檔案。</p>
                        <p id="line-count-multi" class="mt-1 text-sm font-semibold text-gray-700">目前行數: 0</p> <?php // 修改 ID 避免衝突 ?>
                    </div>
                    <div>
                        <button type="submit" name="multi_query" class="w-full flex justify-center py-2 px-4 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-green-600 hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500">
                            查詢並下載 CSV
                        </button>
                    </div>
                </form>
            </div>

        </div>
    </div>

    <script>
        // 多筆查詢的行數計算
        const textareaMulti = document.getElementById('house_nos_multi');
        const lineCountDisplayMulti = document.getElementById('line-count-multi'); // 對應修改後的 ID
        const limitMulti = 50;

        textareaMulti.addEventListener('input', () => {
            const text = textareaMulti.value;
            // 計算行數時過濾空行
            const lines = text.split('\n').filter(line => line.trim() !== '');
            const count = lines.length;

            lineCountDisplayMulti.textContent = `目前行數: ${count}`;

            if (count > limitMulti) {
                lineCountDisplayMulti.classList.add('text-red-600');
                lineCountDisplayMulti.classList.remove('text-gray-700');
            } else {
                lineCountDisplayMulti.classList.remove('text-red-600');
                lineCountDisplayMulti.classList.add('text-gray-700');
            }
        });

        // 頁面載入時觸發一次多筆查詢的 input 事件
        document.addEventListener('DOMContentLoaded', () => {
             const eventMulti = new Event('input');
             textareaMulti.dispatchEvent(eventMulti);
        });
    </script>
</body>
</html>