<?php
/**
 * 大創貨物分揀系統 - 貨櫃清單明細 (Popup View)
 * 檔案名稱: container_detail.php
 * 功能: 顯示特定貨櫃的店鋪統計總表
 */

// 1. 環境與資料庫連線
$host = '127.0.0.1'; $port = '3306'; $db = 'sunrise'; $user = 'alumi136'; $pass = 'Alumi!36';

try {
    $pdo = new PDO("mysql:host=$host;port=$port;dbname=$db;charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    die("DB Error: " . $e->getMessage());
}

// 2. 獲取貨櫃資訊
$containerId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($containerId === 0) die("無效的貨櫃 ID");

// 查詢貨櫃批號
$stmt = $pdo->prepare("SELECT lot_no FROM containers WHERE id = ?");
$stmt->execute([$containerId]);
$container = $stmt->fetch();
$lotNo = $container ? $container['lot_no'] : 'Unknown';

// 3. 執行統計查詢 (核心邏輯)
// 邏輯: 依店鋪分組，計算不重複箱號數、總Box、總Pcs
$sql = "SELECT shop_cd, 
               shop_name, 
               COUNT(DISTINCT carton_no) as total_cartons, 
               SUM(box_qty) as total_boxes, 
               SUM(total_pcs) as total_pcs
        FROM import_items 
        WHERE container_id = ?
        GROUP BY shop_cd, shop_name
        ORDER BY shop_cd ASC";

$stmt = $pdo->prepare($sql);
$stmt->execute([$containerId]);
$list = $stmt->fetchAll();

// 計算整櫃總計
$grandTotalCartons = 0;
$grandTotalBoxes = 0;
$grandTotalPcs = 0;
?>

<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>貨櫃清單: <?php echo htmlspecialchars($lotNo); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background-color: #fff; padding: 20px; font-family: "Microsoft JhengHei", sans-serif; }
        .table-header { background-color: #2c3e50; color: white; }
        .total-row { background-color: #f8f9fa; font-weight: bold; border-top: 2px solid #000; }
        @media print {
            .no-print { display: none; }
        }
    </style>
</head>
<body>

<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h3><i class="bi bi-box-seam"></i> 貨櫃清單明細</h3>
        <div class="no-print">
            <button class="btn btn-secondary" onclick="window.print()">列印清單</button>
            <button class="btn btn-outline-dark" onclick="window.close()">關閉視窗</button>
        </div>
    </div>

    <div class="alert alert-info">
        <strong>貨櫃批號 (Lot No):</strong> <?php echo htmlspecialchars($lotNo); ?>
    </div>

    <table class="table table-bordered table-striped text-center align-middle">
        <thead class="table-header">
            <tr>
                <th width="10%">店鋪號碼<br>(ShopCD)</th>
                <th width="30%">店鋪名稱<br>(ShopName)</th>
                <th width="20%">總箱數<br>(Total Carton)</th>
                <th width="20%">總 Box 數<br>(Total Box)</th>
                <th width="20%">總 Pcs 數<br>(Total Pcs)</th>
            </tr>
        </thead>
        <tbody>
            <?php if (count($list) > 0): ?>
                <?php foreach ($list as $row): 
                    $grandTotalCartons += $row['total_cartons'];
                    $grandTotalBoxes += $row['total_boxes'];
                    $grandTotalPcs += $row['total_pcs'];
                ?>
                <tr>
                    <td><?php echo $row['shop_cd']; ?></td>
                    <td class="text-start"><?php echo $row['shop_name']; ?></td>
                    <td><?php echo number_format($row['total_cartons']); ?></td>
                    <td><?php echo number_format($row['total_boxes']); ?></td>
                    <td><?php echo number_format($row['total_pcs']); ?></td>
                </tr>
                <?php endforeach; ?>
                
                <tr class="total-row">
                    <td colspan="2" class="text-end">本櫃總計 (Grand Total)：</td>
                    <td class="text-primary"><?php echo number_format($grandTotalCartons); ?></td>
                    <td class="text-primary"><?php echo number_format($grandTotalBoxes); ?></td>
                    <td class="text-danger"><?php echo number_format($grandTotalPcs); ?></td>
                </tr>

            <?php else: ?>
                <tr>
                    <td colspan="5" class="text-muted py-4">查無資料，請確認是否已正確導入清單。</td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

</body>
</html>