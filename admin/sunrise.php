// ==========================================================
//  功能模組 3: 貨櫃結案 (Close Action) - 新增功能
// ==========================================================
if (isset($_POST['action']) && $_POST['action'] == 'close_container') {
    $closeId = (int)$_POST['container_id'];
    
    try {
        // 1. 檢查目前狀態
        $check = $pdo->prepare("SELECT lot_no, status FROM containers WHERE id = ?");
        $check->execute([$closeId]);
        $row = $check->fetch();

        if (!$row) throw new Exception("找不到該貨櫃");
        if ($row['status'] == 1) throw new Exception("此貨櫃已經是結案狀態");

        // 2. 執行結案更新
        // 將 status 設為 1，代表已完成。這會導致 daiso.php 的下拉選單不再顯示此貨櫃
        $stmt = $pdo->prepare("UPDATE containers SET status = 1 WHERE id = ?");
        $stmt->execute([$closeId]);

        $message = "<div class='alert alert-success'><i class='fas fa-check-circle'></i> 貨櫃 <strong>{$row['lot_no']}</strong> 已成功結案！(前台將無法再進行掃描)</div>";

    } catch (Exception $e) {
        $message = "<div class='alert alert-danger'>結案失敗： " . $e->getMessage() . "</div>";
    }
}