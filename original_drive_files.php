<?php
// ☆☆☆☆ 這是一個 CLI (命令列介面) 腳本 ☆☆☆☆
// 任務：從 Google Drive 下載多種格式的 Excel 檔案，分析後寫入 daily_outbound 資料表。

set_time_limit(3600); // 增加腳本最大執行時間至 1 小時
ini_set('memory_limit', '512M');

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

function parse_spout_date_value($value) {
    if (empty($value)) return null;
    if ($value instanceof \DateTime) {
        return $value->format('Y-m-d H:i:s');
    }
    if (is_string($value)) {
        try {
            $dt = new DateTime($value);
            return $dt->format('Y-m-d H:i:s');
        } catch (Exception $e) { return null; }
    }
    return null;
}

function batch_insert(mysqli $conn, array $data): int {
    if (empty($data)) return 0;
    $sql = "INSERT INTO " . TARGET_TABLE . " (
        declaration_no, master_no, house_no, weight, total_packages, packages_in, packages_out, 
        clearance_method, declaration_type, carrier_id, route, storage_in_datetime, 
        storage_out_datetime, release_datetime, status, customer_name, remark, status0
    ) VALUES ";
    $placeholders = []; $params = []; $types = '';
    foreach ($data as $row) {
        $placeholders[] = '(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
        array_push($params, 
            $row['declaration_no'], $row['master_no'], $row['house_no'], $row['weight'], $row['total_packages'], 
            $row['packages_in'], $row['packages_out'], $row['clearance_method'], $row['declaration_type'], 
            $row['carrier_id'], $row['route'], $row['storage_in_datetime'], $row['storage_out_datetime'], 
            $row['release_datetime'], $row['status'], $row['customer_name'], $row['remark'], $row['status0']
        );
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
            $service->files->update($fileId, new \Google\Service\Drive\DriveFile(), [
                'addParents' => $destFolderId, 'removeParents' => $sourceFolderId, 'fields' => 'id, parents'
            ]);
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
write_log("==== Original files cron job started (v29 - Handover List Logic). ====");

try {
    $driveService = getGoogleDriveClient();
    $queryParams = ['q' => "'" . SOURCE_FOLDER_ID . "' in parents and (mimeType='application/vnd.ms-excel' or mimeType='application/vnd.openxmlformats-officedocument.spreadsheetml.sheet') and trashed=false", 'pageSize' => 10, 'fields' => "files(id, name)"];
    $results = $driveService->files->listFiles($queryParams);

    if (count($results->getFiles()) == 0) {
        write_log("在 'original_daily_in' 資料夾中未找到新檔案。");
    } else {
        foreach ($results->getFiles() as $file) {
            $file_id = $file->getId();
            $file_name = $file->getName();
            write_log("正在處理檔案: {$file_name} (ID: {$file_id})");

            $response = $driveService->files->get($file_id, ['alt' => 'media']);
            $file_content = $response->getBody()->getContents();

            $file_extension = pathinfo($file_name, PATHINFO_EXTENSION);
            $file_tmp_path = sys_get_temp_dir() . '/' . uniqid('drive_import_', true) . '.' . $file_extension;
            file_put_contents($file_tmp_path, $file_content);
            write_log("檔案已下載至臨時路徑: {$file_tmp_path}");

            $total_inserted_rows = 0;
            $conn = new mysqli($db_config['servername'], $db_config['username'], $db_config['password'], $db_config['dbname']);
            if ($conn->connect_error) throw new Exception("資料庫連線失敗: " . $conn->connect_error);
            $conn->set_charset("utf8mb4");

            $transaction_started = false;
            try {
                $reader = ReaderEntityFactory::createReaderFromFile($file_tmp_path);
                $reader->open($file_tmp_path);
                
                $file_type = null;
                $headerString = '';

                // 1. 讀取第一行以判斷檔案類型
                foreach ($reader->getSheetIterator() as $sheet) {
                    foreach ($sheet->getRowIterator() as $rowIndex => $row) {
                        $headerRow = $row->toArray();
                        $headerString = implode(',', array_map('trim', $headerRow));
                        break;
                    }
                    break;
                }
                
                // 【*** 新增邏輯：增加對「進口出倉交接清表」的判斷 ***】
                if (strpos($headerString, '出倉時間') !== false && strpos($headerString, '申報重量') !== false) {
                    $file_type = 'HANDOVER_LIST';
                } elseif (strpos($headerString, '放行時間') !== false) {
                    $file_type = 'RELEASED_NOT_OUT';
                } elseif (strpos($headerString, '進倉時間') !== false) {
                    $file_type = 'INSTOCK_NOT_OUT';
                } elseif (strpos($headerString, '有無艙單') !== false) {
                    $file_type = 'DECLARED_NOT_IN';
                }

                if (!$file_type) throw new Exception("無法從第一行的欄位標頭識別檔案類型。 Header: " . $headerString);
                
                write_log("檔案類型識別為: {$file_type}");
                $reader->close();

                $conn->begin_transaction();
                $transaction_started = true;
                
                // 2. 重新開啟讀取器，開始逐行串流處理
                $reader->open($file_tmp_path);
                $data_to_insert_chunk = [];
                $chunk_size = 2000;
                
                foreach ($reader->getSheetIterator() as $sheet) {
                    foreach ($sheet->getRowIterator() as $rowIndex => $row) {
                        if ($rowIndex === 1) continue;
                        
                        $rowDataArray = $row->toArray();
                        
                        $key_identifier_cell = '';
                        if($file_type === 'HANDOVER_LIST'){
                            $key_identifier_cell = trim($rowDataArray[4] ?? ''); // 交接清單用 E 欄 (分號)
                        } else {
                            $key_identifier_cell = trim($rowDataArray[2] ?? ''); // 其他清單用 C 欄 (分號)
                        }
                        if (empty($key_identifier_cell)) continue;
                        
                        $row_data = [
                            'declaration_no' => null, 'master_no' => null, 'house_no' => null, 'weight' => null, 
                            'total_packages' => null, 'packages_in' => null, 'packages_out' => null, 
                            'clearance_method' => null, 'declaration_type' => null, 'carrier_id' => null, 
                            'route' => null, 'storage_in_datetime' => null, 'storage_out_datetime' => null, 
                            'release_datetime' => null, 'status' => null, 'customer_name' => null,
                            'remark' => null, 'status0' => 0
                        ];

                        switch ($file_type) {
                            case 'HANDOVER_LIST': // 【*** 新增檔案類型的處理邏輯 ***】
                                $row_data['storage_out_datetime'] = parse_spout_date_value($rowDataArray[0] ?? null); // A
                                $row_data['declaration_no'] = $rowDataArray[1] ?? null; // B
                                $row_data['declaration_type'] = $rowDataArray[2] ?? null; // C
                                $row_data['master_no'] = $rowDataArray[3] ?? null; // D
                                $row_data['house_no'] = $key_identifier_cell; // E
                                $row_data['total_packages'] = (int)($rowDataArray[7] ?? 0); // H
                                $row_data['packages_in'] = (int)($rowDataArray[8] ?? 0); // I
                                $row_data['packages_out'] = (int)($rowDataArray[9] ?? 0); // J
                                $row_data['weight'] = (float)($rowDataArray[10] ?? 0); // K (申報重量)
                                $row_data['remark'] = $rowDataArray[12] ?? null; // M
                                break;
                            
                            case 'DECLARED_NOT_IN':
                                $row_data['master_no'] = $rowDataArray[1]; // B
                                $row_data['house_no'] = $key_identifier_cell; // C
                                $row_data['declaration_no'] = $rowDataArray[4]; // E
                                $row_data['declaration_type'] = $rowDataArray[5]; // F
                                $row_data['total_packages'] = (int)($rowDataArray[6] ?? 0); // G
                                $row_data['packages_in'] = (int)($rowDataArray[7] ?? 0); // H
                                $row_data['packages_out'] = (int)($rowDataArray[8] ?? 0); // I
                                $remark_value = trim($rowDataArray[12] ?? ''); // M
                                $row_data['remark'] = $remark_value;
                                if (strpos($remark_value, 'SZ') !== false) $row_data['status0'] = 5;
                                break;
                            case 'INSTOCK_NOT_OUT':
                                $row_data['master_no'] = $rowDataArray[1]; // B
                                $row_data['house_no'] = $key_identifier_cell; // C
                                $raw_declaration = $rowDataArray[4] ?? ''; // E
                                $row_data['declaration_no'] = substr($raw_declaration, 0, 14);
                                $row_data['declaration_type'] = $rowDataArray[5]; // F
                                $row_data['total_packages'] = (int)($rowDataArray[6] ?? 0); // G
                                $row_data['packages_in'] = (int)($rowDataArray[7] ?? 0); // H
                                $row_data['packages_out'] = (int)($rowDataArray[8] ?? 0); // I
                                $row_data['storage_in_datetime'] = parse_spout_date_value($rowDataArray[9] ?? null); // J
                                break;
                            case 'RELEASED_NOT_OUT':
                                $row_data['master_no'] = $rowDataArray[1]; // B
                                $row_data['house_no'] = $key_identifier_cell; // C
                                $row_data['declaration_no'] = $rowDataArray[3]; // D
                                $row_data['declaration_type'] = $rowDataArray[4]; // E
                                $row_data['total_packages'] = (int)($rowDataArray[7] ?? 0); // H
                                $row_data['packages_in'] = (int)($rowDataArray[8] ?? 0); // I
                                $row_data['packages_out'] = (int)($rowDataArray[9] ?? 0); // J
                                $row_data['release_datetime'] = parse_spout_date_value($rowDataArray[10] ?? null); // K
                                $row_data['storage_in_datetime'] = parse_spout_date_value($rowDataArray[11] ?? null); // L
                                $row_data['storage_out_datetime'] = parse_spout_date_value($rowDataArray[12] ?? null); // M
                                $row_data['clearance_method'] = $rowDataArray[13] ?? null; // N
                                break;
                        }
                        $data_to_insert_chunk[] = $row_data;

                        if (count($data_to_insert_chunk) >= $chunk_size) {
                            write_log("已讀取 {$chunk_size} 筆資料，準備寫入資料庫...");
                            $inserted_in_chunk = batch_insert($conn, $data_to_insert_chunk);
                            $total_inserted_rows += $inserted_in_chunk;
                            write_log("寫入成功 (累計: {$total_inserted_rows})。");
                            $data_to_insert_chunk = [];
                        }
                    }
                }
                // 處理最後不足一個批次的剩餘資料
                if (!empty($data_to_insert_chunk)) {
                    write_log("準備寫入最後 " . count($data_to_insert_chunk) . " 筆剩餘資料...");
                    $inserted_in_chunk = batch_insert($conn, $data_to_insert_chunk);
                    $total_inserted_rows += $inserted_in_chunk;
                    write_log("寫入成功 (累計: {$total_inserted_rows})。");
                }
                
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
                $conn->close();
                unlink($file_tmp_path);
            }
        }
    }
} catch (Exception $e) {
    write_log("致命錯誤: 腳本執行中斷: " . $e->getMessage());
}

write_log("==== Original files cron job finished. ====\n");
?>

