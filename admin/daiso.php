<?php
/**
 * 大創貨物分揀系統 - 現場作業端 (Daiso Operation) v8.1 Fix
 * 修正: 資料庫寫入失敗無回饋問題、加入詳細錯誤日誌
 */

// 強制關閉頁面上的錯誤輸出，改為回傳 JSON 錯誤
error_reporting(E_ALL);
ini_set('display_errors', 0); 

// --- 1. 資料庫連線 ---
$host = '127.0.0.1'; $port = '3306'; $db = 'sunrise'; $user = 'alumi136'; $pass = 'Alumi!36';

try {
    $pdo = new PDO("mysql:host=$host;port=$port;dbname=$db;charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    jsonResponse('error', 'DB連線失敗: ' . $e->getMessage());
}

// 輔助函數: 回傳 JSON 並結束
function jsonResponse($status, $msg, $logStr = '', $shopName = '') {
    header('Content-Type: application/json');
    echo json_encode([
        'status' => $status,
        'msg' => $msg,
        'log_str' => $logStr,
        'shop_name' => $shopName
    ]);
    exit;
}

// ==========================================================
//  AJAX API 處理區
// ==========================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    // API: 取得該貨櫃的店鋪清單
    if ($action == 'get_shops') {
        $cid = $_POST['container_id'];
        $stmt = $pdo->prepare("SELECT DISTINCT shop_cd, shop_name FROM import_items WHERE container_id = ? ORDER BY shop_cd ASC");
        $stmt->execute([$cid]);
        echo json_encode($stmt->fetchAll());
        exit;
    }

    // API: 執行掃描
    if ($action == 'scan') {
        $barcode = trim($_POST['barcode']);
        $containerId = $_POST['container_id'];
        $selectedShopCd = $_POST['shop_cd'];
        $selectedPallet = (int)$_POST['pallet_num']; // 強制轉為數字
        
        if (empty($barcode) || empty($containerId)) {
            jsonResponse('error', '資料不完整 (箱號或貨櫃ID遺失)');
        }

        try {
            // 1. 查詢資料庫是否有此箱號
            $stmt = $pdo->prepare("SELECT * FROM import_items WHERE container_id = ? AND carton_no = ? LIMIT 1");
            $stmt->execute([$containerId, $barcode]);
            $item = $stmt->fetch();

            // --- 狀況 A: 資料庫找不到 (溢卸) ---
            if (!$item) {
                jsonResponse('not_found', '資料庫無此箱號');
            }

            // --- 狀況 B: 店鋪比對 ---
            $correctShopName = $item['shop_name'];
            $correctShopCd = $item['shop_cd'];

            if ($correctShopCd != $selectedShopCd) {
                // 記錄異常 (scan_type = 2)
                $res = insertScanRecord($pdo, $containerId, $item['id'], $barcode, $selectedPallet, 2);
                if (!$res) throw new Exception("寫入異常紀錄失敗");

                jsonResponse('warning', "錯誤！此箱屬於：\n{$correctShopCd} {$correctShopName}", "棧板[$selectedPallet] $barcode (異常:錯分至 $correctShopName)");
            }

            // --- 狀況 C: 正確 ---
            // 記錄正常 (scan_type = 0)
            $res = insertScanRecord($pdo, $containerId, $item['id'], $barcode, $selectedPallet, 0);
            if (!$res) throw new Exception("寫入正常紀錄失敗");

            jsonResponse('success', $correctShopName, "棧板[$selectedPallet] $barcode $correctShopName", $correctShopName);

        } catch (Exception $e) {
            // 寫入 Error Log 以便除錯
            file_put_contents('debug_error_log.txt', date('Y-m-d H:i:s') . " Scan Error: " . $e->getMessage() . "\n", FILE_APPEND);
            jsonResponse('error', '系統錯誤: ' . $e->getMessage());
        }
    }

    // API: 新增溢卸
    if ($action == 'add_overage') {
        $barcode = trim($_POST['barcode']);
        $containerId = $_POST['container_id'];
        $selectedShopCd = $_POST['shop_cd'];
        $selectedShopName = $_POST['shop_name']; 
        $selectedPallet = (int)$_POST['pallet_num'];

        try {
            $pdo->beginTransaction();
            // 新增到 import_items
            $stmt = $pdo->prepare("INSERT INTO import_items (container_id, shop_cd, shop_name, carton_no, box_qty, total_pcs, is_overage) VALUES (?, ?, ?, ?, 1, 1, 1)");
            $stmt->execute([$containerId, $selectedShopCd, $selectedShopName, $barcode]);
            $newItemId = $pdo->lastInsertId();

            // 記錄掃描 (scan_type = 3)
            insertScanRecord($pdo, $containerId, $newItemId, $barcode, $selectedPallet, 3);

            $pdo->commit();
            jsonResponse('success', "已新增溢卸：{$selectedShopName}", "棧板[$selectedPallet] $barcode (溢卸新增)");
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            file_put_contents('debug_error_log.txt', date('Y-m-d H:i:s') . " Overage Error: " . $e->getMessage() . "\n", FILE_APPEND);
            jsonResponse('error', '新增失敗: ' . $e->getMessage());
        }
    }
}

// 輔助函數: 寫入掃描紀錄表
function insertScanRecord($pdo, $cid, $itemId, $carton, $pallet, $type) {
    try {
        // 使用 ON DUPLICATE KEY UPDATE 確保重複掃描會更新時間與狀態
        $sql = "INSERT INTO scan_records (container_id, import_item_id, carton_no, pallet_num, scan_type, scanned_at) 
                VALUES (?, ?, ?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE scanned_at = NOW(), scan_type = VALUES(scan_type), pallet_num = VALUES(pallet_num)";
        $stmt = $pdo->prepare($sql);
        return $stmt->execute([$cid, $itemId, $carton, $pallet, $type]);
    } catch (PDOException $e) {
        // 將 SQL 錯誤寫入檔案，這對除錯非常重要
        file_put_contents('debug_sql_error.txt', date('Y-m-d H:i:s') . " SQL Fail: " . $e->getMessage() . "\n", FILE_APPEND);
        return false;
    }
}
?>

<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DAISO 分揀作業 (Fix)</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
    <style>
        body { background-color: #f4f7f6; font-family: "Microsoft JhengHei", sans-serif; }
        .frame-container { display: flex; flex-direction: column; min-height: 100vh; }
        .header-panel { background: #2c3e50; color: white; padding: 10px 20px; display: flex; justify-content: space-between; align-items: center; }
        .main-content { display: flex; flex: 1; overflow: hidden; }
        .left-panel { width: 320px; padding: 20px; background: #fff; border-right: 1px solid #ddd; overflow-y: auto; }
        .center-panel { flex: 1; padding: 30px; background: #ecf0f1; display: flex; flex-direction: column; align-items: center; }
        .right-panel { width: 350px; padding: 20px; background: #fff; border-left: 1px solid #ddd; overflow-y: auto; }
        .scan-input { font-size: 2rem; text-align: center; width: 100%; max-width: 600px; margin-bottom: 20px; border: 4px solid #3498db; border-radius: 10px; padding: 10px; }
        .result-box { width: 100%; max-width: 600px; min-height: 250px; background: #fff; border-radius: 10px; padding: 30px; box-shadow: 0 5px 15px rgba(0,0,0,0.1); text-align: center; display: flex; flex-direction: column; justify-content: center; align-items: center; }
        .result-title { font-size: 3rem; font-weight: bold; margin-bottom: 15px; }
        .result-desc { font-size: 1.5rem; color: #555; }
        .log-item { padding: 12px; border-bottom: 1px solid #eee; font-size: 1.1rem; animation: fadeIn 0.5s; }
        .log-item.normal { border-left: 5px solid #2ecc71; }
        .log-item.error { border-left: 5px solid #e74c3c; background-color: #fadbd8; }
        .log-item.overage { border-left: 5px solid #f39c12; background-color: #fdebd0; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(-10px); } to { opacity: 1; transform: translateY(0); } }
        @media (max-width: 992px) { .main-content { flex-direction: column; } .left-panel, .right-panel { width: 100%; height: auto; border: none; } }
    </style>
</head>
<body>

<div class="frame-container">
    <div class="header-panel">
        <h4 class="m-0"><i class="bi bi-box-seam"></i> DAISO 分揀作業</h4>
        <div><a href="sunrise.php" class="btn btn-sm btn-outline-light">返回管理</a></div>
    </div>

    <div class="main-content">
        <div class="left-panel shadow-sm">
            <h5 class="mb-3 text-primary"><i class="bi bi-gear-fill"></i> 作業設定</h5>
            <div class="mb-3">
                <label class="form-label fw-bold">1. 選擇貨櫃</label>
                <select id="select_container" class="form-select form-select-lg">
                    <option value="">-- 請選擇 --</option>
                    <?php
                    $stmt = $pdo->query("SELECT * FROM containers WHERE status = 0 ORDER BY id DESC");
                    while ($row = $stmt->fetch()) echo "<option value='{$row['id']}'>{$row['lot_no']}</option>";
                    ?>
                </select>
            </div>
            <div class="mb-3">
                <label class="form-label fw-bold">2. 選擇店鋪</label>
                <select id="select_shop" class="form-select form-select-lg" disabled>
                    <option value="">-- 請先選貨櫃 --</option>
                </select>
                <input type="hidden" id="selected_shop_name">
            </div>
            <div class="mb-3">
                <label class="form-label fw-bold">3. 選擇棧板</label>
                <select id="select_pallet" class="form-select form-select-lg">
                    <?php for($i=1; $i<=20; $i++) echo "<option value='$i'>棧板 [{$i}]</option>"; ?>
                </select>
            </div>
        </div>

        <div class="center-panel">
            <label class="mb-2 fw-bold text-muted">請掃描箱號 (Carton No)</label>
            <input type="text" id="barcode" class="scan-input" placeholder="掃描條碼" autocomplete="off" autofocus>
            <div id="result_area" class="result-box">
                <div class="text-muted"><i class="bi bi-upc-scan" style="font-size: 3rem;"></i><br>等待掃描...</div>
            </div>
        </div>

        <div class="right-panel shadow-sm">
            <h5 class="mb-3 text-success"><i class="bi bi-list-check"></i> 作業紀錄</h5>
            <div id="scan_log"><div class="text-center text-muted mt-5">尚無紀錄</div></div>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script>
    // 載入店鋪
    $('#select_container').change(function() {
        let cid = $(this).val();
        let $shopSelect = $('#select_shop');
        $shopSelect.html('<option value="">載入中...</option>').prop('disabled', true);
        if (cid) {
            $.post('daiso.php', { action: 'get_shops', container_id: cid }, function(data) {
                let html = '<option value="">-- 請選擇店鋪 --</option>';
                data.forEach(function(shop) {
                    html += `<option value="${shop.shop_cd}" data-name="${shop.shop_name}">${shop.shop_cd} ${shop.shop_name}</option>`;
                });
                $shopSelect.html(html).prop('disabled', false);
            }, 'json');
        } else { $shopSelect.html('<option value="">-- 請先選貨櫃 --</option>'); }
    });

    $('#select_shop').change(function() {
        $('#selected_shop_name').val($(this).find(':selected').data('name'));
        $('#barcode').focus();
    });

    // 掃描處理
    $('#barcode').keypress(function(e) {
        if (e.which == 13) {
            let code = $(this).val().trim();
            if(code) handleScan(code);
            $(this).val('');
        }
    });

    function handleScan(code) {
        let cid = $('#select_container').val();
        let shopCd = $('#select_shop').val();
        let shopName = $('#selected_shop_name').val();
        let pallet = $('#select_pallet').val();

        if (!cid || !shopCd) { alert('請先選擇 [貨櫃] 與 [店鋪]'); return; }

        $.ajax({
            url: 'daiso.php',
            type: 'POST',
            dataType: 'json',
            data: { action: 'scan', barcode: code, container_id: cid, shop_cd: shopCd, pallet_num: pallet },
            success: function(res) {
                let $result = $('#result_area');
                if (res.status === 'success') {
                    $result.html(`<div class="result-title text-success"><i class="bi bi-check-circle"></i> 正確</div><div class="result-desc fw-bold">${res.msg}</div>`);
                    addLog(res.log_str, 'normal');
                } else if (res.status === 'warning') {
                    $result.html(`<div class="result-title text-danger">⚠️ 異常警報</div><div class="result-desc text-danger fw-bold">${res.msg.replace(/\n/g, '<br>')}</div>`);
                    addLog(res.log_str, 'error');
                } else if (res.status === 'not_found') {
                    $result.html(`<div class="result-title text-warning">❓ 查無此箱</div>`);
                    setTimeout(() => {
                        if (confirm(`箱號 [${code}] 不在清單中。\n是否新增為 [${shopName}] 的溢卸貨物？`)) {
                            addOverage(code, cid, shopCd, shopName, pallet);
                        } else {
                            $result.html(`<div class="text-muted">已取消操作</div>`);
                        }
                    }, 100);
                } else {
                    alert('發生錯誤: ' + res.msg);
                }
            },
            error: function(xhr, status, error) {
                console.error(xhr.responseText); // 查看後端回傳的原始錯誤
                alert('系統錯誤，請查看 Console 或 debug_sql_error.txt');
            }
        });
    }

    function addOverage(code, cid, shopCd, shopName, pallet) {
        $.post('daiso.php', { action: 'add_overage', barcode: code, container_id: cid, shop_cd: shopCd, shop_name: shopName, pallet_num: pallet }, function(res) {
            if (res.status === 'success') {
                $('#result_area').html(`<div class="result-title text-primary">新增成功</div><div class="result-desc">${res.msg}</div>`);
                addLog(res.log_str, 'overage');
            } else { alert(res.msg); }
        }, 'json');
    }

    function addLog(text, type) {
        if ($('#scan_log .text-center').length) $('#scan_log').empty();
        let timestamp = new Date().toLocaleTimeString('zh-TW', {hour:'2-digit', minute:'2-digit'});
        $('#scan_log').prepend(`<div class="log-item ${type}"><small class="text-muted">${timestamp}</small><div>${text}</div></div>`);
    }
    
    // 保持聚焦
    setInterval(() => { if(!$('#barcode').is(':focus')) { /* $('#barcode').focus(); */ } }, 2000);
</script>

</body>
</html>