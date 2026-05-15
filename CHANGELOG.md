# Changelog

Wszystkie znaczące zmiany w projekcie AIHomeManager dokumentowane w tym pliku.

Format oparty na [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), wersjonowanie wg [SemVer](https://semver.org/lang/pl/).

## [1.3.0] — 2026-05-16

Pierwszy release z formalnym CHANGELOG. Zamyka dwa tematyczne epiki follow-upów z code review HMAI-44: **HMAI-127** (resilience zewnętrznych klientów API) i **HMAI-130** (rate limiting). Dodaje warstwę throttlingu dla `^/api/*` i wszystkich klientów Discogs/Last.fm/Biblioteki Narodowej, hartuje OAuth1/OAuth2 flow i wprowadza pierwszą iterację statycznej analizy w CI.

### Added

- **Rate limiting per-IP dla `^/api/*`** + dekoratory `RateLimitedHttpClient` dla Discogs/Last.fm/Biblioteki Narodowej (sliding/token bucket, Redis-backed). [HMAI-38]
- **`DiscogsClockDriftDetector`** — po każdej odpowiedzi z Discogsa porównuje `Date` header z `time()` i loguje warning gdy drift > 300s (próg konfigurowalny przez `services.yaml`). [HMAI-114]
- **`DiscogsCredentials`** VO (`final readonly`) z `__debugInfo()` redagującym secret i `#[\SensitiveParameter]` — `debug:container --show-arguments` i stack trace przy konstrukcji nie wyciekają już consumer secretu. [HMAI-113]
- **Typowana hierarchia wyjątków Discogs**: `DiscogsAuthException` (401/403), `DiscogsNotFoundException` (404), `DiscogsRateLimitException` (429), `DiscogsUnavailableException` (5xx + transport). [HMAI-63]
- **OAuth refresh error handling (Google)** — detekcja `['error' => 'invalid_grant']` w `fetchAccessTokenWithRefreshToken()` zapobiega zapisaniu skorumpowanego "tokenu". [HMAI-64]
- **Walidacja `oauth_token`/`oauth_token_secret` non-empty** w `DiscogsApiClient` przed wywołaniem signera. [HMAI-64]
- **Migracja `Version20260511000001`** — nullable kolumna `expires_at DATETIME` w `discogs_oauth_tokens` (placeholder dla przyszłego proaktywnego re-auth). [HMAI-64]
- **Walidacja konstruktora `GoogleClientFactory`** — pusty/whitespace-only `clientId`/`clientSecret`/`redirectUri` → `InvalidArgumentException` boot-time. [HMAI-90]
- **Ochrona przed XXE** w `NationalLibraryApiClient` — `LIBXML_NONET` + jawne odrzucenie odpowiedzi z `<!DOCTYPE`. [HMAI-96]
- **Code review hub w Confluence** — strona "External API resilience patterns" (id 59441164) z reusable patterns: typed exceptions, debug-safe VO, drift detector, OAuth refresh. [HMAI-127]
- **Static analysis w CI** — PHPUnit + MySQL/Redis service containers, rector dry-run, PHP-CS-Fixer + PHPStan workflow.

### Changed

- **`DiscogsAuthController`** — `authorize()` i `callback()` jawnie sprawdzają `getStatusCode() === 200` przed `getContent()`, zwracają 502 z `body_sample` w logu zamiast generycznego 500. [HMAI-105]
- **`GoogleAuthController::authorize()`** — `try/catch \Throwable` wokół `setState() + createAuthUrl()` z redirectem do `/tasks?error=oauth_unavailable` zamiast kernel 500. [HMAI-106]
- **`NationalLibraryApiClient` / `GoogleCalendarService`** — wąskie catche zamiast `catch (\Exception $e)`; typed exceptions bąbelkują przez framework. [HMAI-62]
- **`GetMusicComparisonHandler`** cache key — uwzględnia teraz `discogsUsername` (poprzednio collision-prone dla różnych userów). [HMAI-85]
- **`LastFmApiClient`** — `trim($apiKey) === ''` zamiast `=== ''` (whitespace-only liczy się jako brak konfiguracji). [HMAI-84]
- **`ArticleImporter`** — explicit `UTF-8` przy `mb_detect_encoding`, jawnie loguje import problems zamiast cichego skip. [HMAI-81]
- **`AlbumNormalizer`** — błędy regex i `iconv()` są logowane (poprzednio cicho zwracały oryginał). [HMAI-80, HMAI-104]
- **`DiscogsApiClient` ctor** — single VO `DiscogsCredentials` zamiast dwóch stringów; rewiring w `services.yaml`. [HMAI-113]

### Security

- Discogs consumer secret nie jest już widoczny w `debug:container --show-arguments` ani stack trace przy DI bootstrapie. [HMAI-113]
- `NationalLibraryApiClient` odrzuca odpowiedzi XXE — żaden `file:///` ani `http://evil/` nie zostanie pobrany przez parser. [HMAI-96]
- `^/api/*` ma teraz globalny rate limit (60 req/min sliding window per IP) — chroni przed brute-force enumeration i prostym DoS. [HMAI-38]

### Fixed

- Test DB suffix `_test_test` powtórzenia w CI (regression env config). [ci]
- Discogs OAuth1 `oauth_token_secret` walidowany przed sygnowaniem (zapobiega podpisaniu pustym sekretem). [HMAI-64]

### Upgrade notes (manual steps)

> **WAŻNE:** Po deployu zweryfikuj następujące punkty zanim uruchomisz aplikację.

1. **Migracje DB:** `make migrate` doda nullable kolumnę `expires_at` do `discogs_oauth_tokens` — **brak utraty danych**, kolumna na razie zawsze `NULL`.

2. **Env vars — walidacja boot-time (HMAI-90, HMAI-113):**

   Sprawdź że `.env.local` zawiera **niepuste** wartości dla:

   ```
   DISCOGS_CONSUMER_KEY=...        # HMAI-113 - DiscogsCredentials VO waliduje non-empty
   DISCOGS_CONSUMER_SECRET=...     # HMAI-113
   GOOGLE_CLIENT_ID=...            # HMAI-90 - GoogleClientFactory waliduje non-empty
   GOOGLE_CLIENT_SECRET=...        # HMAI-90
   GOOGLE_REDIRECT_URI=...         # HMAI-90
   ```

   Pusty/whitespace-only wpis spowoduje `InvalidArgumentException` przy starcie kernela. To celowy fail-fast — wcześniej puste env było traktowane jak "feature wyłączony", co maskowało faktyczną nieukończoną konfigurację.

3. **Rate limiter w prod (HMAI-38):** workflow domyślnie używa Redis pool `cache.rate_limiter`. Upewnij się że `REDIS_URL` jest osiągalny — bez Redis rate limiter zdegraduje do in-memory storage (per-process, bezsensowny w wielu workerach).

4. **OAuth tokens — bez wymuszanej re-auth.** W 1.3.0 nie ma TRUNCATE migracji (inaczej niż w 1.2.0 dla HMAI-46/47). Istniejące Google + Discogs tokens pozostają ważne.

### Coverage

- **Testy:** 366/366 zielono (vs 299/299 przy 1.2.0). +67 nowych testów rozproszonych w `tests/Unit/Module/{Music,Books,Tasks}` i `tests/Integration/{Music,Security,RateLimit}`.
- **PHPStan:** level 8 czysty (baseline 182 errors zachowany, brak nowych).
- **PHP-CS-Fixer:** `@Symfony` + `@PHP84Migration` + `global_namespace_import` — wszystkie pliki w diff zgodne.

### Closed Jira epics

- [HMAI-127] External API clients — resilience, error handling, OAuth refresh (14/14 podzadań)
- [HMAI-130] Rate limiting & throttling (1/1 podzadanie)

### Contributors

- Leszek Koziatek

[1.3.0]: https://github.com/zlotylesk/AIHomeManager/compare/1.2.0...1.3.0
[HMAI-38]: https://honemanager.atlassian.net/browse/HMAI-38
[HMAI-62]: https://honemanager.atlassian.net/browse/HMAI-62
[HMAI-63]: https://honemanager.atlassian.net/browse/HMAI-63
[HMAI-64]: https://honemanager.atlassian.net/browse/HMAI-64
[HMAI-80]: https://honemanager.atlassian.net/browse/HMAI-80
[HMAI-81]: https://honemanager.atlassian.net/browse/HMAI-81
[HMAI-84]: https://honemanager.atlassian.net/browse/HMAI-84
[HMAI-85]: https://honemanager.atlassian.net/browse/HMAI-85
[HMAI-90]: https://honemanager.atlassian.net/browse/HMAI-90
[HMAI-96]: https://honemanager.atlassian.net/browse/HMAI-96
[HMAI-104]: https://honemanager.atlassian.net/browse/HMAI-104
[HMAI-105]: https://honemanager.atlassian.net/browse/HMAI-105
[HMAI-106]: https://honemanager.atlassian.net/browse/HMAI-106
[HMAI-113]: https://honemanager.atlassian.net/browse/HMAI-113
[HMAI-114]: https://honemanager.atlassian.net/browse/HMAI-114
[HMAI-127]: https://honemanager.atlassian.net/browse/HMAI-127
[HMAI-130]: https://honemanager.atlassian.net/browse/HMAI-130

---

## [1.2.0] — 2026-05-07

Closure epica HMAI-123 — wszystkie 12 Critical findings (C1–C12) z code review HMAI-44 zamknięte. Pełna historia w `git log 1.1.0..1.2.0` oraz [HMAI-44 Confluence](https://honemanager.atlassian.net/wiki/spaces/H/pages/52658177).

Highlights: OAuth state CSRF (Google + Discogs), encryption tokenów at-rest (libsodium), Last.fm HTTPS, walidacja URL VO (Book cover + Article), Discogs collection async, `unserialize()` → JSON we wszystkich cache pathach.

## [1.1.0] — wcześniej

Implementacja modułów Series, Tasks, Books, Articles, Music (HMAI-1—HMAI-30). Historia szczegółowa w `git log 1.0.0..1.1.0`.

## [1.0.0] — wcześniej

Pierwsza wersja milestone'owa.
