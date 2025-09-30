# 檔名: app.py (我們的魔法食譜)

## ---------------------------------------------------
## 步驟 1: 從魔法櫃拿出我們需要的工具
## ---------------------------------------------------
## 就像做蛋糕需要麵粉、雞蛋、糖一樣，寫程式也需要各種工具。
## import 就是「拿進來」的意思。

import io                     # 拿來處理文字的工具，可以想像成一個暫時的筆記本
import csv                    # 處理 CSV 檔案的專家，牠知道怎麼把資料排得整整齊齊
import mysql.connector        # 這是我們的「資料庫鑰匙」，用來打開存放資料的大櫃子

# 從 flask 這個大工具箱裡面，拿出幾個重要的小工具
from flask import Flask, request, render_template, make_response


## ---------------------------------------------------
## 步驟 2: 蓋一間我們自己的「神奇查詢小站」
## ---------------------------------------------------
## 我們要先有一個店面，才能開始做生意。

# 蓋一間店，並取名叫 app
# __name__ 是一個神奇的咒語，它會告訴 Flask 這間店在哪裡
app = Flask(__name__)


## ---------------------------------------------------
## 步驟 3: 記錄存放資料大櫃子(資料庫)的地址和密碼
## ---------------------------------------------------
## 我們要告訴程式，我們的資料都放在哪個櫃子裡。

# 這是一個字典，就像一張寫滿資料的清單
# 裡面記錄了櫃子的位置(host)、使用者名稱(user)、密碼(password)和名稱(database)
DB_CONFIG = {
    'host': "localhost",
    'user': "alumi136",
    'password': "Alumi!36",
    'database': "kaohsiung_port_db",
    'charset': 'utf8mb4' # 讓櫃子可以認識中文
}


## ---------------------------------------------------
## 步驟 4: 設計一個標準動作，用來「打開資料櫃拿東西」
## ---------------------------------------------------
## 每次都要開櫃子很麻煩，我們把這個動作變成一個魔法咒語(函式)。

def get_db_connection():
    """
    這是一個叫做 get_db_connection 的魔法咒語。
    它的功能是：幫我們連接到資料庫大櫃子。
    """
    print("正在嘗試打開資料庫大櫃子...") # 老師加的，讓你知道程式跑到哪了

    # 我們「試試看」(try) 能不能成功連線
    try:
        # 使用我們剛剛記錄的地址和密碼，建立一條通道
        connection = mysql.connector.connect(**DB_CONFIG)
        print("櫃子打開了！")
        # 如果成功，就把這條通道交出去
        return connection
    
    # 如果「發生意外」(except)，例如密碼錯了
    except mysql.connector.Error as err:
        # 就在螢幕上印出錯誤訊息
        print(f"糟糕！打不開櫃子，原因：{err}")
        # 因為沒成功，所以就回傳「空空的」(None)
        return None


## ---------------------------------------------------
## 步驟 5: 設定小站的「服務櫃台」
## ---------------------------------------------------
## 我們的查詢小站需要一個櫃台，來接收客人的要求。

# @app.route('/') 就像在店門口掛上「 여기가 바로 그 집이다 」的牌子，告訴大家這裡就是入口
# methods=['GET', 'POST'] 表示櫃台可以處理兩種要求：
# 1. GET: 客人只是想看看菜單 (瀏覽網頁)
# 2. POST: 客人填好了點菜單，要交給我們處理 (按下查詢按鈕)
@app.route('/', methods=['GET', 'POST'])
def search():
    """
    這是我們的總機小姐，負責在櫃台接待客人。
    """
    print(f"客人來了！他的要求是：{request.method}")

    # 檢查客人是不是按下了「查詢」或「下載」按鈕 (POST)
    if request.method == 'POST':
        
        # 看看客人按的是「單筆查詢」的按鈕嗎？
        if 'single_query' in request.form:
            print("客人點了『單筆查詢』套餐！")
            # 如果是，就請「單筆查詢廚師」來服務
            return handle_single_query()

        # 看看客人按的是「查詢並下載 CSV」的按鈕嗎？
        elif 'multi_query' in request.form:
            print("客人點了『多筆外帶』套餐！")
            # 如果是，就請「多筆查詢廚師」來服務
            return handle_multi_query()

    # 如果客人只是來逛逛，沒有按任何按鈕 (GET)
    print("客人只是來看看菜單，給他看空白的查詢網頁。")
    # 就給他看我們設計好的網頁(search.html)
    return render_template('search.html')


## ---------------------------------------------------
## 步驟 6: 聘請一位「單筆查詢廚師」
## ---------------------------------------------------
## 這位廚師只負責做「單筆查詢」這道菜。

def handle_single_query():
    """
    這位是單筆查詢的專家廚師！
    """
    # --- 廚師的準備工作 ---
    # 先準備好空的盤子和紙條
    single_result = None  # 用來放查詢結果的盤子，現在是空的
    error_message = ""    # 用來寫錯誤訊息的紙條，現在是空白的
    
    # 從客人給的點菜單(request.form)中，拿出他想查詢的「分提單號」
    # .get() 比較安全，就算客人沒寫，程式也不會壞掉
    # .strip() 幫忙把客人不小心多打的空格去掉
    house_no_single = request.form.get('house_no_single', '').strip()
    print(f"廚師收到的單點菜名是：{house_no_single}")

    # --- 開始做菜 ---
    # 如果客人根本沒寫要查什麼
    if not house_no_single:
        print("客人忘記寫菜名了！")
        error_message = "請輸入要查詢的分提單號。"
    
    # 如果客人有寫
    else:
        # 第一步：呼叫咒語，打開資料櫃
        conn = get_db_connection()
        
        # 檢查一下櫃子有沒有成功打開
        if conn is None:
            error_message = "糟糕，廚房的資料櫃打不開，請稍後再來！"
        else:
            # 第二步：準備好「食譜」(SQL 查詢指令)
            # %s 是一個佔位符，像食譜裡的「適量」，等一下會把真正的材料放進去
            # 這樣做比較安全，可以防止壞人亂寫食譜
            sql_recipe = """
                SELECT master_no, house_no, declaration_no, storage_in_datetime, 
                       storage_out_datetime, status 
                FROM daily_outbound WHERE house_no = %s
            """
            
            # 第三步：從櫃子裡拿出一個「執行者」(cursor)，它會幫我們跑腿
            cursor = conn.cursor(dictionary=True) # dictionary=True 讓它拿回來的東西會像字典一樣方便
            
            # 第四步：叫執行者照著食譜去找材料
            # 把客人要的 house_no_single 放進 %s 的位置
            cursor.execute(sql_recipe, (house_no_single,))
            
            # 第五步：從執行者手上拿回找到的「唯一一筆」資料
            single_result = cursor.fetchone()
            
            # 第六步：檢查一下有沒有找到東西
            if single_result is None:
                print(f"在櫃子裡找不到 {house_no_single} 這道菜。")
                error_message = f"找不到符合分提單號 '{house_no_single}' 的資料。若您的EZWay已經按[申報相符],請等待1~2天時間通關,謝謝!"

            # 第七步：不管有沒有找到，都要把櫃子的門關好，通道也收起來
            cursor.close()
            conn.close()
            print("單筆查詢結束，櫃子關好了。")

    # --- 上菜 ---
    # 把做好的菜(single_result)、可能有的錯誤訊息(error_message)、
    # 和客人原本點的菜名(house_no_single)一起端上桌
    # render_template 會幫我們把這些東西漂亮地擺在 search.html 這個盤子上
    return render_template('search.html', 
                           single_result=single_result, 
                           error_message=error_message,
                           house_no_single=house_no_single)


## ---------------------------------------------------
## 步驟 7: 聘請一位「多筆外帶廚師」
## ---------------------------------------------------
## 這位廚師專門處理大訂單，並打包成 CSV 檔案讓客人帶走。

def handle_multi_query():
    """
    這位是處理大訂單並打包的專家廚師！
    """
    # --- 廚師的準備工作 ---
    # 拿出客人寫得滿滿的點菜單
    house_nos_raw = request.form.get('house_nos_multi', '').strip()
    print("廚師收到一張長長的外帶點菜單！")

    # --- 清點訂單 ---
    # 1. 準備一個空籃子，等一下要放乾淨的菜名
    house_nos_list = []
    # 2. 把客人輸入的一大串文字，一行一行分開
    for line in house_nos_raw.splitlines():
        # 3. 去掉每一行前面和後面的空格
        clean_line = line.strip()
        # 4. 如果這一行不是空的
        if clean_line:
            # 5. 就把它放進我們的籃子裡
            house_nos_list.append(clean_line)
            
    print(f"整理好的菜單共有 {len(house_nos_list)} 道菜。")
            
    # 如果訂單超過 50 筆，太忙了做不來，就跟客人說抱歉
    if len(house_nos_list) > 50:
        return "對不起，一次最多只能點 50 筆喔！", 400

    # 如果籃子是空的，表示客人根本沒點菜
    if not house_nos_list:
        return render_template('search.html', error_message="您好像忘記在多筆查詢中輸入內容囉！")

    # --- 開始備料 ---
    # 1. 準備一個大的備料台 (字典)，用來放從資料櫃拿出來的菜
    db_results_map = {}
    
    # 2. 打開資料櫃
    conn = get_db_connection()
    
    # 3. 確定櫃子是開的
    if conn:
        # 4. 準備一張超級食譜
        # 例如客人點了3道菜，就會變成 "IN (%s, %s, %s)"
        placeholders = ', '.join(['%s'] * len(house_nos_list))
        sql_recipe = f"SELECT master_no, house_no, storage_in_datetime, storage_out_datetime, status FROM daily_outbound WHERE house_no IN ({placeholders})"
        
        # 5. 請執行者一次把所有菜都從櫃子裡拿出來
        cursor = conn.cursor(dictionary=True)
        cursor.execute(sql_recipe, tuple(house_nos_list))
        
        # 6. 執行者會拿回一大堆菜，我們把它們全部收下
        all_results = cursor.fetchall()
        
        # 7. 把拿到的每一道菜，都放到我們的備料台上，並用菜名做好標記
        for row in all_results:
            db_results_map[row['house_no']] = row
            
        # 8. 關上櫃子
        cursor.close()
        conn.close()
        print(f"多筆查詢結束，從櫃子裡找到了 {len(db_results_map)} 筆資料。")

    # --- 開始打包 (製作 CSV) ---
    # 1. 在空中變出一個暫時的筆記本 (in-memory text file)
    output = io.StringIO()
    # 2. 告訴筆記本，我們是認真的好學生，用 UTF-8 BOM，這樣 Excel 才看得懂中文
    output.write('\ufeff')
    # 3. 請 CSV 打包專家來幫忙
    writer = csv.writer(output)
    
    # 4. 在打包盒的最上面，先寫好標籤 (欄位名稱)
    writer.writerow(['主號', '分號', '進倉日期時間', '出倉日期時間', '狀態'])
    
    # 5. 按照客人原本的點菜單，一道一道菜來打包
    for house_no in house_nos_list:
        # 如果這道菜在我們的備料台上有
        if house_no in db_results_map:
            # 就把備好的料拿出來
            row_data = db_results_map[house_no]
            # 寫進打包盒裡
            writer.writerow([
                row_data.get('master_no'), row_data.get('house_no'),
                row_data.get('storage_in_datetime'), row_data.get('storage_out_datetime'),
                row_data.get('status')
            ])
        # 如果備料台上沒有這道菜
        else:
            # 就寫一張紙條說「未進倉」，然後一起打包
            writer.writerow(['', house_no, '', '', '未進倉'])

    # --- 交給客人 ---
    # 1. 把寫滿東西的筆記本內容全部拿出來
    csv_content = output.getvalue()
    
    # 2. 準備一個外送專用的盒子 (Response)
    response = make_response(csv_content)
    
    # 3. 在盒子上貼上標籤，告訴瀏覽器：
    #    - 這是一個要讓客人下載的附件(attachment)，檔名叫「通關狀態查詢結果.csv」
    response.headers["Content-Disposition"] = "attachment; filename=通關狀態查詢結果.csv"
    #    - 裡面的東西是 CSV 檔案，請用 UTF-8 的方式閱讀
    response.headers["Content-Type"] = "text/csv; charset=utf-8"
    
    print("外帶餐點打包完成，準備交給客人！")
    # 4. 把打包好的盒子交出去！
    return response


## ---------------------------------------------------
## 步驟 8: 按下「開始營業」的按鈕
## ---------------------------------------------------

# 這是一個很特別的咒語，意思是：
# 「如果我們是直接執行這個 app.py 檔案，而不是被其他檔案叫來用」
# 那就表示我們要開店營業了！
if __name__ == '__main__':
    # app.run() 就是「開始營業」的指令
    # debug=True 叫做「練習模式」，當我們修改程式碼存檔後，小站會自動更新，不用重開
    # 在真正開店給很多人用的時候，會把 debug 關掉 (False)
    print("神奇查詢小站，準備開始營業囉！請在瀏覽器打開指定的網址。")
    app.run(debug=True, port=5001)