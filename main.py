import pygame, sys, random

# ---------------------------------
# 1. 遊戲輔助函式 (Helper Functions)
# ---------------------------------
def create_pipe():
    """ 
    隨機產生一對新的水管 (上方 & 下方)
    回傳： (上方水管的 Rect, 下方水管的 Rect)
    """
    # 隨機決定水管縫隙的Y軸位置
    # random.randrange(min, max) 會回傳一個 min ~ max 之間的隨機數
    # 我們讓縫隙在 200 到 400 像素之間
    random_pipe_pos = random.randrange(250, 450)
    
    # 建立「下方」水管的 Rect
    # 它的 top (頂部) 在 random_pipe_pos，寬 52，高 300
    bottom_pipe = pipe_surface.get_rect(midtop=(400, random_pipe_pos))
    
    # 建立「上方」水管的 Rect
    # 它的 bottom (底部) 在 random_pipe_pos - 150 (這是縫隙大小)
    top_pipe = pipe_surface.get_rect(midbottom=(400, random_pipe_pos - 150))
    
    return top_pipe, bottom_pipe

def move_pipes(pipes):
    """
    將 pipes 列表中的所有水管向左移動
    回傳：新的水管列表
    """
    new_pipes = []
    for pipe in pipes:
        pipe.x -= 3 # 水管向左移動 3 像素
        # 只保留還在畫面上的水管
        if pipe.right > 0:
            new_pipes.append(pipe)
    return new_pipes

def draw_pipes(pipes):
    """
    繪製所有水管
    """
    for pipe in pipes:
        # 如果 pipe 的底部 y 座標 > 400，代表它是「下方」水管
        if pipe.bottom >= 600:
            screen.blit(pipe_surface, pipe)
        else:
            # 否則，它是「上方」水管，我們需要將圖片「翻轉」
            flipped_pipe = pygame.transform.flip(pipe_surface, False, True) # (圖片, X翻轉, Y翻轉)
            screen.blit(flipped_pipe, pipe)

def check_collision(pipes):
    """
    檢查小鳥是否撞到水管或邊界
    回傳：True (遊戲結束) / False (遊戲繼續)
    """
    # 1. 檢查是否撞到水管
    for pipe in pipes:
        if bird_rect.colliderect(pipe):
            return True # 撞到了，遊戲結束
            
    # 2. 檢查是否撞到天空或地板
    if bird_rect.top <= 0 or bird_rect.bottom >= 550:
        return True # 撞到了，遊戲結束
        
    return False # 安全過關

def update_score(score_list, score):
    """
    更新分數
    """
    if score_list:
        # 檢查小鳥是否飛過了第一組水管的中心
        if score_list[0].centerx < bird_rect.centerx:
            score += 1
            # 移除這組水管，這樣才不會重複計分
            score_list.pop(0) 
    return score

def display_score(game_state, score, high_score):
    """
    在螢幕上顯示分數
    """
    if game_state == 'playing':
        score_text = game_font.render(str(score), True, (255, 255, 255))
        score_rect = score_text.get_rect(center=(200, 50))
        screen.blit(score_text, score_rect)
        
    if game_state == 'game_over':
        # 遊戲結束時，顯示目前分數
        score_text = game_font.render(f"Score: {score}", True, (255, 255, 255))
        score_rect = score_text.get_rect(center=(200, 50))
        screen.blit(score_text, score_rect)

        # 顯示最高分數
        high_score_text = game_font.render(f"High Score: {high_score}", True, (255, 255, 255))
        high_score_rect = high_score_text.get_rect(center=(200, 100))
        screen.blit(high_score_text, high_score_rect)
        
        # 顯示提示訊息
        over_text = game_font.render("GAME OVER", True, (255, 0, 0))
        over_rect = over_text.get_rect(center=(200, 250))
        screen.blit(over_text, over_rect)
        
        restart_text = game_font.render("Press SPACE to restart", True, (255, 255, 255))
        restart_rect = restart_text.get_rect(center=(200, 300))
        screen.blit(restart_text, restart_rect)

# ---------------------------------
# 2. 遊戲主程式
# ---------------------------------

# 初始化 Pygame
pygame.init()

# --- 遊戲設定 ---
WIDTH, HEIGHT = 400, 600
screen = pygame.display.set_mode((WIDTH, HEIGHT))
pygame.display.set_caption("Flappy Bird by Python")
clock = pygame.time.Clock()

# 載入字體 (None = 預設字體, 40 = 大小)
game_font = pygame.font.Font(None, 40)

# --- 遊戲變數 ---
# 物理
GRAVITY = 0.25
bird_velocity = 0
FLAP_STRENGTH = -7 # 往上飛的力量 (負數代表Y軸往上)

# 遊戲狀態
game_active = True # True: 遊戲進行中, False: 遊戲結束畫面
score = 0
high_score = 0

# --- 載入資源 (圖片) ---
# 為了方便，如果找不到圖片，我們會用「色塊」代替
try:
    # 小鳥
    bird_surface = pygame.image.load('bird.png').convert_alpha() # convert_alpha() 讓圖片透明背景生效
    bird_surface = pygame.transform.scale(bird_surface, (34, 24)) # 縮放圖片
except:
    bird_surface = pygame.Surface((34, 24)) # 建立一個 34x24 的空畫布
    bird_surface.fill((255, 255, 0)) # 填滿黃色
# 取得小鳥的「矩形 (Rect)」，並放在起始位置
bird_rect = bird_surface.get_rect(center=(50, HEIGHT // 2))

try:
    # 背景
    bg_surface = pygame.image.load('background.png').convert()
    bg_surface = pygame.transform.scale(bg_surface, (WIDTH, HEIGHT))
except:
    bg_surface = pygame.Surface((WIDTH, HEIGHT))
    bg_surface.fill((100, 100, 255)) # 填滿藍色

try:
    # 水管
    pipe_surface = pygame.image.load('pipe.png').convert()
    pipe_surface = pygame.transform.scale(pipe_surface, (52, 300)) # 縮放水管
except:
    pipe_surface = pygame.Surface((52, 300))
    pipe_surface.fill((0, 200, 0)) # 填滿綠色

# --- 水管邏輯 ---
pipe_list = [] # 儲存所有在畫面上的水管
# 建立一個「計分用」的列表
# 裡面只放「下方」水管，用來判斷是否飛過
score_pipe_list = [] 

# 建立一個自訂事件，用來定時產生水管
SPAWNPIPE = pygame.USEREVENT
# 設定計時器，每 1.5 秒 (1500 毫秒) 觸發一次 SPAWNPIPE 事件
pygame.time.set_timer(SPAWNPIPE, 1500)


# ---------------------------------
# 3. 遊戲主迴圈 (Game Loop)
# ---------------------------------
while True:
    
    # --- 3.1 事件處理 (Event Handling) ---
    for event in pygame.event.get():
        if event.type == pygame.QUIT:
            pygame.quit()
            sys.exit()
            
        # 監聽鍵盤按鍵
        if event.type == pygame.KEYDOWN:
            # 如果按下空白鍵
            if event.key == pygame.K_SPACE:
                if game_active:
                    # 遊戲中：小鳥往上飛
                    bird_velocity = 0 # 速度歸零
                    bird_velocity += FLAP_STRENGTH # 加上往上的力量
                else:
                    # 遊戲結束畫面：重新開始
                    game_active = True
                    pipe_list.clear()
                    score_pipe_list.clear()
                    bird_rect.center = (50, HEIGHT // 2)
                    bird_velocity = 0
                    score = 0

        # 監聽自訂事件 (SPAWNPIPE)
        if event.type == SPAWNPIPE and game_active:
            new_top_pipe, new_bottom_pipe = create_pipe()
            pipe_list.append(new_top_pipe)
            pipe_list.append(new_bottom_pipe)
            score_pipe_list.append(new_bottom_pipe) # 只把下方水管加入計分列表

    # --- 3.2 遊戲邏輯 (Game Logic) ---
    
    if game_active:
        # --- 遊戲進行中 ---
        
        # (1) 更新小鳥物理
        bird_velocity += GRAVITY
        bird_rect.y += bird_velocity
        
        # (2) 更新水管位置
        pipe_list = move_pipes(pipe_list)
        
        # (3) 檢查碰撞
        if check_collision(pipe_list):
            game_active = False # 遊戲結束
            
        # (4) 更新分數
        score = update_score(score_pipe_list, score)
        if score > high_score:
            high_score = score
            
    else:
        # --- 遊戲結束 ---
        # (所有邏輯都暫停)
        pass

    # --- 3.3 畫面繪製 (Drawing) ---
    
    # (1) 畫背景
    screen.blit(bg_surface, (0, 0))
    
    # (2) 畫水管
    if game_active:
        draw_pipes(pipe_list)
    
    # (3) 畫小鳥
    screen.blit(bird_surface, bird_rect)
    
    # (4) 畫地板 (我們用一個簡單的色塊)
    pygame.draw.rect(screen, (220, 200, 100), (0, 550, WIDTH, 50))
    
    # (5) 畫分數
    display_score( 'playing' if game_active else 'game_over', score, high_score)

    # --- 3.4 畫面更新 ---
    pygame.display.update()
    
    # 控制遊戲速度 (FPS)
    clock.tick(100) # 讓遊戲以每秒 100 偵的速度運行