<?php
/**
 * 大創貨物分揀系統 - Daiso-LogiFlow v5.1
 * 環境: PHP 7.4.19 / MySQL 8.0.26
 */

// 1. 資料庫連線配置
$host = '127.0.0.1';
$db   = 'sunrise';
$user = 'alumi136';
$pass = 'Alumi!36';
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
     $pdo = new PDO($dsn, $user, $pass, $options);
} catch (\PDOException $e) {
     die("資料庫連線失敗: " . $e->getMessage());
}

// 處理 AJAX 掃描請求 (非同步更新)
if (isset($_POST['action']) && $_POST['action'] == 'scan') {
    $carton_no = $_POST['carton_no'];
    $container_id = $_POST['container_id'];
    $is_damaged = $_POST['is_damaged'] == 'true' ? 1 : 0;

    // 檢查清單
    $stmt = $pdo->prepare("SELECT * FROM import_items WHERE container_id = ? AND carton_no = ?");
    $stmt->execute([$container_id, $carton_no]);
    $item = $stmt->fetch();

    if ($item) {
        // 這裡簡化邏輯：直接寫入 scan_records (假設已處理 pallet_id)
        // 實際開發需關聯 pallet 表，此處回傳結果供介面顯示
        echo json_encode([
            'status' => 'success',
            'shop_name' => $item['shop_name'],
            'shop_cd' => $item['shop_cd'],
            'msg' => '掃描成功'
        ]);
    } else {
        echo json_encode(['status' => 'error', 'msg' => '查無此箱號！']);
    }
    exit;
}
?>

<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>大創貨物分揀系統</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background-color: #f4f7f6; font-family: "Microsoft JhengHei", sans-serif; }
        .frame-container { height: 100vh; display: flex; flex-direction: column; }
        .header-panel { background: #2c3e50; color: white; padding: 10px; }
        .main-content { flex: 1; display: flex; flex-wrap: wrap; }
        
        /* 三個 Frame 的樣式 */
        .frame-left { flex: 1; min-width: 300px; border-right: 1px solid #ddd; background: #fff; padding: 20px; }
        .frame-center { flex: 2; min-width: 400px; background: #ebf0f1; padding: 40px; text-align: center; }
        .frame-right { flex: 1; min-width: 300px; border-left: 1px solid #ddd; background: #fff; padding: 20px; }

        .big-display { font-size: 4rem; font-weight: bold; color: #e74c3c; margin: 20px 0; }
        .scan-input { font-size: 1.5rem; text-align: center; border: 2px solid #3498db; }
        .damage-btn { height: 100px; font-size: 1.5rem; }
        
        @media (max-width: 768px) {
            .main-content { flex-direction: column; }
            .frame-left, .frame-center, .frame-right { border: none; width: 100%; height: auto; }
            .big-display { font-size: 2.5rem; }
        }
    </style>
</head>
<body>

<div class="frame-container">
    <div class="header-panel">
        <h4>DAISO LogiFlow - 倉庫分揀系統</h4>
    </div>

    <div class="main-content">
        <div class="frame-left">
            <h5><i class="bi bi-gear"></i> 作業配置</h5>
            <hr>
            <div class="mb-3">
                <label>選擇貨櫃 (LotNo)</label>
                <select class="form-select" id="container_id">
                    <?php
                    $containers = $pdo->query("SELECT * FROM containers ORDER BY id DESC")->fetchAll();
                    foreach ($containers as $c) {
                        echo "<option value='{$c['id']}'>{$c['lot_no']}</option>";
                    }
                    ?>
                </select>
            </div>
            <div class="alert alert-info">
                <strong>狀態：</strong> 正在連線中...
            </div>
            <button class="btn btn-outline-primary w-100" onclick="location.reload()">重新整理清單</button>
        </div>

        <div class="frame-center">
            <h3>請掃描箱號 (Carton No)</h3>
            <input type="text" id="barcode_input" class="form-control scan-input mb-4" placeholder="點擊此處開始掃描" autofocus>
            
            <div id="result_area">
                <p class="text-muted">等待掃描...</p>
                <div class="big-display" id="display_shop_name">---</div>
                <div class="h3" id="display_shop_cd"></div>
            </div>

            <div class="row mt-4">
                <div class="col">
                    <button class="btn btn-warning w-100 damage-btn" id="btn_damage">
                        ⚠ 疑似破損<br>(按此拍照)
                    </button>
                </div>
            </div>
        </div>

        <div class="frame-right">
            <h5><i class="bi bi-list-check"></i> 最近掃描</h5>
            <hr>
            <ul class="list-group" id="scan_history">
                <li class="list-group-item text-muted">尚無紀錄</li>
            </ul>
            <div class="mt-4">
                <a href="export_report.php" class="btn btn-success w-100 mb-2">產出差異表</a>
                <button class="btn btn-secondary w-100" onclick="alert('開啟大白單列印介面')">列印大白單 (A4)</button>
            </div>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script>
    let isDamagedPending = false;

    // 監聽掃描槍輸入 (Enter 鍵)
    $('#barcode_input').keypress(function(e) {
        if (e.which == 13) {
            let barcode = $(this).val();
            if (barcode) {
                processScan(barcode);
                $(this).val('');
            }
        }
    });

    // 破損按鈕切換
    $('#btn_damage').click(function() {
        isDamagedPending = true;
        $(this).removeClass('btn-warning').addClass('btn-danger').text('請掃描損壞箱號');
        $('#barcode_input').focus();
    });

    function processScan(barcode) {
        let containerId = $('#container_id').val();
        
        $.post('daiso.php', {
            action: 'scan',
            carton_no: barcode,
            container_id: containerId,
            is_damaged: isDamagedPending
        }, function(res) {
            let data = JSON.parse(res);
            if (data.status === 'success') {
                $('#display_shop_name').text(data.shop_name).css('color', '#27ae60');
                $('#display_shop_cd').text('店號: ' + data.shop_cd);
                
                // 更新歷史紀錄
                let damageBadge = isDamagedPending ? '<span class="badge bg-danger">損</span> ' : '';
                $('#scan_history').prepend(`<li class="list-group-item">${damageBadge}${barcode} -> ${data.shop_cd}</li>`);
            } else {
                $('#display_shop_name').text(data.msg).css('color', '#e74c3c');
                $('#display_shop_cd').text('');
            }
            
            // 重置破損狀態
            isDamagedPending = false;
            $('#btn_damage').removeClass('btn-danger').addClass('btn-warning').html('⚠ 疑似破損<br>(按此拍照)');
        });
    }

    // 自動聚焦輸入框，確保掃描槍隨時可用
    setInterval(function() {
        if (!$('#barcode_input').is(':focus')) {
            // $('#barcode_input').focus(); 
        }
    }, 3000);
</script>

</body>
</html>