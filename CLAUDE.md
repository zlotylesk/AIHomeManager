# AIHomeManager — Claude Code Context

Single-user system automatyzacji codziennych czynności. Stack: PHP 8.4 + Symfony 8 + MySQL 8 + Redis 7 + RabbitMQ 3.12. Heksagonalna architektura, CQRS z dwoma busami. Wszystkie moduły zaimplementowane (HMAI-1—HMAI-30).

**Moduły:** Series, Tasks, Books, Articles, Music. Frontend: Twig + vanilla JS w `templates/` i `public/`.

**Status code review (HMAI-44, 2026-05-01; restruktura epików 2026-05-07; refresh 2026-05-16):** 59 otwartych follow-upów (label `ai_code_review`). Wszystkie P0 blockers przed prod zamknięte: ~~brak `security.yaml`~~ (HMAI-34), ~~plaintext OAuth tokens~~ (HMAI-46/47), ~~HTTP w Last.fm~~ (HMAI-48), ~~`unserialize()` z Redis~~ (HMAI-49/50), ~~XSS via `javascript:` w ArticleUrl~~ (HMAI-55), ~~dual-write w `LogReadingSessionHandler`~~ (HMAI-51), ~~brak walidacji `state` w OAuth callback~~ (HMAI-52/53), ~~blokujący `sleep(1)` w Discogs collection fetch~~ (HMAI-56). Pełny raport: `docs/code-review/HMAI-44-app-review.md`. Confluence: page id 52658177.

**Wydania:** ostatni tag `1.4.0` (2026-05-16) zamykający epic **HMAI-125** Test coverage (12/12 podzadań + ReadingSession audit). 408/408 PHP + 5/5 Playwright + 28 Newman requests. Pełny CHANGELOG: `CHANGELOG.md`. Poprzednie: 1.3.0 (HMAI-127 + HMAI-130, 2026-05-16), 1.2.0 (HMAI-123 Critical findings, 2026-05-07).

**Epiki follow-upów (counts: 2026-05-16):**

| Epik | Tytuł | Otwarte |
|---|---|---:|
| [HMAI-123](https://honemanager.atlassian.net/browse/HMAI-123) | Critical findings (C1–C12) — epik zamknięty | — |
| [HMAI-124](https://honemanager.atlassian.net/browse/HMAI-124) | Persistence & DB integrity — DBAL, ORM, indexes, transactions | 9 |
| [HMAI-125](https://honemanager.atlassian.net/browse/HMAI-125) | Test coverage — epik zamknięty po przeglądzie 2026-05-16 (12/12 podzadań + ReadingSession unit test domknął lukę po BookAggregateTest) | — |
| [HMAI-126](https://honemanager.atlassian.net/browse/HMAI-126) | Operability & observability — health, scheduler, fixtures, audit logs, metrics | 6 |
| [HMAI-127](https://honemanager.atlassian.net/browse/HMAI-127) | External API clients — resilience, error handling, OAuth refresh — epik zamknięty po przeglądzie 2026-05-16 (14/14 podzadań, hub patterns Confluence id 59441164) | — |
| [HMAI-128](https://honemanager.atlassian.net/browse/HMAI-128) | Frontend hardening — JS quality, CSP/SRI, build pipeline | 12 |
| [HMAI-129](https://honemanager.atlassian.net/browse/HMAI-129) | API hardening — input validation, error contracts, CSRF | 8 |
| [HMAI-130](https://honemanager.atlassian.net/browse/HMAI-130) | Rate limiting & throttling — epik zamknięty po przeglądzie 2026-05-10 (HMAI-38 + dopełniające testy per-IP isolation, logger spy, DI wiring) | — |
| [HMAI-131](https://honemanager.atlassian.net/browse/HMAI-131) | Domain model & DDD purity — invariants, equals(), event emission | 11 |
| [HMAI-132](https://honemanager.atlassian.net/browse/HMAI-132) | Features — exports (CSV/PDF) and missing endpoints | 1 |

**Ostatnio zamknięte (2026-05-08 → 2026-05-16):** HMAI-38 rate limiting, HMAI-62 narrow exception catches, HMAI-63 Discogs HTTP error codes, HMAI-64 OAuth refresh, HMAI-80 AlbumNormalizer regex logging, HMAI-81 ArticleImporter explicit encoding, HMAI-84 Last.fm whitespace key, HMAI-90 GoogleClientFactory ctor validation, HMAI-96 NationalLibrary XXE protection, HMAI-105 Discogs OAuth status check, HMAI-106 Google OAuth init try-catch, HMAI-113 Discogs credentials VO, HMAI-114 Discogs clock drift detector, HMAI-121 README, HMAI-123 + HMAI-127 + HMAI-130 epic closures, HMAI-125 batch (test coverage, 10/12 zadań — HMAI-73, 74, 76, 82, 93, 94, 95, 97, 99, 116), HMAI-42 Playwright Series E2E, HMAI-33 Newman/Postman collection (12/12), HMAI-125 epic closure (review + ReadingSession unit test).

## Architektura — ZASADY NIENARUSZALNE

- Hexagonal: `src/Module/{Name}/{Domain,Application,Infrastructure}/`
- Domain bez frameworka: `grep -r "use Doctrine" src/Module/*/Domain/` MUSI zwracać pusty wynik
- Doctrine XML w `Infrastructure/Persistence/Doctrine/*.orm.xml` — NIE migrować na atrybuty PHP (ADR-001)
- Domain Events: agregat gromadzi w `$recordedEvents`, handler dispatchuje po `releaseEvents()`. Wzorzec: `Series` aggregate
- Query handlery: DBAL, NIE ORM (nie hydratuj agregatów do odczytu)
- Command handler: `#[AsMessageHandler(bus: 'command.bus')]`
- Query handler: `#[AsMessageHandler(bus: 'query.bus')]`
- Event handler: `#[AsMessageHandler]` bez `bus:` (default)

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

- Twig + vanilla JS, **bez Webpack/Node.js**
- Routes: `/` → redirect, `/series`, `/tasks`, `/books`, `/articles`, `/music`
- Selektor ocen Series: 10 przycisków (NIE `<input type=number>`)
- Tasks UI = tylko `/api/tasks/time-report` (brak create/list endpointów)
- Brakujący zakres frontu (Jira): HMAI-41 (Webpack Encore + Stimulus), HMAI-42 (E2E), HMAI-43 (PATCH rating endpoint)

## Infrastruktura

| Serwis | Kontener / Port | Notatki |
|---|---|---|
| MySQL 8 | `mysql:3306` | DB `homemanager` |
| Redis 7 | `redis:6379` | Pool `series.ratings.cache` (TTL 3600); klucze `series:avg:{id}`, `season:avg:{id}` ustawiane przez `EpisodeRatedHandler` |
| RabbitMQ 3.12 | `rabbitmq:5672` (AMQP), `:15672` UI (guest/guest) | Transport `async`, exchange `series_events` (topic), retry 3× (1s→2s→4s, max 30s), DLQ `failed` |
| Worker Messenger | `messenger_worker` | `messenger:consume async --time-limit=3600 -vv` |
| Graylog 5.2 | profil `monitoring`, UI `:9000` (admin/admin), GELF UDP `:12201` | NIE w `make up` — `make monitoring-up`. Kanał Monolog `series` |

W testach: transport `async` i `failed` → `in-memory://` (`when@test` w `messenger.yaml`).

Async messages routowane do `async` transportu: `Series\Domain\Event\EpisodeRated`, `Music\Application\Command\RefreshDiscogsCollection` (HMAI-56 — fetch kolekcji Discogs offloaded z requestu, endpoint `/api/music/collection` zwraca cache + dispatcha refresh przy miss).

`NewRelicMonologHandler` (`src/Module/Series/Infrastructure/Logging/`) — graceful degrade gdy brak rozszerzenia `newrelic`.

GELF UDP input w Graylog: konfigurować ręcznie po pierwszym `make monitoring-up` (System → Inputs → GELF UDP → Launch).

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
| E2E (Playwright) install/run | `make test-e2e-install` / `make test-e2e` |
| Newman (Postman REST collection) | `make test-newman-install` / `make test-newman` |

## Testy

- Unit: `tests/Unit/Module/{Name}/Domain/` — wzorzec `tests/Unit/Module/Series/Domain/SeriesAggregateTest.php`
- Integration: `tests/Integration/`
- E2E: `tests-e2e/` (Playwright, TypeScript). Files match `*.desktop.spec.ts` (1440×900) lub `*.mobile.spec.ts` (Pixel 5 viewport) per project config w `playwright.config.ts`
- Newman/Postman: `tests-e2e/postman/AIHomeManager.postman_collection.json` (HMAI-33 — 28 req / 42 assertions / 100% green). Uruchamiać przez `make test-newman` (truncate + newman z `--ignore-redirects`); details w `tests-e2e/postman/README.md`
- Framework: PHPUnit 13 + @playwright/test 1.49 + newman 6.x
- Stan: 408/408 PHP passing + 5/5 Playwright + 28/28 Newman requests (HMAI-42 + HMAI-33 + HMAI-125 epic close, 2026-05-16)
- Testy `*ApiTest` używają `App\Tests\Support\AuthenticatedApiTrait` — dodaje header `X-API-Key: test-api-key` (zob. `app/.env.test`)
- E2E/Newman pre-req: `API_KEY=e2e-test-key` w `app/.env.local`, Discogs/Last.fm placeholders (`DISCOGS_TOKEN_KEY`, `GOOGLE_TOKEN_KEY`, `DISCOGS_CONSUMER_KEY`, `DISCOGS_CONSUMER_SECRET`, `LASTFM_API_KEY`, `LASTFM_USERNAME`, `DISCOGS_USERNAME`) ustawione na cokolwiek niepuste (DI nie zboot'uje się z pustymi VO). Graylog GELF UDP input musi być skonfigurowany (`make monitoring-up` + POST do `/api/system/inputs` z `org.graylog2.inputs.gelf.udp.GELFUDPInput` na `0.0.0.0:12201`), inaczej `series` kanał Monologu wywala 500 na `/api/series`

## Security — API Key

- `^/api/*` chronione firewall'em `api` w `app/config/packages/security.yaml` (stateless, custom authenticator)
- Authenticator: `App\Security\ApiKeyAuthenticator` — czyta header `X-API-Key`, porównuje przez `hash_equals` z `%env(API_KEY)%`
- 401 JSON `{"error": "..."}` przy braku/błędnym kluczu
- Klucz produkcyjny w `app/.env.local` (gitignored). `app/.env` ma tylko placeholder
- `/auth/google*`, `/auth/discogs*`, frontend (`/`, `/series` itd.) — firewall `main` z `security: false` (publiczne)
- Test env: `API_KEY=test-api-key` w `app/.env.test`

## Static Analysis (HMAI-40)

- **PHPStan** level 8 + `phpstan-symfony` + `phpstan-doctrine` + `phpstan-phpunit`. Config: `app/phpstan.neon.dist`. Baseline (182 errors): `app/phpstan-baseline.neon` — celowo, by nie blokować mergy istniejącego długu; nowe błędy wymagają fixu lub rozszerzenia baseline'u przez `make phpstan-baseline`
- **PHP CS Fixer**: `@Symfony` + `@PHP84Migration` + `global_namespace_import` (klasy importowane). Config: `app/.php-cs-fixer.dist.php`
- **Rector**: `withPhpSets()` + `deadCode` (49 plików zmienionych przy starcie). Config: `app/rector.php`
- CI: `.github/workflows/static-analysis.yml` uruchamia CS Fixer + PHPStan na każdy push/PR

| Komenda | Akcja |
|---|---|
| `make analyse` | CS Fixer (dry-run) + PHPStan |
| `make phpstan` | PHPStan analyse |
| `make phpstan-baseline` | Regeneruj baseline (po naprawie błędów) |
| `make cs-check` / `cs-fix` | CS Fixer dry-run / apply |
| `make rector-dry` / `rector` | Rector dry-run / apply |

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

## Linki

- Confluence hub: https://honemanager.atlassian.net/wiki/spaces/H/pages/46661633
- Code review HMAI-44: https://honemanager.atlassian.net/wiki/spaces/H/pages/52658177
- Jira board: https://honemanager.atlassian.net/jira/software/projects/HMAI/boards
- Repo: `zlotylesk/AIHomeManager` (GitHub)
