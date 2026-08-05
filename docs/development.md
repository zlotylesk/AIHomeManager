# Development

## Branches and releases

```
master   ← stable, synced from develop at each release
develop  ← integration, the default target for PRs
<KEY>-short-description  ← created from develop
```

Every PR is merged with **"Rebase and merge"** (`gh pr merge <n> --rebase`) — the
new commits are replayed on the target's tip, so both branches stay linear.
Merge commits are not used. Realigning two branches that have drifted is done
the same way, by rebasing and force-pushing, never by merging one into the other.

At release time `develop` is synced onto `master` through a PR merged that same
way, and `develop` is then rebased back onto `master` so both refs point at one
commit. **The release tag is created after that sync, never before** — a rebase
creates new commit objects, so a tag made on the pre-sync commit is left on an
orphan no branch can reach.

## Naming conventions

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

## Project layout

```
.
├── app/                            ← Symfony root
│   ├── assets/                     ← Webpack Encore source
│   │   ├── app.js, bootstrap.js, util.js
│   │   ├── controllers/            ← Stimulus controllers (auto-mounted)
│   │   ├── {series,goals,movies,…}/← pure helpers + view builders (NOT auto-mounted)
│   │   ├── pwa/                    ← manifest, icons, Service Worker source
│   │   ├── tests/                  ← Vitest unit tests
│   │   └── styles/app.css
│   ├── config/packages/            ← security, messenger, rate_limiter, doctrine…
│   ├── deptrac.yaml                ← architecture boundary config
│   ├── migrations/
│   ├── public/
│   │   ├── build/                  ← Encore output (gitignored)
│   │   └── js/                     ← vanilla JS (Tasks/Articles/Music)
│   ├── src/
│   │   ├── Controller/Api/         ← version-agnostic API controllers (imported twice)
│   │   ├── EventListener/          ← request id, API exceptions, rate limit, headers
│   │   ├── Health/
│   │   ├── Http/                   ← RateLimitedHttpClient
│   │   ├── Logging/                ← Monolog processors, request-id holder
│   │   ├── Messaging/              ← typed Query/Command bus + Messenger middleware
│   │   ├── Module/{Name}/{Domain,Application,Infrastructure}/
│   │   ├── Schedule.php
│   │   ├── Security/
│   │   ├── Serializer/             ← *DTONormalizer (DTO → JSON)
│   │   └── Shared/                 ← shared kernel (cross-context VOs + contracts)
│   ├── templates/                  ← Twig
│   └── tests/{Unit,Integration}/
├── docker/                         ← Dockerfiles (php, nginx, opensearch)
├── docs/                           ← this directory
├── scripts/                        ← doctor.sh, graylog-bootstrap.sh, confluence-page.ps1
├── tests-e2e/                      ← Playwright specs + support/ + postman/
└── Makefile, docker-compose.yml, CHANGELOG.md, CLAUDE.md, README.md
```

## Frontend tracks

Two, and the split is being closed rather than maintained. Everything built
since the Goals module uses **Webpack Encore + Stimulus** (`app/assets/`); three
older panels — Tasks, Articles, Music — are still Twig + vanilla JS in
`app/public/js/`.

They share one implementation of the common helpers rather than carrying copies:
`assets/legacy-globals.js` publishes the very same function objects on `window`
(`escHtml`, `apiCall`, `safeUrl`, the pagination helpers, the global banners),
and `app.js` calls it first thing. **That ordering is load-bearing** —
`base.html.twig` renders `encore_entry_script_tags('app')` before
`{% block javascripts %}`, and Encore emits classic, non-deferred script tags, so
the bundle has finished executing before the first legacy file is parsed. Adding
`defer` or `type=module` to the Encore output would break every legacy panel at
once.

`npm test` runs `node --check` over the three legacy files before Vitest,
because nothing else parses them: they are not in the Encore build and not in
Vitest's include glob, so a syntax error there survives every other gate and
first shows up as a dead panel in a browser.

## Makefile reference

### Environment

| Command | Action |
|---|---|
| `make up` / `make min-up` | Start with / without the monitoring profile |
| `make down` | Stop everything |
| `make setup` | `build` + `up` + `composer install` + create DB + migrate |
| `make doctor` | Preflight health check (env, image, backups, queues) |
| `make shell` | Shell in the php container |
| `make logs` | Tail all services (`logs-php`, `logs-worker`, … for one) |
| `make services` / `make routes` | DI container / routing dump |
| `make messenger-status` | What the workers consume |

### Database and search index

| Command | Action |
|---|---|
| `make migrate` / `make migrate-test` | Doctrine migrations, dev / test env |
| `make schema-validate` | ORM XML mapping ↔ MySQL schema |
| `make fixtures` | Demo data (dev only) |
| `make search-index` / `make search-reindex` | Create the index+alias / rebuild and swap |
| `make search-populate` | Fill the active backend's index from the source modules |
| `make backup-now` | Ad-hoc mysqldump + gzip |
| `make restore BACKUP=backups/homemanager-YYYY-MM-DD.sql.gz` | Restore |

### Frontend

| Command | Action |
|---|---|
| `make node-install` | `npm install` in the node container |
| `make assets` / `make assets-watch` / `make assets-prod` | Encore dev / watch / production |
| `make test-js` | Legacy parse check + Vitest |
| `make node-audit` | npm CVE gate (high + critical) |

### Tests and analysis

| Command | Action |
|---|---|
| `make test` / `make test-unit` / `make test-integration` | PHPUnit |
| `make test-parallel` | PHPUnit through paratest — the CI profile |
| `make test-coverage` | PHPUnit + coverage report + floor gate |
| `make test-e2e-install` / `make test-e2e` | Playwright |
| `make test-newman-install` / `make test-newman` | Newman/Postman smoke |
| `make analyse` | CS Fixer + PHPStan + Deptrac + Composer audit |
| `make phpstan` / `make phpstan-baseline` | PHPStan level 8 / regenerate the baseline |
| `make cs-check` / `make cs-fix` | PHP CS Fixer |
| `make rector-dry` / `make rector` | Rector |
| `make deptrac` / `make deptrac-baseline` | Architecture boundaries |
| `make audit` | Composer security advisories |
| `make openapi-dump` / `make openapi-lint` | Dump the contract / dump + Spectral |

### Monitoring

| Command | Action |
|---|---|
| `make monitoring-up` / `make monitoring-down` / `make monitoring-logs` | Graylog stack |
| `make monitoring-bootstrap` | GELF input + index sets + streams (idempotent) |

## Windows note

The containers bind-mount the working tree, and a shebang is not a comment: with
`core.autocrlf=true` a checkout would write `#!/usr/bin/env php\r`, and Linux
`env` then looks for a program literally named `php\r`. Every Makefile target
that runs `bin/console` through its shebang would fail with the thoroughly
misleading `env: can't execute 'php': No such file or directory`, while the same
commands work in CI and on Linux.

`.gitattributes` pins `*.sh` and `app/bin/console` to LF so this cannot happen.
A normal `git clone` picks it up. A working tree that predates the file needs one
re-checkout:

```bash
git rm --cached -r . >/dev/null && git reset --hard
git ls-files --eol app/bin/console          # expect: i/lf  w/lf
```

Do **not** fix this by rewriting line endings with container tools — busybox
`sed`/`tr` mangle the sources. Let git's own attribute do it.
