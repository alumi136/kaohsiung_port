# 1. 載入需要的工具箱
import pygame
import random

# 2. 初始化 Pygame
pygame.init()

# --- 遊戲設定 ---
# 顏色
BLACK = (0, 0, 0)
WHITE = (255, 255, 255)
GREEN = (0, 255, 0)
RED = (255, 0, 0)
BLUE = (0, 0, 255) # (--- 新增 ---) 牆壁的顏色

# 遊戲視窗大小
WIDTH = 600
HEIGHT = 400

# 蛇和食物的大小
BLOCK_SIZE = 20

# 遊戲速度
SPEED = 10

# --- 建立遊戲視窗 ---
screen = pygame.display.set_mode((WIDTH, HEIGHT))
pygame.display.set_caption("碰到牆壁就GG的貪食蛇")

# 建立一個時鐘來控制速度
clock = pygame.time.Clock()

# --- 遊戲主程式 ---

# (--- 新增 ---) 建立一個函式來畫牆壁
def draw_wall(wall_blocks):
    for block in wall_blocks:
        pygame.draw.rect(screen, BLUE, [block[0], block[1], BLOCK_SIZE, BLOCK_SIZE])

# 定義一個函式來畫蛇
def draw_snake(snake_list):
    for block in snake_list:
        pygame.draw.rect(screen, GREEN, [block[0], block[1], BLOCK_SIZE, BLOCK_SIZE])

# (--- 新增 ---) 建立一個函式來產生安全的食物位置
def generate_food(wall_blocks, snake_list):
    while True:
        # 產生隨機位置
        food_x = round(random.randrange(0, WIDTH - BLOCK_SIZE) / BLOCK_SIZE) * BLOCK_SIZE
        food_y = round(random.randrange(0, HEIGHT - BLOCK_SIZE) / BLOCK_SIZE) * BLOCK_SIZE
        
        food_pos = [food_x, food_y]
        
        # 檢查食物是不是長在牆上或蛇身上
        if food_pos not in wall_blocks and food_pos not in snake_list:
            return food_x, food_y # 如果是安全的位置，才回傳

def game_loop():
    # 遊戲是否結束
    game_over = False
    # 遊戲是否關閉
    game_close = False

    # (--- 新增 ---) 建立牆壁
    wall_blocks = []
    # 牆壁的 X 座標在正中間
    wall_x = round((WIDTH / 2) / BLOCK_SIZE) * BLOCK_SIZE
    # 牆壁有幾個方塊 (高度的 1/3)
    num_wall_blocks = (HEIGHT // 3) // BLOCK_SIZE 
    # 牆壁開始的 Y 座標 (讓它垂直置中)
    start_y_block = ((HEIGHT // BLOCK_SIZE) // 2) - (num_wall_blocks // 2)
    
    for i in range(num_wall_blocks):
        block_y = (start_y_block + i) * BLOCK_SIZE
        wall_blocks.append([wall_x, block_y])
    # print(f"牆壁蓋好了，位置在: {wall_blocks}") # 你可以打開這行來看看牆壁在哪

    # (--- 修改 ---) 蛇的初始位置 (移到左邊 1/4 處，避開牆壁)
    x1 = round((WIDTH / 4) / BLOCK_SIZE) * BLOCK_SIZE
    y1 = round((HEIGHT / 2) / BLOCK_SIZE) * BLOCK_SIZE

    # 蛇的位置變化量
    x1_change = 0
    y1_change = 0

    # 蛇的身體 (用一個列表來儲存)
    snake_list = []
    snake_length = 1

    # (--- 修改 ---) 呼叫新的函式來產生食物
    food_x, food_y = generate_food(wall_blocks, snake_list)

    # --- 遊戲迴圈 ---
    while not game_close:

        # 當遊戲結束時，顯示訊息
        while game_over:
            screen.fill(BLACK)
            font_style = pygame.font.SysFont(None, 50)
            message = font_style.render("你輸了! 按 Q 離開或 C 再玩一次", True, RED)
            screen.blit(message, [WIDTH / 6, HEIGHT / 3])
            pygame.display.flip()

            for event in pygame.event.get():
                if event.type == pygame.KEYDOWN:
                    if event.key == pygame.K_q:
                        game_close = True
                        game_over = False
                    if event.key == pygame.K_c:
                        game_loop()

        # --- 事件處理 ---
        for event in pygame.event.get():
            if event.type == pygame.QUIT:
                game_close = True
            if event.type == pygame.KEYDOWN:
                if event.key == pygame.K_LEFT:
                    x1_change = -BLOCK_SIZE
                    y1_change = 0
                elif event.key == pygame.K_RIGHT:
                    x1_change = BLOCK_SIZE
                    y1_change = 0
                elif event.key == pygame.K_UP:
                    y1_change = -BLOCK_SIZE
                    x1_change = 0
                elif event.key == pygame.K_DOWN:
                    y1_change = BLOCK_SIZE
                    x1_change = 0
        
        # --- 規則判斷 ---
        # 判斷是否撞到 "視窗邊界"
        if x1 >= WIDTH or x1 < 0 or y1 >= HEIGHT or y1 < 0:
            game_over = True

        # 更新蛇的位置
        x1 += x1_change
        y1 += y1_change

        # --- 畫面繪製 ---
        screen.fill(BLACK)
        # 畫出食物
        pygame.draw.rect(screen, WHITE, [food_x, food_y, BLOCK_SIZE, BLOCK_SIZE])
        
        # (--- 新增 ---) 畫出牆壁
        draw_wall(wall_blocks)

        # 處理蛇的身體
        snake_head = []
        snake_head.append(x1)
        snake_head.append(y1)
        snake_list.append(snake_head)
        
        if len(snake_list) > snake_length:
            del snake_list[0]

        # (--- 新增 ---) 判斷蛇頭是否撞到 "中間的牆壁"
        if snake_head in wall_blocks:
            game_over = True

        # 判斷蛇頭是否撞到身體
        for block in snake_list[:-1]:
            if block == snake_head:
                game_over = True

        # 畫出蛇
        draw_snake(snake_list)
        
        pygame.display.flip()

        # 判斷是否吃到食物
        if x1 == food_x and y1 == food_y:
            # (--- 修改 ---) 呼叫新的函式來產生食物
            food_x, food_y = generate_food(wall_blocks, snake_list)
            
            # 蛇的長度加 1 (我們還是保持牠吃不胖)
            # snake_length += 1

        # 控制遊戲速度
        clock.tick(SPEED)

    pygame.quit()
    quit()

# 執行遊戲主程式
game_loop()