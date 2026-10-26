// Undava 2.0.0 — joc live local fără server (WebRTC + QR prin modulul p2p): gazdă + 2 jucători, scoruri, deconectare, mesaje ostile.
// Rulare: node tests/live.test.mjs   (rețea externă blocată; Chromium cu candidate locale permise DOAR în test)
import path from "node:path";
import {fileURLToPath} from "node:url";
import {createRequire} from "node:module";
const require = createRequire(process.env.PW_ROOT || import.meta.url); const {chromium} = require("playwright");
const FILE = "file://" + path.join(path.dirname(fileURLToPath(import.meta.url)), "..", "index.html");
let ok = 0, bad = 0;
const check = (n, c, x) => { if (c) { ok++; console.log("  ok   " + n); } else { bad++; console.log("  FAIL " + n + (x !== undefined ? "  -> " + JSON.stringify(x) : "")); } };
const b = await chromium.launch({executablePath: process.env.CHROMIUM_PATH || undefined, args: ["--no-sandbox", "--allow-loopback-in-peer-connection", "--disable-features=WebRtcHideLocalIpsWithMdns"]});
const net = [], errs = [], dlgs = [];
async function mk() {
  const pre = (p) => p.addInitScript(() => { window.__log = []; });
  const ctx = await b.newContext({viewport: {width: 1000, height: 800}, serviceWorkers: "block"});
  await ctx.route(/^https?:/, r => { net.push(r.request().url()); r.abort(); });
  const p = await ctx.newPage(); p.on("pageerror", e => errs.push(e.message)); p.on("dialog", d => { dlgs.push(d.message()); d.dismiss(); });
  await p.goto(FILE); await p.waitForTimeout(1200); return p;
}
const D = (p, role) => p.locator("[data-role=" + role + "]");
async function connect(H, G, name, count) {
  await H.click('[data-action="liveadd"]'); await D(H, "p2p-dialog").waitFor();
  await D(H, "optin").check(); await D(H, "mode-host").click(); await D(H, "gen-offer").click();
  await D(H, "offer-out").waitFor(); const offer = await D(H, "offer-out").inputValue();
  await G.click('[data-action="livejoin"]'); await D(G, "p2p-dialog").waitFor();
  await D(G, "optin").check(); await D(G, "mode-join").click();
  await D(G, "offer-in").fill(offer); await D(G, "gen-answer").click();
  await D(G, "answer-out").waitFor(); const answer = await D(G, "answer-out").inputValue();
  await D(H, "answer-in").fill(answer); await D(H, "connect").click();
  await G.waitForSelector("#lp-name", {timeout: 25000});
  await G.fill("#lp-name", name); await G.click('[data-action="lpjoin"]');
  await H.waitForFunction(n => LIVE.peers.filter(p => p.joined).length === n, count, {timeout: 10000}).catch(async e => { console.log('DBG', JSON.stringify(await H.evaluate(() => LIVE.peers.map(p => [p.name, p.joined, p.alive, p.s.state]))), await G.evaluate(() => state.lp && state.lp.phase)); throw e; });
}
const Q = [
  {text: "2 + 2 = ?", type: "quiz", time: 20, points: 1000, answers: [{text: "4", correct: true}, {text: "3", correct: false}, {text: "5", correct: false}, {text: "22", correct: false}], explain: "Doi plus doi."},
  {text: "Capitala Japoniei?", type: "type", time: 20, points: 1000, answers: [{text: "Tokyo"}, {text: "Tōkyō"}]},
  {text: "21 x 2 = ?", type: "num", time: 20, points: 1000, tol: 0, answers: [{text: "42"}]},
  {text: "Pământul e rotund", type: "tf", time: 20, points: 1000, answers: [{text: "Adevărat", correct: true}, {text: "Fals", correct: false}]}
];
process.env.DBG1&&0;
const H = await mk(), G1 = await mk(), G2 = await mk();
await H.evaluate(q => { state.quizzes.push({id: "qlive", title: "Test live", desc: "", color: "#2f6bff", questions: q}); openSetup("qlive"); }, Q);
check("setup: modul „Joc live local” e oferit", await H.locator('[data-action="mode"][data-v="live"]').count() === 1);
await H.click('[data-action="mode"][data-v="live"]');
check("lobby: avertisment despre IP/rețea locală afișat", await H.evaluate(() => /Wi-?Fi|IP/i.test(document.querySelector(".panel").textContent)));
await H.click('[data-action="startgame"]');
check("start fără jucători: refuzat, fără joc", await H.evaluate(() => !state.play && state.screen === "setup"));
await connect(H, G1, "Ana", 1);
await connect(H, G2, "Ana", 2); // același nume -> atribuire unică
const names = await H.evaluate(() => LIVE.peers.map(p => p.name));
check("două jucătoare conectate; numele duplicat primește sufix", names.length === 2 && names[0] === "Ana" && names[1] !== "Ana" && names[1].startsWith("Ana"), names);
check("lobby arată ambii jucători ca „conectat”", await H.evaluate(() => document.querySelectorAll("#players .player-row").length === 2));
check("jucătorul vede ecranul de așteptare", await G1.evaluate(() => /Test live/.test(document.querySelector(".hero").textContent)));

await H.click('[data-action="startgame"]');
await G1.waitForSelector('.lp-lock[data-action="lpans"]', {timeout: 15000}); await G2.waitForSelector('.lp-lock[data-action="lpans"]', {timeout: 15000});
check("jucătorii primesc întrebarea 1 cu 4 variante, fără indicii despre răspunsul corect", await G1.evaluate(() => document.querySelectorAll('[data-action="lpans"]').length === 4 && !/correct/i.test(JSON.stringify(state.lp.q))));
check("gazda arată întrebarea fără butoane interactive și contorul de răspunsuri", await H.evaluate(() => !!document.getElementById("live-cnt") && document.querySelectorAll('[data-action="answer"]').length === 0));
// mesaje ostile: indice greșit, tip greșit, întrebare veche
await G2.evaluate(() => { const s = state.lp.s; s.send({t: "a", i: 99, k: 1}); s.send({t: "a", i: 0, k: 17}); s.send({t: "a", i: 0, k: "x"}); s.send({t: "a", i: 0, s: "ceva"}); s.send({t: "zzz"}); s.send({t: "a", i: 0, k: -1}); });
await H.waitForTimeout(400);
check("răspunsuri invalide/ostile sunt ignorate", await H.evaluate(() => !state.play.roundAnswers[1] && !state.play.roundAnswers[0] && state.play.phase === "question"));
await G1.click('[data-action="lpans"][data-i="0"]'); // corect
check("după răspuns, jucătorul vede „răspuns trimis”", await G1.evaluate(() => state.lp.phase === "sent"));
await H.waitForFunction(() => /\b1\b.*\b2\b/.test(document.getElementById("live-cnt").textContent), null, {timeout: 5000}).catch(() => {});
check("gazda numără răspunsurile (1 din 2)", await H.evaluate(() => /\b1\b.*\b2\b/.test(document.getElementById("live-cnt").textContent)));
await G2.click('[data-action="lpans"][data-i="1"]'); // greșit
await G1.waitForFunction(() => state.lp.phase === "result", null, {timeout: 10000}); await G2.waitForFunction(() => state.lp.phase === "result", null, {timeout: 10000});
const r1 = await G1.evaluate(() => state.lp.res), r2 = await G2.evaluate(() => state.lp.res);
check("Ana: corect, puncte > 0, locul 1", r1.ok && r1.pts > 500 && r1.rank === 1 && r1.of === 2, r1);
check("a doua: greșit, 0 puncte, locul 2, vede răspunsul corect", !r2.ok && r2.pts === 0 && r2.rank === 2 && r2.right === "4", r2);
check("explicația întrebării ajunge la jucători", r1.ex === "Doi plus doi.");
check("gazda arată rezultatele (revelare) cu ambii jucători", await H.evaluate(() => state.play.phase === "reveal" && document.querySelectorAll(".rr").length === 2));
await H.click('[data-action="continue"]'); // scoreboard
await H.waitForFunction(() => state.play.phase === "scoreboard"); await H.click('[data-action="continue"]');
// întrebarea 2: text liber
await G1.waitForSelector("#lp-text", {timeout: 15000}).catch(async e => { console.log("DBG host", await H.evaluate(() => [state.play.phase, state.play.qIndex, state.screen]), "g1", await G1.evaluate(() => [state.lp.phase, state.lp.q && state.lp.q.ty]), "g2", await G2.evaluate(() => state.lp.phase)); throw e; }); await G2.waitForSelector("#lp-text", {timeout: 15000});
await G1.fill("#lp-text", "tokyo"); await G1.click('[data-action="lptext"]');
await G2.fill("#lp-text", "Osaka"); await G2.press("#lp-text", "Enter");
await G1.waitForFunction(() => state.lp.phase === "result"); await G2.waitForFunction(() => state.lp.phase === "result");
check("text liber: „tokyo” acceptat (fără diferență de majuscule), „Osaka” respins", (await G1.evaluate(() => state.lp.res.ok)) === true && (await G2.evaluate(() => state.lp.res.ok)) === false);
await H.click('[data-action="continue"]'); await H.waitForFunction(() => state.play.phase === "scoreboard"); await H.click('[data-action="continue"]');
// întrebarea 3: numeric — a doua jucătoare se deconectează înainte să răspundă
await G1.waitForSelector("#lp-text", {timeout: 15000}); await G2.waitForSelector("#lp-text", {timeout: 15000});
await G2.evaluate(() => state.lp.s.close("local"));
await H.waitForFunction(() => LIVE.peers.filter(p => p.alive).length === 1, null, {timeout: 15000});
check("deconectare detectată de gazdă; jocul continuă", await H.evaluate(() => state.play.phase === "question"));
await G1.fill("#lp-text", "42"); await G1.click('[data-action="lptext"]');
await G1.waitForFunction(() => state.lp.phase === "result", null, {timeout: 10000});
check("rundă încheiată imediat când răspund toți cei conectați (fără a aștepta timerul)", await G1.evaluate(() => state.lp.res.ok === true));
await H.click('[data-action="continue"]'); await H.waitForFunction(() => state.play.phase === "scoreboard"); await H.click('[data-action="continue"]');
// întrebarea 4: tf
await G1.waitForSelector('[data-action="lpans"]', {timeout: 15000});
check("tf: două variante", await G1.locator('[data-action="lpans"]').count() === 2);
await G1.click('[data-action="lpans"][data-i="0"]'); await G1.waitForFunction(() => state.lp.phase === "result");
await H.click('[data-action="continue"]');
await H.waitForFunction(() => state.play.phase === "scoreboard" || state.play.phase === "podium"); if (await H.evaluate(() => state.play.phase === "scoreboard")) await H.click('[data-action="continue"]');
await G1.waitForFunction(() => state.lp.phase === "end", null, {timeout: 15000});
const end = await G1.evaluate(() => state.lp.end);
check("final: jucătorul primește locul și scorul", end.rank === 1 && end.of === 2 && end.score > 2000 && end.total === 4, end);
check("gazda arată podiumul", await H.evaluate(() => state.play.phase === "podium" && !!document.querySelector(".podium")));
// joc nou cu aceiași jucători
await H.click('[data-action="playagain"]');
check("„Joc nou” revine în lobby cu jucătorii rămași conectați", await H.evaluate(() => state.screen === "setup" && state.setup.mode === "live" && liveJoined().length === 1));
await G1.waitForFunction(() => state.lp.phase === "wait");
// ieșire gazdă
await H.click('[data-action="startgame"]'); await G1.waitForSelector('[data-action="lpans"]', {timeout: 15000});
await H.evaluate(() => stopAll());
await G1.waitForFunction(() => state.lp.phase === "closed" || state.lp.phase === "lost", null, {timeout: 15000});
check("când gazda iese, jucătorul primește mesaj și poate părăsi", await G1.evaluate(() => /Gazda|conexiunea/i.test(document.querySelector(".hero").textContent)));
await G1.click('[data-action="lpleave"]'); check("jucătorul revine acasă", await G1.evaluate(() => state.screen === "home" && !state.lp));
// i18n
const miss = await H.evaluate(() => { const o = []; for (const k in LV) for (const l of ["ro", "en", "fr", "it", "es", "pt", "de", "hu"]) if (!I18N[l][k]) o.push(l + ":" + k); return o; });
check("chei noi în toate cele 8 limbi", miss.length === 0, miss);
check("fără cereri de rețea externe", net.length === 0, net.slice(0, 3));
check("fără dialoguri native", dlgs.length === 0, dlgs);
check("fără erori JS", errs.length === 0, errs);
await b.close(); console.log("\nUndava live: " + ok + " ok, " + bad + " eșuate"); process.exit(bad ? 1 : 0);
