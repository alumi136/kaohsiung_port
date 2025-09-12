<?php
session_start();

// 引用 Composer 的 autoloader
require 'excel/vendor/autoload.php';
// 引用資料庫設定檔
require_once 'config.php';

// 使用 PhpSpreadsheet 相關類別
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date;

// 檢查使用者是否登入，否則導向到登入頁面
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$message = '';
$error = '';
$warning = '';

// --- 後端邏輯處理 ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $action = $_POST['action'] ?? '';

        // --- 匯入 Excel ---
        if ($action === 'import' && isset($_FILES['csv_file']) && $_FILES['csv_file']['error'] == UPLOAD_ERR_OK) {
            $file_tmp_path = $_FILES['csv_file']['tmp_name'];
            
            $spreadsheet = IOFactory::load($file_tmp_path);
            $sheet = $spreadsheet->getActiveSheet();
            $highestRow = $sheet->getHighestRow();

            $pdo->beginTransaction();

            // 準備檢查重複的 statement
            $check_stmt = $pdo->prepare("SELECT id FROM daily_arrange WHERE bl_number = ? AND container_number = ?");
            
            // 準備插入新資料的 statement
            $insert_stmt = $pdo->prepare(
                "INSERT INTO daily_arrange (arrival_date, bl_number, container_number, vessel_code, vessel_name, quantity, weight, warehouse, remarks, status) 
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );

            $importedCount = 0;
            $skippedRecords = [];
            
            for ($row = 2; $row <= $highestRow; $row++) {
                $bl_number = $sheet->getCell('B' . $row)->getValue();
                $container_number = $sheet->getCell('C' . $row)->getValue();

                // 如果提單或櫃號為空，則跳過此行
                if (empty($bl_number) && empty($container_number)) {
                    continue;
                }

                // 檢查資料是否已存在
                $check_stmt->execute([$bl_number, $container_number]);
                if ($check_stmt->fetch()) {
                    $skippedRecords[] = "提單: " . htmlspecialchars($bl_number) . ", 櫃號: " . htmlspecialchars($container_number);
                    continue; // 跳過此筆重複資料
                }

                // 處理日期格式
                $arrival_date_excel = $sheet->getCell('A' . $row)->getValue();
                $arrival_date = null;
                if (!empty($arrival_date_excel)) {
                    if (is_numeric($arrival_date_excel)) {
                         $arrival_date = Date::excelToDateTimeObject($arrival_date_excel)->format('Y-m-d');
                    } else {
                         $arrival_date = date('Y-m-d', strtotime($arrival_date_excel));
                    }
                }

                $status = $sheet->getCell('L' . $row)->getValue();

                $insert_stmt->execute([
                    $arrival_date,
                    $bl_number,
                    $container_number,
                    $sheet->getCell('E' . $row)->getValue(), // 船掛
                    $sheet->getCell('F' . $row)->getValue(), // 船名
                    !empty($sheet->getCell('H' . $row)->getValue()) ? intval($sheet->getCell('H' . $row)->getValue()) : null, // 數量
                    !empty($sheet->getCell('I' . $row)->getValue()) ? floatval($sheet->getCell('I' . $row)->getValue()) : null, // 重量
                    $sheet->getCell('J' . $row)->getValue(), // 統倉
                    $sheet->getCell('K' . $row)->getValue(), // 備註
                    (isset($status) && $status !== '') ? intval($status) : 0 // 狀態
                ]);
                $importedCount++;
            }
            
            $pdo->commit();
            $message = "成功匯入 {$importedCount} 筆新資料！";
            if (!empty($skippedRecords)) {
                $warning = "已跳過 " . count($skippedRecords) . " 筆重複資料：<br>" . implode("<br>", $skippedRecords);
            }
        }
        // --- 新增資料 ---
        elseif ($action === 'add') {
            $stmt = $pdo->prepare(
                "INSERT INTO daily_arrange (arrival_date, bl_number, container_number, vessel_code, vessel_name, quantity, weight, warehouse, remarks, status) 
                 VALUES (:arrival_date, :bl_number, :container_number, :vessel_code, :vessel_name, :quantity, :weight, :warehouse, :remarks, :status)"
            );
            $stmt->execute([
                ':arrival_date' => !empty($_POST['arrival_date']) ? $_POST['arrival_date'] : null,
                ':bl_number' => $_POST['bl_number'],
                ':container_number' => $_POST['container_number'],
                ':vessel_code' => $_POST['vessel_code'],
                ':vessel_name' => $_POST['vessel_name'],
                ':quantity' => !empty($_POST['quantity']) ? intval($_POST['quantity']) : null,
                ':weight' => !empty($_POST['weight']) ? floatval($_POST['weight']) : null,
                ':warehouse' => $_POST['warehouse'],
                ':remarks' => $_POST['remarks'],
                ':status' => intval($_POST['status'])
            ]);
            $message = "資料新增成功！";
        }
        // --- 更新資料 ---
        elseif ($action === 'update') {
            $stmt = $pdo->prepare(
                "UPDATE daily_arrange SET 
                    arrival_date = :arrival_date, bl_number = :bl_number, container_number = :container_number, 
                    vessel_code = :vessel_code, vessel_name = :vessel_name, quantity = :quantity, weight = :weight, 
                    warehouse = :warehouse, remarks = :remarks, status = :status
                 WHERE id = :id"
            );
            $stmt->execute([
                ':id' => $_POST['id'],
                ':arrival_date' => !empty($_POST['arrival_date']) ? $_POST['arrival_date'] : null,
                ':bl_number' => $_POST['bl_number'],
                ':container_number' => $_POST['container_number'],
                ':vessel_code' => $_POST['vessel_code'],
                ':vessel_name' => $_POST['vessel_name'],
                ':quantity' => !empty($_POST['quantity']) ? intval($_POST['quantity']) : null,
                ':weight' => !empty($_POST['weight']) ? floatval($_POST['weight']) : null,
                ':warehouse' => $_POST['warehouse'],
                ':remarks' => $_POST['remarks'],
                ':status' => intval($_POST['status'])
            ]);
            $message = "資料更新成功！";
        }
        // --- 刪除資料 ---
        elseif ($action === 'delete') {
            $stmt = $pdo->prepare("DELETE FROM daily_arrange WHERE id = :id");
            $stmt->execute([':id' => $_POST['id']]);
            $message = "資料刪除成功！";
        }

    } catch (Exception $e) {
        if (isset($pdo) && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = "操作失敗：" . $e->getMessage();
    }
}

// --- 查詢資料 ---
$search_start_date = $_GET['search_start_date'] ?? date('Y-m-01');
$search_end_date = $_GET['search_end_date'] ?? date('Y-m-t');

$stmt = $pdo->prepare("SELECT * FROM daily_arrange WHERE arrival_date BETWEEN ? AND ? ORDER BY arrival_date ASC, id ASC");
$stmt->execute([$search_start_date, $search_end_date]);
$results = $stmt->fetchAll(PDO::FETCH_ASSOC);
$total_records = count($results);

?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>排櫃操作</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+TC:wght@400;500;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Noto Sans TC', sans-serif; }
        th, td { white-space: nowrap; padding: 8px 12px; }
        .modal { display: none; }
        .modal.active { display: flex; }
    </style>
</head>
<body class="bg-gray-100 p-6">

<div class="container mx-auto max-w-full">
    <h1 class="text-2xl font-bold mb-6 text-gray-800">排櫃操作管理</h1>

    <?php if ($message): ?>
        <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded relative mb-4" role="alert">
            <span class="block sm:inline"><?php echo htmlspecialchars($message); ?></span>
        </div>
    <?php endif; ?>
    <?php if ($warning): ?>
        <div class="bg-yellow-100 border border-yellow-400 text-yellow-700 px-4 py-3 rounded relative mb-4" role="alert">
            <span class="block sm:inline"><?php echo $warning; // Use echo without htmlspecialchars to render <br> ?></span>
        </div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative mb-4" role="alert">
            <span class="block sm:inline"><?php echo htmlspecialchars($error); ?></span>
        </div>
    <?php endif; ?>

    <!-- 功能區塊 -->
    <div class="bg-white p-6 rounded-lg shadow-md mb-6">
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <!-- 匯入區塊 -->
            <div>
                <h2 class="text-lg font-semibold mb-2">匯入 Excel 資料</h2>
                <form action="arrange.php?search_start_date=<?php echo htmlspecialchars($search_start_date); ?>&search_end_date=<?php echo htmlspecialchars($search_end_date); ?>" method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="import">
                    <input type="file" name="csv_file" required class="block w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-full file:border-0 file:text-sm file:font-semibold file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100" accept=".xlsx, .xls, .csv">
                    <button type="submit" class="mt-2 px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition">開始匯入</button>
                </form>
            </div>

            <!-- 查詢與新增區塊 -->
            <div>
                <h2 class="text-lg font-semibold mb-2">查詢與新增</h2>
                <form action="arrange.php" method="GET" class="flex items-center space-x-2 flex-wrap">
                    <input type="date" name="search_start_date" value="<?php echo htmlspecialchars($search_start_date); ?>" class="border rounded-lg px-3 py-2">
                    <span class="text-gray-500">到</span>
                    <input type="date" name="search_end_date" value="<?php echo htmlspecialchars($search_end_date); ?>" class="border rounded-lg px-3 py-2">
                    <button type="submit" class="px-4 py-2 bg-gray-600 text-white rounded-lg hover:bg-gray-700 transition">查詢</button>
                    <button type="button" onclick="openModal('addModal')" class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 transition">新增</button>
                </form>
            </div>
        </div>
    </div>
    
    <!-- 查詢結果統計 -->
    <div class="mb-4">
        <p class="text-gray-700 font-semibold">查詢結果：共 <?php echo $total_records; ?> 筆資料</p>
    </div>

    <!-- 資料顯示表格 -->
    <div class="bg-white rounded-lg shadow-md overflow-x-auto">
        <table class="w-full text-sm text-left text-gray-700">
            <thead class="text-xs text-gray-700 uppercase bg-gray-50">
                <tr>
                    <th>操作</th>
                    <th>到港日期</th>
                    <th>提單</th>
                    <th>櫃號</th>
                    <th>船掛</th>
                    <th>船名</th>
                    <th>數量</th>
                    <th>重量</th>
                    <th>統倉</th>
                    <th>備註</th>
                    <th>狀態</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($results as $row): ?>
                <tr class="bg-white border-b hover:bg-gray-50">
                    <td class="flex items-center space-x-2 py-2">
                        <button type="button" onclick='openEditModal(<?php echo json_encode($row); ?>)' class="px-3 py-1 bg-yellow-500 text-white rounded hover:bg-yellow-600 text-xs">修改</button>
                        <form action="arrange.php?search_start_date=<?php echo htmlspecialchars($search_start_date); ?>&search_end_date=<?php echo htmlspecialchars($search_end_date); ?>" method="POST" onsubmit="return confirm('您確定要刪除這筆資料嗎？');" class="inline-block">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?php echo $row['id']; ?>">
                            <button type="submit" class="px-3 py-1 bg-red-600 text-white rounded hover:bg-red-700 text-xs">刪除</button>
                        </form>
                    </td>
                    <td><?php echo htmlspecialchars($row['arrival_date']); ?></td>
                    <td><?php echo htmlspecialchars($row['bl_number']); ?></td>
                    <td><?php echo htmlspecialchars($row['container_number']); ?></td>
                    <td><?php echo htmlspecialchars($row['vessel_code']); ?></td>
                    <td><?php echo htmlspecialchars($row['vessel_name']); ?></td>
                    <td><?php echo htmlspecialchars($row['quantity']); ?></td>
                    <td><?php echo htmlspecialchars($row['weight']); ?></td>
                    <td><?php echo htmlspecialchars($row['warehouse']); ?></td>
                    <td><?php echo htmlspecialchars($row['remarks']); ?></td>
                    <td><?php echo $row['status'] == 1 ? '<span class="text-green-600 font-semibold">已通關</span>' : '<span class="text-red-600 font-semibold">未通關</span>'; ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($results)): ?>
                <tr>
                    <td colspan="11" class="text-center py-4">在指定的日期區間內沒有找到任何資料。</td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- 新增資料 Modal -->
<div id="addModal" class="modal fixed inset-0 bg-black bg-opacity-50 items-center justify-center p-4">
    <div class="bg-white rounded-lg shadow-xl w-full max-w-3xl max-h-full overflow-y-auto">
        <form action="arrange.php?search_start_date=<?php echo htmlspecialchars($search_start_date); ?>&search_end_date=<?php echo htmlspecialchars($search_end_date); ?>" method="POST">
            <input type="hidden" name="action" value="add">
            <div class="p-6 border-b"><h3 class="text-xl font-semibold">新增排櫃資料</h3></div>
            <div class="p-6 grid grid-cols-1 md:grid-cols-2 gap-4">
                <div><label class="block text-sm font-medium">到港日期</label><input type="date" name="arrival_date" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm" value="<?php echo date('Y-m-d'); ?>"></div>
                <div><label class="block text-sm font-medium">提單</label><input type="text" name="bl_number" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm"></div>
                <div><label class="block text-sm font-medium">櫃號</label><input type="text" name="container_number" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm"></div>
                <div><label class="block text-sm font-medium">船掛</label><input type="text" name="vessel_code" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm"></div>
                <div><label class="block text-sm font-medium">船名</label><input type="text" name="vessel_name" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm"></div>
                <div><label class="block text-sm font-medium">數量</label><input type="number" name="quantity" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm"></div>
                <div><label class="block text-sm font-medium">重量</label><input type="number" step="0.01" name="weight" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm"></div>
                <div><label class="block text-sm font-medium">狀態</label>
                    <select name="status" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm">
                        <option value="0" selected>未通關</option>
                        <option value="1">已通關</option>
                    </select>
                </div>
                <div class="md:col-span-2"><label class="block text-sm font-medium">統倉</label><input type="text" name="warehouse" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm"></div>
                <div class="md:col-span-2"><label class="block text-sm font-medium">備註</label><textarea name="remarks" rows="3" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm"></textarea></div>
            </div>
            <div class="p-6 bg-gray-50 flex justify-end space-x-2">
                <button type="button" onclick="closeModal('addModal')" class="px-4 py-2 bg-gray-200 rounded-lg hover:bg-gray-300">取消</button>
                <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">確定新增</button>
            </div>
        </form>
    </div>
</div>

<!-- 修改資料 Modal -->
<div id="editModal" class="modal fixed inset-0 bg-black bg-opacity-50 items-center justify-center p-4">
    <div class="bg-white rounded-lg shadow-xl w-full max-w-3xl max-h-full overflow-y-auto">
        <form action="arrange.php?search_start_date=<?php echo htmlspecialchars($search_start_date); ?>&search_end_date=<?php echo htmlspecialchars($search_end_date); ?>" method="POST">
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="id" id="edit-id">
            <div class="p-6 border-b"><h3 class="text-xl font-semibold">修改排櫃資料</h3></div>
            <div class="p-6 grid grid-cols-1 md:grid-cols-2 gap-4">
                <div><label class="block text-sm font-medium">到港日期</label><input type="date" name="arrival_date" id="edit-arrival_date" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm"></div>
                <div><label class="block text-sm font-medium">提單</label><input type="text" name="bl_number" id="edit-bl_number" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm"></div>
                <div><label class="block text-sm font-medium">櫃號</label><input type="text" name="container_number" id="edit-container_number" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm"></div>
                <div><label class="block text-sm font-medium">船掛</label><input type="text" name="vessel_code" id="edit-vessel_code" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm"></div>
                <div><label class="block text-sm font-medium">船名</label><input type="text" name="vessel_name" id="edit-vessel_name" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm"></div>
                <div><label class="block text-sm font-medium">數量</label><input type="number" name="quantity" id="edit-quantity" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm"></div>
                <div><label class="block text-sm font-medium">重量</label><input type="number" step="0.01" name="weight" id="edit-weight" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm"></div>
                <div><label class="block text-sm font-medium">狀態</label>
                    <select name="status" id="edit-status" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm">
                        <option value="0">未通關</option>
                        <option value="1">已通關</option>
                    </select>
                </div>
                <div class="md:col-span-2"><label class="block text-sm font-medium">統倉</label><input type="text" name="warehouse" id="edit-warehouse" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm"></div>
                <div class="md:col-span-2"><label class="block text-sm font-medium">備註</label><textarea name="remarks" id="edit-remarks" rows="3" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm"></textarea></div>
            </div>
            <div class="p-6 bg-gray-50 flex justify-end space-x-2">
                <button type="button" onclick="closeModal('editModal')" class="px-4 py-2 bg-gray-200 rounded-lg hover:bg-gray-300">取消</button>
                <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">確定修改</button>
            </div>
        </form>
    </div>
</div>

<script>
    function openModal(modalId) {
        document.getElementById(modalId).classList.add('active');
    }

    function closeModal(modalId) {
        document.getElementById(modalId).classList.remove('active');
    }

    function openEditModal(data) {
        document.getElementById('edit-id').value = data.id;
        document.getElementById('edit-arrival_date').value = data.arrival_date;
        document.getElementById('edit-bl_number').value = data.bl_number;
        document.getElementById('edit-container_number').value = data.container_number;
        document.getElementById('edit-vessel_code').value = data.vessel_code;
        document.getElementById('edit-vessel_name').value = data.vessel_name;
        document.getElementById('edit-quantity').value = data.quantity;
        document.getElementById('edit-weight').value = data.weight;
        document.getElementById('edit-warehouse').value = data.warehouse;
        document.getElementById('edit-remarks').value = data.remarks;
        document.getElementById('edit-status').value = data.status;
        openModal('editModal');
    }
</script>

</body>
</html>

