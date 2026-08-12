# Configuration reference

The application reads `app/.env` (committed, placeholders) and `app/.env.local`
(gitignored, the real secrets). `.env.local` wins. The README carries the short
table of what a fresh clone must set; this file is the full reference.

## Startup-critical variables

These four are the only ones that stop the application from booting. Everything
else degrades a single feature.

| Variable | Why it is required |
|---|---|
| `API_KEY` | The `^/api/*` firewall compares against it with `hash_equals`. `API_KEY_PREVIOUS` is the optional rotation partner — empty by default, not startup-critical — see [Operations → API key rotation](operations.md#api-key-rotation). |
| `FRONTEND_USER` / `FRONTEND_PASSWORD_HASH` | HTTP Basic on the `main` firewall. `app/.env` ships a placeholder pair so a clone boots; override both before the app is reachable from anywhere but localhost. |
| `DISCOGS_TOKEN_KEY`, `GOOGLE_TOKEN_KEY`, `TRAKT_TOKEN_KEY`, `SPOTIFY_TOKEN_KEY` | `TokenCipher` throws for any key that does not decode to exactly 32 bytes, and it is constructed at container build time — a wrong length is a boot failure, not a runtime one. |
| `BACKUP_ENCRYPTION_KEY` | `BackupCipher` throws for anything but 32 bytes. Unset, it fails the moment a backup runs or is restored. **The one secret here that cannot be regenerated** — see [Backups](#backups-and-monitoring). |

Generate each encryption key **separately** — five different values. Separate
keys mean a compromised provider costs one provider:

```bash
openssl rand -base64 32     # 32 random bytes, which is exactly a secretbox key
```

Or, once the stack is up, from inside the container:

```bash
docker compose exec php php -r "echo base64_encode(sodium_crypto_secretbox_keygen()), PHP_EOL;"
```

`make doctor` decodes all four and reports the length, so a truncated paste is
caught before the next `docker compose up`.

## Infrastructure credentials

These live in the **repository-root** `.env`, not `app/.env`, because Compose
reads them while it is building the stack — before any container exists to read
an application environment file. That is also why they are the one group of
secrets `app/.env.local` cannot carry.

| Variable | Used by |
|---|---|
| `MYSQL_ROOT_PASSWORD` | The server's root account, and what `make restore` authenticates with. |
| `MYSQL_PASSWORD` | The application's own account, interpolated into `DATABASE_URL`. (`MYSQL_USER` and `MYSQL_DATABASE` beside it are names, not secrets.) |
| `REDIS_PASSWORD` | Starts Redis with `--requirepass`, and is interpolated into `REDIS_URL` and `LOCK_DSN` for the application and both workers. |
| `RABBITMQ_USER` / `RABBITMQ_PASSWORD` | The broker's account, and the credentials in `MESSENGER_TRANSPORT_DSN`. |
| `GRAYLOG_PASSWORD_SECRET` | The pepper Graylog derives its session and user secrets from. Minimum 16 characters, or it refuses to start. |
| `GRAYLOG_ROOT_PASSWORD_SHA2` | SHA-256 of the Graylog administrator password. The tracked value is the hash of `admin` — the image's own default. |

The tracked values are development values, deliberately: a fresh clone has to
come up with `make up` and nothing else. The file says so at the top, in those
words, because it is also the only file Compose reads and therefore the one a
deployment is tempted to edit. **Do not.** A secret committed here is a secret in
the repository history, which is not somewhere a value comes back out of with one
command.

**Production overrides them instead**, from a gitignored `.env.local` beside it:
every `make prod-*` target passes `--env-file .env --env-file .env.local`, in
that order, and the second one wins. Nothing tracked is edited to deploy.

```bash
# .env.local, on the production host only — never committed
MYSQL_ROOT_PASSWORD=$(openssl rand -base64 24)
MYSQL_PASSWORD=$(openssl rand -base64 24)
REDIS_PASSWORD=$(openssl rand -base64 24)
RABBITMQ_USER=aihm
RABBITMQ_PASSWORD=$(openssl rand -base64 24)
GRAYLOG_PASSWORD_SECRET=$(openssl rand -base64 24)
GRAYLOG_ROOT_PASSWORD_SHA2=$(printf '%s' 'your-admin-password' | sha256sum | cut -d' ' -f1)
```

`REDIS_PASSWORD`, `RABBITMQ_USER`, `RABBITMQ_PASSWORD` and both Graylog values
are `${VAR:?}`-guarded in the compose file — required rather than defaulted, so
Compose refuses to start rather than substituting an empty string, which for
`--requirepass` would mean a Redis with no password at all while every file said
otherwise. Graylog's are guarded even though it sits behind the `monitoring`
profile: Compose interpolates every service in the file regardless of which
profile is active.

**A variable left out of `.env.local` does not fail — it falls through.** The
layering has no way to distinguish "deliberately inherited" from "forgotten", so
a missing production password silently becomes the development one and the stack
comes up green on a credential printed in a public repository. That is the one
failure mode of this design, and `make doctor` is what closes it: its
**Production secrets** section reads both files and names every variable still
carrying its `.env` value, whether unset or copied verbatim. It also fails if
`.env.local` has itself been committed.

Run it on the production host after the first deployment and after every rotation:

```bash
make doctor        # == Production secrets ==
```

**Severity follows whether the host actually deploys.** A host running the
`aihm-prod` Compose project gets one failure per variable; anywhere else the
same findings collapse to a single warning, because on a workstation they are
both true and entirely expected — and a check that is red on every development
machine is one its owner learns to skip.

**Changing `RABBITMQ_USER` or `RABBITMQ_PASSWORD` has no effect on a broker
whose volume already exists.** RabbitMQ creates the account named by
`default_user` only when it initialises an empty database, so on an existing
instance the new credentials are simply wrong and every worker fails to connect.
[Operations](operations.md#rotating-the-broker-account) has the three commands.

**`REDIS_PASSWORD` is safe to change at any time**, at the cost of the cache
contents: the caches are all derived data and refill on the next read, the locks
are held for seconds, and the worker heartbeats are rewritten within a minute of
a restart. Redis must be recreated (not merely restarted) for a new password to
take effect, since it is passed on the server's command line.

## What a production instance must set

Two files, because two different things read them at two different times, and
**neither is tracked**. Bringing up production means creating these two and
editing nothing else.

| File | Read by | Holds |
|---|---|---|
| `.env.local` (repository root) | Compose, while building the stack | The infrastructure credentials above — there is no container yet to read an application file |
| `app/.env.local` | The application, at runtime | Everything else: the API key, the frontend account, the five encryption keys, the OAuth secrets, the public address |

The complete set, with how each value is produced:

| Variable | File | How to generate |
|---|---|---|
| `MYSQL_ROOT_PASSWORD`, `MYSQL_PASSWORD` | `.env.local` | `openssl rand -base64 24` |
| `REDIS_PASSWORD`, `RABBITMQ_PASSWORD` | `.env.local` | `openssl rand -base64 24` |
| `RABBITMQ_USER` | `.env.local` | Any name; it is not a secret |
| `GRAYLOG_PASSWORD_SECRET` | `.env.local` | `openssl rand -base64 24` (≥ 16 characters) |
| `GRAYLOG_ROOT_PASSWORD_SHA2` | `.env.local` | `printf '%s' 'the password' \| sha256sum \| cut -d' ' -f1` |
| `API_KEY` | `app/.env.local` | `openssl rand -hex 32` |
| `API_KEY_PREVIOUS` | `app/.env.local` | Not generated — set during a rotation to the value `API_KEY` just moved out of, empty otherwise. See [Operations → API key rotation](operations.md#api-key-rotation) |
| `FRONTEND_USER` | `app/.env.local` | Any name |
| `FRONTEND_PASSWORD_HASH` | `app/.env.local` | `bin/console security:hash-password` |
| `APP_SECRET` | `app/.env.local` | `openssl rand -hex 16` |
| `DISCOGS_TOKEN_KEY`, `GOOGLE_TOKEN_KEY`, `TRAKT_TOKEN_KEY`, `SPOTIFY_TOKEN_KEY` | `app/.env.local` | `openssl rand -base64 32` — **four different values**, see [above](#startup-critical-variables) |
| `BACKUP_ENCRYPTION_KEY` | `app/.env.local` | `openssl rand -base64 32`, stored somewhere that is **not** the backup directory — it cannot be regenerated |
| `DEFAULT_URI` and the four callback URIs | `app/.env.local` | The instance's public HTTPS address, see [below](#public-address-and-oauth-callbacks) |
| `MAILER_DSN`, `NOTIFICATIONS_MAIL_TO` | `app/.env.local` | A real transport and a real mailbox — the committed defaults accept every alert and deliver none |
| The per-integration credentials | `app/.env.local` | From each provider's console, [below](#per-integration-variables) |

`make doctor` checks both files: lengths and placeholders in `app/.env.local`,
and development values still in effect for the root one.

### Secrets in the repository history

Audited across the full history of every tracked environment file. **Nothing
needs revoking:** the root `.env` has only ever held `secret`, `homemanager` and
the literal placeholder `ghp_...`, every secret-bearing key in `app/.env` has
always been committed empty, and no `.env.local` — at either level — has ever
been committed. The only non-empty credential in the history is the placeholder
bcrypt hash in `app/.env`, which is documented as a placeholder and is why
`make doctor` warns when `FRONTEND_PASSWORD_HASH` is not overridden.

If that ever stops being true, the order is: rotate the value at its source
first, then deploy, and only then consider the history. Rewriting history
invalidates every clone and does not reach forks or anything that already
mirrored the repository — a committed secret is compromised from the moment it
is pushed, and rotation is the only step that actually ends that.

## Public address and OAuth callbacks

Six variables carry the instance's own address. `app/.env` holds the
**development** values — `http://localhost:8080`, which is what the dev stack
actually serves — and a production instance overrides all six in
`app/.env.local` with its public HTTPS address.

| Variable | Development | Production |
|---|---|---|
| `DEFAULT_URI` | `http://localhost` | `https://aihm.example.com` |
| `GOOGLE_REDIRECT_URI` | `http://localhost:8080/auth/google/callback` | `https://aihm.example.com/auth/google/callback` |
| `DISCOGS_CALLBACK_URL` | `http://localhost:8080/auth/discogs/callback` | `https://aihm.example.com/auth/discogs/callback` |
| `TRAKT_REDIRECT_URI` | `http://localhost:8080/auth/trakt/callback` | `https://aihm.example.com/auth/trakt/callback` |
| `SPOTIFY_REDIRECT_URI` | `http://localhost:8080/auth/spotify/callback` | `https://aihm.example.com/auth/spotify/callback` |

**Each callback must also be registered, character for character, in the
provider's own console** — Google Cloud, Discogs, Trakt, Spotify. The providers
compare the string, not the destination: a trailing slash, `http` instead of
`https` or a `www.` prefix on one side only is a rejected authorization rather
than a redirect that happens to work.

This is the functional half of HTTPS, and the reason it is not merely a
hardening task. **Google, Trakt and Spotify refuse an `http://` redirect URI
outside the loopback interface**, so on a public domain Calendar sync, the Trakt
imports and podcast listening do not degrade without TLS — they cannot be
authorized at all. Discogs is the exception that proves nothing: it accepts
plain HTTP, and sends an OAuth1 token over it.

`DEFAULT_URI` is what the CLI generates absolute URLs from — notification
e-mails and Web Push payloads have no incoming request to infer a host from.
Left at `http://localhost`, a production instance sends itself perfectly valid
links to nowhere. Requests handled over HTTP need no such setting: nginx passes
the scheme it terminated and `framework.yaml` trusts it, so generated URLs come
out as `https` on their own.

## Per-integration variables

Each block is optional. Leaving one empty disables its module's external calls;
the dependent endpoints answer 503/400/409 rather than 500.

### Google — Calendar (Tasks) + YouTube Data API (YouTubeProgress)

```dotenv
GOOGLE_CLIENT_ID=
GOOGLE_CLIENT_SECRET=
GOOGLE_REDIRECT_URI=http://localhost:8080/auth/google/callback
YOUTUBE_WATCHLIST_PLAYLIST_ID=
```

Register at [console.cloud.google.com](https://console.cloud.google.com): new
project → enable **Google Calendar API** and **YouTube Data API v3** → consent
screen (type *External*, add your own account as a *test user*) → *Credentials*
→ OAuth client ID, type **Web application**, with the redirect URI above.

One token carries both scopes (`calendar.events` and `youtube`), so a single
authorization serves Tasks and YouTubeProgress. `YOUTUBE_WATCHLIST_PLAYLIST_ID`
is the part of the playlist URL after `list=`; empty means `POST
/api/youtube-progress/sync` answers 400 instead of syncing.

### Discogs — vinyl collection (Music)

```dotenv
DISCOGS_CONSUMER_KEY=
DISCOGS_CONSUMER_SECRET=
DISCOGS_USERNAME=
DISCOGS_CALLBACK_URL=http://localhost:8080/auth/discogs/callback
```

[discogs.com/settings/developers](https://www.discogs.com/settings/developers) →
*Create an Application*. OAuth1, so the token and its secret are encrypted
field by field rather than as one JSON blob.

### Last.fm — listening history (Music)

```dotenv
LASTFM_API_KEY=
LASTFM_USERNAME=
```

[last.fm/api/account/create](https://www.last.fm/api/account/create). Read-only
API key, no OAuth flow — it works as soon as the key is set.

### Trakt.tv — watched imports (Series, Movies)

```dotenv
TRAKT_CLIENT_ID=
TRAKT_CLIENT_SECRET=
TRAKT_REDIRECT_URI=http://localhost:8080/auth/trakt/callback
```

[trakt.tv/oauth/applications](https://trakt.tv/oauth/applications) → *New
Application*. One token covers both modules' imports.

### Spotify — podcast listening (Podcasts)

```dotenv
SPOTIFY_CLIENT_ID=
SPOTIFY_CLIENT_SECRET=
SPOTIFY_REDIRECT_URI=http://localhost:8080/auth/spotify/callback
```

[developer.spotify.com/dashboard](https://developer.spotify.com/dashboard) →
*Create app*. The flow requests `user-library-read`,
`user-read-playback-position` and `user-read-currently-playing`. **Without
`user-read-playback-position` Spotify omits resume points entirely** — the
integration then connects successfully and reports nothing, which is the one
failure here that looks like an empty library rather than an error.

### National Library — book metadata (Books)

No registration and no variables. The public `data.bn.org.pl` API is called
through a client throttled to 60 requests/minute.

### Notifications — e-mail and Web Push

```dotenv
MAILER_DSN=null://null
NOTIFICATIONS_MAIL_FROM=
NOTIFICATIONS_MAIL_TO=
VAPID_PUBLIC_KEY=
VAPID_PRIVATE_KEY=
VAPID_SUBJECT=mailto:you@example.com
```

`null://null` disables delivery without breaking anything — the notification is
still recorded, it just goes nowhere. Single user, so one sender and one inbox.

VAPID identifies this server directly to the push service; there is no FCM and
no third party. Generate the pair once:

```bash
docker compose exec php php -r "require 'vendor/autoload.php'; var_dump(Minishlink\WebPush\VAPID::createVapidKeys());"
```

The private key never leaves the server. The public one is handed to the browser
when it subscribes, so it is not a secret.

## Search engine

```dotenv
SEARCH_ENGINE_DSN=http://search:9200
SEARCH_ENGINE_BACKEND=opensearch
SEARCH_INDEX_ALIAS=search_documents
```

`SEARCH_ENGINE_BACKEND` selects between `opensearch` and `fulltext`. The value
is read by a factory rather than a container alias, because an alias is resolved
at compile time and cannot read an environment variable — so switching backends
is a restart, not a deploy. An unrecognised value is rejected at boot instead of
falling back silently.

Three defaults deliberately disagree, and the disagreement is the point:
`app/.env` ships `opensearch` (what the project runs), the container parameter
`app.search_backend.default` stays `fulltext` (so an environment that never
heard of the flag still boots without an extra service), and `app/.env.test`
pins `fulltext` (most search tests seed `search_documents` with SQL, and the CI
E2E jobs run no engine at all).

See [search.md](search.md) for provisioning, cutover and recovery.

## Budget

```dotenv
BUDGET_CURRENCY=PLN
```

The single currency the ledger is kept in. An amount in any other currency is
rejected with 422 rather than converted — there are no exchange rates, no
per-category currency, and no honest answer to what a mixed month's balance
would be denominated in. Unset falls back to the container parameter
`app.budget.default_currency`, so an environment that never heard of this
variable still boots.

## Backups and monitoring

```dotenv
DATABASE_DATA_DIR=/var/lib/mysql
BACKUP_DIR=/backups
BACKUP_ENCRYPTION_KEY=
BACKUP_REMOTE_BACKEND=none
BACKUP_REMOTE_DIR=
BACKUP_REMOTE_TARGET=
BACKUP_REMOTE_RETENTION_DAYS=90
BACKUP_REMOTE_ALLOW_SAME_FILESYSTEM=0
GRAYLOG_HOST=graylog
GRAYLOG_PORT=12201
NEW_RELIC_LICENSE_KEY=
NEW_RELIC_APP_NAME=AIHomeManager
```

`BACKUP_DIR` is the path *inside* the php container (mounted from `./backups`).
`make doctor` also honours `BACKUP_DIR` and `BACKUP_MAX_AGE_HOURS` on the host,
which is how the freshness check can be reproduced against a throwaway directory
— see [operations.md](operations.md).

| Variable | Default | What it does |
|---|---|---|
| `DATABASE_DATA_DIR` | `/var/lib/mysql` | Where the database's data directory is visible *inside* the container, for the `disk_database` health component. The data lives in the `mysql_data` volume, which `php` and `scheduler_worker` mount read-only at this path — change one and change the other, or the probe reports a measurement it cannot take. A path that is not there is `degraded` with a logged warning, never `down`. See [what the disk probe measures](operations.md#what-the-disk-probe-measures) |
| `BACKUP_ENCRYPTION_KEY` | — | 32 bytes, base64. Encrypts the dump. **Not recoverable**: lose it and every stored backup becomes unreadable, including the off-host copies. Store it outside the backup directory and outside any copy of it. Generate with `docker compose exec php php -r "echo base64_encode(sodium_crypto_secretstream_xchacha20poly1305_keygen()), PHP_EOL;"` |
| `BACKUP_REMOTE_BACKEND` | `none` | `none`, `directory` or `rclone`. An unrecognised value is **refused at boot** rather than silently disabling off-host copies |
| `BACKUP_REMOTE_DIR` | — | `directory` backend: the mounted off-host path, inside the container. Refused if it shares a filesystem with `BACKUP_DIR` |
| `BACKUP_REMOTE_TARGET` | — | `rclone` backend: a `remote:path`. Credentials live in `rclone.conf`, never here |
| `BACKUP_REMOTE_RETENTION_DAYS` | `90` | The off-host window. Longer than the 30 local dailies on purpose. A value below 1 prunes nothing rather than everything |
| `BACKUP_REMOTE_ALLOW_SAME_FILESYSTEM` | `0` | Set to `1` only when something else (Syncthing, a Dropbox client, a cron'd rsync) carries `BACKUP_REMOTE_DIR` off the machine |

The last five all have container-parameter defaults, so an instance that has
never heard of them still boots — the same arrangement `SEARCH_ENGINE_BACKEND`
uses. `BACKUP_ENCRYPTION_KEY` deliberately has none.

A Graylog that is not running does not break anything: the `series` and `auth`
log channels are dropped and the request continues. Same for New Relic when the
extension is absent.

## First OAuth connection

With the stack up and `.env.local` filled, visit each provider once in a
browser. The frontend is behind HTTP Basic, so the browser will ask for
`FRONTEND_USER` / the password behind `FRONTEND_PASSWORD_HASH` first and then
reuse it for the callbacks.

| URL | Provider |
|---|---|
| `http://localhost:8080/auth/google` | Google — forces the consent screen for both scopes |
| `http://localhost:8080/auth/discogs` | Discogs (OAuth1) |
| `http://localhost:8080/auth/trakt` | Trakt.tv |
| `http://localhost:8080/auth/spotify` | Spotify |

On a production instance the same four paths sit under the public HTTPS address,
and each provider must already have that callback registered — see [Public
address and OAuth callbacks](#public-address-and-oauth-callbacks). Run all four
after the certificate is issued: they are the end-to-end proof that TLS, the
redirect and the callback registration agree.

Tokens are encrypted with libsodium secretbox and stored in MySQL. Last.fm and
the National Library need no flow at all.

A step-by-step walkthrough with screenshots (consent screen, common mistakes)
lives on Confluence: [First boot — configuring external
services](https://honemanager.atlassian.net/wiki/spaces/H/pages/50659329/First+boot+configuring+external+services).
