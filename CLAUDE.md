# AIHomeManager — Claude Code Context

Single-user system automatyzacji codziennych czynności. Stack: PHP 8.4 + Symfony 8 + MySQL 8 + Redis 7 + RabbitMQ 3.12. Heksagonalna architektura, CQRS z dwoma busami. Wszystkie moduły zaimplementowane (HMAI-1—HMAI-30).

**Moduły:** Series, Tasks, Books, Articles, Music. Frontend: dual track — Series UI przez Webpack Encore + Stimulus (`app/assets/`); Tasks/Books/Articles/Music wciąż na Twig + vanilla JS (`app/public/js/`), wszystkie używają `window.apiCall` z `public/js/util.js`.

**Status code review (HMAI-44):** backlog zamknięty 2026-05-23 (1.9.0). 59/59 ticketów `ai_code_review` Gotowe. Projekt w fazie utrzymania. Raport: `docs/code-review/HMAI-44-app-review.md`. Confluence id 52658177.

**Wydania:** ostatni tag `1.9.0` (2026-05-23) — HMAI-131 DDD purity + HMAI-132 CSV exports. 542/542 PHP + 5/5 Playwright + 34/34 Newman. Pełna historia → [CHANGELOG.md](CHANGELOG.md). Bieżący stan release/epików → [docs/CURRENT-STATE.md](docs/CURRENT-STATE.md). Archiwum domknięć → [docs/HISTORY.md](docs/HISTORY.md).

**Epiki follow-upów (snapshot 2026-05-23 — wszystkie zamknięte):**

| Epik | Tytuł | Status |
|---|---|---|
| HMAI-123 | Critical findings (C1–C12) | ✓ 12/12 (1.2.0) |
| HMAI-124 | Persistence & DB integrity | ✓ 9/9 (1.5.0) |
| HMAI-125 | Test coverage | ✓ 12/12 (1.4.0) |
| HMAI-126 | Operability & observability | ✓ 6/6 (1.6.0) |
| HMAI-127 | External API resilience | ✓ 14/14 (1.3.0) |
| HMAI-128 | Frontend hardening | ✓ 12/12 (1.7.1) |
| HMAI-129 | API hardening | ✓ 8/8 (1.8.0) |
| HMAI-130 | Rate limiting & throttling | ✓ 1/1 (1.3.0) |
| HMAI-131 | Domain model & DDD purity | ✓ 12/12 (1.9.0) |
| HMAI-132 | Features — exports | ✓ 1/1 (1.9.0) |

Detale każdego epika i highlights per release w [docs/HISTORY.md](docs/HISTORY.md).

## Architektura — ZASADY NIENARUSZALNE

- Hexagonal: `src/Module/{Name}/{Domain,Application,Infrastructure}/`
- Domain bez frameworka: `grep -r "use Doctrine" src/Module/*/Domain/` MUSI zwracać pusty wynik. Bramka CI: `make deptrac` (HMAI-146) — Domain → [] na poziomie tokenów, cross-module coupling zakazany
- Doctrine XML w `Infrastructure/Persistence/Doctrine/*.orm.xml` — NIE migrować na atrybuty PHP (ADR-001)
- Domain Events: agregat gromadzi w `$recordedEvents`, handler dispatchuje po `releaseEvents()`. Wzorzec: `Series` aggregate
- Query handlery: DBAL, NIE ORM (nie hydratuj agregatów do odczytu)
- Command handler: `#[AsMessageHandler(bus: 'command.bus')]`
- Query handler: `#[AsMessageHandler(bus: 'query.bus')]`
- Event handler: `#[AsMessageHandler]` bez `bus:` (default)
- `event.bus` skonfigurowany z `allow_no_handlers: true` — domain events to fire-and-forget, subscriber opcjonalny (HMAI-135)

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

- **Series UI:** Webpack Encore + Stimulus (HMAI-41, od 1.7.1). `assets/controllers/series_controller.js` jako Stimulus controller, mountowany przez `data-controller="series"` na `app/templates/series/index.html.twig`. Build: `make assets-prod` → `public/build/*.{js,css}` + `entrypoints.json` manifest. `base.html.twig` używa `{{ encore_entry_link_tags('app') }}` + `{{ encore_entry_script_tags('app') }}`.
- **Pozostałe moduły** (Tasks/Books/Articles/Music): Twig + vanilla JS w `public/js/*.js`, global helpers `window.TOAST_TIMEOUT_MS` / `window.safeUrl` / `window.apiCall` z `public/js/util.js`. Migracja do Encore odroczona (osobne tickety w HMAI-128 follow-up).
- Routes: `/` → redirect, `/series`, `/tasks`, `/books`, `/articles`, `/music`
- Selektor ocen Series: 10 przycisków (NIE `<input type=number>`)
- Tasks API: pełny REST CRUD (`POST/GET/GET{id}/PATCH{id}/DELETE{id} /api/tasks`, `POST {id}/complete`, `POST {id}/cancel`) + `/time-report` + `/export` (HMAI-135). Google Calendar sync via `CalendarServiceInterface` z graceful degrade
- Brakujący zakres frontu (Jira): HMAI-43 (UI dla nowego PATCH episode rating endpointu — backend kompletny)

### Webpack Encore (HMAI-41)

| Plik | Rola |
|---|---|
| `app/webpack.config.js` | Encore config — entry `app`, splitEntryChunks, Stimulus bridge, versioning prod-only |
| `app/assets/app.js` | Główny entry — importuje `bootstrap.js` (Stimulus) + `styles/app.css` |
| `app/assets/bootstrap.js` | `startStimulusApp` auto-discovery z `controllers/` |
| `app/assets/util.js` | ES module export: `TOAST_TIMEOUT_MS`, `safeUrl`, `apiCall`, `escHtml` (źródło dla Encore-side; vanilla side wciąż używa `public/js/util.js`) |
| `app/assets/controllers/series_controller.js` | Stimulus controller dla Series UI |
| `app/assets/styles/app.css` | Globalny stylesheet (jedyne źródło prawdy od 1.7.1; `public/css/app.css` jeszcze zostaje dla vanilla pages — usunąć po migracji wszystkich modułów) |

Komendy: `make assets` (dev), `make assets-watch` (watch mode), `make assets-prod` (production). Node service: `aihm-node-1` (`node:24-alpine`, mount na `./app`). `make node-install` reinstaluje `npm install` po zmianie `package.json`.

`public/build/` + `node_modules/` w `.gitignore`. CI buduje assets w jobach `tests` i `e2e-playwright` (`npm ci && npm run build` w `app/`) przed PHPUnit/Playwright — bez tego Twig `encore_entry_*` wywala 500.

## Infrastruktura

| Serwis | Kontener / Port | Notatki |
|---|---|---|
| MySQL 8 | `mysql:3306` | DB `homemanager` |
| Redis 7 | `redis:6379` | Klucze `series:avg:{id}`, `season:avg:{id}` (TTL 3600) ustawiane bezpośrednio przez `\Redis` w `EpisodeRatedHandler` (nie przez Symfony cache pool — handler iniektuje `\Redis`, nie `CacheItemPoolInterface`). Pool `cache.rate_limiter` używany przez RateLimiter |
| RabbitMQ 3.12 | `rabbitmq:5672` (AMQP), `:15672` UI (guest/guest) | Transport `async`, exchange `series_events` (topic), retry 3× (1s→2s→4s, max 30s), DLQ `failed` |
| Worker Messenger | `messenger_worker` | `messenger:consume async --time-limit=3600 -vv` |
| Worker Scheduler | `scheduler_worker` | `messenger:consume scheduler_default --time-limit=3600 -vv` (HMAI-35) |
| Node (Encore build) | `node:24-alpine`, container `aihm-node-1` | Long-running `tail -f /dev/null`. `docker compose exec node npm ...` (HMAI-41) |
| Graylog 5.2 | profil `monitoring`, UI `:9000` (admin/admin), GELF UDP `:12201` | NIE w `make up` — `make monitoring-up`. Kanał Monolog `series` |

W testach: transport `async` i `failed` → `in-memory://` (`when@test` w `messenger.yaml`).

Async messages routowane do `async` transportu: `Series\Domain\Event\EpisodeRated`, `Music\Application\Command\RefreshDiscogsCollection` (HMAI-56 — fetch kolekcji Discogs offloaded z requestu, endpoint `/api/music/collection` zwraca cache + dispatcha refresh przy miss), `Music\Application\Command\PollLastFmRecentTracks` (HMAI-144 — scheduler poll co 30 min, handler dispatchuje `LogListeningSession` per track na sync command.bus). `Books\Domain\Event\BookCompleted` świadomie sync (in-memory) — brak handlera, brak I/O side-effects (ADR-006, HMAI-141). Pinned przez `BookCompletedRoutingTest`.

`NewRelicMonologHandler` (`src/Module/Series/Infrastructure/Logging/`) — graceful degrade gdy brak rozszerzenia `newrelic`.

GELF UDP input + index sets + streams: `make monitoring-bootstrap` (idempotentny skrypt `scripts/graylog-bootstrap.sh`, HMAI-142). Tworzy GELF UDP input, index sets `auth-events` (90 dni, time-based) i `series-events` (30 dni, time-based) z odpowiadającymi stream'ami filtrującymi po `channel`. Wymaga działającego Graylog (`make monitoring-up` najpierw).

## Symfony Scheduler (HMAI-35)

`src/Schedule.php` rejestruje 5 zadań cyklicznych (via `dragonmantank/cron-expression`):

| Cron | Wiadomość | Efekt |
|---|---|---|
| `0 0 * * *` | `Articles\...\ResetDailyArticleCache` | Usuwa Redis `articles:today`, kasuje `article_daily_picks` > 7 dni |
| `0 3 * * *` | `App\Application\Scheduled\BackupDatabase` | mysqldump + gzip → `/backups/homemanager-YYYY-MM-DD.sql.gz`, retention 30 daily + 12 monthly (HMAI-136) |
| `0 8 * * 1` | `App\Application\Scheduled\GenerateWeeklyActivityReport` | Loguje `scheduled_task=weekly_report` do default channel (read_articles, pages_read, completed_tasks, rated_episodes_total) |
| `0 */6 * * *` | `Music\...\RefreshDiscogsCollection` | Pre-warm cache kolekcji przed wygaśnięciem 6h TTL |
| `*/30 * * * *` | `Music\...\PollLastFmRecentTracks` | Poll Last.fm recent tracks → lokalna historia odsłuchów (HMAI-144), idempotentny przez `dedup_hash` |

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
| Backup MySQL (ręczny) | `make backup-now` |
| Restore MySQL | `make restore BACKUP=backups/homemanager-YYYY-MM-DD.sql.gz` |

## Testy

- Unit: `tests/Unit/Module/{Name}/Domain/` — wzorzec `tests/Unit/Module/Series/Domain/SeriesAggregateTest.php`
- Integration: `tests/Integration/`
- Fixtures: `tests/Integration/DataFixtures/FixturesLoadTest.php` — sprawdza, że `make fixtures` daje stałą strukturę danych (HMAI-39)
- E2E: `tests-e2e/` (Playwright, TypeScript). Files match `*.desktop.spec.ts` (1440×900) lub `*.mobile.spec.ts` (Pixel 5 viewport) per project config w `playwright.config.ts`
- Newman/Postman: `tests-e2e/postman/AIHomeManager.postman_collection.json` (HMAI-33 — 34 req / 66 assertions). Uruchamiać przez `make test-newman` (truncate + newman z `--ignore-redirects`); details w `tests-e2e/postman/README.md`
- Framework: PHPUnit 13 + @playwright/test 1.49 + newman 6.x
- Stan: 630/630 PHP passing + 5/5 Playwright + 36/36 Newman requests (HMAI-144 Music ListeningSession aggregate, 2026-05-28)
- Testy `*ApiTest` używają `App\Tests\Support\AuthenticatedApiTrait` — dodaje header `X-API-Key: test-api-key` (zob. `app/.env.test`)
- E2E/Newman pre-req: `API_KEY=e2e-test-key` w `app/.env.local`, Discogs/Last.fm placeholders (`DISCOGS_TOKEN_KEY`, `GOOGLE_TOKEN_KEY`, `DISCOGS_CONSUMER_KEY`, `DISCOGS_CONSUMER_SECRET`, `LASTFM_API_KEY`, `LASTFM_USERNAME`, `DISCOGS_USERNAME`) ustawione na cokolwiek niepuste (DI nie zboot'uje się z pustymi VO). Graylog GELF UDP input musi być skonfigurowany (`make monitoring-up` + POST do `/api/system/inputs` z `org.graylog2.inputs.gelf.udp.GELFUDPInput` na `0.0.0.0:12201`), inaczej `series` kanał Monologu wywala 500 na `/api/series` — **dotyczy tylko env `dev`/`prod`**. W CI (HMAI-140) joby E2E/Newman lecą z `APP_ENV=test`, gdzie `monolog when@test` kieruje kanały `series`/`auth` na handlery `null` → Graylog niepotrzebny. Klucze `*_TOKEN_KEY` w CI to **poprawny base64 32B** (`TokenCipher` rzuca dla innej długości — OAuth-init request inaczej zwróci 500 zamiast 302/502). App server w CI: `symfony server:start --no-tls --port=8080` (serwuje routing + statyczne assety Encore; gołe `php -S` tego nie łączy)

## Security — API Key

- `^/api/*` chronione firewall'em `api` w `app/config/packages/security.yaml` (stateless, custom authenticator)
- Authenticator: `App\Security\ApiKeyAuthenticator` — czyta header `X-API-Key`, porównuje przez `hash_equals` z `%env(API_KEY)%`. `supports()` zwraca `false` dla `/api/health` (HMAI-37) — publiczny readiness probe dla orchestratorów.
- 401 JSON `{"error": "..."}` przy braku/błędnym kluczu
- Klucz produkcyjny w `app/.env.local` (gitignored). `app/.env` ma tylko placeholder
- `/auth/google*`, `/auth/discogs*`, frontend (`/`, `/series` itd.) — firewall `main` z `security: false` (publiczne)
- Test env: `API_KEY=test-api-key` w `app/.env.test`
- **CSRF (HMAI-57, ADR-005):** świadomie **nie używamy** `#[IsCsrfTokenValid]` na `^/api/*`. Firewall jest `stateless: true`, autoryzacja przez header `X-API-Key` (nie cookie) — przeglądarka nie ustawia custom headerów cross-origin, więc CSRF nie ma drogi. OAuth init (`/auth/*`) używa parametru `state` (HMAI-52/53). Rationale + plan migracji w [ADR-005](https://honemanager.atlassian.net/wiki/spaces/H/pages/64225282) + `docs/HMAI-57.md`; regresja w `tests/Integration/Security/ApiKeyAuthCsrfTest.php`.

## HTTP security headers (HMAI-137)

Dual-layer defense-in-depth: nginx (`docker/nginx/default.conf`) + Symfony `SecurityHeadersListener` (`kernel.response`, priority -128). Oba ustawiają te same 4 headery na każdą odpowiedź:

| Header | Wartość |
|---|---|
| `X-Content-Type-Options` | `nosniff` |
| `X-Frame-Options` | `DENY` |
| `Referrer-Policy` | `strict-origin-when-cross-origin` |
| `Permissions-Policy` | `geolocation=(), microphone=(), camera=(), payment=()` |

HSTS (`Strict-Transport-Security`) zakomentowane w nginx — odkomentować PO konfiguracji HTTPS. `server_tokens off` ukrywa wersję nginx.

Regresja: `tests/Integration/Security/SecurityHeadersTest.php` (4 testy: frontend, API, error 404, all headers).

## API exception listener (HMAI-79)

- `App\EventListener\ApiExceptionListener` — `kernel.exception` (priority 64, przed framework `ErrorListener` na -64). Konwertuje uncaught throwables na `^/api/*` na `JsonResponse`.
- `HttpExceptionInterface` (4xx) zachowuje status i message; pozostałe (`RuntimeException`, `DomainException` poza catch w kontrolerze, itp.) → 500 z generycznym `Internal server error.` (oryginalny message tylko w logu, nie w odpowiedzi).
- `HandlerFailedException` (Messenger wrap) jest rozpakowywany — listener używa previous exception do type-checków, więc HTTP exceptions z handlerów łapią się tak samo jak rzucone bezpośrednio.
- Non-API paths (np. `/series`, `/typo`) przechodzą bez zmian — Twig frontend zachowuje swoje renderowane strony błędu.
- Pełny exception context (path, method, status, exception) loguje się na poziomie `error` przez default channel.

## Health endpoint (HMAI-37)

- `GET /api/health` — publiczny readiness probe (bez `X-API-Key`)
- Probe'y: MySQL (`SELECT 1`), Redis (`PING`), RabbitMQ (TCP do hosta z `MESSENGER_TRANSPORT_DSN`, timeout 1s)
- 200 `{"status":"healthy", "components":{"mysql":"up", "redis":"up", "rabbitmq":"up"}, "timestamp":"..."}` gdy wszystko up
- 503 `"status":"unhealthy"` + komponent `"down"` gdy któryś probe pada — orchestratorzy nie kierują traffic do degraded instancji
- Docker healthcheck na `nginx`: `wget --spider http://localhost/api/health` (interval 30s, retries 3, start_period 30s) — end-to-end stack probe
- `HealthChecker` (`src/Health/HealthChecker.php`) — `readonly` (NIE `final` żeby PHPUnit `createStub` działał w teście kontrolera)

## Static Analysis (HMAI-40, HMAI-146)

- **PHPStan** level 8 + `phpstan-symfony` + `phpstan-doctrine` + `phpstan-phpunit`. Config: `app/phpstan.neon.dist`. Baseline (182 errors): `app/phpstan-baseline.neon` — celowo, by nie blokować mergy istniejącego długu; nowe błędy wymagają fixu lub rozszerzenia baseline'u przez `make phpstan-baseline`
- **PHP CS Fixer**: `@Symfony` + `@PHP84Migration` + `global_namespace_import` (klasy importowane). Config: `app/.php-cs-fixer.dist.php`
- **Rector**: `withPhpSets()` + `deadCode` (49 plików zmienionych przy starcie). Config: `app/rector.php`
- **Deptrac** (HMAI-146): formalizuje granice heksagonalne — każdy moduł ma osobne layery `*Domain` / `*Application` / `*Infrastructure`. Domain → [] (zero zależności poza PHP core), Application → własny Domain + Vendor, Infrastructure → własny Domain + własna Application + Vendor, `Glue` (Controllers/EventListeners/Kernel/Security poza `src/Module/`) → wszystko. Cross-module coupling zakazany. Config: `app/deptrac.yaml` ze scalonym `skip_violations` (6 pre-existing — Domain ports zwracające Application DTOs w Books/Music, Music/Tasks Infrastructure → `App\Security\TokenCipher`). Regeneracja: `make deptrac-baseline` → przenieść `skip_violations` z `deptrac-baseline.yaml` do `deptrac.yaml` i usunąć osobny plik (single source of truth)
- CI: `.github/workflows/ci.yml` — 4 joby na każdy push/PR: `static-analysis` (Rector dry-run + CS Fixer + PHPStan level 8 + Deptrac), `tests` (PHPUnit), `e2e-playwright` i `e2e-newman` (oba `needs: tests`, HMAI-140)

| Komenda | Akcja |
|---|---|
| `make analyse` | CS Fixer (dry-run) + PHPStan + Deptrac |
| `make phpstan` | PHPStan analyse |
| `make phpstan-baseline` | Regeneruj baseline (po naprawie błędów) |
| `make cs-check` / `cs-fix` | CS Fixer dry-run / apply |
| `make rector-dry` / `rector` | Rector dry-run / apply |
| `make deptrac` | Deptrac analyse (architecture boundaries) |
| `make deptrac-baseline` | Regeneruj baseline deptrac |

## Rate limiting — own API + external APIs (HMAI-38)

- `App\EventListener\ApiRateLimitListener` — `kernel.request` (priority 100, przed routerem/firewall'em). Per-IP throttle dla `^/api/*` (limiter `api_per_ip`, sliding_window 60/min). Bypass: `/api/health` i `/auth/*`. 429 zwraca `Retry-After`, `X-RateLimit-Remaining`, `X-RateLimit-Limit`. Loguje `rate_limit_triggered=true` (warning).
- `App\Http\RateLimitedHttpClient` — dekorator `HttpClientInterface` proaktywnie blokuje request przed wywołaniem zewnętrznego API (`reservation->wait()`). Trzy instancje w `services.yaml`: `app.discogs_http_client`, `app.lastfm_http_client`, `app.national_library_http_client` — wstrzykiwane do odpowiednich klientów Music/Books.
- Limitery (`app/config/packages/rate_limiter.yaml`): `api_per_ip` (sliding_window, 60/min), `discogs_api` (token_bucket, 60/min), `lastfm_api` (token_bucket, 5/s), `national_library_api` (token_bucket, 60/min)
- Storage: pool `cache.rate_limiter` (Redis) w prod/dev. W testach `Symfony\Component\RateLimiter\Storage\InMemoryStorage` — nietagged `kernel.reset`, więc stan przeżywa request → request gdy `KernelBrowser::disableReboot()`. External limiters w teście policy `no_limit`
- Distributed lock: `LOCK_DSN=redis://redis:6379` (`.env`) — koordynacja web ↔ worker
- `DiscogsApiClient::fetchAllPages` — `sleep(1)` usunięte, throttling teraz robi `RateLimitedHttpClient`
- Wyjątki/granice: `/auth/*` poza `^/api/*` więc listener nie dotyka; `/api/health` jawnie wykluczone (route nie istnieje, ale przygotowane na przyszłość); Google Calendar SDK używa własnego klienta HTTP (nie Symfony) i NIE jest objęty dekoratorem — limit 1M/dobę zostawia spory margines

## Encryption — OAuth tokens (HMAI-46, HMAI-47)

- `App\Security\TokenCipher` (libsodium `secretbox`, format: base64(nonce ‖ ciphertext)) — wspólne narzędzie dla wszystkich OAuth providerów
- Dwie instancje w `services.yaml`: `app.discogs_token_cipher` (klucz `DISCOGS_TOKEN_KEY`) i `app.google_token_cipher` (`GOOGLE_TOKEN_KEY`) — osobne klucze rozdzielają blast radius
- Klucze 32B base64 w `.env.local`. Generate: `php -r "echo base64_encode(sodium_crypto_secretbox_keygen());"`
- Discogs OAuth1: `DiscogsTokenRepository` (Music) — pole-per-pole encryption (`oauth_token`, `oauth_token_secret`)
- Google OAuth2: `GoogleOAuthTokenRepository` (Tasks) — szyfruje cały `token_json` (access+refresh+expires)
- Migracje TRUNCATE: `Version20260502000001` (Discogs) i `Version20260503000001` (Google) — wymagają re-auth przez `/auth/discogs` i `/auth/google`

## MCP servers (`.mcp.json`)

- `sequential-thinking` (npx)
- `github` (npx — wymaga `GITHUB_PERSONAL_ACCESS_TOKEN`; aktualnie zwraca "Bad credentials" przy próbach create_pull_request — odnowić PAT lub używać `gh` CLI / Web UI)
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
7. Po większym kroku (zamknięty ticket / epic review / release) **zaproponuj** userowi `/compact` — nie wykonuj automatycznie. Sygnał: kolejny krok rozpocznie nowy scope (next ticket, post-release, kolejne PR-y).

## Linki

- Confluence hub: https://honemanager.atlassian.net/wiki/spaces/H/pages/46661633
- Code review HMAI-44: https://honemanager.atlassian.net/wiki/spaces/H/pages/52658177
- Jira board: https://honemanager.atlassian.net/jira/software/projects/HMAI/boards
- Repo: `zlotylesk/AIHomeManager` (GitHub)
