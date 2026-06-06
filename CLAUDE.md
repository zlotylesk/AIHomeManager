# AIHomeManager — Claude Code Context

Single-user system automatyzacji codziennych czynności. Stack: PHP 8.4 + Symfony 8 + MySQL 8 + Redis 7 + RabbitMQ 3.12. Heksagonalna architektura, CQRS z dwoma busami.

**Moduły:** Series, Tasks, Books, Articles, Music. Frontend dual-track: Series + Books UI przez Webpack Encore + Stimulus (`app/assets/`); Tasks/Articles/Music na Twig + vanilla JS (`app/public/js/`) z `window.apiCall` z `public/js/util.js`.

**Status:** projekt operacyjny, jeden release (`1.10.0`). Pełna historia → [CHANGELOG.md](CHANGELOG.md).

## Architektura — ZASADY NIENARUSZALNE

- Hexagonal: `src/Module/{Name}/{Domain,Application,Infrastructure}/`
- Domain bez frameworka: `grep -r "use Doctrine" src/Module/*/Domain/` MUSI zwracać pusty wynik. Bramka CI: `make deptrac` — Domain → [] na poziomie tokenów, cross-module coupling zakazany
- Doctrine XML w `Infrastructure/Persistence/Doctrine/*.orm.xml` — NIE migrować na atrybuty PHP (ADR-001)
- Domain Events: agregat gromadzi w `$recordedEvents`, handler dispatchuje po `releaseEvents()`. Wzorzec: `Series` aggregate
- Query handlery: DBAL, NIE ORM (nie hydratuj agregatów do odczytu)
- Command handler: `#[AsMessageHandler(bus: 'command.bus')]`
- Query handler: `#[AsMessageHandler(bus: 'query.bus')]`
- Event handler: `#[AsMessageHandler]` bez `bus:` (default)
- `event.bus` skonfigurowany z `allow_no_handlers: true` — domain events to fire-and-forget, subscriber opcjonalny

## Konwencje nazewnictwa

| Element | Wzorzec | Lokalizacja |
|---|---|---|
| Aggregate Root | `Series`, `Task`, `Book`, `Article` | `Domain/Entity/` |
| Value Object (immutable, `final readonly`) | `Rating`, `ISBN`, `CoverUrl`, `TimeSlot`, `ReadingProgress` | `Domain/ValueObject/` |
| Command | `CreateSeries`, `LogReadingSession` | `Application/Command/` |
| Command Handler | `*Handler` | `Application/Handler/` |
| Query | `GetAllSeries`, `GetSeriesDetail` | `Application/Query/` |
| Query Handler | `*Handler` | `Application/QueryHandler/` |
| DTO | `*DTO` | `Application/DTO/` |
| Repository Interface | `*RepositoryInterface` | `Domain/Repository/` |
| Repository Impl | `Doctrine*Repository` | `Infrastructure/Persistence/` |

## Frontend

- **Series + Books UI:** Webpack Encore + Stimulus. Stimulus controllers w `assets/controllers/{series,books}_controller.js`, mountowane przez `data-controller="..."` na `app/templates/{series,books}/index.html.twig`. Build: `make assets-prod` → `public/build/*.{js,css}` + `entrypoints.json` manifest. `base.html.twig` używa `{{ encore_entry_link_tags('app') }}` + `{{ encore_entry_script_tags('app') }}`.
- **Pozostałe moduły** (Tasks/Articles/Music): Twig + vanilla JS w `public/js/*.js`, global helpers `window.TOAST_TIMEOUT_MS` / `window.safeUrl` / `window.apiCall` z `public/js/util.js`.
- Routes: `/` → redirect, `/series`, `/tasks`, `/books`, `/articles`, `/music`
- Selektor ocen Series: 10 przycisków (NIE `<input type=number>`)
- Tasks API: pełny REST CRUD (`POST/GET/GET{id}/PATCH{id}/DELETE{id} /api/tasks`, `POST {id}/complete`, `POST {id}/cancel`) + `/time-report` + `/export`. Google Calendar sync via `CalendarServiceInterface` z graceful degrade

### Webpack Encore

| Plik | Rola |
|---|---|
| `app/webpack.config.js` | Encore config — entry `app`, splitEntryChunks, Stimulus bridge, versioning prod-only |
| `app/assets/app.js` | Główny entry — importuje `bootstrap.js` (Stimulus) + `styles/app.css` |
| `app/assets/bootstrap.js` | `startStimulusApp` auto-discovery z `controllers/` |
| `app/assets/util.js` | ES module export: `TOAST_TIMEOUT_MS`, `safeUrl`, `apiCall`, `escHtml` |
| `app/assets/controllers/series_controller.js` | Stimulus controller dla Series UI |
| `app/assets/controllers/books_controller.js` | Stimulus controller dla Books UI |
| `app/assets/styles/app.css` | Globalny stylesheet (jedyne źródło prawdy) |

Komendy: `make assets` (dev), `make assets-watch` (watch mode), `make assets-prod` (production), `make node-audit` (CVE gate). Node service: `aihm-node-1` (`node:24-alpine`, mount na `./app`). `make node-install` reinstaluje `npm install` po zmianie `package.json`.

`public/build/` + `node_modules/` w `.gitignore`. CI buduje assets w jobach `tests` i `e2e-playwright` (`npm ci && npm run build` w `app/`) przed PHPUnit/Playwright — bez tego Twig `encore_entry_*` wywala 500.

**npm audit gate (HMAI-150):** każdy `npm ci` deps frontend (`tests` job + `e2e-playwright`, oba w `app/`) ma zaraz po sobie `npm audit --audit-level=high`. Low/moderate są noise dla devDeps i przepuszczane; high+critical blokują merge. Fix = bump paczki (`npm install pkg@latest`), nie suppress — advisory na zainstalowanej wersji to legit signal. Lokalnie: `make node-audit`.

Root `package.json` (Playwright + Newman) **świadomie poza gate**: newman 6.x (latest stable) ciągnie deep-transitive CVE w `handlebars`/`lodash`/`postman-*` bez forward-fixu od vendora; `audit fix --force` cofnąłby do newman 2.1.2 i wywalił kolekcję Postman. Re-evaluacja śledzona w HMAI-174 — gdy newman 7.x wyjdzie z czystym drzewem, gate wraca na root.

## Infrastruktura

| Serwis | Kontener / Port | Notatki |
|---|---|---|
| MySQL 8 | `mysql:3306` | DB `homemanager` |
| Redis 7 | `redis:6379` | Klucze `series:avg:{id}`, `season:avg:{id}` (TTL 3600) ustawiane bezpośrednio przez `\Redis` w `EpisodeRatedHandler` (nie przez Symfony cache pool — handler iniektuje `\Redis`). Pool `cache.rate_limiter` używany przez RateLimiter |
| RabbitMQ 3.12 | `rabbitmq:5672` (AMQP), `:15672` UI (guest/guest) | Transport `async`, exchange `series_events` (topic), retry 3× (1s→2s→4s, max 30s), DLQ `failed` |
| Worker Messenger | `messenger_worker` | `messenger:consume async --time-limit=3600 -vv` |
| Worker Scheduler | `scheduler_worker` | `messenger:consume scheduler_default --time-limit=3600 -vv` |
| Node (Encore build) | `node:24-alpine`, container `aihm-node-1` | Long-running `tail -f /dev/null`. `docker compose exec node npm ...` |
| Graylog 5.2 | profil `monitoring`, UI `:9000` (admin/admin), GELF UDP `:12201` | NIE w `make up` — `make monitoring-up`. Kanał Monolog `series` |

W testach: transport `async` i `failed` → `in-memory://` (`when@test` w `messenger.yaml`).

Async messages routowane do `async` transportu: `Series\Domain\Event\EpisodeRated`, `Music\Application\Command\RefreshDiscogsCollection` (fetch kolekcji Discogs offloaded z requestu, endpoint `/api/music/collection` zwraca cache + dispatcha refresh przy miss), `Music\Application\Command\PollLastFmRecentTracks` (scheduler poll co 30 min, handler dispatchuje `LogListeningSession` per track na sync command.bus). `Books\Domain\Event\BookCompleted` świadomie sync (in-memory) — brak handlera, brak I/O side-effects (ADR-006). Pinned przez `BookCompletedRoutingTest`.

`NewRelicMonologHandler` (`src/Module/Series/Infrastructure/Logging/`) — graceful degrade gdy brak rozszerzenia `newrelic`.

GELF UDP input + index sets + streams: `make monitoring-bootstrap` (idempotentny skrypt `scripts/graylog-bootstrap.sh`). Tworzy GELF UDP input, index sets `auth-events` (90 dni, time-based) i `series-events` (30 dni, time-based) z odpowiadającymi stream'ami filtrującymi po `channel`. Wymaga działającego Graylog (`make monitoring-up` najpierw).

## Symfony Scheduler

`src/Schedule.php` rejestruje 5 zadań cyklicznych (via `dragonmantank/cron-expression`):

| Cron | Wiadomość | Efekt |
|---|---|---|
| `0 0 * * *` | `Articles\...\ResetDailyArticleCache` | Usuwa Redis `articles:today`, kasuje `article_daily_picks` > 7 dni |
| `0 3 * * *` | `App\Application\Scheduled\BackupDatabase` | mysqldump + gzip → `/backups/homemanager-YYYY-MM-DD.sql.gz`, retention 30 daily + 12 monthly |
| `0 8 * * 1` | `App\Application\Scheduled\GenerateWeeklyActivityReport` | Loguje `scheduled_task=weekly_report` do default channel (read_articles, pages_read, completed_tasks, rated_episodes_total) |
| `0 */6 * * *` | `Music\...\RefreshDiscogsCollection` | Pre-warm cache kolekcji przed wygaśnięciem 6h TTL |
| `*/30 * * * *` | `Music\...\PollLastFmRecentTracks` | Poll Last.fm recent tracks → lokalna historia odsłuchów, idempotentny przez `dedup_hash` |

Worker: `bin/console debug:scheduler` pokazuje stan; `docker compose up -d scheduler_worker` konsumuje transport `scheduler_default`. Stateful via `cache.app` (filesystem, mount na hoście) — restart workera odpala max 1 zaległe okno (`processOnlyLastMissedRun(true)`).

### .env — kluczowe

```
DATABASE_URL=mysql://homemanager:homemanager@mysql:3306/homemanager?serverVersion=8.0&charset=utf8mb4
MESSENGER_TRANSPORT_DSN=amqp://guest:guest@rabbitmq:5672/%2f/messages
REDIS_URL=redis://redis:6379
GRAYLOG_HOST, GRAYLOG_PORT=12201
NEW_RELIC_LICENSE_KEY, NEW_RELIC_APP_NAME
```

## Komendy Makefile

| Akcja | Komenda |
|---|---|
| Start środowiska | `make up` |
| Pełna inicjalizacja | `make setup` |
| Shell PHP | `make shell` |
| Wszystkie testy | `make test` |
| Domain only | `make test-unit` |
| Integration only | `make test-integration` |
| Cache clear | `make cc` |
| Migracje dev/test | `make migrate` / `make migrate-test` |
| Logi | `make logs` |
| Routing | `make routes` |
| Kontenery | `make services` |
| Status workera | `make messenger-status` |
| Monitoring up/down/logs | `make monitoring-up` / `make monitoring-down` / `make monitoring-logs` |
| Graylog bootstrap (inputs+indexes+streams) | `make monitoring-bootstrap` |
| E2E (Playwright) install/run | `make test-e2e-install` / `make test-e2e` |
| Newman (Postman REST collection) | `make test-newman-install` / `make test-newman` |
| Załaduj fixtures (dev) | `make fixtures` |
| Webpack Encore dev/watch/prod | `make assets` / `make assets-watch` / `make assets-prod` |
| Npm install (po `package.json` change) | `make node-install` |
| npm audit (high+critical CVE gate) | `make node-audit` |
| Backup MySQL (ręczny) | `make backup-now` |
| Restore MySQL | `make restore BACKUP=backups/homemanager-YYYY-MM-DD.sql.gz` |

## Testy

- Unit: `tests/Unit/Module/{Name}/Domain/` — wzorzec `tests/Unit/Module/Series/Domain/SeriesAggregateTest.php`
- Integration: `tests/Integration/`
- Fixtures: `tests/Integration/DataFixtures/FixturesLoadTest.php` — sprawdza, że `make fixtures` daje stałą strukturę danych
- E2E: `tests-e2e/` (Playwright, TypeScript). Files match `*.desktop.spec.ts` (1440×900) lub `*.mobile.spec.ts` (Pixel 5 viewport) per project config w `playwright.config.ts`
- Newman/Postman: `tests-e2e/postman/AIHomeManager.postman_collection.json`. Uruchamiać przez `make test-newman` (truncate + newman z `--ignore-redirects`); details w `tests-e2e/postman/README.md`
- Framework: PHPUnit 13 + @playwright/test 1.49 + newman 6.x
- Testy `*ApiTest` używają `App\Tests\Support\AuthenticatedApiTrait` — dodaje header `X-API-Key: test-api-key` (zob. `app/.env.test`)
- CI gate: job `tests` uruchamia `doctrine:schema:validate` po migracjach a przed PHPUnit — drift ORM XML mapping vs schema MySQL blokuje merge (osobna kategoria błędu, nie zaszyta w teście). Lokalnie: `make schema-validate`
- E2E/Newman pre-req: `API_KEY=e2e-test-key` w `app/.env.local`, Discogs/Last.fm placeholders (`DISCOGS_TOKEN_KEY`, `GOOGLE_TOKEN_KEY`, `DISCOGS_CONSUMER_KEY`, `DISCOGS_CONSUMER_SECRET`, `LASTFM_API_KEY`, `LASTFM_USERNAME`, `DISCOGS_USERNAME`) ustawione na cokolwiek niepuste (DI nie zboot'uje się z pustymi VO). Graylog GELF UDP input musi być skonfigurowany (`make monitoring-up` + POST do `/api/system/inputs` z `org.graylog2.inputs.gelf.udp.GELFUDPInput` na `0.0.0.0:12201`), inaczej `series` kanał Monologu wywala 500 na `/api/series` — **dotyczy tylko env `dev`/`prod`**. W CI joby E2E/Newman lecą z `APP_ENV=test`, gdzie `monolog when@test` kieruje kanały `series`/`auth` na handlery `null` → Graylog niepotrzebny. Klucze `*_TOKEN_KEY` w CI to **poprawny base64 32B** (`TokenCipher` rzuca dla innej długości — OAuth-init request inaczej zwróci 500 zamiast 302/502). App server w CI: `symfony server:start --no-tls --port=8080` (serwuje routing + statyczne assety Encore; gołe `php -S` tego nie łączy)

## Security — API Key

- `^/api/*` chronione firewall'em `api` w `app/config/packages/security.yaml` (stateless, custom authenticator)
- Authenticator: `App\Security\ApiKeyAuthenticator` — czyta header `X-API-Key`, porównuje przez `hash_equals` z `%env(API_KEY)%`. `supports()` zwraca `false` dla `/api/health` — publiczny readiness probe dla orchestratorów.
- 401 JSON `{"error": "..."}` przy braku/błędnym kluczu
- Klucz produkcyjny w `app/.env.local` (gitignored). `app/.env` ma tylko placeholder
- `/auth/google*`, `/auth/discogs*`, frontend (`/`, `/series` itd.) — firewall `main` z `security: false` (publiczne)
- Test env: `API_KEY=test-api-key` w `app/.env.test`
- **CSRF (ADR-005):** świadomie **nie używamy** `#[IsCsrfTokenValid]` na `^/api/*`. Firewall jest `stateless: true`, autoryzacja przez header `X-API-Key` (nie cookie) — przeglądarka nie ustawia custom headerów cross-origin, więc CSRF nie ma drogi. OAuth init (`/auth/*`) używa parametru `state`. Regresja w `tests/Integration/Security/ApiKeyAuthCsrfTest.php`.

## HTTP security headers

Dual-layer defense-in-depth: nginx (`docker/nginx/default.conf`) + Symfony `SecurityHeadersListener` (`kernel.response`, priority -128). Oba ustawiają te same 4 headery na każdą odpowiedź:

| Header | Wartość |
|---|---|
| `X-Content-Type-Options` | `nosniff` |
| `X-Frame-Options` | `DENY` |
| `Referrer-Policy` | `strict-origin-when-cross-origin` |
| `Permissions-Policy` | `geolocation=(), microphone=(), camera=(), payment=()` |

HSTS (`Strict-Transport-Security`) zakomentowane w nginx — odkomentować PO konfiguracji HTTPS. `server_tokens off` ukrywa wersję nginx.

Regresja: `tests/Integration/Security/SecurityHeadersTest.php` (4 testy: frontend, API, error 404, all headers).

## Request correlation

- `App\EventListener\RequestIdListener` — `kernel.request` priority 256 (przed `ApiRateLimitListener` @100, żeby 429 niosło korelator), `kernel.response` priority 0. Czyta header `X-Request-ID` z requestu lub generuje UUID v4. Wartość zapisuje w atrybucie requestu `_request_id` i echoes back w response header.
- Walidacja inbound: `^[A-Za-z0-9._-]{1,128}$`. Wartości spoza tego zestawu są odrzucane (server-generated UUID je zastępuje) — ochrona przed wstrzykiwaniem znaków sterujących do logów.
- `App\Logging\RequestIdProcessor` — invokable, `#[AsMonologProcessor]`. Czyta `_request_id` z `RequestStack->getMainRequest()` i dodaje `extra.request_id` do każdego `LogRecord` emitowanego w trakcie requestu. CLI/worker context (brak main requestu) — passthrough.
- Async (Messenger): propagacja ID do workera świadomie poza scope HMAI-158 — wymaga osobnego Stampa + middleware.
- Regresja: `tests/Integration/EventListener/RequestIdListenerTest.php` (4 testy: brak header, valid echo, invalid replaced, log extra carry).

## API exception listener

- `App\EventListener\ApiExceptionListener` — `kernel.exception` (priority 64, przed framework `ErrorListener` na -64). Konwertuje uncaught throwables na `^/api/*` na `JsonResponse`.
- `HttpExceptionInterface` (4xx) zachowuje status i message; pozostałe (`RuntimeException`, `DomainException` poza catch w kontrolerze, itp.) → 500 z generycznym `Internal server error.` (oryginalny message tylko w logu, nie w odpowiedzi).
- `HandlerFailedException` (Messenger wrap) jest rozpakowywany — listener używa previous exception do type-checków, więc HTTP exceptions z handlerów łapią się tak samo jak rzucone bezpośrednio.
- Non-API paths (np. `/series`, `/typo`) przechodzą bez zmian — Twig frontend zachowuje swoje renderowane strony błędu.
- Pełny exception context (path, method, status, exception) loguje się na poziomie `error` przez default channel.

## Health endpoint

- `GET /api/health` — publiczny readiness probe (bez `X-API-Key`)
- Probe'y: MySQL (`SELECT 1`), Redis (`PING`), RabbitMQ (TCP do hosta z `MESSENGER_TRANSPORT_DSN`, timeout 1s)
- 200 `{"status":"healthy", "components":{"mysql":"up", "redis":"up", "rabbitmq":"up"}, "timestamp":"..."}` gdy wszystko up
- 503 `"status":"unhealthy"` + komponent `"down"` gdy któryś probe pada — orchestratorzy nie kierują traffic do degraded instancji
- Docker healthcheck na `nginx`: `wget --spider http://localhost/api/health` (interval 30s, retries 3, start_period 30s) — end-to-end stack probe
- `HealthChecker` (`src/Health/HealthChecker.php`) — `readonly` (NIE `final` żeby PHPUnit `createStub` działał w teście kontrolera)

## Static Analysis

- **PHPStan** level 8 + `phpstan-symfony` + `phpstan-doctrine` + `phpstan-phpunit`. Config: `app/phpstan.neon.dist`. Baseline `app/phpstan-baseline.neon` — nowe błędy wymagają fixu lub rozszerzenia baseline'u przez `make phpstan-baseline`
- **PHP CS Fixer**: `@Symfony` + `@PHP84Migration` + `global_namespace_import` (klasy importowane). Config: `app/.php-cs-fixer.dist.php`
- **Rector**: `withPhpSets()` + `deadCode`. Config: `app/rector.php`
- **Deptrac**: formalizuje granice heksagonalne — każdy moduł ma osobne layery `*Domain` / `*Application` / `*Infrastructure`. Domain → [] (zero zależności poza PHP core), Application → własny Domain + Vendor, Infrastructure → własny Domain + własna Application + Vendor, `Glue` (Controllers/EventListeners/Kernel/Security poza `src/Module/`) → wszystko. Cross-module coupling zakazany. Config: `app/deptrac.yaml` ze scalonym `skip_violations` (pre-existing — Domain ports zwracające Application DTOs w Books/Music, Music/Tasks Infrastructure → `App\Security\TokenCipher`). Regeneracja: `make deptrac-baseline` → przenieść `skip_violations` z `deptrac-baseline.yaml` do `deptrac.yaml` i usunąć osobny plik (single source of truth)
- **Composer audit**: `composer audit` (od 2.4 wbudowane) queryuje FriendsOfPHP/security-advisories. CI gate w `static-analysis` po Deptrac — blokuje merge gdy advisory pojawi się dla zainstalowanej wersji paczki. Lokalnie: `make audit`. Fail = bumpować dep, nie suppressować (advisory failing CI to legit signal)
- CI: `.github/workflows/ci.yml` — 4 joby na każdy push/PR: `static-analysis` (Rector dry-run + CS Fixer + PHPStan level 8 + Deptrac + Composer audit), `tests` (PHPUnit), `e2e-playwright` i `e2e-newman` (oba `needs: tests`)
- **CI job timeouts (HMAI-154)**: każdy job ma explicit `timeout-minutes` — `static-analysis: 10`, `tests: 15`, `e2e-playwright: 20`, `e2e-newman: 10`. Cap = ~2–3× obserwowanego peaku. Default GitHub Actions to 360 min — runaway/deadlock bez bound zjada cały budżet darmowych minut na pojedynczy hang. Po 30 dniach monitorować realne czasy: jeśli któryś job zbliża się do bound (>70%), podnieść — nie obniżać, bo flaky CI to gorsze niż timeout

| Komenda | Akcja |
|---|---|
| `make analyse` | CS Fixer (dry-run) + PHPStan + Deptrac |
| `make phpstan` | PHPStan analyse |
| `make phpstan-baseline` | Regeneruj baseline (po naprawie błędów) |
| `make cs-check` / `cs-fix` | CS Fixer dry-run / apply |
| `make rector-dry` / `rector` | Rector dry-run / apply |
| `make deptrac` | Deptrac analyse (architecture boundaries) |
| `make deptrac-baseline` | Regeneruj baseline deptrac |
| `make schema-validate` | Doctrine schema validate (ORM XML mapping ↔ MySQL schema) |
| `make audit` | Composer audit (security advisories) |

## Rate limiting — own API + external APIs

- `App\EventListener\ApiRateLimitListener` — `kernel.request` (priority 100, przed routerem/firewall'em). Per-IP throttle dla `^/api/*` (limiter `api_per_ip`, sliding_window 60/min). Bypass: `/api/health` i `/auth/*`. 429 zwraca `Retry-After`, `X-RateLimit-Remaining`, `X-RateLimit-Limit`. Loguje `rate_limit_triggered=true` (warning).
- `App\Http\RateLimitedHttpClient` — dekorator `HttpClientInterface` proaktywnie blokuje request przed wywołaniem zewnętrznego API (`reservation->wait()`). Trzy instancje w `services.yaml`: `app.discogs_http_client`, `app.lastfm_http_client`, `app.national_library_http_client` — wstrzykiwane do odpowiednich klientów Music/Books.
- Limitery (`app/config/packages/rate_limiter.yaml`): `api_per_ip` (sliding_window, 60/min), `discogs_api` (token_bucket, 60/min), `lastfm_api` (token_bucket, 5/s), `national_library_api` (token_bucket, 60/min)
- Storage: pool `cache.rate_limiter` (Redis) w prod/dev. W testach `Symfony\Component\RateLimiter\Storage\InMemoryStorage` — nietagged `kernel.reset`, więc stan przeżywa request → request gdy `KernelBrowser::disableReboot()`. External limiters w teście policy `no_limit`
- Distributed lock: `LOCK_DSN=redis://redis:6379` (`.env`) — koordynacja web ↔ worker
- `DiscogsApiClient::fetchAllPages` — throttling teraz robi `RateLimitedHttpClient`
- Wyjątki/granice: `/auth/*` poza `^/api/*` więc listener nie dotyka; `/api/health` jawnie wykluczone; Google Calendar SDK używa własnego klienta HTTP (nie Symfony) i NIE jest objęty dekoratorem — limit 1M/dobę zostawia spory margines

## Encryption — OAuth tokens

- `App\Security\TokenCipher` (libsodium `secretbox`, format: base64(nonce ‖ ciphertext)) — wspólne narzędzie dla wszystkich OAuth providerów
- Dwie instancje w `services.yaml`: `app.discogs_token_cipher` (klucz `DISCOGS_TOKEN_KEY`) i `app.google_token_cipher` (`GOOGLE_TOKEN_KEY`) — osobne klucze rozdzielają blast radius
- Klucze 32B base64 w `.env.local`. Generate: `php -r "echo base64_encode(sodium_crypto_secretbox_keygen());"`
- Discogs OAuth1: `DiscogsTokenRepository` (Music) — pole-per-pole encryption (`oauth_token`, `oauth_token_secret`)
- Google OAuth2: `GoogleOAuthTokenRepository` (Tasks) — szyfruje cały `token_json` (access+refresh+expires)

## MCP servers (`.mcp.json`)

- `sequential-thinking` (npx)
- `github` (npx — wymaga `GITHUB_PERSONAL_ACCESS_TOKEN`)
- `context7` (npx — docs Symfony/Doctrine/PHP)
- `filesystem` (npx — root: AIHM)
- `mysql` (npx — `@benborla29/mcp-server-mysql`, `127.0.0.1:3306`, read-only: INSERT/UPDATE/DELETE wyłączone)
- `playwright` (npx — `@playwright/mcp@latest`, browser automation/E2E)
- `redis` (npx — `@modelcontextprotocol/server-redis`, `redis://127.0.0.1:6379`)
- `docker` (uvx — `mcp-server-docker`, wymaga `uv` na hoście: `pipx install uv` lub `winget install astral-sh.uv`)
- Atlassian Rovo: konfigurowane przez claude.ai (NIE `.mcp.json`)
- Wymóg: Node.js v18+ (zainstalowane v24.x LTS); Docker MCP dodatkowo wymaga `uv`

## Skills przydatne dla projektu

- `/start-task HMAI-XX` — Jira → branch → implement → PR → Confluence → transition (workflow z preferencjami: skip STOP checkpoints, no Co-Authored-By)
- `/review`, `/security-review` — review pending changes / security
- `superpowers-symfony:symfony-tdd-pest` lub `:symfony-tdd-phpunit` — TDD RED/GREEN/REFACTOR
- `superpowers-symfony:symfony-check` — PHP-CS-Fixer + PHPStan + tests
- `superpowers-symfony:doctrine-architect` (subagent) — projektowanie schemy
- `superpowers-symfony:symfony-reviewer` (subagent) — review po zmianie kodu
- `superpowers-symfony:functional-tests` — WebTestCase + TDD dla kontrolerów

## Zasady pracy z Claude Code

1. Najpierw przeczytaj CLAUDE.md i opisz plan przed implementacją
2. Jedno zadanie Jira = jedna sesja
3. Po każdej zmianie kodu: `make test`. Nie zgłaszaj gotowości jeśli testy nie przechodzą
4. Przed `git commit`: pokaż diff + propozycję commit message, NIE commituj bez zgody
5. NIE dodawaj `Co-Authored-By: Claude` w commitach (preferencja)
6. Branche: `HMAI-XX-krotki-opis` od `develop`
7. Po większym kroku (zamknięty ticket / epic review / release) **zaproponuj** userowi `/compact` — nie wykonuj automatycznie

## Linki

- Confluence hub: https://honemanager.atlassian.net/wiki/spaces/H/pages/46661633
- Jira board: https://honemanager.atlassian.net/jira/software/projects/HMAI/boards
- Repo: `zlotylesk/AIHomeManager` (GitHub)
