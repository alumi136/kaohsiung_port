<?php
// 設置響應標頭為 JSON
header('Content-Type: application/json; charset=utf-8');

// 檢查請求方法是否為 POST
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // --- 設定 ---
    $to = "david.aaf49@gmail.com"; 
    
    // --- 清理並獲取表單資料 ---
    $name = filter_input(INPUT_POST, 'name', FILTER_SANITIZE_STRING);
    $phone = filter_input(INPUT_POST, 'phone', FILTER_SANITIZE_STRING);
    $email = filter_input(INPUT_POST, 'email', FILTER_SANITIZE_EMAIL);
    $house_bl = filter_input(INPUT_POST, 'house_bl', FILTER_SANITIZE_STRING); // 新增：分提單號
    $inquiry_type = filter_input(INPUT_POST, 'inquiry_type', FILTER_SANITIZE_STRING); // 新增：問題類型
    $message = filter_input(INPUT_POST, 'message', FILTER_SANITIZE_STRING);

    // --- 驗證表單資料 ---
    // 將手機號碼也設為必填
    if (empty($name) || empty($phone) || empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL) || empty($message) || empty($inquiry_type)) {
        // 如果驗證失敗，發送錯誤響應
        http_response_code(400);
        echo json_encode(["status" => "error", "message" => "請確實填寫所有必填欄位。"]);
        exit;
    }

    // --- 組合郵件內容 ---
    $subject = "=?UTF-8?B?".base64_encode("來自億欣報關行網站的留言 - [$inquiry_type] - 來自: $name")."?=";
    
    $email_content = "您收到一則來自億欣報關行網站的線上留言：\n\n";
    $email_content .= "問題類型: $inquiry_type\n";
    $email_content .= "----------------------------------------\n";
    $email_content .= "姓名: $name\n";
    $email_content .= "手機號碼: $phone\n";
    $email_content .= "Email: $email\n";
    $email_content .= "分提單號: " . (!empty($house_bl) ? $house_bl : '未提供') . "\n\n";
    $email_content .= "留言內容:\n$message\n";

    // --- 設定郵件標頭 ---
    $headers = "From: webmaster@" . ($_SERVER['SERVER_NAME'] ?? 'localhost') . "\r\n";
    $headers .= "Reply-To: $email\r\n";
    $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $headers .= "X-Mailer: PHP/" . phpversion();

    // --- 發送郵件 ---
    if (mail($to, $subject, $email_content, $headers)) {
        // 如果郵件成功發送
        http_response_code(200);
        echo json_encode(["status" => "success", "message" => "感謝您的留言，我們將盡快與您聯繫！"]);
    } else {
        // 如果郵件發送失敗
        http_response_code(500);
        echo json_encode(["status" => "error", "message" => "抱歉，留言發送失敗，請檢查伺服器郵件設定或稍後再試。"]);
    }

} else {
    // 如果不是 POST 請求，則拒絕存取
    http_response_code(403);
    echo json_encode(["status" => "error", "message" => "禁止存取。"]);
}
?>

