from flask import Flask, request, abort, jsonify
from linebot import LineBotApi, WebhookHandler
from linebot.exceptions import InvalidSignatureError
from linebot.models import MessageEvent, TextMessage, TextSendMessage
import mysql.connector
import os
import threading
import sys
import traceback

app = Flask(__name__)

# 環境變數
LINE_CHANNEL_ACCESS_TOKEN = os.getenv("LINE_CHANNEL_ACCESS_TOKEN")
LINE_CHANNEL_SECRET = os.getenv("LINE_CHANNEL_SECRET")

if not LINE_CHANNEL_ACCESS_TOKEN or not LINE_CHANNEL_SECRET:
    print("[ERROR] LINE_CHANNEL_ACCESS_TOKEN 或 LINE_CHANNEL_SECRET 未設定！", file=sys.stderr)

line_bot_api = LineBotApi(LINE_CHANNEL_ACCESS_TOKEN)
handler = WebhookHandler(LINE_CHANNEL_SECRET)
print("[DEBUG] LINE_CHANNEL_ACCESS_TOKEN:", LINE_CHANNEL_ACCESS_TOKEN)
print("[DEBUG] LINE_CHANNEL_SECRET:", LINE_CHANNEL_SECRET)

# MySQL 設定
db_config = {
    "host": "localhost",
    "user": "alumi136",
    "password": "Alumi!36",
    "database": "kaohsiung_port_db",
    "charset": "utf8mb4",
    "connection_timeout": 5,
    "read_timeout": 5,
    "write_timeout": 5
}

@app.route("/health", methods=["GET"])
def health():
    return jsonify({"status": "ok"}), 200

@app.route("/callback", methods=["POST"])
def callback():
    # Debug log: 印出 Header 與 Body
    print("\n=== [DEBUG] /callback 被呼叫 ===")
    print("Headers:", dict(request.headers))
    body = request.get_data(as_text=True)
    print("Body:", body)

    signature = request.headers.get("X-Line-Signature")
    if not signature:
        print("[ERROR] 缺少 X-Line-Signature，可能不是 LINE 發送的請求")
        abort(400)

    try:
        handler.handle(body, signature)
    except InvalidSignatureError:
        print("[ERROR] InvalidSignatureError - 簽名驗證失敗")
        abort(400)
    except Exception as e:
        print("[ERROR] handler.handle 發生例外:", e)
        traceback.print_exc()

    return jsonify({"status": "received"})

@handler.add(MessageEvent, message=TextMessage)
def handle_message(event):
    threading.Thread(target=process_message, args=(event,)).start()

def process_message(event):
    try:
        user_message = event.message.text.strip()
        print(f"[DEBUG] 收到使用者訊息: {user_message}")

        # 特殊關鍵字回覆
        if user_message.lower() == "hello":
            reply_text = "歡迎加入億欣報關行Line官方帳號，有需要什麼服務，請您直接鍵入相關字，謝謝"
        elif "稅金" in user_message:
            reply_text = "關於稅金金額可以直接從您的EzWay APP上查詢到相關金額或是關港貿單一窗口進行查詢，基於個資保護法我們無法在線上提供稅金金額供查詢，請您諒解，謝謝您"
        else:
            # 查詢 MySQL
            conn = mysql.connector.connect(**db_config)
            cursor = conn.cursor(dictionary=True)

            query = """
            SELECT master_no, house_no, storage_in_datetime, storage_out_datetime, status
            FROM daily_outbound
            WHERE house_no = %s
            """
            cursor.execute(query, (user_message,))
            rows = cursor.fetchall()
            cursor.close()
            conn.close()

            if not rows:
                reply_text = f"查無分提單號：{user_message}"
            else:
                reply_lines = []
                for row in rows:
                    reply_lines.append(
                        f"主號: {row['master_no']}\n"
                        f"分號: {row['house_no']}\n"
                        f"進倉: {row['storage_in_datetime']}\n"
                        f"出倉: {row['storage_out_datetime']}\n"
                        f"狀態: {row['status']}"
                    )
                reply_text = "\n\n".join(reply_lines)

        print(f"[DEBUG] 回覆訊息: {reply_text}")
        line_bot_api.reply_message(event.reply_token, TextSendMessage(text=reply_text))

    except Exception as e:
        print("[ERROR] process_message 發生例外:", e)
        traceback.print_exc()
        try:
            line_bot_api.reply_message(
                event.reply_token,
                TextSendMessage(text="系統發生錯誤，請稍後再試。")
            )
        except Exception as e2:
            print("[ERROR] 回覆錯誤訊息時也失敗:", e2)
            traceback.print_exc()
if __name__ == "__main__":
    app.run(host="0.0.0.0", port=5000)

