// 从 sudoku-data.js 提取权威答案，生成 api/sudoku-answers.php（供后端结算校验，避免在 PHP 里正则解析 JS）
const fs = require('fs');
const path = require('path');

const src = fs.readFileSync(path.join(__dirname, 'sudoku-data.js'), 'utf8');
const window = {};
eval(src); // sudoku-data.js 只做 window.SUDOKU_DATA=[...]，数据为字面量
const data = window.SUDOKU_DATA;

if (!Array.isArray(data) || data.length !== 100) {
  console.error('题库长度异常:', data && data.length);
  process.exit(1);
}
for (const it of data) {
  if (!it || typeof it.a !== 'string' || !/^[0-9]{81}$/.test(it.a)) {
    console.error('存在非法答案项');
    process.exit(1);
  }
}

const rows = data.map(it => "'" + it.a + "'");
const out = "<?php\n// 自动生成：题库权威答案（脚本：gen-answers.js），与 sudoku-data.js 的 a 字段一一对应\n// 索引 0 对应第 1 关。修改题库后请重新运行 node gen-answers.js。\nreturn array(\n  " + rows.join(",\n  ") + "\n);\n";
fs.writeFileSync(path.join(__dirname, 'api', 'sudoku-answers.php'), out, 'utf8');
console.log('已写出 api/sudoku-answers.php，共', data.length, '条答案');
