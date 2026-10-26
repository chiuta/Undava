// Undava 1.2.8 — date corupte în localStorage. Rulare: node storage.test.mjs <dir>   (rețea blocată, file://)
import {createRequire} from "module"; const require = createRequire(process.env.PW_ROOT || import.meta.url); const {chromium} = require("playwright");
let fails = 0; const check = (n, c, x) => { c ? console.log("  ok  " + n) : (fails++, console.log("FAIL  " + n + (x !== undefined ? "  -> " + JSON.stringify(x) : ""))); };
const dir = process.argv[2] || process.cwd();
const b = await chromium.launch({executablePath: process.env.CHROMIUM_PATH || undefined});
const good = {id: "q_keep", title: "<img src=x onerror=window.PWN=1>", desc: "d", questions: [{text: "<b>q</b>", type: "quiz", time: 20, points: 1000, answers: [{text: "<img src=x onerror=window.PWN=2>", correct: true}, {text: "b"}]}]};
for (const [n, v] of [["intrări nule/fără întrebări", {userQuizzes: [null, 5, {title: "x"}, good]}], ["userQuizzes nu e listă", {userQuizzes: {a: 1}}], ["stocare = 5", 5], ["stocare = șir", "x"]]) {
  const ctx = await b.newContext(); await ctx.route(/^https?:/, r => r.abort());
  await ctx.addInitScript(s => localStorage.setItem("undava.quiz_v1", s), JSON.stringify(v));
  const p = await ctx.newPage(); const errs = []; p.on("pageerror", e => errs.push(String(e)));
  await p.goto("file://" + dir + "/index.html"); await p.waitForTimeout(1500);
  await p.evaluate(() => { state.screen = "library"; render(); });
  check(n + ": fără erori", errs.length === 0, errs);
  check(n + ": fără XSS", await p.evaluate(() => window.PWN === undefined));
  if (n.startsWith("intrări")) check(n + ": doar quiz-ul valid rămâne, cu id păstrat", await p.evaluate(() => { const u = state.quizzes.filter(q => !q.sample); return u.length === 1 && u[0].id === "q_keep"; }));
  await ctx.close();
}
await b.close(); console.log(fails ? fails + " eșecuri" : "toate trecute"); process.exit(fails ? 1 : 0);
