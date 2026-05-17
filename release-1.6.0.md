# Release 1.6.0 — Operability & observability

**Data:** 2026-05-17
**Epic:** [HMAI-126](https://honemanager.atlassian.net/browse/HMAI-126) — Operability & observability
**Baza:** develop @ `4952b2d` (1.5.0)

## Wykonane zadania

Wszystkie 7 ticketów z `fixVersion = 1.6.0` zamknięte. PR-y mergowane do `develop` w kolejności narrow → wide, każdy z self-contained scope (composer.lock konflikty rozwiązywane po stronie merge'ującego).

| # | Klucz | Tytuł | Priorytet | PR | Tests | Worklog |
|---|---|---|---|---|---:|---:|
| 1 | [HMAI-133](https://honemanager.atlassian.net/browse/HMAI-133) | messenger_worker crashloop — brak symfony/amqp-messenger | High | — (już w `f52e33dd`) | — | 15m |
| 2 | [HMAI-107](https://honemanager.atlassian.net/browse/HMAI-107) | OAuth audit log | Low | [#100](https://github.com/zlotylesk/AIHomeManager/pull/100) | +10 unit | 45m |
| 3 | [HMAI-112](https://honemanager.atlassian.net/browse/HMAI-112) | API duration metrics (Last.fm, Discogs) | Low | [#101](https://github.com/zlotylesk/AIHomeManager/pull/101) | +4 unit | 45m |
| 4 | [HMAI-37](https://honemanager.atlassian.net/browse/HMAI-37) | Endpoint /api/health | Medium | [#102](https://github.com/zlotylesk/AIHomeManager/pull/102) | +8 (5 unit + 2 unit + 1 integration) | 1h 15m |
| 5 | [HMAI-39](https://honemanager.atlassian.net/browse/HMAI-39) | Doctrine Fixtures | Medium | [#103](https://github.com/zlotylesk/AIHomeManager/pull/103) | +4 integration | 1h 15m |
| 6 | [HMAI-35](https://honemanager.atlassian.net/browse/HMAI-35) | Symfony Scheduler | Medium | [#104](https://github.com/zlotylesk/AIHomeManager/pull/104) | +4 unit | 1h 30m |
| 7 | [HMAI-126](https://honemanager.atlassian.net/browse/HMAI-126) | Operability & observability — **epic review** | Medium | (this commit) | — | 30m |

**Łączny czas:** **6h 15m** (rounded 15min per ticket).

## Streszczenie zmian

### HMAI-133 — `symfony/amqp-messenger` w composer
Bez commitu — pakiet już w `composer.json/lock` od `f52e33dd` (HMAI-42 Playwright Series E2E, 2026-05-16). `docker compose ps` → `aihm-messenger_worker-1` Up; logi: `[OK] Consuming messages from transport "async".` Ticket zamknięty jako verified.

### HMAI-107 — OAuth audit log na kanale `auth`
Nowy Monolog channel `auth` (`monolog.yaml`: dev/prod → gelf info, prod również stderr JSON, test → null). `GoogleAuthController` i `DiscogsAuthController` używają `monolog.logger.auth` przez `#[Autowire]`. Eventy:
- `info('OAuth authorize initiated', ['provider' => 'google'|'discogs'])` przy każdej próbie autoryzacji.
- `info('OAuth callback success', ['provider' => ...])` po `tokenRepository->save` (after-save = zero false positives).
- `warning('OAuth callback failed', ['provider' => ..., 'reason' => ...])` z reason `invalid_state`, `missing_code`, `missing_params`, `token_exchange`, `empty_token`.

### HMAI-112 — Duration metrics dla Last.fm/Discogs
Wrap `microtime(true)` wokół każdego `httpClient->request(...)` w `LastFmApiClient` i `DiscogsApiClient`. Emit `info('External API call', ['provider', 'endpoint', 'duration_ms', 'status', 'error?'])` na kanał `music`. Failure tagged `error=transport_error | client_error | transport_or_server_error` — 429 spike distinguishable od transport outage. Logger via `#[Autowire(service: 'monolog.logger.music')]` z `NullLogger` default — zero breaking change w istniejących testach.

### HMAI-37 — `GET /api/health`
Publiczny readiness probe (bypass firewall w `ApiKeyAuthenticator::supports`). `HealthChecker` probuje:
- **MySQL:** `Connection::executeQuery('SELECT 1')`.
- **Redis:** `\Redis::ping()` (string `+PONG` / bool `true`).
- **RabbitMQ:** `fsockopen($host, $port, …, 1.0)` na host:port z `MESSENGER_TRANSPORT_DSN`.

200 + `{"status":"healthy", "components":{...}, "timestamp":"..."}` lub 503 + `"unhealthy"`. Każda probe w `try/catch Throwable` — żaden komponent nie crashuje response. Docker healthcheck na `nginx` (`wget --quiet --spider http://localhost/api/health`, `start_period=30s`) — end-to-end stack probe.

Live verified: `curl -i http://localhost:8080/api/health` → 200 z wszystkimi komponentami up.

### HMAI-39 — Doctrine Fixtures
`doctrine/doctrine-fixtures-bundle` (dev+test). 4 klasy w `app/src/DataFixtures/`:
- `SeriesFixtures` — 3 seriale × 2 sezony × 5 ocenianych odcinków (ratings 6–9).
- `BookFixtures` — 5 książek pokrywających każdy `BookStatus` (to_read / reading×2 / completed×2). Progress driven via `Book::addReadingSession`.
- `ArticleFixtures` — 10 artykułów w 4 kategoriach, 3 read — `/api/articles/today` znajduje kandydata natychmiast.
- `TaskFixtures` — 4 taski (3 today + 1 yesterday) dla `/api/tasks/time-report`.

Wszystkie routed przez domain repositories — invariants agregatów respektowane (np. `BookStatus::COMPLETED` wymaga `pages_read == total_pages`). Nowy `make fixtures` target + `app/fixtures/sample-articles.csv` dla CSV import path.

Live verified: `make fixtures` → 5 książek z poprawnymi statusami w `GET /api/books`.

### HMAI-35 — Symfony Scheduler
`symfony/scheduler` + `dragonmantank/cron-expression`. `src/Schedule.php` (`#[AsSchedule]`, `final readonly`) deklaruje 3 `RecurringMessage`:

| Cron | Wiadomość | Efekt |
|---|---|---|
| `0 0 * * *` | `Articles\…\ResetDailyArticleCache` | `Redis::del('articles:today')` + `DELETE FROM article_daily_picks WHERE picked_date < CURDATE() - INTERVAL 7 DAY` |
| `0 8 * * 1` | `App\Application\Scheduled\GenerateWeeklyActivityReport` | DBAL aggregate 7-day window: `read_articles`, `pages_read`, `completed_tasks`, `rated_episodes_total` → `logger->info('Scheduled task completed', [...])` |
| `0 */6 * * *` | `Music\…\RefreshDiscogsCollection` | Pre-warm Discogs collection cache przed 6h TTL |

Nowy serwis docker `scheduler_worker` (`messenger:consume scheduler_default --time-limit=3600 -vv`). `Schedule` jest `stateful($cache.app)` + `processOnlyLastMissedRun(true)` — restart workera odpala max 1 zaległe okno (handlery są idempotentne).

Live verified: `php bin/console debug:scheduler` listuje wszystkie 3 triggers z poprawnym `Next Run`.

## Walidacja po mergu

- **PHP tests:** 451/451 (z 421 baseline → +30 nowych: HMAI-37 +8, HMAI-107 +10, HMAI-112 +4, HMAI-39 +4, HMAI-35 +4).
- **Playwright E2E:** 5/5 (unchanged).
- **Newman REST:** 28/28 (unchanged).
- **PHPStan level 8:** clean (zero nowych entries w `phpstan-baseline.neon`).
- **CS Fixer:** clean (per-file na każdym PR).
- **Live smoke:** `curl /api/health` → 200; `debug:scheduler` → 3 triggers; `make fixtures` → seed loaded.

## Migration steps (post-deploy)

1. `composer install` — nowe paczki: `symfony/scheduler`, `dragonmantank/cron-expression`, `doctrine/doctrine-fixtures-bundle` (dev).
2. `docker compose up -d` — pełen rebuild wprowadza `scheduler_worker` (ten sam image co `messenger_worker`).
3. Live healthcheck: `curl http://localhost:8080/api/health` powinien zwrócić 200 z `"status":"healthy"`.
4. Scheduler walidacja: `make shell` → `php bin/console debug:scheduler` powinno pokazać 3 triggers.
5. Graylog wiring (opcjonalne, profil monitoring):
   - Filtr `scheduled_task:*` — widok cyklicznych zadań.
   - Filtr `provider:lastfm OR provider:discogs` — latency dashboard (`duration_ms` field).
   - Filtr `provider:google OR provider:discogs AND reason:*` — failed OAuth callbacks.

## Co nie weszło

- **HMAI-128 Frontend hardening** (12 otwartych) — większy epic, kandydat na 1.7.0.
- **HMAI-129 API hardening** (8 otwartych) — wymaga skoordynowanej zmiany w request validation.
- **HMAI-131 DDD purity** (11 otwartych) — wymaga wielu zmian w agregatach z osobnym testowaniem regression.
- **HMAI-132 Features** (1 otwarty: CSV/PDF exports).

## Pełny diff

[`develop ← 1.5.0...1.6.0`](https://github.com/zlotylesk/AIHomeManager/compare/1.5.0...develop) — PRy #100, #101, #102, #103, #104 + epic review commit.
