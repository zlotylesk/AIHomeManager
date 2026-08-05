# Tests, static analysis and CI

```bash
make test               # PHPUnit (Unit + Integration), sequential
make test-unit          # Domain only
make test-integration   # integration only
make test-parallel      # paratest — mirrors the CI profile
make test-coverage      # PHPUnit + coverage report + floor gate
make test-js            # legacy parse check + Vitest
make test-e2e           # Playwright (desktop + mobile)
make test-newman        # Newman/Postman smoke
```

## Layers

- **Unit** — `tests/Unit/Module/{Name}/Domain/`. The gold standard is
  `tests/Unit/Module/Series/Domain/SeriesAggregateTest.php`.
- **Integration** — `tests/Integration/`, against a real database and Redis with
  the async transports switched to `in-memory://` (`when@test` in
  `messenger.yaml`).
- **API** — `*ApiTest` classes use `App\Tests\Support\AuthenticatedApiTrait`,
  which adds the `X-API-Key: test-api-key` header (`app/.env.test`) and decodes
  the response with an asserted shape.
- **E2E** — `tests-e2e/`, TypeScript. Playwright's `testMatch` collects only
  `*.desktop.spec.ts` (1440×900) and `*.mobile.spec.ts` (Pixel 5), so shared
  helpers can live under `tests-e2e/support/` without being picked up as a suite.
- **Smoke** — `tests-e2e/postman/AIHomeManager.postman_collection.json`, run via
  `make test-newman` (truncate + newman with `--ignore-redirects`).
- **JS unit** — `app/assets/tests/*.test.js`, jsdom, for the frontend's pure
  helpers. They must **not** live under `assets/controllers/`, where the Stimulus
  `webpackContext` auto-mounts every `.js` as a controller and would break the
  build.

`make test-newman` truncates the tables its collection writes to. Do not point
it at data you care about.

## Test isolation

An integration test truncates the tables it uses in `setUp`. There is no shared
abstract base and no transaction rollback wrapper — that per-test cleanup is
what makes a worker's own database deterministic across the classes it runs, and
it is what lets the suite run in parallel at all.

## Coverage gate and ratchet

`make test-coverage` measures line coverage through pcov and fails below the
floor (`COVERAGE_MIN`, default 90). CI enforces the same floor, uploads the HTML
report as an artifact, and publishes a summary to the job's **GitHub step
summary**: current %, floor, baseline, and the run-over-run trend (Δ versus the
previous run, persisted between runs via `actions/cache`). Locally the same
summary prints to the terminal.

**The floor only moves up.** When the trend shows coverage sitting comfortably
above a higher number across several runs, raise it in one small PR — bump
`COVERAGE_MIN` in the `Makefile` **and** the literal in the `tests` job of
`.github/workflows/ci.yml`, which are kept in sync by hand. Never lower it to
green a red build; add the missing tests instead.

## Parallel run

CI runs PHPUnit through **paratest** across `PARATEST_PROCESSES` workers
(default 4). Each worker gets its own MySQL database (`homemanager_test{token}`,
via `dbname_suffix` in `doctrine.yaml`) and its own Redis logical DB (rewritten
in `tests/bootstrap.php`), so integration tests never collide on shared state.
Per-worker coverage is merged into one clover file and the floor still gates.

`make test` stays sequential — faster for a TDD loop. `make test-parallel`
mirrors CI locally: it provisions the token databases as root, migrates each,
then runs paratest with coverage.

## PHPUnit gates

`phpunit.dist.xml` sets `failOnDeprecation`, `failOnPhpunitDeprecation`,
`failOnNotice` and `failOnWarning`. A new PHP deprecation in `src/` or one of
PHPUnit's own deprecations blocks CI. Vendor noise is filtered
(`ignoreIndirectDeprecations`), and notices from test data are not on the gate.

## Static analysis

```bash
make analyse              # CS Fixer (dry-run) + PHPStan + Deptrac + Composer audit
make phpstan-baseline     # regenerate the baseline after fixing errors
```

**PHPStan** runs at level 8 with the Symfony, Doctrine and PHPUnit extensions.
The baseline (`app/phpstan-baseline.neon`) holds one deliberately kept entry — a
wire-contract assertion PHPStan sees as a tautology. A new error requires a fix
or an explicit baseline entry.

**Deptrac** formalizes the hexagonal boundaries: per-module Domain / Application
/ Infrastructure layers plus a cross-cutting `Shared` kernel. It runs with
**zero violations and zero `skip_violations`** — the grandfathered exceptions
were resolved rather than re-baselined, so a new violation is a hard failure
with nothing to hide behind.

**PHP CS Fixer** applies `@Symfony` + `@PHP84Migration`. On Windows the working
tree is CRLF while the index is LF, so a local `make cs-check` reports whole-file
`line_ending` diffs that CI never sees. When a local run and CI disagree, the
index is the authority — CI checks out LF.

**Composer audit** queries the FriendsOfPHP advisory database on every run. A
failure means bumping the package, not suppressing the finding.

The frontend has the same gate: every `npm ci` in CI is followed by `npm audit
--audit-level=high`. The root `package.json` (Playwright + Newman) is
deliberately outside it — newman's dependency tree carries advisories with no
forward fix, and `audit fix --force` would roll it back to a version that cannot
run the collection.

## CI pipeline

`.github/workflows/ci.yml` runs on every push to `master`/`develop` and on every
PR. A `concurrency` group per ref cancels a superseded run the moment a newer
commit lands on the same branch, without touching other branches.

| Job | Gate | Observed | `timeout-minutes` |
|---|---|---|---|
| `static-analysis` | Rector · CS Fixer · PHPStan L8 · Deptrac · Composer audit — one parallel matrix leg per tool (`fail-fast: false`, so every failure surfaces in one run) | ~20–51 s / leg | 6 |
| `openapi-contract` | dump `openapi.json` → Spectral lint → upload the spec artifact | ~30 s | 5 |
| `tests` | PHPUnit via paratest + the coverage floor gate | ~2m15s | 12 |
| `e2e-playwright` | Playwright desktop + mobile + the Lighthouse installability audit | ~3m10s | 15 |
| `e2e-newman` | Newman API smoke | ~1m30s | 7 |

**Caching**, keyed so the right cache is invalidated on change: Composer's
download dir + `app/vendor` (on `composer.lock`), the npm cache (on the relevant
`package-lock.json`), Playwright browsers (on the exact `@playwright/test`
version, so a bump re-downloads a matching build), and a one-line coverage
history file rolled across runs.

**Timeouts** carry deliberate headroom — each sits well under 25 % utilisation at
the observed peak, because a flaky timeout is worse than a loose one. If a job
later approaches 70 % of its bound, raise it; never lower one reactively.

### What CI structurally cannot catch

CI runs PHPUnit on the GitHub runner, **never inside the image the application
ships in**. An image-level runtime dependency — a missing binary, an absent
authentication plugin — is therefore invisible to every job here, however green.
That is not a hypothetical gap: it is how a broken backup pipeline survived for
a month. `make doctor` is what covers it, by checking the image and the
*outcome* rather than the code.
