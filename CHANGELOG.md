# Changelog

Wszystkie znaczące zmiany w projekcie AIHomeManager dokumentowane w tym pliku.

Format oparty na [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), wersjonowanie wg [SemVer](https://semver.org/lang/pl/).

## [1.8.0] — 2026-05-21

Domknięcie epica **HMAI-129** (API hardening — input validation, error contracts, exception handling) — 8/8 podzadań (HMAI-43, 57, 65, 66, 67, 68, 79, 109). Najszerszy zakres: nowy globalny `ApiExceptionListener` (HMAI-79) konwertujący uncaught throwables na `^/api/*` na JSON z generycznym 500, nowy PATCH endpoint `/api/series/.../rating` (HMAI-43), spójna walidacja per moduł (Music limit, Series/Episode title length, Books pages_read/date), CSRF decision doc dla stateless+API key (HMAI-57). 495/495 PHP (+42 vs 1.7.1) + 5/5 Playwright + 28/28 Newman. PHPStan level 8 clean (zero new baseline entries).

### Added

- **`App\EventListener\ApiExceptionListener`** (HMAI-79) — `kernel.exception` priority 64, scoped do `^/api/*`. Unwrap `HandlerFailedException` (Messenger), preserve `HttpExceptionInterface` status/message, dla pozostałych throwables 500 z generycznym `Internal server error.` (oryginalny message tylko w logu). Non-API paths przechodzą bez zmian — Twig frontend zachowuje swoje strony błędu. 5 unit + 2 integration testów.
- **PATCH `/api/series/{seriesId}/seasons/{seasonId}/episodes/{episodeId}/rating`** (HMAI-43) — `SeriesController::rateEpisode()` dispatchuje `AddEpisodeRating` command. Pre-validation `is_int($rating) && 1..10` zwraca 422 przed wywołaniem aggregate (omija `HandlerFailedException` unwrap noise). 204 No Content przy sukcesie. 4 integration testy (happy path + 422 invalid rating + 404 series/episode not found).
- **`docs/HMAI-57.md`** — CSRF decision dokument: dlaczego `^/api/*` świadomie nie używa `#[IsCsrfTokenValid]` (firewall `stateless: true` + autoryzacja przez `X-API-Key` header — przeglądarka nie ustawia custom headerów cross-origin). Plan migracji jeśli wprowadzimy stateful session/cookie auth.
- **`tests/Integration/Security/ApiKeyAuthCsrfTest.php`** (HMAI-57) — 4 regression tests: POST/PUT/DELETE z `PHPSESSID` cookie ale bez `X-API-Key` → 401, plus stateless invariant (no `Set-Cookie` w response).
- **`Articles\Domain\Exception\InvalidArticleData`** (HMAI-109) — nowy exception markerujący dane od usera w `CreateArticle` aggregate. Pozwala kontrolerowi rozróżnić "twoje dane są złe" (mapowany na generic 422) od "coś się zepsuło" (500 z generic message).

### Changed

- **`MusicController`** (HMAI-65): nowe stałe `MAX_TOP_ALBUMS_LIMIT=1000`, `MAX_COMPARISON_LIMIT=200`, `DEFAULT_LIMIT=50`. Private helper `parseLimit(?string $raw, int $max): ?int` z `ctype_digit` — odrzuca floats/scientific notation/negatywne/zero przez 422 zamiast cichego clampowania do 1 (`max(1, min(MAX, (int) $raw))` było buggy). Komunikat: `Field "limit" must be a positive integer between 1 and {max}.`
- **`SeriesController::create()` + `SeriesController::addEpisode()`** (HMAI-66): `mb_strlen($title) > 255` → 422 z komunikatem `Title must be at most 255 characters.`. `mb_strlen` (nie `strlen`) liczy znaki, nie bajty — 255-znakowy emoji tytuł mieści się w `VARCHAR(255) utf8mb4`.
- **`BooksController` log reading session** (HMAI-67): `pages_read` walidowane jako `is_int($value) && $value > 0` — odrzuca floaty (`1.5`), stringi numeryczne, ujemne, zero przez 422. Pre-validation przed dispatchem `LogReadingSession`.
- **`BooksController` log reading session** (HMAI-68): pole `date` walidowane przez `DateTimeImmutable::createFromFormat('!Y-m-d', $raw)` + round-trip equality (`$dt->format('Y-m-d') === $raw`) — wyłapuje `2026-02-30`, `2026/05/21`, ISO 8601 z czasem. 422 z komunikatem `Field "date" must be a date in Y-m-d format.`
- **`ArticlesController::create()`** (HMAI-109): `InvalidArgumentException` z aggregate już nie leakuje raw message w response. Zamiast tego logger warning + generyczny `'error' => 'Invalid article data.'`. Domain exception message wraca do logów (Graylog), nie do klienta.
- **CLAUDE.md**: dodana sekcja "API exception listener (HMAI-79)" (kontrakty 4xx vs 5xx, HandlerFailedException unwrap pattern). Status epica HMAI-129 → epik zamknięty (8/8). "Wydania" → 1.8.0.

### Coverage

- **495 PHP tests** passing (vs 453 at 1.7.1) — +42 nowych: 6 ApiExceptionListener (5 unit + 2 integration), 4 PATCH episode rating, 4 CSRF regression, 10 Music limit validation (2× 5-case data provider), 4 Series/Episode title length, 6 Books pages_read int, 5 Books date Y-m-d format, 3 Articles generic error.
- **5 Playwright** (Series desktop + mobile — bez zmian).
- **28 Newman** requests / 42 assertions (bez zmian — nowy PATCH endpoint nie wpięty w `tests-e2e/postman/AIHomeManager.postman_collection.json`; osobny follow-up HMAI-33 deferred do 1.9.x).
- PHPStan level 8 clean, baseline bez nowych entries. CS Fixer + Rector dry-run green.

### Documentation

- **Confluence id 46891009** "Dokumentacja API" — v4. Dodane: sekcja CSRF decision (HMAI-57), sekcja Global exception handling (HMAI-79, kontrakty 4xx vs generic 500), PATCH rating endpoint w Series, walidacje per moduł (HMAI-65/66/67/68/109), 500 status code row.
- **Confluence id 49643522** "Series — Warstwa HTTP REST API Controller" — v2. PATCH rating endpoint, tabela walidacji (`mb_strlen`, `is_int 1..10`), pre-validation pattern note, nowe test scenarios.
- **CHANGELOG.md**: ta sekcja.
- **CLAUDE.md**: epik HMAI-129 → zamknięty 2026-05-20; "Wydania" → 1.8.0.

### Migration

Brak. Czysto warstwa kontrolerów + kernel.exception listener — brak nowych ENV, brak DB migrations, brak Redis schema changes. Klienci API, którzy wcześniej polegali na `getMessage()` z 500 (n.b. tego nie powinni byli robić), zobaczą teraz `Internal server error.` zamiast oryginalnego komunikatu — sprawdzaj logi (Graylog kanał default) by zobaczyć przyczynę.

### Closed Jira

| ID | Tytuł | PR |
|---|---|---|
| [HMAI-67](https://honemanager.atlassian.net/browse/HMAI-67) | Validate `pages_read` is a positive integer in log reading session | [#119](https://github.com/zlotylesk/AIHomeManager/pull/119) |
| [HMAI-68](https://honemanager.atlassian.net/browse/HMAI-68) | Validate reading session date format as `Y-m-d` | [#120](https://github.com/zlotylesk/AIHomeManager/pull/120) |
| [HMAI-65](https://honemanager.atlassian.net/browse/HMAI-65) | Validate Music limit query param as positive integer | [#121](https://github.com/zlotylesk/AIHomeManager/pull/121) |
| [HMAI-66](https://honemanager.atlassian.net/browse/HMAI-66) | Validate series and episode title length up to 255 characters | [#122](https://github.com/zlotylesk/AIHomeManager/pull/122) |
| [HMAI-109](https://honemanager.atlassian.net/browse/HMAI-109) | Replace leaked exception message with generic article validation error | [#123](https://github.com/zlotylesk/AIHomeManager/pull/123) |
| [HMAI-79](https://honemanager.atlassian.net/browse/HMAI-79) | Add global API exception listener returning JSON for `^/api/*` | [#124](https://github.com/zlotylesk/AIHomeManager/pull/124) |
| [HMAI-43](https://honemanager.atlassian.net/browse/HMAI-43) | Add PATCH episode rating endpoint wired to existing aggregate | [#125](https://github.com/zlotylesk/AIHomeManager/pull/125) |
| [HMAI-57](https://honemanager.atlassian.net/browse/HMAI-57) | Document stateless API key decision and add CSRF regression tests | [#126](https://github.com/zlotylesk/AIHomeManager/pull/126) |
| [HMAI-129](https://honemanager.atlassian.net/browse/HMAI-129) | API hardening (epic close) | [#127](https://github.com/zlotylesk/AIHomeManager/pull/127) |

### Carried forward

Brak — fixVersion 1.8.0 100% Done. Postman/Newman update dla nowego PATCH endpointu = HMAI-33 follow-up (deferred). Frontend Series UI button dla inline rating edit = osobny follow-up (deferred).

## [1.7.1] — 2026-05-19

Domknięcie epica **HMAI-128** (Frontend hardening — JS quality, CSP/SRI, build pipeline) — 12/12 podzadań. Druga partia po batchu 1.7.0: HMAI-41 (Webpack Encore + Stimulus pilot dla Series UI) + epic review (wpięcie `window.apiCall` w pozostałe 4 moduły, regression tests dla CSP/Encore manifest, full rewrite Confluence patterns id 52297730 v2). 453/453 PHP (+2 vs 1.7.0 — regression guards) + 5/5 Playwright + 28/28 Newman. PHPStan level 8 clean (zero new baseline entries).

### Added

- **Webpack Encore + Stimulus** dla Series UI (HMAI-41):
    - `app/webpack.config.js` — Encore config z entry `app`, splitEntryChunks, Stimulus bridge, versioning prod-only.
    - `app/assets/app.js` (główny entry), `bootstrap.js` (Stimulus auto-discovery), `util.js` (ES module port), `controllers/series_controller.js` (Stimulus controller — port z `public/js/series.js`), `styles/app.css`.
    - `aihm-node-1` (`node:24-alpine`) jako long-running sidecar (`tail -f /dev/null`) dla `make assets*` shell exec.
    - Twig: `{{ encore_entry_link_tags('app') }}` + `{{ encore_entry_script_tags('app') }}` zamiast manual `<link>`/`<script>`.
    - CI: `actions/setup-node@v4` + `npm ci` + `npm run build` przed PHPUnit (entry tags wymagają `public/build/entrypoints.json`).
    - Makefile: `make assets` (dev), `make assets-watch`, `make assets-prod`, `make node-install`.
- **2 regression tests** w `FrontendControllerTest` (HMAI-128 epic review):
    - `testBaseLayoutContainsCSPMetaTag` — guards na meta CSP (HMAI-100 DoD).
    - `testBaseLayoutLoadsEncoreEntryAssets` — guards na `<script src="/build/...">` + `<link href="/build/...">` (HMAI-41 DoD).

### Changed

- **`window.apiCall` wpięty w 4 modułach** (HMAI-128 epic review, DoD: "zostają wpięcia w books/articles/tasks/music"):
    - `articles.js`: `loadArticles` (2× via `Promise.allSettled`), `markAsRead` (surfaces `err.message` z payload).
    - `books.js`: `loadBooks`, add-book submit, reading-session submit.
    - `music.js`: `loadMusic` (3× via `Promise.allSettled`, `readSection` zsynchronizowany).
    - `tasks.js`: `loadReport`.
    - Boilerplate `if (!res.ok) { const err = await res.json(); showError(err.error || ...); }` zastąpiony przez `try/catch` z `err.message` z helpera. Net -18 LOC w 4 plikach JS.
- **`base.html.twig`**: pre-existing manualne `<link>`/`<script>` zastąpione przez `encore_entry_*` helpery (HMAI-41).
- **`templates/series/index.html.twig`**: `data-controller="series"` na root + usunięcie `<script src="/js/series.js">` — kontrola przez Stimulus auto-discovery (HMAI-41).
- **CLAUDE.md**: sekcja "Frontend" — dual track (Encore+Stimulus dla Series; vanilla JS dla pozostałych). Nowa sekcja "Webpack Encore (HMAI-41)" z opisem plików + komend Makefile. Status epica HMAI-128 → epik zamknięty (12/12).
- **`docker-compose.yml`**: dodany serwis `node` (long-running, mount `./app`).
- **`.gitignore`**: `public/build/` + `node_modules/`.

### Coverage

- **453 PHP tests** passing (vs 451 at 1.7.0) — +2 regression guards w FrontendControllerTest.
- **5 Playwright** (Series desktop + mobile — bez zmian).
- **28 Newman** requests / 42 assertions (bez zmian).

### Documentation

- **Confluence id 52297730** "Frontend Web — architektura i decyzje techniczne" — full rewrite v2 po zamknięciu epica HMAI-128. Dual-track architektura, sekcja "Frontend hardening patterns" (CSP, safeUrl, Promise.allSettled, event delegation, util.js helpers, URLSearchParams), Webpack Encore docs, aktualizacja sekcji Testy, historia zmian.
- **CHANGELOG.md**: ta sekcja.
- **CLAUDE.md**: epik HMAI-128 → zamknięty 2026-05-19; "Wydania" → 1.7.1.

### Migration

Brak. Build assets w CI ustawione w 1.7.1 — deploy wymaga `npm ci && npm run build` przed PHPUnit (już skonfigurowane w `.github/workflows/ci.yml`).

### Closed Jira

| ID | Tytuł | PR |
|---|---|---|
| [HMAI-41](https://honemanager.atlassian.net/browse/HMAI-41) | Webpack Encore + Stimulus dla Series UI | [#116](https://github.com/zlotylesk/AIHomeManager/pull/116) |
| [HMAI-128](https://honemanager.atlassian.net/browse/HMAI-128) | Frontend hardening — JS quality, CSP/SRI, build pipeline (epic close) | [#117](https://github.com/zlotylesk/AIHomeManager/pull/117) |

### Carried forward

Brak — fixVersion 1.7.1 100% Done.

## [1.7.0] — 2026-05-18

Pierwsza partia epica **HMAI-128** (Frontend hardening — JS quality). Dziewięć zadań pokrywających minor/major findings w warstwie JS: shared `util.js` (timeout + `safeUrl` + `apiCall`), CSP meta tag, `URLSearchParams`, walidacja protokołu URL przed renderowaniem (XSS), `Promise.allSettled` zamiast `Promise.all`, event delegation zamiast per-element bindowania. Wszystkie 9 podzadań zamknięte (HMAI-69, 70, 71, 72, 77, 78, 98, 100, 115). 451/451 PHP + 5/5 Playwright + 28/28 Newman — bez zmian liczby testów (czysto frontendowa zmiana). PHPStan level 8 clean (bez nowych entries w baseline). Pozostałe podzadania HMAI-128 (HMAI-41 Webpack Encore + Stimulus, oraz sam epic review) przesunięte do **1.7.1**.

### Added

- **`app/public/js/util.js`** — wspólny helper ładowany przez wszystkie szablony modułów (`articles/books/music/tasks/index.html.twig`):
    - `window.TOAST_TIMEOUT_MS = 5000` — globalna stała zastępująca rozjazd `6000ms` (tasks.js) / `5000ms` (series.js).
    - `window.safeUrl(url)` — sprawdza `new URL(url, document.baseURI).protocol` przeciw `http:`/`https:` i zwraca `null` dla `javascript:`, `data:`, `vbscript:`, itp. XSS guard przed renderowaniem `<a href>` / `<img src>`.
    - `window.apiCall(url, options)` — wrapper na `fetch` z normalizacją błędów (`error.status`/`error.body` z payload `{error: "..."}`) i obsługą 204. [HMAI-98]
- **Content-Security-Policy meta tag** w `base.html.twig` — `default-src 'self'`, `script-src 'self'` (+ `https://cdn.jsdelivr.net` tylko w dev), `style-src 'self' 'unsafe-inline'`, `img-src 'self' data: https:`, `connect-src 'self'`, `font-src 'self'`, `base-uri 'self'`, `object-src 'none'`. XSS injection nie wyleci do dowolnej domeny, eval zablokowany. [HMAI-100]

### Changed

- **`articles.js`**: `<a href>` przechodzi przez `window.safeUrl()` (HMAI-71, XSS guard przed `javascript:`). `Promise.allSettled([list, today])` zamiast `Promise.all` — częściowe 500 z `/api/articles/today` nie zabija renderowania listy (HMAI-69). Event delegation `document.body` matching `.btn-mark-read` zamiast per-element binding w `renderList` (HMAI-77).
- **`books.js`**: `coverUrl` przez `window.safeUrl()` (HMAI-72, XSS guard analogiczny do articles). Event delegation `.btn-log-session` (HMAI-78).
- **`music.js`**: `Promise.allSettled([topAlbums, collection, comparison])` zamiast `Promise.all` — awaria Last.fm nie blokuje panelu Discogs collection i odwrotnie. Per-section `readSection()` helper raportuje błędy granularnie. (HMAI-70)
- **`tasks.js`**: `URLSearchParams({from, to})` zamiast string concatenation `?from=${from}&to=${to}` — escapowanie znaków specjalnych. (HMAI-115)
- **`music.js`**: `URLSearchParams({period, limit})` dla `/top-albums` i `/comparison` (HMAI-115).
- **`books.js`**: `URLSearchParams({status})` dla `/api/books?status=...` (HMAI-115).
- Magic timeouts w `tasks.js`/`series.js` → `window.TOAST_TIMEOUT_MS`. (HMAI-98)
- **CLAUDE.md**: brak zmian konwencji architektonicznych — wszystkie zmiany w warstwie JS bez nowych wzorców backendowych.

### Coverage

- 451/451 PHP (bez zmian — czysto frontendowy release; istniejące unit/integration nie miały być modyfikowane).
- 5 Playwright E2E + 28 Newman REST — bez zmian (selektory testów nie były dotknięte).
- PHPStan level 8 clean, baseline bez nowych entries.

### Closed Jira

| Klucz | Tytuł | PR |
|---|---|---|
| [HMAI-98](https://honemanager.atlassian.net/browse/HMAI-98) | Niespójne magic timeouts w JS — extract `TOAST_TIMEOUT_MS` | #106 |
| [HMAI-115](https://honemanager.atlassian.net/browse/HMAI-115) | `URLSearchParams` w `tasks.js`/`music.js`/`books.js` | #107 |
| [HMAI-100](https://honemanager.atlassian.net/browse/HMAI-100) | CSP meta tag w `base.html.twig` | #108 |
| [HMAI-72](https://honemanager.atlassian.net/browse/HMAI-72) | `books.js` walidacja protokołu `coverUrl` | #109 |
| [HMAI-71](https://honemanager.atlassian.net/browse/HMAI-71) | `articles.js` walidacja protokołu URL przed `href` | #110 |
| [HMAI-69](https://honemanager.atlassian.net/browse/HMAI-69) | `articles.js` `Promise.allSettled` | #111 |
| [HMAI-70](https://honemanager.atlassian.net/browse/HMAI-70) | `music.js` `Promise.allSettled` | #112 |
| [HMAI-77](https://honemanager.atlassian.net/browse/HMAI-77) | `articles.js` event delegation | #113 |
| [HMAI-78](https://honemanager.atlassian.net/browse/HMAI-78) | `books.js` event delegation | #114 |

### Migration

Brak. Czysto frontendowy release — żadnych nowych ENV, żadnych migracji DB, żadnej re-auth.

### Carried forward to 1.7.1

- [HMAI-41](https://honemanager.atlassian.net/browse/HMAI-41) — Webpack Encore + Stimulus (build pipeline; szersza zmiana ergonomiki frontu).
- [HMAI-128](https://honemanager.atlassian.net/browse/HMAI-128) — epic review (dopełnienie po landowaniu HMAI-41).

## [1.6.0] — 2026-05-17

Domknięcie epica **HMAI-126** (Operability & observability). Sześć zadań pokrywających operowanie systemem w produkcji: healthcheck, harmonogram zadań cyklicznych, fixtures dla łatwego startu, audit log OAuth, metryki latencji external API, weryfikacja `messenger_worker`. Wszystkie 6 podzadań zamknięte (HMAI-133, 107, 112, 37, 39, 35). 451/451 PHP + 5/5 Playwright + 28/28 Newman — wszystkie zielone. PHPStan level 8 clean (bez nowych entries w baseline).

### Added

- **`GET /api/health`** — publiczny readiness probe (bypass firewall w `ApiKeyAuthenticator::supports`), trzy probe'y: MySQL `SELECT 1`, Redis `PING`, RabbitMQ TCP socket do hosta z `MESSENGER_TRANSPORT_DSN`. 200 + `{"status":"healthy", "components":{...}, "timestamp":...}` lub 503 + `"unhealthy"`. Docker healthcheck na `nginx` (`wget --spider`) jako end-to-end stack probe. Tests: 5 unit `HealthChecker` + 2 unit `HealthController` + 1 integration without API key. [HMAI-37]
- **`auth` Monolog channel + OAuth audit log** — `GoogleAuthController` i `DiscogsAuthController` używają `monolog.logger.auth` przez `#[Autowire]`. `info('OAuth authorize initiated' | 'OAuth callback success')` + `warning('OAuth callback failed', ['reason' => 'invalid_state' | 'missing_code' | 'missing_params' | 'token_exchange' | 'empty_token'])`. Dev/prod: `auth_gelf` handler (info, Graylog); prod również `auth_stream` (stderr JSON); test: `auth_null`. Tests: 10 unit (5 per provider). [HMAI-107]
- **API duration metrics** — `LastFmApiClient` i `DiscogsApiClient` emitują `info('External API call', ['provider', 'endpoint', 'duration_ms', 'status', 'error?'])` na kanale `music` dla każdego HTTP callu (success + failure tagged `error=transport_error | client_error | transport_or_server_error`). Logger via `#[Autowire(service: 'monolog.logger.music')]` z `NullLogger` default dla backward compat z testami. Tests: 4 unit. [HMAI-112]
- **Doctrine Fixtures bundle (dev+test)** — `doctrine/doctrine-fixtures-bundle` + 4 klasy: `SeriesFixtures` (3 × 2 sezony × 5 ocenianych odcinków), `BookFixtures` (5 książek pokrywających każdy `BookStatus`), `ArticleFixtures` (10 artykułów / 4 kategorie / 3 read), `TaskFixtures` (4 taski today+yesterday). Routed przez domain repositories — invariants agregatów respektowane. `make fixtures` target + `app/fixtures/sample-articles.csv` dla CSV import path. Tests: 4 integration. [HMAI-39]
- **Symfony Scheduler + cron-expression** — `src/Schedule.php` (`#[AsSchedule]`) z 3 zadaniami:
    - `0 0 * * *` — `ResetDailyArticleCache` (Articles): `DEL articles:today` Redis + `DELETE article_daily_picks WHERE picked_date < CURDATE() - INTERVAL 7 DAY`.
    - `0 8 * * 1` — `GenerateWeeklyActivityReport` (App\Application\Scheduled): DBAL counts z ostatnich 7 dni (`read_articles`, `pages_read`, `completed_tasks`) + `rated_episodes_total` → log `scheduled_task=weekly_report`.
    - `0 */6 * * *` — `RefreshDiscogsCollection` (Music) per `DISCOGS_USERNAME`: pre-warm cache przed 6h TTL.

    Nowy serwis docker `scheduler_worker` (`messenger:consume scheduler_default`). Stateful na `cache.app` (filesystem, host mount), `processOnlyLastMissedRun(true)` — restart workera odpala max 1 zaległe okno. Tests: 4 unit (2 per handler). [HMAI-35]

### Changed

- **CLAUDE.md**: nowa sekcja "Health endpoint (HMAI-37)", "Symfony Scheduler (HMAI-35)", `scheduler_worker` row w tabeli Infrastruktura, `make fixtures` w Komendach, `ApiKeyAuthenticator::supports` skip `/api/health` notatka, FixturesLoadTest w sekcji Testy.
- **`ApiKeyAuthenticator::supports()`** zwraca `false` dla dokładnie `/api/health` — bez tej zmiany firewall `^/api/*` blokowałby healthcheck na 401. [HMAI-37]

### Verified (no code change)

- **HMAI-133** — `symfony/amqp-messenger:8.0.*` już w `composer.json/lock` od `f52e33dd` (HMAI-42 Playwright Series E2E, 2026-05-16). `docker compose ps` pokazuje `messenger_worker` Up; `[OK] Consuming messages from transport "async".` Ticket zamknięty bez nowego commitu — fix już w produkcji.

### Upgrade notes (manual steps)

1. **`composer install`** — nowe paczki: `symfony/scheduler`, `dragonmantank/cron-expression`, `doctrine/doctrine-fixtures-bundle` (dev).
2. **`docker compose up -d`** — pełny rebuild zwiększa stos o `scheduler_worker` (ten sam image co `messenger_worker`).
3. **Graylog wiring** — jeśli profil monitoring działa, kanały `auth` i `music` (już istnieje) zaczną emitować nowe info-level events. Filtry/saved searches do utworzenia:
   - `scheduled_task:*` — widok cyklicznych zadań.
   - `provider:lastfm OR provider:discogs` — latency dashboard (`duration_ms` field).
   - `provider:google OR provider:discogs AND reason:*` — failed OAuth callbacks.
4. **Live healthcheck:** `curl http://localhost:8080/api/health` powinien zwrócić 200 z `"status":"healthy"`. Docker `nginx` zacznie reportować healthy po `start_period=30s`.
5. **Scheduler walidacja:** `make shell` → `php bin/console debug:scheduler` powinno pokazać 3 triggers.

### Coverage

- 451 PHP testy (z 421 baseline → +30: HMAI-37 (+8), HMAI-39 (+4), HMAI-35 (+4), HMAI-107 (+10), HMAI-112 (+4)).
- 5 Playwright E2E + 28 Newman REST.
- PHPStan level 8 clean, CS Fixer + Rector clean.

### Closed Jira

| Klucz | Tytuł | PR |
|---|---|---|
| [HMAI-133](https://honemanager.atlassian.net/browse/HMAI-133) | messenger_worker crashloop — brak symfony/amqp-messenger | — (już w `f52e33dd`) |
| [HMAI-107](https://honemanager.atlassian.net/browse/HMAI-107) | OAuth audit log | #100 |
| [HMAI-112](https://honemanager.atlassian.net/browse/HMAI-112) | API duration metrics | #101 |
| [HMAI-37](https://honemanager.atlassian.net/browse/HMAI-37) | /api/health endpoint | #102 |
| [HMAI-39](https://honemanager.atlassian.net/browse/HMAI-39) | Doctrine Fixtures | #103 |
| [HMAI-35](https://honemanager.atlassian.net/browse/HMAI-35) | Symfony Scheduler | #104 |
| [HMAI-126](https://honemanager.atlassian.net/browse/HMAI-126) | Operability & observability — **epic zamknięty** | (this commit) |

## [1.5.0] — 2026-05-17

Domknięcie epica **HMAI-124** (Persistence & DB integrity) — kompletny przegląd warstwy persystencji: N+1 queries, brakujące indeksy FK, race conditions, transakcyjność wielokrokowych zapisów, DBAL parameter hygiene, fragile row→DTO mapping. Wszystkie 9 podzadań zamknięte (HMAI-60, 61, 75, 86, 88, 92, 102, 103, 122). Dodatkowo siedem mniejszych fixów `ai_code_review` z parent epików HMAI-131 (DDD purity) i HMAI-128 (Frontend hardening) trafiło tutaj okolicznościowo (HMAI-89, 91, 101, 108, 111, 117, 118). 421/421 PHP tests + 5/5 Playwright + 28/28 Newman — wszystkie zielone. PHPStan level 8 baseline zregenerowany (-24 stale entries z naprawionych PR-ów).

### Added

- **Bulk IN-query w `DoctrineSeriesRepository`** — `attachSeasonsAndEpisodes()` ładuje seasons i episodes po dwóch batchowanych `WHERE …Id IN (…)` zapytaniach zamiast pętli per agregat. `findById` i `findAll` używają stałej liczby 3 zapytań niezależnie od liczby seriali/sezonów (było `1 + N + N*M`). ORM-managed state zachowany — `save()` działa bez zmian. [HMAI-60]
- **Lookup indexes w XML mapping** — `<indexes>` blok w `Episode.orm.xml` (`idx_episode_season_id`), `Series.orm.xml` (`idx_series_created_at`), `Article.orm.xml` (`idx_article_added_at`). Migracja `Version20260517000001`. Eliminuje full scan na rosnących tabelach w hot-path JOIN/ORDER BY. [HMAI-61]
- **`SeriesRowHydrator` service** — wspólny mapping rows → `SeriesDetailDTO` dla `GetAllSeriesHandler` i `GetSeriesDetailHandler`. Test `SeriesRowHydratorTest` (3 cases: empty, LEFT JOIN null seasons, multi-series grouping). [HMAI-103]
- **`ArticleDTO::fromRow` PHPDoc shape + `requireString()`** — `@param array{...}` deklaracja struktury wiersza + walidacja required columns (`id`, `title`, `url`, `added_at`) z `RuntimeException` zamiast cichych nulli. Test `ArticleDTOTest` (3 cases: full mapping, nullable omission, missing required). [HMAI-102]
- **`BookNotFoundException`** — typed domain exception zamiast `str_contains($e->getMessage(), 'not found')` w `BooksController`. Rzucany przez `LogReadingSessionHandler`, `RemoveBookHandler`, `UpdateBookHandler`. [HMAI-108]
- **`window.apiCall(url, options)` helper w `public/js/util.js`** — wrapper nad `fetch` rzucający typed Error z `.status` i `.body` zamiast cryptic JSON.parse error przy 500 z HTML response. Wpięte w `series.js` GET fetches; `templates/series/index.html.twig` ładuje `util.js`. [HMAI-118]
- **`GetArticleOfTheDayHandlerTest`** — 5 integration cases pokrywających `ArrayParameterType::STRING` named binding (regression guard HMAI-88), preferred-category branch, cache hit short-circuit, fallback i empty state. Domyka jedyną lukę test coverage HMAI-124 odkrytą w epic review. [HMAI-124 epic review]
- **Confluence section 9 w "Doctrine ORM i XML Mapping"** (page id 49119233 → v3) — 9 patternów persistence: bulk IN-query, transactional save, ArrayParameterType, FK indexes, single-query conditional aggregate, row hydrator service, `DTO::fromRow` walidacja, cache pool hygiene, query DoD. [HMAI-124 epic review]

### Changed

- **`DiscogsTokenRepository::save` jest transakcyjny** — `Connection::transactional(fn (Connection $c) => …)` wokół `DELETE` + `INSERT`. Wyklucza okno race w którym między DELETE a INSERT inny request widział pustą tabelę i traktował usera jako wylogowanego. [HMAI-92]
- **`EpisodeRatedHandler` single AVG query** — sezon + serial-wide avg liczone jednym SELECTem z `AVG(CASE WHEN …)` zamiast dwóch osobnych zapytań. [HMAI-86]
- **`GetArticleOfTheDayHandler` używa `ArrayParameterType::STRING`** dla `excludeIds` z named binding (`:excludeIds`) zamiast mieszać positional `?` z named. Bez tego dwa array params w jednym query nie wiążą się poprawnie. [HMAI-88]
- **`GetAllSeriesHandler` i `GetSeriesDetailHandler`** delegują mapping do `SeriesRowHydrator` — query handlery zostają cienkie (SELECT + delegate). [HMAI-103]
- **`AddBookHandler` fail-fast na pustym tytule** — `$title ?? ''` fallback zastąpiony przez `if ('' === trim($title)) throw new InvalidArgumentException(...)`. Książka z pustym tytułem nie wejdzie do bazy. [HMAI-91]
- **`Series::rateEpisode` exception message ma id series** — dotąd `'Season "%s" not found.'`, teraz `'Season "%s" not found in series "%s".'` jak `addEpisode`. Spójność w logach. [HMAI-89]
- **`ISBN` constructor — local var rename** — `$normalized` → `$normalizedValue` (parameter shadowed property o tej samej nazwie). Brak zmiany semantycznej, czytelność. [HMAI-111]
- **`GetTimeReportHandler` PHPDoc** zwężone do `list<TaskTimeDTO>` z `@var list<array{...}>` shape annotation na fetchowane wiersze — PHPStan teraz typecheckuje rezultat. [HMAI-117]
- **Hot-reload `<script>` gated `{% if app.environment == 'dev' %}`** w `templates/base.html.twig` — `idiomorph` i `frankenphp-hot-reload` z CDN nie idą do prod responses (eliminuje wektor wstrzyknięcia przez kompromitację CDN). [HMAI-101]
- **`cache.yaml`: pool `series.ratings.cache` usunięty** — pool był deklarowany ale `EpisodeRatedHandler` iniektuje raw `\Redis` (nie `CacheItemPoolInterface`). Dead config czyszczony. CLAUDE.md infrastructure note zaktualizowane: rating keys (`series:avg:{id}`, `season:avg:{id}`) ustawiane bezpośrednio przez `\Redis::setex` z TTL 3600. [HMAI-122]
- **`phpstan-baseline.neon` zregenerowane** — usuniętych 24 stale entries z PR-ów HMAI-91/102/103 które już naprawiły kod. Net 213 entries baseline (poprzednio 237 z dryftem).
- **`ArticleDTO::fromRow` nullable fields** — `isset() ? (string) … : null` → `… ?? null` (Rector RecastingRemovalRector + TernaryToNullCoalescingRector; PHPDoc shape już deklaruje `string|null`).

### Upgrade notes (manual steps)

1. **Migracje DB:** uruchom `make migrate` (oraz `make migrate-test`). Migracja `Version20260517000001` dodaje 3 indeksy (`idx_episode_season_id`, `idx_series_created_at`, `idx_article_added_at`) — operacja `CREATE INDEX` na dotychczas małych tabelach, sub-second.

2. **Brak nowych env vars i zależności composera** — release jest czysto kodowo-konfiguracyjny.

3. **Frontend:** żadne zmiany schemy template'ów ani route'ów. `public/js/util.js` jest nowym plikiem statycznym ładowanym z `templates/series/index.html.twig`; pozostałe moduły JS bez zmian (przeniesienie na helper to follow-up dla książek/articles/tasks/music — patrz Not in this release).

### Coverage

- **Testy PHP:** 421/421 zielono (vs 408/408 przy 1.4.0). +13 nowych: `ArticleDTOTest` (3), `SeriesRowHydratorTest` (3), `SeriesRepositoryTest::testFindAllLoadsSeasonsAndEpisodes` (1), `EpisodeRatedHandlerTest` rewrite (3 nadal), `GetArticleOfTheDayHandlerTest` (5), drobne adjusty istniejących.
- **Playwright (Series UI):** 5/5 zielono (bez zmian od 1.4.0).
- **Newman (Postman REST):** 28 requestów / 42 assertions / 100% zielono (bez zmian od 1.4.0).
- **PHPStan:** level 8 czysty, baseline 213 errors (regenerowany — drop 24 stale entries).
- **PHP-CS-Fixer:** wszystkie pliki w diff zgodne z `@Symfony` + `@PHP84Migration` + `global_namespace_import`.
- **Rector:** dry-run czysty po refactorze `ArticleDTO::fromRow`.

### Closed Jira epics

- [HMAI-124] Persistence & DB integrity — DBAL, ORM, indexes, transactions (9/9 podzadań)

### Not in this release

Wciąż otwarte pod label `ai_code_review`: HMAI-126 (operability, 6), HMAI-128 (frontend — pozostały bez HMAI-101/118, ~10), HMAI-129 (API hardening / CSRF, 8), HMAI-131 (DDD purity — pozostały bez HMAI-89/91/108/111/117, ~6), HMAI-132 (exports / missing endpoints, 1). `apiCall` helper wpięty tylko w `series.js` — books/articles/tasks/music to follow-up w epiku HMAI-128.

### Contributors

- Leszek Koziatek

## [1.4.0] — 2026-05-16

Domknięcie epica **HMAI-125** (Test coverage) — pełen audit luk w pokryciu testowym i ich uzupełnienie. Dwie nowe warstwy testowe wychodzą poza dotychczasowy zakres PHPUnit: **Playwright** (browser-driven Series UI) i **Newman/Postman** (smoke całej REST powierzchni). Wszystkie 12 podzadań batcha zamknięte (HMAI-33, 42, 73, 74, 76, 82, 93, 94, 95, 97, 99, 116) + audit-driven ReadingSession test. 408/408 PHP tests + 5/5 Playwright + 28 Newman requests (42 assertions) — wszystkie zielone.

### Added

- **Playwright E2E suite** dla Series UI (`tests-e2e/`) — 2 projects (desktop 1440×900 + Pixel 5 mobile), 5 scenariuszy: lista seriali, formularz dodawania bez reload, ocena → średnia natychmiast, błąd 422 → komunikat, layout 375px bez overflow. `BrowserContext.extraHTTPHeaders` wstrzykuje `X-API-Key` na każdy request browsera. [HMAI-42]
- **Newman/Postman collection** (`tests-e2e/postman/AIHomeManager.postman_collection.json`) — 28 requestów, 42 asercje, 100% zielono. `make test-newman` truncate + run z `--ignore-redirects` (niezbędne dla 302 OAuth bez podążania do `accounts.google.com`). Pominięte: Tasks CRUD (HMAI-43), Books ISBN-aware testy mają fallback 503 gdy National Library API jest nieosiągalne. [HMAI-33]
- **Integration testy dla obu OAuth controllerów** — `Auth/GoogleAuthControllerTest` (8 testów) + `Auth/DiscogsAuthControllerTest` (8 testów) pokrywające pełen authorize/callback flow z `disableReboot() + container->set()` patternem. [HMAI-73, HMAI-74]
- **MusicApi happy-path tests** — wcześniej tylko ścieżki 422/503, teraz override portów `MusicListeningHistoryInterface` + `VinylCollectionInterface` przez `installMusicPortMocks()` + Redis cache cleanup. Pokrywa top-albums, collection, comparison. [HMAI-76]
- **GoogleCalendarService refresh-flow tests** — happy path + branch "refresh token missing"; `tokenRepository->save()` weryfikowane po udanym refreshu. [HMAI-82]
- **Isolated unit testy entities** — `SeasonTest` + `EpisodeTest` (Series), `ArticleDailyPickTest` (Articles), `ReadingSessionTest` (Books). Każdy aggregate root i embedded entity ma teraz własny test (DoD HMAI-125). [HMAI-93, HMAI-94, HMAI-125]
- **GoogleClientFactory** — testy pokrywające `access_type=offline`, `prompt=consent` w auth URL + symetryczny whitespace guard dla `clientSecret`. [HMAI-95]
- **DiscogsTokenRepository** — test tampered-ciphertext (`get()` rzuca po mutacji SQL) + assercja `created_at`/`updated_at`. [HMAI-97]
- **DoctrineTaskRepository::findByDateRange** — 3 testy pinujące inclusive boundary semantykę embedded VO `TimeSlot`. [HMAI-116]
- **`symfony/amqp-messenger`** — composer require (wcześniej brakowało; w dev `POST .../episodes` zwracał 500 "No transport supports Messenger DSN amqp://"; test env był OK dzięki `when@test` → `in-memory://`). Discovered podczas HMAI-42. [HMAI-42]

### Changed

- **Test count: 366 → 408** (+42 testów, +6 assertions w nowych testach HMAI-125 ReadingSession). +5 Playwright scenariuszy + 28 Newman requestów.
- **Tooling**: PHPUnit 13 + `@playwright/test` 1.49 + Newman 6.x. `package.json` pojawia się w roocie (devDependencies only — aplikacja PHP pozostaje bez Node runtime).
- **`GetMusicComparisonHandlerTest`** — magic literals (`50.0`, `42.5`, `0.0`) zamienione na nazwane stałe (`HALF_MATCH_SCORE`, `CACHED_MATCH_SCORE_MARKER`, `NO_MATCH_SCORE`) z one-line rationale. [HMAI-99]
- **Makefile**: nowe targety `test-e2e-install` / `test-e2e` (Playwright) i `test-newman-install` / `test-newman` (Newman). Obie pre-truncate odpowiednie tabele przed runem.

### Upgrade notes (manual steps)

> **WAŻNE:** Po deployu zweryfikuj poniższe — kilka wymagań ustawień `.env.local` ujawniło się dopiero podczas pisania E2E. Bez nich Music endpoint i nawet niezależne `/api/series` rozsypują się w runtime.

1. **Migracje DB:** brak (1.4.0 to wyłącznie test coverage + composer deps).

2. **Env vars — `.env.local` musi mieć niepuste wartości (HMAI-42 discovery):**

   ```
   API_KEY=...                       # bez tego JS UI dostaje 401 i Newman nie autentykuje sie
   DISCOGS_TOKEN_KEY=<base64 32B>    # bez tego DI Music nie boot'uje (TokenCipher)
   GOOGLE_TOKEN_KEY=<base64 32B>     # j.w. dla Google
   DISCOGS_CONSUMER_KEY=...          # DiscogsCredentials VO rejects empty
   DISCOGS_CONSUMER_SECRET=...
   LASTFM_API_KEY=...                # placeholder wystarcza (testy toleruja 503)
   LASTFM_USERNAME=...
   DISCOGS_USERNAME=...
   ```

   Wygenerowanie sodium keys: `php -r "echo base64_encode(sodium_crypto_secretbox_keygen()), PHP_EOL;"`. Pusta wartość → `InvalidArgumentException` boot-time (HMAI-90/113).

3. **Composer dependency:** `composer install` zainstaluje nowy `symfony/amqp-messenger` (wpisany w `composer.lock`). Bez tego pakietu routed `EpisodeRated` event nie ma transportu w dev.

4. **Graylog GELF UDP input (HMAI-42 discovery):** kanał `series` w Monologu pisze do GELF; bez skonfigurowanego wejścia każdy `/api/series` returns 500 ECONNREFUSED. Po `make monitoring-up`:

   ```
   curl -u admin:admin -H "Content-Type: application/json" -H "X-Requested-By: cli" \
        -X POST -d '{"title":"GELF UDP","type":"org.graylog2.inputs.gelf.udp.GELFUDPInput",
                     "global":true,"configuration":{"recv_buffer_size":262144,
                     "bind_address":"0.0.0.0","port":12201}}' \
        http://localhost:9000/api/system/inputs
   ```

5. **E2E pre-req:** `make test-e2e-install` (Playwright + browser) i `make test-newman-install` jednorazowo; potem `make test-e2e` / `make test-newman` przy każdym sprawdzeniu pełnego pokrycia.

### Coverage

- **Testy PHP:** 408/408 zielono (vs 366/366 przy 1.3.0). +42 nowych w `tests/Unit/Module/{Articles,Books,Series}/Domain` + `tests/Integration/{Auth,Music,Series,Tasks}`.
- **Playwright (Series UI):** 5/5 zielono — desktop + mobile projects.
- **Newman (Postman REST):** 28 requestów / 42 assertions / 100% zielono.
- **PHPStan:** level 8 czysty (baseline 182 errors zachowany).
- **PHP-CS-Fixer:** `@Symfony` + `@PHP84Migration` + `global_namespace_import` — wszystkie pliki w diff zgodne.

### Closed Jira epics

- [HMAI-125] Test coverage — unit, integration, E2E gaps (12/12 podzadań + audit ReadingSessionTest)

### Contributors

- Leszek Koziatek

[1.4.0]: https://github.com/zlotylesk/AIHomeManager/compare/1.3.0...1.4.0
[HMAI-33]: https://honemanager.atlassian.net/browse/HMAI-33
[HMAI-42]: https://honemanager.atlassian.net/browse/HMAI-42
[HMAI-73]: https://honemanager.atlassian.net/browse/HMAI-73
[HMAI-74]: https://honemanager.atlassian.net/browse/HMAI-74
[HMAI-76]: https://honemanager.atlassian.net/browse/HMAI-76
[HMAI-82]: https://honemanager.atlassian.net/browse/HMAI-82
[HMAI-93]: https://honemanager.atlassian.net/browse/HMAI-93
[HMAI-94]: https://honemanager.atlassian.net/browse/HMAI-94
[HMAI-95]: https://honemanager.atlassian.net/browse/HMAI-95
[HMAI-97]: https://honemanager.atlassian.net/browse/HMAI-97
[HMAI-99]: https://honemanager.atlassian.net/browse/HMAI-99
[HMAI-116]: https://honemanager.atlassian.net/browse/HMAI-116
[HMAI-125]: https://honemanager.atlassian.net/browse/HMAI-125

---

## [1.3.0] — 2026-05-16

Pierwszy release z formalnym CHANGELOG. Zamyka dwa tematyczne epiki follow-upów z code review HMAI-44: **HMAI-127** (resilience zewnętrznych klientów API) i **HMAI-130** (rate limiting). Dodaje warstwę throttlingu dla `^/api/*` i wszystkich klientów Discogs/Last.fm/Biblioteki Narodowej, hartuje OAuth1/OAuth2 flow i wprowadza pierwszą iterację statycznej analizy w CI.

### Added

- **Rate limiting per-IP dla `^/api/*`** + dekoratory `RateLimitedHttpClient` dla Discogs/Last.fm/Biblioteki Narodowej (sliding/token bucket, Redis-backed). [HMAI-38]
- **`DiscogsClockDriftDetector`** — po każdej odpowiedzi z Discogsa porównuje `Date` header z `time()` i loguje warning gdy drift > 300s (próg konfigurowalny przez `services.yaml`). [HMAI-114]
- **`DiscogsCredentials`** VO (`final readonly`) z `__debugInfo()` redagującym secret i `#[\SensitiveParameter]` — `debug:container --show-arguments` i stack trace przy konstrukcji nie wyciekają już consumer secretu. [HMAI-113]
- **Typowana hierarchia wyjątków Discogs**: `DiscogsAuthException` (401/403), `DiscogsNotFoundException` (404), `DiscogsRateLimitException` (429), `DiscogsUnavailableException` (5xx + transport). [HMAI-63]
- **OAuth refresh error handling (Google)** — detekcja `['error' => 'invalid_grant']` w `fetchAccessTokenWithRefreshToken()` zapobiega zapisaniu skorumpowanego "tokenu". [HMAI-64]
- **Walidacja `oauth_token`/`oauth_token_secret` non-empty** w `DiscogsApiClient` przed wywołaniem signera. [HMAI-64]
- **Migracja `Version20260511000001`** — nullable kolumna `expires_at DATETIME` w `discogs_oauth_tokens` (placeholder dla przyszłego proaktywnego re-auth). [HMAI-64]
- **Walidacja konstruktora `GoogleClientFactory`** — pusty/whitespace-only `clientId`/`clientSecret`/`redirectUri` → `InvalidArgumentException` boot-time. [HMAI-90]
- **Ochrona przed XXE** w `NationalLibraryApiClient` — `LIBXML_NONET` + jawne odrzucenie odpowiedzi z `<!DOCTYPE`. [HMAI-96]
- **Code review hub w Confluence** — strona "External API resilience patterns" (id 59441164) z reusable patterns: typed exceptions, debug-safe VO, drift detector, OAuth refresh. [HMAI-127]
- **Static analysis w CI** — PHPUnit + MySQL/Redis service containers, rector dry-run, PHP-CS-Fixer + PHPStan workflow.

### Changed

- **`DiscogsAuthController`** — `authorize()` i `callback()` jawnie sprawdzają `getStatusCode() === 200` przed `getContent()`, zwracają 502 z `body_sample` w logu zamiast generycznego 500. [HMAI-105]
- **`GoogleAuthController::authorize()`** — `try/catch \Throwable` wokół `setState() + createAuthUrl()` z redirectem do `/tasks?error=oauth_unavailable` zamiast kernel 500. [HMAI-106]
- **`NationalLibraryApiClient` / `GoogleCalendarService`** — wąskie catche zamiast `catch (\Exception $e)`; typed exceptions bąbelkują przez framework. [HMAI-62]
- **`GetMusicComparisonHandler`** cache key — uwzględnia teraz `discogsUsername` (poprzednio collision-prone dla różnych userów). [HMAI-85]
- **`LastFmApiClient`** — `trim($apiKey) === ''` zamiast `=== ''` (whitespace-only liczy się jako brak konfiguracji). [HMAI-84]
- **`ArticleImporter`** — explicit `UTF-8` przy `mb_detect_encoding`, jawnie loguje import problems zamiast cichego skip. [HMAI-81]
- **`AlbumNormalizer`** — błędy regex i `iconv()` są logowane (poprzednio cicho zwracały oryginał). [HMAI-80, HMAI-104]
- **`DiscogsApiClient` ctor** — single VO `DiscogsCredentials` zamiast dwóch stringów; rewiring w `services.yaml`. [HMAI-113]

### Security

- Discogs consumer secret nie jest już widoczny w `debug:container --show-arguments` ani stack trace przy DI bootstrapie. [HMAI-113]
- `NationalLibraryApiClient` odrzuca odpowiedzi XXE — żaden `file:///` ani `http://evil/` nie zostanie pobrany przez parser. [HMAI-96]
- `^/api/*` ma teraz globalny rate limit (60 req/min sliding window per IP) — chroni przed brute-force enumeration i prostym DoS. [HMAI-38]

### Fixed

- Test DB suffix `_test_test` powtórzenia w CI (regression env config). [ci]
- Discogs OAuth1 `oauth_token_secret` walidowany przed sygnowaniem (zapobiega podpisaniu pustym sekretem). [HMAI-64]

### Upgrade notes (manual steps)

> **WAŻNE:** Po deployu zweryfikuj następujące punkty zanim uruchomisz aplikację.

1. **Migracje DB:** `make migrate` doda nullable kolumnę `expires_at` do `discogs_oauth_tokens` — **brak utraty danych**, kolumna na razie zawsze `NULL`.

2. **Env vars — walidacja boot-time (HMAI-90, HMAI-113):**

   Sprawdź że `.env.local` zawiera **niepuste** wartości dla:

   ```
   DISCOGS_CONSUMER_KEY=...        # HMAI-113 - DiscogsCredentials VO waliduje non-empty
   DISCOGS_CONSUMER_SECRET=...     # HMAI-113
   GOOGLE_CLIENT_ID=...            # HMAI-90 - GoogleClientFactory waliduje non-empty
   GOOGLE_CLIENT_SECRET=...        # HMAI-90
   GOOGLE_REDIRECT_URI=...         # HMAI-90
   ```

   Pusty/whitespace-only wpis spowoduje `InvalidArgumentException` przy starcie kernela. To celowy fail-fast — wcześniej puste env było traktowane jak "feature wyłączony", co maskowało faktyczną nieukończoną konfigurację.

3. **Rate limiter w prod (HMAI-38):** workflow domyślnie używa Redis pool `cache.rate_limiter`. Upewnij się że `REDIS_URL` jest osiągalny — bez Redis rate limiter zdegraduje do in-memory storage (per-process, bezsensowny w wielu workerach).

4. **OAuth tokens — bez wymuszanej re-auth.** W 1.3.0 nie ma TRUNCATE migracji (inaczej niż w 1.2.0 dla HMAI-46/47). Istniejące Google + Discogs tokens pozostają ważne.

### Coverage

- **Testy:** 366/366 zielono (vs 299/299 przy 1.2.0). +67 nowych testów rozproszonych w `tests/Unit/Module/{Music,Books,Tasks}` i `tests/Integration/{Music,Security,RateLimit}`.
- **PHPStan:** level 8 czysty (baseline 182 errors zachowany, brak nowych).
- **PHP-CS-Fixer:** `@Symfony` + `@PHP84Migration` + `global_namespace_import` — wszystkie pliki w diff zgodne.

### Closed Jira epics

- [HMAI-127] External API clients — resilience, error handling, OAuth refresh (14/14 podzadań)
- [HMAI-130] Rate limiting & throttling (1/1 podzadanie)

### Contributors

- Leszek Koziatek

[1.3.0]: https://github.com/zlotylesk/AIHomeManager/compare/1.2.0...1.3.0
[HMAI-38]: https://honemanager.atlassian.net/browse/HMAI-38
[HMAI-62]: https://honemanager.atlassian.net/browse/HMAI-62
[HMAI-63]: https://honemanager.atlassian.net/browse/HMAI-63
[HMAI-64]: https://honemanager.atlassian.net/browse/HMAI-64
[HMAI-80]: https://honemanager.atlassian.net/browse/HMAI-80
[HMAI-81]: https://honemanager.atlassian.net/browse/HMAI-81
[HMAI-84]: https://honemanager.atlassian.net/browse/HMAI-84
[HMAI-85]: https://honemanager.atlassian.net/browse/HMAI-85
[HMAI-90]: https://honemanager.atlassian.net/browse/HMAI-90
[HMAI-96]: https://honemanager.atlassian.net/browse/HMAI-96
[HMAI-104]: https://honemanager.atlassian.net/browse/HMAI-104
[HMAI-105]: https://honemanager.atlassian.net/browse/HMAI-105
[HMAI-106]: https://honemanager.atlassian.net/browse/HMAI-106
[HMAI-113]: https://honemanager.atlassian.net/browse/HMAI-113
[HMAI-114]: https://honemanager.atlassian.net/browse/HMAI-114
[HMAI-127]: https://honemanager.atlassian.net/browse/HMAI-127
[HMAI-130]: https://honemanager.atlassian.net/browse/HMAI-130

---

## [1.2.0] — 2026-05-07

Closure epica HMAI-123 — wszystkie 12 Critical findings (C1–C12) z code review HMAI-44 zamknięte. Pełna historia w `git log 1.1.0..1.2.0` oraz [HMAI-44 Confluence](https://honemanager.atlassian.net/wiki/spaces/H/pages/52658177).

Highlights: OAuth state CSRF (Google + Discogs), encryption tokenów at-rest (libsodium), Last.fm HTTPS, walidacja URL VO (Book cover + Article), Discogs collection async, `unserialize()` → JSON we wszystkich cache pathach.

## [1.1.0] — wcześniej

Implementacja modułów Series, Tasks, Books, Articles, Music (HMAI-1—HMAI-30). Historia szczegółowa w `git log 1.0.0..1.1.0`.

## [1.0.0] — wcześniej

Pierwsza wersja milestone'owa.
