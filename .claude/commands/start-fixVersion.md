Workflow: zrealizuj **CAŁY** fixVersion **$ARGUMENTS** autonomicznie — wszystkie zadania przez pełny workflow `/start-task`, z **automatycznym mergem po zielonym CI**, potem epic review, potem `/release-version`. To orkiestrator nad `/start-task`.

> **⚡ Świeży kontekst — zrób to PRZED `/start-fixVersion`:** ten przelot jest długi (N zadań × pełny workflow). Najlepszy efekt: wpisz `/clear`, a dopiero potem `/start-fixVersion $ARGUMENTS`. `/clear` i `/compact` to komendy harnessu uruchamiane przez użytkownika — model sam ich nie wywoła. User wybrał **przelot jednym ciągiem**, więc w trakcie harness będzie auto-kompaktował kontekst; startuj z maksymalnym budżetem.

> **Środowisko Windows:** jak w `/start-task` — tool `Bash` = WSL/Git-Bash (składnia POSIX), polecenia czysto-PowerShellowe (`Invoke-RestMethod`, `$env:VAR`) tylko przez tool `PowerShell`. Gdy `make` pada z `env: can't execute 'php'` → `docker exec aihm-php-1 sh -c "cd /var/www/html && …"` (dotyczy też `bin/console`, `vendor/bin/phpunit`, `phpstan`, `php-cs-fixer`, `rector`).

> **⚠️ Brak wersji?** Jeśli `$ARGUMENTS` jest **puste** — zatrzymaj się i poproś usera o numer wersji (`/start-fixVersion 1.23.0`). Nic nie zmieniaj w repo ani w Jirze.

## Czym to jest — orkiestrator nad `/start-task`

Dla **każdego** zadania wykonujesz PEŁNY workflow `/start-task {KEY}` (Ścieżka A) dokładnie jak w jego skillu — ze wszystkimi bramkami jakości (scoped phpunit → Rector dry-run → CS Fixer → PHPStan level 8 → pełny `make test` → `/code-review`), aktualizacją CLAUDE.md i worklogiem per zadanie. **Jedyna różnica względem samodzielnego `/start-task`: tu PR-y mergujesz automatycznie po zielonym CI.** Samodzielny `/start-task` kończy na Code Review i zostawia merge userowi; orkiestrator domyka **merge + `Gotowe`** (używa „recepty mergu" z sekcji `#pr` w `/start-task`).

**Trzy nadpisania `/start-task` w trybie orkiestratora:**
- **Pomijasz Krok 0 (budżet tokenów)** — user świadomie wybrał przelot jednym ciągiem, akceptując auto-kompaktowanie harnessu. Nie zatrzymuj się na ostrzeżeniu o świeżości kontekstu.
- **Pomijasz Krok 14 (rekomendacja „next")** — kolejką zarządza orkiestrator; per-zadaniowa sugestia to szum.
- **Krok 3a (startDate wersji)** robisz **raz**, w Kroku 1 orkiestratora — nie w każdym `/start-task`.

Wszystko inne z `/start-task` (walidacja 1:1 z CI, worklog, aktualizacja CLAUDE.md, Confluence, komentarz-analiza dla Buga w Kroku 12a) obowiązuje bez zmian.

## Kroki

**1. Preflight + baza develop + startDate.** `docker compose ps` (nie działa → `make up`). `git fetch && git checkout develop && git pull origin develop && git remote prune origin`, usuń lokalne branche zmergowane do developa. **StartDate wersji:** jeśli to pierwsze podjęte zadanie w `$ARGUMENTS`:
```
searchJiraIssuesUsingJql:
  project = HMAI AND fixVersion = "$ARGUMENTS" AND statusCategory != "To Do"
  fields: ["key","status"]
```
Zero wyników → wypisz `Manualne TODO: ustaw startDate={dzisiejsza data} dla wersji $ARGUMENTS (Ustawienia projektu → Wersje).` (ten zestaw MCP nie ma narzędzia do edycji metadanych wersji — nie wywołuj nieistniejącego narzędzia, tylko przypomnij i jedź dalej).

**2. Zbuduj kolejkę zadań.**
```
searchJiraIssuesUsingJql:
  project = HMAI AND fixVersion = "$ARGUMENTS" AND statusCategory != Done AND issuetype != Epik
  fields: ["summary","status","priority","issuelinks","description","parent"]
```
Posortuj wg kolejności realizacji (od najważniejszych do najmniejszych):
1. **Blokery najpierw** — licznik wychodzących linków „blocks" (outward w `issuelinks`) malejąco. Ticket blokujący najwięcej innych idzie pierwszy — dzięki temu, gdy zależny task startuje, blocker jest już **zmergowany** na developie (Krok 3a odświeża bazę). Brak formalnych „blocks" → użyj zależności logicznej (fundament typu „lista/skeleton", od którego wiszą CRUD/filtr/eksport/import).
2. **Największy scope** — potem pozostałe malejąco po wielkości: liczba endpointów/operacji/punktów akceptacji w opisie, nowa migracja, nowy moduł, zmiana cross-cutting.
3. **Najmniejszy scope** — na końcu pojedyncze, izolowane zmiany (jeden endpoint/akcja, bez migracji).

Remis → `priority DESC`, potem `key ASC`. **Wypisz kolejkę** userowi jako plan przelotu (`KEY | tytuł | powód pozycji`), zanim ruszysz z pierwszym zadaniem.

**3. Pętla po zadaniach.** Dla każdego `{KEY}` w ustalonej kolejności:
   - **a. Odśwież bazę:** `git checkout develop && git pull origin develop` — zależny task musi widzieć merge poprzedniego (blockera).
   - **b. Wykonaj `/start-task {KEY}`** — Ścieżka A, Kroki 4–13: In Progress → branch → plan → implementacja (hexagonal, Doctrine XML) → walidacja 1:1 z CI → CLAUDE.md → `/code-review` (napraw **Krytyczne**) → commit → PR → **czekaj na zielone CI** → worklog. Z nadpisaniami z sekcji wyżej (bez Kroku 0/14; startDate już zrobiony).
   - **c. Merge (należy do orkiestratora):** po zielonym CI zastosuj **receptę mergu** z `/start-task` (`#pr`): jeden commit na zadanie (squash jeśli >1), `gh pr merge {PR} --rebase --delete-branch`.
   - **d. Status `{KEY}` → Gotowe** (transition id `31`) po mergu.
   - **e.** Przejdź do następnego `{KEY}`.

   **CI czerwone i nie do naprawienia** (obca regresja/flaky spoza scope zadania, konflikt merge nierozstrzygalny automatycznie) → **zatrzymaj cały przelot**, zostaw PR otwarty, zaraportuj userowi (który KEY, jaki job, link do PR) i czekaj na decyzję. Nie idź do następnego zadania z rozjechanym developem.

**4. Epic review.** Gdy kolejka zadań pusta (wszystkie podzadania `Gotowe`) — znajdź epik wersji:
```
searchJiraIssuesUsingJql:
  project = HMAI AND fixVersion = "$ARGUMENTS" AND issuetype = Epik
  fields: ["summary","status"]
```
Wykonaj **`/start-task {EPIC_KEY}`** — Ścieżka B (epic review): B1–B7 (analiza pokrycia testami, dokumentacji Confluence, jakości commitów) → B9 (implementacja braków) → B10 walidacja → B11 `/code-review` → B12 commit → B13 PR + zielone CI.
   - **B8 ⛔ STOP nadpisany:** przelot jest autonomiczny (wybór usera) — nie zatrzymuj się na B8. Implementuj braki znalezione w B5/B6; gdy brak braków — domknij epik (sam PR aktualizacji dokumentacji modułu/CLAUDE.md, jeśli trzeba). Zatrzymaj się **tylko** gdy epic review odsłoni realnie niejednoznaczną, trudno-odwracalną decyzję zakresu (rzadkie) — wtedy raport + czekaj.
   - Po zielonym CI: **merge** (recepta jak 3c) + status epiku → **Gotowe** (id `31`). Confluence — przy epiku zwykle TAK (aktualizacja strony modułu).

**5. Release (automatyczny).** Po zamknięciu epiku uruchom **`/release-version $ARGUMENTS`** — pełny workflow wydania: CHANGELOG + CLAUDE.md status via chore-PR → **sync master (rebase-merge) + wyrównanie developa rebasem → dopiero potem tag → GitHub Release**. Ta kolejność jest krytyczna i pilnuje jej sam skill: tag założony przed syncem zostaje na commicie, którego rebase-merge nie przenosi na mastera (osierocone tagi 1.24.0–1.26.0). To świadomy wybór autonomii end-to-end (owner, ten skill).

**6. Podsumowanie przelotu.** Raport dla usera:
- Tabela zrealizowanych zadań (`KEY | tytuł | worklog`).
- Epik (zamknięty) + link do PR epic review.
- Release: tag, link do GitHub Release, sumaryczne liczniki testów.
- Manualne TODO, których MCP nie zrobi (np. `flip Jira fixVersion $ARGUMENTS → Released`, `startDate` wersji).
