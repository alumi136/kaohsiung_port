<?php
/**
 * 大創貨物分揀系統 - 管理核心 (Sunrise Core) v6.1
 * 修正: 點擊 LotNo 彈出明細視窗
 */

// --- 錯誤報告與環境設定 ---
ini_set('display_errors', 1);
error_reporting(E_ALL);

$autoloadPath = '/var/www/html/excel/vendor/autoload.php';
if (file_exists($autoloadPath)) {
    require $autoloadPath;
} else {
    die("系統錯誤：找不到 autoload 檔案 ($autoloadPath)");
}

use PhpOffice\PhpSpreadsheet\IOFactory;

// --- 資料庫連線 ---
$host = '127.0.0.1'; $port = '3306'; $db = 'sunrise'; $user = 'alumi136'; $pass = 'Alumi!36';
try {
    $pdo = new PDO("mysql:host=$host;port=$port;dbname=$db;charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) { die("DB Connection Error: " . $e->getMessage()); }

$message = "";

// --- 功能: 刪除貨櫃 ---
if (isset($_POST['action']) && $_POST['action'] == 'delete_container') {
    $delId = (int)$_POST['container_id'];
    try {
        $check = $pdo->prepare("SELECT status, lot_no FROM containers WHERE id = ?");
        $check->execute([$delId]);
        $row = $check->fetch();
        
        if (!$row || $row['status'] == 1) {
            throw new Exception("刪除失敗：貨櫃不存在或狀態為 [已完成] 禁止刪除。");
        }

        $pdo->beginTransaction();
        $pdo->prepare("DELETE FROM scan_records WHERE container_id = ?")->execute([$delId]);
        $pdo->prepare("DELETE FROM import_items WHERE container_id = ?")->execute([$delId]);
        $pdo->prepare("DELETE FROM pallets WHERE container_id = ?")->execute([$delId]);
        $pdo->prepare("DELETE FROM containers WHERE id = ?")->execute([$delId]);
        $pdo->commit();
        $message = "<div class='alert alert-success'>貨櫃 <strong>{$row['lot_no']}</strong> 已刪除。</div>";
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $message = "<div class='alert alert-danger'>刪除錯誤: " . $e->getMessage() . "</div>";
    }
}

// --- 功能: 導入貨櫃 ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_FILES['import_file'])) {
    $lotNo = trim($_POST['lot_no']);
    try {
        if (empty($lotNo)) throw new Exception("請輸入 Lot No");
        
        // 檢查重複
        $checkDup = $pdo->prepare("SELECT COUNT(*) FROM containers WHERE lot_no = ?");
        $checkDup->execute([$lotNo]);
        if ($checkDup->fetchColumn() > 0) throw new Exception("貨櫃批號 <strong>$lotNo</strong> 已存在！");

        $file = $_FILES['import_file'];
        if ($file['error'] !== UPLOAD_ERR_OK) throw new Exception("上傳失敗代碼: " . $file['error']);
        
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['csv', 'xls', 'xlsx'])) throw new Exception("格式不支援");

        $spreadsheet = IOFactory::load($file['tmp_name']);
        $sheetData = $spreadsheet->getActiveSheet()->toArray(null, true, true, true);

        $pdo->beginTransaction();
        $pdo->prepare("INSERT INTO containers (lot_no, status) VALUES (?, 0)")->execute([$lotNo]);
        $containerId = $pdo->lastInsertId();

        $stmtItem = $pdo->prepare("INSERT INTO import_items (container_id, shop_cd, shop_name, carton_no, box_qty, total_pcs) VALUES (?, ?, ?, ?, ?, ?)");
        $count = 0;
        foreach ($sheetData as $rowNum => $col) {
            if ($rowNum == 1 || empty($col['E'])) continue;
            $stmtItem->execute([$containerId, trim($col['A']), trim($col['C']), trim($col['E']), (int)$col['I'], (int)$col['K']]);
            $count++;
        }
        $pdo->commit();
        $message = "<div class='alert alert-success'>成功導入 <strong>$lotNo</strong>，共 $count 筆。</div>";
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $message = "<div class='alert alert-danger'>導入失敗: " . $e->getMessage() . "</div>";
    }
}
?>

<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sunrise - 物流管理中樞</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        body { background-color: #f0f2f5; font-family: "Microsoft JhengHei", sans-serif; }
        .status-badge { font-size: 0.9rem; padding: 6px 10px; border-radius: 20px; }
        .bg-gray { background-color: #95a5a6; color: white; }
        .bg-blue { background-color: #3498db; color: white; }
        .bg-green { background-color: #2ecc71; color: white; }
        .lot-link { cursor: pointer; text-decoration: none; font-weight: bold; color: #2980b9; border-bottom: 1px dashed #2980b9; }
        .lot-link:hover { color: #c0392b; border-bottom-color: #c0392b; }
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
            <i class="fas fa-file-upload"></i> 新增貨櫃導入 (Import)
        </div>
        <div class="card-body">
            <form action="sunrise.php" method="POST" enctype="multipart/form-data" class="row g-3 align-items-end">
                <div class="col-md-4">
                    <label class="form-label fw-bold">1. 設定貨櫃批號 (Lot No)</label>
                    <input type="text" name="lot_no" class="form-control" placeholder="如: 6778-15" required>
                </div>
                <div class="col-md-5">
                    <label class="form-label fw-bold">2. 上傳清單 (.xlsx / .csv)</label>
                    <input type="file" name="import_file" class="form-control" accept=".csv, .xls, .xlsx" required>
                </div>
                <div class="col-md-3">
                    <button type="submit" class="btn btn-primary w-100">執行導入</button>
                </div>
            </form>
        </div>
    </div>

    <div class="card shadow">
        <div class="card-header bg-secondary text-white d-flex justify-content-between align-items-center">
            <span><i class="fas fa-list-alt"></i> 貨櫃作業總表</span>
            <button class="btn btn-sm btn-light" onclick="location.reload()"><i class="fas fa-sync"></i> 刷新</button>
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
                            <th>已掃描</th>
                            <th>進度</th>
                            <th>狀態</th>
                            <th>操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $sql = "SELECT c.id, c.lot_no, c.status, c.created_at,
                                       COUNT(DISTINCT i.id) as total_items,
                                       COUNT(DISTINCT s.id) as scanned_items
                                FROM containers c
                                LEFT JOIN import_items i ON c.id = i.container_id
                                LEFT JOIN scan_records s ON c.id = s.container_id
                                GROUP BY c.id ORDER BY c.id DESC";
                        
                        $containers = $pdo->query($sql)->fetchAll();

                        if (count($containers) > 0):
                            foreach ($containers as $row):
                                // 狀態判斷
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
                                $percent = $row['total_items'] > 0 ? round(($row['scanned_items'] / $row['total_items']) * 100) : 0;
                        ?>
                        <tr>
                            <td><?php echo $row['id']; ?></td>
                            <td>
                                <a href="javascript:void(0);" 
                                   class="lot-link" 
                                   title="點擊查看詳細清單"
                                   onclick="openDetail(<?php echo $row['id']; ?>)">
                                   <i class="fas fa-search"></i> <?php echo htmlspecialchars($row['lot_no']); ?>
                                </a>
                            </td>
                            <td class="small text-muted"><?php echo $row['created_at']; ?></td>
                            <td><?php echo number_format($row['total_items']); ?></td>
                            <td><?php echo number_format($row['scanned_items']); ?></td>
                            <td>
                                <div class="progress" style="height: 15px;">
                                    <div class="progress-bar bg-info" style="width: <?php echo $percent; ?>%"><?php echo $percent; ?>%</div>
                                </div>
                            </td>
                            <td><?php echo $statusLabel; ?></td>
                            <td>
                                <?php if ($canDelete): ?>
                                    <form method="POST" onsubmit="return confirm('確定要刪除 [<?php echo $row['lot_no']; ?>] 嗎？\n此動作將清除所有相關數據！');">
                                        <input type="hidden" name="action" value="delete_container">
                                        <input type="hidden" name="container_id" value="<?php echo $row['id']; ?>">
                                        <button type="submit" class="btn btn-outline-danger btn-sm"><i class="fas fa-trash-alt"></i> 刪除</button>
                                    </form>
                                <?php else: ?>
                                    <button class="btn btn-secondary btn-sm" disabled><i class="fas fa-lock"></i> 鎖定</button>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; else: ?>
                            <tr><td colspan="8" class="text-muted p-4">暫無資料</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
    // 開啟明細視窗的 JavaScript 函式
    function openDetail(id) {
        // 設定視窗大小為 900x700
        window.open(
            'container_detail.php?id=' + id, 
            'ContainerDetail', 
            'width=900,height=700,scrollbars=yes,resizable=yes'
        );
    }
</script>

</body>
</html>