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

**Two untracked files have to exist**, and creating them is the whole of the
configuration work — no tracked file is edited to deploy.

| File | Read by | Holds |
|---|---|---|
| `.env.local` (repository root) | Compose, while building the stack | The database, Redis, broker and Graylog credentials |
| `app/.env.local` | The application, at runtime | The API key, the frontend account, the encryption keys, the OAuth secrets, the public address |

The root one is separate because Compose needs those values *before* a container
exists to read an application environment file — and because Compose reads only
`.env` from the project directory, which is tracked. `make prod-*` therefore
passes both: `--env-file .env --env-file .env.local`, in that order, with the
second winning.

**That layering has one failure mode, and it is silent.** A variable left out of
`.env.local` does not stop anything; it falls through to the development value in
the tracked `.env`, and the instance comes up entirely healthy on a password that
is published in the repository. Nothing about the running stack looks wrong.
`make doctor` is what catches it — its **Production secrets** section names every
variable still on its `.env` value — so run it on the host after this step and
after every rotation. `docs/configuration.md` lists the complete set with a
generation command for each.

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

Not configured yet, and worth knowing before this is exposed to anything: no
restart policies or resource limits, no log rotation.

## Network surface

Production publishes exactly two ports, both on nginx: 80, which answers the
ACME challenge and redirects everything else, and 443. **The database, Redis,
the broker and the search engine publish nothing at all** — the application
reaches them by service name over the Compose network, which publishing never
affected either way. What it decided was whether the host, and on a machine
with a public address everything that can route to it, could reach them too.

That mattered more than a missing password usually does, because for three of
the four there was no password to miss: the broker's management UI answered on
15672 to the built-in `guest` account, OpenSearch runs with its security plugin
off, and Redis had no `requirepass` at all. Only MySQL asked for anything, and
what it asked for was in a tracked file.

**Development still publishes all of them**, unchanged — 3306, 6379, 5672,
15672, 9200 — because that is what the MCP servers and every host-side client
talk to, and a development box is not what this protects. The two configurations
differ here on purpose, and `ProductionRuntimeConfigTest` pins both halves so
neither drifts into the other.

Graylog is the one exception in production: behind the `monitoring` profile, so
`make prod-up` does not start it, and bound to `127.0.0.1:9000` rather than
dropped entirely, because it is a UI whose whole point is being looked at.
Reach it over an SSH tunnel:

```bash
ssh -L 9000:127.0.0.1:9000 you@host    # then http://localhost:9000
```

### Diagnosing a service with no published port

Run the client inside the container. This is closer to the thing being
diagnosed than a host port ever was — it proves the path the application
actually uses — and it works on a host where the port was never published:

```bash
# MySQL — quoted so the container's shell expands it, not yours; the password
# is never typed and never lands in your shell history
docker compose -p aihm-prod exec mysql sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" -e "SHOW PROCESSLIST"'

# Redis — --no-auth-warning only silences the notice about -a on the command line
docker compose -p aihm-prod exec redis sh -c 'redis-cli --no-auth-warning -a "$REDIS_PASSWORD" info clients'

# Broker: queue depths, and the management UI's data without the management UI
docker compose -p aihm-prod exec rabbitmq rabbitmqctl list_queues name messages consumers
docker compose -p aihm-prod exec rabbitmq rabbitmqctl list_connections user peer_host state

# Search engine
docker compose -p aihm-prod exec search curl -s localhost:9200/_cluster/health
```

`make prod-shell` drops into the application container, from which the same
services are reachable by name — useful when the question is "can the app see
it", not "is it up".

The broker's management UI is simply not reachable in production, and the
`rabbitmqctl` calls above are the substitute rather than a workaround — they
report the same queue depths, connections and consumers the UI shows. If the UI
itself is genuinely needed, change `rabbitmq`'s `ports:` in
`docker-compose.prod.yml` to `- "127.0.0.1:15672:15672"`, run
`make prod-up`, tunnel to it as with Graylog above, and put the file back
afterwards. Do not reach for the default `guest` account while you are there:
it does not exist on an instance deployed from this configuration.

### Rotating the broker account

`RABBITMQ_USER` and `RABBITMQ_PASSWORD` are read by the image when it
**initialises an empty database**. On a fresh volume that is the whole story,
and the built-in `guest` account is never created. On a volume that already
exists, changing them achieves nothing except breaking every worker's
connection: the old accounts are still the only ones the broker knows.

Three commands, run against the running broker, then recreate the workers:

```bash
docker compose exec rabbitmq rabbitmqctl add_user "$NEW_USER" "$NEW_PASSWORD"
docker compose exec rabbitmq rabbitmqctl set_user_tags "$NEW_USER" administrator
docker compose exec rabbitmq rabbitmqctl set_permissions -p / "$NEW_USER" '.*' '.*' '.*'

# Only once the above succeeded, and only on an instance that still has it:
docker compose exec rabbitmq rabbitmqctl delete_user guest
docker compose exec rabbitmq rabbitmqctl list_users     # confirm what is left

docker compose up -d --force-recreate messenger_worker scheduler_worker
```

Until `guest` is deleted it stays an administrator, which is why
`docker/rabbitmq/20-aihm.conf` sets `loopback_users.guest = true`: the image
ships the opposite, baked in and not derived from `RABBITMQ_DEFAULT_USER`, so
naming a different default account does not confine the old one. That file makes
deleting the account a housekeeping step rather than an urgent one.

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

### What the disk probe measures

Two filesystems, reported as two components, because the two answers lead to
different actions:

| Component | Filesystem | Why it matters |
|---|---|---|
| `disk_database` | the one holding `DATABASE_DATA_DIR` (`/var/lib/mysql`, the `mysql_data` volume) | with no headroom MySQL cannot flush or write binlogs |
| `disk_backups` | the one holding `BACKUP_DIR` | the nightly dump needs somewhere to land; old dumps are what to prune |

```bash
curl -s localhost:8080/api/health | jq '.components | with_entries(select(.key | startswith("disk")))'
```

Neither is the PHP container's root filesystem, which is what this used to
measure and is nothing but that container's own image layer. On a single-machine
install all three sit on the same device, so the reading was accidentally right
— and stopped being right the moment the database or the backups were given a
volume or a partition of their own, which is a normal step in standing
production up. The `php` and `scheduler_worker` services therefore mount
`mysql_data` **read-only**: `statvfs` reports on the device holding a path, and
without a path into that volume there is nothing in the container that resolves
to it. `scheduler_worker` needs it as much as `php` does, because the monitoring
sweep asks the same checker in-process.

Thresholds are per location and identical — 80 % used is `degraded`, 95 % is
critical. What critical *means* is not identical: `disk_database` at 95 % is
`down` (HTTP 503), because MySQL is about to stop writing, while `disk_backups`
stays `degraded` however full it gets. The instance is serving every request it
is asked to; a 503 would take it out of rotation without freeing a byte. That
puts it with `search` and `worker` under the same rule, and what a full backup
filesystem actually costs is announced as **critical** by the `backup:*` probes
the moment a dump goes missing, short or stale — this component is the warning
that arrives before that happens.

A **failed measurement is `degraded`, not `down`**: a missing path is a mount
that has gone or a configuration mistake, and answering it with a 503 would take
an instance out of rotation while it was serving every request perfectly. It is
logged as a `warning` naming the path, so it cannot pass for a healthy reading
either.

Two properties of the measurement itself, worth knowing before reading a number
off it. `disk_free_space` reports what is free to the *application*, while
`disk_total_space` reports the whole device — ext4 keeps 5 % back for root, so a
filesystem an ordinary process has entirely filled reads as roughly 95 %, a few
points ahead of `df` in a root shell. That reserve is not space MySQL or the
backup job can use, so it is the right reading to threshold on. And `statvfs`
can block on a hung mount, with no timeout to pass it from PHP; both measured
paths are local, which is what keeps that theoretical — an off-host backup
destination is deliberately read by `BackupOffsiteProbe` on the monitoring
sweep, never on the request path.

**Upgrading an existing instance:** the single `disk` component became
`disk_database` and `disk_backups`. An external uptime monitor keyed on
`components.disk` needs updating — it will otherwise find nothing there and
report on a field that no longer exists. Nothing inside the project reads it,
and a standing `health:disk` alert resolves itself on the first sweep after the
upgrade, because the monitor announces a key that has stopped being reported as
RESOLVED and then forgets it.

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
| `health:disk_database` | `HealthChecker` | the filesystem holding `DATABASE_DATA_DIR` is ≥ 80 % used → warning, ≥ 95 % → critical; unmeasurable → warning | both |
| `health:disk_backups` | `HealthChecker` | the filesystem holding `BACKUP_DIR`, same thresholds, but **never critical** — a full backup disk does not stop the instance serving, and what it costs is caught by `backup:*` below | warning |
| `backup:missing` | `BACKUP_DIR` | no `homemanager-*.sql.gz.enc` at all | critical |
| `backup:stale` | `BACKUP_DIR` | the newest dump is older than `BACKUP_MAX_AGE_HOURS` | critical |
| `backup:empty` | `BACKUP_DIR` | the newest dump is recent but under `BACKUP_MIN_BYTES` | critical |
| `backup_offsite:unreachable` | the off-host destination | the mount or remote cannot be read at all | critical |
| `backup_offsite:missing` | the off-host destination | reachable, but holds no backup | critical |
| `backup_offsite:stale` | the off-host destination | the newest copy there is older than `BACKUP_MAX_AGE_HOURS` | critical |
| `backup_offsite:empty` | the off-host destination | the newest copy is recent but under `BACKUP_MIN_BYTES` | critical |
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
| `health:disk_database` | above 95 % MySQL cannot flush or write binlogs. A `degraded` can also mean the measurement failed — check that the `mysql_data` volume is still mounted into the container |
| `health:disk_backups` | old dumps are usually the largest thing to prune; retention runs with the nightly backup. Act on it before it becomes a `backup:*` alert, which is the same problem after a dump has already been lost. A `degraded` can also mean `BACKUP_DIR` is no longer mounted |
| `backup:*` | `make backup-now`, then `make doctor` for the fuller picture |
| `backup_offsite:*` | the local backup is probably fine — this says the copy stopped leaving the machine. `docker compose logs scheduler_worker \| grep -i off-host` for the reason the nightly job recorded, fix the destination, then `make backup-now`. The job deliberately does not retry |
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

The Scheduler runs `App\Application\Scheduled\BackupDatabase` daily at 03:00. It
dumps the database, encrypts the dump, applies local retention (30 daily plus 12
monthly — the 1st of each month is kept), then copies the artifact off the host.

```bash
make backup-now
make restore BACKUP=backups/homemanager-2026-06-01.sql.gz.enc
make restore-drill                 # prove the newest backup restores, without touching the live database
```

### The artifact is encrypted, and the key is not recoverable

The stored file is `homemanager-YYYY-MM-DD.sql.gz.enc`: gzip inside, libsodium
`secretstream` (XChaCha20-Poly1305) outside. Compression has to come first —
encrypted bytes do not compress.

`mysqldump` cannot encrypt, so a plaintext dump exists for as long as it takes to
read it back through the cipher. It is written under a dot-prefixed temporary
name that no glob in this system matches, created `0600` before a byte goes into
it, and removed in a `finally` whether the run succeeded or threw. A run that
fails leaves neither a partial artifact nor a plaintext fragment.

The stream is authenticated **per chunk**, and the last chunk carries a FINAL
tag that decryption insists on seeing. That is what makes a half-transferred file
an error instead of a partial success: without it, an upload cut off on a chunk
boundary decrypts perfectly up to the cut, and a truncated SQL dump restores a
database quietly missing everything after it.

> **`BACKUP_ENCRYPTION_KEY` is the one secret in this system that cannot be
> regenerated.** The four token keys can — re-authorise with the provider and
> carry on. This one is the only thing that turns the stored dumps back into a
> database, so losing it loses every backup at once, retroactively, including the
> copies that made it off the host. **Store it somewhere that is neither the
> backup directory nor any copy of it** — a key kept beside the ciphertext
> protects nothing. `make doctor` checks it is present and 32 bytes.

### The copy that leaves the machine

Backups that live only on the database's own disk fail together with it: one
medium dies, or the machine is lost, and both go at once. `BACKUP_REMOTE_BACKEND`
selects where the encrypted artifact goes:

| Backend | What it is | Needs |
|---|---|---|
| `none` | No off-host copy. Legitimate on a laptop, and **stated** — `make doctor` and `app:backup-database` both say so out loud | — |
| `directory` | A second mounted path: NAS export, external disk, NFS | `BACKUP_REMOTE_DIR` |
| `rclone` | Object storage — S3, B2, Drive, another box over SFTP | `BACKUP_REMOTE_TARGET`, and rclone's own config |

The value is read by a factory, not a container alias — an alias is resolved when
the container compiles and cannot read an environment variable. **An unrecognised
value is refused at boot** rather than falling back to `none`: a typo that
silently disabled off-host copies would leave an instance that looks configured
and copies nothing anywhere, which is the failure this whole arrangement exists
to prevent.

The `directory` backend **refuses a destination on the same filesystem as
`BACKUP_DIR`**, comparing device ids rather than paths — a subdirectory or a bind
mount of the same disk is not an off-host copy however "remote" the path is
called. The check runs at push time, not at boot, so a mount that has silently
dropped since startup is caught rather than quietly collapsing back onto the host
filesystem. If something else genuinely carries that directory off the machine —
Syncthing, a Dropbox client, a cron'd rsync — say so with
`BACKUP_REMOTE_ALLOW_SAME_FILESYSTEM=1`.

Off-host retention is its own window (`BACKUP_REMOTE_RETENTION_DAYS`, default 90)
and deliberately longer than the 30 local dailies: the copy that has to survive
losing the machine should not lose the same days at the same moment.

**A failed upload does not fail the backup.** The dump already succeeded; letting
the upload's failure propagate would send the message back through the retry
chain and re-dump the whole database three more times over a problem that had
nothing to do with it. It is logged at `error` — and, because a log entry is
precisely what nobody reads, `BackupOffsiteProbe` reads the destination on every
monitoring sweep, so a copy that stops arriving becomes mail to the owner within
one `BACKUP_MAX_AGE_HOURS` window. `make backup-now` exits non-zero on the same
failure, so a manual run is loud immediately.

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

### Restoring

Restore is the only thing about a backup that actually matters, and it is the one
thing size and freshness checks cannot tell you. Both of the following are
non-destructive rehearsals except where marked.

**Credentials come from configuration, never from the command line.** The dump's
key is `BACKUP_ENCRYPTION_KEY` in the application's environment; the database
password is read inside the mysql container from the variable Compose already put
there, and passed via `MYSQL_PWD` rather than `-p`, because an argument is
visible to every process on the box through `ps`.

#### Rehearse it: `make restore-drill`

```bash
make restore-drill                                    # newest backup, scratch schema, dropped afterwards
BACKUP=backups/homemanager-2026-08-12.sql.gz.enc make restore-drill
KEEP=1 make restore-drill                             # leave the scratch schema to look around
```

It restores into `homemanager_restore_drill`, compares row counts table by table
against the live schema, and drops the scratch database again. **The live schema
is never written to.** A table that gained rows since the 03:00 dump is reported
as a note; a table that comes back *empty* against a non-empty original is a
failure, because that is the shape the six days of empty dumps had.

Run it after any change to the backup pipeline, and periodically regardless — the
value of "our backups restore" decays from the day it was last established.

#### The real thing

**Destructive: this overwrites the live database.** Stop the workers first so
nothing writes underneath the load.

```bash
docker compose stop messenger_worker scheduler_worker

# 1. If the copy you need is off-host, fetch it first.
#    directory backend:
cp /mnt/nas/aihm-backups/homemanager-2026-08-12.sql.gz.enc backups/
#    rclone backend:
docker compose exec php rclone copy "$BACKUP_REMOTE_TARGET/homemanager-2026-08-12.sql.gz.enc" /backups/

# 2. Restore. Decrypts in the php container, loads in the mysql container,
#    nothing plaintext on disk in between.
make restore BACKUP=backups/homemanager-2026-08-12.sql.gz.enc

# 3. Bring everything back and check.
docker compose start messenger_worker scheduler_worker
docker compose exec php bin/console doctrine:migrations:migrate --no-interaction
curl -s localhost:8080/api/health | jq
make search-reindex        # the index is rebuilt, never restored
```

Step 3's migration matters when the dump predates the current code: the schema in
the archive is the schema of the day it was taken.

If the restore fails, the message says which of the three things went wrong, and
they need different responses:

| Message | Meaning |
|---|---|
| `not an encrypted AIHM backup` | A pre-encryption `.sql.gz`. Restore it the old way: `gunzip -c … \| docker compose exec -T mysql …` |
| `wrong BACKUP_ENCRYPTION_KEY, or the file was altered in transit` | Failed on the *first* chunk — the key does not match this file |
| `damaged or truncated at chunk N — the key is right` | The key decrypted earlier chunks. The file is damaged; re-fetch it from the off-host copy |
| `ends without a final chunk` | Truncated on a chunk boundary — an upload that died partway. Re-fetch it |

#### Measured

An actual restore, run on the development stack against the real library — not an
estimate. Times are wall-clock on a laptop, MySQL 8.4 in Docker.

| Step | Time |
|---|---|
| `app:backup-database` — dump, gzip, encrypt, retention | **5.2 s** |
| Restore into an empty schema — decrypt, gunzip, load | **5.5 s** |
| `/api/health` afterwards | 200, every component `up` |

The encrypted artifact was **354 333 bytes** for a database of 33 tables, the
largest being 3 274 listening sessions and 989 search documents.

**Every one of the 33 tables came back with an identical row count** — compared
table by table between the live schema and the restored one, not sampled.

Two things this does not tell you. The times scale with the database, and this one
is small; a library ten times the size is not ten times slower, but it is not 5 s
either. And the numbers are for the mechanism only — on a real recovery the
elapsed time is dominated by fetching the backup from off-host storage and by
whatever it took to notice, neither of which anything here measures.

Re-establish these with `make restore-drill` rather than trusting the table: it
is a record of one run on one day, and the value of "our backups restore" decays
from the moment it was last checked.

#### Backups taken before encryption

> **Run `make backup-now` as the last step of deploying this change.** Until one
> encrypted artifact exists, `BackupFreshnessProbe` sees an empty directory and
> raises `backup:missing` as critical — correctly, by its own rules, but about a
> machine whose backups are fine. Left to the 03:00 schedule, that alert stands
> for up to a day.

Artifacts from before this change are plain `homemanager-YYYY-MM-DD.sql.gz` and
are **no longer counted** by `make doctor`, `BackupFreshnessProbe` or
`make restore-drill` — every glob now matches the encrypted artifact only, on
purpose: an unencrypted dump is not something this system will hand to a restore.
They remain perfectly restorable by hand with `gunzip`. Keep them until the
encrypted set covers the window you care about, then delete them; they are
unencrypted copies of the whole database sitting in the directory whose contents
now get copied off the machine.
