# AIHomeManager — Claude Code Context

Single-user system automatyzacji codziennych czynności. Stack: PHP 8.4 + Symfony 8 + MySQL 8 + Redis 7 + RabbitMQ 3.12. Heksagonalna architektura, CQRS z dwoma busami. Wszystkie moduły zaimplementowane (HMAI-1—HMAI-30).

**Moduły:** Series, Tasks, Books, Articles, Music. Frontend: Twig + vanilla JS w `templates/` i `public/`.

**Status code review (HMAI-44, 2026-05-01):** 78 follow-up tasków w Jira (HMAI-45—HMAI-122, label `ai_code_review`, priority Highest). P0 blockers przed prod: ~~brak `security.yaml`~~ (HMAI-34, 2026-05-01), ~~plaintext OAuth tokens~~ (Discogs HMAI-46 / Google HMAI-47, 2026-05-02—03), ~~HTTP w Last.fm~~ (HMAI-48, 2026-05-02), ~~`unserialize()` z Redis~~ (HMAI-49 MusicComparison, HMAI-50 LastFm/Discogs/NationalLibrary, 2026-05-04—05), ~~XSS via `javascript:` w ArticleUrl~~ (HMAI-55, 2026-05-05), ~~dual-write w `LogReadingSessionHandler`~~ (HMAI-51, 2026-05-06), ~~brak walidacji `state` w Google OAuth callback~~ (HMAI-52, 2026-05-06). Pełny raport: `docs/code-review/HMAI-44-app-review.md`. Confluence: page id 52658177.

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

## Testy

- Unit: `tests/Unit/Module/{Name}/Domain/` — wzorzec `tests/Unit/Module/Series/Domain/SeriesAggregateTest.php`
- Integration: `tests/Integration/`
- Framework: PHPUnit 13
- Stan: 225/225 passing (HMAI-34)
- Testy `*ApiTest` używają `App\Tests\Support\AuthenticatedApiTrait` — dodaje header `X-API-Key: test-api-key` (zob. `app/.env.test`)

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
