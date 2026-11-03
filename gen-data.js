// 题库生成工具：一次性生成 100 关，用回溯分支数作为难度指标，按难度排序输出 sudoku-data.js
const fs = require('fs');
const path = require('path');
const N = 9;

// 基础完整解（可证明合法）
const base = Array.from({ length: 81 }, (_, i) => { const r = (i / 9) | 0, c = i % 9; return (r * 3 + Math.floor(r / 3) + c) % 9 + 1; });

// 确定性随机
function rng(seed) { let s = seed; return () => { s = (s * 16807) % 2147483647; return (s - 1) / 2147483646; }; }

// 统计解数（最多 limit 个）
function countSolutions(board, limit = 2) {
  let count = 0;
  function solve() {
    if (count >= limit) return;
    let best = -1, opts = null;
    for (let i = 0; i < 81; i++) if (!board[i]) {
      const r = (i / 9) | 0, c = i % 9; let a = [];
      for (let d = 1; d <= 9; d++) {
        let ok = true;
        for (let k = 0; k < 9; k++) {
          const br = ((r / 3) | 0) * 3 + ((k / 3) | 0), bc = ((c / 3) | 0) * 3 + k % 3;
          if (board[r * 9 + k] === d || board[k * 9 + c] === d || board[br * 9 + bc] === d) { ok = false; break; }
        }
        if (ok) a.push(d);
      }
      if (!a.length) return;
      if (!opts || a.length < opts.length) { best = i; opts = a; if (a.length === 1) break; }
    }
    if (best < 0) { count++; return; }
    for (const d of opts) { board[best] = d; solve(); board[best] = 0; if (count >= limit) return; }
  }
  solve();
  return count;
}

// 回溯求解的分支数（MRV 启发），作为难度指标
function solveScore(board) {
  let branches = 0;
  function rec() {
    let best = -1, opts = null;
    for (let i = 0; i < 81; i++) if (!board[i]) {
      const r = (i / 9) | 0, c = i % 9; let a = [];
      for (let d = 1; d <= 9; d++) {
        let ok = true;
        for (let k = 0; k < 9; k++) {
          const br = ((r / 3) | 0) * 3 + ((k / 3) | 0), bc = ((c / 3) | 0) * 3 + k % 3;
          if (board[r * 9 + k] === d || board[k * 9 + c] === d || board[br * 9 + bc] === d) { ok = false; break; }
        }
        if (ok) a.push(d);
      }
      if (!a.length) return false;
      if (!opts || a.length < opts.length) { best = i; opts = a; }
    }
    if (best < 0) return true;
    branches += opts.length;
    for (const d of opts) { board[best] = d; if (rec()) return true; board[best] = 0; }
    return false;
  }
  rec();
  return branches;
}

// 生成一个合法完整解（数字置换 + 行列/块交换 + 转置）
function transform(seed) {
  const r = rng(seed);
  const perm = [1, 2, 3, 4, 5, 6, 7, 8, 9];
  for (let i = 8; i > 0; i--) { let j = Math.floor(r() * (i + 1)); [perm[i], perm[j]] = [perm[j], perm[i]]; }
  const blocks = [0, 1, 2];
  for (let i = 2; i > 0; i--) { let j = Math.floor(r() * (i + 1)); [blocks[i], blocks[j]] = [blocks[j], blocks[i]]; }
  const rows = new Array(9), cols = new Array(9);
  for (let b = 0; b < 3; b++) {
    const i1 = [0, 1, 2], i2 = [0, 1, 2];
    for (let i = 2; i > 0; i--) { let j = Math.floor(r() * (i + 1)); [i1[i], i1[j]] = [i1[j], i1[i]]; [i2[i], i2[j]] = [i2[j], i2[i]]; }
    for (let k = 0; k < 3; k++) { rows[b * 3 + k] = blocks[b] * 3 + i1[k]; cols[b * 3 + k] = blocks[b] * 3 + i2[k]; }
  }
  const trans = r() < 0.5, out = new Array(81);
  for (let i = 0; i < 81; i++) {
    let rr = (i / 9) | 0, cc = i % 9;
    if (trans) { const t = rr; rr = cc; cc = t; }
    out[i] = perm[base[rows[rr] * 9 + cols[cc]] - 1];
  }
  return out;
}

// 挖空到目标线索数，保持唯一解
function dig(answer, targetClues) {
  const board = answer.slice();
  let current = 81;
  for (let round = 0; round < 4 && current > targetClues; round++) {
    const r = rng(current * 1000 + round * 97 + board[0] * 31 + 7);
    const order = [...Array(81).keys()];
    for (let i = 80; i > 0; i--) { let j = Math.floor(r() * (i + 1)); [order[i], order[j]] = [order[j], order[i]]; }
    let progressed = false;
    for (const i of order) {
      if (current <= targetClues) break;
      if (board[i]) {
        const v = board[i]; board[i] = 0;
        if (countSolutions(board.slice()) === 1) { current--; progressed = true; } else board[i] = v;
      }
    }
    if (!progressed) break;
  }
  return board;
}

// 生成 100 关：目标线索 51 -> 25，每关计算难度分，最终按难度排序
function makeAll() {
  const items = [];
  let prev = 81;
  for (let level = 0; level < 100; level++) {
    const target = Math.max(25, Math.round(51 - (level / 99) * 26));
    let best = null;
    for (let a = 0; a < 4; a++) {
      const ans = transform(level * 997 + a * 131 + 17);
      const p = dig(ans, target);
      const clues = p.filter(x => x).length;
      const penalty = Math.abs(clues - target) * 1000 + (clues > prev ? 50000 : 0);
      if (!best || penalty < best.penalty) best = { p, ans, penalty, clues };
    }
    prev = best.clues;
    const score = solveScore(best.p.slice());
    items.push({ c: best.p, a: best.ans, clues: best.clues, score });
  }
  // 按难度分升序排序（易 -> 难）
  items.sort((x, y) => x.score - y.score);
  return items;
}

const levels = makeAll();

// 校验
let ok = true;
for (const lv of levels) {
  if (countSolutions(lv.c.slice()) !== 1) { ok = false; console.log('非唯一解:', lv.c.join('')); }
  if (lv.c.length !== 81 || lv.a.length !== 81) { ok = false; console.log('长度错误'); }
}
console.log('唯一解校验:', ok ? '全部通过' : '有失败');
console.log('线索范围:', Math.min(...levels.map(x => x.clues)), '~', Math.max(...levels.map(x => x.clues)));
console.log('难度分范围:', Math.min(...levels.map(x => x.score)), '~', Math.max(...levels.map(x => x.score)));
console.log('不同题目数:', new Set(levels.map(x => x.c.join(''))).size);

// 输出数据文件：每关 81 字符 clue（0 表示空）+ 81 字符 answer
const rows = levels.map(lv => {
  const c = lv.c.map(v => v).join('');
  const a = lv.a.map(v => v).join('');
  return `  {c:"${c}",a:"${a}"}`;
});
const out = `// 自动生成题库，共 ${levels.length} 关，按难度从易到难排序（脚本：gen-data.js）\nwindow.SUDOKU_DATA=[\n${rows.join(',\n')}\n];\n`;
fs.writeFileSync(path.join(__dirname, 'sudoku-data.js'), out, 'utf8');
console.log('已写出 sudoku-data.js，大小', (out.length / 1024).toFixed(1), 'KB');
