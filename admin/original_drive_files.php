<?php
// ☆☆☆☆ 這是一個 CLI (命令列介面) 腳本 ☆☆☆☆
// 任務 (ETL 流程 - v38):
// 1. (Extract) 從 Google Drive 下載 Excel 檔案，寫入 daily_outbound。
// 2. (Load) 將 daily_outbound 資料彙總統計，更新至 daily_arrange 總表。
//
// **注意: 此版本已移除內建的重複資料合併(Transform)邏輯。**
// **系統依賴外部的 SQL 事件 (例如 21:10 的合併 和 21:20 的刪除) 來處理 daily_outbound 的資料唯一性。**

set_time_limit(5400); // 增加腳本最大執行時間至 1.5 小時 (增加的等待時間)
ini_set('memory_limit', '512M');

// 設定腳本的預設時區為台北時間
date_default_timezone_set('Asia/Taipei');

require __DIR__ . '/vendor/autoload.php';

use Google\Client as Google_Client;
use Google\Service\Drive as Google_Service_Drive;
use Box\Spout\Reader\Common\Creator\ReaderEntityFactory;

// --- ☆☆☆ 請在這裡填寫您的設定 ☆☆☆ ---
const SERVICE_ACCOUNT_KEY_PATH = __DIR__ . '/credentials.json';
const SOURCE_FOLDER_ID = '1AnXw6ovqh5dPCq0ZNLagO7uT5fyvE0z_';
const DESTINATION_FOLDER_ID = '1604iZtjVqSibbJpT_KBNNWJyTLBINeeU';
$db_config = [
    'servername' => "localhost",
    'username' => "alumi136",
    'password' => "Alumi!36",
    'dbname' => "kaohsiung_port_db"
];
const TARGET_TABLE = 'daily_outbound';
const LOG_FILE = __DIR__ . '/original_processing.log';
// --- ☆☆☆ 設定結束 ☆☆☆ ---


// --- 輔助函式庫 ---
function write_log($message) {
    $memory = round(memory_get_usage(true) / 1024 / 1024, 2) . " MB";
    $timestamp = date('Y-m-d H:i:s');
    $formatted_message = "[{$timestamp}] [Mem: {$memory}] " . $message . PHP_EOL;
    file_put_contents(LOG_FILE, $formatted_message, FILE_APPEND);
    echo $formatted_message;
}

// ... 其他輔助函式庫 (無變動) ...
function parse_spout_date_value($value) {
    if (empty($value)) return null;
    if ($value instanceof \DateTime) { return $value->format('Y-m-d H:i:s'); }
    if (is_string($value)) { try { $dt = new DateTime($value); return $dt->format('Y-m-d H:i:s'); } catch (Exception $e) { return null; } }
    return null;
}
function batch_insert(mysqli $conn, array $data): int {
    if (empty($data)) return 0;
    $sql = "INSERT INTO " . TARGET_TABLE . " (declaration_no, master_no, house_no, weight, total_packages, packages_in, packages_out, clearance_method, declaration_type, carrier_id, route, storage_in_datetime, storage_out_datetime, release_datetime, status, customer_name, remark, status0) VALUES ";
    $placeholders = []; $params = []; $types = '';
    foreach ($data as $row) {
        $placeholders[] = '(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
        array_push($params, $row['declaration_no'], $row['master_no'], $row['house_no'], $row['weight'], $row['total_packages'], $row['packages_in'], $row['packages_out'], $row['clearance_method'], $row['declaration_type'], $row['carrier_id'], $row['route'], $row['storage_in_datetime'], $row['storage_out_datetime'], $row['release_datetime'], $row['status'], $row['customer_name'], $row['remark'], $row['status0']);
        $types .= 'sssdiisssssssssssi';
    }
    $sql .= implode(', ', $placeholders);
    $stmt = $conn->prepare($sql);
    if ($stmt === false) throw new Exception("SQL 批次新增預備語句失敗: " . $conn->error);
    $stmt->bind_param($types, ...$params);
    if (!$stmt->execute()) throw new Exception("批次新增執行失敗: " . $stmt->error);
    $affected_rows = $stmt->affected_rows;
    $stmt->close();
    return $affected_rows;
}
function getGoogleDriveClient(): Google_Service_Drive {
    $client = new Google_Client();
    $client->setApplicationName('Kaohsiung Port Drive Importer');
    $client->setScopes([Google_Service_Drive::DRIVE]);
    $client->setAuthConfig(SERVICE_ACCOUNT_KEY_PATH);
    $client->setAccessType('offline');
    return new Google_Service_Drive($client);
}
function moveFileOnDrive(Google_Service_Drive $service, string $fileId, string $sourceFolderId, string $destFolderId): void {
    $max_retries = 3;
    for ($attempt = 1; $attempt <= $max_retries; $attempt++) {
        try {
            $service->files->update($fileId, new \Google\Service\Drive\DriveFile(), ['addParents' => $destFolderId, 'removeParents' => $sourceFolderId, 'fields' => 'id, parents']);
            sleep(1);
            $file = $service->files->get($fileId, ['fields' => 'parents']);
            if (in_array($destFolderId, $file->getParents())) return;
            write_log("移動確認失敗 (第 {$attempt} 次嘗試)...");
        } catch (Exception $e) {
            write_log("移動API錯誤 (第 {$attempt} 次嘗試): " . $e->getMessage());
            if ($attempt === $max_retries) throw $e;
        }
        sleep($attempt);
    }
    throw new Exception("在 {$max_retries} 次嘗試後，檔案 {$fileId} 移動失敗。");
}


// --- 核心處理邏輯 ---
write_log("==== Original files ETL cron job started (v38 - Merge/Delete Removed). ====");
$files_were_processed = false;

try {
    // --- 步驟 1: (Extract) 從 Drive 讀取檔案並寫入 daily_outbound ---
    $driveService = getGoogleDriveClient();
    $queryParams = ['q' => "'" . SOURCE_FOLDER_ID . "' in parents and (mimeType='application/vnd.ms-excel' or mimeType='application/vnd.openxmlformats-officedocument.spreadsheetml.sheet') and trashed=false", 'pageSize' => 10, 'fields' => "files(id, name)"];
    $results = $driveService->files->listFiles($queryParams);

    if (count($results->getFiles()) == 0) {
        write_log("在 'original_daily_in' 資料夾中未找到新檔案。");
    } else {
        $files_were_processed = true;
        foreach ($results->getFiles() as $file) {
            // ... (此處檔案處理迴圈邏輯不變) ...
            $file_id = $file->getId();
            $file_name = $file->getName();
            write_log("正在處理檔案: {$file_name} (ID: {$file_id})");
            $response = $driveService->files->get($file_id, ['alt' => 'media']);
            $file_content = $response->getBody()->getContents();
            $file_extension = pathinfo($file_name, PATHINFO_EXTENSION);
            $file_tmp_path = sys_get_temp_dir() . '/' . uniqid('drive_import_', true) . '.' . $file_extension;
            file_put_contents($file_tmp_path, $file_content);
            $conn = new mysqli($db_config['servername'], $db_config['username'], $db_config['password'], $db_config['dbname']);
            if ($conn->connect_error) throw new Exception("資料庫連線失敗: " . $conn->connect_error);
            $conn->set_charset("utf8mb4");
            $transaction_started = false;
            try {
                $reader = ReaderEntityFactory::createReaderFromFile($file_tmp_path);
                $reader->open($file_tmp_path);
                $file_type = null; $headerString = '';
                foreach ($reader->getSheetIterator() as $sheet) { foreach ($sheet->getRowIterator() as $rowIndex => $row) { $headerRow = $row->toArray(); $headerString = implode(',', array_map('trim', $headerRow)); break; } break; }
                if (strpos($headerString, '出倉時間') !== false && strpos($headerString, '申報重量') !== false) { $file_type = 'HANDOVER_LIST'; } elseif (strpos($headerString, '放行時間') !== false) { $file_type = 'RELEASED_NOT_OUT'; } elseif (strpos($headerString, '進倉時間') !== false) { $file_type = 'INSTOCK_NOT_OUT'; } elseif (strpos($headerString, '有無艙單') !== false) { $file_type = 'DECLARED_NOT_IN'; }
                if (!$file_type) throw new Exception("無法從第一行的欄位標頭識別檔案類型。 Header: " . $headerString);
                write_log("檔案類型識別為: {$file_type}");
                $reader->close();
                $conn->begin_transaction();
                $transaction_started = true;
                $reader->open($file_tmp_path);
                $data_to_insert_chunk = []; $chunk_size = 2000; $total_inserted_rows = 0;
                foreach ($reader->getSheetIterator() as $sheet) {
                    foreach ($sheet->getRowIterator() as $rowIndex => $row) {
                        if ($rowIndex === 1) continue;
                        $rowDataArray = $row->toArray();
                        $key_identifier_cell = ($file_type === 'HANDOVER_LIST') ? trim($rowDataArray[4] ?? '') : trim($rowDataArray[2] ?? '');
                        if (!empty($key_identifier_cell) && preg_match('/^[A-Za-z0-9]/', $key_identifier_cell) === 0) { write_log("在第 {$rowIndex} 行偵測到非英數開頭的識別碼 '{$key_identifier_cell}'，判斷為檔案結尾，停止讀取此檔案。"); break; }
                        if (empty($key_identifier_cell)) continue;
                        $row_data = ['declaration_no' => null, 'master_no' => null, 'house_no' => null, 'weight' => null, 'total_packages' => null, 'packages_in' => null, 'packages_out' => null, 'clearance_method' => null, 'declaration_type' => null, 'carrier_id' => null, 'route' => null, 'storage_in_datetime' => null, 'storage_out_datetime' => null, 'release_datetime' => null, 'status' => null, 'customer_name' => null, 'remark' => null, 'status0' => 0];
                        $temp_total = 0; $temp_in = 0; $temp_out = 0;
                        switch ($file_type) {
                            case 'HANDOVER_LIST': $row_data['master_no'] = $rowDataArray[3] ?? null; $row_data['house_no'] = $key_identifier_cell; $row_data['declaration_no'] = $rowDataArray[1] ?? null; $row_data['declaration_type'] = $rowDataArray[2] ?? null; $temp_total = (int)($rowDataArray[7] ?? 0); $temp_in = (int)($rowDataArray[8] ?? 0); $temp_out = (int)($rowDataArray[9] ?? 0); $row_data['total_packages'] = $temp_total; $row_data['packages_in'] = $temp_in; $row_data['packages_out'] = $temp_out; $row_data['weight'] = (float)($rowDataArray[10] ?? 0); $row_data['remark'] = $rowDataArray[12] ?? null; if ($temp_total === $temp_in && $temp_in === $temp_out && $temp_total > 0) { $row_data['storage_out_datetime'] = parse_spout_date_value($rowDataArray[0] ?? null); } break;
                            case 'DECLARED_NOT_IN': $row_data['master_no'] = $rowDataArray[1]; $row_data['house_no'] = $key_identifier_cell; $row_data['declaration_no'] = $rowDataArray[4]; $row_data['declaration_type'] = $rowDataArray[5]; $row_data['total_packages'] = (int)($rowDataArray[6] ?? 0); $row_data['packages_in'] = (int)($rowDataArray[7] ?? 0); $row_data['packages_out'] = (int)($rowDataArray[8] ?? 0); $remark_value = trim($rowDataArray[12] ?? ''); $row_data['remark'] = $remark_value; if (strpos($remark_value, 'SZ') !== false) $row_data['status0'] = 5; break;
                            case 'INSTOCK_NOT_OUT': $row_data['master_no'] = $rowDataArray[1]; $row_data['house_no'] = $key_identifier_cell; $raw_declaration = $rowDataArray[4] ?? ''; $row_data['declaration_no'] = substr($raw_declaration, 0, 14); $row_data['declaration_type'] = $rowDataArray[5]; $row_data['total_packages'] = (int)($rowDataArray[6] ?? 0); $row_data['packages_in'] = (int)($rowDataArray[7] ?? 0); $row_data['packages_out'] = (int)($rowDataArray[8] ?? 0); $row_data['storage_in_datetime'] = parse_spout_date_value($rowDataArray[9] ?? null); break;
                            case 'RELEASED_NOT_OUT': $row_data['master_no'] = $rowDataArray[1]; $row_data['house_no'] = $key_identifier_cell; $row_data['declaration_no'] = $rowDataArray[3]; $row_data['declaration_type'] = $rowDataArray[4]; $temp_total = (int)($rowDataArray[7] ?? 0); $temp_in = (int)($rowDataArray[8] ?? 0); $temp_out = (int)($rowDataArray[9] ?? 0); $row_data['total_packages'] = $temp_total; $row_data['packages_in'] = $temp_in; $row_data['packages_out'] = $temp_out; $row_data['release_datetime'] = parse_spout_date_value($rowDataArray[10] ?? null); $row_data['storage_in_datetime'] = parse_spout_date_value($rowDataArray[11] ?? null); $row_data['clearance_method'] = $rowDataArray[13] ?? null; if ($temp_total === $temp_in && $temp_in === $temp_out && $temp_total > 0) { $row_data['storage_out_datetime'] = parse_spout_date_value($rowDataArray[12] ?? null); } break;
                        }
                        $data_to_insert_chunk[] = $row_data;
                        if (count($data_to_insert_chunk) >= $chunk_size) { $inserted_in_chunk = batch_insert($conn, $data_to_insert_chunk); $total_inserted_rows += $inserted_in_chunk; write_log("已寫入 " . count($data_to_insert_chunk) . " 筆資料 (累計: {$total_inserted_rows})..."); $data_to_insert_chunk = []; }
                    }
                }
                if (!empty($data_to_insert_chunk)) { $inserted_in_chunk = batch_insert($conn, $data_to_insert_chunk); $total_inserted_rows += $inserted_in_chunk; write_log("準備寫入最後 " . count($data_to_insert_chunk) . " 筆剩餘資料..."); }
                $reader->close();
                $conn->commit();
                write_log("成功: 檔案 '{$file_name}' 處理完畢。共新增 {$total_inserted_rows} 筆資料。");
                write_log("正在將 '{$file_name}' 移動至 'daily_out' 資料夾...");
                moveFileOnDrive($driveService, $file_id, SOURCE_FOLDER_ID, DESTINATION_FOLDER_ID);
                write_log("檔案移動成功。");
            } catch (Exception $e) {
                if ($transaction_started) $conn->rollback();
                write_log("嚴重錯誤: 處理檔案 '{$file_name}' 時發生例外: " . $e->getMessage());
            } finally {
                if (isset($conn) && $conn->ping()) { $conn->close(); }
                unlink($file_tmp_path);
            }
        }
    }

    // --- 步驟 2: (Load) 在所有檔案處理完畢後，執行資料彙總程序 ---
    if ($files_were_processed) {
        write_log("所有檔案匯入完成，準備開始執行資料後處理程序...");

        $conn_cleanup = new mysqli($db_config['servername'], $db_config['username'], $db_config['password'], $db_config['dbname']);
        if ($conn_cleanup->connect_error) { throw new Exception("後處理程序：資料庫連線失敗: " . $conn_cleanup->connect_error); }
        $conn_cleanup->set_charset("utf8mb4");

        try {
            // --- 【已移除】步驟 1/3: 合併重複資料 ---
            // write_log("步驟 1/3: 正在合併重複資料，創建黃金紀錄...");
            // (相關 $sql_update_golden 和 query 已被移除)

            // --- 【已移除】步驟 2/3: 刪除舊的重複資料 ---
            // write_log("步驟 2/3: 正在刪除舊的重複資料...");
            // (相關 $sql_delete_duplicates 和 query 已被移除)

            // --- 步驟 3/3: (Load) 更新 daily_arrange 總表 ---
            // **注意**: 此步驟假定外部的合併/刪除 SQL 事件已經執行完畢
            write_log("等待 180 秒 (3分鐘)，準備更新排櫃總表...");
            sleep(180);
            write_log("步驟 (Load): 正在從 daily_outbound 彙總資料並更新至 daily_arrange...");
            // 【最新修改】在此 SQL 的子查詢中加入排除 status0 = 8 的條件
            $sql_update_arrange = "
            UPDATE
                daily_arrange AS da
            JOIN
                (SELECT
                    master_no,
                    SUM(IF(storage_out_datetime IS NOT NULL, packages_out, 0)) AS calculated_inandout,
                    SUM(IF(storage_in_datetime IS NOT NULL AND storage_out_datetime IS NULL, packages_in, 0)) AS calculated_innoout,
                    SUM(IF(storage_in_datetime IS NULL AND storage_out_datetime IS NULL, total_packages, 0)) AS calculated_noin,
                    MAX(release_datetime) AS calculated_release_datetime
                FROM
                    daily_outbound
                WHERE
                    master_no IS NOT NULL AND master_no != ''
                    AND (status0 IS NULL OR status0 != 8) -- **新增排除短卸條件**
                GROUP BY
                    master_no
                ) AS stats ON da.bl_number = stats.master_no
            SET
                da.inandout = stats.calculated_inandout,
                da.innoout = stats.calculated_innoout,
                da.noin = stats.calculated_noin,
                da.nodeclare = da.quantity - stats.calculated_inandout - stats.calculated_innoout - stats.calculated_noin,
                da.status = CASE
                    WHEN stats.calculated_inandout > 300 THEN 1 -- 使用您之前確認的 > 300 邏輯
                    ELSE da.status
                END,
                da.scale = CASE
                    WHEN da.quantity > 0 THEN
                        ROUND(((stats.calculated_inandout + stats.calculated_innoout + stats.calculated_noin) / da.quantity) * 100, 1)
                    ELSE
                        0.0
                END,
                da.release_datetime = stats.calculated_release_datetime";
            
            if ($conn_cleanup->query($sql_update_arrange) === TRUE) {
                write_log("步驟 (Load) 成功: " . $conn_cleanup->affected_rows . " 筆排櫃總表紀錄已更新。");
            } else {
                throw new Exception("步驟 (Load) 失敗: " . $conn_cleanup->error);
            }

            write_log("排櫃總表(Arrange)更新程序成功執行完畢。");

        } catch (Exception $e) {
            write_log("嚴重錯誤：在執行資料後處理程序時發生例外: " . $e->getMessage());
        } finally {
            if (isset($conn_cleanup) && $conn_cleanup->ping()) {
                $conn_cleanup->close();
            }
        }
    }

} catch (Exception $e) {
    write_log("致命錯誤: 腳本執行中斷: " . $e->getMessage());
}

write_log("==== Original files ETL cron job finished. ====\n");
?>