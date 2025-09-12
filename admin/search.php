<?php
// 啟動 session
session_start();

// --- 檢查使用者是否已登入的邏輯 ---
// 如果 Session 中沒有 'loggedin' 變數，或者 'loggedin' 不為 true
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    // 將使用者導向到登入頁面
    header('Location: login.php');
    exit(); // 確保重定向後停止執行
}

//<?php
// --- 資料庫連線設定 ---
$servername = "localhost";
$username = "alumi136";
$password = "Alumi!36";
$dbname = "kaohsiung_port_db";

// --- 多筆查詢與 CSV 下載邏輯 ---
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['multi_query'])) {
    
    $house_nos_raw = trim($_POST['house_nos_multi']);
    if (empty($house_nos_raw)) {
        return;
    }

    $house_nos = preg_split('/\\r\\n|\\r|\\n/', $house_nos_raw);
    $house_nos = array_filter(array_map('trim', $house_nos));

    if (count($house_nos) > 50) {
        die("查詢筆數超過 50 筆上限，請重新操作。");
    }

    if (!empty($house_nos)) {
        $conn = new mysqli($servername, $username, $password, $dbname);
        if ($conn->connect_error) {
            die("資料庫連線失敗: " . $conn->connect_error);
        }
        $conn->set_charset("utf8mb4");

        $placeholders = implode(',', array_fill(0, count($house_nos), '?'));
        $types = str_repeat('s', count($house_nos));
        
        $sql = "SELECT master_no, house_no, storage_in_datetime, storage_out_datetime, status, remark FROM daily_outbound WHERE house_no IN ($placeholders)";
        
        $stmt = $conn->prepare($sql);
        if ($stmt === false) {
             die("資料庫查詢準備失敗: " . $conn->error);
        }
        $stmt->bind_param($types, ...$house_nos);
        $stmt->execute();
        $result = $stmt->get_result();

        $db_results_map = [];
        while ($row = $result->fetch_assoc()) {
            $db_results_map[$row['house_no']] = $row;
        }

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="通關狀態查詢結果.csv"');

        $output = fopen('php://output', 'w');
        
        fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

        fputcsv($output, ['主號', '分號', '進倉日期時間', '出倉日期時間', '狀態','備註']);

        foreach ($house_nos as $house_no) {
            $current_house_no = trim($house_no);
            if (empty($current_house_no)) {
                continue;
            }
            if (isset($db_results_map[$current_house_no])) {
                $row = $db_results_map[$current_house_no];
                fputcsv($output, [
                    $row['master_no'],
                    $row['house_no'],
                    $row['storage_in_datetime'],
                    $row['storage_out_datetime'],
		    $row['status'],
		    $row['remark']
                ]);
            } else {
                fputcsv($output, ['', $current_house_no, '', '', '未進倉','']);
            }
        }

        fclose($output);
        $stmt->close();
        $conn->close();
        exit();
    }
}

// --- 單筆查詢的變數初始化 ---
$single_result = null;
$error_message = '';
$house_no_single = '';
$advanced_view = false;

// --- 單筆查詢邏輯 ---
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['single_query'])) {
    $house_no_single = trim($_POST['house_no_single']);
    $advanced_view = isset($_POST['advanced_view']);

    if (!empty($house_no_single)) {
        $conn = new mysqli($servername, $username, $password, $dbname);
        if ($conn->connect_error) {
            $error_message = "資料庫連線失敗: " . $conn->connect_error;
        } else {
            $conn->set_charset("utf8mb4");

            // 根據是否勾選進階來決定查詢的欄位
            if ($advanced_view) {
                // **【修改點 2】將 SQL 中的 renark 改為 remark **
                $sql = "SELECT master_no, house_no, declaration_no, weight, total_packages, packages_in, packages_out, clearance_method, storage_in_datetime, storage_out_datetime, status, remark FROM daily_outbound WHERE house_no = ?";
            } else {
                $sql = "SELECT master_no, house_no, storage_in_datetime, storage_out_datetime, status FROM daily_outbound WHERE house_no = ?";
            }

            $stmt = $conn->prepare($sql);
            
            if ($stmt === false) {
                 $error_message = "資料庫查詢準備失敗，請檢查欄位名稱是否正確。";
            } else {
                $stmt->bind_param("s", $house_no_single);
                $stmt->execute();
                $result = $stmt->get_result();
                
                if ($result->num_rows > 0) {
                    $single_result = $result->fetch_assoc();
                } else {
                    $error_message = "找不到符合分號 '" . htmlspecialchars($house_no_single) . "' 的資料，若是我司申報,目前尚未通關,請再耐心等候。";
                }
                $stmt->close();
            }
            $conn->close();
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
        .result-grid {
            display: grid;
            grid-template-columns: 150px 1fr;
            gap: 0.75rem;
        }
        .result-grid dt {
            font-weight: 600;
            color: #4A5568; /* text-gray-700 */
            text-align: right;
            padding-right: 1rem;
        }
        .result-grid dd {
            color: #1A202C; /* text-gray-900 */
        }
    </style>
</head>
<body class="bg-gray-100">

    <div class="container mx-auto p-4 md:p-8 max-w-4xl">
        <header class="text-center mb-10">
            <h1 class="text-3xl md:text-4xl font-bold text-blue-900">
                高雄海快通關狀態查詢
            </h1>
        </header>

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

                <?php if ($single_result): ?>
                <div class="mt-8 pt-6 border-t">
                    <h3 class="text-lg font-semibold text-gray-900 mb-4">查詢結果:</h3>
                    <dl class="result-grid">
                        <dt>主號:</dt> <dd><?php echo htmlspecialchars($single_result['master_no'] ?? 'N/A'); ?></dd>
                        <dt>分號:</dt> <dd><?php echo htmlspecialchars($single_result['house_no'] ?? 'N/A'); ?></dd>
                        <?php if ($advanced_view): ?>
                        <dt>報單號碼:</dt> <dd><?php echo htmlspecialchars($single_result['declaration_no'] ?? 'N/A'); ?></dd>
                        <dt>重量 (KG):</dt> <dd><?php echo htmlspecialchars($single_result['weight'] ?? 'N/A'); ?></dd>
                        <dt>總件數:</dt> <dd><?php echo htmlspecialchars($single_result['total_packages'] ?? 'N/A'); ?></dd>
                        <dt>已進倉件數:</dt> <dd><?php echo htmlspecialchars($single_result['packages_in'] ?? 'N/A'); ?></dd>
                        <dt>已出倉件數:</dt> <dd><?php echo htmlspecialchars($single_result['packages_out'] ?? 'N/A'); ?></dd>
                        <dt>通關方式:</dt> <dd><?php echo htmlspecialchars($single_result['clearance_method'] ?? 'N/A'); ?></dd>
                        <?php endif; ?>
                        <dt>進倉日期時間:</dt> <dd><?php echo htmlspecialchars($single_result['storage_in_datetime'] ?? 'N/A'); ?></dd>
                        <dt>出倉日期時間:</dt> <dd><?php echo htmlspecialchars($single_result['storage_out_datetime'] ?? 'N/A'); ?></dd>
                        <dt>狀態:</dt> <dd class="font-bold <?php echo ($single_result['status'] == '已出倉') ? 'text-green-600' : 'text-red-600'; ?>"><?php echo htmlspecialchars($single_result['status'] ?? 'N/A'); ?></dd>
                        
                        <?php if ($advanced_view): ?>
                        <dt>備註:</dt> <dd><?php echo htmlspecialchars($single_result['remark'] ?? 'N/A'); ?></dd>
                        <?php endif; ?>
                    </dl>
                </div>
                <?php elseif ($error_message): ?>
                <div class="mt-8 pt-6 border-t">
                     <p class="text-red-600"><?php echo $error_message; ?></p>
                </div>
                <?php endif; ?>
            </div>

            <div class="bg-white rounded-xl shadow-lg p-6 md:p-8">
                <h2 class="text-2xl font-bold text-gray-800 mb-6 border-b pb-3">多筆查詢 (下載CSV)</h2>
                <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post" class="space-y-6">
                    <div>
                        <label for="house_nos_multi" class="block text-sm font-medium text-gray-700 mb-1">輸入多筆分號 (每筆一行)</label>
                        <textarea name="house_nos_multi" id="house_nos_multi" rows="10" class="mt-1 block w-full px-3 py-2 bg-white border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500"></textarea>
                        <p class="mt-2 text-sm text-gray-500">最多 50 筆資料。結果將會直接下載為 CSV 檔案。</p>
                        <p id="line-count" class="mt-1 text-sm font-semibold text-gray-700">目前行數: 0</p>
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
        const textarea = document.getElementById('house_nos_multi');
        const lineCountDisplay = document.getElementById('line-count');

        textarea.addEventListener('input', () => {
            const text = textarea.value;
            const lines = text.split('\n').filter(line => line.trim() !== '');
            const count = lines.length;
            
            lineCountDisplay.textContent = `目前行數: ${count}`;
            
            if (count > 50) {
                lineCountDisplay.classList.add('text-red-600');
                lineCountDisplay.classList.remove('text-gray-700');
            } else {
                lineCountDisplay.classList.remove('text-red-600');
                lineCountDisplay.classList.add('text-gray-700');
            }
        });
    </script>
</body>
</html>
