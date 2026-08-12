# AIHomeManager — Claude Code Context

Single-user system for automating everyday activities. PHP 8.5 + Symfony 8 + MySQL 8.4 LTS + Redis 8 + RabbitMQ 4.x + OpenSearch 2.x. Hexagonal architecture, CQRS with two buses, fifteen domain modules.

**This file describes the system as it is now — the rules in force and why they are in force.** It is not a history of how they came about; that lives in `CHANGELOG.md`, in Confluence and in `git log`. See [Maintaining this file](#maintaining-this-file) before adding to it.

Run-and-setup documentation is in `README.md` and `docs/`. This file is the working context: what the code must look like, and which mistakes are already known.

---

## Status

Operational. Current release **1.34.0** (2026-08-05), which is also the highest-numbered tag and GitHub `latest`.

| Gate | State |
|---|---|
| PHPUnit | 2607 tests |
| Playwright | 169 tests |
| Vitest | 249 tests |
| Newman | 161 assertions |
| PHPStan | level 8, clean; one baseline entry |
| Deptrac | 0 violations, 0 `skip_violations` |
| Coverage floor | 90 % |

Release history belongs in `CHANGELOG.md`, not here — this table is the current reading, and it is updated rather than appended to.

---

## Modules

Fifteen bounded contexts under `src/Module/`. The right-hand column is not a summary — it is the rule that still binds when working in that module.

| Module | Responsibility | What binds |
|---|---|---|
| **Series** | Shows / seasons / episodes, own rating and the average from episodes, watched flag, Trakt import | Seasons and episodes are separate aggregates joined by string FKs, not ORM associations (ADR-007). Cascade is explicit in the repository. A show's own `rating` and the computed `averageRating` are disjoint fields |
| **Movies** | Films alongside Series — CRUD, watched, own rating, metadata, Trakt import | Flat aggregate: no season hierarchy, one rating. Import is idempotent by `trakt_id` |
| **Tasks** | REST CRUD over `TimeSlot`, time report, CSV/PDF export, Google Calendar sync | Calendar sync degrades gracefully — an unavailable calendar never fails the write |
| **Books** | Library, reading sessions, ISBN metadata, export | Metadata comes from a Domain port; the National Library adapter is behind a rate-limited client |
| **Articles** | Daily article pick, Pocket CSV import, categories, export | The daily pick is created **lazily inside the read handler**, so nothing proactive can be triggered from it |
| **Music** | Listening history, top albums, vinyl collection, owned-vs-listened comparison | A scrobble is a real event, so dedup hashes `artist\|title\|playedAt(second)\|source`. `play_count` is only ever set by the manual log endpoint — the poll leaves it null, so counts come from row counts |
| **Podcasts** | Listening history from Spotify | **Listens are derived from state, not fetched as events** — Spotify exposes no listen timestamp for episodes, only each episode's resume point. `listenedAt` therefore means "no later than", and dedup buckets by `podcastId\|episodeId\|day` in UTC, never by second. Progress is forward-only: once `fullyPlayed`, always `fullyPlayed` |
| **YouTubeProgress** | Watchlist sync, unwatched videos split into ≤30 min sessions by channel, push a session back as a playlist | Measures the video's full duration — the watchlist records *that* a video was watched, not how far in |
| **Budget** | Transactions, categories with monthly limits, monthly report, export | Amounts are whole minor units (grosze), never floats. **One currency** — a foreign amount is 422, never converted or relabelled. A transaction's type must match its category's type (422), because the list filters on one and the report groups by the other |
| **Recipes** | Recipe catalog, weekly meal plan, shopping list, export | `MeasurementUnit` is a closed enum **because the shopping list aggregates by (name, unit)** — free text would split one item into two lines. A recipe used in the plan cannot be deleted (409, never a cascade): a dangling reference would leave the list short by a whole meal |
| **Goals** | Cross-module goals and day-continuity streaks | A streak is **the current run computed live, merged with the all-time longest from the persisted row**. Neither half works alone: the stored row is a night stale, and a live computation drops anything older than the 365-day window |
| **Search** | Global search across every module | Two interchangeable engines behind one port, selected by a factory. See [Search](#search) |
| **Dashboard** | The cockpit at `/` — one "today" slice per module | Each widget is fault-isolated: a source that throws degrades to an empty card, logged `warning`, never a failed page |
| **Insights** | Trends at `/insights` per week or month | No tables and no migration at all — every series is composed on read. A bucket with no activity is a **zero point**; a metric with **no points** is the unavailable signal |
| **Notifications** | E-mail + WebPush, reactive and scheduled triggers, per-type opt-in | Quiet hours **suppress, they do not defer** — a held reminder announces a deadline that may already have passed. Dedup identity is `type:subject:window`, and idempotency is retry-aware: only a `SENT` record blocks a re-announce |

---

## Architecture — INVIOLABLE RULES

- **Hexagonal:** `src/Module/{Name}/{Domain,Application,Infrastructure}/`
- **Domain free of framework.** `grep -r "use Doctrine" src/Module/*/Domain/` MUST return empty. CI gate: `make deptrac` — Domain → [`Shared`] only, cross-module coupling forbidden. Deptrac runs at **zero violations and zero `skip_violations`**; there is nothing to hide a new violation behind.
- **Shared kernel.** Value objects and contracts that genuinely belong to more than one bounded context live in `src/Shared/` (`App\Shared\…`), never duplicated per module. It is the *one* sanctioned exception to "no cross-module coupling" — module-specific types stay in the module. Deptrac gives it its own layer that every `*Domain` (and Infrastructure and Glue) may depend on, and which has zero outbound dependencies itself.

  | Contract | Purpose |
  |---|---|
  | `Domain\ValueObject\CoverUrl` | URL validation shared by Books + Series + Movies |
  | `Security\TokenCipherInterface` | Encryption port; `App\Security\TokenCipher` wired per provider key |
  | `Security\GoogleTokenProviderInterface` | Raw read of the stored Google token |
  | `Security\GoogleAccessTokenProviderInterface` | A token usable **right now** — refreshes on expiry and persists. Both Tasks and YouTubeProgress consume it, so neither duplicates refresh and freshness does not depend on which module touched the token last |
  | `Security\TraktTokenProviderInterface` | Read-only Trakt token, so Movies never reaches into Series |
  | `Activity\StreakReaderInterface` | One streak reading, shared by the Goals API and the Dashboard |
  | `Notification\NotifiableEvent` + `NotificationRequest` | A source module opts an event in by implementing the interface; Notifications listens for the *interface*, so the reactive trigger needs zero cross-module imports |
  | `Search\AffectsSearchIndex` + `SearchDocumentRef` | Same shape for incremental indexing. Primitives only, and it names *what changed* rather than what to write — the indexer re-reads the source, so a vanished entity removes its document |
  | `Pagination\PageRequest` + `Page` | The one pagination convention |

- **Cross-module reads go through a DBAL adapter behind the reading module's own Domain port** — raw SQL against the source module's tables, importing none of its classes. Goals, Search, Dashboard, Notifications and Insights all read five other modules this way and stay at zero violations. A `query.bus` read would mean importing the other module's Query class, which is why it is not used for this no matter what a ticket's wording suggests.
- **Domain ports return Domain read models, never Application DTOs.** `final readonly` shapes in `Domain/ReadModel/`. Infrastructure adapters build them, Glue normalizers serialize them, and Application may compose them (Application → Domain is allowed). `DateTimeImmutable` inside one is deptrac-clean.
- **A composite provider tags its adapters explicitly, not via `_instanceof`** — the composite implements the same interface, so an `_instanceof` rule would make it iterate over itself.
- **Doctrine XML** in `Infrastructure/Persistence/Doctrine/*.orm.xml`. Do NOT migrate to PHP attributes (ADR-001).
- **Domain Events:** the aggregate collects them in `$recordedEvents`, the handler dispatches after `releaseEvents()`. Pattern: the `Series` aggregate. Most modules deliberately have none — add one when something subscribes, not before.
- **Query handlers use DBAL, not the ORM.** Reads do not hydrate aggregates.
- **Handler attributes:** command `#[AsMessageHandler(bus: 'command.bus')]`, query `#[AsMessageHandler(bus: 'query.bus')]`, event `#[AsMessageHandler]` with no `bus:`. `event.bus` has `allow_no_handlers: true` — domain events are fire-and-forget.
- **Bus dispatch in controllers goes through the typed wrappers `App\Messaging\{QueryBus,CommandBus}`, never the raw `MessageBusInterface`.** `QueryBus::ask()` and `CommandBus::dispatchAndReturn()` return the single handler's result and throw `LogicException` when no handler ran, instead of dereferencing null. `CommandBus::dispatch()` is fire-and-forget and is **mandatory for async-routed commands** — those get a `SentStamp`, not a `HandledStamp`, so they would throw through `HandleTrait`.
- **A cross-aggregate rule lives in the Application handler, not on the aggregate** — a Domain entity must not reach for a repository. That is why "does this category exist", "does the transaction's type match its category's", "does this recipe exist" and "is this slot free" are all handler checks.

---

## Naming conventions

| Element | Pattern | Location |
|---|---|---|
| Aggregate Root | `Series`, `Task`, `Book`, `Article` | `Domain/Entity/` |
| Value Object (immutable, `final readonly`) | `Rating`, `ISBN`, `CoverUrl`, `TimeSlot`, `Money` | `Domain/ValueObject/` |
| Read Model (Domain port return, `final readonly`) | `BookMetadata`, `Album`, `ListenedEpisode`, `ActivityEvent` | `Domain/ReadModel/` |
| Command | `CreateSeries`, `LogReadingSession` | `Application/Command/` |
| Command Handler | `*Handler` | `Application/Handler/` |
| Query | `GetAllSeries`, `GetSeriesDetail` | `Application/Query/` |
| Query Handler | `*Handler` | `Application/QueryHandler/` |
| DTO | `*DTO` | `Application/DTO/` |
| Repository Interface | `*RepositoryInterface` | `Domain/Repository/` |
| Repository Impl | `Doctrine*Repository` | `Infrastructure/Persistence/` |
| Serializer Normalizer | `*DTONormalizer` | `src/Serializer/` (Glue) |

Some older modules put command handlers in `Application/CommandHandler/` beside their `QueryHandler/`. Both spellings exist; follow the module you are in.

Input parsing that more than one command shares goes in a `final readonly` factory with a private constructor and a static `fromRaw()` — `TransactionInput`, `IngredientInput`, `MealPlacementInput`, `MovieMetadata`. That is what stops a create and an update from parsing the same payload two different ways.

---

## Persistence

### The nullable value-object hazard

**A nullable value object is mapped by a custom DBAL type, never by a nullable embeddable.** A nullable embeddable hydrates a NULL column as a **non-null object with an uninitialized property** — harmless on the write path and invisible to DBAL reads, but it explodes the first time anything reads the hydrated VO, typically during an import. The custom type round-trips `null` cleanly both ways.

Register each in `doctrine.yaml`. The sixteen currently registered: `budget_money`, `budget_transaction_type`, `task_status`, `book_status`, `series_status`, `series_rating`, `listening_source`, `goal_type`, `goal_period`, `movie_rating`, `movie_status`, `notification_type`, `notification_channel`, `notification_status`, `quiet_hours`, `meal_slot`.

An embeddable is fine when the field is **required** — `Title`, `ISBN`, `ListeningProgress`. `budget_money` is a custom type despite `Transaction::$amount` being required, because `Category::$monthlyLimit` is the same VO and is nullable; one mapping serves both.

### Associations and cascades

Aggregates are persisted with **manual string foreign keys, not ORM associations** (ADR-007). Reads are DBAL anyway, so associations would only serve the write path, and they would cost the N+1-free bulk loader and reintroduce the nullable-VO hydration hazard. The consequence is that `orphanRemoval`/`cascade` do nothing: **cascade is written explicitly in the repository**, and the tests assert it against raw row counts so a delete path that forgets its children fails loudly.

No FK constraints are declared for the same reason.

### When plain DBAL beats an ORM mapping

`Recipe` is written with plain DBAL and has no ORM mapping at all — the only aggregate that does. Two reasons, and the second is the deciding one:

1. Doctrine cannot map a collection of identity-less embeddables, and giving `Ingredient` a surrogate id purely to satisfy the mapper would hand a value object an identity it does not have.
2. **Doctrine hydrates entities by bypassing the constructor.** An ORM-mapped `Recipe` would be the one aggregate in the system whose invariants had never run. Reading through the real constructor means every recipe that comes out of the database was validated by the same code that validates one the user just typed — and a row that could never have been written by the aggregate is refused rather than hydrated.

`PlannedMeal` is a flat row of scalars with neither problem, so it stays ORM-mapped rather than following its module for consistency — going DBAL there would give up `schema:validate` coverage for nothing.

Non-ORM tables are excluded from `doctrine.dbal.schema_filter`: `recipes`, `recipe_ingredients`, `recipe_steps`, `search_documents`, `watch_session_videos`, and the four token tables `google_oauth_tokens`, `discogs_oauth_tokens`, `trakt_oauth_tokens`, `spotify_oauth_tokens`. They are therefore **not** covered by `make schema-validate`; a round-trip integration test is what pins them.

### Indexes for the range reads

**A column the cross-module readers filter by range carries an index, and a compound predicate is indexed leading with its equality half.** Goals, Insights, the cockpit and the weekly report read `articles`, `series_episodes`, `book_reading_sessions`, `videos`, `music_listening_sessions` and `tasks` over a date window on every page visit and in the nightly recompute. An unindexed column there is a full table scan that grows with the library while the answer stays perfectly correct — nothing breaks, it only gets slower, which is exactly why no functional test would notice.

The indexes: `(is_read, read_at)` on `articles`, `(watched, watched_at)` on `series_episodes`, `(date, pages_read)` on `book_reading_sessions`, and single-column `played_at` / `watched_at` / `time_start` on the other three. The compound ones lead with the equality column because `watched = 1 AND watched_at BETWEEN …` can only use both halves that way round. The trailing column is also the whole of what those queries select, so each read is answered from the index alone (`Using index`) — which is why `book_reading_sessions` carries `pages_read` even though nothing filters on it.

**An index declared only in a migration drifts from the ORM mapping and fails `make schema-validate`** — put it in the entity's `*.orm.xml` `<indexes>` block too. `RangeQueryIndexTest` runs the real adapters and reads MySQL's own `Handler_read_rnd_next` counter rather than restating their SQL, so a predicate later edited into a shape the index cannot serve fails there even while returning the right rows.

### Query-layer traps

- **Aggregate over two child tables with correlated subqueries, not two `LEFT JOIN`s** — joined children multiply each other's rows and every counter comes back inflated.
- **NULL sorts first in a MySQL `ASC` order.** A "most recent first, never-touched last" ordering needs an explicit `ORDER BY col IS NULL, col DESC`.
- **Bind `LIMIT`/`OFFSET` as `ParameterType::INTEGER`.** MySQL rejects a string limit, and DBAL 4 rejects the `\PDO::PARAM_*` constants with a `TypeError`.
- **Raw SQL rows never pass through a custom DBAL type's conversion.** A packed column read by a DBAL handler must be parsed explicitly (`MoneyColumn::parse`, `TagsColumn::decode`) — and that parse belongs in one shared class, because a decode duplicated per reader is how one of them quietly starts tolerating a shape the others reject.
- **Parse a date strictly, by round-trip comparison.** `DateTimeImmutable::createFromFormat` *rolls over*: `2026-02-31` becomes March 3rd and `2026-13` becomes January 2027. A value that does not format back to exactly what was supplied was never that date. Shared parsers: `MonthRange`, `PlanWindow`, `TransactionInput`, `MealPlacementInput`.
- A read that windows a date range makes it explicit and bounded — `GetTrends` caps at 120 buckets, `PlanWindow` at 92 days. An unbounded range is refused, not served slowly.

---

## Reads, DTOs and the API

- **Folds are precomputed in the read layer, never at serialize time.** `averageRating`/`watchedCount`/`episodeCount`, the trend totals and averages, the shopping-list sums are all computed in the query handler and merely copied by the normalizer.
- **List endpoints are paginated, one convention for the whole API.** A collection query carries a `PageRequest` (`page` from 1, `perPage` default 50 / max 100) and its handler returns a `Page` — a `COUNT(*)` over the filtered set plus `LIMIT/OFFSET`. Controllers parse the window with the Glue `PaginationRequestParser` (out of range → 422, never a silent clamp) and the single `PageNormalizer` serializes the `{data, pagination}` envelope, delegating each item to its own DTO normalizer.

  A read that fans out over a join (Series → seasons → episodes) applies the window to the **parent** in a derived table, never to the joined rows, or a page cuts an aggregate in half. A source that can only hand back the whole set (the cached Discogs collection) windows it with `Page::slice()` — that bounds the response without pretending the upstream read got cheaper.

  Deliberately unpaginated: reads bounded by an enum (goal streaks, notification preferences), by an explicit window (meal plan, shopping list), composed read models that are not collections (dashboard, trends, budget report), and the two Last.fm reads that already take a caller-chosen `?limit=` with a hard max. `/api/search` keeps the identical `page`/`perPage` guard but returns a bare array — an envelope would mean threading a total through both engines and the cache and fallback decorators.
- **DTO → JSON goes through `src/Serializer/*DTONormalizer.php`**, never a hand-rolled private `serialize*()`. Controllers inject `NormalizerInterface` and return `$this->normalizer->normalize($dtoOrList)`. One normalizer per DTO that reaches the wire; nested DTOs delegate through `NormalizerAwareTrait` rather than inlining a nested `array_map`. `NormalizersTest` pins them, and every module's `*ApiTest` pins the byte-identical JSON.
- **Payload shape-checking lives in a stateless Glue request parser** (`src/Controller/{Module}/*RequestParser`) which throws `UnprocessableEntityHttpException`; domain validation stays in the handlers. The controller's only remaining logic is mapping `HandlerFailedException` to 404 / 409 / 422.
- Controllers live in `src/Controller/Api/` with **version-agnostic** routes (`#[Route('/series')]`). `config/routes.yaml` imports that directory twice — once under `/api/v1`, once under `/api` — so the prefix is applied centrally (ADR-008). `/api/health` stays un-versioned.

### The OpenAPI contract

Generated from `#[OA\*]` + `#[Model]` attributes by NelmioApiDocBundle; `/api/doc`, `/api/doc/redoc` and `/api/doc.json` are public. The contract is a **gate**: a CI job dumps and Spectral-lints it, and `OpenApiContractTest` validates real responses against the schema documented for the status they actually returned. **Every documented `^/api/*` module is under that runtime gate** — keep it that way when adding endpoints.

Three things that have caught people out:

- A normalizer that **flattens** a detail DTO to the top level (Book, Podcast, Recipe details) must be documented as `allOf[BaseDTO, {extra}]`, **not** `#[Model(DetailDTO)]` — the DTO's own wrapper key never reaches the wire.
- **OpenAPI 3.1 encodes nullability as a type union** (`type: ["string","null"]`), not the 3.0 `nullable` flag.
- **JSON has one number type**, so a whole-valued float serializes as `50`, not `50.0`. Compare numerically in tests rather than `assertSame` against a float.

A schema documented with `additionalProperties` will happily validate an undocumented extra key, so the runtime gate cannot catch drift there — `/api/health`'s component list is guarded by an explicit test comparing the documented example against what `HealthChecker::check()` actually reports.

---

## Messaging

Routed to the **async** transport: `EpisodeRated`, the four Trakt imports (shows, series ratings, movies, movie ratings), `RefreshDiscogsCollection`, `PollLastFmRecentTracks`, `PollPodcastListens`, `RecalculateStreaks`, `DispatchNotification`, `IndexSearchDocument`. Each has a routing test pinning it.

Deliberately **sync**: `BookCompleted` (no handler, no I/O — ADR-006), `ReindexSearchDocuments` (runs inline in the scheduler worker), and Mailer's own `SendEmailMessage` — routing that would make `Mailer::send()` return before the transport ran, so a rejected message would surface in the DLQ while the dispatch engine had already recorded the notification as `SENT`. Asynchrony sits one level up instead, on `DispatchNotification`.

In the test environment both `async` and `failed` are `in-memory://`. **Dispatching an async-routed command in a test parks it and runs nothing** — invoke the handler directly and pin the routing separately.

### Request correlation

`RequestIdListener` reads or generates `X-Request-ID` (validated `^[A-Za-z0-9._-]{1,128}$`, so control characters cannot reach the logs) and echoes it back. `RequestIdProcessor` adds it to every log record.

It also **follows the message onto the worker**: `AttachRequestIdStampMiddleware` stamps an envelope at dispatch — from the current request, or failing that from `RequestIdHolder`, which is what keeps a chain on one trail when a worker handler dispatches another message. `RestoreRequestIdMiddleware` parks the stamped id in the holder for the duration of the handler and clears it in `finally`, **only when that frame set it**, so a nested synchronous dispatch cannot wipe the outer id. Both are registered on `command.bus` and `event.bus`, so a module added later inherits the rail for free.

---

## Frontend

Two tracks, and the split is being closed rather than maintained.

**Encore + Stimulus** (`app/assets/`) carries everything except three panels: Series, Books, YouTubeProgress, Goals, Search, Dashboard, Movies, Notifications, Podcasts, Insights, Budget, Recipes. Controllers in `assets/controllers/*_controller.js`, mounted with `data-controller="…"`.

**Twig + vanilla JS** (`app/public/js/`) still carries Tasks, Articles and Music, loaded as plain `<script src>`.

**They share one implementation of the common helpers rather than carrying copies.** `assets/legacy-globals.js` publishes the very same function objects on `window` (`escHtml`, `apiCall`, `safeUrl`, `TOAST_TIMEOUT_MS`, the `{data, pagination}` helpers `unwrapPage`/`fetchAllPages`/`mountPagerAfter`, and the global banners), and `app.js` calls it first thing. **That ordering is load-bearing:** `base.html.twig` renders `encore_entry_script_tags('app')` before `{% block javascripts %}`, and Encore emits classic, non-deferred script tags, so the bundle has finished executing before the first legacy file is parsed. Adding `defer` or `type=module` to the Encore output would break every legacy panel at once.

`escHtml` escapes the apostrophe as well as `& < > "`, so the one implementation is safe inside single-quoted attributes too.

**UI language is Polish** throughout. Domain jargon tied to an external service (Trakt, Discogs, Spotify, OpenSearch, YouTube, `Last.fm scrobble`) stays as-is. **API error payloads stay English** — `{"error": …}` is the contract, pinned byte-for-byte by the API tests and the conformance gate, not UI. Data keys, `data-*` values, CSS classes, element ids, route names and API field names are untouched.

### Layout and gotchas

| File / directory | Role |
|---|---|
| `assets/app.js` | Entry — publishes the legacy globals, boots Stimulus, initialises the PWA pieces |
| `assets/bootstrap.js` | Stimulus auto-discovery via **`import.meta.webpackContext`** |
| `assets/controllers/` | Stimulus controllers — **every `.js` here is auto-mounted as a controller** |
| `assets/{series,goals,movies,recipes,…}/` | Pure helpers + DOM builders, deliberately outside `controllers/` |
| `assets/banners.js` | The two global banners in `base.html.twig` (`showError`/`hideError`/`showInfo`), written with `textContent` so an API message cannot become markup |
| `assets/legacy-globals.js` | `publishLegacyGlobals()` — its Vitest asserts **reference identity**, not equivalent behaviour |
| `assets/pwa/` | Manifest, icons, Service Worker source, install/offline/push/queue helpers |
| `assets/tests/` | Vitest specs — must NOT live under `controllers/` |

- **`import.meta.webpackContext`, never `require.context`.** `"type": "module"` makes webpack parse the assets as ESM, where the CommonJS `require` stays a free variable and Stimulus dies at startup with `require is not defined`. The build **compiles anyway** — this is only caught by booting a real page.
- **A test file under `assets/controllers/` breaks the build**, because the `webpackContext` there registers every `.js` as a controller.
- **Chart.js is loaded through a dynamic `import()`**, so its ~205 KB lands in its own lazy chunk instead of the `app` entry that every page loads. A failed chunk load degrades to an error banner while the numbers still render.
- **`npm test` runs `node --check` over the three legacy files before Vitest.** Nothing else parses them — not the Encore build, not Vitest's include glob — so a syntax error there survives every other gate and first appears as a dead panel in a browser.
- Encore peers are pinned: `@symfony/webpack-encore` declares `webpack-cli ^6 || ^7`, and a peer major outside that range breaks `npm ci` with `ERESOLVE`. Do not merge one before Encore widens the range.

**Routes:** `/` (the cockpit — a render, not a redirect), `/series`, `/movies`, `/tasks`, `/books`, `/articles`, `/music`, `/podcasts`, `/youtube-progress`, `/goals`, `/notifications`, `/insights`, `/budget`, `/recipes`, `/meal-plan`. Global search sits in the navbar on every page.

### PWA

The frontend is an installable PWA built inside the Encore pipeline — no second bundler. `assets/pwa/sw.js` is bundled by Workbox `InjectManifest` and emitted to the site **root** as `public/sw.js`, so its scope is the whole origin; a hashed `/build/` path would narrow it. Production builds only; registration fails soft in dev.

Runtime caches are versioned (`aihm-runtime-{api-reads,pages}-${CACHE_VERSION}`) and an `activate` sweep drops every non-current runtime bucket. **Bump `CACHE_VERSION` after an `/api` response-shape change** that would make a stale cached read render wrong. The `api-writes` Background-Sync queue is never swept — that would lose a user's offline writes.

An offline write returns a synthetic `202 {queued:true}`; a browser **without** Background Sync gets no queue and a `503 {requiresNetwork:true}`, because being told the action needs a connection is honest and a promised replay that can never fire is not. Both `apiCall`s detect the marked responses, fire a window event and throw a typed error, so no caller renders a queued write as saved.

nginx serves `/sw.js` with `Cache-Control: no-cache`, which is also what makes the kill-switch in `docs/pwa.md` deployable.

---

## Search

Two interchangeable engines behind one Domain port, selected by `SEARCH_ENGINE_BACKEND` (`opensearch` | `fulltext`).

- **The flag is read by a factory, not a container alias** — an alias is resolved at compile time and cannot read an env var. An unknown value is rejected at boot rather than silently defaulted.
- Three defaults disagree on purpose: `app/.env` ships `opensearch`, the container parameter `app.search_backend.default` stays `fulltext` (so an environment that never heard of the flag still boots without an extra service), and `app/.env.test` pins `fulltext` (most search tests seed `search_documents` with SQL, and the CI E2E jobs run no engine).
- **Everything addresses the index through an alias**, never a physical name. A reindex builds `{alias}_v{schema}_{timestamp}_{rand}` and moves the alias in **one** `_aliases` call — two calls would leave a window where the alias resolves to nothing or to both.
- The OpenSearch image is **built**, not pulled: OpenSearch has no built-in Polish stemmer, so `analysis-stempel` is installed on top. Analyzer filter order is `lowercase → polish_stop → polish_stem → asciifolding`, because Stempel is trained on accented Polish and folding first would hand it words it cannot stem. Every searchable field also carries a `.folded` sub-field for diacritic-free input.
- **Reads degrade, they do not fail.** The OpenSearch adapter is wrapped in a fallback to FULLTEXT, logging a `warning` per degraded query. Only a failure of *both* surfaces as an error — answering "no results" while search is broken would be a lie about the library's contents. It does not health-probe first: a probe is a second round trip that can succeed while the query behind it fails.
- **Selecting OpenSearch also turns on a dual write**, so the MySQL index stays current. Without it the table would freeze the day the flag was flipped and the fallback would serve a months-old library. This is also what makes a rollback need no data work.
- Bulk indexing on OpenSearch is **mark-and-sweep**, not delete-then-fill: every document is upserted with a millisecond-precision stamp, then anything older is deleted. Emptying first would make search answer "nothing found" for the length of a rebuild that runs every 15 minutes.
- **The index is not backed up — it is rebuilt.** Every document is derived from the source tables.
- Fuzzy matching runs **only on the folded fields** (edit distance over Stempel stems is not what a user means by a typo) and at a low enough boost that **a typo match can surface a missing result but never outrank a literal one**.
- Facet counts span the **whole** match set and **ignore the active type filter** — narrowing them by the current selection would leave the selected type as the only option and no way back.

---

## Infrastructure

| Service | Container / port | Notes |
|---|---|---|
| MySQL 8.4 LTS | `mysql:3306` | Image pinned to `mysql:8.4`, not the floating `mysql:8`. **`serverVersion=8.0` in the DSN is deliberate** — DBAL's `MySQL84Platform` is `@deprecated` and differs from `MySQL80Platform` only in the reserved-keyword list; the 8.0 platform is fully compatible with the 8.4 server |
| Redis 8 | `redis:6379` | `series:avg:{id}` / `season:avg:{id}` written directly via `\Redis`; pools `cache.rate_limiter`, `cache.search`, `cache.dashboard`, `cache.insights` |
| OpenSearch 2.x | `search:9200` | App data, **separate instance from Graylog's**. Built from `docker/opensearch`. No `monitoring` profile, so it starts with the lean stack too |
| RabbitMQ 4.x | `rabbitmq:5672`, UI `:15672` | Exchange `series_events` (topic), classic queues, retry 3× (1s→2s→4s), DLQ `failed`. **Needs both a named volume and a fixed `hostname`** — see below |
| Messenger worker | `messenger_worker` | `messenger:consume async --time-limit=3600` |
| Scheduler worker | `scheduler_worker` | `messenger:consume scheduler_default --time-limit=3600` |
| Node | `aihm-node-1` | `node:24-alpine`, long-running; `docker compose exec node npm …` |
| Graylog 6.3 | `monitoring` profile, UI `:9000`, GELF UDP `:12201` | Coupled to `mongo:7` + its own `opensearch:2` (Graylog 6.3 supports OpenSearch 1.1–2.19.5 only, **not** 3.x). Ships over the Compose network (`GRAYLOG_HOST=graylog`), so production drops the published UDP port and binds the UI to `127.0.0.1` |

**RabbitMQ durability needs both halves.** Queues, exchanges and messages have always been durable, but durability is written to `/var/lib/rabbitmq` — without a volume that lived in the container's writable layer, so a `restart` kept everything while a recreate took the DLQ with it. The hostname matters by another route: RabbitMQ derives its node name from it and stores each node's database under `mnesia/rabbit@<hostname>`, so with Docker's default (the container id) every recreation starts an empty new node while the old one's data sits untouched beside it in the volume — the mount looks fine and the messages are still gone.

**Production publishes nginx's 80 and 443 and nothing else; development publishes every infrastructure port and keeps doing so.** The two are opposite on purpose — the host clients and MCP servers talk to 3306/6379/5672/15672/9200, and a development box is not what this protects — and `ProductionRuntimeConfigTest` pins both halves. The overlay writes `ports: !override []`, because Compose *appends* untagged sequences: an overlay that merely listed loopback bindings would publish those **and** the base file's `0.0.0.0` mappings. Diagnostics run the client inside the container (`docker compose exec`), which also proves the path the application uses; Graylog is the one production exception, bound to `127.0.0.1` rather than dropped, because a UI exists to be looked at.

**Infrastructure credentials live in the repository-root `.env`, not `app/.env.local`** — Compose reads them while building the stack, before a container exists to read an application env file. The tracked values are development values and the file says so at the top; `make prod-*` layers a gitignored root `.env.local` on top (`--env-file .env --env-file .env.local`, second wins), so production passwords are never committed and no tracked file is edited to deploy. `REDIS_PASSWORD`, `RABBITMQ_USER`, `RABBITMQ_PASSWORD` and Graylog's `GRAYLOG_PASSWORD_SECRET` / `GRAYLOG_ROOT_PASSWORD_SHA2` are `${VAR:?}`-guarded, never `:-`-defaulted: an empty `--requirepass` is a Redis with no password while every file claims otherwise, and a defaulted Graylog is one still answering to `admin`. Guarded means the tracked `.env` must carry a development value for each, or a fresh clone cannot start at all — including Graylog's, which Compose interpolates despite its `monitoring` profile.

**The layering fails silently in one direction, and `make doctor` is what covers it.** A variable *missing* from `.env.local` does not stop a deployment — it falls through to the development value and the instance comes up healthy on a password published in a public repository. Nothing about the running stack looks wrong. The **Production secrets** check reads both files and names every variable still on its `.env` value (unset or copied verbatim), compares against what `.env` currently holds rather than a copy of those strings, and fails outright if `.env.local` has itself been committed. **Severity follows whether the host deploys** — one failure per variable where the `aihm-prod` Compose project exists, one collapsed warning anywhere else, because a workstation legitimately keeps a root `.env.local` (the GitHub MCP token) and a check red on every dev box stops being read. `COMPOSE_ENV_DIR` moves both reads and a labelled throwaway container supplies the production signal, which is how CI pins the real verdicts without a production host.

**The broker does not run as `guest`, and renaming the account is only half of it.** RabbitMQ creates `default_user` when it initialises an **empty** database, so a fresh volume never gets a guest account — and an existing one keeps its guest administrator no matter what the environment says. The image also ships `loopback_users.guest = false` baked into its own `conf.d`, unaffected by `RABBITMQ_DEFAULT_USER`, which is why `docker/rabbitmq/20-aihm.conf` sets it back to `true` and is mounted in both environments. Deleting the account on an existing volume is a documented manual step.

**Redis runs with `--requirepass`, and `LOCK_DSN` needs the password as much as `REDIS_URL` does** — it is a separate variable read by `lock.yaml`, not derived, so a password added to only one leaves every lock failing while the caches work. The healthcheck authenticates and greps for `PONG`: an unauthenticated `redis-cli ping` answers `NOAUTH` and **exits 0**, so the naive probe reports healthy on a server refusing every client.

**Redis also runs with `maxmemory 192mb` and `maxmemory-policy allkeys-lru`, not `volatile-lru`.** Every key this instance holds — the four `cache.*` pools, `series:avg:{id}`/`season:avg:{id}`, the external-API response caches, `articles:today`, the worker heartbeats — already carries a TTL, so the two policies evict identically today; `allkeys-lru` is still the one chosen because it always has something to evict, where `volatile-lru` degrades back to `noeviction`'s write-rejecting errors the moment the keyspace ever fills with non-expiring keys. The rate limiter's internal locks (auto-wired from `LOCK_DSN` into every `framework.rate_limiter` limiter) are acquired and released within a single request, so they carry no held-across-eviction risk. The scheduler's own state is not in Redis at all — `Schedule::stateful()` runs on the filesystem-backed default `cache.app` pool — so it is unaffected either way. Full per-key-group review: `docs/operations.md`.

**`messenger:failed:show` does not work here.** It needs a receiver that can list messages by id, which Doctrine offers and AMQP does not. Use `messenger:stats` for the depth and `messenger:failed:retry` to work through them.

**Every service carries `restart: unless-stopped` and a `mem_limit`, so a host reboot brings the stack back with no manual step and no service can starve the others.** The restart policy lives in the base `docker-compose.yml` — a scalar, so the prod overlay inherits it unmodified — except for `certbot`, which only the overlay defines. `mem_limit` is prod-overlay-only rather than shared: `php` and `scheduler_worker` are also where `docker exec` runs heavier local tooling (`make phpstan` alone asks for `--memory-limit=1G`), and a ceiling sized for the running application would starve that on a development machine. The `monitoring` profile's three services carry their limits in the base file instead, since it is their only definition regardless of environment — keeping the profile's budget independent of the core stack's is what lets it be enabled without taking memory from `mysql`. Full per-service numbers and reasoning: `docs/operations.md`.

**`php` and `nginx` have healthchecks, and boot order is enforced through `depends_on: condition: service_healthy`.** `php` has no HTTP server of its own — only php-fpm's socket — so its probe speaks FastCGI directly via `cgi-fcgi` to the pool's built-in `ping` responder, rather than asking a route that needs the application fully booted to answer. `nginx` waits on `php` being healthy before it starts proxying, or a fresh boot answers with `502`s for however long php-fpm takes to warm up; `php` in turn waits on `mysql`, `redis` and `rabbitmq`.

**Every service's `logging:` driver carries a `max-size`/`max-file` cap** (`json-file`, ~500M lean stack / ~150M more for the `monitoring` profile, sized independently — see `docs/operations.md`), because the default is unlimited and the log lives on the same disk as the database. `app/var/log` bypasses that driver entirely — it is a bind-mounted file, not stdout/stderr — so `config/packages/monolog.yaml`'s `dev` handler is `type: rotating_file` (`max_files: 7`) instead of a plain `stream`, which had grown unrotated to 640M. Production's `deprecation` channel is `type: null`: it was the one handler never wrapped in `fingers_crossed`, so it wrote every PHP deprecation notice straight to stderr — measured at ~30 lines per request for a single vendor-level deprecation with nothing actionable behind it in production; `failOnDeprecation` in `phpunit.dist.xml` is what actually forces these to be fixed, and `when@dev`/`when@test` still log every one.

### Scheduler

`src/Schedule.php`, 11 recurring tasks. `bin/console debug:scheduler` shows state. Stateful via `cache.app` with `processOnlyLastMissedRun(true)`, so a restart fires at most one missed window.

| Cron | Message | Effect |
|---|---|---|
| `0 0 * * *` | `ResetDailyArticleCache` | Drops `articles:today`, prunes picks older than 7 days |
| `0 1 * * *` | `RecalculateStreaks` | Recomputes and persists the per-type streak; carries the all-time longest forward once activity leaves the read window |
| `0 3 * * *` | `BackupDatabase` | mysqldump + gzip, **encrypted**, retention 30 daily + 12 monthly, then copied off-host |
| `0 8 * * 1` | `GenerateWeeklyActivityReport` | Logs the week's counters |
| `0 8 * * *` | `ReviewNotificationCandidates` | Morning sweep — deadlines, the article pick, the digest |
| `0 20 * * *` | `ReviewNotificationCandidates` | Evening sweep — when "your streak dies at midnight" first becomes true |
| `0 */6 * * *` | `RefreshDiscogsCollection` | Pre-warms the collection cache before its 6 h TTL |
| `*/15 * * * *` | `ReindexSearchDocuments` | Rebuilds the search index; the only thing keeping event-less modules current |
| `*/30 * * * *` | `PollLastFmRecentTracks` | Last.fm → listening history, idempotent by `dedup_hash` |
| `*/30 * * * *` | `PollPodcastListens` | Spotify sweep; overlapping windows are harmless because the source reports state |
| `*/5 * * * *` | `MonitorSystemHealth` | Sweeps the alert probes and mails the owner about anything that changed. **The one recurring command that is deliberately not async-routed** — see [Operational alerting](#operational-alerting) |

The backup job has **three image-level dependencies**: `bash -o pipefail` (POSIX `sh` reports only the last pipeline member, so a dead `mysqldump` is masked by a successful `gzip`), `mariadb-connector-c` (Alpine's `mysql-client` is MariaDB's and ships no `caching_sha2_password`, MySQL 8.4's default plugin) and `rclone` (only when that off-host backend is selected). All three are in `docker/php/Dockerfile`; **an image built before those lines must be rebuilt.**

**The stored artifact is encrypted, and that is the format, not a step on the way out** — `homemanager-YYYY-MM-DD.sql.gz.enc`, gzip inside (encrypted bytes do not compress), libsodium `secretstream` outside. `secretstream` rather than the `secretbox` `TokenCipher` uses, because a whole-string cipher would hold the database in memory twice; the plaintext dump exists only as a dot-prefixed 0600 temp file that no glob matches and that is unlinked in a `finally`. Decryption **insists on the final chunk's tag**: an upload cut off on a chunk boundary otherwise decrypts cleanly to a partial dump, and a truncated dump restores a database quietly missing everything after the cut. **`BACKUP_ENCRYPTION_KEY` cannot be regenerated** — unlike the four token keys, losing it loses every backup retroactively, off-host copies included.

**Where the copy goes is a port with a factory**, `none` | `directory` | `rclone`, selected by `BACKUP_REMOTE_BACKEND` — an alias resolves at compile time and cannot read an env var, and an unknown value is refused at boot rather than degrading to `none`, which would leave an instance that looks configured and copies nothing. The `directory` backend **compares device ids, not paths**, at push time: a "remote" directory on the database's own disk protects against nothing, and a mount that has silently dropped since startup collapses back onto the host filesystem. Off-host retention is its own, longer window. **A failed upload does not fail the backup** — the dump already succeeded and a retry would re-dump the whole database — so it is logged and then announced by `BackupOffsiteProbe`, which reads the destination on every sweep. `make restore-drill` restores the newest artifact into a scratch schema and compares row counts, because freshness and size checks can all pass on a file that no longer restores.

**Every backup filename is parsed by `BackupFilename`, never per reader** — the writer, both retention sweeps, both probes and the destinations all ask it, so none of them can develop its own idea of what a backup is or when it was taken.

**CI cannot catch either.** It runs PHPUnit on the GitHub runner, never inside the image the app ships in, so an image-level runtime dependency is invisible to every job. `make doctor` is what covers that gap — and it checks the *outcome* (is the newest backup non-empty, and is it recent) rather than only the causes already known.

### Production configuration

Development and production are two compose files rather than a flag —
`docker-compose.yml` plus `docker-compose.prod.yml`, and every `make prod-*`
target passes both. Development bind-mounts `./app` over the code; production
runs what is baked into the image. **The overlay declares its volumes with the
Compose `!override` tag**, because Compose *merges* untagged sequences: without
it the base file's `./app:/var/www/html` survives the merge and the host working
copy silently shadows every file in the image.

`docker/php/Dockerfile` is multi-stage over a repository-root build context: a
shared `base` (so the two images never differ in *which extensions exist*), an
empty `dev`, `assets` (the Encore production build, so a deployed image carries
the bundle its own code was built against), and `prod` — code copied in,
`composer install --no-dev`, cache warmed at build time. nginx has its own image
with `public/` baked in, because `try_files` resolves static files on nginx's own
filesystem — and it takes that directory **from the application image**, not from
the build context: `public/build/` and `public/bundles/` are gitignored generated
output, so copying them from the context yields a working image on a machine that
happens to have them and a half-empty one on a clean clone. That is what makes
`make prod-build` two ordered steps.

**TLS terminates in nginx, in production only.** Development stays on plain
HTTP at `:8080`, because a browser already treats localhost as a secure context
and every E2E suite talks to it. The two site configurations are separate files
(`default.conf`, `default.prod.conf`) that `include` the same `snippets/`, so
the serving rules cannot drift apart. In production `:80` answers the ACME
challenge and 301s everything else; `:443` serves the app.

**The public hostname appears once in the repository — the `DOMAIN=` argument
to `make prod-cert-init`.** Certificates are issued under the fixed lineage name
`aihm`, so nginx names a lineage and not a domain, and no config is templated.
An entrypoint script symlinks that lineage into `/etc/nginx/certs/` at start, or
writes a self-signed placeholder when there is none: nginx refuses to start
without a certificate, and the only way to obtain one is a challenge served by a
running nginx, so the placeholder is what breaks the deadlock on a fresh host.
The ACME location is exempt from the redirect for the same reason — the route
back from an expired certificate must not run through HTTPS. Renewal is the
`certbot` service plus nginx reloading on a timer; both containers share the
`certbot_webroot` volume, and a mismatch there is a renewal that fails sixty
days later, which is why the test compares the two mounts.

**Secrets reach production as environment variables, never as a file in the
image.** `app/.env.local` is excluded from the build context and read by the prod
overlay as an `env_file`; it is required, because the tracked `app/.env` holds
empty placeholders and an instance booted from those would have an empty
`API_KEY` and an empty `FRONTEND_PASSWORD_HASH` while looking healthy.

**A class under `src/` that extends a `require-dev` class breaks the production
build, and two separate scans have to exclude it**: the routing attribute loader
reflects on every class under `../src/`, and the `App\` service resource does the
same — reflection loads the parent, which a `--no-dev` install does not have.
`src/DataFixtures/` is therefore excluded in *both* `routes.yaml` and
`services.yaml`, and re-registered under `when@dev` / `when@test`. Neither dev
nor test can catch this, since both install the package.

**OPcache is not off in development** — `php:8.5-fpm-alpine` ships it enabled.
What production changes is `validate_timestamps=0`, which stops a `stat()` per
included file per request, and a `max_accelerated_files` above the ~50k-file
working set that the 10000 default sits below.
`docker/php/opcache-prod.ini` is loaded only by the prod stage.

**`.dockerignore` is load-bearing, not a build-speed tweak.** The build context
is the repository root and `app/.env.local` holds the real credentials; a layer
that captured it would keep them after any later deletion.

Cache warming at build time runs with placeholder token keys of the correct
length, because `TokenCipher` rejects anything that is not 32 bytes and is built
at container-compile time. Symfony compiles `%env()%` as a runtime placeholder,
so none of those values reach the warmed container — the real ones arrive as
environment variables.

### Environment

`app/.env` holds placeholders, `app/.env.local` the real values — for the **application**. The repository-root `.env` / `.env.local` pair is a separate layer for what **Compose** interpolates (`MYSQL_*`, `REDIS_PASSWORD`, `RABBITMQ_USER`/`RABBITMQ_PASSWORD`); the application file cannot carry those, because they are needed before a container exists to read it. Full reference: `docs/configuration.md`. The startup-critical set is `API_KEY`, `FRONTEND_USER`/`FRONTEND_PASSWORD_HASH`, and four **different** 32-byte base64 keys `DISCOGS_TOKEN_KEY` / `GOOGLE_TOKEN_KEY` / `TRAKT_TOKEN_KEY` / `SPOTIFY_TOKEN_KEY` — `TokenCipher` throws for any other length, and it is built at container-compile time, so a wrong key is a boot failure. `BACKUP_ENCRYPTION_KEY` is a fifth 32-byte key with the same length rule, but it is resolved lazily, so a missing one fails when a backup runs or is restored rather than at boot.

**`.gitattributes` pins `*.sh`, `app/bin/console` and `docker/rabbitmq/*.conf` to LF.** The broker's parser reads `loopback_users.guest = true\r` as the string `true\r` and refuses to start; nginx happens to tolerate the stray CR in its own mounted configuration, which is why the rule names the broker rather than every `*.conf`. A shebang is not a comment either: a CRLF checkout makes Linux `env` look for a program literally named `php\r`, and every Make target running `bin/console` fails with a misleading error — on Windows only, so no gate can see it. Do not "fix" line endings with container busybox tools; they mangle sources.

---

## Makefile commands

| Action | Command |
|---|---|
| Start (full / lean) | `make up` / `make min-up` |
| Full initialization | `make setup` (build + up + composer install + migrate) |
| Production build / start / stop | `make prod-build` / `make prod-up` / `make prod-down` |
| Production migrate / verify / logs / shell | `make prod-migrate` / `make prod-about` / `make prod-logs` / `make prod-shell` |
| First certificate / renew now | `make prod-cert-init DOMAIN=… EMAIL=…` / `make prod-cert-renew` |
| Preflight health check | `make doctor` |
| One monitoring sweep now | `make monitor-run` |
| PHP shell | `make shell` |
| All tests / unit / integration | `make test` / `make test-unit` / `make test-integration` |
| Coverage + floor gate | `make test-coverage` |
| Parallel PHPUnit (paratest) | `make test-parallel` |
| JS unit (legacy parse check + Vitest) | `make test-js` |
| E2E / Newman | `make test-e2e` / `make test-newman` |
| Migrations dev / test | `make migrate` / `make migrate-test` |
| Schema validate | `make schema-validate` |
| Search index provision / rebuild / fill | `make search-index` / `make search-reindex` / `make search-populate` |
| Encore dev / watch / prod | `make assets` / `make assets-watch` / `make assets-prod` |
| npm install / audit | `make node-install` / `make node-audit` |
| Static analysis (all) | `make analyse` |
| PHPStan / baseline | `make phpstan` / `make phpstan-baseline` |
| CS Fixer | `make cs-check` / `make cs-fix` |
| Rector | `make rector-dry` / `make rector` |
| Deptrac | `make deptrac` / `make deptrac-baseline` |
| Composer audit | `make audit` |
| OpenAPI dump / lint | `make openapi-dump` / `make openapi-lint` |
| Backup / restore / rehearse a restore | `make backup-now` / `make restore BACKUP=…` / `make restore-drill` |
| Cache clear / routes / services | `make cc` / `make routes` / `make services` |
| Logs (all / per service) | `make logs` / `make logs-{php,nginx,mysql,redis,rabbitmq,worker,scheduler,node}` |
| Monitoring | `make monitoring-up` / `-down` / `-logs` / `-bootstrap` |
| Fixtures (dev) | `make fixtures` |

---

## Tests

- **Unit:** `tests/Unit/Module/{Name}/Domain/`. Gold standard: `SeriesAggregateTest`.
- **Integration:** `tests/Integration/`, real MySQL + Redis, in-memory transports.
- **E2E:** `tests-e2e/`, TypeScript. `testMatch` collects only `*.desktop.spec.ts` (1440×900) and `*.mobile.spec.ts` (Pixel 5), so shared helpers live in `tests-e2e/support/` without becoming a suite. The default context sets `serviceWorkers: 'block'` — a controlling worker fetches from its own context and would bypass every `page.route()` stub; the PWA specs opt back in.
- **Newman:** `tests-e2e/postman/`. `make test-newman` **truncates** the tables its collection writes to.
- **JS unit:** `app/assets/tests/*.test.js`, jsdom, for pure helpers only.

**Test isolation:** an integration test truncates the tables it uses in `setUp`. No shared abstract base, no rollback wrapper — that per-test cleanup is what makes a paratest worker's own database deterministic. If a test reads a table another class writes, truncate it too; a leaked row from elsewhere is how a streak length appears out of nowhere.

**Conventions:**
- `*ApiTest` uses `App\Tests\Support\AuthenticatedApiTrait` — the `X-API-Key: test-api-key` header plus `jsonResponse()`, which decodes with an asserted shape rather than a raw `json_decode` on `getContent()`'s `string|false`.
- A test double stored in a **property** must keep the intersection type — `private Foo&Stub $foo;` or `private Foo&MockObject $foo;`. The bare interface erases it and PHPStan reports `method.notFound`. Local variables keep the inferred intersection and need no annotation.
- **The container refuses to replace an already-initialized service**, so a programmable double must be planted once and reprogrammed between phases rather than swapped.
- A failure-path test on a query handler is invoked **directly**, not through `QueryBus::ask()` — `HandleTrait` does not unwrap `HandlerFailedException` the way the HTTP boundary does, so asserting through the bus tests Messenger's wrapping instead of the handler.
- **Write a test that can go red.** A rule pinned by an assertion that would pass with the rule removed is not pinned. Where a claim is cheap to verify — a CSS media query, an escaped LIKE wildcard, a NULL-last clause — remove the mechanism, watch the test fail, put it back.

**Gates:** `phpunit.dist.xml` sets `failOnDeprecation`, `failOnPhpunitDeprecation`, `failOnNotice` and `failOnWarning`. Vendor noise is filtered via `ignoreIndirectDeprecations`.

**Coverage:** measured with pcov (`pcov.enabled=0` in `php.ini`, enabled per run), floor `COVERAGE_MIN` default 90, enforced by `app/bin/coverage-check.php`. **The floor only moves up** — bump it in the `Makefile` and in `ci.yml` together; never lower it to green a build.

**Parallel:** CI runs paratest across 4 workers, each with its own database (`homemanager_test{token}`) and Redis logical DB. `make test` stays sequential; `make test-parallel` mirrors CI.

**CI:** five jobs, all required — `static-analysis` (one matrix leg per tool), `openapi-contract`, `tests`, `e2e-playwright` (+ a Lighthouse installability audit), `e2e-newman`. Detail in `docs/testing.md`.

---

## Static analysis

- **PHPStan** level 8 with the Symfony, Doctrine and PHPUnit extensions. `app/phpstan-baseline.neon` holds **one** deliberately kept entry — a wire-contract assertion PHPStan reads as a tautology. A new error means a fix, or an explicit baseline addition via `make phpstan-baseline`.
- **PHP CS Fixer:** `@Symfony` + `@PHP84Migration` + `global_namespace_import`. On Windows the working tree is CRLF while the index is LF, so a local `make cs-check` reports whole-file `line_ending` diffs CI never sees. **When local and CI disagree, the index is the authority** — CI checks out LF; run the fixer against the staged blobs to see what CI sees.
- **Rector:** `withPhpSets()` + `deadCode`. It will collapse an aggregate with no mutator to `final readonly class`; that is expected, and the class reopens when a ticket adds the behaviour that needs it.
- **Deptrac:** per-module Domain / Application / Infrastructure layers plus the `Shared` kernel and a `Glue` layer (controllers, listeners, Kernel, Security) that may depend on everything. Zero violations, zero `skip_violations`.
- **Composer audit** runs in CI. A finding means bumping the package, not suppressing it. The frontend equivalent is `npm audit --audit-level=high` after every `npm ci` in `app/`; the root `package.json` (Playwright + Newman) is deliberately outside the gate, because newman's tree carries advisories with no forward fix and `audit fix --force` would break the collection.
- **`phpunit/phpunit` is capped below 13.3 (`>=13.1.13 <13.3`), with a matching `dependabot.yml` ignore.** 13.3 deprecates the `--do-not-cache-result` option that paratest passes to every worker it spawns, and `failOnPhpunitDeprecation` turns that into exit 1 on a suite where every test passed — so it breaks only the parallel run CI uses, not `make test`. Upgrading paratest is not a way out: 7.24 is the first release requiring `^13.3.0` and emits the same flag. The cap is what keeps the pair on the last combination that runs clean.
- **`doctrine/orm` is pinned to an exact `3.6.7`, and `dependabot.yml` ignores anything above it.** 3.6.8 adds `GenerateSchemaEventArgs::setSchema()`, which throws unless DBAL exposes `Schema::edit()` — an API its own source comment calls unreleased (it wants `doctrine/dbal ^4.5`; the newest stable is 4.4.4). `symfony/doctrine-bridge` feature-detects that method with `method_exists()`, so the bump makes the detection a false positive and every `doctrine:schema:validate` a `BadMethodCallException`. Unpin both once DBAL 4.5 ships. The npm-side counterpart is the Encore peer range below.

---

## Security

### API key

`^/api/*` is protected by the stateless `api` firewall and `ApiKeyAuthenticator`, which compares the `X-API-Key` header against `%env(API_KEY)%` with `hash_equals`. The pattern covers both `/api/v1/*` and the `/api/*` alias. Public exceptions: `/api/health` and the three API-doc routes.

**A rotation accepts two keys, never one.** `%env(API_KEY_PREVIOUS)%` is an optional second value `ApiKeyAuthenticator` also compares with `hash_equals` — both comparisons run unconditionally, not short-circuited, so which of the two matched is not observable from response timing. Empty (the default) means no rotation is in progress; it never degenerates into an empty-string match, because a request with no header is rejected on its own path before either comparison runs. Procedure: `docs/operations.md` → [API key rotation](docs/operations.md#api-key-rotation).

**No CSRF token on `^/api/*` (ADR-005)** — the firewall is stateless and authorization travels in a custom header, not a cookie, and a browser does not set custom headers cross-origin. OAuth init uses the `state` parameter.

### Frontend HTTP Basic

The `main` firewall (frontend pages + `/auth/*`) requires HTTP Basic against a single in-memory account (`FRONTEND_USER` / `FRONTEND_PASSWORD_HASH`). **It is not optional:** every page renders `API_KEY` into a `<meta name="api-key">` tag, so an anonymous frontend hands out full `/api/*` access.

`access_control` order matters — `{path: ^/api, roles: PUBLIC_ACCESS}` comes **before** `{path: ^/, roles: ROLE_USER}`; first match wins. The two firewalls are disjoint: `ApiUser` only ever carries `ROLE_API`, never `ROLE_USER`.

In the **test** environment `security.yaml`'s `when@test` sets `firewalls.main.security: false`, so the frontend and auth-controller tests need no credentials. The real configuration is proven instead by a test that boots the kernel in `dev`.

**`login_throttling` gates failed panel logins** — Symfony's built-in mechanism, which needs no authenticator-specific wiring because it reacts to the same `CheckPassportEvent` every `AuthenticatorManager`-based firewall goes through, `http_basic` included: 5 attempts per username+IP, 25 per bare IP, both per 15 minutes, both stored in `cache.rate_limiter` (Redis) alongside `api_per_ip`. A locked-out request and a wrong password both come back as a plain 401 with `WWW-Authenticate` — `HttpBasicAuthenticator` never varies the response body by failure reason, so a client cannot distinguish the two from the wire. Every failed attempt is logged to the `auth` channel — the same audit trail OAuth authorize/callback events use, via `LoginFailureAuditListener` filtered to the `main` firewall, since `LoginFailureEvent` also fires for `api`'s key mismatches and those do not belong in an operator-account audit log.

### HTTP headers

Dual layer — nginx and `SecurityHeadersListener` (`kernel.response`, priority -128) both set `X-Content-Type-Options: nosniff`, `X-Frame-Options: DENY`, `Referrer-Policy: strict-origin-when-cross-origin`, `Permissions-Policy: geolocation=(), microphone=(), camera=(), payment=()` and `Content-Security-Policy`.

**HSTS is sent only over TLS, from both layers, with one value.** nginx drives it from a `map $scheme` (`snippets/hsts-map.conf`) — an `add_header` whose value evaluates to empty is omitted entirely — and the listener sets it behind `$request->isSecure()`. RFC 6797 has a browser ignore the header on a plain-HTTP connection, so sending it there is not weaker protection but none, dressed as some. The value lives in the nginx map and in `SecurityHeadersListener::STRICT_TRANSPORT_SECURITY`, and `ProductionRuntimeConfigTest` fails the build when they disagree. No `preload`: that is a submission to a list shipped inside browser binaries, and its undo takes months.

A location-level nginx `add_header` **replaces** the inherited server-level ones, so any `location` block that sets a header of its own must re-declare the whole set — which is why it `include`s `snippets/security-headers.conf` rather than repeating it, and why the test asserts the include for every such block.

**Content-Security-Policy exists because every page carries the API key in a `<meta>` tag and the interface builds markup through `innerHTML` at roughly 130 call sites — one missed `escHtml` is normally a layout bug, but under that combination it is a full API takeover.** The policy is header-only, never a `<meta>` tag: a `<meta>` CSP cannot carry `frame-ancestors` at all, and the two would combine by intersection if both existed, which is a second thing to keep in sync for no gain. `default-src 'self'` covers script, connect and font by inheritance — every page-served asset is same-origin (Encore's `/build`, the three legacy panels' own `/js/*.js`, Chart.js's lazy same-origin chunk) — and `style-src` keeps `'unsafe-inline'` because progress bars and status badges interpolate a `style="width:${x}%"` attribute directly; `escHtml` protects the surrounding markup, not that attribute, and style injection there cannot reach the API key.

`/api/doc*` (Swagger UI, Redoc) carries a deliberately relaxed policy in its own nginx `map` branch and its own `SecurityHeadersListener` branch: both are vendor templates, neither touches the API key meta tag, and Redoc specifically needs an inline `<style>`, a Google Fonts stylesheet, a `blob:` Worker for its search index and `cdn.redoc.ly` for its logo — verified against a live render rather than guessed. Development additionally allows `https://cdn.jsdelivr.net` in `script-src`, and only there: it is what the FrankenPHP hot-reload script needs, and that script itself only renders under `APP_ENV=dev`.

Nginx's map is keyed on `$request_uri`, not `$uri` — `app.conf`'s `try_files $uri /index.php$is_args$args` rewrites `$uri` to `/index.php` internally before any PHP-routed response is built, so a map keyed on `$uri` would never see `/api/doc` and would silently fall back to the strict policy for every dynamic page. Both the default and `/api/doc*` policy strings are kept byte-identical between nginx (`snippets/csp-map.conf` production, `snippets/csp-map.dev.conf` development) and `SecurityHeadersListener::CONTENT_SECURITY_POLICY` / `::CONTENT_SECURITY_POLICY_API_DOC` — `ProductionRuntimeConfigTest` enforces it the same way it enforces HSTS, because two layers sending different policies would have a browser enforce their intersection rather than either one.

### Encryption at rest

`App\Security\TokenCipher` (libsodium secretbox, `base64(nonce ‖ ciphertext)`) implements the shared `TokenCipherInterface`. Four instances in `services.yaml`, one key per provider, so a compromise costs one provider. Discogs (OAuth1) is encrypted field by field; Google, Trakt and Spotify have their whole token JSON encrypted.

**A refresh response that omits `refresh_token` must not overwrite the stored one** — Spotify rotates it only occasionally, and a wholesale overwrite keeps working until the next expiry and then dies needing manual re-authorization. Merge the refreshed payload over the old one.

### API exception listener

`ApiExceptionListener` (`kernel.exception`, priority 64) turns uncaught throwables on `^/api/*` into JSON. `HttpExceptionInterface` keeps its status and message; anything else becomes a 500 with a generic message and the real one only in the log. It **unwraps `HandlerFailedException`**, so exceptions thrown in handlers are mapped exactly like those thrown directly. Non-API paths pass through to the Twig error pages.

### Rate limiting

`ApiRateLimitListener` (`kernel.request`, priority 100 — after `RequestIdListener` at 256, so a 429 still carries the correlator) throttles `^/api/*` per IP at 60/min, exempting `/auth/*`. A 429 carries `Retry-After`, `X-RateLimit-Remaining`, `X-RateLimit-Limit`.

**`/api/health` carries its own, looser limiter (`health_per_ip`, 120/min) rather than a full exemption** — a public, unauthenticated endpoint that runs five network probes per call is still a knob worth bounding, just not at the threshold that would risk an external uptime monitor or the in-process `app:monitor:run` sweep tripping it.

Outbound calls are throttled **proactively** by `RateLimitedHttpClient`, which waits before the request rather than reacting to a rejection. Six instances: Discogs, Last.fm, National Library, YouTube, Trakt, Spotify. The Google Calendar SDK and the OpenSearch client use their own transports and are deliberately not wrapped — neither is a Symfony `HttpClientInterface`, and the OpenSearch traffic is internal with no external quota.

---

## Health endpoint

`GET /api/health` is public. It probes MySQL (`SELECT 1`), Redis (`PING`), RabbitMQ (TCP, 1 s timeout), Search (`ping()`), the workers (heartbeat) and two disk locations.

**The per-component breakdown stays in the public payload, reviewed rather than assumed.** The body only ever carries the three-value status enum per component — no error message, version, hostname or path; those go to the `warning` log line a failed probe writes, never to the response — and an external uptime monitor, which is unauthenticated by construction, is the entire reason this endpoint is public: collapsing to a bare up/down would take away exactly what lets an operator see *which* dependency failed without opening a shell. What closes the actual risk — an unauthenticated endpoint running five network round trips per call, previously with no bound at all — is `health_per_ip` (see Rate limiting), not payload redaction.

Three components report **`degraded` (HTTP 200), never `down`**, on purpose:

- **Search** — an unreachable engine falls back to FULLTEXT, so a search outage must not take a working instance out of rotation.
- **Worker** — the instance still serves every request; the fix is restarting a worker, not shifting traffic.
- **`disk_backups`** — a full backup filesystem stops tonight's dump, not today's requests, and a 503 would free no space. The cost is announced as *critical* by the `backup:*` probes the moment a dump goes missing, short or stale, so this is the warning that arrives before the failure rather than instead of it.

The **worker probe answers a question nothing else does**: the `rabbitmq` probe opens a socket to the broker, and the broker is perfectly happy with nobody consuming. Each worker writes a heartbeat to Redis from its own run loop, one key per transport, and `worker` is `up` only when **every** transport in `WORKER_TRANSPORTS` has beaten within 5 minutes — all of them, not any, because the failure worth catching had the async worker alive and the scheduler dead. Asking RabbitMQ for consumer counts would not work either: `scheduler_default` never touches the broker.

Known limitation, stated rather than hidden: a single message taking longer than the threshold reads as `degraded` while the worker is busy. The heartbeat also only starts once the workers run this code, so a box that pulls a change without restarting them reports `degraded` until it does.

**Disk is two components, and it measures paths rather than the container it runs in.** `disk_database` reads the filesystem holding `DATABASE_DATA_DIR`, `disk_backups` the one holding `BACKUP_DIR` — which of the two filled up decides whether pruning dumps would help. Same thresholds (`< 80 %` up, `80–95 %` degraded, `≥ 95 %` critical), different meaning of critical: only `disk_database` turns it into `down`, because MySQL flush and binlog die with no headroom. The PHP container's root filesystem is its own image layer and answers for neither, so `php` and `scheduler_worker` mount `mysql_data` **read-only** at `DATABASE_DATA_DIR` — `statvfs` reports on the device holding a path, and both services run the probe (`php` serves the endpoint, `scheduler_worker` runs the monitoring sweep in-process). The prod overlay repeats both mounts, because `volumes: !override` replaces the list.

**A failed measurement is `degraded`, never `down`**, logged as a `warning` naming the path: a missing path is a mount that has gone, and not knowing how much space is left is not the same as knowing there is none. The measurement itself sits behind `DiskUsageReaderInterface`, because there is no way to arrange a real 96 %-full filesystem inside a test. `disk_free_space` reports what is free to the application while `disk_total_space` reports the whole device, so a filesystem an ordinary process has entirely filled reads as ~95 % on ext4 — that reserve is not usable space, so it is the right reading to threshold on.

---

## Operational alerting

`src/Monitoring/` — probes on a timer, e-mail out. It is what closes the gap the health endpoint left: every component correctly reported to nobody. Runbook, thresholds and per-alert first steps live in `docs/operations.md`.

- **It does not go through the Notifications module, and that is the whole design.** That engine reads a preference row and writes a notification row before sending — correct for user notifications, and unable to announce the database being down, because announcing needs the database. Alerting therefore reaches Mailer directly, keeps its dedup state in a **JSON file on local disk**, and builds the e-mail body in PHP rather than Twig. Nothing on the path touches MySQL, Redis or RabbitMQ. `AlertDeliveryIndependenceTest` breaks the lot at once and still demands the mail.
- **Quiet hours do not apply, and not via an exemption flag** — there is no `DispatchPolicy` in this path to exempt. Quiet hours suppress because a held *reminder* announces a passed deadline; infrastructure is the case where delay costs rather than saves.
- **The sweep announces transitions, never state.** Probes report everything wrong on every run; a week-long outage must cost one e-mail. A severity that **rises** is announced again (82 % and 96 % disk are different situations); one that falls is not. An alert no channel accepted is left un-recorded, so a mail outage delays rather than swallows it.
- **A probe that throws does not resolve its own alerts.** Its keys are held and the failure becomes a `probe:*` alert — otherwise the first act of a broken probe would be an all-clear for everything it used to watch. That is why keys are namespaced by probe, and why the monitor iterates the probes itself instead of hiding them behind a composite.
- **`MonitorSystemHealth` is the one recurring command deliberately left out of `messenger.yaml` routing.** Routed to async, the alert about a dead async worker would sit in the queue that worker was meant to drain, and reporting RabbitMQ down would need RabbitMQ up. It runs inline in the scheduler worker; that worker's own death is the known blind spot, which is what `app:monitor:run` (`make monitor-run`) exists for — an external timer covers it.
- **Backup freshness is single-sourced with `scripts/doctor.sh`**: same `BACKUP_MAX_AGE_HOURS` / `BACKUP_MIN_BYTES`, and age read from the **date in the filename**, because copying or restoring the backup directory stamps every file's mtime with "now" and would make a months-old archive look fresh.
- The health probe is called **in process**, not over HTTP — an HTTP hop would put nginx, the firewall and the network between the alerter and the thing it reports on. `/api/health` stays for external uptime monitors, which is what HTTP is good for.
- **`MAILER_DSN` ships as `null://null`, which accepts every alert and delivers none — reporting success as it does so.** An instance left on that default has working probes and no alerting at all: the same shape of failure this exists to end, one level up. `make doctor` warns about it, and about `NOTIFICATIONS_MAIL_TO` still being the placeholder. In production the state file needs the `monitoring_state` volume, or every container recreation re-announces whatever was already failing.

---

## MCP servers (`.mcp.json`)

`sequential-thinking`, `github`, `context7`, `filesystem`, `mysql` (read-only), `playwright`, `redis`, `docker` (needs `uv` on the host). Atlassian Rovo is configured through claude.ai, not `.mcp.json`; when it is unresponsive, Jira REST v3 with the same token is the fallback — it needs ADF, not markdown.

## Skills useful here

- `/start-task <KEY>` — Jira → branch → implement → PR → Confluence → transition.
- `/start-fixVersion <X.Y.Z>` — the whole version, ticket by ticket, then epic review and release.
- `/code-review`, `/security-review`.
- `superpowers-symfony:symfony-tdd-phpunit`, `:symfony-check`, `:doctrine-architect`, `:symfony-reviewer`, `:functional-tests`.

---

## Rules for working with Claude Code

1. Read this file and describe the plan before implementing.
2. One Jira task = one session.
3. After every code change: `make test`. Do not report readiness while tests fail.
4. **Ad-hoc work only** — before `git commit`, show the diff and the proposed message and wait for approval. This does **not** apply inside `/start-task` / `/start-fixVersion`, which commit on their own; the PR is the review gate there.
5. Do NOT add `Co-Authored-By: Claude` to commits.
6. Branches: `<KEY>-short-description`, from `develop`.
7. Every PR is merged with **"Rebase and merge"**. Realigning branches is rebase + force-push, never a merge commit. Neither `develop` nor `master` is branch-protected, so nothing enforces this mechanically.
8. After a larger step (a closed ticket, an epic review, a release) **propose** `/compact` rather than running it.
9. CI is the suite gate: run the module's subset locally, push, and let CI run the rest. Poll `gh pr checks` actively rather than idling on a monitor.

---

## Maintaining this file

This file is loaded into context at the start of **every** session, so its size is a standing cost paid on every task. It grew to roughly forty-five thousand words by treating each closed ticket as something to append, and the cost was not only tokens: a file that reads as a description of the present while being written as a history starts to mislead. A sentence true when it was written goes quiet and stays as a rule, and nobody re-checks it, because a historical sentence cannot be checked against the code at all.

So, when closing a ticket or an epic:

- **Update the description of the current state; do not append a paragraph of history.** If a rule changed, rewrite the rule. If a rule was removed, delete the sentence — do not annotate it as formerly true.
- **Every sentence here must be verifiable against the code as it stands.** A sentence that is only historically true is deleted, not updated.
- **No ticket keys and no release numbers as carriers of information.** What matters is the rule, not which ticket introduced it; `git log` and `CHANGELOG.md` are for archaeology. The same rule already applies to the Confluence pages.
- **Keep the reason, drop the chronology.** "Quiet hours suppress rather than defer, because a held reminder announces a deadline that may already have passed" belongs here. "We first considered deferring them, then changed our minds in a later release" does not.
- **Ask whether the next reader needs it to write correct code.** A trap that has already cost someone a day — the nullable-embeddable hydration, the CRLF shebang, `require.context` under ESM — earns its lines. A per-endpoint recital of request bodies does not; that is what the OpenAPI contract is for.
- **Full history lives in `CHANGELOG.md`, on Confluence and in `git log`.** Nothing needs to be duplicated here to be preserved.

---

## Links

- Confluence hub: https://honemanager.atlassian.net/wiki/spaces/H/pages/46661633
- Repo: `zlotylesk/AIHomeManager` (GitHub)
- `README.md` — the project's front door: prerequisites, the fresh-clone quick start, the secrets table, a map of everything else.
- `docs/` — reference material: `configuration.md` (every env var, how to obtain each key, the OAuth flows), `development.md` (branches, naming, layout, the full Makefile), `testing.md` (test layers, coverage ratchet, static analysis, CI), `operations.md` (workers, DLQ, monitoring, failure alerting, backups), `search.md` (backends, cutover, recovery), `api.md` (versioning, auth, pagination), `pwa.md` (Service Worker, offline queue).
- `CHANGELOG.md` — release history and the decisions behind it.
