# Jurnal de modificări — Undava

Format: [SemVer](https://semver.org/lang/ro/) (MAJOR.MINOR.PATCH). Istoricul complet al versiunilor anterioare este în secțiunea „Istoric / Changelog” din [README.ro.md](README.ro.md).

## 2.2.0 — 2026-10-27

Anterior: v2.1.0.

### Adăugat
- Import de cursuri Fabrica: `.course.json` (format `fabrica-course`, schema 1) și copia `.fabrica.fff.json`. Se convertesc alegerea unică, adevărat/fals, răspunsul scurt, numericul (cu toleranță) și completarea cu un singur gol; restul (alegere multiplă, ordonare, potrivire, completare cu mai multe goluri, texte prea lungi) sunt sărite și numărate în mesaj. Markdown-ul devine text simplu; peste 100 de întrebări se împart în mai multe quiz-uri.
- Texte noi (`course_ok`, `course_err`) în 8 limbi (fr/it/es/pt/de/hu automate, nerevizuite de vorbitori nativi).
- Test nou `tests/v22.test.mjs` (19 verificări) cu un fișier generat de codul Fabrica 2.1.0 (`tests/fixtures/mostra-fabrica.course.json`).

### Nu s-a implementat
- Tipurile multi, ordonare, potrivire din Fabrica (Undava nu are echivalent).
- Aceleași funcții în ediția server `index.php` (copie separată, v1.0.1).
- Testul cu fișiere `.course.json` produse de alte versiuni de Fabrica decât 2.1.0 (formatul `schema: 1` este același, dar nu am rulat altă versiune).

## 2.1.0 — 2026-10-27

### Adăugat
- Mod prezentator pentru ecran mare / proiector: buton 📺 în bara jocului, comutator în Setări, tasta `P`; text, răspunsuri, cronometru, rezultate, clasament și podium mărite (doar la ferestre de cel puțin 900 px), cerere de ecran complet; starea se reține.
- Import din CSV (fișier `.csv`, CSV lipit sau șablon descărcabil) cu aceleași coloane ca Fabrica: tipurile `unic`, `adevarat-fals`, `scurt`, `numeric`; rândurile nerecunoscute sunt sărite și numărate. Import JSON existent neschimbat.
- Patru quiz-uri demonstrative în română (31 de întrebări), marcate „DEMO · RO”, cu notă în bibliotecă pentru celelalte limbi.
- Texte noi în 8 limbi (traducerile fr/it/es/pt/de/hu sunt automate, neverificate de vorbitori nativi).
- Test nou `tests/v21.test.mjs` (60 de verificări; rețea blocată).
- README: „Stare” și „Offline”.

### Reparat
- Containerul transparent al butonului de donații acoperea în joc bara de sus (✕ nu putea fi apăsat cu mouse-ul): acum doar linkul primește clicuri.

### Nu s-a implementat
- Citirea fișierelor `.course.json` din Fabrica (Fabrica poate exporta direct JSON pentru Undava, pe care importul JSON îl citește deja).
- Tipurile multi, completare, ordonare și potrivire din CSV (Undava nu are echivalent).
- Verificarea editorială a întrebărilor din banca demo (scrise de AI): de făcut de un profesor/redactor.
- Testarea modului prezentator pe un proiector/TV real și a ecranului complet în browsere reale (testat doar în Chromium headless; ecranul complet depinde de browser).
- Aceleași funcții în ediția server `index.php` (copie separată, v1.0.1).

## 2.0.0 — 2026-10-26

Versiunea anterioară; vezi README.ro.md.
