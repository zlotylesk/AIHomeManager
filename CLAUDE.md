# AIHomeManager — Claude Code Context

Single-user system for automating everyday activities. Stack: PHP 8.5 + Symfony 8 + MySQL 8.4 LTS + Redis 8 + RabbitMQ 4.x. Hexagonal architecture, CQRS with two buses.

**Modules:** Series, Tasks, Books, Articles, Music, YouTubeProgress. Dual-track frontend: the Series + Books + YouTubeProgress UI via Webpack Encore + Stimulus (`app/assets/`); Tasks/Articles/Music on Twig + vanilla JS (`app/public/js/`) with `window.apiCall` from `public/js/util.js`.

**Status:** operational project, latest tag `1.17.0` (maintenance release — epic HMAI-227: runtime PHP 8.4→8.5, infrastructure images bumped/pinned to supported lines [MySQL 8.4 LTS, Redis 8, RabbitMQ 4.x after 3.12 EOL, Graylog 6.3 + MongoDB 7], frontend build on Encore 7 / Babel 8 / webpack-cli 7 ESM, in-range Composer/npm bumps [doctrine-migrations-bundle 4, php-cs-fixer, webpack, @playwright/test]; Symfony deliberately held at 8.0.\* — HMAI-225 deferred, `allow_no_handlers` regression in framework-bundle 8.1.0. No domain-model changes; 930 PHP / 52 Playwright / 43 Newman with no change in test count. Previous: 1.16.0 Books+Music GUI, 1.15.0 Articles GUI, 1.14.0 Tasks GUI). Six domain modules in production. Full history → [CHANGELOG.md](CHANGELOG.md).

## Architecture — INVIOLABLE RULES

- Hexagonal: `src/Module/{Name}/{Domain,Application,Infrastructure}/`
- Domain free of framework: `grep -r "use Doctrine" src/Module/*/Domain/` MUST return an empty result. CI gate: `make deptrac` — Domain → [] at the token level, cross-module coupling forbidden
- Doctrine XML in `Infrastructure/Persistence/Doctrine/*.orm.xml` — do NOT migrate to PHP attributes (ADR-001)
- Domain Events: the aggregate collects them in `$recordedEvents`, the handler dispatches after `releaseEvents()`. Pattern: the `Series` aggregate
- Query handlers: DBAL, NOT ORM (do not hydrate aggregates for reads)
- Command handler: `#[AsMessageHandler(bus: 'command.bus')]`
- Query handler: `#[AsMessageHandler(bus: 'query.bus')]`
- Event handler: `#[AsMessageHandler]` without `bus:` (default)
- `event.bus` configured with `allow_no_handlers: true` — domain events are fire-and-forget, the subscriber is optional
- **Bus dispatch in controllers goes through the typed wrappers `App\Messaging\{QueryBus,CommandBus}` (HMAI-241), NOT the raw `MessageBusInterface`:** both use Symfony `HandleTrait`. `QueryBus::ask($q)` and `CommandBus::dispatchAndReturn($cmd)` return the single handler's result (they throw `LogicException` when no handler ran instead of dereferencing `null` — eliminating the null-unsafe chain `->dispatch(...)->last(HandledStamp::class)->getResult()`). `CommandBus::dispatch($cmd, $stamps=[])` is a fire-and-forget passthrough — **for async-routed commands** (e.g. `ImportWatchedShowsFromTrakt`, `RefreshDiscogsCollection`) it MUST go this way, because they get a `SentStamp` not a `HandledStamp` (they would throw through `HandleTrait`). Autowiring: `CommandBus` → default bus, `QueryBus` via `#[Target('query.bus')]` in the wrapper's constructor. Regression: `tests/Unit/Messaging/{QueryBus,CommandBus}Test.php`

## Naming conventions

| Element | Pattern | Location |
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

- **Series + Books + YouTubeProgress UI:** Webpack Encore + Stimulus. Stimulus controllers in `assets/controllers/{series,books,youtube_progress}_controller.js`, mounted via `data-controller="..."` on `app/templates/{series,books,youtube_progress}/index.html.twig`. Build: `make assets-prod` → `public/build/*.{js,css}` + `entrypoints.json` manifest. `base.html.twig` uses `{{ encore_entry_link_tags('app') }}` + `{{ encore_entry_script_tags('app') }}`.
- **The remaining modules** (Tasks/Articles/Music): Twig + vanilla JS in `public/js/*.js`, global helpers `window.TOAST_TIMEOUT_MS` / `window.safeUrl` / `window.apiCall` from `public/js/util.js`.
- Routes: `/` → redirect, `/series`, `/tasks`, `/books`, `/articles`, `/music`, `/youtube-progress`
- YouTubeProgress panel (`/youtube-progress`): `YouTubeProgressController` (`^/api/youtube-progress/*`) — `GET watchlist` + `GET sessions` read directly through the Domain repos (no query layer); `POST sync` (dispatch `SyncWatchlist`+`RegenerateSessions`, 400 when `YOUTUBE_WATCHLIST_PLAYLIST_ID` is empty), `POST videos/{id}/start|watched`, `POST sessions/{id}/push-to-youtube` dispatch command handlers (404/idempotency in the handlers, unwrap via `ApiExceptionListener`). The Twig page is routed from `FrontendController` like the rest of the nav.
- Series rating selector: 10 buttons (NOT `<input type=number>`)
- Series — own rating: `Series` and `Season` have their **own, optional `?Rating`** (VO → `rating_value` column on `series` and `series_seasons`, nullable; the same pattern as `Episode`), independent of the average from episodes. **Mapping `?Rating` via the custom DBAL type `series_rating` (`Infrastructure/.../Doctrine/Type/RatingType.php`, the `SeriesStatusType` pattern), NOT an embeddable:** a nullable embeddable hydrates as a **non-null** object with an uninitialized `$value` when the column is NULL — harmless for the write path (set-only) and read path (DBAL), but it blows up every read of a hydrated VO (importing ratings from Trakt). The custom type round-trips `null` cleanly both ways, so `Series/Season/Episode::rating()` returns a real `null`. Aggregate: `Series::rate()` / `Series::rateSeason()` (delegates to `Season::rate()`) + clearing `Series::clearRating()` / `Series::clearSeasonRating()` (delegates to `Season::clearRating()`) — no Domain Event (no subscriber, YAGNI). Commands `RateSeries`/`RateSeason` on `command.bus` (field `?int $rating` — `null` = clear). Endpoints `PATCH /api/series/{id}/rating` and `PATCH /api/series/{seriesId}/seasons/{seasonId}/rating` (body `{rating:1..10}` → 204 sets it; `{rating:null}` → 204 clears the own rating; 422 out of range OR missing `rating` key — `parseRating` distinguishes an explicit `null` from absence via `array_key_exists`; 404 not found). `GET /api/series/{id}` returns, for the show and the season, the disjoint `rating` (own) AND `averageRating` (computed from episodes). "My rating" controls in the show and season header (`series_controller.js`, reuse `renderRatingSelector`); when a rating is set, the "✕" button clears it (PATCH `{rating:null}`)
- Series — episode watched: `Episode` has a `watched` flag (bool, NOT NULL DEFAULT 0) + nullable `watchedAt` (`datetime_immutable`) — the `watched`/`watched_at` columns on `series_episodes` (the mapping declares the same `default: 0`, without which `schema:validate` drifts and raw-insert tests fail on "no default value"). Aggregate: `Series::setEpisodeWatched()` (delegates to `Episode::markWatched(?$watchedAt)` / `unmarkWatched()`) — no Domain Event (YAGNI), the optional `?$watchedAt` leaves a door open for the Trakt import to use a real date. Command `SetEpisodeWatched` on `command.bus`. Endpoint `PATCH /api/series/{seriesId}/seasons/{seasonId}/episodes/{episodeId}/watched` (body `{watched:bool}` → 204, 422 when non-bool, 404 when the show/season/episode is missing). `GET /api/series/{id}` returns per-episode `watched`/`watchedAt` plus `watchedCount`/`episodeCount` counters at the season and show level. UI: a "Watched" column (checkbox `.js-episode-watched`) + an `x/y watched` counter in the season header (`series_controller.js`)
- Series — deletion: `DELETE /api/series/{id}` (cascades seasons+episodes), `DELETE /api/series/{seriesId}/seasons/{seasonId}` (cascades episodes), `DELETE /api/series/{seriesId}/seasons/{seasonId}/episodes/{episodeId}` — all 204, 404 when missing. Commands `DeleteSeries`/`DeleteSeason`/`DeleteEpisode` on `command.bus`. Aggregate: `Series::removeSeason()` / `Series::removeEpisode()` (delegates to `Season::removeEpisode()`) return the removed entity and throw a `DomainException` (→404) when missing. **Cascade is explicit in the repo** (`SeriesRepositoryInterface::delete/deleteSeason/deleteEpisode` → `EntityManager::remove` + flush) — the aggregate has NO ORM associations (entities persisted manually via string FKs), so `orphanRemoval`/`cascade` will not work. The handlers invalidate Redis `series:avg:{id}` / `season:avg:{id}` after deletion. UI: trash buttons (🗑) in the show, season, and episode-row header with `confirm()` (`series_controller.js`)
- Series — editing: `PATCH /api/series/{id}` (body `{title}`), `PATCH /api/series/{seriesId}/seasons/{seasonId}` (body `{number}`), `PATCH /api/series/{seriesId}/seasons/{seasonId}/episodes/{episodeId}` (body `{title}`) — all 204, 422 (empty/`>255` title or number `<1`/non-int), 404 not found. **Renumbering a season to a number already used in the show → 409 Conflict** (dedicated `Domain\Exception\SeasonNumberAlreadyTaken extends DomainException`; the controller checks it BEFORE the generic `DomainException`, since it extends it). Commands `RenameSeries`/`RenumberSeason`/`RenameEpisode` on `command.bus`; aggregate `Series::rename()`/`renumberSeason()`/`renameEpisode()` (`Season::renumber()`, `Episode::rename()`) — the `title`/`number` fields stopped being `readonly`, persistence via a plain `save()` (Doctrine UoW change-tracking of managed entities, no new repo method). Shared title validation: `SeriesController::parseTitle()` (reused in create/addEpisode/rename). UI: inline-edit (click the title/number → input, Enter/blur saves, Esc cancels) — `buildInlineEditable()` in `series_controller.js`
- Series — catalog metadata: `Series` gains 4 **optional** fields: `?string $coverUrl` (the `cover_url` column, validated by the VO `Series\Domain\ValueObject\CoverUrl` — a copy of the Books pattern, http/https + `FILTER_VALIDATE_URL`; cross-module reuse is forbidden by deptrac so each module has its own copy), `?int $year`, `?SeriesStatus $status` (enum `Domain\Enum\SeriesStatus` = `ongoing`/`ended`, the `status` column via the custom DBAL type `series_status` in `Infrastructure/.../Doctrine/Type/SeriesStatusType.php` — the `BookStatusType` pattern, nullable round-trip; registered in `doctrine.yaml`), `?string $description` (the `description` column LONGTEXT = Doctrine `text`). Migration `Version20260613000001` (4 nullable columns). Aggregate: `Series::updateMetadata(?coverUrl, ?year, ?status, ?description)` = **full replace** (each field overwritten, `null` clears) — no Domain Event. `POST /api/series` and `PATCH /api/series/{id}` accept `{coverUrl, year, status, description}` (camelCase); validation in `SeriesController::parseMetadata()` (coverUrl via the VO → 422; year `int` in `[1900, year+5]`; status via `SeriesStatus::tryFrom`; description ≤2000 characters). **PATCH = partial-safe**: title still via `RenameSeries`, metadata dispatches `UpdateSeriesMetadata` **only when ≥1 metadata key is present** (`array_key_exists`) — a bare `{title}` (inline edit) does NOT zero out the metadata; the full edit form sends all 4. Command `UpdateSeriesMetadata` on `command.bus` (404 when the show is missing). `GET /api/series` + `/{id}` return `coverUrl`/`year`/`status`/`description`. UI: fields in the "New Series" modal + a "✎ Edit details" form in the detail (`buildEditDetailsForm`/`readMetadataInputs`), a poster thumbnail (2:3) on the list card + poster/year/status/description in the detail header (`series_controller.js`). Regression: `Unit/.../ValueObject/CoverUrlTest`, `SeriesAggregateTest::testUpdateMetadata*`, `SeriesApiTest` (create+GET metadata, PATCH update/clear/partial-safe, 422 for a bad coverUrl/year/status)
- Series — real episode number: `Episode` has `int $number` (the `number` column on `series_episodes`, NOT NULL, no default) — **unique within a season**, validated in `Season::addEpisode()` (throws `InvalidArgumentException` → 422 on a duplicate; allows re-adding the same id = replacement). `POST /api/series/{s}/seasons/{se}/episodes` now requires `{number:int≥1, title, rating?}` (422 for non-int/`<1`/duplicate). `GET` returns `number` per episode; reads sort `ORDER BY e.number` (query handlers), the UI renders `ep.number` (not the loop index) and sorts by number. Migration `Version20260612000004` backfills existing episodes with `ROW_NUMBER() OVER (PARTITION BY season_id ORDER BY id)` (= the previous UI order), then `MODIFY ... NOT NULL`. **Raw-insert tests into `series_episodes` must provide `number`** (NOT NULL with no default — `EpisodeRatedHandlerTest`). The add-episode form has a number field (default = max+1)
- Series — list: search + sorting + own rating on the card (**frontend-only** — `GET /api/series` already returns the full set): the `#series-toolbar` toolbar (input `#series-search` + select `#series-sort`) in `series/index.html.twig`, hidden when there are zero shows. `series_controller.js` keeps the full list in `this.allSeries`, `applyListView()` filters by title (case-insensitive, live) and sorts via `sortSeries()` (keys: `title` A–Z / `rating-desc` average ↓ / `own-desc` own ↓ / `created-desc` newest — `null` ratings at the end). An empty list vs no matches are two different states ("No series yet" vs "No series match your search"). The card shows the disjoint `cardRating('My', rating)` + `cardRating('Avg', averageRating)`. E2E: `series.desktop.spec.ts` (the filter narrows, `created-desc` changes the order)
- Series — coloring of cards/seasons by rating (**frontend-only** — `serializeDTO`, shared by list and detail, returns per-show/per-season `rating`/`averageRating`/`watchedCount`/`episodeCount`): a subtle card background on the list and on the show/season headers in the detail. Helpers in `series_controller.js`: `ratingHighlight(entity)` returns `'incomplete'|'mismatch'|null` (**`incomplete` = `episodeCount>0 && watchedCount<episodeCount`** has **priority** over `mismatch`, because the average of a partially watched show is unreliable; `mismatch` = `Math.round(averageRating) !== rating`, and also "there is an average, no own rating"), `ratingFlag()` → `{cls,title}` for the show/season headers, `cardRatingFlag(s)` → card: amber when the show is incomplete, otherwise red when the show **OR any season** has a `mismatch`. CSS classes `.is-rating-incomplete` (amber `#fff8e1`) / `.is-rating-mismatch` (red `#fdecea`) in `app.css` (compounded with `.series-card`/`.season-header`/`.series-detail-header` for specificity) + a `title` tooltip (a11y — color is not the only carrier). E2E: `series.desktop.spec.ts` (list stub → mismatch=red / incomplete=amber with priority / aligned=neutral)
- Series — import from Trakt (GUI): `POST /api/series/import/trakt` dispatches `ImportWatchedShowsFromTrakt` (async) → **202** `{status:"import_started"}` immediately (the import is rate-limited + I/O bound, it NEVER blocks the request — the same pattern as `RefreshDiscogsCollection`). **No Trakt token → 409** `{error, authUrl:"/auth/trakt"}` BEFORE the dispatch (`SeriesController::isTraktConnected()` reads `TraktTokenRepositoryInterface::get()` — the token itself, no network I/O; refresh-on-expiry is done by the worker). UI: an "Import from Trakt" button in the Series header (`#btn-import-trakt`, `series_controller.js`) → `API.importFromTrakt()`; 202 shows `#info-banner` ("import started…", auto-hide), 409 sets `#error-banner` with a `/auth/trakt` link (no auto-hide). The `#info-banner` banner is in `base.html.twig` (green, `.info-banner` in `app.css`). Regression: `ImportFromTraktApiTest` (409 not-connected with no dispatch / 202 + dispatch to the async transport), E2E `series.desktop.spec.ts` (202 stub → info banner / 409 stub → connect link)
- Tasks API: full REST CRUD (`POST/GET/GET{id}/PATCH{id}/DELETE{id} /api/tasks`, `POST {id}/complete`, `POST {id}/cancel`) + `/time-report` + `/export`. Google Calendar sync via `CalendarServiceInterface` with graceful degrade

### Webpack Encore

| File | Role |
|---|---|
| `app/webpack.config.js` | Encore 7 config (**ESM**: `import Encore`, top-level `await Encore.getWebpackConfig()`, `"type":"module"` in `package.json`) — entry `app`, splitEntryChunks, Stimulus bridge, versioning prod-only, `configureBabel` with `polyfill-corejs3` |
| `app/assets/app.js` | Main entry — imports `bootstrap.js` (Stimulus) + `styles/app.css` |
| `app/assets/bootstrap.js` | `startStimulusApp` auto-discovery from `controllers/` via **`import.meta.webpackContext`** (ESM-native; NOT `require.context` — see below) |
| `app/assets/util.js` | ES module export: `TOAST_TIMEOUT_MS`, `safeUrl`, `apiCall`, `escHtml` |
| `app/assets/controllers/series_controller.js` | Stimulus controller for the Series UI |
| `app/assets/controllers/books_controller.js` | Stimulus controller for the Books UI |
| `app/assets/controllers/youtube_progress_controller.js` | Stimulus controller for the YouTubeProgress panel (sync/start/watched/push) |
| `app/assets/styles/app.css` | Global stylesheet (the single source of truth) |

Commands: `make assets` (dev), `make assets-watch` (watch mode), `make assets-prod` (production), `make node-audit` (CVE gate). Node service: `aihm-node-1` (`node:24-alpine`, mounted on `./app`). `make node-install` re-runs `npm install` after a `package.json` change.

`public/build/` + `node_modules/` in `.gitignore`. CI builds the assets in the `tests` and `e2e-playwright` jobs (`npm ci && npm run build` in `app/`) before PHPUnit/Playwright — without this Twig `encore_entry_*` throws 500.

**Encore 7 + Babel 8 + webpack-cli 7 (ESM, HMAI-226):** `webpack.config.js` is **ESM** (`import Encore`, top-level `await Encore.getWebpackConfig()`, `"type":"module"` in `package.json`). `@symfony/webpack-encore@7.1` declares `peer webpack-cli@^6 || ^7` — `webpack-cli` is pinned to 7.x. Babel 8 (`@babel/core`/`@babel/preset-env` 8.x) removed the `useBuiltIns`/`corejs` options from `preset-env`; core-js polyfills are now injected by `babel-plugin-polyfill-corejs3` via `configureBabel` (push `['polyfill-corejs3',{method:'usage-global',version:'3.38'}]`). The polyfill targets go through `"browserslist": ["defaults"]` in `package.json` (Encore sets preset-env `targets:{}` → falls back to browserslist; the polyfill plugin reads the same browserslist) — `defaults` (2026 baseline) natively supports `at`/`replaceAll`/`fromEntries`/`allSettled`, so our modern code gets **0 polyfills**. **Stimulus bootstrap under ESM (GOTCHA):** `assets/bootstrap.js` loads controllers via `import.meta.webpackContext` (**NOT** `require.context`) — `"type":"module"` makes webpack parse the asset `.js` files as `javascript/esm`, where the CommonJS `require.context` stays a raw `require` reference → runtime `ReferenceError: require is not defined` at startup, dead Stimulus (zero controllers mount). The build **compiles anyway** (a free var ≠ a compile error), so the bug is only caught by E2E in the browser (a green `npm run build` alone is not enough — you need a real page boot). `import.meta.webpackContext` is the webpack-5 ESM-native equivalent that the parser transforms in an ESM module. Three `ignore` rules in `.github/dependabot.yml` (`webpack-cli` + `@babel/core` + `@babel/preset-env`) were **removed** — Encore 7 covers those majors with its peer range, so Dependabot refreshes them again. **Caveat for the future:** another major of an Encore peer outside its range (e.g. `webpack-cli` 8 outside `^6||^7`) will again break `npm ci` with `ERESOLVE` — do not merge a peer major before Encore widens its peer range (precedent: the ill-fated auto-merge of webpack-cli 7 / Babel 8 on Encore 6 → hotfixes PR #212/#231).

**Held dependency bump (HMAI-224, PHP 8.5 maintenance):** after closing out Encore 7 (HMAI-226), **Symfony is the only deliberately held bump** on the target PHP 8.5 stack — it remains the sole `outdated`, awaiting a separate ticket HMAI-225:
- **Symfony held at 8.0.*** (not 8.1): `symfony/framework-bundle 8.1.0` has a regression — the resolved config `event.bus → default_middleware.allow_no_handlers: true` does NOT reach the compiled `HandleMessageMiddleware` (the value lands in the wrong constructor argument after the signature change in 8.1; `allowNoHandlers` stays `false`). Effect: all fire-and-forget domain events without a handler (`TaskCreated/Updated/Completed/Cancelled/Deleted`) throw `NoHandlerForMessageException` → 500 → 11 red Tasks tests. Unblock when 8.1.1+ ships with a fix (verify that the `event.bus` middleware compiles with `allowNoHandlers=true`).

(`newman 7` still does not exist — latest is 6.2.2 — the root stays on newman 6.x; see the section below.)

**npm audit gate:** every frontend `npm ci` of deps (`tests` job + `e2e-playwright`, both in `app/`) is immediately followed by `npm audit --audit-level=high`. Low/moderate are noise for devDeps and are let through; high+critical block the merge. Fix = bump the package (`npm install pkg@latest`), not suppress — an advisory on the installed version is a legit signal. Locally: `make node-audit`.

The root `package.json` (Playwright + Newman) is **deliberately outside the gate**: newman 6.x (latest stable) pulls a deep-transitive CVE in `handlebars`/`lodash`/`postman-*` with no forward fix from the vendor; `audit fix --force` would roll back to newman 2.1.2 and break the Postman collection. Re-evaluate when newman 7.x ships with a clean dependency tree — then the gate returns to the root.

## Infrastructure

| Service | Container / Port | Notes |
|---|---|---|
| MySQL 8.4 LTS | `mysql:3306` | Image pinned to `mysql:8.4` (the LTS line) in compose **and** CI (×3 jobs) — NOT the floating `mysql:8` (its tag currently still resolves to 8.4.9, but after 8.0 EOL it would jump to 9.x innovation; the pin = reproducibility dev↔CI↔prod). DB `homemanager`. **`serverVersion=8.0` in the DSN stays deliberately** (NOT 8.4): DBAL 4.4 for `8.4` picks `MySQL84Platform`, which is `@deprecated` and differs from `MySQL80Platform` **only** in the reserved-keyword list (zero changes in schema SQL generation) — the 8.0 platform is fully compatible with the 8.4 server, `schema:validate` with no drift. We stay on **8.4 LTS, not 9.x** (9.x = innovation, short support; LTS = predictability for a single-user/single-disk setup) |
| Redis 8 | `redis:6379` | Keys `series:avg:{id}`, `season:avg:{id}` (TTL 3600) set directly via `\Redis` in `EpisodeRatedHandler` (not via a Symfony cache pool — the handler injects `\Redis`). The `cache.rate_limiter` pool is used by the RateLimiter |
| RabbitMQ 4.x | `rabbitmq:5672` (AMQP), `:15672` UI (guest/guest) | Image pinned to `rabbitmq:4-management-alpine` (3.12 was EOL; the major pin `:4` keeps the supported 4.x line — Khepri metadata backend, quorum queues by default). Transport `async`, exchange `series_events` (topic) + **classic queues** (NOT mirrored — removed in 4.0; we do not use them, so the bump = low BC risk), retry 3× (1s→2s→4s, max 30s), DLQ `failed`. Metadata is ephemeral (no data volume) — a restart = a fresh broker, Messenger auto-declares the exchange/queues on connect |
| Worker Messenger | `messenger_worker` | `messenger:consume async --time-limit=3600 -vv` |
| Worker Scheduler | `scheduler_worker` | `messenger:consume scheduler_default --time-limit=3600 -vv` |
| Node (Encore build) | `node:24-alpine`, container `aihm-node-1` | Long-running `tail -f /dev/null`. `docker compose exec node npm ...` |
| Graylog 6.3 | `monitoring` profile, UI `:9000` (admin/admin), GELF UDP `:12201` | In `make up` (full stack); `make min-up` = lean without monitoring. The Monolog `series`/`auth` channels go through GELF — `gelf.transport` is wrapped in `IgnoreErrorTransportWrapper`, so no Graylog ≠ 500 (graceful degrade, logs dropped). The stack is coupled: `graylog/graylog:6.3` ↔ `mongo:7` (metadata; Graylog 6.3 supports 5.0.7–8.x, the 6→7 bump = a single major step) ↔ `opensearchproject/opensearch:2` (search; **Graylog 6.3 supports OpenSearch only 1.1–2.19.5, NOT 3.x** — `:2` stays for the foreseeable future). External OpenSearch via `GRAYLOG_ELASTICSEARCH_HOSTS` (Graylog Open, no Data Node). `GRAYLOG_*` env unchanged from 5.2; the `scripts/graylog-bootstrap.sh` API (`/api/system/inputs`, index_sets, streams) is stable in 6.x — no changes |

In tests: the `async` and `failed` transports → `in-memory://` (`when@test` in `messenger.yaml`).

Async messages routed to the `async` transport: `Series\Domain\Event\EpisodeRated`, `Series\Application\Command\ImportWatchedShowsFromTrakt` (import of watched shows from Trakt offloaded from the request, pinned by `ImportWatchedShowsRoutingTest`), `Series\Application\Command\ImportRatingsFromTrakt` (import of ratings from Trakt, chained after the watched import, pinned by `ImportRatingsRoutingTest`), `Music\Application\Command\RefreshDiscogsCollection` (fetch of the Discogs collection offloaded from the request, the `/api/music/collection` endpoint returns the cache + dispatches a refresh on a miss), `Music\Application\Command\PollLastFmRecentTracks` (scheduler poll every 30 min, the handler dispatches `LogListeningSession` per track on the sync command.bus). `Books\Domain\Event\BookCompleted` is deliberately sync (in-memory) — no handler, no I/O side-effects (ADR-006). Pinned by `BookCompletedRoutingTest`.

`NewRelicMonologHandler` (`src/Module/Series/Infrastructure/Logging/`) — graceful degrade when the `newrelic` extension is absent.

GELF UDP input + index sets + streams: `make monitoring-bootstrap` (the idempotent script `scripts/graylog-bootstrap.sh`). Creates the GELF UDP input, the `auth-events` (90 days, time-based) and `series-events` (30 days, time-based) index sets with the corresponding streams filtering by `channel`. Requires a running Graylog (`make monitoring-up` first).

## Symfony Scheduler

`src/Schedule.php` registers 5 recurring tasks (via `dragonmantank/cron-expression`):

| Cron | Message | Effect |
|---|---|---|
| `0 0 * * *` | `Articles\...\ResetDailyArticleCache` | Removes Redis `articles:today`, deletes `article_daily_picks` > 7 days old |
| `0 3 * * *` | `App\Application\Scheduled\BackupDatabase` | mysqldump + gzip → `/backups/homemanager-YYYY-MM-DD.sql.gz`, retention 30 daily + 12 monthly |
| `0 8 * * 1` | `App\Application\Scheduled\GenerateWeeklyActivityReport` | Logs `scheduled_task=weekly_report` to the default channel (read_articles, pages_read, completed_tasks, rated_episodes_total) |
| `0 */6 * * *` | `Music\...\RefreshDiscogsCollection` | Pre-warms the collection cache before the 6h TTL expires |
| `*/30 * * * *` | `Music\...\PollLastFmRecentTracks` | Polls Last.fm recent tracks → local listening history, idempotent via `dedup_hash` |

Worker: `bin/console debug:scheduler` shows the state; `docker compose up -d scheduler_worker` consumes the `scheduler_default` transport. Stateful via `cache.app` (filesystem, mounted on the host) — a worker restart fires at most 1 missed window (`processOnlyLastMissedRun(true)`).

### .env — key entries

```
DATABASE_URL=mysql://homemanager:homemanager@mysql:3306/homemanager?serverVersion=8.0&charset=utf8mb4
MESSENGER_TRANSPORT_DSN=amqp://guest:guest@rabbitmq:5672/%2f/messages
REDIS_URL=redis://redis:6379
GRAYLOG_HOST, GRAYLOG_PORT=12201
NEW_RELIC_LICENSE_KEY, NEW_RELIC_APP_NAME
```

## Makefile commands

| Action | Command |
|---|---|
| Start the environment (full stack + monitoring) | `make up` |
| Start the environment (lean, no monitoring) | `make min-up` |
| Full initialization | `make setup` |
| PHP shell | `make shell` |
| All tests | `make test` |
| Domain only | `make test-unit` |
| Integration only | `make test-integration` |
| Cache clear | `make cc` |
| Migrations dev/test | `make migrate` / `make migrate-test` |
| Logs | `make logs` (all) / `make logs-{php,nginx,mysql,redis,rabbitmq,worker,scheduler,node}` (per-service) |
| Routing | `make routes` |
| Containers | `make services` |
| Worker status | `make messenger-status` |
| Preflight env health check | `make doctor` |
| Monitoring up/down/logs | `make monitoring-up` / `make monitoring-down` / `make monitoring-logs` |
| Graylog bootstrap (inputs+indexes+streams) | `make monitoring-bootstrap` |
| E2E (Playwright) install/run | `make test-e2e-install` / `make test-e2e` |
| Newman (Postman REST collection) | `make test-newman-install` / `make test-newman` |
| Load fixtures (dev) | `make fixtures` |
| Webpack Encore dev/watch/prod | `make assets` / `make assets-watch` / `make assets-prod` |
| Npm install (after a `package.json` change) | `make node-install` |
| npm audit (high+critical CVE gate) | `make node-audit` |
| Backup MySQL (manual) | `make backup-now` |
| Restore MySQL | `make restore BACKUP=backups/homemanager-YYYY-MM-DD.sql.gz` |

## Tests

- Unit: `tests/Unit/Module/{Name}/Domain/` — pattern `tests/Unit/Module/Series/Domain/SeriesAggregateTest.php`
- Integration: `tests/Integration/`
- Fixtures: `tests/Integration/DataFixtures/FixturesLoadTest.php` — checks that `make fixtures` produces a stable data structure
- E2E: `tests-e2e/` (Playwright, TypeScript). Files match `*.desktop.spec.ts` (1440×900) or `*.mobile.spec.ts` (Pixel 5 viewport) per the project config in `playwright.config.ts`
- Newman/Postman: `tests-e2e/postman/AIHomeManager.postman_collection.json`. Run via `make test-newman` (truncate + newman with `--ignore-redirects`); details in `tests-e2e/postman/README.md`
- Framework: PHPUnit 13 + @playwright/test 1.61 + newman 6.x
- **PHPUnit gates**: `phpunit.dist.xml` has `failOnDeprecation="true"` + `failOnPhpunitDeprecation="true"` + `failOnNotice="true"` + `failOnWarning="true"`. New PHP deprecations in `src/` AND PHPUnit's own deprecations (`->expects(self::any())`, `with()` without `expects()`, etc.) block CI. `<source>` has `ignoreIndirectDeprecations="true"` + `restrictNotices/Warnings="true"` — vendor noise (e.g. google/apiclient's `str_replace null` deprecation) is deliberately filtered. Notices are not on the gate — 41 are data noise from the tests, fix-effort vs value is weak. Locally: `vendor/bin/phpunit --display-phpunit-deprecations` shows the source PHPUnit deprecation; `--display-deprecations` shows the PHP deprecation
- `*ApiTest` tests use `App\Tests\Support\AuthenticatedApiTrait` — it adds the `X-API-Key: test-api-key` header (see `app/.env.test`)
- CI gate: the `tests` job runs `doctrine:schema:validate` after the migrations and before PHPUnit — a drift of the ORM XML mapping vs the MySQL schema blocks the merge (a separate error category, not baked into a test). Locally: `make schema-validate`
- E2E/Newman prerequisite: `API_KEY=e2e-test-key` in `app/.env.local`, Discogs/Last.fm placeholders (`DISCOGS_TOKEN_KEY`, `GOOGLE_TOKEN_KEY`, `DISCOGS_CONSUMER_KEY`, `DISCOGS_CONSUMER_SECRET`, `LASTFM_API_KEY`, `LASTFM_USERNAME`, `DISCOGS_USERNAME`) set to anything non-empty (DI will not boot with empty VOs). The Graylog GELF UDP input is worth configuring for full observability (`make up` now starts monitoring by default, then `make monitoring-bootstrap`; alternatively a manual POST to `/api/system/inputs` with `org.graylog2.inputs.gelf.udp.GELFUDPInput` on `0.0.0.0:12201`). A non-running Graylog does **not** blow up with 500 on `/api/series` anymore — `gelf.transport` is wrapped in `IgnoreErrorTransportWrapper`, so GELF transport errors are swallowed (the `series`/`auth` logs are silently dropped, the request goes on; **this applies to the `dev`/`prod` envs** — in `test` the channels go to `null` anyway). In CI the E2E/Newman jobs run with `APP_ENV=test`, where `monolog when@test` routes the `series`/`auth` channels to `null` handlers → Graylog is not needed. The `*_TOKEN_KEY` keys in CI are **valid base64 32B** (`TokenCipher` throws for any other length — an OAuth-init request would otherwise return 500 instead of 302/502). App server in CI: `symfony server:start --no-tls --port=8080` (serves routing + Encore static assets; a bare `php -S` does not combine the two)

## Security — API Key

- `^/api/*` protected by the `api` firewall in `app/config/packages/security.yaml` (stateless, custom authenticator)
- Authenticator: `App\Security\ApiKeyAuthenticator` — reads the `X-API-Key` header, compares via `hash_equals` with `%env(API_KEY)%`. `supports()` returns `false` for `/api/health` — a public readiness probe for orchestrators.
- 401 JSON `{"error": "..."}` on a missing/invalid key
- Production key in `app/.env.local` (gitignored). `app/.env` has only a placeholder
- `/auth/google*`, `/auth/discogs*`, `/auth/trakt*`, frontend (`/`, `/series`, etc.) — the `main` firewall with `security: false` (public)
- Test env: `API_KEY=test-api-key` in `app/.env.test`
- **CSRF (ADR-005):** we deliberately **do not use** `#[IsCsrfTokenValid]` on `^/api/*`. The firewall is `stateless: true`, authorization via the `X-API-Key` header (not a cookie) — the browser does not set custom headers cross-origin, so CSRF has no path. OAuth init (`/auth/*`) uses the `state` parameter. Regression in `tests/Integration/Security/ApiKeyAuthCsrfTest.php`.

## HTTP security headers

Dual-layer defense-in-depth: nginx (`docker/nginx/default.conf`) + the Symfony `SecurityHeadersListener` (`kernel.response`, priority -128). Both set the same 4 headers on every response:

| Header | Value |
|---|---|
| `X-Content-Type-Options` | `nosniff` |
| `X-Frame-Options` | `DENY` |
| `Referrer-Policy` | `strict-origin-when-cross-origin` |
| `Permissions-Policy` | `geolocation=(), microphone=(), camera=(), payment=()` |

HSTS (`Strict-Transport-Security`) is commented out in nginx — uncomment AFTER configuring HTTPS. `server_tokens off` hides the nginx version.

Regression: `tests/Integration/Security/SecurityHeadersTest.php` (4 tests: frontend, API, error 404, all headers).

## Request correlation

- `App\EventListener\RequestIdListener` — `kernel.request` priority 256 (before `ApiRateLimitListener` @100, so that a 429 carries the correlator), `kernel.response` priority 0. Reads the `X-Request-ID` header from the request or generates a UUID v4. It stores the value in the `_request_id` request attribute and echoes it back in the response header.
- Inbound validation: `^[A-Za-z0-9._-]{1,128}$`. Values outside this set are rejected (a server-generated UUID replaces them) — protection against injecting control characters into the logs.
- `App\Logging\RequestIdProcessor` — invokable, `#[AsMonologProcessor]`. Reads `_request_id` from `RequestStack->getMainRequest()` and adds `extra.request_id` to every `LogRecord` emitted during the request. CLI/worker context (no main request) — passthrough.
- Async (Messenger): propagating the ID to the worker is deliberately out of scope — it requires a separate Stamp + middleware.
- Regression: `tests/Integration/EventListener/RequestIdListenerTest.php` (4 tests: no header, valid echo, invalid replaced, log extra carry).

## API exception listener

- `App\EventListener\ApiExceptionListener` — `kernel.exception` (priority 64, before the framework `ErrorListener` at -64). Converts uncaught throwables on `^/api/*` into a `JsonResponse`.
- `HttpExceptionInterface` (4xx) preserves the status and message; everything else (`RuntimeException`, a `DomainException` outside the controller's catch, etc.) → 500 with a generic `Internal server error.` (the original message only in the log, not in the response).
- `HandlerFailedException` (the Messenger wrap) is unwrapped — the listener uses the previous exception for type checks, so HTTP exceptions from handlers are caught the same as those thrown directly.
- Non-API paths (e.g. `/series`, `/typo`) pass through unchanged — the Twig frontend keeps its rendered error pages.
- The full exception context (path, method, status, exception) is logged at the `error` level via the default channel.

## Health endpoint

- `GET /api/health` — a public readiness probe (no `X-API-Key`)
- Probes: MySQL (`SELECT 1`), Redis (`PING`), RabbitMQ (TCP to the host from `MESSENGER_TRANSPORT_DSN`, timeout 1s), Disk (`disk_free_space('/')`)
- 200 `{"status":"healthy", "components":{"mysql":"up", "redis":"up", "rabbitmq":"up", "disk":"up"}, "timestamp":"..."}` when everything is up
- 503 `"status":"unhealthy"` + a `"down"` component when a probe fails (or disk >95% used) — orchestrators do not route traffic to a degraded instance
- The **disk probe** has 3 states, the rest are still binary up/down:
    - `< 80% used` → `up`
    - `80–95% used` → `degraded` (HTTP 200, `status: "degraded"` in the body — monitoring pages before escalation, traffic still routed)
    - `> 95% used` → `down` (HTTP 503 — MySQL flush/binlog dies with no headroom, make room BEFORE the server crashes)
    - Thresholds hardcoded as consts in `HealthChecker` (`DISK_DEGRADED_RATIO=0.80`, `DISK_DOWN_RATIO=0.95`). YAGNI on an ENV override — a single instance, one disk, one problem.
    - `disk_free_space('/')` measures Docker's overlayfs → reflects the host's space (single-volume setup). Multi-volume (MySQL on a separate data volume) would need a separate probe — out of scope.
- Docker healthcheck on `nginx`: `wget --spider http://localhost/api/health` (interval 30s, retries 3, start_period 30s) — an end-to-end stack probe
- `HealthChecker` (`src/Health/HealthChecker.php`) — `readonly` (NOT `final` so that PHPUnit `createStub` works in the controller test)

## Static Analysis

- **PHPStan** level 8 + `phpstan-symfony` + `phpstan-doctrine` + `phpstan-phpunit`. Config: `app/phpstan.neon.dist`. Baseline `app/phpstan-baseline.neon` — new errors require a fix or extending the baseline via `make phpstan-baseline`
- **PHP CS Fixer**: `@Symfony` + `@PHP84Migration` + `global_namespace_import` (classes imported). Config: `app/.php-cs-fixer.dist.php`
- **Rector**: `withPhpSets()` + `deadCode`. Config: `app/rector.php`
- **Deptrac**: formalizes the hexagonal boundaries — every module has separate `*Domain` / `*Application` / `*Infrastructure` layers. Domain → [] (zero dependencies beyond PHP core), Application → its own Domain + Vendor, Infrastructure → its own Domain + its own Application + Vendor, `Glue` (Controllers/EventListeners/Kernel/Security outside `src/Module/`) → everything. Cross-module coupling forbidden. Config: `app/deptrac.yaml` with a merged `skip_violations` (pre-existing — Domain ports returning Application DTOs in Books/Music, Music/Tasks Infrastructure → `App\Security\TokenCipher`). Regeneration: `make deptrac-baseline` → move `skip_violations` from `deptrac-baseline.yaml` into `deptrac.yaml` and delete the separate file (single source of truth)
- **Composer audit**: `composer audit` (built in since 2.4) queries FriendsOfPHP/security-advisories. CI gate in `static-analysis` after Deptrac — blocks the merge when an advisory appears for an installed package version. Locally: `make audit`. A failure = bump the dep, do not suppress (an advisory failing CI is a legit signal)
- **Dependabot**: `.github/dependabot.yml` — 4 ecosystems: composer (`/app`, weekly Mon, groups `symfony/*`/`doctrine/*`/dev), npm (`/app` + `/`, weekly), github-actions (`/`, monthly). PRs from `dependabot[bot]` go through the same CI gate as user commits — review + merge when green. Dependabot covers freshness, `composer audit`/`npm audit` covers severity-gated regression — complementary, they do not replace each other
- CI: `.github/workflows/ci.yml` — 4 jobs on every push/PR: `static-analysis` (Rector dry-run + CS Fixer + PHPStan level 8 + Deptrac + Composer audit), `tests` (PHPUnit), `e2e-playwright` and `e2e-newman` (both `needs: tests`)
- **CI job timeouts**: every job has an explicit `timeout-minutes` — `static-analysis: 10`, `tests: 15`, `e2e-playwright: 20`, `e2e-newman: 10`. The cap = ~2–3× the observed peak. The GitHub Actions default is 360 min — a runaway/deadlock with no bound eats the whole free-minutes budget on a single hang. After 30 days monitor the real times: if a job approaches its bound (>70%), raise it — do not lower it, because flaky CI is worse than a timeout

| Command | Action |
|---|---|
| `make analyse` | CS Fixer (dry-run) + PHPStan + Deptrac |
| `make phpstan` | PHPStan analyse |
| `make phpstan-baseline` | Regenerate the baseline (after fixing errors) |
| `make cs-check` / `cs-fix` | CS Fixer dry-run / apply |
| `make rector-dry` / `rector` | Rector dry-run / apply |
| `make deptrac` | Deptrac analyse (architecture boundaries) |
| `make deptrac-baseline` | Regenerate the deptrac baseline |
| `make schema-validate` | Doctrine schema validate (ORM XML mapping ↔ MySQL schema) |
| `make audit` | Composer audit (security advisories) |

## Rate limiting — own API + external APIs

- `App\EventListener\ApiRateLimitListener` — `kernel.request` (priority 100, before the router/firewall). Per-IP throttle for `^/api/*` (limiter `api_per_ip`, sliding_window 60/min). Bypass: `/api/health` and `/auth/*`. A 429 returns `Retry-After`, `X-RateLimit-Remaining`, `X-RateLimit-Limit`. Logs `rate_limit_triggered=true` (warning).
- `App\Http\RateLimitedHttpClient` — an `HttpClientInterface` decorator that proactively blocks the request before calling the external API (`reservation->wait()`). Five instances in `services.yaml`: `app.discogs_http_client`, `app.lastfm_http_client`, `app.national_library_http_client`, `app.youtube_http_client`, `app.trakt_http_client` — injected into the respective Music/Books/YouTubeProgress/Series clients.
- Limiters (`app/config/packages/rate_limiter.yaml`): `api_per_ip` (sliding_window, 60/min), `discogs_api` (token_bucket, 60/min), `lastfm_api` (token_bucket, 5/s), `national_library_api` (token_bucket, 60/min), `youtube_api` (token_bucket, 60/min — a soft HTTP fallback under the unit-based YT quota of 10000/day), `trakt_api` (token_bucket, 1000/5min — the Trakt import client)
- `TraktApiClient` (`Series/Infrastructure/External`): `fetchWatchedShows()` → `GET https://api.trakt.tv/sync/watched/shows?extended=full` with the headers `trakt-api-version: 2` + `trakt-api-key: {TRAKT_CLIENT_ID}` + `Authorization: Bearer {access_token}` (the token read from `TraktTokenRepositoryInterface::get()`, NOT from ENV). Returns `list<array{traktId:int, title:string, year:?int, seasons:list<...episodes...>}>` (no cache — the import reads fresh state; Trakt does not provide episode titles here, only numbers + `last_watched_at`). `RuntimeException` when there is no token ("not connected") / an empty client ID / a transport error. The result is consumed by the Trakt library import into the Series module. `fetchRatings()` → three GETs `/sync/ratings/{shows,seasons,episodes}` (the same headers), returns `array{shows,seasons,episodes}` with ratings 1–10 (the `RatingsProviderInterface` port); a shared private `get()` does the token/client guard + error handling for both methods
- Trakt import → Series: command `ImportWatchedShowsFromTrakt` (`Series/Application/Command`, **no payload** — single-user) + handler `#[AsMessageHandler(bus:'command.bus')]`, routing `async` (RabbitMQ; `in-memory` in tests, pinned by `ImportWatchedShowsRoutingTest`). Maps watched shows from Trakt onto the `Series` aggregate (+ seasons + episodes with the `watched` flag and a real `watchedAt` from `last_watched_at`), **idempotently**: dedup the show by `trakt_id`, the season by `number` within the show, the episode by `number` within the season — a re-import does not create duplicates nor overwrite already-watched episodes (`save()` only when something changed). The criterion for importing a show = ≥1 watched episode; empty shows/seasons are not materialized. Trakt does not provide episode titles here → placeholder `Episode {n}` (the user can change it). No Trakt token → a `RuntimeException` from the client propagates (worker retry/DLQ; UX handling in a separate layer). Regression: `ImportWatchedShowsFromTraktHandlerTest` (fresh import / idempotency / match-by-`trakt_id` + flip watched / skip an empty show / propagation of a missing token)
- Trakt ratings import: command `ImportRatingsFromTrakt` (`Series/Application/Command`, **no payload**) + handler, routing `async` (pinned by `ImportRatingsRoutingTest`), **chained at the end** of `ImportWatchedShowsFromTraktHandler` (ratings go after the watched ones, once the shows/seasons/episodes already exist — a single "Import from Trakt" button does both). Maps Trakt ratings 1–10 onto the aggregate's **own** ratings (`Series::rate()`/`rateSeason()`/`rateEpisode()`), **skip-if-missing** (a rating for a non-imported show/season/episode is skipped — no materialization of unwatched entities) + **idempotently** (`save()` only when the rating differs from the stored one; grouping by `trakt_id` → 1 load/save per show). Episode ratings deliberately do **NOT** dispatch `EpisodeRated` (bulk = thousands of Redis-recompute events; averages computed live in `SeriesController::serializeDTO`). Regression: `ImportRatingsFromTraktHandlerTest` (apply show/season/episode / skip-missing / idempotency), `TraktApiClientTest::testParsesRatingsIntoStructuredShape`, the chain in `ImportWatchedShowsFromTraktHandlerTest::testChainsRatingsImportAfterWatchedShows`
- Storage: the `cache.rate_limiter` pool (Redis) in prod/dev. In tests `Symfony\Component\RateLimiter\Storage\InMemoryStorage` — not tagged `kernel.reset`, so the state survives request → request when `KernelBrowser::disableReboot()`. External limiters in tests use the `no_limit` policy
- Distributed lock: `LOCK_DSN=redis://redis:6379` (`.env`) — coordination web ↔ worker
- `DiscogsApiClient::fetchAllPages` — throttling is now done by `RateLimitedHttpClient`
- Exceptions/boundaries: `/auth/*` is outside `^/api/*` so the listener does not touch it; `/api/health` is explicitly excluded; the Google Calendar SDK uses its own HTTP client (not Symfony) and is NOT covered by the decorator — the 1M/day limit leaves a wide margin

## Encryption — OAuth tokens

- `App\Security\TokenCipher` (libsodium `secretbox`, format: base64(nonce ‖ ciphertext)) — a shared tool for all OAuth providers
- Three instances in `services.yaml`: `app.discogs_token_cipher` (key `DISCOGS_TOKEN_KEY`), `app.google_token_cipher` (`GOOGLE_TOKEN_KEY`) and `app.trakt_token_cipher` (`TRAKT_TOKEN_KEY`) — separate keys split the blast radius
- 32B base64 keys in `.env.local`. Generate: `php -r "echo base64_encode(sodium_crypto_secretbox_keygen());"`
- Discogs OAuth1: `DiscogsTokenRepository` (Music) — field-by-field encryption (`oauth_token`, `oauth_token_secret`)
- Google OAuth2: `GoogleOAuthTokenRepository` (Tasks) — encrypts the whole `token_json` (access+refresh+expires). Scope claims are cumulative on the refresh token: `calendar.events` (Tasks) + `youtube` (YouTubeProgress, full read/write — required by `createPlaylist`). One token, two modules — `GoogleClientFactory::create()` requests both scopes, `setPrompt('consent')` forces a re-consent. **After widening the scope with `youtube` the user MUST go through `/auth/google` once** to obtain a token with the widened scope — without it a YT API call returns 403. Regression: `tests/Integration/Module/YouTubeProgress/GoogleClientYouTubeScopeTest.php`
- Trakt OAuth2: `TraktOAuthTokenRepository` (Series/Infrastructure) — encrypts the whole `token_json`, the Google pattern. Flow `/auth/trakt` (302→`trakt.tv/oauth/authorize` with `state` in the session) + `/auth/trakt/callback` (code→token via `HttpClientInterface`, encrypted save, redirect `/series`). `TraktTokenProvider::getValidAccessToken()` does refresh-on-expiry (grant `refresh_token`) — layer 2 (TraktApiClient) injects the provider instead of the repo. The `trakt_oauth_tokens` table (DBAL, non-ORM) is excluded from `doctrine.dbal.schema_filter` like `google_oauth_tokens`. ENV: `TRAKT_CLIENT_ID`/`TRAKT_CLIENT_SECRET`/`TRAKT_REDIRECT_URI`. Regression: `tests/Integration/Auth/TraktAuthControllerTest.php`, `tests/Unit/Module/Series/Infrastructure/TraktTokenProviderTest.php`

## MCP servers (`.mcp.json`)

- `sequential-thinking` (npx)
- `github` (npx — requires `GITHUB_PERSONAL_ACCESS_TOKEN`)
- `context7` (npx — Symfony/Doctrine/PHP docs)
- `filesystem` (npx — root: AIHM)
- `mysql` (npx — `@benborla29/mcp-server-mysql`, `127.0.0.1:3306`, read-only: INSERT/UPDATE/DELETE disabled)
- `playwright` (npx — `@playwright/mcp@latest`, browser automation/E2E)
- `redis` (npx — `@modelcontextprotocol/server-redis`, `redis://127.0.0.1:6379`)
- `docker` (uvx — `mcp-server-docker`, requires `uv` on the host: `pipx install uv` or `winget install astral-sh.uv`)
- Atlassian Rovo: configured through claude.ai (NOT `.mcp.json`)
- Requirement: Node.js v18+ (v24.x LTS installed); the Docker MCP additionally requires `uv`

## Skills useful for the project

- `/start-task <KEY>` — Jira → branch → implement → PR → Confluence → transition (workflow with preferences: skip STOP checkpoints, no Co-Authored-By)
- `/review`, `/security-review` — review pending changes / security
- `superpowers-symfony:symfony-tdd-pest` or `:symfony-tdd-phpunit` — TDD RED/GREEN/REFACTOR
- `superpowers-symfony:symfony-check` — PHP-CS-Fixer + PHPStan + tests
- `superpowers-symfony:doctrine-architect` (subagent) — schema design
- `superpowers-symfony:symfony-reviewer` (subagent) — review after a code change
- `superpowers-symfony:functional-tests` — WebTestCase + TDD for controllers

## Rules for working with Claude Code

1. First read CLAUDE.md and describe the plan before implementing
2. One Jira task = one session
3. After every code change: `make test`. Do not report readiness if the tests do not pass
4. Before `git commit`: show the diff + a proposed commit message, do NOT commit without approval
5. Do NOT add `Co-Authored-By: Claude` in commits (preference)
6. Branches: `<KEY>-short-description` from `develop`
7. After a larger step (a closed ticket / epic review / release) **propose** `/compact` to the user — do not run it automatically

## Links

- Confluence hub: https://honemanager.atlassian.net/wiki/spaces/H/pages/46661633
- Repo: `zlotylesk/AIHomeManager` (GitHub)
