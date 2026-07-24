# AIHomeManager

Single-user system for automating everyday activities — television (Series, Movies), calendar (Tasks), reading (Books / Articles), listening (Music, Podcasts), YouTube watching progress (YouTubeProgress), plus the cross-cutting Goals, Search, Dashboard, Notifications and Insights modules. A modular Symfony 8 monolith with hexagonal architecture and CQRS.

---

## Table of contents

- [About the project](#about-the-project)
- [Modules](#modules)
- [Architecture](#architecture)
- [Tech stack](#tech-stack)
- [Requirements](#requirements)
- [Quick start](#quick-start)
- [Configuration](#configuration)
- [Makefile commands](#makefile-commands)
- [Development](#development)
- [Tests](#tests)
- [Static analysis](#static-analysis)
- [Monitoring](#monitoring)
- [Project structure](#project-structure)
- [API](#api)
- [Progressive Web App (PWA)](#progressive-web-app-pwa)
- [Links](#links)
- [License](#license)

---

## About the project

AIHomeManager aggregates one user's everyday activities across thirteen domain modules. Each module is architecturally independent (Domain free of any framework, its own ubiquitous language), loosely coupled through the CQRS bus and Symfony Messenger. Dual-track frontend: every module built since 1.19.0 uses Webpack Encore + Stimulus (Series, Books, YouTubeProgress, Goals, Search, Dashboard, Movies, Notifications, Podcasts, Insights), while three legacy panels (Tasks/Articles/Music) still run on Twig + vanilla JS — sharing `window.apiCall` from `public/js/util.js`.

**Core principles:**

- Single user (no multi-tenant).
- Stateless API protected by a key (`X-API-Key`); the UI is public.
- Hexagonal architecture — `Domain` knows nothing about Doctrine or Symfony, boundaries enforced by Deptrac in CI (currently zero violations and zero `skip_violations`).
- Doctrine XML mapping (ADR-001 — we do not migrate to PHP attributes).
- CQRS with two buses: `command.bus` and `query.bus`, plus `event.bus` for domain events (Series.`EpisodeRated`, Books.`BookCompleted`).
- Versioned REST contract under `/api/v1` (ADR-008), documented as OpenAPI 3.1 and enforced in CI.
- Per-IP rate limiting on `^/api/*` (60/min), proactive throttling of external API clients (Last.fm, Discogs, National Library, YouTube Data API, Trakt, Spotify).
- OAuth tokens encrypted at rest (libsodium secretbox, separate key per provider: Google, Discogs, Trakt, Spotify).
- Defense-in-depth security headers (dual-layer: nginx + Symfony listener).
- Daily mysqldump → gzip + retention (30 daily + 12 monthly).

---

## Modules

| Module | What it does | Key integrations |
|---|---|---|
| **Series** | Catalog of shows, seasons, episodes, own rating 1–10 + average from episodes, "watched" flag, edit/delete, import of watched shows from Trakt | Trakt.tv API (OAuth2) |
| **Tasks** | Full REST CRUD for tasks with `TimeSlot`, time report, CSV/PDF export, sync with Google Calendar | Google Calendar API (OAuth2) |
| **Books** | Library, status (`to_read` / `reading` / `completed`), reading sessions, metadata by ISBN, CSV/PDF export | National Library API (XML) |
| **Articles** | Daily article to read, CSV import from Pocket, categories, CSV/PDF export | — |
| **Music** | Top albums + local listening history (Last.fm), vinyl collection (Discogs), comparison of owned vs listened | Last.fm API, Discogs OAuth1 |
| **YouTubeProgress** | Sync of the "watchlist" playlist from YouTube, auto-splitting of unwatched videos into sessions ≤30 min (grouped by channel), progress tracking, pushing a session back out as a new playlist | YouTube Data API v3 (OAuth2) |
| **Movies** | Catalog of films alongside Series — CRUD, watched flag, own 1–10 rating, catalog metadata, import of watched movies + ratings from Trakt | Trakt.tv API (OAuth2) |
| **Podcasts** | Podcast listening history. Spotify exposes no listen timestamp for episodes, so listens are **derived** from each episode's resume point — a stored moment means "no later than", never the exact listening time | Spotify Web API (OAuth2) |
| **Goals** | Cross-module goals and day-continuity streaks over the other modules' activity (reading, watching, articles, video), read through DBAL adapters so no module is coupled to another | — |
| **Search** | Global search spanning every module — MySQL FULLTEXT, relevance-ranked, type-filtered, Redis-cached, reindexed every 15 min | — |
| **Dashboard** | The startup cockpit at `/` — one "today" slice per module (tasks, the daily article, goal snapshots, recommendations, recent tracks), each widget fault-isolated so one failing source degrades to an empty card | — |
| **Notifications** | Proactive delivery — e-mail + WebPush channels, reactive (domain event) and scheduled triggers, per-type/per-channel opt-in with quiet hours | Symfony Mailer, WebPush + VAPID |
| **Insights** | The trends dashboard at `/insights` — reading pace, episodes and YouTube minutes watched, tracks played and the task completion rate, charted per week or month. Read-only: no tables of its own, every metric read through a DBAL adapter behind one port and fault-isolated per metric | — |

---

## Architecture

```
src/Module/{Name}/
├── Domain/             ← pure PHP, aggregates, VOs, events, repository interfaces
│   ├── Entity/
│   ├── ValueObject/
│   ├── ReadModel/      ← what Domain ports return (never Application DTOs)
│   ├── Port/           ← outbound contracts implemented in Infrastructure
│   ├── Event/
│   └── Repository/
├── Application/        ← orchestration: commands, queries, handlers, DTOs
│   ├── Command/
│   ├── Handler/
│   ├── Query/
│   ├── QueryHandler/
│   └── DTO/
└── Infrastructure/     ← Doctrine, HTTP clients, external integrations
    ├── Persistence/Doctrine/    ← .orm.xml mappings
    ├── External/                ← API clients
    └── Messenger/               ← async event handlers
```

**Inviolable rules:**

- `grep -r "use Doctrine" src/Module/*/Domain/` MUST return an empty result. Enforced by `make deptrac` in CI — Domain → [`Shared`], cross-module coupling forbidden.
- Genuinely cross-context value objects and contracts live in the **shared kernel** `src/Shared/` (`App\Shared\…`) — the one sanctioned exception to "no cross-module coupling". Everything else stays inside its module.
- Cross-module reads (Goals, Search, Dashboard, Notifications, Insights) go through **DBAL adapters behind a Domain port**, reading the source module's tables with raw SQL rather than importing its classes — which is how those five modules stay at zero deptrac violations.
- The aggregate root collects events in `$recordedEvents`, the handler dispatches them after `releaseEvents()` (pattern: the `Series` aggregate).
- Query handlers use DBAL directly — we do not hydrate aggregates for reads.
- Command handler: `#[AsMessageHandler(bus: 'command.bus')]`. Query handler: `#[AsMessageHandler(bus: 'query.bus')]`. Event handler: `#[AsMessageHandler]` (default bus).

Architecture decisions (ADR): see Confluence space `H` → ADRs.

---

## Tech stack

| Layer | Technology                                              |
|---|----------------------------------------------------------|
| Language | PHP 8.5                                                  |
| Framework | Symfony 8                                                |
| ORM | Doctrine ORM (XML mapping)                               |
| DB | MySQL 8.4 LTS (image pinned to `mysql:8.4`)               |
| Cache / KV | Redis 8                                                  |
| Async messaging | RabbitMQ 4.x + Symfony Messenger                         |
| API contract | OpenAPI 3.1 via NelmioApiDocBundle (`/api/doc`)           |
| Frontend (all modules since 1.19.0) | Webpack Encore + Stimulus (Node.js 24 LTS in a container)  |
| Frontend (Tasks, Articles, Music) | Twig + vanilla JavaScript (`public/js/`)                 |
| Backend tests | PHPUnit 13                                               |
| Frontend unit tests | Vitest 4 + jsdom (`app/assets/tests/`)                   |
| E2E tests | Playwright 1.61 (`tests-e2e/`)                           |
| API smoke tests | Newman / Postman v2.1 (`tests-e2e/postman/`)             |
| Logging | Monolog → Graylog 6.3 (GELF UDP) + optionally New Relic |
| PDF | dompdf/dompdf ^3.1                                       |
| Containerization | Docker + Docker Compose                                  |

**Static analysis:** PHPStan level 8 (`phpstan-symfony` + `phpstan-doctrine` + `phpstan-phpunit`), PHP CS Fixer (`@Symfony` + `@PHP84Migration`), Rector (`withPhpSets()` + `deadCode`), Deptrac (hexagonal boundaries).

---

## Requirements

- **Docker Desktop** 4.x or Docker Engine 24+ with Docker Compose v2.
- **GNU Make** (Windows: `choco install make` or WSL).
- **Git** 2.40+.
- ~4 GB of free RAM for the containers (8 GB if you enable the monitoring stack).

You do **not** need PHP, Composer, MySQL, or Redis directly on the host — everything runs in containers.

---

## Quick start

The first run takes ~5 minutes on a fresh host (mostly pulling Docker images and `composer install`).

### 1. Clone the repo and prepare secrets

```bash
git clone git@github.com:zlotylesk/AIHomeManager.git
cd AIHomeManager
cp app/.env app/.env.local
```

Fill in `app/.env.local` according to the [Configuration](#configuration) section. **Without valid `API_KEY` + `DISCOGS_TOKEN_KEY` + `GOOGLE_TOKEN_KEY` + `TRAKT_TOKEN_KEY` + `SPOTIFY_TOKEN_KEY` keys the application will not start** (DI will not boot the value objects with empty arguments — the encryption keys must be valid 32-byte base64, otherwise `TokenCipher` throws). OAuth/API keys (`GOOGLE_CLIENT_*`, `DISCOGS_CONSUMER_*`, `LASTFM_*`, `TRAKT_CLIENT_*`, `SPOTIFY_CLIENT_*`, `YOUTUBE_WATCHLIST_PLAYLIST_ID`) can stay empty until you want to use a specific module — the dependent endpoints will then return 503/400/409 instead of 500.

### 2. Start the stack

```bash
make setup
```

`make setup` does it all in one command: build Docker images → `docker compose up -d` → `composer install` → `npm install` (Node container, for Webpack Encore) → MySQL migrations → cache warmup.

```bash
make services            # list of containers + ports
make logs                # tail logs of all services
make messenger-status    # whether the worker consumes the async transport
```

### 3. Build the frontend (Webpack Encore)

```bash
make assets-prod         # build artifacts into public/build/
```

Required — without `entrypoints.json` Twig throws 500 on the `encore_entry_*` helpers (`base.html.twig` uses them for every page).

### 4. Service addresses

| Service | Address |
|---|---|
| Application (UI + API) | http://localhost:8080 |
| Health check (public, no auth) | http://localhost:8080/api/health |
| API documentation — Swagger UI (public) | http://localhost:8080/api/doc |
| API documentation — Redoc / raw spec | http://localhost:8080/api/doc/redoc · http://localhost:8080/api/doc.json |
| RabbitMQ Management | http://localhost:15672 (guest/guest) |
| MySQL | localhost:3306 (homemanager/homemanager, DB `homemanager`) |
| Redis | localhost:6379 |
| Graylog (optional) | http://localhost:9000 (admin/admin) — requires `make monitoring-up` |

UI routes: `/` (the **Dashboard cockpit** — not a redirect), `/series`, `/movies`, `/tasks`, `/books`, `/articles`, `/music`, `/podcasts`, `/youtube-progress`, `/goals`, `/notifications`, `/insights`. Global search lives in the navbar on every page.

### 5. (Optional) load fixtures + verify tests

```bash
make fixtures            # demo data for the dev env
make test                # PHPUnit (unit + integration)
make test-e2e            # Playwright (desktop + mobile)
make test-newman         # Newman/Postman smoke
```

> **The first run of modules that require OAuth / API keys** (Google Calendar, YouTube, Discogs, Last.fm, Trakt) is described step by step on Confluence: [First boot — configuring external services](https://honemanager.atlassian.net/wiki/spaces/H/pages/50659329/First+boot+configuring+external+services).

---

## Configuration

The application reads variables from `app/.env` (committed, placeholders) and `app/.env.local` (gitignored, the actual secrets).

### Required secrets in `.env.local`

```dotenv
# API key protecting /api/* (any strong, random string)
API_KEY=...

# OAuth token encryption keys at rest (libsodium secretbox) — 32 bytes base64.
# REQUIRED for the application to start (TokenCipher throws for any other key length).
# Generate EACH one separately (see below).
DISCOGS_TOKEN_KEY=...
GOOGLE_TOKEN_KEY=...
TRAKT_TOKEN_KEY=...
SPOTIFY_TOKEN_KEY=...

# OAuth2 — Google Calendar (Tasks) + YouTube Data API (YouTubeProgress); one client, two scopes
# https://console.cloud.google.com
GOOGLE_CLIENT_ID=...
GOOGLE_CLIENT_SECRET=...
GOOGLE_REDIRECT_URI=http://localhost:8080/auth/google/callback

# YouTubeProgress — ID of the "AIHM Watchlist" playlist (the part of the URL after `list=`); empty = /sync returns 400
YOUTUBE_WATCHLIST_PLAYLIST_ID=...

# OAuth1 — Discogs (Music) — https://www.discogs.com/settings/developers
DISCOGS_CONSUMER_KEY=...
DISCOGS_CONSUMER_SECRET=...
DISCOGS_USERNAME=...
DISCOGS_CALLBACK_URL=http://localhost:8080/auth/discogs/callback

# Last.fm (Music) — read-only API key — https://www.last.fm/api/account/create
LASTFM_API_KEY=...
LASTFM_USERNAME=...

# OAuth2 — Trakt.tv (Series + Movies — import of watched shows/films) — https://trakt.tv/oauth/applications
TRAKT_CLIENT_ID=...
TRAKT_CLIENT_SECRET=...
TRAKT_REDIRECT_URI=http://localhost:8080/auth/trakt/callback

# OAuth2 — Spotify (Podcasts — derived listening history) — https://developer.spotify.com/dashboard
# Scopes requested: user-library-read, user-read-playback-position, user-read-currently-playing.
# WITHOUT user-read-playback-position Spotify omits resume points entirely — the integration
# then connects successfully and reports nothing.
SPOTIFY_CLIENT_ID=...
SPOTIFY_CLIENT_SECRET=...
SPOTIFY_REDIRECT_URI=http://localhost:8080/auth/spotify/callback

# Notifications — e-mail channel (Symfony Mailer). null://null disables delivery without breaking.
MAILER_DSN=null://null
NOTIFICATIONS_MAIL_FROM=...
NOTIFICATIONS_MAIL_TO=...

# Notifications — WebPush. VAPID identifies this server to the push service (no FCM, no third party).
# Generate the pair: php -r "require 'vendor/autoload.php'; var_dump(Minishlink\WebPush\VAPID::createVapidKeys());"
# The private key never leaves the server; the public one is handed to the browser on subscribe.
VAPID_PUBLIC_KEY=...
VAPID_PRIVATE_KEY=...
VAPID_SUBJECT=mailto:you@example.com
```

### Generating encryption keys

```bash
docker compose exec php php -r "echo base64_encode(sodium_crypto_secretbox_keygen()), PHP_EOL;"
```

Generate **four different** keys — a separate one for Discogs, Google, Trakt, and Spotify. Separate keys isolate the blast radius if one provider is compromised.

### How to obtain keys and tokens

The full step-by-step guide (scopes, consent screen, common mistakes) is on Confluence: [First boot — configuring external services](https://honemanager.atlassian.net/wiki/spaces/H/pages/50659329/First+boot+configuring+external+services). In short:

| Provider | Module | Where to register | What goes into `.env.local` |
|---|---|---|---|
| **Google Cloud** (Calendar + YouTube) | Tasks, YouTubeProgress | [console.cloud.google.com](https://console.cloud.google.com) → new project → enable **Google Calendar API** and **YouTube Data API v3** → configure the consent screen (type *External*, add your account as a *test user*) → *Credentials* → OAuth client ID of type **Web application** with redirect URI `http://localhost:8080/auth/google/callback` | `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET` |
| **YouTube playlist** | YouTubeProgress | In your YouTube account create an "AIHM Watchlist" playlist and copy its ID from the URL (the part after `list=`) | `YOUTUBE_WATCHLIST_PLAYLIST_ID` |
| **Discogs** | Music | [discogs.com/settings/developers](https://www.discogs.com/settings/developers) → *Create an Application* → callback `http://localhost:8080/auth/discogs/callback` | `DISCOGS_CONSUMER_KEY`, `DISCOGS_CONSUMER_SECRET`, `DISCOGS_USERNAME` |
| **Last.fm** | Music | [last.fm/api/account/create](https://www.last.fm/api/account/create) → create an API account | `LASTFM_API_KEY`, `LASTFM_USERNAME` |
| **Trakt.tv** | Series, Movies | [trakt.tv/oauth/applications](https://trakt.tv/oauth/applications) → *New Application* → Redirect URI `http://localhost:8080/auth/trakt/callback` | `TRAKT_CLIENT_ID`, `TRAKT_CLIENT_SECRET` |
| **Spotify** | Podcasts | [developer.spotify.com/dashboard](https://developer.spotify.com/dashboard) → *Create app* → Redirect URI `http://localhost:8080/auth/spotify/callback`. One token covers the followed-show catalog and every episode's resume point | `SPOTIFY_CLIENT_ID`, `SPOTIFY_CLIENT_SECRET` |
| **National Library** | Books | no registration — public `data.bn.org.pl` API (throttled 60/min by a shared client) | — |

Google cumulatively requires the `calendar.events` **and** `youtube` (read/write) scopes on a single token; after the first authorization both modules (Tasks + YouTubeProgress) work on the same encrypted token.

### First OAuth connection

After starting the application and filling in `.env.local`, open in a browser:

- `http://localhost:8080/auth/google` — OAuth2 Google (Calendar + YouTube; forces the consent screen for both scopes)
- `http://localhost:8080/auth/discogs` — OAuth1 Discogs
- `http://localhost:8080/auth/trakt` — OAuth2 Trakt.tv (Series + Movies imports)
- `http://localhost:8080/auth/spotify` — OAuth2 Spotify (Podcasts)

The tokens are encrypted (libsodium secretbox) and stored in MySQL. Last.fm and the National Library do not require an OAuth flow — Last.fm works right after setting the API key, and BN needs no keys at all.

---

## Makefile commands

| Action | Command |
|---|---|
| Start the environment (full stack + monitoring) | `make up` |
| Start the environment (lean, no monitoring) | `make min-up` |
| Full initialization (build + migrations + node install) | `make setup` |
| Stop the environment | `make down` |
| Preflight env health check | `make doctor` |
| Shell in the PHP container | `make shell` |
| All tests | `make test` |
| Unit only (Domain) | `make test-unit` |
| Integration only | `make test-integration` |
| E2E Playwright (install + run) | `make test-e2e-install` / `make test-e2e` |
| Newman/Postman smoke | `make test-newman-install` / `make test-newman` |
| Migrations dev / test | `make migrate` / `make migrate-test` |
| Cache clear | `make cc` |
| Routing | `make routes` |
| List of DI services | `make services` |
| Worker status | `make messenger-status` |
| Logs | `make logs` |
| Fixtures (demo data, dev only) | `make fixtures` |
| Webpack Encore (frontend build) | `make assets` / `make assets-watch` / `make assets-prod` |
| JS unit tests (Vitest) | `make test-js` |
| Tests with coverage + threshold gate | `make test-coverage` |
| OpenAPI contract dump / lint | `make openapi-dump` / `make openapi-lint` |
| Reinstall npm in the node container | `make node-install` |
| Static analysis (CS Fixer + PHPStan + Deptrac) | `make analyse` |
| PHPStan | `make phpstan` / `make phpstan-baseline` |
| CS Fixer | `make cs-check` / `make cs-fix` |
| Rector | `make rector-dry` / `make rector` |
| Deptrac (architecture boundaries) | `make deptrac` / `make deptrac-baseline` |
| Composer / npm audit (CVE gate) | `make audit` / `make node-audit` |
| Doctrine schema validate (ORM XML ↔ MySQL) | `make schema-validate` |
| Backup MySQL (manual) | `make backup-now` |
| Restore MySQL from gzip | `make restore BACKUP=backups/homemanager-YYYY-MM-DD.sql.gz` |
| Monitoring up/down/logs | `make monitoring-up` / `make monitoring-down` / `make monitoring-logs` |
| Graylog bootstrap (inputs + indexes + streams) | `make monitoring-bootstrap` |

---

## Development

### Branches

```
master   ← stable, synced from develop at each release
develop  ← integration, default for PRs
feature/fix branches  ← created from develop
```

Branches are created from `develop` and merged into it via PR. **Every PR is merged with "Rebase and merge"** (`gh pr merge <n> --rebase`) — the new commits are replayed on the target's tip, keeping both branches linear; merge commits are not used. Realigning two branches that have drifted is likewise done by rebasing and force-pushing, never by merging one into the other.

At release time `develop` is synced onto `master` through a PR merged the same way, and `develop` is then rebased back onto `master` so both refs point at the same commit. **The release tag is created after that sync, never before** — a rebase creates new commit objects, so a tag made on the pre-sync commit would be left on an orphan that no branch can reach.

### Symfony Messenger worker

Two workers: `messenger_worker` (async — `EpisodeRated`, the Trakt imports, `RefreshDiscogsCollection`, `PollLastFmRecentTracks`, `RecalculateStreaks`, `DispatchNotification`, `PollPodcastListens`) and `scheduler_worker` (the `scheduler_default` transport — 10 recurring tasks: backup, weekly report, daily article reset, Discogs refresh, Last.fm poll, streak recompute, search reindex, two notification sweeps, podcast poll). Command:

```
bin/console messenger:consume async --time-limit=3600 -vv
bin/console messenger:consume scheduler_default --time-limit=3600 -vv
```

Routing is defined in `app/config/packages/messenger.yaml`. In the test env the transports are switched to `in-memory://`.

### Naming conventions

| Element | Pattern | Location |
|---|---|---|
| Aggregate Root | `Series`, `Task`, `Book`, `Article` | `Domain/Entity/` |
| Value Object (`final readonly`) | `Rating`, `ISBN`, `CoverUrl`, `TimeSlot` | `Domain/ValueObject/` |
| Read Model (what a Domain port returns) | `BookMetadata`, `Album`, `ListenedEpisode` | `Domain/ReadModel/` |
| Command | `CreateSeries`, `LogReadingSession` | `Application/Command/` |
| Command Handler | `*Handler` | `Application/Handler/` |
| Query | `GetAllSeries`, `GetSeriesDetail` | `Application/Query/` |
| Query Handler | `*Handler` | `Application/QueryHandler/` |
| DTO | `*DTO` | `Application/DTO/` |
| Repository Interface | `*RepositoryInterface` | `Domain/Repository/` |
| Repository Implementation | `Doctrine*Repository` | `Infrastructure/Persistence/` |
| Serializer Normalizer (DTO → JSON) | `*DTONormalizer` | `src/Serializer/` (Glue) |

---

## Tests

```bash
make test               # PHPUnit (Unit + Integration)
make test-unit          # Domain only
make test-integration   # integration only
make test-js            # Vitest (frontend pure helpers, jsdom)
make test-coverage      # PHPUnit + coverage report + threshold gate
make test-parallel      # PHPUnit in parallel (paratest) — the CI test-run profile
make test-e2e           # Playwright (desktop + mobile)
make test-newman        # Newman/Postman smoke
```

- **Unit:** `tests/Unit/Module/{Name}/Domain/` — pattern `tests/Unit/Module/Series/Domain/SeriesAggregateTest.php` (gold standard).
- **Integration:** `tests/Integration/` — a real database + Redis + in-memory transport (`when@test` in `messenger.yaml`).
- `*ApiTest` tests use `App\Tests\Support\AuthenticatedApiTrait` — the `X-API-Key: test-api-key` header (see `app/.env.test`).
- **E2E (Playwright)** in `tests-e2e/`, TypeScript. Files matching `*.desktop.spec.ts` (1440×900) or `*.mobile.spec.ts` (Pixel 5 viewport).
- **Smoke (Newman)** in `tests-e2e/postman/AIHomeManager.postman_collection.json`. Run via `make test-newman` (truncate + newman with `--ignore-redirects`).
- **JS unit (Vitest)** in `app/assets/tests/*.test.js` — fast jsdom tests for the frontend's pure helpers (labels, formatting, grouping, sorting). They must NOT live under `assets/controllers/`, where the Stimulus `webpackContext` would auto-mount every `.js` as a controller and break the build.
- **Coverage gate + trend:** `make test-coverage` measures line coverage via pcov and fails below the floor (`COVERAGE_MIN`, default 90; measured 93.66% at the 1.18.0 baseline). CI enforces the same floor, uploads the HTML report as an artifact, and publishes a coverage summary to the job's **GitHub step summary** — current %, floor, baseline and the **run-over-run trend** (Δ vs the previous run, persisted between runs via `actions/cache`). `make test-coverage` prints the same summary to the terminal locally.
  - **Ratchet the floor:** the floor only moves **up**. When the job-summary trend shows coverage sitting comfortably above a higher number across several runs, raise the floor in one small PR — bump `COVERAGE_MIN` (default in the `Makefile`) **and** the literal `90` in the `tests` job of `.github/workflows/ci.yml` (the two are kept in sync). Never lower it to make a red build pass — add the missing tests instead.
- **Parallel run:** CI's `tests` job runs the PHPUnit suite through **paratest** across `PARATEST_PROCESSES` workers (default 4), each isolated on its own database (`homemanager_test{n}`, via the `dbname_suffix: '_test{TEST_TOKEN}'` in `doctrine.yaml`) and its own Redis logical DB (`tests/bootstrap.php`), so integration tests never collide on the shared MySQL/Redis. Per-worker coverage is merged into one clover and the floor still gates. `make test` stays **sequential** (fast for TDD); `make test-parallel` mirrors CI locally — it provisions the token databases (as root), migrates each, then runs paratest with coverage.
- **E2E/Newman prerequisite:** `API_KEY=e2e-test-key` in `app/.env.local`, Discogs/Last.fm/Google placeholders set to anything non-empty (DI will not boot with empty VOs).

---

## Static analysis

```bash
make analyse              # CS Fixer (dry-run) + PHPStan + Deptrac
make phpstan              # PHPStan analyse
make phpstan-baseline     # regenerate the baseline after fixing errors
make cs-check / cs-fix    # PHP CS Fixer
make rector-dry / rector  # Rector
make deptrac              # Deptrac architecture boundaries
```

The PHPStan baseline (`app/phpstan-baseline.neon`) holds the existing debt. New errors require a fix or an explicit addition to the baseline via `make phpstan-baseline`.

Deptrac formalizes the hexagonal boundaries: every module has separate Domain/Application/Infrastructure layers, plus a cross-cutting `Shared` kernel layer. Domain → [`Shared`] (otherwise zero dependencies beyond PHP core), cross-module coupling forbidden. It runs with **zero violations and zero `skip_violations`** — the four grandfathered exceptions were resolved rather than re-baselined, so a new violation is a hard failure with nothing to hide behind.

CI (`.github/workflows/ci.yml`) runs five jobs on every push and PR: `static-analysis` (Rector dry-run + CS Fixer + PHPStan level 8 + Deptrac + Composer audit), `openapi-contract` (dump `openapi.json` → Spectral lint → upload the spec artifact), `tests` (PHPUnit + the coverage threshold gate), `e2e-playwright` (Playwright desktop + mobile), and `e2e-newman` (Newman API smoke). The E2E jobs start the application via `symfony server:start` (env `test`, `in-memory://` transport) and upload HTML reports as artifacts (30-day retention).

---

## Monitoring

The `graylog + mongodb + opensearch` stack runs under the Compose `monitoring` profile. `make up` starts the **full** stack (including monitoring); `make min-up` is the lean variant without monitoring. You can also control the profile manually:

```bash
make monitoring-up           # start (if you previously ran lean via make min-up)
make monitoring-bootstrap    # GELF UDP input + index sets + streams (idempotent)
make monitoring-logs         # view
make monitoring-down         # stop
```

After the first start, log in to http://localhost:9000 (admin/admin). `make monitoring-bootstrap` creates the GELF UDP input (port 12201), the `auth-events` (90-day retention) and `series-events` (30-day) index sets, plus streams filtering by `channel`. A non-running Graylog does not crash the application — the `series`/`auth` log channels are then silently dropped (graceful degrade).

`NewRelicMonologHandler` (`src/Module/Series/Infrastructure/Logging/`) — graceful degrade when the `newrelic` extension is absent (logs are not sent, but the application does not fail).

### MySQL backup

The Symfony Scheduler runs `App\Application\Scheduled\BackupDatabase` daily at 03:00:

```bash
make backup-now                                         # ad-hoc backup
make restore BACKUP=backups/homemanager-2026-06-01.sql.gz
```

Retention: 30 daily + 12 monthly (the 1st of each month is kept). Runbook: Confluence → Disaster recovery — MySQL restore.

---

## Project structure

```
.
├── app/                            ← Symfony root
│   ├── assets/                     ← Webpack Encore source
│   │   ├── app.js
│   │   ├── bootstrap.js
│   │   ├── util.js                 ← ES module helpers
│   │   ├── controllers/            ← Stimulus controllers (auto-mounted)
│   │   ├── {series,goals,movies,…}/← pure helpers + view builders (NOT auto-mounted)
│   │   ├── tests/                  ← Vitest unit tests
│   │   └── styles/app.css
│   ├── bin/console
│   ├── config/
│   │   └── packages/
│   │       ├── security.yaml       ← API Key authenticator
│   │       ├── messenger.yaml      ← async transport, routing
│   │       └── rate_limiter.yaml
│   ├── deptrac.yaml                ← architecture boundary config
│   ├── migrations/
│   ├── public/
│   │   ├── index.php
│   │   ├── build/                  ← Encore build output (gitignored)
│   │   └── js/                     ← vanilla JS (Tasks/Articles/Music)
│   ├── src/
│   │   ├── Controller/
│   │   │   └── Api/                ← version-agnostic API controllers (imported twice)
│   │   ├── EventListener/
│   │   ├── Health/
│   │   ├── Http/                   ← RateLimitedHttpClient
│   │   ├── Logging/                ← Monolog processors, request-id holder
│   │   ├── Messaging/              ← typed Query/Command bus + Messenger middleware
│   │   ├── Module/                 ← 13 modules (Series, Tasks, Books, Articles, Music,
│   │   │   │                          YouTubeProgress, Goals, Search, Dashboard,
│   │   │   │                          Movies, Notifications, Podcasts, Insights)
│   │   │   └── {Name}/{Domain,Application,Infrastructure}/
│   │   ├── Schedule.php
│   │   ├── Security/
│   │   ├── Serializer/             ← *DTONormalizer (DTO → JSON)
│   │   └── Shared/                 ← shared kernel (cross-context VOs + contracts)
│   ├── templates/                  ← Twig
│   ├── tests/
│   │   ├── Unit/
│   │   └── Integration/
│   ├── webpack.config.js
│   ├── composer.json
│   └── phpunit.dist.xml
├── docker/                         ← Dockerfiles, nginx config
├── docker-compose.yml
├── scripts/
│   └── graylog-bootstrap.sh
├── tests-e2e/                      ← Playwright + Newman
│   └── postman/
├── Makefile
├── CHANGELOG.md
├── CLAUDE.md                       ← context for Claude Code
└── README.md
```

---

## API

### Versioning and documentation

The REST surface is served under the versioned base **`/api/v1`**, with the bare **`/api`** prefix kept as a backward-compatible alias — both resolve to the same controllers and return byte-identical responses (ADR-008). Versioning is by path prefix, not by an `Accept` header. A breaking change would ship as `/api/v2`; `/api/v1` is never mutated in place.

The whole contract is generated as **OpenAPI 3.1** from the controllers' attributes and published without a key:

| What | Where |
|---|---|
| Swagger UI (interactive) | `/api/doc` |
| Redoc | `/api/doc/redoc` |
| Raw spec (JSON) | `/api/doc.json` |

The contract is a CI gate, not just documentation: a dedicated job dumps the spec, lints it with Spectral, and PHPUnit validates real responses against the schema documented for the status code they actually returned — so a normalizer drifting from its documented shape fails the build. Locally: `make openapi-dump` / `make openapi-lint`.

### Authentication

The `^/api/*` endpoints are protected by the `api` firewall (stateless, custom authenticator) — the pattern covers both `/api/v1/*` and the `/api/*` alias. Add the header:

```
X-API-Key: <value from .env.local>
```

Missing / invalid key → `401 {"error": "..."}`.

Exceptions: `GET /api/health` — a public readiness probe (MySQL + Redis + RabbitMQ + a 3-state disk probe) — and the three API-doc routes above.

The `/auth/google*`, `/auth/discogs*`, `/auth/trakt*`, `/auth/spotify*` endpoints and the UI (`/`, `/series`, …) are public.

### Example — Series

```bash
# List of shows
curl -H "X-API-Key: $API_KEY" http://localhost:8080/api/series

# Create a show
curl -X POST http://localhost:8080/api/series \
  -H "X-API-Key: $API_KEY" \
  -H "Content-Type: application/json" \
  -d '{"title": "Severance"}'

# Rate an episode (scale 1–10)
curl -X POST http://localhost:8080/api/series/{seriesId}/seasons/{seasonId}/episodes/{episodeId}/rate \
  -H "X-API-Key: $API_KEY" \
  -H "Content-Type: application/json" \
  -d '{"rating": 9}'
```

### Export — Books / Articles / Tasks

```bash
curl -H "X-API-Key: $API_KEY" "http://localhost:8080/api/books/export?format=csv" -o books.csv
curl -H "X-API-Key: $API_KEY" "http://localhost:8080/api/books/export?format=pdf" -o books.pdf
```

### Notifications — enabling the daily digest

Notification types are opt-in/out per type and per channel. Every type is **on by default except the daily digest** (`daily_digest`), which ships **off** — with every type enabled it would duplicate the individual reminders (task deadlines, the daily article, streak warnings) it summarises. Turn it on when you want the once-a-day summary instead of, or in addition to, the per-item notifications.

Two ways to enable it:

- **UI** — open `/notifications`, find the **Podsumowanie dnia** (Daily summary) row and tick its enable checkbox. Optionally pick the channels (e-mail / push) and quiet hours on the same row.
- **API** — flip the type on directly:

  ```bash
  curl -X PATCH http://localhost:8080/api/notifications/preferences/daily_digest/enabled \
    -H "X-API-Key: $API_KEY" \
    -H "Content-Type: application/json" \
    -d '{"enabled": true}'
  ```

  Read back the current preferences (every type, with its channels and quiet hours) any time:

  ```bash
  curl -H "X-API-Key: $API_KEY" http://localhost:8080/api/notifications/preferences
  ```

The digest is produced by the twice-daily scheduler sweep, so it starts arriving on the next run after you enable it. Disabling it again is the same call with `{"enabled": false}`.

Full list of endpoints: `make routes`. Detailed API documentation: Confluence → API documentation.

---

## Progressive Web App (PWA)

The web frontend is an installable PWA (epic HMAI-344): a Web App Manifest + maskable icons (Add-to-Home-Screen), a Workbox Service Worker with an offline mode, an offline write queue, and Web Push. Everything is built inside the existing Encore pipeline — there is no separate bundler.

### Service Worker

- **Source:** `app/assets/pwa/sw.js`, built by Workbox `InjectManifest` and emitted to the site root as `public/sw.js` (a gitignored build artifact). It is served from the root **on purpose** so its scope is the whole origin (`/`) — a hashed `/build/` path would narrow the scope and break registration.
- **Emitted in production builds only.** `make assets-prod` / `npm run build` produce `public/sw.js`; a dev build does not, and registration fails soft (nothing breaks in `make assets`).
- **Registered** from `app.js` via `registerPushServiceWorker()` on every page.
- **Caches:**
  - *Precache* (`workbox-precache-*`) — the content-hashed app-shell (Encore statics + `offline.html`); self-invalidating via Workbox revisions + `cleanupOutdatedCaches()`.
  - *Runtime* `aihm-runtime-api-reads-v1` — `GET /api/*`, NetworkFirst, 200-only, bounded (60 entries / 24 h).
  - *Runtime* `aihm-runtime-pages-v1` — navigations, NetworkFirst, bounded (30 entries / 7 d); `/auth/*` and `/api/*` are denylisted (always network).
  - *Queue* `api-writes` — offline `POST`/`PATCH`/`DELETE` (Background Sync, IndexedDB), replayed on reconnect.

### Update strategy & cache versioning

- `skipWaiting()` + `clientsClaim()` make a newly installed worker take over immediately, so a shipped app-shell update is never stranded behind the old worker.
- `nginx` serves `/sw.js` with `Cache-Control: no-cache`, so the browser revalidates the worker script on every navigation and a new deploy is picked up at once (see `docker/nginx/default.conf`).
- The **runtime** read caches persist by design, so they carry an explicit lever: bump `CACHE_VERSION` in `sw.js` (`v1` → `v2`) after a change that would make a stale cached read render wrong (e.g. an `/api` response-shape change). On the next activation a no-zombie sweep deletes every runtime bucket that is not current (old versions + the pre-versioning names). The `api-writes` queue is never swept — dropping it would lose a user's offline writes.

### Scope, headers & CSP

- Scope is `/` (root-served worker); `nginx` also sends `Service-Worker-Allowed: /` on `/sw.js` to pin the intent explicitly (redundant for a root-served worker, but future-proof).
- A location-level `add_header` in nginx **replaces** the inherited server-level ones, so the `/sw.js` location re-declares all four security headers — `/sw.js` stays consistent with `SecurityHeadersListener` and every other response (verified by `SecurityHeadersTest`).
- There is **no `Content-Security-Policy`** header in this project, so the same-origin Service Worker needs no CSP allowance; nothing in `SecurityHeadersListener` had to change for the PWA.

### Emergency kill-switch

If a bad worker ever traps clients on stale content, deploy a **self-unregistering** `public/sw.js` (overriding the Workbox build) and ship it — the `no-cache` header guarantees every client fetches it on the next navigation:

```js
// public/sw.js — emergency kill-switch. Unregisters itself and drops all caches.
self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', (event) => {
  event.waitUntil((async () => {
    await self.registration.unregister();
    await Promise.all((await caches.keys()).map((k) => caches.delete(k)));
    const clients = await self.clients.matchAll({ type: 'window' });
    clients.forEach((c) => c.navigate(c.url)); // reload each tab, now uncontrolled
  })());
});
```

Once every client has updated, revert to the normal Workbox build.

### Testing

- E2E: `tests-e2e/pwa.mobile.spec.ts` (Pixel 5) — manifest installability, SW registers/activates/controls, offline serves cached view + offline page. Runs against a **production** build (the SW only exists there), opting into the worker with `test.use({ serviceWorkers: 'allow' })`.
- CI gate: a Lighthouse `installable-manifest` audit in the `e2e-playwright` job (`@lhci/cli@0.13.0` pinned — Lighthouse 12 removed the PWA category; config `lighthouserc.json`). A broken manifest / lost icons / a dead SW drops the score below 1 and blocks the merge.

---

## Links

- **Confluence hub:** https://honemanager.atlassian.net/wiki/spaces/H/pages/46661633
- **Repository:** https://github.com/zlotylesk/AIHomeManager
- **Claude Code context documentation:** [`CLAUDE.md`](CLAUDE.md)
- **Changelog:** [`CHANGELOG.md`](CHANGELOG.md)

---

## License

Private / single-user project. No public license — contact the author before use.
