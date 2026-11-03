# 数独 · 静心一刻

> 一格一数，慢慢来就好。

一个**零依赖、纯前端**的数独小游戏，内置 100 个关卡，从入门到挑战渐进，支持本地进度与最佳成绩记录。

## ✨ 功能特性

- 🎯 **100 个关卡**，从易到难自动排序（线索数 51 → 25 递减）
- 📊 **10 个章节**（晨间练习、轻松起步、数字漫步、午后片刻、静心推理、思路交汇、专注时分、层层深入、终极挑战、大师之路）
- ✏️ **笔记模式**：每格可记录候选数
- ↩️ **撤销**：可回退任意一步
- 💡 **提示**：一键填入正确数字
- 🧩 **挑战模式**：关闭对错提示，纯靠推理
- ⌨️ **键盘支持**：数字 `1-9` 输入、方向键移动、`Backspace/Delete` 擦除
- 📱 **移动端适配**：触控优化与响应式布局
- 💾 **本地存档**：进度、用时、最佳成绩保存在浏览器 `localStorage`

## 🚀 快速开始

无需构建，直接用浏览器打开 `index.html` 即可游玩：

```bash
# 方式一：直接双击打开
start index.html

# 方式二：本地静态服务器（可选）
npx serve .
```

> 题库已内置在 `sudoku-data.js`，无需额外操作。如需重新生成题库，请安装 Node.js 后运行 `node gen-data.js`。

## 🎮 玩法说明

| 操作 | 方式 |
|------|------|
| 选择格子 | 鼠标点击 / 触控点选 |
| 填入数字 | 点击下方数字面板，或按键盘 `1-9` |
| 移动格子 | 键盘方向键 `↑ ↓ ← →` |
| 擦除数字 | 键盘 `Backspace` / `Delete`，或「擦除」按钮 |
| 笔记 | 切换「笔记」模式后输入数字 |
| 撤销 | 「撤销」按钮 |
| 提示 | 选中空格后点「提示」，填入正确数字 |
| 挑战模式 | 侧边栏开关（不提示对错） |

完成一关后自动解锁下一关，并记录用时与最佳成绩。

## 🗂 项目结构

```
sudoku/
├── index.html        # 页面骨架：棋盘、面板、侧边栏、完成弹窗
├── sudoku.js         # 全部游戏逻辑（状态、渲染、输入、计时、存档）
├── sudoku.css        # 样式（含移动端适配）
├── sudoku-data.js    # 自动生成的题库（100 关）
├── gen-data.js       # 题库生成工具（一次性脚本）
└── README.md         # 本文档
```

## 🔧 题库生成（gen-data.js）

题库由 Node.js 脚本离线生成，核心流程：

1. **构造合法解**：由 `(r*3 + r/3 + c) % 9 + 1` 生成基础完整解
2. **随机变换**：数字置换 + 行列/块交换 + 转置，扩充题目变体
3. **唯一解挖空**：逐步挖掉数字，每次用回溯求解器保证解唯一
4. **难度排序**：以 MRV 回溯的**分支数**作为难度指标，从易到难输出

每关数据为 81 字符的 clue 串（`0` 表示空格）与 81 字符的 answer 串。

## 🛠 技术栈

- **前端**：原生 HTML / CSS / JavaScript（IIFE，无框架、无构建）
- **生成脚本**：Node.js（仅用于生成题库，运行时不依赖）
- **存储**：`localStorage`（键：`quiet-sudoku`，仅保存在本设备浏览器）

## 📄 许可证

本项目基于 MIT License 开源。

```
MIT License

Copyright (c) 2026 <作者名>

Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the "Software"), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software, and to permit persons to whom the Software is
furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all
copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
SOFTWARE.
```
