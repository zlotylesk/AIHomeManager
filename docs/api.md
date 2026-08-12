# API

## Versioning and documentation

The REST surface is served under the versioned base **`/api/v1`**, with the bare
**`/api`** prefix kept as a backward-compatible alias — both resolve to the same
controllers and return byte-identical responses. Versioning is by path prefix,
not by an `Accept` header. A breaking change ships as `/api/v2`; `/api/v1` is
never mutated in place.

The whole contract is generated as **OpenAPI 3.1** from the controllers'
attributes and published without a key:

| What | Where |
|---|---|
| Swagger UI (interactive) | `/api/doc` |
| Redoc | `/api/doc/redoc` |
| Raw spec (JSON) | `/api/doc.json` |

The contract is a CI gate rather than just documentation: a dedicated job dumps
the spec, lints it with Spectral, and PHPUnit validates real responses against
the schema documented for the status code they actually returned — so a
normalizer drifting from its documented shape fails the build. Locally:
`make openapi-dump` / `make openapi-lint`.

`make routes` lists everything.

## Authentication

`^/api/*` is protected by the `api` firewall (stateless, custom authenticator);
the pattern covers both `/api/v1/*` and the `/api/*` alias:

```
X-API-Key: <value from .env.local>
```

A missing or invalid key answers `401 {"error": "..."}`.

Public exceptions: `GET /api/health` (readiness probe — MySQL, Redis, RabbitMQ,
OpenSearch, the async workers, and two disk components: `disk_database` for the
filesystem holding the database data directory, 3-state up to `down`, and
`disk_backups` for the one holding the backups, which never goes past
`degraded` — see [operations](operations.md#what-the-disk-probe-measures)) and
the three API-doc routes above.

The frontend pages and the `/auth/*` OAuth endpoints are served by the separate
`main` firewall and require **HTTP Basic** (`FRONTEND_USER` /
`FRONTEND_PASSWORD_HASH`): every page renders `API_KEY` into a `<meta
name="api-key">` tag, so leaving them anonymous would hand out full `/api/*`
access to anyone who loads a page.

The two firewalls are disjoint. `/api/*` still authenticates with `X-API-Key`
alone and never needs the Basic credentials, and `/api/health` stays public. A
browser that authenticated once resends the credentials for the rest of the
realm, so the OAuth callbacks work unchanged.

There is deliberately **no CSRF token** on `^/api/*`: the firewall is stateless
and authorization travels in a custom header rather than a cookie, and a browser
does not set custom headers cross-origin. The OAuth init endpoints use the
`state` parameter.

## Rate limiting

`^/api/*` is throttled per IP at 60 requests/minute. A 429 carries
`Retry-After`, `X-RateLimit-Remaining` and `X-RateLimit-Limit`. `/api/health`
and `/auth/*` are exempt.

Outbound calls are throttled too, proactively rather than by reacting to a 429:
the Discogs, Last.fm, National Library, YouTube, Trakt and Spotify clients are
each wrapped in a rate-limited HTTP client that waits before the request rather
than after the rejection.

## Pagination

List endpoints answer with a `{data, pagination}` envelope:

```bash
curl -H "X-API-Key: $API_KEY" 'http://localhost:8080/api/v1/series?page=2&perPage=25'
```

`page` starts at 1, `perPage` defaults to 50 and caps at 100. Out-of-range
values are a 422 rather than a silent clamp, so a client asking for something
impossible finds out.

Some reads are deliberately unpaginated: those bounded by an enum (goal streaks,
notification preferences), those bounded by an explicit window (meal plan,
shopping list), and composed read models that are not collections at all
(dashboard, trends, the budget report).

## Correlation

Every response carries `X-Request-ID` — echoed from the request when it supplies
one and generated otherwise. The same id is attached to every log record, and it
**follows the message onto the worker**: a command offloaded to the queue during
a request logs under the id of the request that offloaded it, and a message a
worker handler chains keeps the same id rather than starting a fresh trail.

## Examples

```bash
# List
curl -H "X-API-Key: $API_KEY" http://localhost:8080/api/v1/series

# Create
curl -X POST http://localhost:8080/api/v1/series \
  -H "X-API-Key: $API_KEY" -H "Content-Type: application/json" \
  -d '{"title": "Severance"}'

# Rate an episode (1–10)
curl -X POST http://localhost:8080/api/v1/series/{seriesId}/seasons/{seasonId}/episodes/{episodeId}/rate \
  -H "X-API-Key: $API_KEY" -H "Content-Type: application/json" \
  -d '{"rating": 9}'

# Export — Books, Articles, Tasks, Budget, the shopping list
curl -H "X-API-Key: $API_KEY" "http://localhost:8080/api/v1/books/export?format=csv" -o books.csv
curl -H "X-API-Key: $API_KEY" "http://localhost:8080/api/v1/books/export?format=pdf" -o books.pdf
```

## Enabling the daily digest

Notification types are opt-in/out per type and per channel. Every type is **on
by default except the daily digest** (`daily_digest`), which ships off — with
every type enabled it would duplicate the individual reminders it summarises.

Either tick the **Podsumowanie dnia** row on `/notifications`, or:

```bash
curl -X PATCH http://localhost:8080/api/v1/notifications/preferences/daily_digest/enabled \
  -H "X-API-Key: $API_KEY" -H "Content-Type: application/json" \
  -d '{"enabled": true}'

curl -H "X-API-Key: $API_KEY" http://localhost:8080/api/v1/notifications/preferences
```

The digest is produced by the twice-daily scheduler sweep, so it starts arriving
on the next run after you enable it. Disabling is the same call with `false`.
