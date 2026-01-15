<?php
/**
 * 大創貨物分揀系統 - 管理後台 (導入端) v5.3 (Fix Path)
 * 環境: PHP 7.4 / MySQL 8.0
 * 修正: 指定 PhpSpreadsheet autoload 絕對路徑
 */

// --- 錯誤報告設定 ---
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// --- [修正] 載入 Composer 套件 (使用您指定的絕對路徑) ---
$autoloadPath = '/var/www/html/excel/vendor/autoload.php';

if (file_exists($autoloadPath)) {
    require $autoloadPath;
} else {
    die("<div style='color:red; padding:20px; border:1px solid red;'>
        <strong>系統路徑錯誤：</strong> 找不到 autoload 檔案。<br>
        系統嘗試讀取路徑：<code>$autoloadPath</code><br>
        請確認路徑是否正確，或檢查權限。
        </div>");
}

use PhpOffice\PhpSpreadsheet\IOFactory;

// --- 1. 資料庫連線配置 (含 Port) ---
$host = '127.0.0.1';
$port = '3306';      // MySQL Port
$db   = 'sunrise';
$user = 'alumi136';
$pass = 'Alumi!36';

$dsn = "mysql:host=$host;port=$port;dbname=$db;charset=utf8mb4";
$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (PDOException $e) {
    die("<div style='color:red;'>[DB Error] 資料庫連線失敗: " . $e->getMessage() . "</div>");
}

$message = "";

// --- 2. 處理上傳動作 ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_FILES['import_file'])) {
    $lotNo = $_POST['lot_no'] ?: 'AUTO-' . date('YmdHis');
    
    try {
        // [節點] 檔案上傳檢查
        $file = $_FILES['import_file'];
        if ($file['error'] !== UPLOAD_ERR_OK) {
            throw new Exception("檔案上傳錯誤代碼: " . $file['error']);
        }
        
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $validExts = ['csv', 'xls', 'xlsx'];
        if (!in_array($ext, $validExts)) {
            throw new Exception("不支援的檔案格式 ($ext)，請上傳 .csv, .xls, .xlsx");
        }

        // [節點] 解析 Excel/CSV
        try {
            $spreadsheet = IOFactory::load($file['tmp_name']);
            $sheetData = $spreadsheet->getActiveSheet()->toArray(null, true, true, true);
        } catch (Exception $e) {
            throw new Exception("Excel 解析失敗: " . $e->getMessage());
        }

        // [節點] 資料庫交易開始
        $pdo->beginTransaction();

        // A. 建立貨櫃
        $stmtContainer = $pdo->prepare("INSERT INTO containers (lot_no, status) VALUES (?, 0)");
        $stmtContainer->execute([$lotNo]);
        $containerId = $pdo->lastInsertId();

        // B. 寫入清單 items
        // 注意: 根據您的 CSV 欄位對應: A:ShopCD, C:ShopName, E:CartonNo, I:Box, K:TotalPcs
        $sql = "INSERT INTO import_items (container_id, shop_cd, shop_name, carton_no, box_qty, total_pcs) VALUES (?, ?, ?, ?, ?, ?)";
        $stmtItem = $pdo->prepare($sql);
        
        $count = 0;
        foreach ($sheetData as $rowNum => $col) {
            if ($rowNum == 1) continue; // 跳過標題行
            
            // 簡單的欄位檢查: 若 CartonNo (E欄) 為空則跳過
            if (empty($col['E'])) continue; 

            try {
                $stmtItem->execute([
                    $containerId,
                    trim($col['A']), // ShopCD
                    trim($col['C']), // ShopName
                    trim($col['E']), // CartonNo
                    (int)$col['I'],  // Box
                    (int)$col['K']   // TotalPcs
                ]);
                $count++;
            } catch (Exception $rowEx) {
                // 若單行寫入失敗，拋出包含行號的錯誤
                throw new Exception("第 {$rowNum} 行寫入失敗 (箱號:{$col['E']}): " . $rowEx->getMessage());
            }
        }

        // [節點] 提交交易
        $pdo->commit();
        $message = "<div class='alert alert-success'>成功導入貨櫃：<strong>$lotNo</strong>，共 $count 筆數據。</div>";
        
    } catch (Exception $e) {
        // [節點] 發生錯誤，回滾交易
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $message = "<div class='alert alert-danger'><strong>導入失敗：</strong><br>" . $e->getMessage() . "</div>";
    }
}
?>

<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DAISO - 管理端數據導入</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">

<nav class="navbar navbar-dark bg-dark">
    <div class="container">
        <span class="navbar-brand">DAISO 物流管理後台</span>
        <a href="daiso.php" class="btn btn-outline-light btn-sm">前往分揀作業</a>
    </div>
</nav>

<div class="container mt-5">
    <div class="row justify-content-center">
        <div class="col-md-8">
            <?php echo $message; ?>
            
            <div class="card shadow">
                <div class="card-header bg-primary text-white">
                    <h5 class="mb-0">導入貨物清單 (.csv, .xls, .xlsx)</h5>
                </div>
                <div class="card-body">
                    <form action="admin.php" method="POST" enctype="multipart/form-data">
                        <div class="mb-3">
                            <label class="form-label">設定貨櫃批號 (Lot No.)</label>
                            <input type="text" name="lot_no" class="form-control" placeholder="例如: 6778-15" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">選擇檔案</label>
                            <input type="file" name="import_file" class="form-control" accept=".csv, .xls, .xlsx" required>
                            <div class="form-text">路徑檢查: 系統將使用 /var/www/html/excel/vendor/autoload.php</div>
                        </div>
                        <button type="submit" class="btn btn-primary w-100">開始上傳</button>
                    </form>
                </div>
            </div>

            <div class="text-muted text-center mt-3" style="font-size: 0.8rem;">
                DB Host: <?php echo $host; ?> | Port: <?php echo $port; ?> | Status: <span class="text-success">Connected</span>
            </div>
            
            <div class="card mt-4 shadow border-0">
                <div class="card-body">
                    <h6>系統內現有貨櫃 (最近 5 筆)</h6>
                    <table class="table table-sm mt-3">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Lot No.</th>
                                <th>狀態</th>
                                <th>建立時間</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $list = $pdo->query("SELECT * FROM containers ORDER BY id DESC LIMIT 5")->fetchAll();
                            foreach ($list as $row) {
                                $status = $row['status'] == 1 ? '<span class="badge bg-secondary">已結案</span>' : '<span class="badge bg-success">作業中</span>';
                                echo "<tr><td>{$row['id']}</td><td>{$row['lot_no']}</td><td>$status</td><td>{$row['created_at']}</td></tr>";
                            }
                            ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
</body>
</html>