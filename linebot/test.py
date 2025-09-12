from linebot.exceptions import LineBotApiError
try:
    line_bot_api.reply_message(
        event.reply_token,
        TextSendMessage(text=reply_text)
    )
except LineBotApiError as e:
    print(f"Error status_code: {e.status_code}")
    print(f"Error details: {e.error.message}")
    print(os.getenv("LINE_CHANNEL_ACCESS_TOKEN")) 
