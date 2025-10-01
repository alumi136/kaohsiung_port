<?php
// ☆☆☆☆ 這是一個 CLI (命令列介面) 腳本 ☆☆☆☆
// 說明: 智慧判斷 Excel 格式，使用 spout (處理 .xlsx) 或 PhpSpreadsheet (處理 .xls) 來高效處理檔案。

set_time_limit(3600);
ini_set('memory_limit', '512M');

require __DIR__ . '/vendor/autoload.php';

// 【*** 核心變更：同時引入兩個函式庫 ***】
use Google\Client as Google_Client;
use Google\Service\Drive as Google_Service_Drive;
use Box\Spout\Reader\Common\Creator\ReaderEntityFactory;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date;

// --- ☆☆☆ 設定 (無變更) ☆☆☆ ---
const SERVICE_ACCOUNT_KEY_PATH = __DIR__ . '/credentials.json';
const SOURCE_FOLDER_ID = '1_iMIDmPzZXf9c9QmL9sL94Xpj6rm1f4Y';
const DESTINATION_FOLDER_ID = '1604iZtjVqSibbJpT_KBNNWJyTLBINeeU';
$db_config = [
    'servername' => "localhost",
    'username' => "alumi136",
    'password' => "Alumi!36",
    'dbname' => "kaohsiung_port_db"
];
const TARGET_TABLE = 'daily_outbound';
const LOG_FILE = __DIR__ . '/daily_outbound.log';

// 【*** 邏輯修正：將 XlsChunkReadFilter 的定義移至檔案頂部 ***】
// 為了降低記憶體，我們一樣採用分塊讀取
class XlsChunkReadFilter implements \PhpOffice\PhpSpreadsheet\Reader\IReadFilter {
    private $startRow = 0; private $endRow = 0;
    public function setRows($startRow, $chunkSize) { $this->startRow = $startRow; $this->endRow = $startRow + $chunkSize; }
    public function readCell($columnAddress, $row, $worksheetName = '') { return ($row >= $this->startRow && $row < $this->endRow); }
}


// --- 輔助函式庫 ---
function write_log($message) {
    $memory = round(memory_get_usage(true) / 1024 / 1024, 2) . " MB";
    $timestamp = date('Y-m-d H:i:s');
    $formatted_message = "[{$timestamp}] [Mem: {$memory}] " . $message . PHP_EOL;
    file_put_contents(LOG_FILE, $formatted_message, FILE_APPEND);
    echo $formatted_message;
}

function parse_date_value($value) {
    if (empty($value)) return null;
    if (is_numeric($value)) return Date::excelToDateTimeObject($value)->format('Y-m-d H:i:s');
    if ($value instanceof \DateTime) return $value->format('Y-m-d H:i:s');
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
        storage_out_datetime, status, customer_name, remark, status0
    ) VALUES ";
    $placeholders = []; $params = []; $types = '';
    foreach ($data as $row) {
        $placeholders[] = '(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
        array_push($params, 
            $row['declaration_no'], $row['master_no'], $row['house_no'], $row['weight'], $row['total_packages'], 
            $row['packages_in'], $row['packages_out'], $row['clearance_method'], $row['declaration_type'], 
            $row['carrier_id'], $row['route'], $row['storage_in_datetime'], $row['storage_out_datetime'], 
            $row['status'], $row['customer_name'], $row['remark'], $row['status0']
        );
        $types .= 'sssdiissssssssssi';
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

// ... Google Drive 相關函式 (無變更) ...
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
write_log("==== Cron job started (v3.2 - XLS Chunk Optimization). ====");

try {
    $driveService = getGoogleDriveClient();
    $queryParams = ['q' => "'" . SOURCE_FOLDER_ID . "' in parents and (mimeType='application/vnd.ms-excel' or mimeType='application/vnd.openxmlformats-officedocument.spreadsheetml.sheet') and trashed=false", 'pageSize' => 10, 'fields' => "files(id, name)"];
    $results = $driveService->files->listFiles($queryParams);

    if (count($results->getFiles()) == 0) {
        write_log("在 'daily_in' 資料夾中未找到新檔案。");
    } else {
        foreach ($results->getFiles() as $file) {
            $file_id = $file->getId();
            $file_name = $file->getName();
            write_log("正在處理檔案: {$file_name} (ID: {$file_id})");

            $response = $driveService->files->get($file_id, ['alt' => 'media']);
            $file_content = $response->getBody()->getContents();

            $file_extension = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
            $file_tmp_path = sys_get_temp_dir() . '/' . uniqid('drive_import_', true) . '.' . $file_extension;
            file_put_contents($file_tmp_path, $file_content);
            write_log("檔案已下載至臨時路徑: {$file_tmp_path}");

            $total_inserted_rows = 0;
            $conn = new mysqli($db_config['servername'], $db_config['username'], $db_config['password'], $db_config['dbname']);
            if ($conn->connect_error) throw new Exception("資料庫連線失敗: " . $conn->connect_error);
            $conn->set_charset("utf8mb4");

            $transaction_started = false;
            try {
                $conn->begin_transaction();
                $transaction_started = true;
                
                if ($file_extension === 'xlsx') {
                    write_log("偵測到 XLSX 格式，使用 Spout 串流模式處理...");
                    $total_inserted_rows = processWithSpout($conn, $file_tmp_path);
                } else {
                    write_log("偵測到 XLS 或其他格式，使用 PhpSpreadsheet 相容模式處理...");
                    $total_inserted_rows = processWithPhpSpreadsheet($conn, $file_tmp_path);
                }

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

write_log("==== Cron job finished. ====\n");


// 【*** Spout 處理器 (無變更) ***】
function processWithSpout($conn, $filePath) {
    $reader = ReaderEntityFactory::createReaderFromFile($filePath);
    $reader->open($filePath);
    
    $total_rows = 0;
    $chunk_size = 2000;
    $data_to_insert_chunk = [];

    foreach ($reader->getSheetIterator() as $sheet) {
        foreach ($sheet->getRowIterator() as $rowIndex => $row) {
            if ($rowIndex === 1) continue;
            
            $rowDataArray = $row->toArray();
            
            $house_no_cell = trim($rowDataArray[2] ?? '');
            if (empty($house_no_cell)) continue;
            
            $row_data = [
                'declaration_no' => $rowDataArray[0] ?? null, 'master_no' => $rowDataArray[1] ?? null, 'house_no' => $house_no_cell,
                'weight' => !empty($rowDataArray[3]) ? (float)$rowDataArray[3] : 0,
                'total_packages' => !empty($rowDataArray[4]) ? (int)$rowDataArray[4] : 0,
                'packages_in' => !empty($rowDataArray[5]) ? (int)$rowDataArray[5] : 0,
                'packages_out' => !empty($rowDataArray[6]) ? (int)$rowDataArray[6] : 0,
                'clearance_method' => $rowDataArray[7] ?? null, 'declaration_type' => $rowDataArray[8] ?? null,
                'carrier_id' => $rowDataArray[9] ?? null, 'route' => $rowDataArray[10] ?? null,
                'storage_in_datetime' => parse_date_value($rowDataArray[11] ?? null),
                'storage_out_datetime' => parse_date_value($rowDataArray[12] ?? null),
                'status' => $rowDataArray[13] ?? null, 'customer_name' => $rowDataArray[14] ?? null,
                'remark' => null, 'status0' => 0
            ];
            $data_to_insert_chunk[] = $row_data;

            if (count($data_to_insert_chunk) >= $chunk_size) {
                $inserted = batch_insert($conn, $data_to_insert_chunk);
                $total_rows += $inserted;
                write_log("Spout: 已寫入 {$inserted} 筆資料 (累計: {$total_rows})...");
                $data_to_insert_chunk = [];
            }
        }
    }

    if (!empty($data_to_insert_chunk)) {
        $inserted = batch_insert($conn, $data_to_insert_chunk);
        $total_rows += $inserted;
        write_log("Spout: 已寫入最後 {$inserted} 筆資料 (累計: {$total_rows})...");
    }
    
    $reader->close();
    return $total_rows;
}

// 【*** PhpSpreadsheet 處理器 (已優化) ***】
function processWithPhpSpreadsheet($conn, $filePath) {
    $reader = IOFactory::createReaderForFile($filePath);
    $reader->setReadDataOnly(true);
    
    $total_rows = 0;
    $chunk_size = 2000; // 【優化】將批次大小提升至 2000
    $chunkFilter = new XlsChunkReadFilter();
    $reader->setReadFilter($chunkFilter);

    $worksheetInfo = $reader->listWorksheetInfo($filePath);
    $highestRow = $worksheetInfo[0]['totalRows'];
    write_log("PhpSpreadsheet: 偵測到總行數: {$highestRow}");

    for ($startRow = 2; $startRow <= $highestRow; $startRow += $chunk_size) {
        // 【優化】增加詳細日誌
        write_log("PhpSpreadsheet: 準備讀取第 {$startRow} 行至 " . ($startRow + $chunk_size - 1) . " 行...");
        $chunkFilter->setRows($startRow, $chunk_size);
        $spreadsheet = $reader->load($filePath);
        $worksheet = $spreadsheet->getActiveSheet();
        write_log("PhpSpreadsheet: 區塊已載入記憶體。");
        
        $data_to_insert_chunk = [];
        // 【優化】改用 rangeToArray 一次性讀取區塊資料
        $chunkData = $worksheet->rangeToArray('A' . $startRow . ':' . $worksheet->getHighestColumn() . ($startRow + $chunk_size - 1), null, true, true, true);

        foreach ($chunkData as $rowIndex => $rowDataArray) {
            $house_no_cell = trim($rowDataArray['C'] ?? '');
            if (empty($house_no_cell)) continue;

            $row_data = [
                'declaration_no' => $rowDataArray['A'] ?? null, 'master_no' => $rowDataArray['B'] ?? null, 'house_no' => $house_no_cell,
                'weight' => !empty($rowDataArray['D']) ? (float)$rowDataArray['D'] : 0,
                'total_packages' => !empty($rowDataArray['E']) ? (int)$rowDataArray['E'] : 0,
                'packages_in' => !empty($rowDataArray['F']) ? (int)$rowDataArray['F'] : 0,
                'packages_out' => !empty($rowDataArray['G']) ? (int)$rowDataArray['G'] : 0,
                'clearance_method' => $rowDataArray['H'] ?? null, 'declaration_type' => $rowDataArray['I'] ?? null,
                'carrier_id' => $rowDataArray['J'] ?? null, 'route' => $rowDataArray['K'] ?? null,
                'storage_in_datetime' => parse_date_value($rowDataArray['L'] ?? null),
                'storage_out_datetime' => parse_date_value($rowDataArray['M'] ?? null),
                'status' => $rowDataArray['N'] ?? null, 'customer_name' => $rowDataArray['O'] ?? null,
                'remark' => null, 'status0' => 0
            ];
            $data_to_insert_chunk[] = $row_data;
        }

        if (!empty($data_to_insert_chunk)) {
            $inserted = batch_insert($conn, $data_to_insert_chunk);
            $total_rows += $inserted;
            write_log("PhpSpreadsheet: 已寫入 {$inserted} 筆資料 (累計: {$total_rows})...");
        }
        
        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);
        write_log("PhpSpreadsheet: 區塊記憶體已釋放。");
    }
    return $total_rows;
}
?>

