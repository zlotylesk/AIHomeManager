# AIHomeManager

A single-user system for automating everyday activities across fifteen domain
modules — television, calendar, reading, listening, watching progress, home
finances and cooking, plus the cross-cutting goals, search, dashboard,
notifications and trends layers on top of them.

A modular Symfony 8 monolith: hexagonal architecture, CQRS over two buses, a
versioned OpenAPI 3.1 REST contract, and everything running in Docker.

---

## Quick start

You need **Docker** (Desktop 4.x or Engine 24+ with Compose v2), **GNU Make**,
**Git**, and about 4 GB of free RAM — 8 GB with the monitoring stack. You do
*not* need PHP, Composer, MySQL or Node on the host; everything runs in
containers.

The first run takes roughly five minutes, mostly pulling images and installing
dependencies.

### 1. Clone and configure

```bash
git clone git@github.com:zlotylesk/AIHomeManager.git
cd AIHomeManager
cp app/.env app/.env.local
```

On Windows, clone normally and let the repo's `.gitattributes` apply. It pins
`app/bin/console` to LF, and it has to: a shebang is not a comment, so a CRLF
checkout makes Linux `env` look for a program literally named `php\r` and every
Make target that runs `bin/console` fails with a thoroughly misleading error.
An older working tree needs one re-checkout — see
[docs/development.md](docs/development.md#windows-note).

Now fill in `app/.env.local`. **Do this before step 2** — `composer install`
runs `cache:clear` as an auto-script, which boots the Symfony kernel, and the
service container will not compile with an encryption key of the wrong length.
Five values are enough to start:

```bash
# run four times — a separate key per OAuth provider, so a compromised
# provider costs one provider
openssl rand -base64 32
```

```dotenv
API_KEY=<any strong random string>
DISCOGS_TOKEN_KEY=<32-byte base64>
GOOGLE_TOKEN_KEY=<32-byte base64>
TRAKT_TOKEN_KEY=<32-byte base64>
SPOTIFY_TOKEN_KEY=<32-byte base64>
```

`FRONTEND_USER` / `FRONTEND_PASSWORD_HASH` already carry a working placeholder
pair in `app/.env` (`admin` / the literal word `test`), so a clone boots — but
override both before the application is reachable from anything but localhost.

Everything else is per-integration and optional; see [Secrets and
keys](#secrets-and-keys) below.

### 2. Start the stack and migrate

```bash
make setup
```

That is `docker compose build` → `up -d` → `composer install` → create the
database → run the migrations.

### 3. Install and build the frontend

```bash
make node-install
make assets-prod
```

Both are required, and neither is part of `make setup`. `node_modules` is not
committed, so without `make node-install` the Encore build has no binary to run;
and without the build there is no `entrypoints.json`, so Twig throws a 500 on
the `encore_entry_*` helpers that `base.html.twig` uses for **every** page.

### 4. Provision the search index

```bash
make search-index
make search-populate
```

Also not part of `make setup`. The shipped configuration runs global search on
OpenSearch, and an unprovisioned engine does not raise an error — it degrades to
the MySQL fallback, which is equally empty until the 15-minute reindex first
fires. So skipping this gives you a search box that quietly finds nothing.

### 5. Verify

```bash
make doctor
curl -s localhost:8080/api/health | jq
```

`make doctor` checks the containers, decodes each encryption key to confirm it
is really 32 bytes, verifies the php image can actually take a backup, and
reports the dead-letter queue depth. The health endpoint is public and needs no
key.

Optionally load demo data and run the suites:

```bash
make fixtures
make test
```

### Service addresses

| Service | Address |
|---|---|
| Application (UI + API) | http://localhost:8080 |
| Health check (public) | http://localhost:8080/api/health |
| API docs — Swagger UI / Redoc / raw spec | `/api/doc` · `/api/doc/redoc` · `/api/doc.json` |
| RabbitMQ Management | http://localhost:15672 (guest/guest) |
| MySQL | localhost:3306 (homemanager/homemanager, DB `homemanager`) |
| Redis | localhost:6379 |
| Search engine (OpenSearch) | http://localhost:9200 |
| Graylog (optional) | http://localhost:9000 (admin/admin), after `make monitoring-up` |

UI routes: `/` (the dashboard cockpit), `/series`, `/movies`, `/tasks`,
`/books`, `/articles`, `/music`, `/podcasts`, `/youtube-progress`, `/goals`,
`/notifications`, `/insights`, `/budget`, `/recipes`, `/meal-plan`. Global
search sits in the navbar on every page.

The frontend is behind HTTP Basic, so the first page load asks for
`FRONTEND_USER` and the password behind `FRONTEND_PASSWORD_HASH`. That is not
optional decoration: every page renders `API_KEY` into a `<meta>` tag for the
JavaScript to use, so an anonymous frontend would hand out full API access.

---

## Secrets and keys

`app/.env` is committed and holds placeholders; `app/.env.local` is gitignored
and holds the real values.

| Variable | Required | Where it comes from |
|---|---|---|
| `API_KEY` | **yes** | Any strong random string. Sent as `X-API-Key`. |
| `FRONTEND_USER`, `FRONTEND_PASSWORD_HASH` | **yes** (placeholder shipped) | `bin/console security:hash-password` |
| `DISCOGS_TOKEN_KEY`, `GOOGLE_TOKEN_KEY`, `TRAKT_TOKEN_KEY`, `SPOTIFY_TOKEN_KEY` | **yes** | Four *different* 32-byte base64 keys — see step 1. A wrong length is a boot failure, not a runtime one. |
| `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET` | Tasks, YouTubeProgress | [console.cloud.google.com](https://console.cloud.google.com) — one OAuth client, Calendar + YouTube scopes |
| `YOUTUBE_WATCHLIST_PLAYLIST_ID` | YouTubeProgress | The part of the playlist URL after `list=` |
| `DISCOGS_CONSUMER_KEY`, `DISCOGS_CONSUMER_SECRET`, `DISCOGS_USERNAME` | Music | [discogs.com/settings/developers](https://www.discogs.com/settings/developers) |
| `LASTFM_API_KEY`, `LASTFM_USERNAME` | Music | [last.fm/api/account/create](https://www.last.fm/api/account/create) |
| `TRAKT_CLIENT_ID`, `TRAKT_CLIENT_SECRET` | Series, Movies | [trakt.tv/oauth/applications](https://trakt.tv/oauth/applications) |
| `SPOTIFY_CLIENT_ID`, `SPOTIFY_CLIENT_SECRET` | Podcasts | [developer.spotify.com/dashboard](https://developer.spotify.com/dashboard) |
| `MAILER_DSN`, `NOTIFICATIONS_MAIL_FROM/TO` | Notifications (e-mail) | Any Symfony Mailer DSN; `null://null` disables delivery without breaking |
| `VAPID_PUBLIC_KEY`, `VAPID_PRIVATE_KEY`, `VAPID_SUBJECT` | Notifications (push) | Generated once — see [docs/configuration.md](docs/configuration.md) |
| `SEARCH_ENGINE_BACKEND`, `SEARCH_INDEX_ALIAS` | defaults shipped | `opensearch` or `fulltext` — see [docs/search.md](docs/search.md) |
| `BUDGET_CURRENCY` | defaults to `PLN` | The single currency the ledger is kept in |

An empty integration block disables that integration; the dependent endpoints
answer 503/400/409 rather than 500. Once the keys are in place, connect each
provider once by visiting `/auth/google`, `/auth/discogs`, `/auth/trakt` and
`/auth/spotify`. Tokens are encrypted at rest with libsodium secretbox.

Full reference, including how to register each application and what each scope
buys: **[docs/configuration.md](docs/configuration.md)**.

---

## Everyday commands

```bash
make up / make min-up      # start with / without the monitoring stack
make down                  # stop
make doctor                # preflight check: env, image, backups, queues
make shell                 # shell in the php container
make logs                  # tail everything (logs-php, logs-worker, … for one)
make migrate               # run new migrations
make assets-watch          # Encore in watch mode while working on the frontend
make test                  # PHPUnit
make analyse               # CS Fixer + PHPStan + Deptrac + Composer audit
```

The full table is in [docs/development.md](docs/development.md#makefile-reference).

---

## Modules

| Module | What it does | External integration |
|---|---|---|
| **Series** | Shows, seasons, episodes; own rating and the average from episodes; watched flag; import of watched shows | Trakt.tv |
| **Movies** | Films alongside Series — CRUD, watched, own rating, metadata, import of watched films and ratings | Trakt.tv |
| **Tasks** | REST CRUD over time slots, time report, CSV/PDF export, calendar sync | Google Calendar |
| **Books** | Library with reading sessions and per-ISBN metadata, CSV/PDF export | National Library (public API) |
| **Articles** | A daily article to read, categories, CSV import from Pocket, CSV/PDF export | — |
| **Music** | Listening history and top albums, vinyl collection, owned-vs-listened comparison | Last.fm, Discogs |
| **Podcasts** | Listening history derived from each episode's resume point, because the source exposes no listen timestamp — a stored moment means "no later than" | Spotify |
| **YouTubeProgress** | Watchlist sync, unwatched videos split into sessions ≤30 min by channel, progress, pushing a session back out as a playlist | YouTube Data API |
| **Budget** | Transactions, categories with monthly limits, a monthly income/expense/balance report, CSV/PDF export | — |
| **Recipes** | Recipe catalog, a weekly meal-planning calendar, and a shopping list generated from the plan | — |
| **Goals** | Cross-module goals and day-continuity streaks over the other modules' activity | — |
| **Search** | Global search spanning every module — ranked, type-filtered with per-type counts, cached, reindexed every 15 min | OpenSearch |
| **Dashboard** | The cockpit at `/` — one "today" slice per module, each widget fault-isolated | — |
| **Insights** | Trends at `/insights` — reading pace, episodes and minutes watched, tracks played, task completion rate, per week or month | — |
| **Notifications** | E-mail and Web Push delivery, reactive and scheduled triggers, per-type opt-in with quiet hours | Symfony Mailer, WebPush/VAPID |

---

## Architecture

```
src/Module/{Name}/
├── Domain/          ← pure PHP: aggregates, value objects, read models, ports, events
├── Application/     ← commands, queries, handlers, DTOs
└── Infrastructure/  ← Doctrine XML mappings, HTTP clients, external integrations
```

The rules that are actually enforced, not merely intended:

- **The Domain imports no framework.** `grep -r "use Doctrine"
  src/Module/*/Domain/` must come back empty; `make deptrac` gates it in CI, at
  zero violations and zero `skip_violations`.
- **No cross-module coupling.** The one sanctioned exception is the shared
  kernel `src/Shared/`, for value objects and contracts that genuinely belong to
  more than one context.
- **A module that reads another module's data does it through a DBAL adapter
  behind its own Domain port** — raw SQL against the source tables, importing
  none of its classes. That is how Goals, Search, Dashboard, Notifications and
  Insights read five other modules and still hold at zero violations.
- **Query handlers use DBAL, not the ORM.** Reads do not hydrate aggregates.
- **Doctrine mapping is XML**, deliberately, and is not being migrated to
  attributes.
- Command handler: `#[AsMessageHandler(bus: 'command.bus')]`. Query handler:
  `bus: 'query.bus'`. Event handler: no `bus:` at all.

| Layer | Technology |
|---|---|
| Runtime | PHP 8.5, Symfony 8 |
| Persistence | MySQL 8.4 LTS, Doctrine ORM (XML mapping) |
| Cache / KV | Redis 8 |
| Async | RabbitMQ 4.x + Symfony Messenger |
| Search | OpenSearch 2.x with `analysis-stempel`, MySQL FULLTEXT as fallback |
| API contract | OpenAPI 3.1 via NelmioApiDocBundle |
| Frontend | Webpack Encore + Stimulus (Node 24); three legacy panels on Twig + vanilla JS |
| Tests | PHPUnit 13, Vitest 4, Playwright 1.61, Newman |
| Logging | Monolog → Graylog 6.3 (GELF), optionally New Relic |

Architecture decisions are recorded as ADRs on Confluence.

---

## Quality gates

Five CI jobs run on every push and PR, and all must be green to merge: static
analysis (Rector, CS Fixer, PHPStan level 8, Deptrac, Composer audit — one
parallel leg each), the OpenAPI contract (dump → Spectral lint), PHPUnit through
paratest with a coverage floor, Playwright plus a Lighthouse installability
audit, and a Newman API smoke run.

The OpenAPI contract is a gate rather than documentation: real responses are
validated against the schema documented for the status they returned, so a
serializer drifting from its documented shape fails the build.

Details, including the coverage ratchet procedure and what CI structurally
*cannot* catch: **[docs/testing.md](docs/testing.md)**.

---

## Documentation

| Where | What |
|---|---|
| [docs/configuration.md](docs/configuration.md) | Every environment variable, how to obtain each key, the first OAuth connection |
| [docs/development.md](docs/development.md) | Branches and releases, naming conventions, project layout, the full Makefile |
| [docs/testing.md](docs/testing.md) | Test layers, coverage gate and ratchet, static analysis, the CI pipeline |
| [docs/operations.md](docs/operations.md) | Production deployment and updates, workers, the dead-letter queue, monitoring, failure alerting, backups and their freshness check |
| [docs/search.md](docs/search.md) | Search backends, provisioning, cutover and rollback, recovery |
| [docs/api.md](docs/api.md) | Versioning, authentication, pagination, rate limits, examples |
| [docs/pwa.md](docs/pwa.md) | Service Worker, offline reads and the offline write queue, push |
| [`CLAUDE.md`](CLAUDE.md) | Working context for Claude Code — current rules and conventions |
| [`CHANGELOG.md`](CHANGELOG.md) | Release history |
| [Confluence](https://honemanager.atlassian.net/wiki/spaces/H/pages/46661633) | ADRs, module pages, runbooks |

---

## License

Private, single-user project. No public license — contact the author before use.
