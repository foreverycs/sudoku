# 数独「静心一刻」升级规划：用户 · 等级 · 勋章 · 后台

> 版本：v1.0 规划稿
> 技术栈：**PHP + MySQL + JWT**
> 范围：把纯前端静态数独，升级为带账号体系的完整产品

---

## 1. 目标

在**不改动现有游戏核心逻辑**（棋盘、计时、笔记、撤销、题库）的前提下，新增：

- **用户体系**：注册 / 登录 / 个人资料 / 云端进度同步
- **等级体系**：经验值、等级、称号、经验条 UI
- **勋章体系**：成就定义、条件判定、解锁、勋章墙、获得提示
- **后台管理**：用户管理、数据统计、关卡管理、勋章管理、系统配置

## 2. 现状与约束

| 项 | 现状 |
|----|------|
| 前端 | 纯 HTML/CSS/JS，无框架无构建，进度存 `localStorage`（键 `quiet-sudoku`） |
| 后端 | 无（目录已有 `api.php` 等 PHP 文件，说明 PHP 可用） |
| 服务器 | 宝塔面板，`/www/wwwroot/www.southerly.top/net/sudoku/`，通常自带 MySQL |
| 域名 | `https://www.southerly.top/net/sudoku/`（HTTPS 已配） |

**核心判断**：升级必须引入后端。服务器已具备 PHP + MySQL，选 **PHP + MySQL** 成本最低。

## 3. 总体架构

```
前端（现有数独逻辑不动，新增账号面板 / 等级条 / 勋章墙）
        │  fetch('/api/...') JSON
        ▼
后端 API（PHP，/api 目录，轻量路由 + PDO）
        │
        ▼
MySQL（users / game_progress / game_records / achievements / user_achievements）
        │
        ▼
后台管理（/admin，独立登录，role=admin）
```

- 游戏核心**保持纯前端**；后端只做**认证、云同步、经验结算、勋章判定、统计**。
- 未登录：完全本地玩（**兼容现状**）；登录后：云端同步 + 计经验。

## 4. 技术选型

| 项 | 选择 | 理由 |
|----|------|------|
| 后端 | PHP 7.4+ | 宝塔自带，无额外运行时，与现有 `api.php` 一致 |
| 数据库 | MySQL 5.7+（utf8mb4） | 宝塔自带 |
| 认证 | JWT（HS256） | 无状态，前端存 token，API 友好 |
| 密码 | `password_hash`/`password_verify` | 原生安全哈希 |
| 前端 | 现有原生 JS + `fetch` | 不引入构建，保持零依赖 |
| JWT 实现 | 手写 HS256（约 30 行，无 composer） | 宝塔免依赖，可控 |

> 若后续引入 composer，可换 `firebase/php-jwt`，接口不变。

## 5. 数据库设计（MySQL）

```sql
-- 用户
CREATE TABLE users (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  username      VARCHAR(32)  NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  nickname      VARCHAR(32)  NOT NULL DEFAULT '',
  avatar        VARCHAR(255) NOT NULL DEFAULT '',
  role          ENUM('user','admin') NOT NULL DEFAULT 'user',
  status        TINYINT      NOT NULL DEFAULT 1,          -- 1 正常 / 0 封禁
  exp           INT          NOT NULL DEFAULT 0,
  level         INT          NOT NULL DEFAULT 1,
  last_login_at DATETIME     NULL,
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 云端进度（每关一份）
CREATE TABLE game_progress (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id    INT UNSIGNED NOT NULL,
  level      SMALLINT UNSIGNED NOT NULL,
  cells      VARCHAR(81) NOT NULL DEFAULT '',             -- 81 字符，0=空
  notes      TEXT NULL,
  seconds    INT UNSIGNED NOT NULL DEFAULT 0,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_user_level (user_id, level),
  CONSTRAINT fk_progress_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 通关记录（统计/勋章依据）
CREATE TABLE game_records (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id    INT UNSIGNED NOT NULL,
  level      SMALLINT UNSIGNED NOT NULL,
  seconds    INT UNSIGNED NOT NULL,
  is_record  TINYINT(1) NOT NULL DEFAULT 0,
  played_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_user (user_id, level),
  CONSTRAINT fk_records_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 勋章定义（后台可增改）
CREATE TABLE achievements (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code        VARCHAR(64) NOT NULL UNIQUE,
  name        VARCHAR(64) NOT NULL,
  description VARCHAR(255) NOT NULL DEFAULT '',
  icon        VARCHAR(255) NOT NULL DEFAULT '',
  category    ENUM('progress','speed','persistence','challenge','daily') NOT NULL DEFAULT 'progress',
  cond_type   VARCHAR(32) NOT NULL,   -- complete_count/best_seconds_lt/total_seconds_ge/daily_streak_ge/level_ge
  cond_value  INT NOT NULL DEFAULT 0,
  points      INT NOT NULL DEFAULT 0, -- 解锁额外经验
  active      TINYINT(1) NOT NULL DEFAULT 1,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 用户勋章
CREATE TABLE user_achievements (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id        INT UNSIGNED NOT NULL,
  achievement_id INT UNSIGNED NOT NULL,
  unlocked_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_user_ach (user_id, achievement_id),
  CONSTRAINT fk_ua_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_ua_ach  FOREIGN KEY (achievement_id) REFERENCES achievements(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

**种子数据**（`/api/admin/init` 或安装脚本）：
- 内置一枚 `role=admin` 账号（用户名/密码由环境变量或安装时设定）
- 内置勋章定义（见 §7）

## 6. 等级系统

**经验来源（`POST /api/game/complete` 结算）**

| 行为 | 经验 |
|------|------|
| 完成关卡（按难度） | 入门 20 / 简单 30 / 中等 40 / 困难 50 / 挑战 60 |
| 首次完成该关 | 额外 ×1.5（向下取整） |
| 刷新本关最佳 | 额外 +20 |
| 解锁勋章 | 勋章 `points` 值 |

**升级公式**
```
exp_needed(level) = 100 + (level - 1) * 50     // 每级所需
level = max{ n | sum(exp_needed(1..n-1)) <= exp }
```

**等级称号**（每 10 级一档，复用章节语义）
`1 晨间新手 · 10 轻松起步 · 20 数字漫步 · 30 午后片刻 · 40 静心推理 · 50 思路交汇 · 60 专注时分 · 70 层层深入 · 80 终极挑战 · 90 大师之路`

**前端**：侧边栏新增「等级条 + 经验进度 + 称号」，升级弹 toast。

## 7. 勋章系统

| code | 类别 | 条件(cond_type/cond_value) | 名称 |
|------|------|----------------------------|------|
| `prog_1` | progress | complete_count / 1 | 初露锋芒 |
| `prog_10` | progress | complete_count / 10 | 渐入佳境 |
| `prog_25` | progress | complete_count / 25 | 小有所成 |
| `prog_50` | progress | complete_count / 50 | 半程捷报 |
| `prog_100` | progress | complete_count / 100 | 百炼成钢 |
| `speed_60` | speed | best_seconds_lt / 60 | 电光石火 |
| `speed_30` | speed | best_seconds_lt / 30 | 一闪而逝 |
| `persist_1h` | persistence | total_seconds_ge / 3600 | 持之以恒 |
| `persist_10h` | persistence | total_seconds_ge / 36000 | 铁杵成针 |
| `chal_hard` | challenge | 通关「困难」及以上 | 勇攀高峰 |
| `daily_7` | daily | daily_streak_ge / 7 | 七日之约 |

**判定时机**：关卡完成结算时，后端读取用户统计，逐条检查未解锁勋章，达标则写入 `user_achievements` 并返回本次新解锁列表。

**前端**：侧边栏新增「勋章墙」（已解锁高亮/未解锁置灰），获得时弹 toast。

## 8. API 接口

**通用**：`POST /api/auth/register`、`POST /api/auth/login`、`GET /api/auth/me`；请求头 `Authorization: Bearer <jwt>`（登录后）。

| 方法 | 路径 | 说明 |
|------|------|------|
| POST | `/api/auth/register` | 注册 `{username,password,nickname}` → `{token,user}` |
| POST | `/api/auth/login` | 登录 → `{token,user}` |
| GET | `/api/auth/me` | 当前用户 `{user, level, exp, expNext, achievements:[]}` |
| GET | `/api/progress` | 拉取全部云进度 |
| PUT | `/api/progress/{level}` | 上传单关进度 `{cells,notes,seconds}` |
| POST | `/api/game/complete` | 结算 `{level,seconds}` → `{expGain, levelUp, newAchievements}` |
| GET | `/api/achievements` | 勋章列表 + 用户解锁状态 |
| GET | `/api/me/stats` | 我的统计（完成数、总时长、最佳各关） |

**后台（role=admin）**

| 方法 | 路径 | 说明 |
|------|------|------|
| POST | `/api/admin/login` | 管理登录 |
| GET | `/api/admin/users` | 用户列表（分页/搜索） |
| POST | `/api/admin/users/{id}/ban` | 封禁/解封 |
| GET | `/api/admin/stats` | 总览（注册/DAU/完成/时长/等级分布） |
| GET/POST/PUT/DELETE | `/api/admin/achievements` | 勋章定义管理 |
| GET/PUT | `/api/admin/levels/{id}` | 关卡启停 |
| POST | `/api/admin/init` | 首次初始化（建表/种子/管理员） |

## 9. 后台管理前端（/admin）

- 独立 `admin/index.html`（登录页 + 面板 SPA，原生 JS）
- 模块：仪表盘统计 / 用户管理 / 关卡管理 / 勋章管理 / 配置
- 视觉沿用现有 CSS 风格（绿色主题、卡片式）

## 10. 目录结构

```
/www/wwwroot/www.southerly.top/net/sudoku/
├── index.html            # 现有前端（不动）
├── sudoku.js / sudoku.css / sudoku-data.js
├── /api/                 # 后端
│   ├── index.php         # 路由入口（.htaccess 重写到 index.php）
│   ├── config.php        # DB / JWT 密钥 / 常量（禁止提交真实密钥）
│   ├── db.php            # PDO 单例
│   ├── jwt.php           # HS256 手写（encode/decode/verify）
│   ├── auth.php          # requireAuth() / requireAdmin()
│   ├── resp.php          # JSON 响应助手
│   └── /handlers/        # auth.php / progress.php / game.php / achievements.php / admin.php
├── /admin/               # 后台前端
│   ├── index.html
│   └── admin.js / admin.css
└── /assets/              # 共享静态资源
```

## 11. 安全要点

- 密码 `password_hash()`（BCRYPT），绝不明文存储
- PDO **预处理语句**，杜绝 SQL 注入
- JWT **签名校验 + 过期校验**，密钥放 `config.php`（不提交 git，宝塔单独配置）
- 管理端点强制 `role=admin` 校验
- 所有输入**服务端校验**：`level ∈ [1,100]`、`seconds ∈ [0,1e5]`、`cells` 长度 81
- 响应统一 `{ok, data|error}`；CORS 仅允许本站域名
- 敏感接口（注册/登录）限流（简单 IP 计数）

## 12. 迭代计划

| 阶段 | 内容 | 交付 |
|------|------|------|
| 一 | 建表 SQL、`/api` 框架、注册/登录/JWT | 可注册登录 |
| 二 | 前端接入：登录/注册弹窗、用户面板、本地↔云端进度 | 云同步可用 |
| 三 | 等级：经验结算、升级公式、等级条 UI | 等级系统 |
| 四 | 勋章：条件判定、解锁、勋章墙、toast | 勋章系统 |
| 五 | 后台：admin 登录、用户/统计/关卡/勋章管理 | 后台可用 |

**每阶段验收**：本机（宝塔/php -S）跑通 → 线上部署 → 手机实测。

## 13. 风险与注意

- **前端文件保持兼容**：未登录时行为与现在完全一致，避免影响已有用户。
- **`localStorage` 与云同步冲突**：登录后以云端为准拉取；本地作为离线兜底。
- **经验/勋章结算幂等**：同一关卡重复完成按"首次/新纪录"加成，防止刷分。
- **HTTPS 已配置**，JWT 走 HTTPS 传输安全。
