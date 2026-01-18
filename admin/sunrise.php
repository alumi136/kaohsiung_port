<?php
/**
 * 大創貨物分揀系統 - 管理核心 (Sunrise Core) v12.0
 * 修正: UI 視窗高度、大白單 A4 分頁置中、疊板明細直立合併顯示
 */

// --- 錯誤報告與環境設定 ---
ini_set('display_errors', 1);
error_reporting(E_ALL);
ini_set('memory_limit', '512M'); 

$autoloadPath = '/var/www/html/excel/vendor/autoload.php';
if (file_exists($autoloadPath)) {
    require $autoloadPath;
} else {
    die("系統錯誤：找不到 Excel 模組 ($autoloadPath)");
}

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup; // 引入頁面設定
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

// ==========================================================
//  功能模組: Excel 報表輸出 (Export Actions)
// ==========================================================
if (isset($_POST['action']) && strpos($_POST['action'], 'export_') === 0) {
    $cid = (int)$_POST['container_id'];
    $type = $_POST['action'];

    // 取得貨櫃基本資料
    $stmt = $pdo->prepare("SELECT * FROM containers WHERE id = ?");
    $stmt->execute([$cid]);
    $container = $stmt->fetch();
    $lotNo = $container['lot_no'];
    $closeTime = $container['updated_at'] ?? date('Y-m-d H:i'); 

    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $filename = "Report.xlsx";

    // ------------------------------------------------------
    //  報表 1: 總表 (維持原有邏輯)
    // ------------------------------------------------------
    if ($type == 'export_summary') {
        $filename = "總表_{$lotNo}.xlsx";
        $sheet->setTitle('總表');
        // 設定 A4 橫印
        $sheet->getPageSetup()->setPaperSize(PageSetup::PAPERSIZE_A4);
        $sheet->getPageSetup()->setOrientation(PageSetup::ORIENTATION_LANDSCAPE);

        $headers = ['ShopCD', 'ShopName', 'Total Carton (實掃)', 'Total Box (應到)', 'Total Pcs (應到)', '備註'];
        $sheet->fromArray($headers, NULL, 'A1');
        $sheet->getStyle('A1:F1')->getFont()->setBold(true);
        $sheet->getColumnDimension('B')->setWidth(30);
        $sheet->getColumnDimension('F')->setWidth(40);

        $sql = "
            SELECT 
                i.shop_cd, 
                i.shop_name,
                COUNT(DISTINCT s.carton_no) as scanned_cartons,
                SUM(CASE WHEN i.is_overage = 0 THEN i.box_qty ELSE 0 END) as total_box,
                SUM(CASE WHEN i.is_overage = 0 THEN i.total_pcs ELSE 0 END) as total_pcs,
                COUNT(DISTINCT CASE WHEN i.is_overage = 0 THEN i.carton_no END) as expected_cartons,
                COUNT(DISTINCT CASE WHEN s.scan_type = 3 THEN s.carton_no END) as overage_count,
                COUNT(DISTINCT CASE WHEN s.scan_type = 1 THEN s.carton_no END) as damaged_count
            FROM import_items i
            LEFT JOIN scan_records s ON i.id = s.import_item_id
            WHERE i.container_id = ?
            GROUP BY i.shop_cd, i.shop_name
            ORDER BY i.shop_cd ASC
        ";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$cid]);
        $rows = $stmt->fetchAll();

        $rowIndex = 2;
        foreach ($rows as $row) {
            $missing = $row['expected_cartons'] - ($row['scanned_cartons'] - $row['overage_count']);
            $missing = $missing < 0 ? 0 : $missing; 

            $remarks = [];
            if ($row['overage_count'] > 0) $remarks[] = "溢卸:{$row['overage_count']}箱";
            if ($row['damaged_count'] > 0) $remarks[] = "破損:{$row['damaged_count']}箱";
            if ($missing > 0) $remarks[] = "未到:{$missing}箱";

            $sheet->setCellValue("A$rowIndex", $row['shop_cd']);
            $sheet->setCellValue("B$rowIndex", $row['shop_name']);
            $sheet->setCellValue("C$rowIndex", $row['scanned_cartons']);
            $sheet->setCellValue("D$rowIndex", $row['total_box']);
            $sheet->setCellValue("E$rowIndex", $row['total_pcs']);
            $sheet->setCellValue("F$rowIndex", implode(', ', $remarks));
            $rowIndex++;
        }
    }

    // ------------------------------------------------------
    //  報表 2: 大白單 (Pallet Labels) - A4 嚴格格式 + 置中
    // ------------------------------------------------------
    elseif ($type == 'export_labels') {
        $filename = "大白單_{$lotNo}.xlsx";
        $sheet->setTitle('大白單');
        
        // 設定 A4 直印
        $sheet->getPageSetup()->setPaperSize(PageSetup::PAPERSIZE_A4);
        $sheet->getPageSetup()->setOrientation(PageSetup::ORIENTATION_PORTRAIT);
        
        // 取得資料
        $shopOrderSql = "SELECT DISTINCT shop_cd FROM import_items WHERE container_id = ? ORDER BY shop_cd ASC";
        $stmt = $pdo->prepare($shopOrderSql);
        $stmt->execute([$cid]);
        $shopOrders = array_flip($stmt->fetchAll(PDO::FETCH_COLUMN)); 

        $sql = "
            SELECT i.shop_cd, i.shop_name, s.pallet_num, COUNT(s.id) as carton_count
            FROM scan_records s
            JOIN import_items i ON s.import_item_id = i.id
            WHERE s.container_id = ?
            GROUP BY i.shop_cd, i.shop_name, s.pallet_num
            ORDER BY i.shop_cd ASC, s.pallet_num ASC
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$cid]);
        $pallets = $stmt->fetchAll();

        $currentRow = 1;
        foreach ($pallets as $p) {
            $shopIndex = isset($shopOrders[$p['shop_cd']]) ? ($shopOrders[$p['shop_cd']] + 1) : '?';
            $palletLabel = "{$shopIndex}-{$p['pallet_num']}";

            // 1. 馬來西亞直送櫃
            $sheet->mergeCells("A{$currentRow}:B{$currentRow}");
            $sheet->setCellValue("A{$currentRow}", "馬來西亞直送櫃");
            $sheet->getStyle("A{$currentRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $sheet->getStyle("A{$currentRow}")->getFont()->setBold(true)->setSize(24); // 字體加大

            // 2. 門市
            $sheet->setCellValue("A" . ($currentRow + 1), "門市:");
            $sheet->setCellValue("B" . ($currentRow + 1), $p['shop_name']);
            $sheet->getStyle("A" . ($currentRow + 1))->getFont()->setSize(16);
            $sheet->getStyle("B" . ($currentRow + 1))->getFont()->setSize(18);

            // 3. 板號
            $sheet->setCellValue("A" . ($currentRow + 2), "板號:");
            $sheet->setCellValue("B" . ($currentRow + 2), $palletLabel);
            $sheet->getStyle("A" . ($currentRow + 2))->getFont()->setSize(16);
            $sheet->getStyle("B" . ($currentRow + 2))->getFont()->setBold(true)->setSize(36); // 板號特大

            // 4. 件數 (數值置中)
            $sheet->setCellValue("A" . ($currentRow + 3), "件數:");
            $sheet->setCellValue("B" . ($currentRow + 3), $p['carton_count']);
            $sheet->getStyle("A" . ($currentRow + 3))->getFont()->setSize(16);
            $sheet->getStyle("B" . ($currentRow + 3))->getFont()->setSize(20);
            $sheet->getStyle("B" . ($currentRow + 3))->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER); // [需求2] 置中

            // 邊框
            $styleArray = [
                'borders' => ['outline' => ['borderStyle' => Border::BORDER_THICK]],
                'alignment' => ['vertical' => Alignment::VERTICAL_CENTER]
            ];
            $sheet->getStyle("A{$currentRow}:B" . ($currentRow + 3))->applyFromArray($styleArray);

            // 調整列高，讓它看起來像一張完整的單子
            $sheet->getRowDimension($currentRow)->setRowHeight(40);
            $sheet->getRowDimension($currentRow+1)->setRowHeight(40);
            $sheet->getRowDimension($currentRow+2)->setRowHeight(60);
            $sheet->getRowDimension($currentRow+3)->setRowHeight(40);

            // 設定分頁符號，確保一張紙一個標籤
            $sheet->setBreak("A" . ($currentRow + 4), \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet::BREAK_ROW);

            $currentRow += 5; // 下一張標籤起始列
        }
        
        $sheet->getColumnDimension('A')->setWidth(15);
        $sheet->getColumnDimension('B')->setWidth(50);
    }

    // ------------------------------------------------------
    //  報表 3: 疊板明細 (Stacking Details) - A4 + 垂直合併
    // ------------------------------------------------------
    elseif ($type == 'export_details') {
        $filename = "疊板明細_{$lotNo}.xlsx";
        $sheet->setTitle('疊板明細');
        
        // 設定 A4 直印
        $sheet->getPageSetup()->setPaperSize(PageSetup::PAPERSIZE_A4);

        $totalSql = "SELECT COUNT(id) FROM scan_records WHERE container_id = ?";
        $stmt = $pdo->prepare($totalSql);
        $stmt->execute([$cid]);
        $grandTotal = $stmt->fetchColumn();

        // Header 資訊
        $sheet->setCellValue('A1', '類型'); $sheet->setCellValue('B1', '馬來西亞直送櫃');
        $sheet->setCellValue('A2', '拆櫃日期'); $sheet->setCellValue('B2', $closeTime);
        $sheet->setCellValue('A3', '櫃號'); $sheet->setCellValue('B3', $lotNo);
        $sheet->setCellValue('A4', '尺寸'); $sheet->setCellValue('B4', '40Ft');
        $sheet->setCellValue('A5', '貨運公司'); $sheet->setCellValue('B5', '昇洋物流');
        $sheet->setCellValue('A6', '總件數'); $sheet->setCellValue('B6', $grandTotal);

        $headers = ['門市名', '店鋪代號', '總板數', '總件數', '板號明細', '對應件數'];
        $sheet->fromArray($headers, NULL, 'A8');
        $sheet->getStyle('A8:F8')->getFont()->setBold(true);
        $sheet->getStyle('A8:F8')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        // 資料準備
        $shopOrderSql = "SELECT DISTINCT shop_cd FROM import_items WHERE container_id = ? ORDER BY shop_cd ASC";
        $stmt = $pdo->prepare($shopOrderSql);
        $stmt->execute([$cid]);
        $shopOrders = array_flip($stmt->fetchAll(PDO::FETCH_COLUMN));

        $sql = "
            SELECT i.shop_cd, i.shop_name, s.pallet_num, COUNT(s.id) as count
            FROM scan_records s
            JOIN import_items i ON s.import_item_id = i.id
            WHERE s.container_id = ?
            GROUP BY i.shop_cd, i.shop_name, s.pallet_num
            ORDER BY i.shop_cd ASC, s.pallet_num ASC
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$cid]);
        $raw = $stmt->fetchAll();

        // 整理資料結構
        $shops = [];
        foreach ($raw as $r) {
            if (!isset($shops[$r['shop_cd']])) {
                $shops[$r['shop_cd']] = [
                    'name' => $r['shop_name'], 
                    'pallets' => [], 
                    'total_count' => 0,
                    'shop_index' => isset($shopOrders[$r['shop_cd']]) ? ($shopOrders[$r['shop_cd']] + 1) : '?'
                ];
            }
            $palletLabel = "{$shops[$r['shop_cd']]['shop_index']}-{$r['pallet_num']}";
            $shops[$r['shop_cd']]['pallets'][] = [
                'label' => $palletLabel,
                'count' => $r['count']
            ];
            $shops[$r['shop_cd']]['total_count'] += $r['count'];
        }

        // [需求3] 直立式顯示並合併
        $rowIdx = 9;
        foreach ($shops as $cd => $data) {
            $countPallets = count($data['pallets']);
            $startRow = $rowIdx;
            
            // 逐列印出板號明細
            foreach ($data['pallets'] as $pInfo) {
                $sheet->setCellValue("E$rowIdx", $pInfo['label']);
                $sheet->setCellValue("F$rowIdx", $pInfo['count']);
                $sheet->getStyle("E$rowIdx:F$rowIdx")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
                $rowIdx++;
            }
            $endRow = $rowIdx - 1;

            // 垂直合併左側欄位
            if ($endRow >= $startRow) {
                $sheet->mergeCells("A$startRow:A$endRow");
                $sheet->mergeCells("B$startRow:B$endRow");
                $sheet->mergeCells("C$startRow:C$endRow");
                $sheet->mergeCells("D$startRow:D$endRow");
            }

            // 填入店鋪匯總資訊
            $sheet->setCellValue("A$startRow", $data['name']);
            $sheet->setCellValue("B$startRow", $cd);
            $sheet->setCellValue("C$startRow", $countPallets);
            $sheet->setCellValue("D$startRow", $data['total_count']);

            // 設定左側垂直置中
            $sheet->getStyle("A$startRow:D$endRow")->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
            $sheet->getStyle("A$startRow:D$endRow")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        }
        
        $sheet->getColumnDimension('A')->setWidth(25);
        $sheet->getColumnDimension('B')->setWidth(15);
        $sheet->getColumnDimension('C')->setWidth(10);
        $sheet->getColumnDimension('D')->setWidth(10);
        $sheet->getColumnDimension('E')->setWidth(15);
        $sheet->getColumnDimension('F')->setWidth(10);
    }

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment;filename="'.$filename.'"');
    header('Cache-Control: max-age=0');
    $writer = new Xlsx($spreadsheet);
    $writer->save('php://output');
    exit;
}

// ==========================================================
//  下方 Action 保持不變
// ==========================================================
// 刪除
if (isset($_POST['action']) && $_POST['action'] == 'delete_container') {
    $delId = (int)$_POST['container_id'];
    try {
        $check = $pdo->prepare("SELECT status FROM containers WHERE id = ?");
        $check->execute([$delId]);
        $row = $check->fetch();
        if (!$row || $row['status'] == 1) throw new Exception("狀態為 [已完成] 禁止刪除。");

        $pdo->beginTransaction();
        $pdo->prepare("DELETE FROM scan_records WHERE container_id = ?")->execute([$delId]);
        $pdo->prepare("DELETE FROM import_items WHERE container_id = ?")->execute([$delId]);
        $pdo->prepare("DELETE FROM pallets WHERE container_id = ?")->execute([$delId]); 
        $pdo->prepare("DELETE FROM containers WHERE id = ?")->execute([$delId]);
        $pdo->commit();
        $message = "<div class='alert alert-success'>刪除成功。</div>";
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $message = "<div class='alert alert-danger'>刪除失敗: " . $e->getMessage() . "</div>";
    }
}
// 結案
if (isset($_POST['action']) && $_POST['action'] == 'close_container') {
    $closeId = (int)$_POST['container_id'];
    try {
        $pdo->prepare("UPDATE containers SET status = 1, updated_at = NOW() WHERE id = ?")->execute([$closeId]);
        $message = "<div class='alert alert-success'>貨櫃已成功結案！</div>";
    } catch (Exception $e) {
        $message = "<div class='alert alert-danger'>結案失敗: " . $e->getMessage() . "</div>";
    }
}
// 導入
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_FILES['import_file'])) {
    $lotNo = trim($_POST['lot_no']);
    try {
        if (empty($lotNo)) throw new Exception("請輸入 Lot No");
        $checkDup = $pdo->prepare("SELECT COUNT(*) FROM containers WHERE lot_no = ?");
        $checkDup->execute([$lotNo]);
        if ($checkDup->fetchColumn() > 0) throw new Exception("貨櫃批號已存在！");

        $file = $_FILES['import_file'];
        $spreadsheet = IOFactory::load($file['tmp_name']);
        $sheetData = $spreadsheet->getActiveSheet()->toArray(null, true, true, true);

        $pdo->beginTransaction();
        $pdo->prepare("INSERT INTO containers (lot_no, status) VALUES (?, 0)")->execute([$lotNo]);
        $containerId = $pdo->lastInsertId();
        $stmtItem = $pdo->prepare("INSERT INTO import_items (container_id, shop_cd, shop_name, carton_no, box_qty, total_pcs, is_overage) VALUES (?, ?, ?, ?, ?, ?, 0)");
        
        $count = 0;
        foreach ($sheetData as $rowNum => $col) {
            if ($rowNum == 1 || empty($col['E'])) continue;
            $stmtItem->execute([$containerId, trim($col['A']), trim($col['C']), trim($col['E']), (int)$col['I'], (int)$col['K']]);
            $count++;
        }
        $pdo->commit();
        $message = "<div class='alert alert-success'>導入成功，共 $count 筆。</div>";
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
        
        /* [需求1] 增加表格容器高度，避免下拉選單被遮擋 */
        .table-responsive {
            min-height: 600px; 
            overflow-y: auto;
            padding-bottom: 150px; /* 預留底部空間給下拉選單 */
        }
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
            <i class="fas fa-file-upload"></i> 新增貨櫃導入
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

    <div class="card shadow mb-5">
        <div class="card-header bg-secondary text-white d-flex justify-content-between align-items-center">
            <span><i class="fas fa-list-alt"></i> 貨櫃作業總表</span>
            <button class="btn btn-sm btn-light" onclick="location.reload()"><i class="fas fa-sync"></i> 刷新</button>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover table-striped align-middle mb-0 text-center">
                    <thead class="table-light sticky-top">
                        <tr>
                            <th>ID</th>
                            <th>貨櫃批號 (LotNo)</th>
                            <th>導入時間</th>
                            <th>應到總箱數</th>
                            <th>實掃總箱數</th>
                            <th>進度</th>
                            <th>狀態</th>
                            <th>操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        // 應到總箱數排除溢卸
                        $sql = "SELECT c.id, c.lot_no, c.status, c.created_at,
                                       COUNT(DISTINCT CASE WHEN i.is_overage = 0 THEN i.carton_no END) as expected_cartons,
                                       COUNT(DISTINCT s.carton_no) as scanned_cartons
                                FROM containers c
                                LEFT JOIN import_items i ON c.id = i.container_id
                                LEFT JOIN scan_records s ON c.id = s.container_id
                                GROUP BY c.id ORDER BY c.id DESC";
                        
                        $containers = $pdo->query($sql)->fetchAll();

                        if (count($containers) > 0):
                            foreach ($containers as $row):
                                if ($row['status'] == 1) {
                                    $statusLabel = '<span class="status-badge bg-green">已完成</span>';
                                } elseif ($row['scanned_cartons'] > 0) {
                                    $statusLabel = '<span class="status-badge bg-blue">作業中</span>';
                                } else {
                                    $statusLabel = '<span class="status-badge bg-gray">未作業</span>';
                                }
                                $percent = $row['expected_cartons'] > 0 ? round(($row['scanned_cartons'] / $row['expected_cartons']) * 100) : 0;
                        ?>
                        <tr>
                            <td><?php echo $row['id']; ?></td>
                            <td>
                                <a href="javascript:void(0);" class="lot-link" onclick="openDetail(<?php echo $row['id']; ?>)">
                                   <i class="fas fa-search"></i> <?php echo htmlspecialchars($row['lot_no']); ?>
                                </a>
                            </td>
                            <td class="small text-muted"><?php echo $row['created_at']; ?></td>
                            <td class="fw-bold"><?php echo number_format($row['expected_cartons']); ?></td>
                            <td class="fw-bold text-primary"><?php echo number_format($row['scanned_cartons']); ?></td>
                            <td>
                                <div class="progress" style="height: 15px;">
                                    <div class="progress-bar bg-info" style="width: <?php echo $percent; ?>%"><?php echo $percent; ?>%</div>
                                </div>
                            </td>
                            <td><?php echo $statusLabel; ?></td>
                            <td>
                                <?php if ($row['status'] == 0): ?>
                                    <form method="POST" style="display:inline-block;" onsubmit="return confirm('確定結案？');">
                                        <input type="hidden" name="action" value="close_container">
                                        <input type="hidden" name="container_id" value="<?php echo $row['id']; ?>">
                                        <button type="submit" class="btn btn-success btn-sm"><i class="fas fa-check"></i> 結案</button>
                                    </form>
                                    <form method="POST" style="display:inline-block;" onsubmit="return confirm('確定刪除？');">
                                        <input type="hidden" name="action" value="delete_container">
                                        <input type="hidden" name="container_id" value="<?php echo $row['id']; ?>">
                                        <button type="submit" class="btn btn-outline-danger btn-sm"><i class="fas fa-trash-alt"></i></button>
                                    </form>
                                <?php else: ?>
                                    <div class="btn-group">
                                        <button type="button" class="btn btn-primary btn-sm dropdown-toggle" data-bs-toggle="dropdown">
                                            <i class="fas fa-print"></i> 進行輸出
                                        </button>
                                        <ul class="dropdown-menu dropdown-menu-end">
                                            <li>
                                                <form method="POST" target="_blank">
                                                    <input type="hidden" name="action" value="export_summary">
                                                    <input type="hidden" name="container_id" value="<?php echo $row['id']; ?>">
                                                    <button class="dropdown-item" type="submit">1. 輸出總表</button>
                                                </form>
                                            </li>
                                            <li>
                                                <form method="POST" target="_blank">
                                                    <input type="hidden" name="action" value="export_labels">
                                                    <input type="hidden" name="container_id" value="<?php echo $row['id']; ?>">
                                                    <button class="dropdown-item" type="submit">2. 輸出大白單 (A4)</button>
                                                </form>
                                            </li>
                                            <li>
                                                <form method="POST" target="_blank">
                                                    <input type="hidden" name="action" value="export_details">
                                                    <input type="hidden" name="container_id" value="<?php echo $row['id']; ?>">
                                                    <button class="dropdown-item" type="submit">3. 輸出疊板明細 (A4)</button>
                                                </form>
                                            </li>
                                        </ul>
                                    </div>
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

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
    function openDetail(id) {
        window.open('container_detail.php?id=' + id, 'ContainerDetail', 'width=900,height=700,scrollbars=yes,resizable=yes');
    }
</script>

</body>
</html>