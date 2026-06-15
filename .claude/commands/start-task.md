Workflow Jira: **$ARGUMENTS**. Wykonuj kroki ściśle po kolei.

> **⚡ Świeży kontekst — zrób to PRZED `/start-task`:** ten workflow zakłada czystą sesję. Najlepszy efekt: wpisz `/clear`, a dopiero potem `/start-task $ARGUMENTS`. Dlaczego nie jako krok wewnątrz skilla — `/clear` kasuje cały kontekst (łącznie z numerem ticketu i treścią tych instrukcji), więc po nim nie ma czego kontynuować; `/compact` i `/clear` to komendy harnessu uruchamiane przez użytkownika, model sam ich nie wywoła. Jeśli wystartowałeś bez `/clear`, Krok 0 oceni świeżość kontekstu i w razie potrzeby przerwie, prosząc o reset.

> **Środowisko Windows:** Host to Windows 11 (`C:\Users\poczt\PhpstormProjects\AIHM`). Tool `Bash` w Claude Code uruchamia WSL/Git-Bash — większość poleceń POSIX-owych (`git`, `docker exec`, `sed`, `xargs`, `grep`) działa stamtąd. Polecenia czysto-PowerShellowe (`Invoke-RestMethod`, `$env:VAR`, `[IO.File]::ReadAllText`) używaj wyłącznie przez tool `PowerShell` — nie mieszaj składni. `make` działa w WSL; gdy pada z `env: can't execute 'php'` (PATH issue wokół docker compose CLI), użyj: `docker exec aihm-php-1 sh -c "cd /var/www/html && php …"` — to działa zarówno z Bash jak i PowerShell. Dotyczy też `bin/console`, `vendor/bin/phpunit`, `vendor/bin/phpstan`, `vendor/bin/php-cs-fixer`, `vendor/bin/rector`.

> **⚠️ Brak numeru zadania?** Jeśli `$ARGUMENTS` jest **puste** (wywołano `/start-task` bez klucza), NIE wykonuj kroków 0–14 ani ścieżki B. Przejdź od razu do [trybu sugestii](#suggest): zaproponuj trzy zadania (najszerszy scope / najwęższy scope / blokujące najwięcej innych) i **zatrzymaj się**, aż user wskaże konkretny klucz. Workflow startuje dopiero z `/start-task HMAI-XX`.

## Wspólne (0–3)

**0. Preflight — budżet tokenów sesji.** Przed czymkolwiek innym oceń, czy obecna sesja udźwignie cały workflow do zamknięcia worklogu. User nie chce przerywać pracy na odświeżenie limitu i wracać do połowicznego stanu (uncommitted diff, branch bez PR, status Jira "W toku" bez worklogu).

Heurystyka — jeśli **dwie lub więcej** z poniższych są prawdziwe, zatrzymaj się i powiedz userowi `Kontekst nie jest świeży — uruchom `/clear`, a potem ponownie `/start-task $ARGUMENTS` (czysty start = pełny budżet tokenów na cały workflow).`:
- Aktualna sesja ma >70% długości typowego okna kontekstu (≥1 kompaktowanie już się zdarzyło lub liczba wcześniejszych wywołań narzędzi przekracza ~150).
- Jira opis zadania zapowiada szeroki scope (≥5 plików do tknięcia, nowa migracja, nowy moduł, refactor cross-cutting, epic review).
- Zadanie wymaga długo trwających operacji (Playwright E2E, `make test` pełnym pakietem, build pipeline) wielokrotnie pod rząd.
- W tej sesji właśnie skończyłeś inny ticket — kolejny tego samego dnia w tej samej sesji rośnie ryzyko, że worklog drugiego nie zdąży.

Gdy zatrzymujesz się: **nic nie zmieniaj w repo i nie ruszaj Jiry**. Tylko ostrzeżenie + rekomendacja `/clear` i ponowne `/start-task $ARGUMENTS`. User decyduje czy mimo to ruszamy bez resetu.

Gdy ryzyka nie ma — przejdź do Kroku 1 bez komentarza.

**1. Cleanup:** `git checkout develop`, usuń lokalne branche scalone do develop (poza `develop`/`master`).

**2. Środowisko + develop:** `docker compose ps` (jeśli nie działa → `make up`), następnie `git fetch && git checkout develop && git pull origin develop && git remote prune origin`.

**3. Fetch Jira** (`getJiraIssue` $ARGUMENTS) — zapamiętaj tytuł, opis, typ.
- `issuetype == "Epik"` → **Ścieżka B**, inaczej → **Ścieżka A**.

---

# A. Zwykłe zadanie

**4.** Status → In Progress. **Zapamiętaj timestamp przejścia** (`startedAt`) — będzie potrzebny w Kroku 13 do worklog.

**5. Branch:** `git checkout -b $ARGUMENTS-{slug}` (slug: tytuł→ang, ≤5 słów, myślniki).

**6. Plan:** krótki nagłówek + tabela "plik → zmiana" + lista nowych testów. Bez `⛔ STOP` — przechodź od razu do Kroku 7, chyba że user wyraźnie zatrzyma. Trzymaj się Domain/Application/Infrastructure zgodnie z CLAUDE.md.

**7. Implementacja:** hexagonal, Doctrine XML, Domain Events, query handlery DBAL.

**8. Walidacja — pełny stack zgodny z CI w `.github/workflows/ci.yml`:**

NIGDY nie pushuj kodu bez przejścia tego stacku w całości. CI uruchamia dokładnie Rector → CS Fixer → PHPStan → PHPUnit i blokuje PR przy pierwszym czerwonym kroku. Lokalna walidacja musi być 1:1 z CI, inaczej "działa u mnie" wraca z CI 5 minut później.

- **Najpierw scoped phpunit:** `phpunit tests/Unit/Module/{TouchedModule}/` (sekundy zamiast 2 minut, szybki feedback).
- `bin/console doctrine:schema:validate` (przez docker exec workaround jeśli `make` pada).
- `bin/console doctrine:migrations:migrate --env=test` (gdy w PR jest migracja).
- **Rector dry-run** — `make rector-dry` lub `vendor/bin/rector process --dry-run`. Jeśli zwraca **cokolwiek** poza `[OK] 0 files would have been changed (dry-run) by Rector`:
  1. Uruchom `vendor/bin/rector process`, zweryfikuj diff (czasem CS Fixer dochodzi do tych samych plików — sprawdź czy nie tworzy "use" sieroty po `RemoveDeadTryCatchRector` itp.).
  2. Powtórz `--dry-run` aż zwróci `[OK]`.
  3. Jeśli zmienione pliki dotyczą ticketu — wrzuć w ten sam commit. Jeśli to ortogonalne pliki (poprzedni epic) — osobny follow-up commit `HMAI-XX - Apply rector cleanups` na tej samej gałęzi (precedens: PR #107).
- **PHP CS Fixer** — `make cs-check` (= `vendor/bin/php-cs-fixer fix --dry-run --diff`). Jeśli pokazuje diff: `vendor/bin/php-cs-fixer fix src/Path/To/File.php` per plik (config odrzuca multi-path argument). Pełny `make cs-check` pokazuje cały dług projektu — wystarczy że Twoje pliki przechodzą.
- **PHPStan level 8** — `make phpstan` lub `vendor/bin/phpstan analyse --memory-limit=1G --no-progress`. Zero nowych entries w baseline.
- **Sanity check na końcu:** pełny `make test` (450+/450+ tests must pass) tuż przed commitem.

Napraw wszystkie błędy przed kontynuowaniem. Push'owanie kodu, który zwali Rector/CS Fixer/PHPStan w CI = wstyd i forced-push do naprawy.

**9. CLAUDE.md:** zaktualizuj jeśli zmiany tego wymagają (nowe moduły/konwencje/Makefile/zamknięte epiki). Bugfix bez nowych konwencji — pomiń.

**10. Code review:** uruchom `/code-review`. Napraw problemy **Krytyczne**; Ważne/Sugestie — user decyduje. Po fixach ponów review.

**11. Commit:** `git diff` → commit `$ARGUMENTS - {Tytuł EN}`.

**12. PR** → [wspólna](#pr). **Confluence** → [opcjonalnie](#confluence). Status → Code Review. **Zapamiętaj timestamp przejścia** (`endedAt`).

**12a. Bug → komentarz z analizą** (tylko gdy `issuetype` to `Błąd`/Bug): dodaj do ticketu komentarz z dokładną analizą problemu i podjętymi działaniami do rozwiązania — patrz [bug-analiza](#bug-analysis). Obowiązkowe dla każdego Buga, niezależnie od scope'u; osobny byt od worklogu (Krok 13).

**13. Rejestr czasu pracy:** różnica `endedAt − startedAt` zaokrąglona **w górę do pełnych 15 min** (zawsze w górę — patrz [worklog](#worklog)). `addWorklogToJiraIssue` z `started=startedAt` (ISO 8601 + tz) i `timeSpent` = wynik (np. `15m`, `30m`, `1h 15m`). **Bez `commentBody`** — czysty rejestr czasu.

**14. Następny kandydat:** zarekomenduj userowi co dalej — patrz [next](#next).

---

# B. Epik

**B1.** Status epiku → W toku. **Zapamiętaj timestamp przejścia** (`startedAt`) — będzie potrzebny w Kroku B14.

**B2. Branch:** jak Krok 5 (slug z tytułu epiku).

**B3. Podzadania:** `searchJiraIssuesUsingJql`: `"Epic Link" = $ARGUMENTS OR parentEpic = $ARGUMENTS`. Jeśli nie wszystkie **Gotowe** — wypisz niezrealizowane z linkami `https://honemanager.atlassian.net/browse/{KEY}` i zakończ.

**B4. Analiza kodu/testów:** `src/Module/`, `tests/Unit/`, `tests/Integration/`. Braki: Domain bez testów jednostkowych, endpointy API bez integracyjnych.

**B5. Propozycja testów** na bazie B4 (klasy + scenariusze) lub "brak braków".

**B6. Confluence:** moduł powinien mieć opis architektury, decyzje techniczne, instrukcje uruchomienia. Wypisz braki.

**B7. Quality commitów:** `git log --oneline` od develop. Oceń opisowość, spójność z formatem projektu, brak WIP/fix/temp.

**B8. Podsumowanie:** tabela podzadań (ID | Tytuł | Status), pokrycie testami, stan dokumentacji, jakość commitów, rekomendacje. ⛔ STOP — czekaj na decyzję. (Tu STOP zostaje — zakres rekomendacji to realna decyzja użytkownika.)

**B9. Implementacja rekomendacji** (po akceptacji): tylko na branchu z B2, nigdy bezpośrednio na develop.

**B10.** Walidacja jak w Kroku 8 (scoped phpunit → Rector dry-run → CS Fixer → PHPStan level 8 → pełny `make test`). Stack 1:1 z CI — Rectora nie pomijać.

**B11. Code review:** uruchom `/code-review`. Napraw **Krytyczne** przed commitem.

**B12. Commit:** `git diff` → commit `$ARGUMENTS - {Tytuł EN} — epic review`.

**B13. PR** → [wspólna](#pr). **Confluence** → [opcjonalnie](#confluence) (przy epiku zazwyczaj TAK — to zwykle aktualizacja dokumentacji modułu). Status epiku → Code Review. **Zapamiętaj timestamp przejścia** (`endedAt`).

**B14. Rejestr czasu pracy:** identycznie jak Krok 13 — różnica `endedAt − startedAt` zaokrąglona w górę do pełnych 15 min, `addWorklogToJiraIssue` na klucz epiku, `started=startedAt`, **bez `commentBody`**. Patrz [worklog](#worklog).

**B15. Następny kandydat:** zarekomenduj userowi co dalej — patrz [next](#next).

---

## PR {#pr}

PR do `develop`, kolejność: 1) GitHub MCP `create_pull_request`, 2) URL fallback `https://github.com/{owner}/{repo}/compare/develop...{branch}?expand=1`.
(Uwaga: `gh` CLI nie jest dostępne w sandbox — nie próbuj.)

**Tytuł:** `$ARGUMENTS - {Pełny tytuł EN}` (epic: dodaj ` — epic review`).

**Opis (template, ≤200 słów):**

```
Closes [HMAI-XX](https://honemanager.atlassian.net/browse/HMAI-XX) — {severity, np. P1/Major}.

{1–2 zdania o problemie — co było źle / czego brakowało}

Fix: {1 zdanie — co zmieniliśmy}

Tests: {N} nowych — {jeden bullet z najważniejszym}

✓ phpstan level 8 / ✓ cs-fixer / ✓ make test ({N}/{N})
```

## Confluence — kiedy aktualizować {#confluence}

**Aktualizuj** tylko gdy zmiana wprowadza:
- (a) Nową konwencję architektoniczną (np. nowy port domenowy, nowy bus, nowy listener pattern)
- (b) Nowy endpoint, kolumnę schemy lub migrację z user-visible impact
- (c) Zmianę publicznego kontraktu (API contract, exception hierarchy, event payload)
- (d) Epic review (krok B13) — zazwyczaj wymaga aktualizacji dokumentacji modułu

**Pomiń** dla:
- Bugfix narrow scope (np. "narrow exception catch", "guard against bad response", "fix off-by-one")
- Refactor bez zmiany zachowania
- Dodanie testów do istniejącej funkcjonalności

Re-upload pełnej strony Confluence to drogi koszt (~10–15 KB tokenów per stronę). Pomijaj świadomie.

Jak aktualizujesz: `searchConfluenceUsingCql` → pasująca strona modułu → `updateConfluencePage` z bumpniętym `versionMessage` zawierającym numer Jira. Nie istnieje → `createConfluencePage` w przestrzeni `H`.

## Bug — komentarz z analizą {#bug-analysis}

Gdy `issuetype` ticketu to **Błąd/Bug**, po wdrożeniu fixu dodaj `addCommentToJiraIssue` (cloudId honemanager, `contentFormat: markdown`) z analizą. Cel: ktoś czytający ticket za pół roku rozumie co i dlaczego się działo, bez czytania diffa. Struktura:

- **Root cause** — konkretna przyczyna (plik:linia, łańcuch zdarzeń), nie objaw. Jeśli diagnoza skręciła, napisz czym różniła się od pierwszej hipotezy.
- **Diagnoza** — jak doszedłeś do root cause (kluczowe obserwacje: status HTTP, wpis z logu, `git blame`, komenda repro). Zwięźle.
- **Fix** — co zmienione i **dlaczego ta opcja**, nie inna. Lista plików z jednozdaniowym „po co".
- **Weryfikacja** — jak potwierdzone (testy, manualny/E2E flow, before/after).
- **PR** — link.

Komentarz to rejestr decyzji, nie changelog — zwięźle, ale nie pomijaj „dlaczego". Odrębny od worklogu (Krok 13, który jest **bez** `commentBody`).

## Brak numeru zadania — tryb sugestii {#suggest}

**Trigger:** `/start-task` wywołane **bez** klucza (`$ARGUMENTS` puste). NIE wykonuj wtedy kroków 0–14 ani ścieżki B — zaproponuj zadania i **zatrzymaj się**, aż user wskaże klucz (sam wpisze `/start-task HMAI-XX` albo poda numer w odpowiedzi). Workflow startuje dopiero z konkretnym kluczem. Nic nie zmieniaj w repo ani w Jirze w tym trybie.

**S1. Ustal scope `fixVersion`** (priorytet malejąco — gdy ustalony, **wszystkie trzy** rekomendacje wybierasz wyłącznie z tej wersji, nigdy spoza):
1. **`fixVersion` omawiany w bieżącej rozmowie** — jeśli wątek dotyczy konkretnej wersji (user ją nazwał lub właśnie o niej rozmawialiście), użyj jej.
2. **`fixVersion` „w trakcie realizacji"** — wykryj automatycznie: najniższy (semver) **niewydany** (`released = false`) fixVersion, który ma jednocześnie ≥1 podzadanie `Gotowe` i ≥1 `statusCategory != Done` (mieszany stan = zaczęty, niedokończony). Gdy żaden nie jest zaczęty — najniższy niewydany fixVersion z otwartymi zadaniami.
   ```
   searchJiraIssuesUsingJql:
     project = HMAI AND statusCategory != Done AND issuetype != Epik
     ORDER BY fixVersion ASC, priority DESC, key ASC
     fields: ["summary","status","priority","fixVersions","issuelinks","description","parent"]
   ```
   (mieszany stan potwierdź osobnym `... AND fixVersion = "{VER}" AND statusCategory = Done` gdy trzeba odróżnić „zaczęty" od „nietknięty").
3. **Brak czytelnego `fixVersion`** — dopiero gdy nie da się ustalić jednej wersji, zapytaj usera (`AskUserQuestion`) o `fixVersion`, albo (jeśli woli) pracuj na całym otwartym backlogu HMAI.

**S2. Pobierz kandydatów** w ustalonym scope: `issuetype != Epik AND statusCategory != Done`. W `fields` koniecznie `issuelinks` (liczenie blokad) i `description` (ocena scope).

**S3. Wylicz trzy wymiary** (jeden ticket może wygrać w >1 — zaznacz pokrycie, nie ukrywaj nakładki):
- **Najszerszy scope** — ticket dotykający najwięcej: liczba endpointów/operacji/punktów akceptacji w opisie, nowa migracja, nowy moduł, zmiana cross-cutting, „fundament" na którym wiszą inne.
- **Najwęższy scope** — odwrotność: pojedyncza akcja/endpoint, brak migracji, izolowana zmiana (to samo kryterium co [#next](#next) po skończonym tasku).
- **Blokuje najwięcej zadań** — policz wychodzące linki typu **blocks** (`issuelinks`, outward „blocks") — ticket z najwyższym licznikiem blokowanych. Gdy brak formalnych linków „blocks", użyj zależności logicznej (fundament typu „lista", od której zależą akcje CRUD/filtr/eksport).

**S4. Prezentacja** — wypisz dokładnie te trzy rekomendacje, każda jako `HMAI-XX — {tytuł}` + 1 zdanie *dlaczego* (dla wymiaru 3 dodaj licznik blokowanych), z jawnie podanym scope (`fixVersion=X.Y.Z`). Dołóż sugestię `/start-task HMAI-XX`. Jeśli któryś wymiar wskazuje na epik (np. najszerszy = cały epik) — zaznacz, że to ścieżka B (epic review) i wymaga domkniętych podzadań. **Stop** — czekaj na wybór usera; gdy poda klucz, rusza normalny workflow od Kroku 0.

## Po zakończeniu zadania — co dalej {#next}

Po wpisaniu worklogu i przejściu do Code Review **zaproponuj userowi następny krok** wg progresywnego algorytmu. Jedna rekomendacja, nie lista.

1. **Pobierz `fixVersion`** z bieżącego zadania (`getJiraIssue` → `fields.fixVersions[0].name`). Brak fixVersion → zakończ bez rekomendacji.

2. **Otwarte podzadania w tym samym fixVersion** (priorytetyzuj najwęższy scope):
   ```
   searchJiraIssuesUsingJql:
     fixVersion = "{VER}" AND statusCategory != Done AND issuetype != Epik
     ORDER BY priority DESC, key ASC
   ```
   Niepusty wynik → `Następny: /start-task HMAI-XX` z krótkim opisem dlaczego ten ticket jest najwęższy z pozostałych (1 zdanie).

3. **Otwarte epiki w tym samym fixVersion** (gdy podzadania puste — czas na epic review):
   ```
   searchJiraIssuesUsingJql:
     fixVersion = "{VER}" AND statusCategory != Done AND issuetype = Epik
   ```
   Niepusty wynik → `Pozostały tylko epiki — następny: /start-task HMAI-XX (epic review)`.

4. **Wszystko zamknięte w fixVersion** → zaproponuj wydanie:
   ```
   Wszystkie ticket-y i epiki fixVersion={VER} są Gotowe.
   Czas na wydanie: /release-version {VER}
   ```

Jeśli user pominął rekomendację i podał własne `/start-task HMAI-YY` — wykonuj jego polecenie, rekomendacja była tylko sugestią.

## Worklog — rejestr czasu {#worklog}

**Reguła zaokrąglania:** różnica `endedAt − startedAt` zawsze w górę do pełnych 15 min. Przykłady:
- 2m32s → 15m
- 15m00s → 15m (idealnie na granicy = zostaje 15m, ale jeśli choćby 15m01s → 30m)
- 39m56s → 45m
- 1h 7m → 1h 15m
- 2h 30m 01s → 2h 45m

**Wywołanie:** `addWorklogToJiraIssue(cloudId, issueKey, timeSpent=…, started=ISO_8601)`. **Bez `commentBody`** — czysty rejestr czasu, żadnych adnotacji.

Cloud ID: `0579d404-bf72-42dd-a5af-975a36fbb84d` (honemanager).

Jeśli zadanie zostało wcześniej wstrzymane i wznawiane (multiple In Progress↔ inne stany), zsumuj wszystkie odcinki `In Progress` z changelogu (`getJiraIssue expand=changelog`) — tylko czas faktycznie spędzony w stanie In Progress.

## Token efficiency {#tokens}

Reguły dotyczą wszystkich kroków workflow — zwłaszcza Kroku 4 (analiza), 7 (implementacja), 8/B10 (walidacja) oraz B4 (audit kodu epica). Cel: nie czytać/nie wypluwać do kontekstu rzeczy, które nie wpłyną na decyzje.

- **Read z `offset`/`limit` zamiast full file** — gdy z `Grep` masz konkretny `file:line`, czytaj tylko sąsiedztwo (np. `Read file_path=… offset=120 limit=80`). Pełny `Read` tylko gdy plik jest naprawdę krótki (<200 linii) albo musisz zobaczyć całość (np. nowy plik testowy do dopisania na końcu).
- **Bundle Edit calls per plik** — jeśli zmieniasz 3 miejsca w tym samym pliku, wyślij 3 wywołania `Edit` w **jednym message bloku** (parallel). Nie czekaj na wynik każdego oddzielnie. Wyjątek: gdy drugi `Edit` zależy od kontekstu po pierwszym (rzadkie).
- **`make test` — tnij output** — pełny PHPUnit z `--testdox` to ~3-5 KB tokenów. Filtruj:
  ```
  make test 2>&1 | tail -20
  ```
  lub jeszcze ciaśniej:
  ```
  make test 2>&1 | grep -E "OK|FAIL|Tests:|Errors:|Failures:" | tail -10
  ```
  Pełny output potrzebny tylko gdy widzisz `FAILED` i musisz znaleźć stack trace.
- **Newman/Playwright — `run_in_background=true`** — Newman trwa 30-60s i wypluwa 5-10 KB outputu request-by-request. Uruchamiaj w tle:
  ```
  Bash run_in_background=true command="make test-newman"
  ```
  Potem `BashOutput` zwraca finalne podsumowanie. Dotyczy też `make test-e2e` (Playwright).
- **JQL bez `fields` zwraca 250+ KB** — przy `searchJiraIssuesUsingJql` zawsze podawaj `fields: ["summary", "status"]` (lub minimum konieczne). Pełna lista pól jest wymagana tylko gdy potrzebujesz changelogu/worklogu.
- **Subagent do audytu epica (B4)** — zamiast 10× Read w main context, użyj `Agent subagent_type=Explore` z konkretnym pytaniem ("znajdź moduły bez testów Domain unit"). Wracaj z summary, nie z dumpem plików.
