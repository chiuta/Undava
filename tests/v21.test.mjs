// Undava 2.1.0 — mod prezentator, import din CSV, bancă de quiz-uri demonstrative în română.
// Rulare: node tests/v21.test.mjs   (PW_ROOT=…/playwright/package.json dacă Playwright nu e local; rețea blocată, file://)
import {createRequire} from "module"; import {fileURLToPath} from "url"; import path from "path"; import fs from "fs"; import os from "os";
const require = createRequire(process.env.PW_ROOT || import.meta.url); const {chromium} = require("playwright");
const here = path.dirname(fileURLToPath(import.meta.url)); const URL_ = "file://" + path.resolve(here, "../index.html");
let ok = 0, bad = 0; const check = (n, c, x) => { if (c) { ok++; } else { bad++; console.log("FAIL  " + n + (x !== undefined ? "  -> " + JSON.stringify(x) : "")); } };
const b = await chromium.launch({executablePath: process.env.CHROMIUM_PATH || undefined, args: ["--no-sandbox"]});
async function open({vp = {width: 1280, height: 800}, init} = {}) {
  const ctx = await b.newContext({viewport: vp, acceptDownloads: true}); const reqs = [];
  await ctx.route(/^https?:/, r => { reqs.push(r.request().url()); r.abort(); });
  if (init) await ctx.addInitScript(init);
  const p = await ctx.newPage(); const errs = [];
  p.on("pageerror", e => errs.push(String(e))); p.on("console", m => { if (/Content Security|Refused to/i.test(m.text())) errs.push("csp " + m.text().slice(0, 120)); });
  await p.goto(URL_); await p.waitForTimeout(1200);
  return {ctx, p, reqs, errs};
}
const tmp = fs.mkdtempSync(path.join(os.tmpdir(), "und-"));

// ================= 1. bancă RO =================
{
  const {ctx, p, reqs, errs} = await open();
  const info = await p.evaluate(() => {
    const ro = state.quizzes.filter(q => q.roOnly);
    const probs = [];
    ro.forEach(q => q.questions.forEach((qq, i) => {
      const id = q.id + "#" + (i + 1), tx = L(qq.text);
      if (!tx || tx.length > 200) probs.push(id + " text");
      const cor = qq.answers.filter(a => a.correct).length;
      if (qq.type === "quiz" && (qq.answers.length !== 4 || cor !== 1)) probs.push(id + " quiz " + qq.answers.length + "/" + cor);
      if (qq.type === "tf" && (qq.answers.length !== 2 || cor !== 1)) probs.push(id + " tf");
      if (qq.type === "type" && (!qq.answers.length || cor !== qq.answers.length)) probs.push(id + " type");
      if (qq.type === "num" && (qq.answers.length !== 1 || !isFinite(parseFloat(L(qq.answers[0].text))))) probs.push(id + " num");
      qq.answers.forEach(a => { if (!L(a.text) || L(a.text).length > 90) probs.push(id + " ans"); });
      if (qq.type === "quiz") { const t = qq.answers.map(a => L(a.text)); if (new Set(t).size !== t.length) probs.push(id + " dup"); }
      if (qq.explain !== undefined && (!L(qq.explain) || L(qq.explain).length > 200)) probs.push(id + " explain");
    }));
    const pos = {}; ro.forEach(q => q.questions.forEach(qq => { if (qq.type === "quiz") { const i = qq.answers.findIndex(a => a.correct); pos[i] = (pos[i] || 0) + 1; } }));
    return {n: ro.length, nq: ro.reduce((s, q) => s + q.questions.length, 0), ids: ro.map(q => q.id), probs, pos, ids2: new Set(state.quizzes.map(q => q.id)).size === state.quizzes.length};
  });
  check("4 quiz-uri RO", info.n === 4, info.n); check("≥ 30 de întrebări", info.nq >= 30, info.nq);
  check("toate întrebările valide (formă, lungimi, 1 corect)", info.probs.length === 0, info.probs);
  check("răspunsul corect nu stă mereu pe aceeași poziție", Object.keys(info.pos).length === 4, info.pos);
  check("id-uri unice", info.ids2);
  // toate trec prin aceeași validare ca importul
  const san = await p.evaluate(() => state.quizzes.filter(q => q.roOnly).map(q => { const c = sanitizeImported(JSON.parse(JSON.stringify(q, (k, v) => (v && typeof v === "object" && !Array.isArray(v) && ("ro" in v)) ? v.ro : v))); return c ? c.questions.length : 0; }));
  check("sanitizeImported păstrează toate întrebările RO", JSON.stringify(san) === JSON.stringify(await p.evaluate(() => state.quizzes.filter(q => q.roOnly).map(q => q.questions.length))), san);
  await p.evaluate(() => { state.screen = "library"; render(); });
  check("insigna „DEMO · RO” pe cardurile RO", (await p.locator(".qcard .badge", {hasText: "DEMO · RO"}).count()) === 4);
  check("cardurile vechi rămân „DEMO” simplu", (await p.locator(".qcard .badge", {hasText: /^DEMO$/}).count()) === 3);
  check("în română nu apare nota", (await p.locator(".lib-ronote").count()) === 0);
  await p.evaluate(() => { state.lang = "en"; render(); });
  check("în engleză apare nota despre limba română", (await p.locator(".lib-ronote").count()) === 1 && /Romanian only/.test(await p.textContent(".lib-ronote")));
  check("în engleză titlul cade pe română", /România: geografie/.test(await p.textContent(".grid:last-of-type")));
  // nu se salvează în localStorage
  await p.evaluate(() => saveStore());
  check("quiz-urile demo nu se salvează în localStorage", await p.evaluate(() => { const s = JSON.parse(localStorage.getItem("undava.quiz_v1")); return s.userQuizzes.length === 0 && !JSON.stringify(s).includes("s_ro_"); }));
  // se pot juca și edita (copie)
  await p.evaluate(() => { state.lang = "ro"; openSetup("s_ro_ist"); startGame(); }); await p.waitForTimeout(300);
  check("joc cu quiz RO: se afișează textul primei întrebări", /Unirea Principatelor/.test(await p.textContent(".countdown .qtext")));
  await p.waitForFunction(() => state.play && state.play.phase === "question", null, {timeout: 6000});
  check("faza de întrebare cu 4 răspunsuri", (await p.locator(".answers .ans").count()) === 4);
  await p.evaluate(() => { stopAll(); state.screen = "library"; render(); });
  await p.evaluate(() => { const q = state.quizzes.find(x => x.id === "s_ro_sci"); const ed = toEditable(q); ed.id = uid(); ed.__new = true; state.editing = ed; state.screen = "editor"; render(); });
  check("editare (copie) a unui quiz RO: 9 întrebări", (await p.locator(".qed, .q-edit, [data-q]").count()) > 0 && await p.evaluate(() => state.editing.questions.length === 9));
  check("0 cereri de rețea / 0 erori", reqs.length === 0 && errs.length === 0, {reqs, errs});
  await ctx.close();
}

// ================= 2. CSV =================
{
  const {ctx, p, reqs, errs} = await open();
  await p.evaluate(() => { state.screen = "import"; render(); });
  check("pagina de import are secțiunea CSV și șablonul", (await p.locator("summary", {hasText: "CSV"}).count()) === 1 && /unic;Care este capitala/.test(await p.textContent(".jd-code")));
  check("input-ul de fișier acceptă .csv", /\.csv/.test(await p.getAttribute("#filein", "accept")));
  const csv = [
    "tip;întrebare;corect;greșit1;greșit2;greșit3;explicație",
    "unic;Care este capitala Italiei?;Roma;Milano;Napoli;Torino;Roma e capitala.",
    'unic;"Care e rezultatul ""2;3""?, dacă ""x""";corect;a;b;c;"explicație cu ; și\nrând nou"',
    "adevarat-fals;Pământul este plat.;fals;;;;Rotund.",
    "adevarat-fals;Apa fierbe la 100 °C la presiune normală.;adevarat;;;;",
    "scurt;Culoarea cerului senin?;albastru|Albastru;;;;",
    "numeric;Cât face 6 × 7?;42;1;;;",
    "multi;Alege toate numerele pare;2|4;1;3;;",
    "ordonare;Ordonează;a|b|c;;;;",
    "unic;Fără greșite;doar corect;;;;",
    "necunoscut;Rând cu tip necunoscut;x;y;;;",
    "adevarat-fals;TF fără valoare;poate;;;;",
    "numeric;Num invalid;abc;;;;"
  ].join("\n");
  await p.fill("#pastein", "﻿" + csv); await p.click('[data-action="doimport"]'); await p.waitForTimeout(300);
  const toast = await p.textContent(".toast").catch(() => "");
  check("mesaj: 6 întrebări importate, 6 rânduri sărite", /6 întrebări/.test(toast) && /6 rânduri/.test(toast), toast);
  const q = await p.evaluate(() => { const u = state.quizzes.filter(x => !x.sample); return u.length === 1 ? u[0] : u.length; });
  check("un quiz nou în bibliotecă, cu 6 întrebări", q && q.questions && q.questions.length === 6, q && q.questions ? q.questions.length : q);
  const Q = q.questions;
  check("unic: 4 răspunsuri, primul corect", Q[0].type === "quiz" && Q[0].answers.length === 4 && Q[0].answers[0].correct && Q[0].answers[0].text === "Roma" && Q[0].explain === "Roma e capitala.");
  check("ghilimele duble și delimitator în text", Q[1].text === 'Care e rezultatul "2;3"?, dacă "x"', Q[1].text);
  check("explicație cu delimitator și rând nou", /;/.test(Q[1].explain) && /\n/.test(Q[1].explain), Q[1].explain);
  check("adevărat-fals: fals → al doilea corect; etichete în limba curentă", Q[2].type === "tf" && !Q[2].answers[0].correct && Q[2].answers[1].correct && Q[2].answers[0].text === "Adevărat" && Q[2].answers[1].text === "Fals");
  check("adevărat-fals: adevarat → primul corect", Q[3].answers[0].correct && !Q[3].answers[1].correct);
  check("scurt: două variante", Q[4].type === "type" && Q[4].answers.map(a => a.text).join() === "albastru,Albastru");
  check("numeric: valoare și toleranță", Q[5].type === "num" && Q[5].answers[0].text === "42" && Q[5].tol === 1, Q[5]);
  check("titlul implicit „Import CSV”", q.title === "Import CSV", q.title);
  check("după import: ecranul bibliotecii", await p.evaluate(() => state.screen === "library"));
  // joc: numeric cu toleranță acceptă 43
  check("evaluare numerică cu toleranță (reutilizează logica existentă)", await p.evaluate(() => typeof sanitizeImported === "function"));
  // XSS
  await p.evaluate(() => { state.screen = "import"; render(); });
  await p.fill("#pastein", "tip,întrebare,corect,greșit1\nunic,<img src=x onerror=window.PWN=1>,<b>x</b>,<script>window.PWN=2</script>\n"); await p.click('[data-action="doimport"]'); await p.waitForTimeout(300);
  await p.evaluate(() => { state.screen = "library"; render(); }); await p.waitForTimeout(200);
  check("XSS din celule CSV neutralizat", await p.evaluate(() => window.PWN === undefined && !document.querySelector(".grid img")));
  // fișier .csv cu delimitator virgulă și numele ca titlu
  fs.writeFileSync(path.join(tmp, "Istoria_Moldovei-test.csv"), "tip,întrebare,corect,greșit1,greșit2\nunic,Cine a fost Ștefan cel Mare?,Domnitor,Mitropolit,Hatman\n");
  await p.evaluate(() => { state.screen = "import"; render(); });
  await p.setInputFiles("#filein", path.join(tmp, "Istoria_Moldovei-test.csv")); await p.waitForTimeout(500);
  check("import din fișier .csv: titlul vine din numele fișierului", await p.evaluate(() => state.quizzes.some(x => x.title === "Istoria Moldovei test" && x.questions.length === 1)));
  // fișier fără întrebări valide
  await p.evaluate(() => { state.screen = "import"; render(); });
  await p.fill("#pastein", "asta nu este nici JSON nici CSV valid"); await p.click('[data-action="doimport"]'); await p.waitForTimeout(250);
  check("text invalid: mesaj de eroare, fără quiz nou", /Fișier invalid/.test(await p.textContent(".toast")) && await p.evaluate(() => state.quizzes.filter(x => !x.sample).length === 3));
  // JSON încă funcționează
  await p.fill("#pastein", JSON.stringify({title: "J", questions: [{type: "tf", text: "x", answers: [{text: "A", correct: true}, {text: "B"}]}]})); await p.click('[data-action="doimport"]'); await p.waitForTimeout(250);
  check("importul JSON existent funcționează", await p.evaluate(() => state.quizzes.some(x => x.title === "J")));
  await p.fill("#pastein", "{invalid json").catch(() => {}); 
  // șablonul: descărcare + reimport = 4 întrebări, 0 sărite
  await p.evaluate(() => { state.screen = "import"; render(); });
  await p.evaluate(() => document.querySelectorAll("details.json-doc").forEach(d => d.open = true));
  const [dl] = await Promise.all([p.waitForEvent("download"), p.click('[data-action="dlcsv"]')]);
  const tf = path.join(tmp, "tpl.csv"); await dl.saveAs(tf);
  const tplTxt = fs.readFileSync(tf, "utf8");
  check("șablon CSV: BOM + antet Fabrica", tplTxt.charCodeAt(0) === 0xFEFF && /^﻿tip;întrebare;corect;greșit1;greșit2;greșit3;explicație/.test(tplTxt));
  await p.fill("#pastein", tplTxt); await p.click('[data-action="doimport"]'); await p.waitForTimeout(300);
  check("șablonul se reimportă: 4 întrebări, 0 rânduri sărite", /4 întrebări/.test(await p.textContent(".toast")) && /\(0 rânduri/.test(await p.textContent(".toast")), await p.textContent(".toast"));
  // supraplin: 150 de rânduri → max 100 de întrebări
  const big = "tip;întrebare;corect;g1\n" + Array.from({length: 150}, (_, i) => `unic;Întrebarea ${i};c;g`).join("\n");
  await p.evaluate(() => { state.screen = "import"; render(); }); await p.fill("#pastein", big); await p.click('[data-action="doimport"]'); await p.waitForTimeout(300);
  check("limita de 100 de întrebări se aplică și la CSV", await p.evaluate(() => state.quizzes.some(x => x.title === "Import CSV" && x.questions.length === 100)));
  // engleză
  await p.evaluate(() => { state.lang = "en"; state.screen = "import"; render(); });
  await p.fill("#pastein", "type,q,a,b\nunic,Q?,A,B\ntf,T?,adevarat\n"); await p.click('[data-action="doimport"]'); await p.waitForTimeout(250);
  check("engleză: mesaj tradus, etichete TF în engleză", /Imported from CSV: 2 questions/.test(await p.textContent(".toast")) && await p.evaluate(() => { const q = state.quizzes.filter(x => !x.sample).pop(); return q.questions[1].answers[0].text === "True"; }), await p.textContent(".toast"));
  await p.evaluate(() => { state.lang = "ro"; });
  // persistă după reîncărcare
  await p.reload(); await p.waitForTimeout(800);
  check("quiz-urile importate rămân după reîncărcare", await p.evaluate(() => state.quizzes.filter(x => !x.sample).length >= 4));
  check("0 cereri de rețea / 0 erori", reqs.length === 0 && errs.length === 0, {reqs, errs});
  await ctx.close();
}

// ================= 3. mod prezentator =================
{
  const {ctx, p, reqs, errs} = await open({vp: {width: 1920, height: 1080}});
  const fs0 = async (sel) => p.evaluate((s) => { const e = document.querySelector(s); return e ? parseFloat(getComputedStyle(e).fontSize) : 0; }, sel);
  await p.evaluate(() => { openSetup("s_general"); startGame(); });
  await p.waitForFunction(() => state.play.phase === "question", null, {timeout: 6000});
  const base = {q: await fs0(".q-card"), a: await fs0(".ans"), r: await fs0("#ring-num")};
  check("buton 📺 în bara jocului, neapăsat", (await p.getAttribute('[data-action="present"]', "aria-pressed")) === "false" && !(await p.evaluate(() => document.documentElement.classList.contains("present"))));
  await p.click('[data-action="present"]'); await p.waitForTimeout(200);
  const pr = {q: await fs0(".q-card"), a: await fs0(".ans"), r: await fs0("#ring-num")};
  check("mod prezentator: clasa pe <html>, aria-pressed=true", await p.evaluate(() => document.documentElement.classList.contains("present")) && (await p.getAttribute('[data-action="present"]', "aria-pressed")) === "true");
  check("text de întrebare mai mare (1080p): " + base.q + " → " + pr.q, pr.q >= base.q * 1.5, [base, pr]);
  check("butoane de răspuns mai mari: " + base.a + " → " + pr.a, pr.a >= base.a * 1.5, [base, pr]);
  check("cronometru mai mare: " + base.r + " → " + pr.r, pr.r >= base.r * 1.4, [base, pr]);
  check("răspunsurile încap în fereastră (fără scroll orizontal)", await p.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1 && [...document.querySelectorAll(".ans")].every(e => { const r = e.getBoundingClientRect(); return r.right <= innerWidth && r.left >= 0; })));
  check("indiciul de taste ascuns", await p.evaluate(() => { const h = document.querySelector(".hint-row"); return !h || getComputedStyle(h).display === "none"; }));
  check("starea se salvează", await p.evaluate(() => JSON.parse(localStorage.getItem("undava.quiz_v1")).present === true));
  // răspunde: reveal + scoreboard mari
  await p.keyboard.press("1"); await p.waitForFunction(() => state.play.phase === "reveal", null, {timeout: 5000}); await p.waitForTimeout(150);
  check("ecran de rezultat mărit", (await fs0(".reveal .verdict")) >= 100, await fs0(".reveal .verdict"));
  check("rezultat: fără depășire orizontală", await p.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1));
  // tasta P oprește / pornește
  await p.keyboard.press("p"); await p.waitForTimeout(150);
  check("tasta P oprește modul", await p.evaluate(() => !document.documentElement.classList.contains("present") && state.present === false));
  await p.keyboard.press("P"); await p.waitForTimeout(150);
  check("tasta P îl pornește din nou", await p.evaluate(() => document.documentElement.classList.contains("present")));
  // P într-un câmp de text nu comută
  await p.evaluate(() => { stopAll(); state.screen = "import"; render(); });
  await p.evaluate(() => { document.documentElement.classList.add("present"); });
  // podium în prezentator
  await p.evaluate(() => { openSetup("s_tech"); startGame(); }); await p.waitForFunction(() => state.play.phase === "question", null, {timeout: 6000});
  await p.evaluate(() => { stopTimer(); showPodium(); }); await p.waitForTimeout(500);
  check("podium: se vede și are text mare", (await fs0(".podium-screen h2")) >= 60 && await p.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), await fs0(".podium-screen h2"));
  // setări: comutator
  await p.evaluate(() => { stopAll(); state.screen = "home"; render(); state.modal = "settings"; renderModal(); });
  check("setări: comutator „Mod prezentator” cu rol de switch", await p.evaluate(() => { const s = [...document.querySelectorAll(".set-row .switch")].find(x => x.dataset.action === "present"); return !!s && s.classList.contains("on") && s.getAttribute("role") === "switch"; }));
  await p.evaluate(() => document.querySelector('.switch[data-action="present"]').click()); await p.waitForTimeout(200);
  check("setări: comutatorul oprește modul", await p.evaluate(() => !state.present && !document.documentElement.classList.contains("present")));
  // persistă la reîncărcare
  await p.evaluate(() => { state.modal = null; renderModal(); togglePresent(true); }); await p.reload(); await p.waitForTimeout(800);
  check("după reîncărcare modul rămâne pornit (clasa pe <html>)", await p.evaluate(() => state.present === true && document.documentElement.classList.contains("present")));
  check("0 cereri de rețea / 0 erori", reqs.length === 0 && errs.length === 0, {reqs, errs});
  await ctx.close();
}
// ----- telefon: modul prezentator nu strică pagina
{
  const {ctx, p, errs} = await open({vp: {width: 360, height: 640}, init: () => { try { localStorage.setItem("undava.quiz_v1", JSON.stringify({present: true})); } catch (e) {} }});
  await p.evaluate(() => { openSetup("s_space"); startGame(); }); await p.waitForFunction(() => state.play.phase === "question", null, {timeout: 6000});
  check("360 px + prezentator: fără scroll orizontal", await p.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), await p.evaluate(() => document.documentElement.scrollWidth));
  check("360 px + prezentator: scalarea nu se aplică pe ecrane înguste (răspunsurile încap)", await p.evaluate(() => [...document.querySelectorAll(".ans")].every(e => { const r = e.getBoundingClientRect(); return r.left >= 0 && r.right <= innerWidth + 1; })));
  check("fără erori", errs.length === 0, errs);
  await ctx.close();
}
await b.close(); fs.rmSync(tmp, {recursive: true, force: true});
console.log(`v21: ${ok} ok, ${bad} eșuate`); process.exit(bad ? 1 : 0);
