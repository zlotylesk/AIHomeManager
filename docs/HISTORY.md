# Historia projektu — log zamkniętych epików i zadań

Plik archiwalny — chronologiczny log domknięć od ekstrakcji do `CLAUDE.md` w 2026-05-23 (po 1.9.0). Aktualne wydania → [CHANGELOG.md](../CHANGELOG.md). Bieżący stan → [docs/CURRENT-STATE.md](CURRENT-STATE.md).

## Ekstrakcja 2026-05-23 — wszystkie zamknięte epiki

### Code review HMAI-44 — backlog zamknięty 2026-05-23 (release 1.9.0)

Audyt całej aplikacji w kwiecień 2026 ujawnił 59 follow-upów (12× P0 critical + reszta P1/P2). Wszystkie 9 epików tematycznych domknięte:

| Epik | Tytuł | Zamknięcie | Subzadań |
|---|---|---|---:|
| [HMAI-123](https://honemanager.atlassian.net/browse/HMAI-123) | Critical findings (C1–C12) | 2026-05-07 (1.2.0) | 12/12 |
| [HMAI-124](https://honemanager.atlassian.net/browse/HMAI-124) | Persistence & DB integrity | 2026-05-17 (1.5.0) | 9/9 |
| [HMAI-125](https://honemanager.atlassian.net/browse/HMAI-125) | Test coverage | 2026-05-16 (1.4.0) | 12/12 |
| [HMAI-126](https://honemanager.atlassian.net/browse/HMAI-126) | Operability & observability | 2026-05-17 (1.6.0) | 6/6 |
| [HMAI-127](https://honemanager.atlassian.net/browse/HMAI-127) | External API resilience | 2026-05-16 (1.3.0) | 14/14 |
| [HMAI-128](https://honemanager.atlassian.net/browse/HMAI-128) | Frontend hardening | 2026-05-19 (1.7.1) | 12/12 |
| [HMAI-129](https://honemanager.atlassian.net/browse/HMAI-129) | API hardening | 2026-05-20 (1.8.0) | 8/8 |
| [HMAI-130](https://honemanager.atlassian.net/browse/HMAI-130) | Rate limiting & throttling | 2026-05-10 (1.3.0, HMAI-38) | 1/1 |
| [HMAI-131](https://honemanager.atlassian.net/browse/HMAI-131) | Domain model & DDD purity | 2026-05-23 (1.9.0) | 12/12 |
| [HMAI-132](https://honemanager.atlassian.net/browse/HMAI-132) | Features — exports | 2026-05-23 (1.9.0) | 1/1 |

Pełny raport audytu: `docs/code-review/HMAI-44-app-review.md`. Confluence hub: page id 52658177.

### P0 (Critical) blockers przed prod — wszystkie zamknięte w 1.2.0

| Issue | Severity | Zamknięcie |
|---|---|---|
| Brak `security.yaml` | P0 | HMAI-34 |
| Plaintext OAuth tokens | P0 | HMAI-46/47 (libsodium secretbox) |
| HTTP w Last.fm | P0 | HMAI-48 (HTTPS only) |
| `unserialize()` z Redis | P0 | HMAI-49/50 (`json_decode`) |
| XSS via `javascript:` w ArticleUrl | P0 | HMAI-55 (scheme whitelist) |
| Dual-write w LogReadingSessionHandler | P0 | HMAI-51 (transactional) |
| Brak walidacji `state` w OAuth callback | P0 | HMAI-52/53 (CSRF guard) |
| Blokujący `sleep(1)` w Discogs collection | P0 | HMAI-56 (async via RabbitMQ) |

### Detail-level highlights z poszczególnych zamknięć

**1.9.0 (2026-05-23, HMAI-131 + HMAI-132):**
HMAI-131 (12/12 podzadań DDD purity): HMAI-58 BookCompleted event, HMAI-59 Article reflection guard, HMAI-83 equals() w 7 VO, HMAI-89 Series exception context, HMAI-91 AddBookHandler fail-fast, HMAI-108 typed BookNotFoundException, HMAI-110 Article::updateMetadata invariants, HMAI-111 ISBN shadowed param, HMAI-117 GetTimeReportHandler PHPDoc, HMAI-119 ImportArticles --dry-run, HMAI-120 final readonly audit, HMAI-134 Task dead-code + reflection guard. HMAI-132 (1/1): HMAI-36 CSV exports + shared `App\Csv\CsvBuilder` + 11 integration tests + 3 Newman smoke. 542/542 PHP (+47 vs 1.8.0). Confluence id 49053698 v4 (Architektura heksagonalna i DDD w PHP — Sekcja 7) + id 46891009 v5 (Dokumentacja API — CSV Export wzorce).

**1.8.0 (2026-05-21, HMAI-129):**
8/8 podzadań API hardening: HMAI-43 PATCH episode rating endpoint, HMAI-57 CSRF stateless+API key decision (`docs/HMAI-57.md`), HMAI-65 Music limit walidacja (`ctype_digit`), HMAI-66 Series/Episode title length 255, HMAI-67 Books pages_read int, HMAI-68 Books date Y-m-d, HMAI-79 globalny `ApiExceptionListener` JSON 500, HMAI-109 Articles generic error message. 495/495 PHP (+42 vs 1.7.1). Confluence id 46891009 v4 + id 49643522 v2 (Series HTTP Controller).

**1.7.1 (2026-05-19, HMAI-128 epic close):**
12/12 frontend hardening — HMAI-41 Encore+Stimulus + 9-pack batch 1.7.0 + apiCall wpięty w 4 modułach. Confluence id 52297730 v2.

**1.7.0 (2026-05-18, HMAI-128 batch 1):**
Frontend JS hardening 9 zadań: HMAI-69, 70, 71, 72, 77, 78, 98, 100, 115 (event delegation, URL validation, CSP, URLSearchParams, magic timeouts extract).

**1.6.0 (2026-05-17, HMAI-126):**
6/6 Operability: HMAI-37 `/api/health`, HMAI-35 Symfony Scheduler, HMAI-39 fixtures, HMAI-107 OAuth audit log, HMAI-112 API metrics, HMAI-133 amqp-messenger.

**1.5.0 (2026-05-17, HMAI-124):**
9/9 Persistence & DB integrity: HMAI-60 (N+1 fix in series), 61 (lookup indexes), 75 (dup), 86 (avg query), 88 (named params), 92 (transactional), 102 (column validation), 103 (hydrator extraction), 122 (cache audit). Plus `GetArticleOfTheDayHandlerTest`. Confluence patterns id 49119233 v3.

**1.4.0 (2026-05-16, HMAI-125):**
12/12 Test coverage — HMAI-73, 74, 76 (Music happy paths), 82 (Google refresh), 93 (Episode/Season), 94 (ArticleDailyPick), 95 (GoogleClientFactory), 97 (DiscogsTokenRepository), 99 (Music comparison magic numbers), 116 (DoctrineTaskRepository) + ReadingSession unit test.

**1.3.0 (2026-05-16, HMAI-127 + HMAI-130):**
14/14 External API resilience: HMAI-38 (rate limiting per-IP + external), HMAI-62 (narrow exception catches), HMAI-63 (Discogs HTTP error codes), HMAI-64 (OAuth refresh), HMAI-80 (AlbumNormalizer regex logging), HMAI-81 (ArticleImporter explicit encoding), HMAI-84 (Last.fm whitespace key), HMAI-90 (GoogleClientFactory ctor validation), HMAI-96 (NationalLibrary XXE protection), HMAI-105 (Discogs OAuth status check), HMAI-106 (Google OAuth init try-catch), HMAI-113 (Discogs credentials VO), HMAI-114 (Discogs clock drift detector), HMAI-121 (README).
Plus: HMAI-42 Playwright Series E2E, HMAI-33 Newman/Postman collection (12/12). Hub patterns Confluence id 59441164.

**1.2.0 (2026-05-07, HMAI-123):**
12/12 Critical findings — wszystkie P0 blockers wymienione w tabeli powyżej.

---

## Konwencja archiwum

Po każdym kolejnym release update'uj sekcję "Ostatnie X.Y.Z" w jednym z powyższych formatów (preferuj 2-4 zdania per release). Verbose log z każdego tag-message → CHANGELOG.md. Tutaj idą tylko summaries 1-2 paragrafy.

Pełna historia commitów: `git log --oneline | grep -E "HMAI-|chore"`.
