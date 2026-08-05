# Configuration reference

The application reads `app/.env` (committed, placeholders) and `app/.env.local`
(gitignored, the real secrets). `.env.local` wins. The README carries the short
table of what a fresh clone must set; this file is the full reference.

## Startup-critical variables

These four are the only ones that stop the application from booting. Everything
else degrades a single feature.

| Variable | Why it is required |
|---|---|
| `API_KEY` | The `^/api/*` firewall compares against it with `hash_equals`. |
| `FRONTEND_USER` / `FRONTEND_PASSWORD_HASH` | HTTP Basic on the `main` firewall. `app/.env` ships a placeholder pair so a clone boots; override both before the app is reachable from anywhere but localhost. |
| `DISCOGS_TOKEN_KEY`, `GOOGLE_TOKEN_KEY`, `TRAKT_TOKEN_KEY`, `SPOTIFY_TOKEN_KEY` | `TokenCipher` throws for any key that does not decode to exactly 32 bytes, and it is constructed at container build time — a wrong length is a boot failure, not a runtime one. |

Generate each encryption key **separately** — four different values. Separate
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
BACKUP_DIR=/backups
GRAYLOG_HOST=graylog
GRAYLOG_PORT=12201
NEW_RELIC_LICENSE_KEY=
NEW_RELIC_APP_NAME=AIHomeManager
```

`BACKUP_DIR` is the path *inside* the php container (mounted from `./backups`).
`make doctor` also honours `BACKUP_DIR` and `BACKUP_MAX_AGE_HOURS` on the host,
which is how the freshness check can be reproduced against a throwaway directory
— see [operations.md](operations.md).

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

Tokens are encrypted with libsodium secretbox and stored in MySQL. Last.fm and
the National Library need no flow at all.

A step-by-step walkthrough with screenshots (consent screen, common mistakes)
lives on Confluence: [First boot — configuring external
services](https://honemanager.atlassian.net/wiki/spaces/H/pages/50659329/First+boot+configuring+external+services).
