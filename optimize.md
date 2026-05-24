# Token spend optimization — plan wdrożenia

**Utworzony:** 2026-05-23 (po wydaniu 1.9.0).
**Cel:** zredukować koszt tokenowy typowej sesji `/start-task` o 20-30% bez utraty kontekstu architektonicznego.

Plik tymczasowy — usuń po realizacji wszystkich pozycji (pozostawione, lub przeniesione do `docs/`).

---

## ✅ Zrealizowane teraz (sesja 2026-05-23 po release 1.9.0)

### 1. Spłaszcz CLAUDE.md "Ostatnio zamknięte"
**Akcja:** przeniesiono verbose log "Ostatnio zamknięte" do `docs/HISTORY.md`. W CLAUDE.md zostaje 1-2 zdania + link.
**Oszczędność:** ~2 500 znaków × każdy turn = -8-12% kontekstu na turn.

### 2. Spłaszcz "Wydania" w CLAUDE.md
**Akcja:** zastąpiono listę 1.2 → 1.9 paragrafem 1-liniowym + link do CHANGELOG.md.
**Oszczędność:** ~600 znaków per turn.

### 3. Kompaktowa tabela epików
**Akcja:** zamknięte epiki sprowadzono do `| HMAI-XXX | Tytuł | ✓ N/N (X.Y.Z) |`. Otwarte zostają full-form.
**Oszczędność:** ~1 500 znaków per turn.

### 4. Włącz `/compact` mid-session
**Akcja:** Dodana reguła w CLAUDE.md "Zasady pracy z Claude Code": po większym kroku (zamknięty ticket / epic review / release) **proponuj** userowi `/compact`. Nie wykonuj automatycznie.
**Oszczędność:** ~30-50% kontekstu po każdym wywołaniu — działa skumulowanie w wielokrokowych sesjach.

### 14. Split CLAUDE.md statyczne ↔ dynamiczne
**Akcja:** utworzony `docs/CURRENT-STATE.md` (dynamic state — release status, fixVersion, otwarte epiki) — **NIE auto-loadowany**, ładuj tylko na żądanie. CLAUDE.md zostaje 1-linijkowy reference do CURRENT-STATE + HISTORY.
**Oszczędność:** ~5-8% per turn (CURRENT-STATE czytany rzadko, nie na każdą tool call).

### Skill modifications dla efektów 6, 7, 8, 9
**Akcja:** dodana sekcja `## Token efficiency` w `.claude/commands/start-task.md` — instruuje co robić:
- 6: Grep+Read offset/limit zamiast full file reads (Krok 4 + B4)
- 7: Bundle multiple Edit calls (Krok 7 + B9)
- 8: `make test` z `tail -20 | grep -E "OK|FAIL|Tests:"` (Krok 8 + B10)
- 9: Newman/Playwright z `run_in_background=true` (Krok 8 + B10)

---

## 📋 Zapamiętane do realizacji po 10 zadaniach

**Counter:** ostatnio: 0 ticketów po dziś (2026-05-23).
**Trigger:** po zamknięciu 10 zadań (skill start-task pomyślnie ukończonych) — przypomnij userowi o przejrzeniu poniższych.

### 10. JQL queries z explicit `fields` list
**Problem:** `searchJiraIssuesUsingJql` bez `fields` zwraca 250+ KB output (jak dziś sesja z 50 issues).
**Akcja:** review `.claude/commands/` na wszystkie wywołania `searchJiraIssuesUsingJql` — wymuś `fields: ["summary", "status"]` lub minimum konieczne.
**Effort:** ~10 minut review + edit kilku skill files.

### 11. Atomic tickets > big PRs
**Problem:** duże PR-y (np. epic review z 5+ zmianami) trzymają duży delta-set w kontekście przez wiele tour.
**Akcja:** wytyczne dla project board:
- Każdy bug/refactor z >5 plików → split na 2-3 sub-tickety
- Epic review: domykać sub-tickety w osobnych sesjach (świeży kontekst)
- Maks scope per PR: ~10 plików zmienionych
**Effort:** dyskusja + wprowadzenie do kontraktu projektu (Confluence?).

### 12. Subagents dla research/audit
**Problem:** "przeszukaj cały codebase pod kątem X" → 10 Read calli w main context.
**Akcja:** rozszerz start-task Krok B4 (analiza kodu/testów epica): zamiast inline scanning, użyj `Agent subagent_type=Explore` do audytu i przyjmij tylko summary.
**Effort:** edit skill + 1 sesja testowa weryfikująca.

### 13. Skill body audit
**Problem:** `.claude/commands/start-task.md` ma ~180 linii, auto-loaded przy każdej inwokacji. Może mieć duplikację z CLAUDE.md.
**Akcja:** porównaj skill z CLAUDE.md, znajdź duplikaty (architektura, konwencje, środowisko Windows). Usuń duplikaty ze skill — CLAUDE.md jest źródłem prawdy.
**Effort:** ~30 min review + edit.

---

## Plan kontroli

**Sprawdzenie po 10 zadaniach** — sygnał: gdy w sesji widzisz "Następne kandydat: ..." po 10. zadanym worklogu od dziś, **przypomnij userowi**: `Po 10 zadaniach od 2026-05-23 — pora przejrzeć optimize.md (punkty 10-13). Czy realizujemy?`

**Sukces metryki:** liczba tokenów do zamknięcia średniego ticketu spada o ≥20% vs baseline z dzisiejszej sesji.

**Reweryfikacja optymalizacji:** w przyszłej dużej sesji (np. epic review) zmierz: liczba tool calls, średnia długość prompta, czy CURRENT-STATE jest czytane sporadycznie czy często. Jeśli często — wraca do CLAUDE.md.
