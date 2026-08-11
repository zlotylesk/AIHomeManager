# Progressive Web App

The web frontend is an installable PWA: a Web App Manifest with maskable icons
(Add-to-Home-Screen), a Workbox Service Worker with offline reads, an offline
write queue, and Web Push. It is built inside the existing Encore pipeline —
there is no second bundler — and the push backend is the Notifications module's,
so the PWA layer adds no PHP of its own.

## Service Worker

- **Source** `app/assets/pwa/sw.js`, built by Workbox `InjectManifest` and
  emitted to the site root as `public/sw.js` (a gitignored build artifact). Root
  is deliberate: the scope is then the whole origin, and a hashed `/build/` path
  would narrow it and break registration.
- **Production builds only.** `make assets-prod` produces `public/sw.js`; a dev
  build does not, and registration fails soft — nothing breaks under `make
  assets`.
- **Registered** from `app.js` on every page.

Caches:

| Cache | Contents | Strategy |
|---|---|---|
| `workbox-precache-*` | Content-hashed app shell + `offline.html` | Precache, self-invalidating via revisions |
| `aihm-runtime-api-reads-v1` | `GET /api/*` | NetworkFirst, 200-only, 60 entries / 24 h |
| `aihm-runtime-pages-v1` | Navigations | NetworkFirst, 30 entries / 7 d; `/auth/*` and `/api/*` denylisted |
| `api-writes` | Offline `POST`/`PATCH`/`DELETE` | Background Sync queue (IndexedDB), replayed on reconnect |

## The offline write queue

An offline write is enqueued and the route handler returns a synthetic `202
{queued: true}`, so the page can say "saved when back online" rather than
treating it as lost. Both `apiCall` implementations detect that marker, fire a
`pwa:queued` event and throw a typed non-success error — no caller renders a
queued write as saved.

**The capability decision lives in the Service Worker, not the page.** A browser
without the Background Sync API has no reliable replay trigger, so it gets plain
`NetworkOnly` with no queue at all and a `503 {requiresNetwork: true}`. Being
told the action needs a connection is honest; being promised a replay that can
never fire is not.

The online path is byte-identical to no Service Worker at all — `NetworkOnly` is
a plain fetch and HTTP error statuses pass through unthrown — so no write path
regresses when the worker is installed.

## Updates and cache versioning

- `skipWaiting()` + `clientsClaim()` make a newly installed worker take over
  immediately, so a shipped app-shell update is never stranded behind the old
  one.
- nginx serves `/sw.js` with `Cache-Control: no-cache`, so the browser
  revalidates the worker script on every navigation and a new deploy is adopted
  at once (`docker/nginx/default.conf`).
- The **runtime** read caches persist by design, so they carry an explicit
  lever: bump `CACHE_VERSION` in `sw.js` after a change that would make a stale
  cached read render wrong — an `/api` response-shape change, for instance. On
  the next activation a sweep deletes every runtime bucket that is not current.
  The `api-writes` queue is never swept; dropping it would lose a user's offline
  writes.

## Scope, headers and CSP

Scope is `/`. nginx also sends `Service-Worker-Allowed: /` on `/sw.js` to pin
the intent explicitly — redundant for a root-served worker, but it states what
is meant.

A location-level `add_header` in nginx **replaces** the inherited server-level
ones, so the `/sw.js` location re-declares the security headers by including
`snippets/security-headers.conf` — the same file every other block includes,
which is what keeps the copies from drifting.
`ProductionRuntimeConfigTest` fails the build for any location that sets a
header of its own without re-declaring the set.

A Service Worker only registers in a secure context. Development is exempt
because browsers treat `localhost` as one; a production instance is not, which
is the PWA's share of the reason production terminates TLS.

There is no `Content-Security-Policy` header in this project, so the same-origin
Service Worker needs no CSP allowance.

## Emergency kill-switch

If a bad worker ever traps clients on stale content, deploy a
self-unregistering `public/sw.js` overriding the Workbox build. The `no-cache`
header guarantees every client fetches it on the next navigation:

```js
// public/sw.js — emergency kill-switch. Unregisters itself and drops all caches.
self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', (event) => {
  event.waitUntil((async () => {
    await self.registration.unregister();
    await Promise.all((await caches.keys()).map((k) => caches.delete(k)));
    const clients = await self.clients.matchAll({ type: 'window' });
    clients.forEach((c) => c.navigate(c.url)); // reload each tab, now uncontrolled
  })());
});
```

Once every client has updated, revert to the normal Workbox build.

## Testing

`tests-e2e/pwa.mobile.spec.ts` runs against a **production** build — the worker
only exists there — and opts back into the worker with `test.use({
serviceWorkers: 'allow' })`, because the default Playwright context blocks
service workers (a controlling worker fetches from its own context and would
bypass every `page.route()` stub in the app specs).

It covers manifest installability, the worker registering → activating →
**controlling** the page, offline serving a cached view and the precached
offline page for one never visited, and the offline write returning the
synthetic 202.

One load-order detail the spec encodes: it waits for a non-null
`navigator.serviceWorker.controller`, **not** merely `registration.active` —
`active` flips true while the worker is still activating and precaching, so a
reload in that window races `clients.claim()` and lands an uncontrolled
navigation the worker never intercepts.

CI gate: a Lighthouse `installable-manifest` audit in the `e2e-playwright` job.
`@lhci/cli@0.13.0` is pinned because Lighthouse 12 removed the PWA category
entirely; the config is `lighthouserc.json`. A broken manifest, lost icons or a
dead worker drops the score below 1 and blocks the merge.
