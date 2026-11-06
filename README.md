# 数独 · 静心一刻

> 一格一数，慢慢来就好。

一个**零依赖、轻量全栈**的数独小游戏。内置 100 个渐进关卡与每日挑战，支持账号体系、云端进度、经验等级、勋章成就、排行榜与后台管理。未登录也可纯本地畅玩，登录后自动云端同步并累计经验。

线上站点：<https://sudoku.southerly.top/>

## ✨ 功能特性

### 游戏核心
- 🎯 **100 个关卡**，从易到难自动排序（线索数 51 → 25 递减），按难度分 5 档
- 📊 **10 个章节**（晨间练习、轻松起步、数字漫步、午后片刻、静心推理、思路交汇、专注时分、层层深入、终极挑战、大师之路）
- ✏️ **笔记模式**：每格可记录候选数
- ↩️ **撤销**：可回退任意一步
- 💡 **提示**：一键填入正确数字
- 🧩 **挑战模式**：关闭对错提示，纯靠推理
- ⌨️ **键盘支持**：数字 `1-9` 输入、方向键移动、`Backspace/Delete` 擦除
- 📱 **移动端适配**：触控优化与响应式布局
- ⏱ **计时优化**：进入关卡不计时，**首次填数才开始**；完成即停表；每关可「↻ 重新开始」

### 账号与进阶
- 👤 **注册 / 登录**（JWT 认证，Bearer Token）
- ☁️ **云端进度同步**：登录后自动上传/拉取各关进度与最佳成绩
- 🏅 **经验等级**：完成关卡获得经验，`exp_needed(level)=100+(level-1)*50`，升级弹提示
- 🎖 **勋章成就**：11 枚勋章（完成数、极速、时长、高难度、连续 7 天完成每日挑战），解锁加经验
- 📅 **每日挑战**：每天全球同题（按日期种子生成，目标 34 线索、唯一解），完成 +40 经验、计连胜（连续完成天数），激活「七日之约」勋章
- 🏆 **排行榜**：所选关卡的时间榜（该关最佳用时，用时越短越靠前），跟随选关联动
- 🛠 **后台管理**：用户/统计/勋章管理，支持封禁、调角色、重置、删除
- 📱 **PWA 离线**：可「添加到主屏幕」当 App 用，断网也能玩（游戏核心本地可玩，登录态/每日挑战需联网）

## 🚀 快速开始（纯本地）

无需构建，直接用浏览器打开 `index.html` 即可游玩（未登录走本地 `localStorage`）：

```bash
# 方式一：直接双击打开
start index.html

# 方式二：本地静态服务器（可选）
npx serve .
```

> 题库已内置在 `sudoku-data.js`。如需重新生成题库，请安装 Node.js 后运行 `node gen-data.js`。

## 🖥 部署（完整功能：账号 / 云同步 / 等级 / 勋章 / 排行榜 / 后台）

### 环境要求
- Web 服务器：Nginx（宝塔）或 Apache，**PHP 5.4+**（含 `pdo_mysql`、`curl`、`openssl`）
- 数据库：**MySQL 5.7+**（utf8mb4）

### 部署步骤
1. 将项目文件上传到网站根目录（如 `/www/wwwroot/sudoku.southerly.top/`）。
2. 编辑 `api/config.php`，配置数据库连接、`JWT_SECRET`（改为随机长串）、初始管理员 `ADMIN_PASS`。
   > `config.php` 已在 `.gitignore`，请勿提交真实凭据。
3. 访问一次 `https://你的域名/api/install.php` 完成初始化（**建表 + 创建管理员 + 写入 11 枚勋章种子**），**完成后删除 `install.php`**。
4. 路由说明：
   - **Nginx（宝塔）免伪静态**：接口走查询参数 `?r=xxx`，无需额外配置。
   - **Apache**：`api/.htaccess` 已内置重写，支持路径式 `/api/xxx`。
5. **PWA 离线**：`manifest.json` + `sw.js` 已就绪，需 HTTPS（已满足）。首次访问后 Service Worker 自动缓存应用外壳，可「添加到主屏幕」。
   > 更新资源版本号（如 `?v=22`）时，同步修改 `sw.js` 顶部的 `ASSETS` 列表，并把 `CACHE` 常量改为新版本号以触发更新。

### 后台管理
访问 `https://你的域名/admin/`，用 `config.php` 里的管理员账号登录（`role=admin`）。

## 🎮 玩法说明

| 操作 | 方式 |
|------|------|
| 选择格子 | 鼠标点击 / 触控点选 |
| 填入数字 | 点击下方数字面板，或按键盘 `1-9` |
| 移动格子 | 键盘方向键 `↑ ↓ ← →` |
| 擦除数字 | 键盘 `Backspace` / `Delete`，或「擦除」按钮 |
| 笔记 | 切换「笔记」模式后输入数字 |
| 撤销 | 「撤销」按钮 |
| 重新开始 | 「↻ 重新开始」按钮（清空本关进度并归零计时） |
| 提示 | 选中空格后点「提示」，填入正确数字 |
| 挑战模式 | 侧边栏开关（不提示对错） |

完成一关后自动解锁下一关，记录用时与最佳成绩；登录后云端保存并结算经验/勋章。

## 🗂 项目结构

```
sudoku/
├── index.html            # 页面骨架：棋盘、面板、侧边栏、登录弹窗、完成弹窗
├── sudoku.js             # 全部前端逻辑（游戏 + 认证 + 云同步 + 排行榜 + 计时）
├── sudoku.css            # 样式（含移动端适配、勋章墙、排行榜）
├── sudoku-data.js        # 自动生成的题库（100 关）
├── gen-data.js           # 题库生成工具（一次性脚本）
├── manifest.json         # PWA 应用清单（名称/图标/standalone）
├── sw.js                 # Service Worker（离线缓存应用外壳）
├── icons/                # PWA 图标（192/512 + apple-touch-icon）
├── admin/                # 后台管理前端
│   ├── index.html
│   └── admin.js
├── api/                  # 后端 API（PHP + MySQL）
│   ├── index.php         # 路由入口（?r= 查询参数式）
│   ├── config.php        # DB / JWT / 管理员配置（gitignore）
│   ├── db.php            # PDO 单例
│   ├── jwt.php           # JWT HS256 手写实现
│   ├── auth.php          # requireAuth / requireAdmin / bearer_token
│   ├── compat.php        # PHP 5.4 兼容层（password_hash 等价、ph_verify）
│   ├── resp.php          # JSON 响应助手（ok/fail/body）
│   ├── install.php       # 首次初始化（建表 + 管理员 + 勋章种子）
│   ├── schema.sql        # 数据库结构
│   ├── .htaccess         # Apache 路径式重写
│   └── handlers/         # auth.php / progress.php / game.php / admin.php
├── docs/UPGRADE_PLAN.md  # 升级规划（阶段一~五）
└── README.md             # 本文档
```

## 🗄 数据库设计

| 表 | 说明 |
|----|------|
| `users` | 用户（username/password_hash/nickname/avatar/role/status/exp/level） |
| `game_progress` | 云端进度（每关一份：cells 81 字符、notes、seconds） |
| `game_records` | 通关记录（level、seconds、is_record、played_at） |
| `achievements` | 勋章定义（code/category/cond_type/cond_value/points/active） |
| `user_achievements` | 用户已解锁勋章 |
| `daily_records` | 每日挑战完成记录（user_id/date/seconds，每日每用户唯一） |

## 🔌 API 接口

通用响应：`{ok: true, data: {...}}` 或 `{ok: false, error: "..."}`。认证接口请求头带 `Authorization: Bearer <jwt>`。

| 方法 | 路径 | 说明 |
|------|------|------|
| POST | `?r=auth/register` | 注册 `{username,password,nickname}` → `{token,user,expNext,levelExp}` |
| POST | `?r=auth/login` | 登录 → `{token,user,expNext,levelExp}` |
| GET  | `?r=auth/me` | 当前用户信息 + 经验/等级 |
| GET  | `?r=progress` | 拉取全部云端进度 |
| PUT  | `?r=progress/{level}` | 上传单关进度 `{cells,notes,seconds}` |
| POST | `?r=game/complete` | 结算 `{level,seconds}` → `{expGain,level,levelUp,isRecord,newAchievements}` |
| GET  | `?r=achievements` | 勋章列表 + 用户解锁状态 + 进度 |
| GET  | `?r=me/stats` | 我的统计（完成数/总时长/最佳各关） |
| GET  | `?r=leaderboard&level=X` | 某关卡时间榜（该关最佳用时，用时短靠前） |
| GET  | `?r=daily` | 今日挑战（题面+完成状态+连胜+近60天记录） |
| POST | `?r=daily/complete` | 每日完成结算 `{seconds}` → `{expGain,streak,firstToday}` |

### 后台（需 `role=admin`）

| 方法 | 路径 | 说明 |
|------|------|------|
| GET  | `?r=admin/overview` | 总览统计 |
| GET  | `?r=admin/users` | 用户列表（分页/搜索/排序） |
| GET  | `?r=admin/users/{id}` | 用户详情（统计/勋章/最佳各关） |
| POST | `?r=admin/users/{id}/status` | 封禁/解封 |
| POST | `?r=admin/users/{id}/role` | 调整角色（user/admin） |
| POST | `?r=admin/users/{id}/reset` | 重置用户进度 |
| POST | `?r=admin/users/{id}/delete` | 删除用户 |
| GET  | `?r=admin/achievements` | 勋章列表 |
| POST | `?r=admin/achievements/{id}/toggle` | 启用/停用勋章 |
| GET  | `?r=admin/logs` | 操作日志 |

## 🏅 等级与勋章

**经验来源（`game/complete` 结算）**

| 行为 | 经验 |
|------|------|
| 完成关卡（按难度档） | 入门 20 / 简单 30 / 中等 40 / 困难 50 / 挑战 60 |
| 首次完成该关 | 额外 ×1.5（向下取整） |
| 刷新本关最佳 | 额外 +20 |
| 解锁勋章 | 勋章 `points` 值 |

**升级公式**：`exp_needed(level) = 100 + (level - 1) * 50`；等级由累计经验推算。

**勋章（11 枚）**：完成数（1/10/25/50/100 关）、极速（单关 <60s / <30s）、累计时长（1h/10h）、高难度（第 81 关及以上）、连续 7 天完成每日挑战（`daily_7` 七日之约）。

### 每日挑战

- **题面**：按日期（`Asia/Shanghai`）做种子确定性生成（LCG + 随机变换 + 挖空保持唯一解），目标 34 线索，全球每天同题，无需缓存。
- **独立**：不占用 100 关闯关进度（不写 `saved.boards`、不占 `completed`）。
- **结算**：完成 +40 经验（一天一次计经验），连胜 = 连续完成每日挑战的天数（含今天）。
- **接口**：`GET ?r=daily`（今日题+完成状态+连胜）、`POST ?r=daily/complete`（`{seconds}`）。

## 🔧 题库生成（gen-data.js）

题库由 Node.js 脚本离线生成，核心流程：

1. **构造合法解**：由 `(r*3 + r/3 + c) % 9 + 1` 生成基础完整解
2. **随机变换**：数字置换 + 行列/块交换 + 转置，扩充题目变体
3. **唯一解挖空**：逐步挖掉数字，每次用回溯求解器保证解唯一
4. **难度排序**：以 MRV 回溯的**分支数**作为难度指标，从易到难输出

每关数据为 81 字符的 clue 串（`0` 表示空格）与 81 字符的 answer 串。

## 🛠 技术栈

- **前端**：原生 HTML / CSS / JavaScript（IIFE，无框架、无构建、`fetch`）
- **后端**：PHP 5.4+（PDO 预处理、手写 JWT HS256、crypt 兼容层）
- **数据库**：MySQL（utf8mb4）
- **生成脚本**：Node.js（仅用于生成题库，运行时不依赖）
- **存储**：未登录用 `localStorage`；登录后云端（MySQL）+ 本地兜底

## 🔒 安全要点

- 密码 `crypt()` Blowfish 哈希（PHP 5.4 兼容），绝不明文存储
- PDO **预处理语句**，杜绝 SQL 注入
- JWT **签名 + 过期校验**，密钥在 `config.php`（不提交 git）
- 管理端点强制 `role=admin` 校验
- 服务端输入校验：`level ∈ [1,100]`、`seconds ∈ [0,1e5]`、`cells` 长度 81
- 响应统一 `{ok, data|error}`

## 📄 许可证

本项目基于 MIT License 开源。
