# -*- coding: utf-8 -*-
import pandas as pd
import pdfplumber  # 用於讀取 PDF 文字
import re          # 用於搜尋特定文字 (正規表示法)
import os
from pathlib import Path

# --- 參數設定區 (使用者請在此修改) ---

# 1. 您存放所有 PDF 檔案的資料夾路徑
#    (請確保路徑寫法正確，建議使用 r'...' 或 '/')
PDF_SOURCE_FOLDER = r'e:\56'

# 2. 最終產出的 Excel 檔案路徑與名稱
OUTPUT_EXCEL_PATH = r'e:\56r\稅單總表.xlsx'

# --- 參數設定區結束 ---


def clean_value(text_value):
    """
    輔助函式：清理抓取到的數字，移除空白和千分位逗號
    """
    if text_value:
        return text_value.strip().replace(',', '')
    return None


def extract_data_from_pdf(pdf_path):
    """
    核心處理函式：開啟單一 PDF 檔案並抓取所需資料
    """
    full_text = ""
    
    try:
        # 使用 pdfplumber 開啟 PDF
        with pdfplumber.open(pdf_path) as pdf:
            # 根據您的範例，三聯的資料都一樣，
            # 我們只需要讀取第一頁 (pdf.pages[0]) 即可
            if pdf.pages:
                full_text = pdf.pages[0].extract_text()
            
            if not full_text:
                # PDF 中沒有可讀取的文字 (可能是空白或純圖片)
                return None, None, None, "Error: PDF 內無文字"
                
    except Exception as e:
        # PDF 檔案已損壞或無法開啟
        return None, None, None, f"Error: 無法讀取 PDF ({e})"
    
    # --- 開始使用正規表示法 (Regex) 抓取資料 ---
    
    tax_bill_num = None
    total_tax = None
    vat_base = None
    
    # 1. 抓取稅單號碼 (邏輯：'稅單號碼:' 後面跟著的 'BY' 開頭字串)
    #    r'稅單號碼:\s*(BY[A-Za-z0-9]+)'
    #    \s* = 允許中間有任意空白
    #    (BY[A-Za-z0-9]+) = 我們要抓取的內容 (BY 開頭的英數字組合)
    match_bill = re.search(r'稅單號碼:\s*(BY[A-Za-z0-9]+)', full_text)
    if match_bill:
        tax_bill_num = match_bill.group(1) # .group(1) 指的是抓取 () 內的內容
        
    # 2. 抓取稅費合計 (邏輯：'稅費合計' 後面跟著的第一個數字)
    #    r'稅費合計\s*([\d,]+)'
    #    \s* = 允許中間有任意空白或換行
    #    ([\d,]+) = 我們要抓取的內容 (由 數字 和 逗號 組成)
    match_tax = re.search(r'稅費合計\s*([\d,]+)', full_text)
    if match_tax:
        total_tax = clean_value(match_tax.group(1))

    # 3. 抓取營業稅稅基 (邏輯：'營業稅稅基' 後面跟著的第一個數字)
    #    r'營業稅稅基\s*([\d,]+)'
    match_vat = re.search(r'營業稅稅基\s*([\d,]+)', full_text)
    if match_vat:
        vat_base = clean_value(match_vat.group(1))

    # 檢查是否有缺漏
    status = "Success"
    if not all([tax_bill_num, total_tax, vat_base]):
        missing = []
        if not tax_bill_num: missing.append("稅單號碼")
        if not total_tax: missing.append("稅費合計")
        if not vat_base: missing.append("營業稅稅基")
        status = f"Warning: 缺少資料 ({', '.join(missing)})"
        
    return tax_bill_num, total_tax, vat_base, status


def main():
    """
    主執行函式
    """
    print("--- PDF 資料擷取系統啟動 ---")
    
    # --- 階段一: 路徑檢查與建立 ---
    src_folder = Path(PDF_SOURCE_FOLDER)
    output_file = Path(OUTPUT_EXCEL_PATH)

    if not src_folder.exists() or not src_folder.is_dir():
        print(f"錯誤: 來源資料夾 '{PDF_SOURCE_FOLDER}' 不存在。")
        print("請檢查參數 'PDF_SOURCE_FOLDER' 的路徑是否正確。")
        return

    # 建立輸出資料夾 (例如 ./output/)
    output_file.parent.mkdir(parents=True, exist_ok=True)
    
    # --- 階段二: 掃描與處理 PDF ---
    # 找出資料夾中所有的 .pdf 檔案
    pdf_files = list(src_folder.glob('*.pdf')) + list(src_folder.glob('*.PDF'))
    
    if not pdf_files:
        print(f"錯誤: 在 '{src_folder}' 中找不到任何 .pdf 檔案。")
        return
        
    print(f"找到 {len(pdf_files)} 個 PDF 檔案。開始處理...")
    
    # 用來存放所有抓取結果的列表
    all_data = []
    
    for pdf_path in pdf_files:
        print(f"正在處理: {pdf_path.name}")
        
        # 呼叫核心函式來抓資料
        bill, tax, vat, status = extract_data_from_pdf(pdf_path)
        
        # 將結果存入
        all_data.append({
            'PDF原始檔名': pdf_path.name,
            '稅單號碼': bill,
            '稅費合計': tax,
            '營業稅稅基': vat,
            '處理狀態': status  # 額外增加一個狀態欄，方便您除錯
        })

    print("...所有檔案處理完成。")

    # --- 階段三: 匯出 Excel 報表 ---
    if not all_data:
        print("沒有處理任何資料，程式結束。")
        return
        
    try:
        print(f"正在將結果匯出至 '{output_file}'...")
        df = pd.DataFrame(all_data)
        
        # 確保欄位順序
        columns_order = ['PDF原始檔名', '稅單號碼', '稅費合計', '營業稅稅基', '處理狀態']
        df = df[columns_order]
        
        df.to_excel(output_file, index=False, engine='openpyxl')
        
        print("--- 報表產出成功！ ---")
        print(f"檔案位置: {output_file.resolve()}")
        
    except Exception as e:
        print(f"錯誤: 寫入 Excel 檔案時失敗: {e}")

if __name__ == '__main__':
    main()