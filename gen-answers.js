// 从 sudoku-data.js 求解题面并生成 api/sudoku-answers.php（答案只进入服务端）
const fs = require('fs');
const path = require('path');

const src = fs.readFileSync(path.join(__dirname, 'sudoku-data.js'), 'utf8');
const window = {};
eval(src); // sudoku-data.js 只做 window.SUDOKU_DATA=[...]，数据为字面量
const data = window.SUDOKU_DATA;

function solve(board) {
  function rec() {
    let best = -1, opts = null;
    for (let i = 0; i < 81; i++) if (!board[i]) {
      const r = (i / 9) | 0, c = i % 9, choices = [];
      for (let d = 1; d <= 9; d++) {
        let ok = true;
        for (let k = 0; k < 9; k++) {
          const br = ((r / 3) | 0) * 3 + ((k / 3) | 0), bc = ((c / 3) | 0) * 3 + k % 3;
          if (board[r * 9 + k] === d || board[k * 9 + c] === d || board[br * 9 + bc] === d) { ok = false; break; }
        }
        if (ok) choices.push(d);
      }
      if (!choices.length) return false;
      if (!opts || choices.length < opts.length) { best = i; opts = choices; }
    }
    if (best < 0) return true;
    for (const d of opts) { board[best] = d; if (rec()) return true; board[best] = 0; }
    return false;
  }
  return rec() ? board.join('') : '';
}

if (!Array.isArray(data) || data.length !== 100) {
  console.error('题库长度异常:', data && data.length);
  process.exit(1);
}
for (const it of data) {
  if (!it || typeof it.c !== 'string' || !/^[0-9]{81}$/.test(it.c)) {
    console.error('存在非法答案项');
    process.exit(1);
  }
}

const rows = data.map(it => {
  const answer = solve(it.c.split('').map(Number));
  if (!/^[0-9]{81}$/.test(answer)) { console.error('存在无法求解题目'); process.exit(1); }
  return "'" + answer + "'";
});
const out = "<?php\n// 自动生成：题库权威答案（脚本：gen-answers.js），与 sudoku-data.js 的 a 字段一一对应\n// 索引 0 对应第 1 关。修改题库后请重新运行 node gen-answers.js。\nreturn array(\n  " + rows.join(",\n  ") + "\n);\n";
fs.writeFileSync(path.join(__dirname, 'api', 'sudoku-answers.php'), out, 'utf8');
console.log('已写出 api/sudoku-answers.php，共', data.length, '条答案');
