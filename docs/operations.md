# Operations

Everything in this file is about a running instance: the workers behind the
queue, what to do when one stops, monitoring, and the backup that has to be
there when it is needed.

Start with `make doctor`. It checks the Docker daemon, the containers, the
`.env.local` keys (decoding each encryption key to confirm it is really 32
bytes), the php image, the backup archive and the dead-letter queue depth, and
it exits non-zero on a real failure.

## Production deployment

Development and production are two stacks, and what separates them is not a
flag — it is where the code comes from.

| | Development | Production |
|---|---|---|
| Source | `./app` bind-mounted over `/var/www/html` | baked into the image |
| Environment | `dev`, debug on | `prod`, debug off |
| Dependencies | including `require-dev` | `composer install --no-dev` |
| Frontend bundle | built on demand in the `node` container | built by the image's `assets` stage |
| Symfony cache | compiled on the first request | warmed during the build |
| OPcache | `validate_timestamps=1` — every included file stat()ed per request | `validate_timestamps=0` |

Production is `docker-compose.yml` **plus** `docker-compose.prod.yml`, always
both. Every `make prod-*` target passes the pair, which is why nothing below
spells out a bare `docker compose` command: without `-f` you are talking to the
development stack.

### Before the first deployment

`app/.env.local` must exist on the host with the real values. It is deliberately
**not** in the image — `.dockerignore` excludes it, because a layer that
captured it would keep the secrets there after any later deletion. Instead the
production overlay reads it as an `env_file`, so the values reach the containers
as environment variables and Symfony's real-environment precedence puts them
above the placeholders in the tracked `app/.env`.

That file is therefore required, not optional: without it Compose refuses to
start, which is the intended outcome. The alternative is worse than a failed
deploy — the placeholders in `app/.env` are empty, so an instance would come up
with an empty `API_KEY` and an empty `FRONTEND_PASSWORD_HASH` and look perfectly
healthy while authenticating nobody.

The startup-critical set is `API_KEY`, `FRONTEND_USER`/`FRONTEND_PASSWORD_HASH`
and the four 32-byte base64 token keys; `docs/configuration.md` lists every
variable and how to generate it. `make doctor` decodes the keys and reports a
wrong one before a container refuses to boot over it.

### First deployment

```bash
make prod-build      # image with the code, the bundle and a warm cache
make prod-migrate    # creates the schema; brings MySQL up via depends_on
make prod-up         # start serving
make search-index    # only when SEARCH_ENGINE_BACKEND=opensearch
```

### Updating a running instance

```bash
git pull
make prod-build      # the running containers are untouched throughout
make prod-migrate    # new migrations, old code still serving
make prod-up         # recreates the containers on the new image
```

**The migration step sits in the middle, and that is the whole of the ordering
question.** `prod-migrate` uses `docker compose run --rm`, so it runs the
migrations from the image just built rather than from whatever the running
container holds — and it runs them before any new container serves a request
against the schema they change.

The cost of that order is a window between `prod-migrate` and `prod-up` in which
the OLD code runs against the NEW schema. That is safe for an additive migration
— a new table, a new nullable column, a new index — which is what nearly all of
them are. A migration that drops or renames something the running version still
reads is not safe in either order: stop first, then migrate.

```bash
make prod-down && make prod-migrate && make prod-up
```

### Verifying a deployment

```bash
make prod-about   # must report Environment prod / Debug false
curl -s -o /dev/null -w 'health %{time_total}s\n' http://localhost:8080/api/health
```

`prod-about` is the check that would have caught a stack serving `dev` with
debug on, which is exactly what ran here before there was a production
configuration at all.

The health call is the second half. Measured on the development machine, same
endpoint, same data: **13 ms in production against 5.2 s in development**, and
an unauthenticated `/api/series` at 7 ms against the 3.8 s that prompted this
work. The cause is not one setting — it is `prod` without the debug machinery,
a container compiled at build time instead of on the first request, and OPcache
no longer re-`stat()`ing every included file.

### Rolling back

Images are tagged `aihm-php:prod` and `aihm-nginx:prod`, so a rollback is a
checkout of the previous revision plus `make prod-build && make prod-up`. Roll
the schema back only if the release actually changed it —
`doctrine:migrations:migrate prev` — and only when the migration was reversible.

Not configured yet, and worth knowing before this is exposed to anything: HTTPS
and HSTS, infrastructure ports still published on the host, broker and Redis
still on default credentials, no restart policies or resource limits, no log
rotation.

## Workers

Two of them, and they consume different things:

| Container | Transport | Carries |
|---|---|---|
| `messenger_worker` | `async` (RabbitMQ) | Trakt imports, Discogs refresh, Last.fm and Spotify polls, streak recompute, notification dispatch, incremental search indexing |
| `scheduler_worker` | `scheduler_default` (Symfony Scheduler) | 10 recurring tasks — nightly backup, weekly report, daily article reset, Discogs refresh, Last.fm poll, streak recompute, search reindex, two notification sweeps, podcast poll |

```bash
docker compose exec php bin/console debug:scheduler     # the recurring tasks and their next run
make messenger-status                                   # what is registered on which bus
docker compose restart messenger_worker scheduler_worker
```

Routing lives in `app/config/packages/messenger.yaml`. In the test environment
both transports are `in-memory://`, so a test that dispatches an async command
parks it rather than running it.

The Scheduler is stateful through `cache.app` (filesystem, mounted on the host),
and fires **at most one** missed window after a restart. That is why the 03:00
backup on a workstation powered off overnight actually runs somewhere between
07:00 and 22:00 — see the freshness threshold below.

### Is anything consuming?

`GET /api/health` carries a `worker` component that answers this, because
nothing else does. The `rabbitmq` probe opens a socket to the broker, and the
broker is perfectly happy with nobody consuming: an instance with both workers
dead used to report five green components while every import, notification,
search-index update and the nightly backup silently stopped.

Each worker writes a heartbeat to Redis from its own run loop, one key per
transport. `worker` is `up` only when **every** expected transport has beaten
within 5 minutes — all of them, not any, because the failure that prompted this
had the async worker alive and the scheduler dead. It reports `degraded` (HTTP
200), never `down`: the instance still serves every request it is asked to, and
the fix is restarting a worker rather than shifting traffic away.

```bash
curl -s localhost:8080/api/health | jq '.components.worker'
```

Asking RabbitMQ for consumer counts would not have worked: `scheduler_default`
is a Symfony Scheduler transport and never touches the broker, so the worker
whose silence actually costs something is invisible from there.

Two things to know. The heartbeat only starts once the workers run this code, so
after pulling a change they must be restarted or `worker` stays `degraded`. And
a single message taking longer than 5 minutes reads as `degraded` while the
worker is in fact busy — a deliberate trade, since the reading is informational
and the failure worth catching lasts days rather than minutes.

### Dead-letter queue

A message that exhausts its retries lands in the `failed` transport (queue
`series_events_failed`) and stays there. **Nothing consumes it by design** — it
is waiting for a person — so nothing surfaces it either, however deep it gets.
`make doctor` reports the depth; these are the commands behind it:

```bash
docker compose exec php bin/console messenger:stats          # depth per transport
docker compose exec php bin/console messenger:failed:retry   # replay, one prompt each
docker exec aihm-rabbitmq-1 rabbitmqctl list_queues name messages
```

**`messenger:failed:show` does not work here.** It needs a receiver that can
list and address messages by id, which the Doctrine transport offers and AMQP
does not — it answers `The "failed" receiver does not support listing or showing
specific messages`. Use `messenger:stats` for the depth and
`messenger:failed:retry` to work through them.

### Broker persistence

The broker keeps a named volume **and** a fixed hostname, and both are required.
The queues, exchanges and messages have always been durable — Symfony's AMQP
transport declares `AMQP_DURABLE` and sets `delivery_mode: 2` — but durability is
written to `/var/lib/rabbitmq`, and without a volume that lived in the
container's writable layer: a plain `restart` kept everything, while recreating
the container (`docker compose down`, an image bump, any edit to the compose
file) took the DLQ with it.

The hostname matters for the same outcome by a different route. RabbitMQ derives
its node name from it and stores each node's database under
`mnesia/rabbit@<hostname>`, so with Docker's default (the container id) every
recreation would start an empty new node while the old one's data sat untouched
beside it in the volume — the mount would look like it was working and the
messages would still be gone.

## Monitoring

The `graylog + mongodb + opensearch` stack runs under the Compose `monitoring`
profile. `make up` starts the full stack including it; `make min-up` is the lean
variant without.

```bash
make monitoring-up
make monitoring-bootstrap    # GELF UDP input + index sets + streams (idempotent)
make monitoring-logs
make monitoring-down
```

Log in at http://localhost:9000 (admin/admin). The bootstrap creates the GELF
UDP input on port 12201, the `auth-events` (90-day retention) and
`series-events` (30-day) index sets, and streams filtering by `channel`.

A Graylog that is not running does not crash anything: the GELF transport is
wrapped so the `series` and `auth` channels are silently dropped and the request
continues. `NewRelicMonologHandler` degrades the same way when the `newrelic`
extension is absent.

This Graylog OpenSearch is a **different instance** from the one global search
uses — different lifecycle, different retention, and the search one starts with
the lean stack too.

## MySQL backup

The Scheduler runs `App\Application\Scheduled\BackupDatabase` daily at 03:00.
Retention is 30 daily plus 12 monthly (the 1st of each month is kept).

```bash
make backup-now
make restore BACKUP=backups/homemanager-2026-06-01.sql.gz
```

### Two image-level dependencies

The dump runs as `bash -o pipefail -c "mysqldump … | gzip …"`, and both halves of
that are load-bearing:

- **`bash -o pipefail`** — POSIX `sh` reports only the *last* pipeline member's
  exit code, so a failed `mysqldump` is masked by a successful `gzip` and leaves
  a truncated archive that looks like a healthy backup.
- **`mariadb-connector-c`** — Alpine's `mysql-client` is in fact MariaDB's
  client and ships no `caching_sha2_password` module, which is MySQL 8.4's
  default authentication plugin. Without it `mysqldump` cannot authenticate at
  all.

Both are installed in `docker/php/Dockerfile`. **An image built before those
lines must be rebuilt:**

```bash
docker compose build php && docker compose up -d php messenger_worker scheduler_worker
```

Without the rebuild the scheduled backup aborts with `bash: not found`. That
failure is loud — an `error` log entry plus a Messenger retry and the DLQ — but
no backup is produced until the image is refreshed. `make doctor` checks both
dependencies directly.

### Freshness check

The two guards above name causes. `make doctor` additionally checks the
**outcome**, because the causes worth guarding against are only the ones already
known, and the failures that actually happened were not on that list:

| Check | Threshold | Verdict |
|---|---|---|
| Newest backup's size | > 1024 B | `fail` — an empty dump (20 B is gzip's empty stream); restoring from it yields nothing |
| Newest backup's **age** | < 48 h (`BACKUP_MAX_AGE_HOURS`) | `fail` — the schedule has stopped producing backups |
| Retained backups that are empty | any | `warn` — those days have no restore point, but today's is intact |

The age check exists because a schedule that simply **stops firing** leaves a
perfectly valid dump in place, and a size-only check passes it indefinitely.

**48 hours, not 24**, because the 03:00 cron is not when the backup actually runs
on a workstation that is powered off overnight — the Scheduler fires the missed
window whenever the host next comes up. A 24-hour threshold would cry wolf on an
ordinary day, and a check that is routinely wrong is one people stop reading.

Age is read from the **date in the filename**, not from mtime. The two normally
agree, but copying, restoring or syncing the backup directory stamps every file
with "now" — so an mtime check would call a months-old archive perfectly fresh,
which is exactly the reassuring-but-wrong answer the check exists to remove. The
filename is also what `make restore BACKUP=…` takes.

When it fails:

```bash
make backup-now                    # the restore point you are currently missing
docker compose ps scheduler_worker # then find out why the schedule stopped
docker compose logs scheduler_worker | grep -i backup
```

To reproduce either verdict without waiting two days or touching the real
archive, point the check at a throwaway directory:

```bash
mkdir -p /tmp/bk && head -c 20000 /dev/urandom > /tmp/bk/homemanager-2026-01-01.sql.gz
BACKUP_DIR=/tmp/bk bash scripts/doctor.sh   # exits 1 on the age check
```

The search index is deliberately **not** backed up — every document in it is
derived from the MySQL tables, so the dump already contains everything. See
[search.md](search.md#recovery).
