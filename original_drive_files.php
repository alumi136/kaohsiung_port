<?php
// ☆☆☆☆ 這是一個 CLI (命令列介面) 腳本，不應透過網頁瀏覽器執行 ☆☆☆☆

// --- 資源優化設定 ---
set_time_limit(1800); // 增加腳本最大執行時間至 1800 秒 (30 分鐘)
ini_set('memory_limit', '512M'); // 提高記憶體限制至 512MB

// 引入 Composer 的 autoloader
// 請根據您的實際路徑修改
require '/var/www/google-drive-importer/vendor/autoload.php';

use Google\Client as Google_Client;
use Google\Service\Drive as Google_Service_Drive;
use PhpOffice\Phpspreadsheet\IOFactory;
use PhpOffice\Phpspreadsheet\Shared\Date;

// --- ☆☆☆ 請在這裡填寫您的設定 ☆☆☆ ---

// 1. Google 服務帳號金鑰檔案的完整路徑
const SERVICE_ACCOUNT_KEY_PATH = '/var/www/google-drive-importer/credentials.json';

// 2. 新的來源資料夾 (original_daily_in) 的 ID
const SOURCE_FOLDER_ID = 'ogiginal_daily_in'; // <-- 請替換為您實際的資料夾 ID

// 3. 目的地資料夾 (daily_out) 的 ID (與舊腳本共用)
const DESTINATION_FOLDER_ID = '1604iZtjVqSibbJpT_KBNNWJyTLBINeeU';

// 4. 資料庫連線設定
$servername = "localhost";
$username = "alumi136";
$password = "Alumi!36";
$dbname = "kaohsiung_port_db";

// 5. 新的日誌檔案路徑
const LOG_FILE = '/var/www/google-drive-importer/original_import.log';

// 6. 【*** 新增 ***】指定要寫入的目標資料表 (測試用)
const TARGET_TABLE_NAME = 'daily_outbound_test';


// --- ☆☆☆ 設定結束 ☆☆☆ ---


// (沿用 process_drive_files.php 的輔助函式庫，此處省略以保持簡潔)
// ... ChunkReadFilter, write_log, parse_date_value, getGoogleDriveClient, moveFileOnDrive ...

/**
 * 實作 IReadFilter 介面，用於分塊讀取 Excel 檔案，大幅降低記憶體消耗。
 */
class ChunkReadFilter implements \PhpOffice\PhpSpreadsheet\Reader\IReadFilter
{
    private int $startRow = 0;
    private int $endRow = 0;

    public function setRows(int $startRow, int $chunkSize): void {
        $this->startRow = $startRow;
        $this->endRow = $startRow + $chunkSize;
    }

    public function readCell($columnAddress, $row, $worksheetName = ''): bool {
        // 我們需要讀取標頭，所以調整邏輯以包含前面的行
        if ($this->startRow <= 1) { // 第一次讀取時，連同標頭一起讀
            return true;
        }
        return ($row >= $this->startRow && $row < $this->endRow);
    }
}

// --- 日誌功能 ---
function write_log($message) {
    $timestamp = date('Y-m-d H:i:s');
    $formatted_message = "[{$timestamp}] " . $message . PHP_EOL;
    file_put_contents(LOG_FILE, $formatted_message, FILE_APPEND);
    echo $formatted_message;
}

// --- 日期解析功能 ---
function parse_date_value($value) {
    if (empty($value)) return null;
    if (is_numeric($value)) return Date::excelToDateTimeObject($value)->format('Y-m-d H:i:s');
    if (is_string($value)) {
        $timestamp = strtotime($value);
        if ($timestamp !== false) return date('Y-m-d H:i:s', $timestamp);
    }
    return null; // 如果無法解析，返回 null
}

/**
 * 初始化並返回 Google Drive 服務客戶端
 */
function getGoogleDriveClient(): Google_Service_Drive {
    $client = new Google_Client();
    $client->setApplicationName('Kaohsiung Port Original Importer');
    $client->setScopes([Google_Service_Drive::DRIVE]);
    $client->setAuthConfig(SERVICE_ACCOUNT_KEY_PATH);
    $client->setAccessType('offline');
    return new Google_Service_Drive($client);
}

/**
 * 在 Google Drive 中移動檔案
 */
function moveFileOnDrive(Google_Service_Drive $service, string $fileId, string $sourceFolderId, string $destFolderId): void {
    $emptyFileMetadata = new \Google\Service\Drive\DriveFile();
    $service->files->update($fileId, $emptyFileMetadata, [
        'addParents' => $destFolderId,
        'removeParents' => $sourceFolderId,
        'fields' => 'id, parents'
    ]);
}

/**
 * 批次寫入資料到資料庫
 */
function batch_insert(mysqli $conn, array $data): int {
    if (empty($data)) return 0;
    // 欄位列表已移除 remark 和 status0，因為新格式沒有
    // 【*** 修改 ***】加入新的 release_datetime 欄位，並使用 TARGET_TABLE_NAME 常數
    $sql = "INSERT INTO " . TARGET_TABLE_NAME . " (
        declaration_no, master_no, house_no, weight, total_packages, 
        packages_in, packages_out, clearance_method, declaration_type, carrier_id, 
        route, storage_in_datetime, storage_out_datetime, release_datetime, status, customer_name
    ) VALUES ";
    $placeholders = []; $params = []; $types = '';
    foreach ($data as $row) {
        $placeholders[] = '(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'; // 增加一個 ?
        array_push($params, 
            $row['declaration_no'], $row['master_no'], $row['house_no'], $row['weight'], $row['total_packages'], 
            $row['packages_in'], $row['packages_out'], $row['clearance_method'], $row['declaration_type'], 
            $row['carrier_id'], $row['route'], $row['storage_in_datetime'], $row['storage_out_datetime'], 
            $row['release_datetime'], // 【*** 新增 ***】
            $row['status'], $row['customer_name']
        );
        // 對應的類型字串
        $types .= 'sssdiissssssssss'; // 增加一個 s
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


// --- 核心處理邏輯 ---
write_log("==== Original files cron job started. ====");

try {
    $driveService = getGoogleDriveClient();
    $queryParams = [
        'q' => "'" . SOURCE_FOLDER_ID . "' in parents and (mimeType='application/vnd.ms-excel' or mimeType='application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' or mimeType='text/csv') and trashed=false",
        'pageSize' => 10,
        'fields' => "files(id, name)"
    ];
    $results = $driveService->files->listFiles($queryParams);

    if (count($results->getFiles()) == 0) {
        write_log("在 'ogiginal_daily_in' 資料夾中未找到新檔案。");
    } else {
        foreach ($results->getFiles() as $file) {
            $file_id = $file->getId();
            $file_name = $file->getName();
            write_log("正在處理檔案: {$file_name} (ID: {$file_id})");

            $response = $driveService->files->get($file_id, ['alt' => 'media']);
            $file_content = $response->getBody()->getContents();
            $file_tmp_path = tempnam(sys_get_temp_dir(), 'original_drive_');
            file_put_contents($file_tmp_path, $file_content);

            $total_inserted_rows = 0;
            
            $conn = new mysqli($servername, $username, $password, $dbname);
            if ($conn->connect_error) throw new Exception("資料庫連線失敗: " . $conn->connect_error);
            $conn->set_charset("utf8mb4");

            try {
                // 1. 讀取標題以判斷檔案類型
                $spreadsheet_for_title = IOFactory::load($file_tmp_path);
                $sheet_for_title = $spreadsheet_for_title->getActiveSheet();
                // 標題通常在合併儲存格，我們檢查幾個可能的位置
                $title = $sheet_for_title->getCell('A3')->getValue() ?? $sheet_for_title->getCell('I3')->getValue() ?? '';
                $spreadsheet_for_title->disconnectWorksheets();
                unset($spreadsheet_for_title, $sheet_for_title);

                $file_type = null;
                if (str_contains($title, '進口貨物已進倉未出倉列表')) {
                    $file_type = 'INSTOCK_NOT_OUT';
                    write_log("偵測到檔案類型: 已進倉未出倉");
                } elseif (str_contains($title, '進口貨物已放行未出倉列表')) {
                    $file_type = 'RELEASED_NOT_OUT';
                    write_log("偵測到檔案類型: 已放行未出倉");
                } else {
                    throw new Exception("無法識別的檔案標題: '{$title}'");
                }
                
                // 2. 根據檔案類型進行分塊處理
                $conn->begin_transaction();
                
                $chunk_size = 500;
                $reader = IOFactory::createReaderForFile($file_tmp_path);
                $worksheetInfo = $reader->listWorksheetInfo($file_tmp_path);
                $highestRow = $worksheetInfo[0]['totalRows'];
                $data_start_row = 16; // 根據範本，資料從第16行開始
                $chunkFilter = new ChunkReadFilter();
                $reader->setReadFilter($chunkFilter);

                for ($startRow = $data_start_row; $startRow <= $highestRow; $startRow += $chunk_size) {
                    $chunkFilter->setRows($startRow, $chunk_size);
                    $spreadsheet = $reader->load($file_tmp_path);
                    $worksheet = $spreadsheet->getActiveSheet();
                    $data_chunk = [];

                    for ($r = $startRow; $r < $startRow + $chunk_size && $r <= $highestRow; $r++) {
                        // 如果第一欄為空，則跳過此行
                        if(empty($worksheet->getCell('A' . $r)->getValue())) continue;
                        
                        $rowData = [];
                        // 根據檔案類型設定欄位對應
                        if ($file_type === 'INSTOCK_NOT_OUT') {
                             $rowData = [
                                'declaration_no'   => $worksheet->getCell('K' . $r)->getValue(),
                                'master_no'        => $worksheet->getCell('B' . $r)->getValue(),
                                'house_no'         => $worksheet->getCell('G' . $r)->getValue(),
                                'declaration_type' => $worksheet->getCell('M' . $r)->getValue(),
                                'total_packages'   => $worksheet->getCell('O' . $r)->getValue(),
                                'packages_in'      => $worksheet->getCell('Q' . $r)->getValue(),
                                'packages_out'     => $worksheet->getCell('S' . $r)->getValue(),
                                'storage_in_datetime' => $worksheet->getCell('U' . $r)->getValue(),
                                'clearance_method' => $worksheet->getCell('AA' . $r)->getValue(),
                                'storage_out_datetime' => null, // 此類型無出倉時間
                                'release_datetime' => null, // 【*** 新增 ***】此類型無放行時間
                             ];
                        } elseif ($file_type === 'RELEASED_NOT_OUT') {
                             $rowData = [
                                'declaration_no'   => $worksheet->getCell('K' . $r)->getValue(),
                                'master_no'        => $worksheet->getCell('C' . $r)->getValue(),
                                'house_no'         => $worksheet->getCell('I' . $r)->getValue(),
                                'declaration_type' => $worksheet->getCell('M' . $r)->getValue(),
                                'total_packages'   => $worksheet->getCell('Q' . $r)->getValue(),
                                'packages_in'      => $worksheet->getCell('S' . $r)->getValue(),
                                'packages_out'     => $worksheet->getCell('U' . $r)->getValue(),
                                'storage_in_datetime' => $worksheet->getCell('X' . $r)->getValue(),
                                'clearance_method' => $worksheet->getCell('Z' . $r)->getValue(),
                                'storage_out_datetime' => $worksheet->getCell('Y' . $r)->getValue(), // 【*** 修正 ***】使用 Y 欄位的出倉時間
                                'release_datetime' => $worksheet->getCell('W' . $r)->getValue(), // 【*** 新增 ***】使用 W 欄位的放行時間
                             ];
                        }
                        
                        // 整理並轉換資料
                        $data_chunk[] = [
                            'declaration_no'   => (string) ($rowData['declaration_no'] ?? null),
                            'master_no'        => (string) ($rowData['master_no'] ?? null),
                            'house_no'         => (string) ($rowData['house_no'] ?? null),
                            'weight'           => null, // 新格式無此欄位
                            'total_packages'   => !empty($rowData['total_packages']) ? intval($rowData['total_packages']) : 0,
                            'packages_in'      => !empty($rowData['packages_in']) ? intval($rowData['packages_in']) : 0,
                            'packages_out'     => !empty($rowData['packages_out']) ? intval($rowData['packages_out']) : 0,
                            'clearance_method' => (string) ($rowData['clearance_method'] ?? null),
                            'declaration_type' => (string) ($rowData['declaration_type'] ?? null),
                            'carrier_id'       => null, // 新格式無此欄位
                            'route'            => null, // 新格式無此欄位
                            'storage_in_datetime' => parse_date_value($rowData['storage_in_datetime'] ?? null),
                            'storage_out_datetime' => parse_date_value($rowData['storage_out_datetime'] ?? null),
                            'release_datetime' => parse_date_value($rowData['release_datetime'] ?? null), // 【*** 新增 ***】
                            'status'           => null, // 新格式無此欄位
                            'customer_name'    => null, // 新格式無此欄位
                        ];
                    }

                    if (!empty($data_chunk)) {
                        $total_inserted_rows += batch_insert($conn, $data_chunk);
                    }
                    $spreadsheet->disconnectWorksheets();
                    unset($spreadsheet, $worksheet, $data_chunk);
                }

                $conn->commit();
                write_log("成功: 檔案 '{$file_name}' 處理完畢。共新增 {$total_inserted_rows} 筆資料。");
                
                write_log("正在將 '{$file_name}' 移動至 'daily_out' 資料夾...");
                moveFileOnDrive($driveService, $file_id, SOURCE_FOLDER_ID, DESTINATION_FOLDER_ID);
                write_log("檔案移動成功。");

            } catch (Exception $e) {
                if ($conn->in_transaction) {
                    $conn->rollback();
                }
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

