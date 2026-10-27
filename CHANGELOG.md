# Jurnal de modificări — Undava

Format: [SemVer](https://semver.org/lang/ro/) (MAJOR.MINOR.PATCH). Istoricul complet al versiunilor anterioare este în secțiunea „Istoric / Changelog” din [README.ro.md](README.ro.md).

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
