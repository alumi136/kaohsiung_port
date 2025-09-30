# 檔名: db_test.py
# 功能: 專門用來測試資料庫連線，並產生詳細日誌檔案。

import mysql.connector
import logging # 我們這次請來的「日誌記錄官」
import datetime # 用來取得目前時間

# --- 日誌設定 ---
# 設定日誌記錄官，讓它同時在「螢幕」和「檔案」上記錄
log_filename = 'db_test_log.txt'
logging.basicConfig(
    level=logging.INFO, # 設定記錄的等級
    format='%(asctime)s - %(levelname)s - %(message)s', # 設定記錄的格式
    handlers=[
        logging.FileHandler(log_filename, mode='w', encoding='utf-8'), # 寫入檔案
        logging.StreamHandler() # 顯示在螢幕
    ]
)

# --- 資料庫連線設定 (請再次確認這裡的資料是 100% 正確的) ---
DB_CONFIG = {
    'host': "mouse.galloptek.com",
    'port':"3306",
    'user': "alumi136",
    'password': "Alumi!36",
    'database': "kaohsiung_port_db",
    'charset': 'utf8mb4'
}

def run_test():
    """執行資料庫連線測試的主要流程"""
    logging.info("--- 開始執行資料庫連線測試 ---")
    
    # 為了安全，記錄時我們把密碼換成 ***
    safe_config_to_log = DB_CONFIG.copy()
    safe_config_to_log['password'] = '***'
    logging.info(f"使用的連線設定: {safe_config_to_log}")

    connection = None  # 先準備一個空的變數
    try:
        # --- 這是測試的核心 ---
        logging.info("步驟 1: 正在嘗試連接到 MySQL 伺服器...")
        connection = mysql.connector.connect(**DB_CONFIG)
        logging.info("步驟 1: ✅ 連線成功！")

        # --- 如果連線成功，我們再做一個小小的查詢測試 ---
        logging.info("步驟 2: 正在嘗試執行一筆簡單的測試查詢...")
        cursor = connection.cursor()
        cursor.execute("SELECT DATABASE();") # 這個指令會回傳目前連上的資料庫名稱
        result = cursor.fetchone()
        logging.info(f"步驟 2: ✅ 測試查詢成功！目前連接的資料庫是: {result[0]}")
        cursor.close()

    except mysql.connector.Error as err:
        # 如果上面 try 的過程中有任何 mysql 相關的錯誤，都會被抓到這裡
        logging.error(f"❌ 測試失敗！發生錯誤: {err}")

    except Exception as e:
        # 捕捉其他意料之外的錯誤
        logging.error(f"❌ 測試失敗！發生了非預期的錯誤: {e}")

    finally:
        # 不論成功或失敗，最後都一定會執行這裡
        logging.info("步驟 3: 正在關閉資料庫連線...")
        if connection and connection.is_connected():
            connection.close()
            logging.info("步驟 3: ✅ 連線已成功關閉。")
        else:
            logging.info("步驟 3: ℹ️ 沒有可關閉的連線。")
        
        logging.info("--- 資料庫連線測試結束 ---")

# --- 程式從這裡開始執行 ---
if __name__ == "__main__":
    run_test()