<?php
// 檔案: innoout_details.php
// 說明: 顯示特定主號下「已進倉未出倉」的貨物明細，並提供下載功能。

session_start();
require_once 'config.php';
require 'excel/vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

// 檢查使用者是否登入
if (!isset($_SESSION['user_id'])) {
    die("請先登入系統。");
}

// 獲取並驗證主單號
$bl_number = $_GET['bl_number'] ?? null;
if (empty($bl_number)) {
    die("錯誤：未提供主單號。");
}

// 查詢資料庫
try {
    $stmt = $pdo->prepare(
        "SELECT master_no, house_no, total_packages, packages_in, packages_out, 
                clearance_method, storage_in_datetime, storage_out_datetime, remark 
         FROM daily_outbound 
         WHERE master_no = ? 
         AND storage_in_datetime IS NOT NULL 
         AND storage_out_datetime IS NULL
         ORDER BY house_no ASC"
    );
    $stmt->execute([$bl_number]);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("資料庫查詢失敗：" . $e->getMessage());
}

// --- 處理 Excel 匯出請求 ---
if (isset($_GET['export']) && $_GET['export'] == '1') {
    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('已進未出明細');

    // 設定標頭
    $headers = ['主號', '分號', '總件數', '已進倉', '已出倉', '通關方式', '進倉時間', '出倉時間', '備註'];
    $sheet->fromArray($headers, NULL, 'A1');

    // 寫入資料
    $row_num = 2;
    foreach ($results as $row) {
        $sheet->fromArray($row, NULL, 'A' . $row_num);
        $row_num++;
    }

    // 設定 HTTP Header 讓瀏覽器下載檔案
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment;filename="已進未出明細_' . $bl_number . '.xlsx"');
    header('Cache-Control: max-age=0');

    $writer = new Xlsx($spreadsheet);
    $writer->save('php://output');
    exit();
}
?>

<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>已進未出 詳細資料</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        body { font-family: 'Noto Sans TC', sans-serif; }
    </style>
</head>
<body class="bg-gray-100 p-4 sm:p-6">
    <div class="container mx-auto bg-white p-6 rounded-lg shadow-md">
        <div class="flex justify-between items-center mb-6 border-b pb-4">
            <div>
                <h1 class="text-2xl font-bold text-gray-800">已進未出 詳細資料</h1>
                <p class="text-gray-600">主單號: <span class="font-semibold text-blue-600"><?php echo htmlspecialchars($bl_number); ?></span></p>
            </div>
            <a href="?bl_number=<?php echo urlencode($bl_number); ?>&export=1" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md shadow-sm text-white bg-green-600 hover:bg-green-700">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
                </svg>
                下載 Excel
            </a>
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">主號</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">分號</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">總件數</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">已進倉</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">已出倉</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">通關方式</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">進倉時間</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">出倉時間</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">備註</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php if (empty($results)): ?>
                        <tr>
                            <td colspan="9" class="px-4 py-4 text-center text-gray-500">找不到符合條件的資料。</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($results as $row): ?>
                            <tr class="hover:bg-gray-50">
                                <td class="px-4 py-4 whitespace-nowrap text-sm"><?php echo htmlspecialchars($row['master_no']); ?></td>
                                <td class="px-4 py-4 whitespace-nowrap text-sm"><?php echo htmlspecialchars($row['house_no']); ?></td>
                                <td class="px-4 py-4 whitespace-nowrap text-sm"><?php echo htmlspecialchars($row['total_packages']); ?></td>
                                <td class="px-4 py-4 whitespace-nowrap text-sm"><?php echo htmlspecialchars($row['packages_in']); ?></td>
                                <td class="px-4 py-4 whitespace-nowrap text-sm"><?php echo htmlspecialchars($row['packages_out']); ?></td>
                                <td class="px-4 py-4 whitespace-nowrap text-sm"><?php echo htmlspecialchars($row['clearance_method']); ?></td>
                                <td class="px-4 py-4 whitespace-nowrap text-sm"><?php echo htmlspecialchars($row['storage_in_datetime']); ?></td>
                                <td class="px-4 py-4 whitespace-nowrap text-sm"><?php echo htmlspecialchars($row['storage_out_datetime']); ?></td>
                                <td class="px-4 py-4 text-sm text-gray-700"><?php echo htmlspecialchars($row['remark']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</body>
</html>
