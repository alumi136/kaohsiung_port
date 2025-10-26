import pygame
import sys

# 1. 初始化
pygame.init()
# 初始化字體模組
pygame.font.init() 

# --- 遊戲設定 ---
WIDTH = 800
HEIGHT = 600
FPS = 60 

# 顏色
BLACK = (0, 0, 0)
WHITE = (255, 255, 255)
RED = (255, 0, 0)
GREEN = (0, 255, 0)
BLUE = (0, 0, 255)

# --- 建立視窗和時鐘 ---
screen = pygame.display.set_mode((WIDTH, HEIGHT))
pygame.display.set_caption("打磚塊 - 最終版！")
clock = pygame.time.Clock()

# --- 建立字體 ---
# (字型檔案, 大小) None 表示使用預設字型
SCORE_FONT = pygame.font.Font(None, 40)
GAME_OVER_FONT = pygame.font.Font(None, 74)
WIN_FONT = pygame.font.Font(None, 74)

# --- 遊戲物件 ---
# 球拍
PADDLE_WIDTH = 100
PADDLE_HEIGHT = 10
paddle = pygame.Rect((WIDTH - PADDLE_WIDTH) / 2, HEIGHT - PADDLE_HEIGHT - 20, PADDLE_WIDTH, PADDLE_HEIGHT)

# 球
BALL_RADIUS = 10
ball = pygame.Rect(WIDTH / 2 - BALL_RADIUS, HEIGHT / 2 - BALL_RADIUS, BALL_RADIUS * 2, BALL_RADIUS * 2)

# 球的速度
ball_speed_x = 7
ball_speed_y = 7

# 磚塊
BRICK_WIDTH = 75
BRICK_HEIGHT = 20
BRICK_PADDING = 5
ROWS = 5
COLS = 10
bricks = []
for row in range(ROWS):
    for col in range(COLS):
        brick_x = (BRICK_WIDTH + BRICK_PADDING) * col + (BRICK_PADDING * 2)
        brick_y = (BRICK_HEIGHT + BRICK_PADDING) * row + (BRICK_PADDING * 2)
        # 根據行數給不同顏色
        color = BLUE if row % 2 == 0 else GREEN
        brick = pygame.Rect(brick_x, brick_y, BRICK_WIDTH, BRICK_HEIGHT)
        # 我們把顏色和 Rect 一起存起來
        bricks.append((brick, color))

# --- 遊戲變數 ---
score = 0
lives = 3
# 遊戲狀態： "running", "game_over", "win"
game_state = "running"

# --- 遊戲迴圈 ---
running = True
while running:
    # --- 事件處理 ---
    for event in pygame.event.get():
        if event.type == pygame.QUIT:
            running = False
        # 如果遊戲結束或勝利，按任意鍵重新開始
        if game_state != "running" and event.type == pygame.KEYDOWN:
            # 重置所有變數
            score = 0
            lives = 3
            bricks = []
            for row in range(ROWS):
                for col in range(COLS):
                    brick_x = (BRICK_WIDTH + BRICK_PADDING) * col + (BRICK_PADDING * 2)
                    brick_y = (BRICK_HEIGHT + BRICK_PADDING) * row + (BRICK_PADDING * 2)
                    color = BLUE if row % 2 == 0 else GREEN
                    brick = pygame.Rect(brick_x, brick_y, BRICK_WIDTH, BRICK_HEIGHT)
                    bricks.append((brick, color))
            ball.center = (WIDTH / 2, HEIGHT / 2)
            game_state = "running"


    # --- 遊戲邏輯 (只在 "running" 狀態下執行) ---
    if game_state == "running":
        # 1. 移動球拍
        paddle.centerx = pygame.mouse.get_pos()[0]
        if paddle.left < 0: paddle.left = 0
        if paddle.right > WIDTH: paddle.right = WIDTH

        # 2. 移動球
        ball.x += ball_speed_x
        ball.y += ball_speed_y

        # 3. 碰撞偵測 - 牆壁
        if ball.left <= 0 or ball.right >= WIDTH:
            ball_speed_x *= -1
        if ball.top <= 0:
            ball_speed_y *= -1
        
        # 4. 碰撞偵測 - 球拍
        if ball.colliderect(paddle):
            ball_speed_y *= -1
            ball.bottom = paddle.top

        # 5. 碰撞偵測 - 磚塊 (本步驟重點)
        # 我們需要一個變數來安全地移除磚塊
        brick_to_remove = None
        for brick_data in bricks:
            brick_rect = brick_data[0] # 取得磚塊的 Rect
            if ball.colliderect(brick_rect):
                ball_speed_y *= -1 # 垂直反彈
                brick_to_remove = brick_data # 記錄這個磚塊
                score += 10 # 加分
                break # 跳出迴圈，一次只撞一個
        
        # 安全地移除磚塊 (在迴圈外移除)
        if brick_to_remove is not None:
            bricks.remove(brick_to_remove)

        # 6. 遊戲狀態檢查 - 勝利
        # if not bricks: (如果 bricks 列表是空的)
        if len(bricks) == 0:
            game_state = "win"

        # 7. 遊戲狀態檢查 - 掉落 (失敗)
        if ball.bottom >= HEIGHT:
            lives -= 1 # 扣一條命
            if lives > 0:
                # 重置球
                ball.center = (WIDTH / 2, HEIGHT / 2)
                ball_speed_x = 7
                ball_speed_y = 7
            else:
                # 遊戲結束
                game_state = "game_over"

    # --- 畫面繪製 ---
    screen.fill(BLACK) # 背景改為黑色
    
    if game_state == "running":
        # 畫出球拍
        pygame.draw.rect(screen, WHITE, paddle)
        # 畫出球
        pygame.draw.circle(screen, RED, ball.center, BALL_RADIUS)
        # 畫出所有磚塊
        for brick_data in bricks:
            brick_rect = brick_data[0]
            brick_color = brick_data[1]
            pygame.draw.rect(screen, brick_color, brick_rect)
        
        # 畫出分數和生命值
        score_text = SCORE_FONT.render(f"Score: {score}", True, WHITE)
        screen.blit(score_text, (10, 10))
        lives_text = SCORE_FONT.render(f"Lives: {lives}", True, WHITE)
        screen.blit(lives_text, (WIDTH - lives_text.get_width() - 10, 10))

    elif game_state == "game_over":
        # 畫出遊戲結束畫面
        over_text = GAME_OVER_FONT.render("GAME OVER", True, RED)
        screen.blit(over_text, (WIDTH/2 - over_text.get_width()/2, HEIGHT/2 - over_text.get_height()/2))
        restart_text = SCORE_FONT.render("Press any key to restart", True, WHITE)
        screen.blit(restart_text, (WIDTH/2 - restart_text.get_width()/2, HEIGHT/2 + 50))

    elif game_state == "win":
        # 畫出勝利畫面
        win_text = WIN_FONT.render("YOU WIN!", True, GREEN)
        screen.blit(win_text, (WIDTH/2 - win_text.get_width()/2, HEIGHT/2 - win_text.get_height()/2))
        restart_text = SCORE_FONT.render("Press any key to restart", True, WHITE)
        screen.blit(restart_text, (WIDTH/2 - restart_text.get_width()/2, HEIGHT/2 + 50))

    # 更新畫面
    pygame.display.flip()
    # 控制幀率
    clock.tick(FPS)

# 遊戲結束
pygame.quit()
sys.exit()