<?php
// ☆☆☆☆ 這是一個 CLI (命令列介面) 腳本 ☆☆☆☆
// 說明: 智慧判斷 Excel 格式，並使用最佳的串流讀取器來高效處理檔案。

set_time_limit(3600);
ini_set('memory_limit', '512M');

require __DIR__ . '/vendor/autoload.php';

// 【*** 核心變更：同時引入所有需要的函式庫 ***】
use Google\Client as Google_Client;
use Google\Service\Drive as Google_Service_Drive;
use Box\Spout\Reader\Common\Creator\ReaderEntityFactory;
use Shuchkin\SimpleXLS;
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

// --- IReadFilter for PhpSpreadsheet ---
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
    if ($value instanceof \DateTime) return $value->format('Y-m-d H:i:s');
    if (is_numeric($value)) return Date::excelToDateTimeObject($value)->format('Y-m-d H:i:s');
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
write_log("==== Cron job started (v7.0 - Fallback Logic). ====");

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
                } elseif ($file_extension === 'xls') {
                    write_log("偵測到 XLS 格式，優先嘗試 SimpleXLS 串流模式...");
                    $total_inserted_rows = processWithSimpleXLS($conn, $file_tmp_path);
                } else {
                    throw new Exception("不支援的檔案格式: {$file_extension}");
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


// 【*** Spout 處理器 (for .xlsx) - 無變更 ***】
function processWithSpout($conn, $filePath) {
    // ... 此函式內容與 v6.0 版本完全相同 ...
    $reader = ReaderEntityFactory::createReaderFromFile($filePath);
    $reader->open($filePath);
    $total_rows = 0; $chunk_size = 2000; $data_to_insert_chunk = [];
    foreach ($reader->getSheetIterator() as $sheet) {
        foreach ($sheet->getRowIterator() as $rowIndex => $row) {
            if ($rowIndex === 1) continue;
            $rowDataArray = $row->toArray();
            $house_no_cell = trim($rowDataArray[2] ?? '');
            if (empty($house_no_cell)) continue;
            $row_data = [
                'declaration_no' => $rowDataArray[0] ?? null, 'master_no' => $rowDataArray[1] ?? null, 'house_no' => $house_no_cell,
                'weight' => !empty($rowDataArray[3]) ? (float)$rowDataArray[3] : 0,
                'total_packages' => !empty($rowDataArray[4]) ? (int)$rowDataArray[4] : 0, 'packages_in' => !empty($rowDataArray[5]) ? (int)$rowDataArray[5] : 0, 'packages_out' => !empty($rowDataArray[6]) ? (int)$rowDataArray[6] : 0,
                'clearance_method' => $rowDataArray[7] ?? null, 'declaration_type' => $rowDataArray[8] ?? null,
                'carrier_id' => $rowDataArray[9] ?? null, 'route' => $rowDataArray[10] ?? null,
                'storage_in_datetime' => parse_date_value($rowDataArray[11] ?? null),
                'storage_out_datetime' => parse_date_value($rowDataArray[12] ?? null),
                'status' => $rowDataArray[13] ?? null, 'customer_name' => $rowDataArray[14] ?? null,
                'remark' => null, 'status0' => 0
            ];
            $data_to_insert_chunk[] = $row_data;
            if (count($data_to_insert_chunk) >= $chunk_size) {
                $inserted = batch_insert($conn, $data_to_insert_chunk); $total_rows += $inserted;
                write_log("Spout: 已寫入 {$inserted} 筆資料 (累計: {$total_rows})..."); $data_to_insert_chunk = [];
            }
        }
    }
    if (!empty($data_to_insert_chunk)) {
        $inserted = batch_insert($conn, $data_to_insert_chunk); $total_rows += $inserted;
        write_log("Spout: 已寫入最後 {$inserted} 筆資料 (累計: {$total_rows})...");
    }
    $reader->close(); return $total_rows;
}

// 【*** 全新函式：整合 SimpleXLS 與 PhpSpreadsheet 作為備用方案 ***】
function processWithSimpleXLS($conn, $filePath) {
    if ( $xls = SimpleXLS::parse($filePath) ) {
        // 如果成功，代表是標準的 XLS 檔案，使用高效能的 SimpleXLS
        write_log("SimpleXLS: 成功解析為標準 XLS 檔案，開始串流處理...");
        $total_rows = 0;
        $chunk_size = 2000;
        $data_to_insert_chunk = [];
        $is_header = true;

        foreach( $xls->rows() as $rowDataArray ) {
            if ($is_header) { $is_header = false; continue; }
            $house_no_cell = trim($rowDataArray[2] ?? '');
            if (empty($house_no_cell)) continue;
            $row_data = [
                'declaration_no' => $rowDataArray[0] ?? null, 'master_no' => $rowDataArray[1] ?? null, 'house_no' => $house_no_cell,
                'weight' => !empty($rowDataArray[3]) ? (float)$rowDataArray[3] : 0,
                'total_packages' => !empty($rowDataArray[4]) ? (int)$rowDataArray[4] : 0, 'packages_in' => !empty($rowDataArray[5]) ? (int)$rowDataArray[5] : 0, 'packages_out' => !empty($rowDataArray[6]) ? (int)$rowDataArray[6] : 0,
                'clearance_method' => $rowDataArray[7] ?? null, 'declaration_type' => $rowDataArray[8] ?? null,
                'carrier_id' => $rowDataArray[9] ?? null, 'route' => $rowDataArray[10] ?? null,
                'storage_in_datetime' => parse_date_value($rowDataArray[11] ?? null),
                'storage_out_datetime' => parse_date_value($rowDataArray[12] ?? null),
                'status' => $rowDataArray[13] ?? null, 'customer_name' => $rowDataArray[14] ?? null,
                'remark' => null, 'status0' => 0
            ];
            $data_to_insert_chunk[] = $row_data;
            if (count($data_to_insert_chunk) >= $chunk_size) {
                $inserted = batch_insert($conn, $data_to_insert_chunk); $total_rows += $inserted;
                write_log("SimpleXLS: 已寫入 {$inserted} 筆資料 (累計: {$total_rows})..."); $data_to_insert_chunk = [];
            }
        }
        if (!empty($data_to_insert_chunk)) {
            $inserted = batch_insert($conn, $data_to_insert_chunk); $total_rows += $inserted;
            write_log("SimpleXLS: 已寫入最後 {$inserted} 筆資料 (累計: {$total_rows})...");
        }
        return $total_rows;
    } else {
        // 如果失敗，檢查是否為 "File is not XLS" 錯誤
        if (strpos(SimpleXLS::parseError(), 'File is not XLS') !== false) {
            write_log("SimpleXLS 解析失敗: " . SimpleXLS::parseError() . "。這可能是一個偽裝的 XLS 檔案，正在啟動 PhpSpreadsheet 作為備用方案...");
            // 如果是，則呼叫 PhpSpreadsheet 進行處理
            return processWithPhpSpreadsheet($conn, $filePath);
        } else {
            // 如果是其他類型的錯誤，則直接拋出
            throw new Exception('SimpleXLS 解析時發生未知錯誤: ' . SimpleXLS::parseError());
        }
    }
}

// 【*** 備用函式：使用 PhpSpreadsheet 處理非標準的 XLS 檔案 ***】
function processWithPhpSpreadsheet($conn, $filePath) {
    $reader = IOFactory::createReaderForFile($filePath);
    $reader->setReadDataOnly(true);
    
    $total_rows = 0;
    $chunk_size = 1000; // 對於可能較耗資源的 PhpSpreadsheet，批次縮小一點
    $chunkFilter = new XlsChunkReadFilter();
    $reader->setReadFilter($chunkFilter);

    $worksheetInfo = $reader->listWorksheetInfo($filePath);
    $highestRow = $worksheetInfo[0]['totalRows'];
    write_log("PhpSpreadsheet (備用): 偵測到總行數: {$highestRow}");

    for ($startRow = 2; $startRow <= $highestRow; $startRow += $chunk_size) {
        write_log("PhpSpreadsheet (備用): 準備讀取第 {$startRow} 行至 " . ($startRow + $chunk_size - 1) . " 行...");
        $chunkFilter->setRows($startRow, $chunk_size);
        $spreadsheet = $reader->load($filePath);
        $worksheet = $spreadsheet->getActiveSheet();
        
        $data_to_insert_chunk = [];
        $chunkData = $worksheet->rangeToArray('A' . $startRow . ':' . $worksheet->getHighestColumn() . ($startRow + $chunk_size - 1), null, true, true, true);

        foreach ($chunkData as $rowIndex => $rowDataArray) {
            $house_no_cell = trim($rowDataArray['C'] ?? '');
            if (empty($house_no_cell)) continue;
            $row_data = [
                'declaration_no' => $rowDataArray['A'] ?? null, 'master_no' => $rowDataArray['B'] ?? null, 'house_no' => $house_no_cell,
                'weight' => !empty($rowDataArray['D']) ? (float)$rowDataArray['D'] : 0,
                'total_packages' => !empty($rowDataArray['E']) ? (int)$rowDataArray['E'] : 0, 'packages_in' => !empty($rowDataArray['F']) ? (int)$rowDataArray['F'] : 0, 'packages_out' => !empty($rowDataArray['G']) ? (int)$rowDataArray['G'] : 0,
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
            write_log("PhpSpreadsheet (備用): 已寫入 {$inserted} 筆資料 (累計: {$total_rows})...");
        }
        
        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);
    }
    return $total_rows;
}
?>

