<?php
// 啟動 session
session_start();

// --- 檢查使用者是否已登入的邏輯 ---
// 如果 Session 中沒有 'loggedin' 變數，或者 'loggedin' 不為 true
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    // 將使用者導向到登入頁面
    header('Location: login.php');
    exit(); // 確保重定向後停止執行
}

// 獲取登入者帳號，用於日誌記錄和顯示
// 重要提醒：請確保您的 login.php 程式在成功登入時，
// 除了設定 $_SESSION['username'] 外，也應將登入者的資料庫 ID 儲存到 $_SESSION['user_id']。
// 範例：$_SESSION['user_id'] = $user['id'];
$logged_in_username = $_SESSION['username'] ?? '未知使用者';


// --- 資源優化設定 ---
//ini_set('memory_limit', '1024M'); // 這些通常在 php.ini 中設定，不建議在每個腳本中頻繁設定
//set_time_limit(300); // 將時間限制設定為 300 秒，以防操作耗時過長，但此頁面通常很快

// --- 資料庫連線設定 (請根據您的實際情況修改) ---
$servername = "localhost";
$username_db = "alumi136"; // 請替換為您的資料庫使用者名稱
$password_db = "Alumi!36"; // 請替換為您的資料庫密碼
$dbname = "kaohsiung_port_db"; // 請替換為您的資料庫名稱

// --- 輔助函式定義 ---
function write_log($message) {
    // 獨立的日誌檔案，用於管理員帳號管理程式
    $log_file = '/var/www/html/account_management.log'; 
    $timestamp = date('Y-m-d H:i:s');
    $formatted_message = "[{$timestamp}] " . $message . PHP_EOL;
    file_put_contents($log_file, $formatted_message, FILE_APPEND);
}

// --- 全域變數初始化 ---
$user_message = '';
$message_type = '';
$administrators = []; // 儲存從資料庫讀取到的管理員列表
$edit_admin = null; // 儲存要編輯的管理員資料

// --- 資料庫連線 ---
$conn = new mysqli($servername, $username_db, $password_db, $dbname);
if ($conn->connect_error) {
    die("系統錯誤：無法連線到資料庫。" . $conn->connect_error); // 嚴重錯誤，直接終止
}
$conn->set_charset("utf8mb4");

// --- 處理 GET 請求 (編輯和刪除) ---
// 這裡將 GET 請求放在 POST 之前，確保 'edit_admin' 在頁面載入時就準備好
if (isset($_GET['action'])) {
    if ($_GET['action'] == 'edit' && isset($_GET['id'])) {
        $admin_id = intval($_GET['id']); // 確保 ID 是整數，防止 SQL 注入
        $stmt = $conn->prepare("SELECT id, username, full_name, job_title, email FROM administrators WHERE id = ?");
        $stmt->bind_param("i", $admin_id);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result->num_rows === 1) {
            $edit_admin = $result->fetch_assoc();
        } else {
            $user_message = "找不到要編輯的管理員。";
            $message_type = 'error';
        }
        $stmt->close();
    } elseif ($_GET['action'] == 'delete' && isset($_GET['id'])) {
        $admin_id = intval($_GET['id']); // 確保 ID 是整數
        
        // 防止自己刪除自己
        // 必須確保 $_SESSION['user_id'] 在 login.php 中已正確設置！
        if (isset($_SESSION['user_id']) && $admin_id === (int)$_SESSION['user_id']) { 
            $user_message = "您不能刪除您自己的帳號！";
            $message_type = 'warn';
            write_log("[{$logged_in_username}] 嘗試刪除自己的帳號 (ID: {$admin_id})。");
        } else {
            // 獲取當前管理員數量，防止刪除最後一個管理員
            $count_stmt = $conn->prepare("SELECT COUNT(*) FROM administrators");
            $count_stmt->execute();
            $total_admins = $count_stmt->get_result()->fetch_row()[0];
            $count_stmt->close();

            if ($total_admins <= 1) {
                $user_message = "不能刪除最後一個管理員帳號！請至少保留一個。";
                $message_type = 'warn';
                write_log("[{$logged_in_username}] 嘗試刪除最後一個管理員帳號 (ID: {$admin_id})。");
            } else {
                $stmt = $conn->prepare("DELETE FROM administrators WHERE id = ?");
                $stmt->bind_param("i", $admin_id);
                if ($stmt->execute()) {
                    if ($stmt->affected_rows > 0) {
                        $user_message = "管理員帳號刪除成功！";
                        $message_type = 'success';
                        write_log("[{$logged_in_username}] 刪除管理員帳號成功 (ID: {$admin_id})。");
                    } else {
                        $user_message = "刪除失敗：找不到指定管理員或資料已不存在。";
                        $message_type = 'warn';
                        write_log("[{$logged_in_username}] 刪除管理員失敗: 找不到管理員 (ID: {$admin_id})。");
                    }
                } else {
                    $user_message = "刪除管理員失敗：" . $stmt->error;
                    $message_type = 'error';
                    write_log("[{$logged_in_username}] 刪除管理員失敗: ID {$admin_id} - {$stmt->error}");
                }
                $stmt->close();
            }
        }
        // 重定向回 account.php 以刷新列表並清除 GET 參數
        header('Location: account.php');
        exit();
    }
}


// --- 處理 POST 請求 (新增和提交編輯) ---
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // 判斷是新增還是編輯提交
    if (isset($_POST['add_admin']) || isset($_POST['edit_admin_submit'])) {
        $admin_id = $_POST['admin_id'] ?? null; // 如果是編輯，admin_id 會存在
        $username_input = trim($_POST['username'] ?? ''); // 統一 input name
        $password_input = $_POST['password'] ?? ''; // 統一 input name
        $full_name_input = trim($_POST['full_name'] ?? ''); // 統一 input name
        $job_title_input = trim($_POST['job_title'] ?? ''); // 統一 input name
        $email_input = trim($_POST['email'] ?? '');

        if (empty($username_input)) {
            $user_message = "使用者名稱為必填欄位。";
            $message_type = 'warn';
        } elseif (is_null($admin_id) && empty($password_input)) { // 新增時密碼必填
            $user_message = "新增管理員時，密碼為必填欄位。";
            $message_type = 'warn';
        } else {
            // 檢查帳號是否與其他帳號重複（排除自己）
            $stmt_check = $conn->prepare("SELECT id FROM administrators WHERE username = ? AND id != ?");
            // 對於新增操作，id != ? 條件可以設為 id != 0 (因為新增沒有 id)
            $check_id = $admin_id ?? 0; // 如果是新增，admin_id 為 null，設為 0 不影響 check
            $stmt_check->bind_param("si", $username_input, $check_id);
            $stmt_check->execute();
            $stmt_check->store_result();
            
            if ($stmt_check->num_rows > 0) {
                $user_message = "操作失敗：該帳號 '{$username_input}' 已存在或已被其他管理員使用。";
                $message_type = 'error';
                write_log("[{$logged_in_username}] 操作失敗: 帳號 '{$username_input}' 已存在或已被使用。");
            } else {
                if (is_null($admin_id)) { // 新增操作
                    $hashed_password = password_hash($password_input, PASSWORD_DEFAULT);
                    $stmt = $conn->prepare("INSERT INTO administrators (username, password_hash, full_name, job_title, email) VALUES (?, ?, ?, ?, ?)");
                    $stmt->bind_param("sssss", $username_input, $hashed_password, $full_name_input, $job_title_input, $email_input);
                    if ($stmt->execute()) {
                        $user_message = "管理員 '{$username_input}' 新增成功！";
                        $message_type = 'success';
                        write_log("[{$logged_in_username}] 新增管理員成功: '{$username_input}'。");
                    } else {
                        $user_message = "新增管理員失敗：" . $stmt->error;
                        $message_type = 'error';
                        write_log("[{$logged_in_username}] 新增管理員失敗: '{$username_input}' - {$stmt->error}");
                    }
                } else { // 編輯操作
                    $sql_parts = [];
                    $params = [];
                    $types = '';

                    $sql_parts[] = "username = ?";
                    $params[] = $username_input;
                    $types .= 's';

                    if (!empty($password_input)) { // 只有當有輸入新密碼時才更新密碼
                        $hashed_password = password_hash($password_input, PASSWORD_DEFAULT);
                        $sql_parts[] = "password_hash = ?";
                        $params[] = $hashed_password;
                        $types .= 's';
                    }

                    $sql_parts[] = "full_name = ?";
                    $params[] = $full_name_input;
                    $types .= 's';

                    $sql_parts[] = "job_title = ?";
                    $params[] = $job_title_input;
                    $types .= 's';

                    $sql_parts[] = "email = ?";
                    $params[] = $email_input;
                    $types .= 's';

                    $sql = "UPDATE administrators SET " . implode(', ', $sql_parts) . " WHERE id = ?";
                    $params[] = $admin_id;
                    $types .= 'i';

                    $stmt = $conn->prepare($sql);
                    if ($stmt === false) {
                        throw new Exception("SQL 更新預備語句失敗: " . $conn->error);
                    }
                    $stmt->bind_param($types, ...$params);

                    if ($stmt->execute()) {
                        $user_message = "管理員 '{$username_input}' 資料更新成功！";
                        $message_type = 'success';
                        write_log("[{$logged_in_username}] 更新管理員資料成功: '{$username_input}' (ID: {$admin_id})。");
                    } else {
                        $user_message = "更新管理員資料失敗：" . $stmt->error;
                        $message_type = 'error';
                        write_log("[{$logged_in_username}] 更新管理員資料失敗: '{$username_input}' (ID: {$admin_id}) - {$stmt->error}");
                    }
                }
            }
            $stmt_check->close(); // 關閉檢查帳號的預處理語句
            if (isset($stmt)) $stmt->close(); // 關閉新增/更新的預處理語句

            // 如果是編輯提交，並且成功，清空 $edit_admin 以返回新增模式
            if (!is_null($admin_id) && $message_type === 'success') {
                 $edit_admin = null; // 清空編輯資料，讓表單恢復新增模式
                 // 重定向以清除 POST 數據並刷新列表
                 header('Location: account.php');
                 exit();
            }
        }
    }
}

// --- 讀取所有管理者資料 ---
$stmt = $conn->prepare("SELECT id, username, full_name, job_title, email, created_at FROM administrators ORDER BY id ASC");
$stmt->execute();
$result = $stmt->get_result();
if ($result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $administrators[] = $row;
    }
}
$stmt->close();

$conn->close(); // 關閉資料庫連線
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>管理員帳號管理</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', 'Noto Sans TC', sans-serif; }
        .form-input {
            @apply mt-1 block w-full px-3 py-2 bg-white border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm;
        }
        .btn-primary {
            @apply inline-flex justify-center py-2 px-4 border border-transparent shadow-sm text-sm font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500;
        }
        .btn-danger {
            @apply inline-flex justify-center py-2 px-4 border border-transparent shadow-sm text-sm font-medium rounded-md text-white bg-red-600 hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-red-500;
        }
        .btn-warning {
            @apply inline-flex justify-center py-2 px-4 border border-transparent shadow-sm text-sm font-medium rounded-md text-white bg-yellow-500 hover:bg-yellow-600 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-yellow-400;
        }
    </style>
</head>
<body class="bg-gray-100 p-4">
    <div class="container mx-auto p-4 md:p-8 max-w-6xl bg-white rounded-xl shadow-lg">
        <header class="text-center mb-10">
            <h1 class="text-3xl md:text-4xl font-bold text-blue-900">管理員帳號管理系統</h1>
            <p class="text-gray-600 mt-2">目前登入者: <span class="font-semibold text-blue-700"><?php echo htmlspecialchars($logged_in_username); ?></span></p>
            <!-- 導航連結已移除 -->
        </header>

        <main>
            <!-- 結果訊息顯示區 -->
            <?php if ($user_message): ?>
                <div class="mb-6 p-4 rounded-lg <?php 
                    switch ($message_type) {
                        case 'success': echo 'bg-green-100 text-green-800'; break;
                        case 'error': echo 'bg-red-100 text-red-800'; break;
                        case 'warn': echo 'bg-yellow-100 text-yellow-800'; break;
                    }
                ?>">
                    <p><?php echo $user_message; ?></p>
                </div>
            <?php endif; ?>

            <!-- 新增/編輯管理員表單 -->
            <div class="mb-10 p-6 bg-gray-50 rounded-lg shadow-sm">
                <h2 class="text-2xl font-bold text-gray-800 mb-6"><?php echo $edit_admin ? '編輯管理員資料' : '新增管理員'; ?></h2>
                <form action="account.php" method="POST">
                    <?php if ($edit_admin): ?>
                        <input type="hidden" name="admin_id" value="<?php echo htmlspecialchars($edit_admin['id']); ?>">
                    <?php endif; ?>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                        <div>
                            <label for="username" class="block text-sm font-medium text-gray-700">使用者名稱 (帳號)</label>
                            <input type="text" name="username" id="username" class="form-input" required
                                value="<?php echo htmlspecialchars($edit_admin['username'] ?? ''); ?>"
                                <?php echo $edit_admin ? 'readonly' : ''; // 編輯時帳號不可修改 ?>
                            >
                            <?php if ($edit_admin): ?>
                                <p class="mt-1 text-xs text-gray-500">編輯時，使用者名稱不可修改。</p>
                            <?php endif; ?>
                        </div>
                        <div>
                            <label for="password" class="block text-sm font-medium text-gray-700">密碼 (留空表示不修改)</label>
                            <input type="password" name="password" id="password" class="form-input"
                                placeholder="<?php echo $edit_admin ? '留空表示不修改密碼' : '請輸入密碼'; ?>"
                            >
                        </div>
                        <div>
                            <label for="full_name" class="block text-sm font-medium text-gray-700">姓名 (full_name)</label>
                            <input type="text" name="full_name" id="full_name" class="form-input"
                                value="<?php echo htmlspecialchars($edit_admin['full_name'] ?? ''); ?>"
                            >
                        </div>
                        <div>
                            <label for="job_title" class="block text-sm font-medium text-gray-700">職稱 (job_title)</label>
                            <input type="text" name="job_title" id="job_title" class="form-input"
                                value="<?php echo htmlspecialchars($edit_admin['job_title'] ?? ''); ?>"
                            >
                        </div>
                        <div class="md:col-span-2">
                            <label for="email" class="block text-sm font-medium text-gray-700">Email</label>
                            <input type="email" name="email" id="email" class="form-input"
                                value="<?php echo htmlspecialchars($edit_admin['email'] ?? ''); ?>"
                            >
                        </div>
                    </div>
                    <div class="mt-6 flex justify-end space-x-3">
                        <?php if ($edit_admin): ?>
                            <button type="submit" name="edit_admin_submit" class="btn-primary">保存修改</button>
                            <a href="account.php" class="btn-warning bg-gray-500 hover:bg-gray-600">取消編輯</a>
                        <?php else: ?>
                            <button type="submit" name="add_admin" class="btn-primary">新增管理員</button>
                        <?php endif; ?>
                    </div>
                </form>
            </div>

            <!-- 管理員列表 -->
            <div class="bg-white rounded-lg shadow-lg p-6">
                <h2 class="text-2xl font-bold text-gray-800 mb-6">現有管理員列表</h2>
                <?php if (empty($administrators)): ?>
                    <p class="text-gray-600">目前沒有任何管理員帳號。</p>
                <?php else: ?>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">ID</th>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">使用者名稱</th>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">姓名 (full_name)</th>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">職稱 (job_title)</th>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Email</th>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">建立日期</th>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">操作</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <?php foreach ($administrators as $admin): ?>
                                <tr>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900"><?php echo htmlspecialchars($admin['id']); ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900"><?php echo htmlspecialchars($admin['username']); ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900"><?php echo htmlspecialchars($admin['full_name']); ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900"><?php echo htmlspecialchars($admin['job_title']); ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900"><?php echo htmlspecialchars($admin['email']); ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900"><?php echo htmlspecialchars($admin['created_at']); ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium space-x-2">
                                        <a href="account.php?action=edit&id=<?php echo htmlspecialchars($admin['id']); ?>" class="text-blue-600 hover:text-blue-900 btn-warning">編輯</a>
                                        <a href="account.php?action=delete&id=<?php echo htmlspecialchars($admin['id']); ?>" 
                                           onclick="return confirm('確定要刪除管理員帳號 ' + '<?php echo htmlspecialchars($admin['username']); ?>' + ' 嗎？這項操作無法復原。');" 
                                           class="text-red-600 hover:text-red-900 btn-danger">刪除</a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </main>
    </div>
</body>
</html>

