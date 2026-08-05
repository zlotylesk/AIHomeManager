# Operations

Everything in this file is about a running instance: the workers behind the
queue, what to do when one stops, monitoring, and the backup that has to be
there when it is needed.

Start with `make doctor`. It checks the Docker daemon, the containers, the
`.env.local` keys (decoding each encryption key to confirm it is really 32
bytes), the php image, the backup archive and the dead-letter queue depth, and
it exits non-zero on a real failure.

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
