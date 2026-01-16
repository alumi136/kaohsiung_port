<?php
/**
 * 大創貨物分揀系統 - 現場作業端 (Daiso Operation) v8.0
 * 功能: 掃描分揀、防呆告警、溢卸處理、棧板管理
 */

// --- 1. 資料庫連線 ---
$host = '127.0.0.1'; $port = '3306'; $db = 'sunrise'; $user = 'alumi136'; $pass = 'Alumi!36';

try {
    $pdo = new PDO("mysql:host=$host;port=$port;dbname=$db;charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    // 若是 AJAX 請求則回傳 JSON 錯誤，否則顯示文字
    if (isset($_POST['action'])) {
        header('Content-Type: application/json');
        echo json_encode(['status' => 'error', 'msg' => '資料庫連線失敗']); 
        exit;
    }
    die("系統維護中 (DB Error)");
}

// ==========================================================
//  AJAX API 處理區 (處理前端請求)
// ==========================================================
if (isset($_POST['action'])) {
    header('Content-Type: application/json');
    $action = $_POST['action'];

    // API: 取得該貨櫃的店鋪清單
    if ($action == 'get_shops') {
        $cid = $_POST['container_id'];
        $sql = "SELECT DISTINCT shop_cd, shop_name FROM import_items WHERE container_id = ? ORDER BY shop_cd ASC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$cid]);
        echo json_encode($stmt->fetchAll());
        exit;
    }

    // API: 執行掃描與驗證
    if ($action == 'scan') {
        $barcode = trim($_POST['barcode']);
        $containerId = $_POST['container_id'];
        $selectedShopCd = $_POST['shop_cd'];
        $selectedPallet = $_POST['pallet_num'];
        
        try {
            // 1. 查詢資料庫是否有此箱號
            $stmt = $pdo->prepare("SELECT * FROM import_items WHERE container_id = ? AND carton_no = ? LIMIT 1");
            $stmt->execute([$containerId, $barcode]);
            $item = $stmt->fetch();

            // --- 狀況 A: 資料庫找不到 (可能為溢卸) ---
            if (!$item) {
                echo json_encode([
                    'status' => 'not_found',
                    'msg' => '資料庫無此箱號'
                ]);
                exit;
            }

            // --- 狀況 B: 找到資料，檢查店鋪是否相符 ---
            $correctShopName = $item['shop_name'];
            $correctShopCd = $item['shop_cd'];

            if ($correctShopCd != $selectedShopCd) {
                // [需求 3] 錯分告警 -> 記錄為異常件 (scan_type = 2)
                insertScanRecord($pdo, $containerId, $item['id'], $barcode, $selectedPallet, 2);

                echo json_encode([
                    'status' => 'warning',
                    'msg' => "錯誤！此箱屬於：\n{$correctShopCd} {$correctShopName}\n(已記錄為異常件)",
                    'log_str' => "棧板[$selectedPallet] $barcode (異常:錯分至 $correctShopName)"
                ]);
                exit;
            }

            // --- 狀況 C: 資料正確 ---
            // 記錄為正常 (scan_type = 0)
            insertScanRecord($pdo, $containerId, $item['id'], $barcode, $selectedPallet, 0);

            echo json_encode([
                'status' => 'success',
                'msg' => "正確：{$correctShopName}",
                'shop_name' => $correctShopName,
                'log_str' => "棧板[$selectedPallet] $barcode $correctShopName"
            ]);
            exit;

        } catch (Exception $e) {
            echo json_encode(['status' => 'error', 'msg' => $e->getMessage()]); exit;
        }
    }

    // API: 新增溢卸貨物
    if ($action == 'add_overage') {
        $barcode = trim($_POST['barcode']);
        $containerId = $_POST['container_id'];
        $selectedShopCd = $_POST['shop_cd'];
        $selectedShopName = $_POST['shop_name']; 
        $selectedPallet = $_POST['pallet_num'];

        $pdo->beginTransaction();
        try {
            // [需求 4] 新增到 import_items (標記 is_overage=1)
            // 因為是溢卸，我們假設 Box=1, Pcs=1 (或者您可以設為 0 待確認)
            $stmt = $pdo->prepare("INSERT INTO import_items (container_id, shop_cd, shop_name, carton_no, box_qty, total_pcs, is_overage) VALUES (?, ?, ?, ?, 1, 1, 1)");
            $stmt->execute([$containerId, $selectedShopCd, $selectedShopName, $barcode]);
            $newItemId = $pdo->lastInsertId();

            // 記錄掃描 (scan_type = 3 溢卸)
            insertScanRecord($pdo, $containerId, $newItemId, $barcode, $selectedPallet, 3);

            $pdo->commit();
            echo json_encode([
                'status' => 'success',
                'msg' => "已新增溢卸貨物：{$selectedShopName}",
                'log_str' => "棧板[$selectedPallet] $barcode $selectedShopName (溢卸)"
            ]);
        } catch (Exception $e) {
            $pdo->rollBack();
            echo json_encode(['status' => 'error', 'msg' => '新增失敗: ' . $e->getMessage()]);
        }
        exit;
    }
}

// 輔助函數: 寫入掃描紀錄表
function insertScanRecord($pdo, $cid, $itemId, $carton, $pallet, $type) {
    // scan_type: 0正常, 1破損, 2錯分, 3溢卸
    // 這裡使用 ON DUPLICATE KEY UPDATE 避免重複掃描導致 Error (視您的業務邏輯而定，若允許重複掃描則直接 INSERT)
    // 假設一箱只能被掃一次，若重複掃描則更新狀態
    $sql = "INSERT INTO scan_records (container_id, import_item_id, carton_no, pallet_num, scan_type, scanned_at) 
            VALUES (?, ?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE scanned_at = NOW(), scan_type = VALUES(scan_type), pallet_num = VALUES(pallet_num)";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$cid, $itemId, $carton, $pallet, $type]);
}
?>

<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DAISO 倉庫分揀系統</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
    <style>
        body { background-color: #f4f7f6; font-family: "Microsoft JhengHei", sans-serif; }
        .frame-container { display: flex; flex-direction: column; min-height: 100vh; }
        
        /* 頂部導航 */
        .header-panel { background: #2c3e50; color: white; padding: 10px 20px; display: flex; justify-content: space-between; align-items: center; }
        
        /* 主佈局: 左(設定) 中(操作) 右(紀錄) */
        .main-content { display: flex; flex: 1; overflow: hidden; }
        
        .left-panel { width: 320px; padding: 20px; background: #fff; border-right: 1px solid #ddd; overflow-y: auto; }
        .center-panel { flex: 1; padding: 30px; background: #ecf0f1; display: flex; flex-direction: column; align-items: center; }
        .right-panel { width: 350px; padding: 20px; background: #fff; border-left: 1px solid #ddd; overflow-y: auto; }

        /* 輸入框與結果顯示 */
        .scan-input { font-size: 2rem; text-align: center; width: 100%; max-width: 600px; margin-bottom: 20px; border: 4px solid #3498db; border-radius: 10px; padding: 10px; }
        .result-box { width: 100%; max-width: 600px; min-height: 250px; background: #fff; border-radius: 10px; padding: 30px; box-shadow: 0 5px 15px rgba(0,0,0,0.1); text-align: center; display: flex; flex-direction: column; justify-content: center; align-items: center; }
        
        .result-title { font-size: 2.5rem; font-weight: bold; margin-bottom: 15px; }
        .result-desc { font-size: 1.5rem; color: #555; }
        
        /* 紀錄列表樣式 */
        .log-item { padding: 12px; border-bottom: 1px solid #eee; font-size: 1.1rem; animation: fadeIn 0.5s; }
        .log-item.normal { border-left: 5px solid #2ecc71; }
        .log-item.error { border-left: 5px solid #e74c3c; background-color: #fadbd8; }
        .log-item.overage { border-left: 5px solid #f39c12; background-color: #fdebd0; }
        
        @keyframes fadeIn { from { opacity: 0; transform: translateY(-10px); } to { opacity: 1; transform: translateY(0); } }

        /* 手機 RWD */
        @media (max-width: 992px) {
            .main-content { flex-direction: column; }
            .left-panel, .right-panel { width: 100%; height: auto; border: none; padding: 15px; }
            .center-panel { min-height: 300px; }
            .scan-input { font-size: 1.5rem; }
        }
    </style>
</head>
<body>

<div class="frame-container">
    <div class="header-panel">
        <h4 class="m-0"><i class="bi bi-box-seam"></i> DAISO 分揀作業</h4>
        <div>
            </div>
    </div>

    <div class="main-content">
        <div class="left-panel shadow-sm">
            <h5 class="mb-3 text-primary"><i class="bi bi-gear-fill"></i> 作業設定</h5>
            <hr>
            
            <div class="mb-3">
                <label class="form-label fw-bold">1. 選擇貨櫃 (LotNo)</label>
                <select id="select_container" class="form-select form-select-lg">
                    <option value="">-- 請選擇 --</option>
                    <?php
                    // 只列出未結案的貨櫃
                    $stmt = $pdo->query("SELECT * FROM containers WHERE status = 0 ORDER BY id DESC");
                    while ($row = $stmt->fetch()) {
                        echo "<option value='{$row['id']}'>{$row['lot_no']}</option>";
                    }
                    ?>
                </select>
            </div>

            <div class="mb-3">
                <label class="form-label fw-bold">2. 選擇店鋪 (Shop)</label>
                <select id="select_shop" class="form-select form-select-lg" disabled>
                    <option value="">-- 請先選貨櫃 --</option>
                </select>
                <input type="hidden" id="selected_shop_name">
            </div>

            <div class="mb-3">
                <label class="form-label fw-bold">3. 選擇棧板 (Pallet)</label>
                <select id="select_pallet" class="form-select form-select-lg">
                    <?php 
                    for($i=1; $i<=20; $i++) { 
                        echo "<option value='$i'>棧板 [{$i}]</option>"; 
                    } 
                    ?>
                </select>
            </div>

            <div class="alert alert-secondary mt-4">
                <small><i class="bi bi-exclamation-circle"></i> 請依序選擇上方項目，確認無誤後再進行掃描。</small>
            </div>
        </div>

        <div class="center-panel">
            <label class="mb-2 fw-bold text-muted">請掃描箱號 (Carton No)</label>
            <input type="text" id="barcode" class="scan-input" placeholder="點擊此處掃描" autocomplete="off" autofocus>
            
            <div id="result_area" class="result-box">
                <div class="text-muted"><i class="bi bi-upc-scan" style="font-size: 3rem;"></i><br>等待掃描...</div>
            </div>
        </div>

        <div class="right-panel shadow-sm">
            <h5 class="mb-3 text-success"><i class="bi bi-list-check"></i> 作業紀錄</h5>
            <hr>
            <div id="scan_log">
                <div class="text-center text-muted mt-5">尚無紀錄</div>
            </div>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script>
    // --- 1. 貨櫃變更時，AJAX 載入店鋪 ---
    $('#select_container').change(function() {
        let cid = $(this).val();
        let $shopSelect = $('#select_shop');
        
        // 重置店鋪選單
        $shopSelect.html('<option value="">載入中...</option>').prop('disabled', true);
        
        if (cid) {
            $.post('daiso.php', { action: 'get_shops', container_id: cid }, function(data) {
                let html = '<option value="">-- 請選擇店鋪 --</option>';
                data.forEach(function(shop) {
                    html += `<option value="${shop.shop_cd}" data-name="${shop.shop_name}">${shop.shop_cd} ${shop.shop_name}</option>`;
                });
                $shopSelect.html(html).prop('disabled', false);
            }, 'json');
        } else {
            $shopSelect.html('<option value="">-- 請先選貨櫃 --</option>');
        }
    });

    // 當選擇店鋪時，紀錄店名到隱藏欄位，並聚焦輸入框
    $('#select_shop').change(function() {
        let name = $(this).find(':selected').data('name');
        $('#selected_shop_name').val(name);
        $('#barcode').focus();
    });

    // --- 2. 監聽掃描 (Enter 鍵) ---
    $('#barcode').keypress(function(e) {
        if (e.which == 13) { // Enter key
            let code = $(this).val().trim();
            if(code) handleScan(code);
            $(this).val(''); // 清空輸入框
        }
    });

    // --- 3. 處理掃描邏輯 ---
    function handleScan(code) {
        let cid = $('#select_container').val();
        let shopCd = $('#select_shop').val();
        let shopName = $('#selected_shop_name').val();
        let pallet = $('#select_pallet').val();

        // 前端防呆：確認已選取必要條件
        if (!cid || !shopCd) {
            alert('請先在左側選擇 [貨櫃] 與 [店鋪]');
            return;
        }

        $.post('daiso.php', {
            action: 'scan',
            barcode: code,
            container_id: cid,
            shop_cd: shopCd,
            pallet_num: pallet
        }, function(res) {
            let $result = $('#result_area');
            
            // 狀態判斷
            if (res.status === 'success') {
                // 正確
                $result.html(`
                    <div class="result-title text-success"><i class="bi bi-check-circle"></i> 正確</div>
                    <div class="result-desc fw-bold">${res.msg}</div>
                `);
                addLog(res.log_str, 'normal');
            } 
            else if (res.status === 'warning') {
                // [需求 3] 錯分告警
                $result.html(`
                    <div class="result-title text-danger"><i class="bi bi-exclamation-triangle-fill"></i> 異常警報</div>
                    <div class="result-desc text-danger fw-bold">${res.msg.replace(/\n/g, '<br>')}</div>
                `);
                addLog(res.log_str, 'error');
                // 播放音效 (瀏覽器需允許)
                // new Audio('error.mp3').play().catch(e=>{}); 
            }
            else if (res.status === 'not_found') {
                // [需求 4] 溢卸確認流程
                $result.html(`<div class="result-title text-warning">❓ 查無此箱</div>`);
                
                // 延遲一點點跳 Alert 以免擋住介面更新
                setTimeout(function() {
                    if (confirm(`箱號 [${code}] 不在清單中。\n\n是否將其新增為 [${shopName}] 的溢卸貨物？`)) {
                        addOverage(code, cid, shopCd, shopName, pallet);
                    } else {
                        $result.html(`<div class="text-muted">已取消操作</div>`);
                    }
                }, 100);
            }
        }, 'json').fail(function() {
            alert('系統連線錯誤，請檢查網路');
        });
    }

    // --- 4. 新增溢卸 AJAX ---
    function addOverage(code, cid, shopCd, shopName, pallet) {
        $.post('daiso.php', {
            action: 'add_overage',
            barcode: code,
            container_id: cid,
            shop_cd: shopCd,
            shop_name: shopName,
            pallet_num: pallet
        }, function(res) {
            if (res.status === 'success') {
                $('#result_area').html(`
                    <div class="result-title text-primary">新增成功</div>
                    <div class="result-desc">${res.msg}</div>
                `);
                addLog(res.log_str, 'overage');
            } else {
                alert(res.msg);
            }
        }, 'json');
    }

    // --- 5. 更新右側紀錄 (Log) ---
    function addLog(text, type) {
        // 移除"尚無紀錄"文字
        if ($('#scan_log .text-center').length) $('#scan_log').empty();

        let timestamp = new Date().toLocaleTimeString('zh-TW', {hour:'2-digit', minute:'2-digit', second:'2-digit'});
        let html = `
            <div class="log-item ${type}">
                <div class="d-flex justify-content-between">
                    <small class="text-muted">${timestamp}</small>
                </div>
                <div>${text}</div>
            </div>`;
        $('#scan_log').prepend(html);
    }

    // --- 6. 自動聚焦 (可選，防止掃描槍失焦) ---
    setInterval(() => {
        if(!$('#barcode').is(':focus') && !window.blockFocus) {
            // $('#barcode').focus(); // 若干擾手動輸入可註解此行
        }
    }, 2000);
</script>

</body>
</html>