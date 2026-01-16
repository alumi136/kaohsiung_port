<?php
/**
 * 大創貨物分揀系統 - 管理核心 (Sunrise Core) v6.0
 * 檔案名稱: sunrise.php
 * 功能: 貨櫃導入、狀態監控、資料刪除、總表查詢
 */

// --- 錯誤報告與環境設定 ---
ini_set('display_errors', 1);
error_reporting(E_ALL);

// 指定 Autoload 絕對路徑
$autoloadPath = '/var/www/html/excel/vendor/autoload.php';
if (file_exists($autoloadPath)) {
    require $autoloadPath;
} else {
    die("系統錯誤：找不到 autoload 檔案 ($autoloadPath)");
}

use PhpOffice\PhpSpreadsheet\IOFactory;

// --- 資料庫連線 ---
$host = '127.0.0.1';
$port = '3306';
$db   = 'sunrise';
$user = 'alumi136';
$pass = 'Alumi!36';

try {
    $pdo = new PDO("mysql:host=$host;port=$port;dbname=$db;charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    die("DB Connection Error: " . $e->getMessage());
}

$message = "";

// ==========================================================
//  功能模組 1: 刪除貨櫃 (Delete Action)
// ==========================================================
if (isset($_POST['action']) && $_POST['action'] == 'delete_container') {
    $delId = (int)$_POST['container_id'];
    
    try {
        // 1. 檢查狀態，若為「已完成 (status=1)」則禁止刪除
        $check = $pdo->prepare("SELECT status, lot_no FROM containers WHERE id = ?");
        $check->execute([$delId]);
        $row = $check->fetch();
        
        if (!$row) {
            throw new Exception("找不到該貨櫃資料");
        }
        if ($row['status'] == 1) {
            throw new Exception("貨櫃 {$row['lot_no']} 狀態為 [已完成]，禁止刪除！");
        }

        // 2. 執行連動刪除 (Transaction)
        $pdo->beginTransaction();

        // 刪除掃描紀錄
        $stmt = $pdo->prepare("DELETE FROM scan_records WHERE container_id = ?");
        $stmt->execute([$delId]);
        
        // 刪除清單項目
        $stmt = $pdo->prepare("DELETE FROM import_items WHERE container_id = ?");
        $stmt->execute([$delId]);
        
        // 刪除棧板紀錄
        $stmt = $pdo->prepare("DELETE FROM pallets WHERE container_id = ?");
        $stmt->execute([$delId]);
        
        // 最後刪除貨櫃主檔
        $stmt = $pdo->prepare("DELETE FROM containers WHERE id = ?");
        $stmt->execute([$delId]);

        $pdo->commit();
        $message = "<div class='alert alert-success'>貨櫃 <strong>{$row['lot_no']}</strong> 及其所有關聯資料已成功刪除。</div>";

    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $message = "<div class='alert alert-danger'>刪除失敗： " . $e->getMessage() . "</div>";
    }
}

// ==========================================================
//  功能模組 2: 導入貨櫃 (Import Action)
// ==========================================================
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_FILES['import_file'])) {
    $lotNo = trim($_POST['lot_no']);
    
    try {
        if (empty($lotNo)) throw new Exception("請輸入貨櫃批號 (Lot No.)");

        // [需求 3] 檢查重複導入
        $checkDup = $pdo->prepare("SELECT COUNT(*) FROM containers WHERE lot_no = ?");
        $checkDup->execute([$lotNo]);
        if ($checkDup->fetchColumn() > 0) {
            throw new Exception("貨櫃批號 <strong>$lotNo</strong> 已存在，無法重複導入！");
        }

        // 檔案檢查
        $file = $_FILES['import_file'];
        if ($file['error'] !== UPLOAD_ERR_OK) throw new Exception("上傳錯誤代碼: " . $file['error']);
        
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['csv', 'xls', 'xlsx'])) throw new Exception("不支援的檔案格式");

        // 解析 Excel
        $spreadsheet = IOFactory::load($file['tmp_name']);
        $sheetData = $spreadsheet->getActiveSheet()->toArray(null, true, true, true);

        // 寫入 DB
        $pdo->beginTransaction();

        // 建立貨櫃
        $stmtContainer = $pdo->prepare("INSERT INTO containers (lot_no, status) VALUES (?, 0)");
        $stmtContainer->execute([$lotNo]);
        $containerId = $pdo->lastInsertId();

        // 寫入項目
        $sql = "INSERT INTO import_items (container_id, shop_cd, shop_name, carton_no, box_qty, total_pcs) VALUES (?, ?, ?, ?, ?, ?)";
        $stmtItem = $pdo->prepare($sql);
        
        $count = 0;
        foreach ($sheetData as $rowNum => $col) {
            if ($rowNum == 1) continue; 
            if (empty($col['E'])) continue; // 無箱號則跳過

            $stmtItem->execute([
                $containerId,
                trim($col['A']), // ShopCD
                trim($col['C']), // ShopName
                trim($col['E']), // CartonNo
                (int)$col['I'],  // Box
                (int)$col['K']   // TotalPcs
            ]);
            $count++;
        }

        $pdo->commit();
        $message = "<div class='alert alert-success'>成功導入貨櫃：<strong>$lotNo</strong>，共 $count 筆數據。</div>";

    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $message = "<div class='alert alert-danger'>導入失敗： " . $e->getMessage() . "</div>";
    }
}
?>

<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sunrise - 大創物流管理中樞</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        body { background-color: #f0f2f5; font-family: "Microsoft JhengHei", sans-serif; }
        .status-badge { font-size: 0.9rem; padding: 6px 10px; border-radius: 20px; }
        .bg-gray { background-color: #95a5a6; color: white; } /* 未作業 */
        .bg-blue { background-color: #3498db; color: white; } /* 作業中 */
        .bg-green { background-color: #2ecc71; color: white; } /* 已完成 */
        .card-header { font-weight: bold; }
    </style>
</head>
<body>

<nav class="navbar navbar-dark bg-dark shadow-sm">
    <div class="container">
        <span class="navbar-brand"><i class="fas fa-shipping-fast"></i> SUNRISE 物流管理系統</span>
        <a href="daiso.php" class="btn btn-outline-warning btn-sm">前往分揀作業端</a>
    </div>
</nav>

<div class="container mt-4">
    <?php echo $message; ?>

    <div class="card shadow mb-4">
        <div class="card-header bg-primary text-white">
            <i class="fas fa-file-upload"></i> 新增貨櫃導入 (Import Cargo List)
        </div>
        <div class="card-body">
            <form action="sunrise.php" method="POST" enctype="multipart/form-data" class="row g-3 align-items-end">
                <div class="col-md-4">
                    <label class="form-label fw-bold">1. 設定貨櫃批號 (Lot No)</label>
                    <input type="text" name="lot_no" class="form-control" placeholder="請輸入 Lot No (如: 6778-15)" required>
                </div>
                <div class="col-md-5">
                    <label class="form-label fw-bold">2. 上傳清單檔案 (.xlsx / .csv)</label>
                    <input type="file" name="import_file" class="form-control" accept=".csv, .xls, .xlsx" required>
                </div>
                <div class="col-md-3">
                    <button type="submit" class="btn btn-primary w-100"><i class="fas fa-cloud-upload-alt"></i> 執行導入</button>
                </div>
            </form>
        </div>
    </div>

    <div class="card shadow">
        <div class="card-header bg-secondary text-white d-flex justify-content-between align-items-center">
            <span><i class="fas fa-list-alt"></i> 貨櫃作業總表 (LotNo Summary)</span>
            <button class="btn btn-sm btn-light" onclick="location.reload()"><i class="fas fa-sync"></i> 刷新狀態</button>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover table-striped align-middle mb-0 text-center">
                    <thead class="table-light">
                        <tr>
                            <th>ID</th>
                            <th>貨櫃批號 (LotNo)</th>
                            <th>導入時間</th>
                            <th>總箱數</th>
                            <th>已掃描箱數</th>
                            <th>作業進度</th>
                            <th>目前狀態</th>
                            <th>操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        // 查詢所有貨櫃，並關聯計算總數與掃描數
                        // [需求 2] 邏輯判斷依據：MAX(s.scanned_at) 是否存在
                        $sql = "SELECT c.id, c.lot_no, c.status, c.created_at,
                                       COUNT(DISTINCT i.id) as total_items,
                                       COUNT(DISTINCT s.id) as scanned_items,
                                       MAX(s.scanned_at) as last_scan_time
                                FROM containers c
                                LEFT JOIN import_items i ON c.id = i.container_id
                                LEFT JOIN scan_records s ON c.id = s.container_id
                                GROUP BY c.id
                                ORDER BY c.id DESC";
                        
                        $stmt = $pdo->query($sql);
                        $containers = $stmt->fetchAll();

                        if (count($containers) > 0):
                            foreach ($containers as $row):
                                // --- 狀態判斷邏輯 ---
                                // 1. 若 DB status 為 1 -> 已完成
                                // 2. 若 掃描數 > 0 -> 作業中
                                // 3. 否則 -> 未作業
                                if ($row['status'] == 1) {
                                    $statusLabel = '<span class="status-badge bg-green">已完成</span>';
                                    $canDelete = false;
                                } elseif ($row['scanned_items'] > 0) {
                                    $statusLabel = '<span class="status-badge bg-blue">作業中</span>';
                                    $canDelete = true;
                                } else {
                                    $statusLabel = '<span class="status-badge bg-gray">未作業</span>';
                                    $canDelete = true;
                                }

                                // 計算百分比
                                $percent = $row['total_items'] > 0 ? round(($row['scanned_items'] / $row['total_items']) * 100) : 0;
                        ?>
                        <tr>
                            <td><?php echo $row['id']; ?></td>
                            <td class="fw-bold text-primary"><?php echo htmlspecialchars($row['lot_no']); ?></td>
                            <td class="text-muted small"><?php echo $row['created_at']; ?></td>
                            <td><?php echo number_format($row['total_items']); ?></td>
                            <td><?php echo number_format($row['scanned_items']); ?></td>
                            <td>
                                <div class="progress" style="height: 20px;">
                                    <div class="progress-bar bg-info" role="progressbar" style="width: <?php echo $percent; ?>%">
                                        <?php echo $percent; ?>%
                                    </div>
                                </div>
                            </td>
                            <td><?php echo $statusLabel; ?></td>
                            <td>
                                <?php if ($canDelete): ?>
                                    <form method="POST" onsubmit="return confirm('確定要刪除貨櫃 [<?php echo $row['lot_no']; ?>] 嗎？\n注意：這將會刪除所有相關的清單與掃描紀錄，此動作無法復原！');">
                                        <input type="hidden" name="action" value="delete_container">
                                        <input type="hidden" name="container_id" value="<?php echo $row['id']; ?>">
                                        <button type="submit" class="btn btn-outline-danger btn-sm">
                                            <i class="fas fa-trash-alt"></i> 刪除
                                        </button>
                                    </form>
                                <?php else: ?>
                                    <button class="btn btn-secondary btn-sm" disabled title="已完成狀態不可刪除">
                                        <i class="fas fa-lock"></i> 鎖定
                                    </button>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; else: ?>
                            <tr><td colspan="8" class="text-muted p-4">目前系統內無任何貨櫃資料</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

</body>
</html>