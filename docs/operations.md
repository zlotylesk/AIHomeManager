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
make prod-up         # start serving, on a self-signed placeholder certificate
make prod-cert-init DOMAIN=aihm.example.com EMAIL=you@example.com
make search-index    # only when SEARCH_ENGINE_BACKEND=opensearch
```

`prod-up` comes **before** the certificate, not after. Let's Encrypt proves you
control the domain by fetching a file over plain HTTP from the server answering
for it, so there has to be a server answering first — which is why nginx starts
on a self-signed placeholder rather than refusing to start without a
certificate.

### HTTPS and certificates

Browser traffic is HTTPS. Port 80 keeps two jobs and nothing else: it answers
the ACME challenge, and it 301s every other request to the same resource over
HTTPS.

The public hostname appears in exactly one place in this repository — the
`DOMAIN=` argument above. Certificates are issued under the fixed lineage name
`aihm`, so nginx names a lineage instead of a domain and a change of domain is
a re-run of `prod-cert-init`, not an edit.

| | |
|---|---|
| Issued by | Let's Encrypt, http-01 challenge over the webroot |
| Lives in | the `letsencrypt` volume, symlinked to `/etc/nginx/certs/` at container start |
| Renewed by | the `certbot` service, `certbot renew` twice a day |
| Picked up by | nginx reloading itself every 12 h |
| HSTS | `max-age=31536000; includeSubDomains`, no `preload` |

**Renewal needs nothing from you and no restart.** `certbot renew` is a no-op
until thirty days before expiry, then replaces the file the symlink already
points at; the next reload serves it. Roughly sixty attempts happen before
anything expires, so a spell of downtime or a rate limit costs an attempt.

**The first issuance does need one restart**, which `prod-cert-init` performs:
until the lineage exists nginx is serving the placeholder, and the swap happens
at container start.

To read the outcome rather than wait for it:

```bash
make prod-cert-renew                       # dry-run against staging, then for real
docker compose -p aihm-prod exec certbot certbot certificates
```

**When renewal fails**, the certificate is still valid for up to thirty days —
this is not an outage, it is the warning before one. In order of likelihood:
the domain no longer resolves to this host; port 80 is not reachable from the
internet (the challenge is answered there, and only there); the `certbot_webroot`
volume is no longer shared by both containers, so certbot writes the token
where nginx cannot serve it. `make prod-cert-renew` reports which.

An expired certificate does **not** lock you out of fixing it: the ACME location
on port 80 is exempt from the redirect precisely so that the route back to a
working certificate never runs through HTTPS.

**HSTS outlives the certificate.** Once a browser has seen the header it refuses
plain HTTP for a year, so an instance cannot be moved back to HTTP by reverting
this configuration — visitors would see a connection failure, not a downgrade.
Reverting means keeping HTTPS working until the max-age has run out.

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
curl -sI http://aihm.example.com/api/health | head -1          # expect 301
curl -s -o /dev/null -w 'health %{time_total}s\n' https://aihm.example.com/api/health
curl -sI https://aihm.example.com/ | grep -i strict-transport  # expect the HSTS header
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

A rollback does not touch the certificates: they live in a volume, not in the
image, and the lineage name does not change between revisions.

Not configured yet, and worth knowing before this is exposed to anything:
infrastructure ports still published on the host, broker and Redis still on
default credentials, no restart policies or resource limits, no log rotation.

## Workers

Two of them, and they consume different things:

| Container | Transport | Carries |
|---|---|---|
| `messenger_worker` | `async` (RabbitMQ) | Trakt imports, Discogs refresh, Last.fm and Spotify polls, streak recompute, notification dispatch, incremental search indexing |
| `scheduler_worker` | `scheduler_default` (Symfony Scheduler) | 11 recurring tasks — nightly backup, weekly report, daily article reset, Discogs refresh, Last.fm poll, streak recompute, search reindex, two notification sweeps, podcast poll, and the monitoring sweep, which runs inline here rather than being routed (see [Failure alerting](#failure-alerting)) |

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
is waiting for a person. The monitoring sweep is what surfaces it now (the
`queue:failed` alert); `make doctor` reports the depth on demand, and these are
the commands behind both:

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

## Failure alerting

Detection was never the missing part. The health endpoint has known the state of
six components for a long time, the backup job logs its own failures, the DLQ
counts its own depth — and each of those went somewhere nobody was looking. The
alerter is the last metre: every five minutes it asks each probe what is wrong
and e-mails the owner about anything that **changed**.

```bash
make monitor-run       # one sweep now, and print what it found
```

### What is watched

| Alert key | Source | Fires when | Severity |
|---|---|---|---|
| `health:mysql` / `health:redis` / `health:rabbitmq` | `HealthChecker` | the component is `down` | critical |
| `health:search` / `health:worker` | `HealthChecker` | the component is `degraded` | warning |
| `health:disk` | `HealthChecker` | ≥ 80 % used → warning, ≥ 95 % → critical | both |
| `backup:missing` | `BACKUP_DIR` | no `homemanager-*.sql.gz` at all | critical |
| `backup:stale` | `BACKUP_DIR` | the newest dump is older than `BACKUP_MAX_AGE_HOURS` | critical |
| `backup:empty` | `BACKUP_DIR` | the newest dump is recent but under `BACKUP_MIN_BYTES` | critical |
| `queue:failed` | `failed` transport | depth ≥ `MONITORING_DLQ_THRESHOLD` | warning |
| `probe:*` | the monitor itself | a probe threw instead of answering | critical |

The health probe is asked **in process**, not over HTTP. An HTTP call would put
nginx, the firewall and the network between the alerter and the thing it is
reporting on, and would blame the application for their failures. `/api/health`
still exists and is the better target for an *external* uptime monitor, which is
the job HTTP is genuinely good at.

The backup thresholds are read from the same two variables `scripts/doctor.sh`
uses, and age is taken from the **date in the filename** for the same reason —
copying, restoring or syncing the backup directory stamps every file with "now",
so an mtime check calls a months-old archive perfectly fresh.

### When you get mail

Only on a change of state, never on the state itself:

| Transition | Subject | Meaning |
|---|---|---|
| firing | `[AIHM] CRITICAL — …` | first time this was seen |
| escalated | `[AIHM] ESCALATED to CRITICAL — …` | already announced, and it got worse |
| resolved | `[AIHM] RESOLVED — …` | it stopped, and how long it lasted |

A failure standing for a week costs one e-mail, not two thousand — otherwise the
channel becomes noise and the next real alert is the one that gets ignored. A
severity that **rises** is announced again, because a disk at 82 % and a disk at
96 % are different situations. A severity that falls is not: the thing is still
broken.

Nothing was delivered means nothing was announced. An alert no channel accepted
is left un-recorded and retried on the next sweep, so a mail outage delays an
alert rather than swallowing it.

### Two rules that look like exceptions

**Quiet hours do not apply.** Not by an opt-out flag — operational alerting does
not go through the Notifications module at all, so there is no quiet-hours rule
in the path to exempt. Quiet hours exist because a held *reminder* announces a
deadline that may already have passed; infrastructure is the opposite case,
where delay costs rather than saves.

**Nothing here touches MySQL, Redis or RabbitMQ.** The Notifications dispatch
engine reads a preference row and writes a notification row before sending,
which is right for user notifications and fatal here: it could not announce that
the database is down, because announcing it needs the database. Alerts go
straight through Symfony Mailer, and the "already announced" set is a JSON file
on local disk (`var/monitoring/alert-state.json`, or `MONITORING_STATE_FILE`).
The e-mail body is built in PHP rather than rendered from a Twig template, for
the same reason: fewest moving parts on the path that runs when everything else
is already broken.

### What to do about each alert

Every alert carries a first step in its body. The short version:

| Alert | First move |
|---|---|
| `health:mysql` | `make logs-mysql`; nothing writes until it is back |
| `health:redis` | rating averages, rate limiting and read caches are degraded; worker heartbeats stop being recorded |
| `health:rabbitmq` | `make logs-rabbitmq`; check the named volume **and** the fixed hostname |
| `health:search` | not user-visible — global search already fell back to FULLTEXT — but the index is going stale |
| `health:worker` | `docker compose ps`; while it stands, imports, reindexing, notifications and the nightly backup are all stopped |
| `health:disk` | above 95 % MySQL cannot flush or write binlogs; `BACKUP_DIR` is usually the largest thing to prune |
| `backup:*` | `make backup-now`, then `make doctor` for the fuller picture |
| `queue:failed` | `messenger:stats` for the depth, `messenger:failed:retry` to drain — see [Dead-letter queue](#dead-letter-queue) |
| `probe:*` | nothing that probe watches is being monitored while this stands; the body names the exception |

### Configuration

| Variable | Default | What it sets |
|---|---|---|
| `NOTIFICATIONS_MAIL_FROM` / `_TO` | — | sender and recipient, shared with notification e-mails |
| `BACKUP_MAX_AGE_HOURS` | 48 | how old the newest dump may be |
| `BACKUP_MIN_BYTES` | 1024 | below this a dump cannot be real |
| `MONITORING_DLQ_THRESHOLD` | 1 | dead-letter depth worth an e-mail |
| `MONITORING_STATE_FILE` | `var/monitoring/alert-state.json` | where the announced set is kept |

`MAILER_DSN` ships as `null://null`, which accepts everything and sends nothing.
**An instance without a real `MAILER_DSN` has monitoring and no alerting** —
that is the one setting whose absence this whole section cannot survive, so
`make doctor` says so out loud:

```
== Alerting ==
  !! MAILER_DSN not set in .env.local — falling back to null://null …
  !! NOTIFICATIONS_MAIL_TO is the placeholder (owner@localhost) …
```

Warnings rather than failures, because a laptop that does not e-mail itself is a
correctly configured laptop and a check that is red on every dev box stops being
read. On anything you actually depend on, both should be green.

In production the announced-alert state is kept on the `monitoring_state` named
volume, mounted into `scheduler_worker`. Without it the file would live in the
container's writable layer and every recreation — an image bump, an edit to the
compose file — would re-announce whatever was already failing.

### The blind spot, and how to cover it

The sweep runs **inline in `scheduler_worker`**, deliberately unrouted. Sending
it to the async transport would park the alert about a dead async worker in the
queue that worker was meant to drain, and would need RabbitMQ up in order to
report RabbitMQ down.

The cost is that the scheduler worker's own death is the one failure it cannot
report. Cover it from outside the stack — host cron, a systemd timer, anything
that is not the thing being watched:

```bash
*/5 * * * * cd /srv/aihm && docker compose exec -T php bin/console app:monitor:run
```

An external uptime monitor pointed at `GET /api/health` covers the same gap from
the other direction, and additionally covers the whole host being gone.

One more limit worth stating rather than hiding: a transport that cannot report
its depth — the in-memory one the test suite binds — makes `queue:failed`
silently inapplicable. `messenger:stats` is what shows whether the real transport
can be counted; against AMQP it reports a number, and names the transports it
could not count.

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
known, and the failures that actually happened were not on that list. The same
two thresholds drive the `backup:*` alerts in [Failure alerting](#failure-alerting),
under the same variable names — one answer to "is the backup fresh", not two:

| Check | Threshold | Verdict |
|---|---|---|
| Newest backup's size | ≥ 1024 B (`BACKUP_MIN_BYTES`) | `fail` — an empty dump (20 B is gzip's empty stream); restoring from it yields nothing |
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
