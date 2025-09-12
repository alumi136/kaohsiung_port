<?php
// --- 資料庫連線設定 ---
$servername = "localhost";
$username = "alumi136";
$password = "Alumi!36";
$dbname = "kaohsiung_port_db";

// --- 單筆查詢的變數初始化 ---
$single_result = null;
$error_message = '';
$house_no_single = '';

// --- 單筆查詢邏輯 ---
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['single_query'])) {
    $house_no_single = trim($_POST['house_no_single']);

    if (!empty($house_no_single)) {
        $conn = new mysqli($servername, $username, $password, $dbname);
        if ($conn->connect_error) {
            $error_message = "資料庫連線失敗: " . $conn->connect_error;
        } else {
            $conn->set_charset("utf8mb4");

            // 修改 SQL 查詢，加入 declaration_no 欄位
            $sql = "SELECT master_no, house_no, declaration_no, storage_in_datetime, storage_out_datetime, status FROM daily_outbound WHERE house_no = ?";
            
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("s", $house_no_single);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows > 0) {
                $single_result = $result->fetch_assoc();
            } else {
                $error_message = "找不到符合分提單號 '" . htmlspecialchars($house_no_single) . "' 的資料。若您的EZWay已經按[申報相符],請等待1~2天時間通關,謝謝!";
            }
            $stmt->close();
            $conn->close();
        }
    } else {
        $error_message = "請輸入要查詢的分提單號。";
    }
}
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>億欣高雄海快通關狀態查詢</title>
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

    <div class="container mx-auto p-4 md:p-8 max-w-2xl">
        <header class="text-center mb-10">
            <h1 class="text-3xl md:text-4xl font-bold text-blue-900">
                億欣高雄海快通關狀態查詢
            </h1>
        </header>

        <div class="bg-white rounded-xl shadow-lg p-6 md:p-8">
            <h2 class="text-2xl font-bold text-gray-800 mb-6 border-b pb-3">單筆查詢</h2>
            <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post" class="space-y-6">
                <div>
                    <label for="house_no_single" class="block text-sm font-medium text-gray-700 mb-1">輸入分提單號</label>
                    <input type="text" name="house_no_single" id="house_no_single" value="<?php echo htmlspecialchars($house_no_single); ?>" class="mt-1 block w-full px-3 py-2 bg-white border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500">
                </div>
                <div>
                    <button type="submit" name="single_query" class="w-full flex justify-center py-2 px-4 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500">
                        查詢
                    </button>
                </div>
            </form>

            <!-- 單筆查詢結果顯示區 -->
            <?php if ($single_result): ?>
            <div class="mt-8 pt-6 border-t">
                <h3 class="text-lg font-semibold text-gray-900 mb-4">查詢結果:</h3>
                <dl class="result-grid">
                    <dt>主號:</dt> <dd><?php echo htmlspecialchars($single_result['master_no']); ?></dd>
                    <dt>分號:</dt> <dd><?php echo htmlspecialchars($single_result['house_no']); ?></dd>
                    <dt>報單號碼:</dt> <dd><?php echo htmlspecialchars($single_result['declaration_no']); ?></dd>
                    <dt>進倉日期時間:</dt> <dd><?php echo htmlspecialchars($single_result['storage_in_datetime'] ?? 'N/A'); ?></dd>
                    <dt>出倉日期時間:</dt> <dd><?php echo htmlspecialchars($single_result['storage_out_datetime'] ?? 'N/A'); ?></dd>
                    <dt>狀態:</dt> <dd class="font-bold <?php echo ($single_result['status'] == '已出倉') ? 'text-green-600' : 'text-red-600'; ?>"><?php echo htmlspecialchars($single_result['status']); ?></dd>
                </dl>
            </div>
            <?php elseif ($error_message): ?>
            <div class="mt-8 pt-6 border-t">
                 <p class="text-red-600"><?php echo $error_message; ?></p>
            </div>
            <?php endif; ?>
        </div>

    </div>
</body>
</html>

