// Undava 2.2.0 — import de cursuri Fabrica (.course.json / .fabrica.fff.json). Rulare: node tests/v22.test.mjs
// Fixture: tests/fixtures/mostra-fabrica.course.json, generat cu codul Fabrica 2.1.0 (courseToJson), nu scris de mână.
import {createRequire} from "module"; import {fileURLToPath} from "url"; import path from "path"; import fs from "fs";
const require = createRequire(process.env.PW_ROOT || import.meta.url); const {chromium} = require("playwright");
const here = path.dirname(fileURLToPath(import.meta.url)); const URL_ = "file://" + path.resolve(here, "../index.html");
let ok = 0, bad = 0; const check = (n, c, x) => { if (c) { ok++; } else { bad++; console.log("FAIL  " + n + (x !== undefined ? "  -> " + JSON.stringify(x) : "")); } };
const FIX = fs.readFileSync(path.join(here, "fixtures", "mostra-fabrica.course.json"), "utf8");
const b = await chromium.launch({executablePath: process.env.CHROMIUM_PATH || undefined, args: ["--no-sandbox"]});
async function open(lang) {
  const ctx = await b.newContext({viewport: {width: 1280, height: 800}}); const reqs = [];
  await ctx.route(/^https?:/, r => { reqs.push(r.request().url()); r.abort(); });
  const p = await ctx.newPage(); const errs = [];
  p.on("pageerror", e => errs.push(String(e))); p.on("console", m => { if (/Content Security|Refused to/i.test(m.text())) errs.push("csp " + m.text().slice(0, 120)); });
  await p.goto(URL_); await p.waitForTimeout(1000); return {ctx, p, reqs, errs};
}
const fixtureJson = JSON.parse(FIX);
check("fixture: format fabrica-course, schema 1, generat de Fabrica 2.1.0", fixtureJson.format === "fabrica-course" && fixtureJson.schema === 1 && fixtureJson.app === "Fabrica" && fixtureJson.version === "2.1.0");
{
  const {ctx, p, reqs, errs} = await open();
  const n0 = await p.evaluate(() => state.quizzes.length);
  await p.evaluate(t => tryImport(t, "mostra-fabrica.course.json"), FIX); await p.waitForTimeout(150);
  const r = await p.evaluate(n0 => { const q = state.quizzes[state.quizzes.length - 1]; return {added: state.quizzes.length - n0, title: q.title, desc: q.desc, qs: q.questions.map(x => ({type: x.type, text: x.text, tol: x.tol, ans: x.answers.map(a => a.text + (a.correct ? "*" : "")), explain: x.explain}))}; }, n0);
  check("un quiz adăugat", r.added === 1, r.added); check("titlul din curs", r.title === "Mostră Fabrica", r.title);
  check("5 întrebări convertite (single, tf, fill cu 1 gol, short, num)", r.qs.map(x => x.type).join() === "quiz,tf,type,type,num", r.qs.map(x => x.type));
  check("Markdown eliminat din text", r.qs[0].text === "Care este capitala Franței? Vezi harta.", r.qs[0].text);
  check("single: 1 corectă + 2 greșite", r.qs[0].ans.length === 3 && r.qs[0].ans.filter(a => a.endsWith("*")).join() === "Paris*", r.qs[0].ans);
  check("fill cu un gol -> type cu răspunsul", r.qs[2].text === "Un an obișnuit are _____ de zile." && r.qs[2].ans.join() === "365*", r.qs[2]);
  check("short: variante", r.qs[3].ans.join() === "Pacific*,Oceanul Pacific*", r.qs[3].ans);
  check("num: valoare + toleranță", r.qs[4].ans.join() === "2.5*" && r.qs[4].tol === 0.1, r.qs[4]);
  check("explicație păstrată", r.qs[4].explain === "Zecimal.", r.qs[4].explain);
  // persistă și se poate juca
  await p.reload(); await p.waitForTimeout(900);
  check("quiz-ul persistă după reîncărcare", await p.evaluate(() => state.quizzes.some(q => q.title === "Mostră Fabrica")));
  check("0 cereri de rețea / 0 erori", reqs.length === 0 && errs.length === 0, {reqs, errs});
  await ctx.close();
}
{ // mesajul (ro/en) cu numărul de întrebări sărite
  const {ctx, p} = await open();
  const msgs = await p.evaluate(t => { const out = {}; for (const l of ["ro", "en", "fr", "it", "es", "pt", "de", "hu"]) { out[l] = [tfmt.call(null, "course_ok", 5, 5), I18N[l] && I18N[l].course_ok, I18N[l] && I18N[l].course_err]; } return out; }, FIX);
  check("course_ok / course_err există în 8 limbi", Object.values(msgs).every(v => v[1] && v[2] && /\{0\}/.test(v[1]) && /\{1\}/.test(v[1])), msgs.ro);
  await ctx.close();
}
{ // .fabrica.fff.json, curs fără întrebări convertibile, >100 de întrebări, JSON non-Fabrica neschimbat, injecție
  const {ctx, p, errs} = await open();
  const r = await p.evaluate(txt => {
    const c = JSON.parse(txt).course; const n0 = state.quizzes.length, res = {};
    tryImport(JSON.stringify({format: "fff-bundle", schema: 1, app: "fabrica", data: {draft: c}}), "x.fabrica.fff.json"); res.bundle = state.quizzes.length - n0;
    const onlyMulti = {format: "fabrica-course", schema: 1, course: {title: "M", levels: [{lessons: [{questions: [{id: "m", type: "multi", text: "t", options: []}, {id: "o", type: "order", text: "t", items: ["a", "b"]}]}]}]}};
    const n1 = state.quizzes.length; tryImport(JSON.stringify(onlyMulti), "m.course.json"); res.none = state.quizzes.length - n1;
    const many = {format: "fabrica-course", schema: 1, course: {title: "Mare", levels: [{lessons: [{questions: Array.from({length: 130}, (_, i) => ({id: "q" + i, type: "tf", text: "Afirmația " + i, answer: i % 2 === 0}))}]}]}};
    const n2 = state.quizzes.length; tryImport(JSON.stringify(many), "mare.course.json"); res.many = state.quizzes.slice(n2).map(q => [q.title, q.questions.length]);
    const n3 = state.quizzes.length; tryImport(JSON.stringify({title: "Simplu", questions: [{type: "tf", text: "x", answers: [{text: "a", correct: true}, {text: "b"}]}]}), "s.json"); res.plain = state.quizzes.length - n3;
    const evil = {format: "fabrica-course", schema: 1, course: {title: "<img src=x onerror=window.PWN=1>", description: "d", levels: [{lessons: [{questions: [{id: "e", type: "tf", text: "<img src=x onerror=window.PWN=2>", answer: true}]}]}]}};
    tryImport(JSON.stringify(evil), "e.course.json"); state.screen = "library"; render(); res.pwn = window.PWN || null; res.imgs = document.querySelectorAll("#app img[src='x']").length;
    return res;
  }, FIX);
  await p.waitForTimeout(200);
  check(".fabrica.fff.json importat", r.bundle === 1, r.bundle); check("curs fără întrebări convertibile: nimic adăugat", r.none === 0, r.none);
  check("130 de întrebări -> 2 quiz-uri (100 + 30)", JSON.stringify(r.many) === JSON.stringify([["Mare (1/2)", 100], ["Mare (2/2)", 30]]), r.many);
  check("JSON Undava obișnuit neschimbat", r.plain === 1, r.plain); check("fără injecție HTML", r.pwn === null && r.imgs === 0, r);
  check("0 erori", errs.length === 0, errs);
  await ctx.close();
}
await b.close();
console.log(`v22: ${ok} ok, ${bad} eșuate`); process.exit(bad ? 1 : 0);
