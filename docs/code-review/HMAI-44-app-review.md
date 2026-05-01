# HMAI-44 — Code review całej aplikacji (HMAI-1 do HMAI-30)

**Zakres:** AIHomeManager, wszystkie zadania od HMAI-1 do HMAI-30 (5 modułów backendowych + frontend Twig/JS).
**Branża:** PHP 8.4 + Symfony 8 + MySQL + Redis + RabbitMQ.
**Data:** 2026-05-01.
**Branch:** `HMAI-44-whole-app-code-review`.
**Recenzent:** AI code review (Claude Opus 4.7) + 3× agent Explore.
**Konwencja priorytetów:** Critical (bezpieczeństwo / utrata danych), Major (architektura / błędy), Minor (kod / konwencje).

---

## Werdykt końcowy

**Aplikacja jest gotowa do dalszego rozwoju, ale NIE jest gotowa do wystawienia na zewnątrz** bez naprawy zagadnień krytycznych. Architektura heksagonalna jest spójnie zaimplementowana i czysta (Domain bez Doctrine/Symfony — zweryfikowane), CQRS z dwoma busami działa poprawnie, pokrycie testami jest dobre (87% endpointów). Główne problemy:

1. **Brak warstwy uwierzytelniania** — `security.yaml` nie istnieje, wszystkie endpointy publiczne, OAuth callback nie weryfikuje state.
2. **Tokeny OAuth (Google + Discogs) trzymane plaintextem w MySQL** bez szyfrowania.
3. **Last.fm API używa HTTP** zamiast HTTPS — ujawnia api_key.
4. **Niebezpieczne `unserialize()`** danych z Redis cache w trzech klientach API (Music, Books).
5. **Pojedynczy dual-write** w `LogReadingSessionHandler` (ORM + raw insert) — ryzyko niespójności.

Pozostałe znaleziska to dług techniczny do iteracyjnego sprzątania — nie blokują rozwoju.

**Rekomendacja:** wszystkie znaleziska rozdrobnione w osobne taski Jira z labelem `ai_code_review` i najwyższym priorytetem. Pierwsza fala wdrożenia (P0) — krytyczne pkt. 1–5 powyżej.

---

## Statystyki review

| Metryka | Wartość |
|---|---|
| Plików PHP w `src/Module/` | 106 |
| Plików testowych | 32 |
| Modułów | 5 (Series, Tasks, Books, Articles, Music) |
| Kontrolerów | 8 (5 modułów + Frontend + Google OAuth + Discogs OAuth) |
| Pokrycie endpointów testami integracyjnymi | 27/31 (87%) |
| Domain layer purity | ✅ Czysty (`grep -r "use Doctrine" src/Module/*/Domain/` zwraca pusty wynik) |
| Doctrine XML mapping | ✅ Wszystkie agregaty zmapowane XML, brak atrybutów PHP |
| CQRS bus naming | ✅ Wszystkie handlery używają `bus: 'command.bus'` lub `bus: 'query.bus'` |
| Findings ogółem | 79 |

---

## Tabela zbiorcza znalezisk

| Severity | Liczba | Obszary |
|---|---|---|
| **Critical** | 12 | Brak auth, OAuth state, plaintext tokens, HTTP w API, unserialize, dual-write, walidacja URL |
| **Major** | 27 | Eventy, repozytoria N+1, broad exception catching, walidacja inputów, CSRF, pokrycie OAuth controllers |
| **Minor** | 40 | Brak `equals()` w VO, indeksy DB, magic numbers, event delegation w JS, niespójności |

---

# Szczegółowe findings

## CRITICAL (12)

### C1 — Brak `security.yaml` — aplikacja całkowicie publiczna
**Plik:** `app/config/packages/security.yaml` — **plik nie istnieje**.
**Opis:** W `app/config/packages/` brak `security.yaml`. Symfony nie ma żadnego firewalla. Wszystkie endpointy `/api/*`, `/auth/*`, oraz strony Twig są publicznie dostępne dla każdego.
**Ryzyko:** Każdy z dostępem sieciowym może tworzyć/usuwać/edytować dane (Series, Books, Articles, Tasks). OAuth tokeny mogą zostać podmienione przez nieautoryzowany call do `/auth/discogs`.
**Fix:** Utwórz `security.yaml` z firewallem (basic auth lub login form) dla wszystkich tras poza `/auth/google/callback`, `/auth/discogs/callback`. Dla aplikacji single-user — basic auth z hasłem z env wystarczy.

### C2 — `DiscogsTokenRepository` przechowuje tokeny plaintextem
**Plik:** `app/src/Module/Music/Infrastructure/Persistence/DiscogsTokenRepository.php:20-30`
**Opis:** `oauth_token` i `oauth_token_secret` zapisywane bez szyfrowania. Tabela jest wykluczona z `schema_filter`, ale dane wciąż są w MySQL plaintext.
**Fix:** Zaszyfruj tokeny przed zapisem przez `LIBSODIUM` (`sodium_crypto_secretbox`) z kluczem z `APP_SECRET` lub dedykowanym `DISCOGS_TOKEN_KEY`. Deszyfruj przy odczycie. Alternatywa: użyj Symfony Vault.

### C3 — `GoogleOAuthTokenRepository` przechowuje JSON token plaintextem
**Plik:** `app/src/Module/Tasks/Infrastructure/Persistence/GoogleOAuthTokenRepository.php`
**Opis:** `token_json` zawierający `access_token` + `refresh_token` zapisywany bez szyfrowania.
**Fix:** Identycznie jak C2 — szyfrowanie kolumn.

### C4 — Last.fm API używa HTTP zamiast HTTPS
**Plik:** `app/src/Module/Music/Infrastructure/External/LastFmApiClient.php:14`
**Opis:** `private const API_URL = 'http://ws.audioscrobbler.com/2.0/';` — `api_key` jest przesyłany jako parametr w URL po HTTP. Każdy MITM (np. publiczne wifi developera) widzi klucz.
**Fix:** Zmień na `https://ws.audioscrobbler.com/2.0/`. Last.fm API obsługuje HTTPS od 2013 r.

### C5 — `unserialize()` danych z Redis cache (Music)
**Plik:** `app/src/Module/Music/Application/QueryHandler/GetMusicComparisonHandler.php`
**Opis:** Cache DTOs są deserializowane przez `unserialize()`. Jeśli ktoś dostanie się do Redis (np. przez współdzielony kontener developera), może wstrzyknąć złośliwy obiekt → object injection / RCE.
**Fix:** Zmień strategię cache na `json_encode`/`json_decode`. DTOs są readonly i mają proste typy — JSON wystarczy. Alternatywa: użyj Symfony Cache komponent z dedykowanym serializer.

### C6 — `unserialize()` w `LastFmApiClient` i `DiscogsApiClient`
**Pliki:**
- `app/src/Module/Music/Infrastructure/External/LastFmApiClient.php`
- `app/src/Module/Music/Infrastructure/External/DiscogsApiClient.php`
- `app/src/Module/Books/Infrastructure/External/NationalLibraryApiClient.php`
**Fix:** Identycznie jak C5 — JSON serialization.

### C7 — Dual-write w `LogReadingSessionHandler` — niespójność danych
**Plik:** `app/src/Module/Books/Application/Handler/LogReadingSessionHandler.php:38-47`
**Opis:** Handler najpierw `$book->addReadingSession($session)` → `$bookRepository->save($book)` (ORM flush), potem ręcznie `$this->connection->insert('book_reading_sessions', ...)`. Jeśli zmieni się stan i Doctrine cascade już zapisał ReadingSession, to `insert()` rzuci duplicate key. Jeśli cascade nie jest skonfigurowane, sesja jest w obu mapach, ale każda zmiana w przyszłości robi out-of-sync.
**Fix:** Wybierz JEDEN sposób:
- albo cascade: `<cascade><cascade-persist/></cascade>` w `Book.orm.xml` dla `readingSessions` i tylko `$book->addReadingSession()` + `save()`,
- albo usuń `addReadingSession()` z agregatu, zostaw tylko raw insert + osobne ładowanie w query handler.

### C8 — Brak walidacji `state` w callback Google OAuth — CSRF na OAuth
**Plik:** `app/src/Controller/GoogleAuthController.php:32-50`
**Opis:** Endpoint `GET /auth/google/callback` nie weryfikuje parametru `state`. Atakujący może spreparować link, który po kliknięciu przez ofiarę przyłączy konto Google atakującego do aplikacji ofiary.
**Fix:** Wygeneruj `state = bin2hex(random_bytes(32))` w `authorize()`, zapisz do `Session` lub `$_SESSION['oauth_state']`. W `callback()` porównaj `$request->query->get('state')` z sesyjnym i odrzuć, jeśli brak / niezgodne.

### C9 — Brak walidacji `state` w callback Discogs OAuth — CSRF na OAuth1
**Plik:** `app/src/Controller/DiscogsAuthController.php`
**Fix:** OAuth1 ma swój nonce, ale dodatkowy state w session jest standardem. Implementacja jak w C8.

### C10 — Brak walidacji URL w `BooksController::create` (`coverUrl`)
**Plik:** `app/src/Controller/BooksController.php:81`
**Opis:** Pole `coverUrl` z requestu jest przekazywane bezpośrednio do `AddBook` command bez walidacji schematu. Dopuszcza `javascript:`, `data:`, `file://`. Frontend renderuje to w `<img src=...>` bez dodatkowych zabezpieczeń.
**Fix:** Walidacja: `filter_var($url, FILTER_VALIDATE_URL)` + `parse_url($url, PHP_URL_SCHEME) in ['http', 'https']`. Odrzuć inaczej z 422.

### C11 — Brak walidacji URL w `ArticlesController::create`
**Plik:** `app/src/Controller/ArticlesController.php:72-77`
**Opis:** Identycznie jak C10 — pole `url` artykułu zapisywane bez walidacji. Frontend renderuje w `<a href="{{ url }}">`.
**Fix:** Identyczny jak C10. Dodatkowo dla artykułów: rozważ blacklistę URL phishingowych (opcjonalne).

### C12 — `sleep(1)` synchronicznie blokuje request w `DiscogsApiClient`
**Plik:** `app/src/Module/Music/Infrastructure/External/DiscogsApiClient.php:64`
**Opis:** Pętla paginacji vinyl collection ma `sleep(1)` między stronami. Dla kolekcji 200 płyt (4 strony × 50) to 3 sekundy blokady na każdy request użytkownika. PHP-FPM worker zajęty, timeout w przeglądarce realny.
**Fix:** Przesuń ładowanie kolekcji do tła (Symfony Messenger async), zapisz wynik w cache. Endpoint `/api/music/collection` zwraca cached snapshot. Cron lub event-driven refresh.

---

## MAJOR (27)

### M1 — Brak CSRF na endpointach POST/PUT/DELETE
**Pliki:** wszystkie kontrolery API (`SeriesController`, `BooksController`, `ArticlesController`, `TasksController`, `MusicController`).
**Opis:** Nawet po naprawie C1 (auth), bez tokenu CSRF każdy CSRF-able endpoint (POST/PUT/DELETE) jest podatny na ataki cross-site, jeśli sesja użytkownika żyje.
**Fix:** Dodaj `#[IsCsrfTokenValid('action_name')]` na akcjach mutujących, generuj token w Twigu `{{ csrf_token('action_name') }}`. JS pobiera token z meta tagu / data-attribute i wysyła w `X-CSRF-Token`.

### M2 — `Book` agregat nie emituje eventów po `addReadingSession()`
**Plik:** `app/src/Module/Books/Domain/Entity/Book.php:92`
**Opis:** Status zmienia się TO_READ → READING → COMPLETED, ale brak `recordedEvents[]` i `releaseEvents()`. Niemożliwe wpięcie subskrybentów (np. powiadomienie o ukończeniu książki).
**Fix:** Dodaj `BookStatusChanged` lub `BookCompleted` event, dispatch w `LogReadingSessionHandler` po `releaseEvents()`. Wzorzec z `Series`.

### M3 — `Article` ma metodę `releaseEvents()` ale handlery jej nie wywołują
**Pliki:**
- `app/src/Module/Articles/Application/Handler/MarkArticleAsReadHandler.php:21-22`
- `app/src/Module/Articles/Application/Handler/DeleteArticleHandler.php:22-23`
**Opis:** Konwencja deklaruje, że handlery dispatch'ują eventy z agregatu. Articles ma metodę, ale nikt jej nie wywołuje — martwy kod.
**Fix:** Albo usuń metodę i recordedEvents (jeśli nie potrzebne), albo dispatchuj eventy.

### M4 — N+1 query w `DoctrineSeriesRepository`
**Plik:** `app/src/Module/Series/Infrastructure/Persistence/DoctrineSeriesRepository.php:25-33`
**Opis:** `loadSeasons()` i `loadEpisodes()` to osobne zapytania per agregat. Dla listy 100 series → 1 + 100 + (100*N) queries.
**Fix:** Pojedyncze zapytanie z `LEFT JOIN seasons ON ... LEFT JOIN episodes ON ...` i ręczna hydratacja. Dla listy używaj DBAL bezpośrednio.

### M5 — Brak indeksów FK w XML mapping
**Pliki:**
- `app/src/Module/Series/Infrastructure/Persistence/Doctrine/Entity/Episode.orm.xml` — brak indeksu na `season_id`
- `app/src/Module/Series/Infrastructure/Persistence/Doctrine/Entity/Series.orm.xml` — brak indeksu na `created_at`
- `app/src/Module/Articles/Infrastructure/Persistence/Doctrine/Entity/Article.orm.xml` — brak indeksu na `added_at`
**Opis:** Doctrine XML pozwala dodać `<index name="..." columns="..."/>`. Brak indeksu = full scan na rosnących tabelach.
**Fix:** Dodaj indeksy + migracja.

### M6 — Klienty API łapią `\Exception` zbyt szeroko
**Pliki:**
- `app/src/Module/Books/Infrastructure/External/NationalLibraryApiClient.php:49`
- `app/src/Module/Tasks/Infrastructure/Google/GoogleCalendarService.php:83-96`
**Opis:** `catch (\Exception $e)` / `catch (\Throwable $e)` łapie błędy programistyczne (TypeError, AssertionError) i je tłumi. Bug może żyć latami niezauważony.
**Fix:** Łap konkretne wyjątki: `TransportExceptionInterface`, `Google\Service\Exception`, `\InvalidArgumentException`. Pozostałe niech propagują.

### M7 — `DiscogsApiClient` nie rozróżnia kodów błędów HTTP
**Plik:** `app/src/Module/Music/Infrastructure/External/DiscogsApiClient.php:52-58`
**Opis:** 401 (auth failed), 404 (user not found), 429 (rate limit) — wszystko kończy generic `RuntimeException('Discogs API unavailable')`. Caller nie może zareagować inaczej (np. ponowić auth flow przy 401).
**Fix:** Stwórz hierarchię wyjątków: `DiscogsApiException`, `DiscogsAuthException`, `DiscogsRateLimitException`. `MusicController` mapuje na 401/503/429.

### M8 — Brak tokenu OAuth refresh w Discogs i Google
**Pliki:**
- `DiscogsTokenRepository.php` — brak `expires_at`
- `GoogleCalendarService.php` — brak refresh logic
**Opis:** Token Google OAuth wygasa po 1h; Discogs OAuth1 jest bezterminowy, ale może być rewoke'owany przez użytkownika. Brak detekcji i automatycznego odświeżenia.
**Fix:** Dodaj `expires_at` do schema, w `GoogleCalendarService` użyj `$client->refreshToken($refreshToken)` przy 401.

### M9 — `MusicController::limit` walidacja przepuszcza ujemne
**Plik:** `app/src/Controller/MusicController.php:39, 69`
**Opis:** `(int) $request->query->get('limit', 20)` rzutuje '-1' na -1, potem `min(50, max(1, $limit))` co prawda sprowadza do 1, ale lepsze early-return z 422.
**Fix:** `if (!is_numeric($limit) || (int) $limit < 1) return new JsonResponse(['error' => 'Invalid limit'], 422);`.

### M10 — Brak walidacji długości tytułu series
**Plik:** `app/src/Controller/SeriesController.php:62-68`
**Opis:** `$title` po trim sprawdzane tylko `=== ''`. Jeśli ktoś wyśle 10MB string, zapisany w MySQL VARCHAR(255) → silent truncation lub Doctrine error.
**Fix:** Sprawdź `strlen($title) <= 255` w controllerze, ostatecznie 422 jeśli za długi.

### M11 — `BooksController` walidacja `pages_read` przepuszcza floaty
**Plik:** `app/src/Controller/BooksController.php:159`
**Opis:** `is_numeric($data['pages_read'])` przepuszcza `1.5`. Po cast na `int` strony są obcinane.
**Fix:** `is_int($data['pages_read']) && $data['pages_read'] > 0`.

### M12 — `BooksController::logReadingSession` data bez walidacji formatu
**Plik:** `app/src/Controller/BooksController.php:163`
**Opis:** Pole `date` przekazane stringiem do `new \DateTimeImmutable($command->date)`. Jeśli format zły, exception 500 z full stack trace zamiast 422.
**Fix:** `\DateTimeImmutable::createFromFormat('Y-m-d', $date)` z fallbackiem na 422.

### M13 — `articles.js` Promise.all zwraca silent failure przy częściowej awarii
**Plik:** `app/public/js/articles.js:104`
**Opis:** Jeden 500 z `/api/articles/today` zabija cały load.
**Fix:** `Promise.allSettled()` + per-promise error handling.

### M14 — `music.js` Promise.all — to samo
**Plik:** `app/public/js/music.js:38-42`
**Fix:** `Promise.allSettled()`.

### M15 — `articles.js` brak walidacji protokołu URL przed renderowaniem
**Plik:** `app/public/js/articles.js:37`
**Opis:** `<a href="${escHtml(article.url)}">` — `escHtml` tylko escape'uje, ale `javascript:alert(1)` nadal działa po escape'owaniu (bo to nie HTML special chars).
**Fix:** W `renderArticle` sprawdź `article.url.startsWith('http://') || startsWith('https://')`. Inaczej `href="#"`.

### M16 — `books.js` brak walidacji protokołu `coverUrl` przed `<img src>`
**Plik:** `app/public/js/books.js:24`
**Opis:** `<img src="${coverUrl}">` — `data:` lub `javascript:` (w starszych przeglądarkach) ujdzie.
**Fix:** Identyczny jak M15.

### M17 — Brak testów integracyjnych dla `GoogleAuthController`
**Plik:** `app/src/Controller/GoogleAuthController.php`
**Opis:** OAuth flow Google (authorize + callback) niepokryty żadnym testem. Regresja niezauważona w produkcji.
**Fix:** `tests/Integration/Auth/GoogleAuthControllerTest.php` — mock `Google\Client`, asercje na redirect URL i token save.

### M18 — Brak testów integracyjnych dla `DiscogsAuthController`
**Plik:** `app/src/Controller/DiscogsAuthController.php`
**Fix:** Identyczne jak M17, mock `HttpClientInterface`, asercje na flow.

### M19 — `Series.orm.xml` Manual session loading w repo, brak `fetch="EAGER"`
**Plik:** `app/src/Module/Series/Infrastructure/Persistence/DoctrineSeriesRepository.php`
**Fix:** Patrz M4 — przenieść do JOIN.

### M20 — `MusicApiTest` testuje tylko ścieżki błędów, brak happy path
**Plik:** `app/tests/Integration/Music/MusicApiTest.php`
**Opis:** Wszystkie 5 testów to 422/503. Brak weryfikacji formatu odpowiedzi przy poprawnym scenariuszu.
**Fix:** Zamockuj `LastFmApiClient` i `DiscogsApiClient` w `services_test.yaml`, dodaj `testTopAlbumsReturnsArrayWithExpectedFields`, `testComparisonWithOwnedAndListenedReturnsCorrectStructure`.

### M21 — `articles.js` brak event delegation — orphan listeners
**Plik:** `app/public/js/articles.js:61-62`
**Opis:** `list.querySelectorAll('.btn-mark-read').forEach(...)` po każdym `renderList`. Stare listenery są garbage-collected (bo węzły usunięte), ale wzorzec jest nieefektywny.
**Fix:** Event delegation na `#articles-list` raz w `loadArticles()`.

### M22 — `books.js` brak event delegation — to samo
**Plik:** `app/public/js/books.js`

### M23 — Brak handlera 500 w kontrolerach — leak stack trace
**Pliki:** wszystkie kontrolery
**Opis:** `HandlerFailedException` propaguje do Symfony, w env=prod symfony zwraca generyczny 500 (OK), ale brak custom JsonResponse handler-a → klient JS dostaje HTML zamiast JSON.
**Fix:** Dodaj `ExceptionListener` lub `kernel.exception` event subscriber, mapuj wyjątki na JSON.

### M24 — `AlbumNormalizer.php` ukrywa błędy regex
**Plik:** `app/src/Module/Music/Application/Service/AlbumNormalizer.php:16-17`
**Opis:** `preg_replace(...) ?? $original` — jeśli regex zły, normalizacja jest no-op, comparison score gorsze.
**Fix:** Test jednostkowy z `assertNotNull(preg_replace(...))` lub log warning.

### M25 — `ArticleImporter` `mb_detect_encoding()` może milczeć
**Plik:** `app/src/Module/Articles/Application/Service/ArticleImporter.php:34-35`
**Opis:** Polskie znaki w CSV z Pocketa mogą być w ISO-8859-2. Detekcja może zwrócić false, fallback na UTF-8 zniekształca dane.
**Fix:** Wymagaj jawnego parametru encoding albo testuj na sample data PL z Pocketa.

### M26 — `MarkArticleAsReadHandler` — Redis cache invalidation brak testu
**Plik:** Test istnieje (`ArticlesApiTest:137-150`) — false positive od agenta. **Pomiń.**

### M27 — `GoogleCalendarService` — brak testu na refresh token flow
**Plik:** `app/src/Module/Tasks/Infrastructure/Google/GoogleCalendarService.php`
**Fix:** Dodaj test przy implementacji M8.

---

## MINOR (40)

### Min1 — Brak `equals()` w Value Objects
**Pliki:**
- `app/src/Module/Series/Domain/ValueObject/Rating.php`
- `app/src/Module/Books/Domain/ValueObject/ReadingProgress.php`
- `app/src/Module/Books/Domain/ValueObject/ISBN.php`
- `app/src/Module/Tasks/Domain/ValueObject/TaskTitle.php`
- `app/src/Module/Tasks/Domain/ValueObject/TimeSlot.php`
- `app/src/Module/Articles/Domain/ValueObject/ArticleUrl.php`
- `app/src/Module/Series/Domain/ValueObject/AverageRating.php`
**Fix:** Dodaj `public function equals(self $other): bool`. Konwencja DDD.

### Min2 — `LastFmApiClient` brak null-check api key
**Plik:** `LastFmApiClient.php:27` — `if ($this->apiKey === '')`
**Fix:** `if ($this->apiKey === null || $this->apiKey === '')`. Property jest typed string, więc null nigdy się nie zdarzy — można zignorować.

### Min3 — `GetMusicComparisonHandler` cache key bez `discogsUsername`
**Plik:** `GetMusicComparisonHandler.php:48-52`
**Opis:** Klucz `music:comparison:{lastfm}:{period}:{limit}` — jeśli zmieniają discogsUsername, cache stary się serwuje.
**Fix:** Dołącz `discogsUsername` do klucza.

### Min4 — `EpisodeRatedHandler` 2× zapytanie avg
**Plik:** `EpisodeRatedHandler.php:19-26`
**Opis:** Series avg + Season avg w 2 queries — można w 1.
**Fix:** Pojedyncze SELECT z UNION lub agregacja w PHP.

### Min5 — `BookRepositoryInterface` — brak transakcji w handlerze
**Plik:** `LogReadingSessionHandler.php` — patrz C7.

### Min6 — Niespójność: positional vs named DBAL parameters
**Plik:** `GetAllBooksHandler.php:17` używa `:status`, inne handlery `?`.
**Fix:** Standaryzuj na named.

### Min7 — `Series` aggregate `array<string, Season>` brak fallback message
**Plik:** `Series.php:55`
**Opis:** `if (!isset($this->seasons[$seasonId])) throw new DomainException('Season "%s" not found.')` — w `addEpisode` ma context (id series), w `rateEpisode` brak.
**Fix:** Dodaj kontekst.

### Min8 — `GoogleClientFactory` brak walidacji constructor parametrów
**Plik:** `GoogleClientFactory.php:19`
**Fix:** Walidacja niepustości w konstruktorze.

### Min9 — `AddBookHandler` empty string fallback dla title
**Plik:** `AddBookHandler.php:31` — `title: $title ?? ''`
**Fix:** Walidacja w handlerze, inaczej throw.

### Min10 — Race condition w `DiscogsTokenRepository::save`
**Plik:** `DiscogsTokenRepository.php:22-24`
**Opis:** DELETE + INSERT, brak transakcji.
**Fix:** `REPLACE INTO ... VALUES (...)` lub `connection->beginTransaction()`.

### Min11 — `NationalLibraryApiClient` brak ochrony przed XXE
**Plik:** `NationalLibraryApiClient.php:43`
**Opis:** `new \SimpleXMLElement($content)` — XXE attack możliwy, jeśli BN.org by zwrócił złośliwe XML.
**Fix:** `libxml_disable_entity_loader(true)` lub `LIBXML_NOENT | LIBXML_DTDLOAD = 0`.

### Min12 — Brak testów Season/Episode Entity bezpośrednio
**Plik:** `tests/Unit/Module/Series/Domain/`
**Opis:** Testowane przez `SeriesAggregateTest`, ale brak izolowanych testów Season/Episode.
**Fix:** Dodaj `SeasonTest.php`, `EpisodeTest.php`.

### Min13 — Brak testu `ArticleDailyPick` entity
**Plik:** `app/src/Module/Articles/Domain/Entity/ArticleDailyPick.php`
**Fix:** Dodaj `ArticleDailyPickTest.php`.

### Min14 — Brak testów `GoogleClientFactory`
**Fix:** `GoogleClientFactoryTest.php`.

### Min15 — Brak testu `DiscogsTokenRepository`
**Plik:** Przy okazji C2 dodaj test integracyjny zapisu/odczytu zaszyfrowanego tokenu.

### Min16 — `tasks.js` magic timeout 6000ms vs `series.js` 5000ms
**Fix:** Stała w jednym pliku `public/js/util.js` lub data-attribute w base.html.twig.

### Min17 — Magic numbers w testach (`50.0` match score w `GetMusicComparisonHandlerTest.php:88`)
**Fix:** Wyciąg do `const EXPECTED_HALF_MATCH = 50.0;`.

### Min18 — Brak CSP meta tagu w `base.html.twig`
**Plik:** `templates/base.html.twig:6-10`
**Fix:** `<meta http-equiv="Content-Security-Policy" content="default-src 'self'; script-src 'self' 'unsafe-inline'; ...">`. Najpierw audit jakie inline.

### Min19 — Hot reload script bez SRI
**Plik:** `templates/base.html.twig:30-31`
**Opis:** `idiomorph` i `frankenphp-hot-reload` z CDN bez `integrity=...`.
**Fix:** Tylko dev — usuń w prod env. W dev SRI nie jest wymagane.

### Min20 — `ArticleDTO::fromRow` fragile column mapping
**Plik:** `ArticleDTO.php:20-29`
**Fix:** Refaktor do explicit nullable cast.

### Min21 — `GetAllSeriesHandler` ręczna hydratacja DTO
**Plik:** `GetAllSeriesHandler.php:21-22`
**Fix:** ResultSetMapping lub query builder.

### Min22 — `AlbumNormalizer::iconv()` może zwrócić false
**Plik:** `AlbumNormalizer.php:23`
**Fix:** Log warning gdy fail.

### Min23 — `DiscogsAuthController::callback` — brak weryfikacji status 200
**Plik:** `DiscogsAuthController.php:79-98`
**Fix:** `if ($response->getStatusCode() !== 200) throw ...`.

### Min24 — `GoogleAuthController::authorize` — brak try-catch
**Plik:** `GoogleAuthController.php:27`
**Fix:** Wrap `$client->createAuthUrl()` w try-catch.

### Min25 — Brak audit log dla OAuth events
**Pliki:** `GoogleAuthController.php`, `DiscogsAuthController.php`
**Fix:** Logger info: "Google OAuth completed for user X".

### Min26 — `BooksController::create` — `instanceof` zamiast `str_contains`
**Plik:** `BooksController.php:175`
**Opis:** `if (str_contains($e->getMessage(), 'not found'))` — fragile, message może się zmienić.
**Fix:** Użyj klas wyjątków.

### Min27 — `ArticlesController::create` — leak `$e->getMessage()` w response
**Plik:** `ArticlesController.php:80-88`
**Fix:** Log + generic message do klienta.

### Min28 — `Articles::Article` — pełna mutacja przez `updateMetadata`
**Plik:** `Article.php:47-51`
**Opis:** Title, category, etc. mutowalne bez invariant check.
**Fix:** Walidacja w `updateMetadata`, lub immutable + `withMetadata()`.

### Min29 — `ISBN.php:25` shadowed parameter
**Plik:** `ISBN.php:25`
**Opis:** `$this->normalized = $normalized` — property name = local variable name. Tehno OK, ale myli reviewerów.
**Fix:** Rename local var lub komentarz.

### Min30 — Brak metryk czasu trwania API calls (Last.fm/Discogs)
**Plik:** `LastFmApiClient.php`, `DiscogsApiClient.php`
**Fix:** Wrap `$httpClient->request` w timer + log p95.

### Min31 — `DiscogsApiClient` consumer secret w stringu konstruktora
**Plik:** `DiscogsApiClient.php:15-20`
**Opis:** Jeśli DI container debug log printuje konstruktor calls, secret może wyciekać.
**Fix:** Document, nie log w prod.

### Min32 — `DiscogsOAuth1Signer` brak walidacji timestamp drift
**Plik:** `DiscogsOAuth1Signer.php:29`
**Opis:** Brak walidacji że timestamp jest w ±5 min od server. Replay attack windows.
**Fix:** Discogs nie wymaga, ale dobra praktyka.

### Min33 — `tasks.js` brak `URLSearchParams` dla query params
**Plik:** `tasks.js:30`
**Fix:** Użyj `URLSearchParams` zamiast string concatenation.

### Min34 — `DoctrineTaskRepository::findByDateRange` — embedded VO query
**Plik:** `DoctrineTaskRepository.php:27-33`
**Opis:** `t.timeSlot.startDateTime BETWEEN ?` — działa, ale jeśli zmieni się mapping, breakuje silently.
**Fix:** Dodaj test.

### Min35 — `GetTimeReportHandler` weak type narrow
**Plik:** `GetTimeReportHandler.php:15`
**Fix:** Zdefiniuj `array{date: string, hours: float}` w PHPdoc.

### Min36 — `series.js` brak walidacji `res.ok` przed `.json()`
**Plik:** `public/js/series.js:4-10`
**Fix:** Helper `apiCall(url, options)` sprawdzający `res.ok`.

### Min37 — Brak `--dry-run` w `ImportArticlesCommand`
**Plik:** `app/src/Module/Articles/Infrastructure/Console/ImportArticlesCommand.php`
**Fix:** Dodaj flagę dla weryfikacji CSV bez insertu.

### Min38 — Konsystencja `final readonly` na klasach
**Opis:** Część handlerów ma `final readonly`, część tylko `final`. PHP 8.4 sugeruje readonly wszędzie gdzie możliwe.
**Fix:** Audit wszystkich handlerów + DTO.

### Min39 — Brak `make monitoring-up` w README / docs
**Fix:** Już w CLAUDE.md, ale dobrze dodać do README jeśli istnieje.

### Min40 — `cache.yaml` — pool `series.ratings.cache` ale klucze są również globalne
**Plik:** `app/config/packages/cache.yaml`
**Fix:** Audit czy keys są namespaced poprawnie.

---

## Mapa modułów — krótki werdykt każdego

| Moduł | Architektura | Pokrycie testami | Krytyczne ryzyka |
|---|---|---|---|
| **Series** (HMAI-1–HMAI-13) | ✅ Wzorzec, czysty hexagonal | 90% | M4 (N+1), M5 (indeksy) |
| **Tasks** (HMAI-14–HMAI-20) | ✅ OK, brak Command handlerów (limited scope) | 82% | M8 (refresh token), C3 (plaintext OAuth) |
| **Books** (HMAI-22–HMAI-25) | ⚠️ Dual-write pattern (C7) | 85% | C7, M2 (brak eventów) |
| **Articles** (HMAI-19, HMAI-31–HMAI-32) | ✅ OK, ale martwe `releaseEvents` | 88% | C11, M3 |
| **Music** (HMAI-23, HMAI-25–HMAI-27) | ⚠️ Najwyższe ryzyko | 75% | C2, C4, C5, C6, C9, C12 |
| **Frontend** (HMAI-28, HMAI-29, HMAI-30) | ✅ Twig + vanilla JS, czyste, mało testów | 100% endpointów Frontend | C1 (security), C10–11 (XSS via URL) |

---

## Wzorce do utrzymania (highlights)

Mocne strony obecnej architektury, które warto zachować:

1. **Domain layer purity** — żadnych Doctrine/Symfony imports w `Domain/`. Weryfikowane skryptem `grep -r "use Doctrine" src/Module/*/Domain/` (zwraca pusty wynik). Zachowaj.
2. **XML mapping** — wszystkie agregaty zmapowane przez XML. Nie migruj na atrybuty PHP.
3. **CQRS** — dwa busy (command + query), wszystkie handlery konsekwentnie oznaczone. `EpisodeRatedHandler` (event handler) bez `bus:` — poprawne.
4. **Domain Events** — `Series` jako wzorzec, `Tasks` ma `TaskScheduled`. Replikuj wzorzec w Books (M2) i Articles (M3).
5. **Test pattern** — `SeriesAggregateTest` jako gold standard (helper methods, edge cases, behavior assertions). Stosuj przy nowych testach.

---

## Lista zadań Jira do utworzenia

Wszystkie z labelem `ai_code_review`, najwyższy priorytet. Format: `HMAI-44/{severity}/{nr} — {tytuł}`. Tytuły zaakceptowane jako pełnoprawne taski:

**P0 (Critical) — 12 tasków**
**P1 (Major) — 26 tasków** (M26 pominięty jako false positive)
**P2 (Minor) — 40 tasków**

Łącznie: **78 tasków**.

Pełna lista zostanie utworzona w Jira automatycznie podczas finalizacji workflow HMAI-44.

---

## Następne kroki

1. **Natychmiast (P0):** C1 (security.yaml), C2/C3 (encryption tokenów), C4 (HTTPS Last.fm).
2. **Krótkoterminowo (P1):** M2/M3 (eventy Books/Articles), M17/M18 (testy OAuth), M4 (N+1).
3. **Iteracyjnie (P2):** Min1 (`equals()` w VO), Min12–Min15 (brakujące testy).
4. **Brakujący zakres** udokumentowany jako HMAI-41/42/43 z poprzednich review (frontend) — w toku.

Aplikacja w obecnym kształcie nadaje się do dalszego rozwoju funkcjonalnego (kolejne moduły domeny). Przed jakimkolwiek wystawieniem na zewnątrz / produkcją — wymaga ukończenia wszystkich P0 oraz przynajmniej M1 (CSRF), M17/M18 (testy OAuth) i M23 (exception handler).
