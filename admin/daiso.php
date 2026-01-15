<?php
/**
 * 大創貨物分揀系統 - 手機端 v5.2 (Debug Mode)
 * 修正: 加入 Port 設定, JSON 錯誤處理
 */

// --- 1. 資料庫連線配置 (含 Port) ---
$host = '127.0.0.1';
$port = '3306';      // <--- 確保與 admin.php 一致
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
    // 若這裡是 AJAX 請求，回傳 JSON 錯誤；否則顯示文字
    if (isset($_POST['action'])) {
        header('Content-Type: application/json');
        echo json_encode(['status' => 'error', 'msg' => 'DB連線失敗: ' . $e->getMessage()]);
        exit;
    } else {
        die("資料庫連線失敗 (Port $port): " . $e->getMessage());
    }
}

// --- 2. 處理 AJAX 掃描請求 (加入 Try-Catch) ---
if (isset($_POST['action']) && $_POST['action'] == 'scan') {
    header('Content-Type: application/json'); // 強制設定 JSON Header
    
    try {
        $carton_no = $_POST['carton_no'] ?? '';
        $container_id = $_POST['container_id'] ?? '';
        $is_damaged = ($_POST['is_damaged'] ?? 'false') === 'true' ? 1 : 0;

        if (empty($carton_no) || empty($container_id)) {
            throw new Exception("參數錯誤：箱號或貨櫃ID遺失");
        }

        // [節點] 查詢清單
        $stmt = $pdo->prepare("SELECT * FROM import_items WHERE container_id = ? AND carton_no = ?");
        $stmt->execute([$container_id, $carton_no]);
        $item = $stmt->fetch();

        if ($item) {
            // [節點] 這裡模擬寫入記錄 (之後需擴充為寫入 DB)
            // 為了除錯，我們回傳模擬的成功訊息
            echo json_encode([
                'status' => 'success',
                'shop_name' => $item['shop_name'], // 顯示店名
                'shop_cd' => $item['shop_cd'],
                'msg' => '掃描成功'
            ]);
        } else {
            // 查無此箱
            echo json_encode([
                'status' => 'error', 
                'msg' => "查無箱號：$carton_no (非此櫃貨物)"
            ]);
        }

    } catch (Exception $e) {
        // [節點] 捕捉所有邏輯錯誤回傳給前端
        echo json_encode([
            'status' => 'error', 
            'msg' => '系統異常: ' . $e->getMessage()
        ]);
    }
    exit;
}
?>

<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DAISO 分揀作業 (Debug)</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
    
    <style>
        body { background-color: #f4f7f6; font-family: "Microsoft JhengHei", sans-serif; }
        .frame-container { min-height: 100vh; display: flex; flex-direction: column; }
        .header-panel { background: #2c3e50; color: white; padding: 10px; }
        .main-content { flex: 1; display: flex; flex-wrap: wrap; }
        
        /* 響應式佈局 */
        .frame-left { flex: 1; min-width: 300px; border-right: 1px solid #ddd; background: #fff; padding: 20px; }
        .frame-center { flex: 2; min-width: 400px; background: #ebf0f1; padding: 20px; text-align: center; }
        .frame-right { flex: 1; min-width: 300px; border-left: 1px solid #ddd; background: #fff; padding: 20px; }

        .big-display { font-size: 3.5rem; font-weight: bold; color: #27ae60; margin: 20px 0; line-height: 1.2; }
        .error-display { color: #c0392b; font-size: 2rem; }
        .scan-input { font-size: 1.5rem; text-align: center; border: 3px solid #3498db; }
        .damage-btn { height: 80px; font-size: 1.5rem; }
        
        /* 錯誤訊息框 */
        #error_debug_box { display: none; font-size: 0.9rem; margin-top: 10px; }

        @media (max-width: 768px) {
            .main-content { flex-direction: column; }
            .frame-left, .frame-center, .frame-right { width: 100%; border: none; padding: 15px; }
            .big-display { font-size: 2.5rem; }
        }
    </style>
</head>
<body>

<div class="frame-container">
    <div class="header-panel d-flex justify-content-between align-items-center">
        <h4 class="m-0">DAISO 分揀系統</h4>
        <span class="badge bg-secondary">V5.2 Debug</span>
    </div>

    <div class="main-content">
        <div class="frame-left">
            <h5><i class="bi bi-sliders"></i> 作業配置</h5>
            <hr>
            <div class="mb-3">
                <label>選擇貨櫃 (LotNo)</label>
                <select class="form-select" id="container_id">
                    <?php
                    // 這裡也加入 Try-Catch 防止下拉選單導致頁面掛掉
                    try {
                        $containers = $pdo->query("SELECT * FROM containers ORDER BY id DESC LIMIT 20")->fetchAll();
                        if (count($containers) == 0) echo "<option value=''>無貨櫃資料，請先導入</option>";
                        foreach ($containers as $c) {
                            $selected = ($c['status'] == 0) ? 'selected' : '';
                            echo "<option value='{$c['id']}' $selected>{$c['lot_no']} " . ($c['status']==1?'(結案)':'') . "</option>";
                        }
                    } catch (Exception $e) {
                        echo "<option>讀取失敗: {$e->getMessage()}</option>";
                    }
                    ?>
                </select>
            </div>
            <div class="alert alert-light border">
                <small class="text-muted">DB狀態: 連線正常 (Port <?php echo $port; ?>)</small>
            </div>
        </div>

        <div class="frame-center">
            <h3 class="mb-3">請掃描箱號</h3>
            <input type="text" id="barcode_input" class="form-control scan-input shadow-sm" placeholder="游標請置於此掃描" autocomplete="off" autofocus>
            
            <div id="result_area" class="mt-4 p-3 bg-white rounded shadow-sm" style="min-height: 200px;">
                <p class="text-muted mb-0">等待掃描...</p>
                
                <div id="display_shop_name" class="big-display">---</div>
                <div class="h4 text-secondary" id="display_shop_cd"></div>
                
                <div id="error_debug_box" class="alert alert-danger"></div>
            </div>

            <div class="row mt-4">
                <div class="col">
                    <button class="btn btn-warning w-100 damage-btn shadow-sm" id="btn_damage">
                        <i class="bi bi-camera"></i> 疑似破損<br><small>(按此拍照)</small>
                    </button>
                </div>
            </div>
        </div>

        <div class="frame-right">
            <h5><i class="bi bi-clock-history"></i> 最近掃描</h5>
            <hr>
            <ul class="list-group list-group-flush" id="scan_history">
                <li class="list-group-item text-muted text-center">尚無紀錄</li>
            </ul>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script>
    let isDamagedPending = false;

    // 1. 監聽 Enter 鍵 (掃描槍行為)
    $('#barcode_input').keypress(function(e) {
        if (e.which == 13) {
            let barcode = $(this).val().trim();
            if (barcode) {
                processScan(barcode);
                $(this).val(''); // 清空輸入框
            }
        }
    });

    // 2. 破損按鈕邏輯
    $('#btn_damage').click(function() {
        isDamagedPending = !isDamagedPending; // 切換狀態
        if (isDamagedPending) {
            $(this).removeClass('btn-warning').addClass('btn-danger').html('<i class="bi bi-exclamation-circle"></i> 請掃描損壞箱號');
            $('#barcode_input').focus();
        } else {
            resetDamageBtn();
        }
    });

    function resetDamageBtn() {
        isDamagedPending = false;
        $('#btn_damage').removeClass('btn-danger').addClass('btn-warning').html('<i class="bi bi-camera"></i> 疑似破損<br><small>(按此拍照)</small>');
    }

    // 3. 核心：發送 AJAX 請求
    function processScan(barcode) {
        let containerId = $('#container_id').val();
        
        // UI 重置
        $('#error_debug_box').hide();
        $('#display_shop_name').text('查詢中...').removeClass('error-display').css('color', '#95a5a6');
        
        if(!containerId) {
            showError('錯誤：請先在左側選擇貨櫃批號');
            return;
        }

        $.ajax({
            url: 'daiso.php',
            type: 'POST',
            dataType: 'json', // 強制解析 JSON
            data: {
                action: 'scan',
                carton_no: barcode,
                container_id: containerId,
                is_damaged: isDamagedPending
            },
            success: function(res) {
                if (res.status === 'success') {
                    // 成功：綠色大字顯示店名
                    $('#display_shop_name').text(res.shop_name).css('color', '#27ae60').removeClass('error-display');
                    $('#display_shop_cd').text('店號: ' + res.shop_cd);
                    
                    // 加入歷史紀錄
                    let badge = isDamagedPending ? '<span class="badge bg-danger">損</span> ' : '';
                    let itemHtml = `<li class="list-group-item d-flex justify-content-between align-items-center">
                                        <span>${badge}${barcode}</span>
                                        <span class="fw-bold">${res.shop_name}</span>
                                    </li>`;
                    $('#scan_history').prepend(itemHtml);
                    // 只保留最近 10 筆
                    if ($('#scan_history li').length > 10) $('#scan_history li:last').remove();
                    
                    // 播放成功音效 (可選)
                    // new Audio('ok.mp3').play();
                } else {
                    // 邏輯錯誤 (如查無此箱)
                    showError(res.msg);
                    // 播放錯誤音效 (可選)
                    // new Audio('error.mp3').play();
                }
                resetDamageBtn();
            },
            error: function(xhr, status, error) {
                // 系統層級錯誤 (如 PHP 語法錯誤、DB 斷線)
                console.error("AJAX Error:", xhr.responseText);
                let errorMsg = "系統連線錯誤";
                
                // 嘗試解析後端回傳的錯誤文字
                if (xhr.responseJSON && xhr.responseJSON.msg) {
                    errorMsg = xhr.responseJSON.msg;
                } else if (xhr.responseText) {
                    // 截取部分錯誤訊息顯示
                    errorMsg = "程式錯誤: " + xhr.responseText.substring(0, 50) + "...";
                }
                showError(errorMsg);
            }
        });
    }

    function showError(msg) {
        $('#display_shop_name').text('錯誤').addClass('error-display');
        $('#display_shop_cd').text('');
        $('#error_debug_box').text(msg).show(); // 顯示紅框錯誤訊息
    }

    // 保持焦點
    setInterval(function() {
        if (!isDamagedPending && !$('#barcode_input').is(':focus')) {
            // $('#barcode_input').focus(); // 視需求開啟，避免在手機上一直跳鍵盤
        }
    }, 2000);
</script>

</body>
</html>