# Current project state

**Updated:** 2026-05-23. Plik NIE jest auto-loaded do kontekstu Claude — czytaj ręcznie gdy potrzebujesz aktualnego stanu wydań/epików. Codzienna praca: bieżący release w CLAUDE.md "Wydania" (kompakt 1-liniowy), pełna historia w [docs/HISTORY.md](HISTORY.md), wszystkie releases w [CHANGELOG.md](../CHANGELOG.md).

---

## Bieżący release

**Tag:** `1.9.0` (2026-05-23). GitHub Release: https://github.com/zlotylesk/AIHomeManager/releases/tag/1.9.0.

**Theme:** Domain model & DDD purity (epic HMAI-131) + CSV exports (epic HMAI-132). Zamyka backlog code review HMAI-44.

**Testy:** 542/542 PHPUnit + 5/5 Playwright + 34/34 Newman. PHPStan level 8 clean.

**Confluence z aktualizacjami w tym release:**
- id 49053698 v4 — "Architektura heksagonalna i DDD w PHP" (Sekcja 7 DDD purity hardening)
- id 46891009 v5 — "Dokumentacja API" (CSV Export wzorce + per-module endpoints)
- id 50659329 v2 — "Pierwsze uruchomienie — konfiguracja zewnętrznych serwisów" (full rewrite z aktualnymi krokami)

---

## Backlog / fixVersion status

**Aktywny fixVersion:** brak. Wszystkie tickety z label `ai_code_review` zamknięte 2026-05-23. Następny batch wymaga nowego sourcing'u (audit, user feedback, lub bug reports).

**Epiki — wszystkie zamknięte** (snapshot 2026-05-23):

| Epik | Tytuł | Closed |
|---|---|---|
| HMAI-123 | Critical findings (C1–C12) | 1.2.0 |
| HMAI-124 | Persistence & DB integrity | 1.5.0 |
| HMAI-125 | Test coverage | 1.4.0 |
| HMAI-126 | Operability & observability | 1.6.0 |
| HMAI-127 | External API resilience | 1.3.0 |
| HMAI-128 | Frontend hardening | 1.7.1 |
| HMAI-129 | API hardening | 1.8.0 |
| HMAI-130 | Rate limiting & throttling | 1.3.0 |
| HMAI-131 | Domain model & DDD purity | 1.9.0 |
| HMAI-132 | Features — exports | 1.9.0 |

**Co dalej:** projekt w fazie utrzymania. Nowe pomysły jako standalone tickety, fixVersion = następny release przy mergu.

---

## Historia (pełna)

[docs/HISTORY.md](HISTORY.md) — verbose log domknięć per epik/release. Format archive — update po każdym kolejnym release.
