# Changelog

Wszystkie znaczące zmiany w projekcie AIHomeManager dokumentowane w tym pliku.

Format oparty na [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), wersjonowanie wg [SemVer](https://semver.org/lang/pl/).

## [1.28.0] — 2026-07-22

Web-frontend AIHomeManager staje się **Progressive Web App** — instalowalną, działającą offline i z powiadomieniami push (epik **HMAI-344** — 7 podzadań HMAI-345…351, każde z osobnym zielonym CI). To lżejsza, komplementarna ścieżka mobilności wobec natywnego klienta Android: aplikację można dodać do ekranu głównego, wcześniej odwiedzone widoki otwierają się bez sieci, offline'owe zapisy są kolejkowane i odtwarzane po powrocie łączności, a przypomnienia docierają przez WebPush. Całość powstaje **wewnątrz istniejącego pipeline Webpack Encore** (Workbox) — bez osobnego bundlera — a backend push (WebPush/VAPID) jest współdzielony z modułem Powiadomień, więc epik nie buduje własnego. Motywem przewodnim jest uczciwy graceful-degrade: przeglądarka bez Background Sync dostaje jawny komunikat „akcja wymaga sieci” zamiast obietnicy odtworzenia, którego nie ma jak wywołać, a zielony build nigdy nie jest brany za dowód działania — bramką jest realna przeglądarka (Playwright) plus audyt Lighthouse. Epik **nie dotyka ani jednej linii PHP**: **1768/1768 PHP** (bez zmian vs 1.27.0) + **120/120 Playwright** (+4) + **148 Vitest JS** (+26) + **43 Newman** (bez zmian) — wszystko zielone. PHPStan level 8 clean, deptrac **0 violations / 0 skip_violations**.

### Added

- **Web App Manifest + instalowalność** (HMAI-345) — `manifest.webmanifest` (`name`/`short_name`, `display: standalone`, `start_url`/`scope` `/`, kolory motywu) z kompletem ikon **maskowalnych** 192/512 (+ apple-touch 180), generowanych czystym Node bez biblioteki graficznej i kopiowanych do `public/build` bez hasha. Własny install prompt (A2HS) przechwytuje `beforeinstallprompt`, pokazuje zamykalny baner i odtwarza przechwycone zdarzenie po kliknięciu — systemowy dialog może wystartować wyłącznie z niego.
- **Service Worker (Workbox)** (HMAI-346) — jeden worker budowany przez `InjectManifest` w pipeline Encore (tylko build produkcyjny) i emitowany do **korzenia** serwisu, więc jego scope to całe origin. Precache app-shell (hashowane statyki) + `skipWaiting`/`clientsClaim` (natychmiastowe przejęcie), scalając dawny ręczny worker push w jeden.
- **Tryb offline** (HMAI-347) — runtime cache `GET /api/*` (network-first, tylko 200, ograniczony) i nawigacji (network-first, z wykluczeniem `/auth` i `/api`), dedykowana samodzielna strona offline oraz nienachalny wskaźnik offline reagujący na zdarzenia `online`/`offline`.
- **Kolejka zapisów offline — Background Sync** (HMAI-348) — `POST`/`PATCH`/`DELETE /api/*` idą przez network-only; gdy przeglądarka udostępnia Background Sync, offline'owy zapis trafia do kolejki IndexedDB i worker zwraca stronie syntetyczne **202 `{queued:true}`** („zapiszę po powrocie online”); bez Background Sync — jawne **503 `{requiresNetwork:true}`**, bez cichej utraty ani obietnicy odtworzenia. Obie warstwy `apiCall` wykrywają oznaczone 202/503 i pokazują jeden wspólny toast.
- **Push — kontekstowa zgoda** (HMAI-349) — reużycie subskrypcji WebPush/VAPID z modułu Powiadomień; miękki baner zgody pokazywany **przy powrocie na stronę, nigdy przy pierwszym wejściu**, zamykalny na stałe, z cichą re-subskrypcją, gdy uprawnienie jest przyznane, a subskrypcja zniknęła. Każdy błąd połykany, więc niedostępność push nigdy nie psuje ładowania strony.

### Changed

- **Utwardzenie Service Workera** (HMAI-351) — wersjonowanie cache runtime (`aihm-runtime-*-vN`) z handlerem `activate` usuwającym każdy nie-bieżący kubełek runtime (brak „zombie”), przy czym kolejka zapisów offline nie jest czyszczona. nginx serwuje `/sw.js` z `Cache-Control: no-cache`, więc nowy deploy jest adoptowany od razu; lokacja `/sw.js` ponownie deklaruje wszystkie cztery nagłówki bezpieczeństwa (nginxowe `add_header` na poziomie `location` zastępuje serwerowe), a `SecurityHeadersListener` pozostaje nietknięty — w projekcie nie ma CSP, więc same-origin worker nie potrzebuje wyjątku.
- **Pułapka kontroli SW utrwalona w E2E** — specyfikacje czekają na **kontrolera** strony (clientsClaim), a nie tylko na „aktywny” stan workera: przeładowanie w oknie aktywacji ściga się z `clients.claim()` i ląduje jako nawigacja niekontrolowana (zielony build ≠ działająca funkcja — precedens bootstrapa Stimulus pod ESM).

### Coverage

- **E2E + bramka instalowalności** (HMAI-350) — `tests-e2e/pwa.mobile.spec.ts` (viewport mobilny) dowodzi w realnej przeglądarce: manifest instalowalny w kształcie, SW rejestruje się → aktywuje → **kontroluje** stronę, offline wcześniej odwiedzony widok otwiera się z cache, a niecache'owany pokazuje stronę offline. Bramka CI: audyt **Lighthouse** `installable-manifest` w jobie `e2e-playwright` — `@lhci/cli@0.13.0` przypięte, bo Lighthouse 12 usunął całą kategorię PWA; zły manifest / utracone ikony / martwy worker zbijają wynik poniżej 1 i blokują merge.
- **Przegląd epiku (HMAI-344)** — pokrycie zamknięte; dodano **czwarty** test E2E domykający kontrakt kolejki zapisów offline (Background Sync obecny, offline'owy `POST /api/*` zwraca syntetyczne 202 — nigdy utracony zapis), deterministyczny, bez zależności od niedeterministycznego zdarzenia `sync`. Czyste helpery PWA (install/offline/push/queue) pokryte Vitest (148 testów).

### Documentation

- Nowa strona Confluence modułu PWA (po polsku) — instalowalność, strategia aktualizacji i wersjonowanie cache, tryb offline, kolejka zapisów, push, zakres/nagłówki/CSP, procedura awaryjna (kill-switch) i testy.
- README — nowa sekcja „Progressive Web App (PWA)” (EN): architektura SW, układ cache + dźwignia `CACHE_VERSION`, scope/nagłówki, bramka E2E+Lighthouse i gotowy do wdrożenia kill-switch.
- CLAUDE.md — rozbudowany wpis PWA (podzadania 345…351 + przegląd epiku), tabela plików Encore.

### Migration

1. **Brak migracji bazy** — epik nie dodaje tabel. `make migrate` nie jest potrzebne.
2. **Zależności frontendu** — `make node-install` (paczki Workbox), następnie `make assets-prod`; build produkcyjny emituje `public/sw.js` (artefakt, gitignored).
3. **nginx** — przeładuj konfigurację, aby wejść w życie weszła lokacja `/sw.js` (`Cache-Control: no-cache` + nagłówki bezpieczeństwa): `docker compose exec nginx nginx -s reload`.
4. **Push (opcjonalnie)** — powiadomienia push korzystają z pary kluczy VAPID modułu Powiadomień (`VAPID_PUBLIC_KEY`/`VAPID_PRIVATE_KEY`/`VAPID_SUBJECT`); brak nowych zmiennych ENV w tym epiku.

### Closed Jira

HMAI-344 (epik), HMAI-345, HMAI-346, HMAI-347, HMAI-348, HMAI-349, HMAI-350, HMAI-351.

### Carried forward

- **Niestabilny test integracyjny** (nietknięty, wciąż poza zakresem) — wzorzec „encja utworzona przed chwilą jest nie do znalezienia”, kandydat na osobne zgłoszenie w epiku jakościowym.
- Odtwarzanie kolejki Background Sync (samo zdarzenie `sync`) pozostaje niedeterministyczne w Playwright — pokryty jest kontrakt „offline'owy zapis w kolejce, nie utracony”, a nie realne odtworzenie po powrocie sieci.
- Persystowany streak z 1.19.0 wciąż nie jest czytany przez stronę odczytu (`GetStreaks` liczy w locie).

## [1.27.0] — 2026-07-21

Nowy moduł domenowy **Insights** — dashboard trendów pokazujący, jak nawyki zmieniają się w czasie (epik **HMAI-314** — 7 podzadań HMAI-329…335, każde z osobnym zielonym CI). Moduł jest celowo **prostopadły do kokpitu startowego z 1.21.0**: kokpit odpowiada na pytanie „co dzisiaj”, Insights na „jak mi z tym idzie od dłuższego czasu” — tempo czytania, obejrzane odcinki, minuty wideo, odsłuchane utwory i odsetek ukończonych zadań, w kubełkach tygodniowych lub miesięcznych. To pierwszy moduł, który **nie ma ani jednej własnej tabeli i ani jednej migracji**: niczego nie przechowuje, tylko wylicza serie z danych modułów źródłowych. Motywem przewodnim jest odróżnianie ciszy od awarii — pusty kubełek jest zerem, nigdy brakiem punktu, więc pusta seria jednoznacznie znaczy „nie udało się odczytać”, i to rozróżnienie przechodzi przez każdą warstwę aż po dwa różne stany karty w interfejsie. **1768/1768 PHP** (+84 vs 1684 w 1.26.0) + **116/116 Playwright** (+13) + **122 Vitest JS** (+18) + **43 Newman** (bez zmian) — wszystko zielone. PHPStan level 8 clean, deptrac **0 violations / 0 skip_violations**.

### Added

- **Szkielet modułu** (HMAI-329) — `src/Module/Insights/` z VO `MetricPoint`/`TrendSeries`, enumami `MetricType`/`MetricUnit`/`Granularity`, portem `TrendDataProviderInterface` i warstwami deptrac. **Jednostka metryki niesie reguły**, których reszta modułu nie musi wyprowadzać osobno: dopuszczalny zakres wartości (wskaźnik ograniczony do 100, licznik nie) oraz to, **które zwinięcie serii ma sens** — metryka kumulatywna sumuje się, wskaźnik uśrednia, więc zsumowany procent ukończeń nie ma jak trafić na dashboard.
- **Adaptery danych cross-module** (HMAI-330) — pięć adapterów DBAL (Books/Series/YouTube/Music/Tasks) czytających tabele modułów źródłowych surowym SQL-em, bez importu choćby jednej obcej klasy, plus kompozyt **kierujący** metrykę do jedynego adaptera, który ją obsługuje. Wspólna baza adapterów agreguje dzień po dniu w SQL, a kubełkuje w PHP — rok aktywności to najwyżej kilkaset wierszy, a granica tygodnia zostaje w jednym miejscu, zamiast rozejść się po pięciu ręcznych wyrażeniach SQL.
- **Warstwa odczytu** (HMAI-331) — `GetTrends` + handler na `query.bus` składający wszystkie metryki w jeden `TrendsDTO`, ze zwinięciami policzonymi w warstwie odczytu (nie przy serializacji) i **izolacją awarii per metryka**. Okno jest strzeżone: początek nie później niż koniec, liczba kubełków ograniczona (ponad dwa lata tygodni / dziesięć lat miesięcy) — nieograniczony zakres jest odrzucany, a nie obsługiwany wolno.
- **REST API** (HMAI-332) — cienki `TrendsController` (`/api/v1/trends` + alias `/api/trends`), normalizer, nowy tag `Insights` w kontrakcie OpenAPI. **Wszystkie parametry są opcjonalne** — samo wywołanie bez nich zwraca ostatnie 12 kubełków tygodniowych, więc frontend nie musi wykonywać arytmetyki dat.
- **Frontend** (HMAI-333) — strona `/insights` w konwencji Stimulus: przełącznik tydzień/miesiąc i karta na metrykę (liczba nagłówkowa z podpisem nazywającym użyte zwinięcie + wykres). **Chart.js 4 ładowany dynamicznym `import()`**, więc ~205 KB trafia do osobnej paczki zamiast do bundle'a ładowanego na każdej podstronie serwisu — zweryfikowane w wyniku builda, nie założone.
- **Cache odczytu** (HMAI-334) — dedykowana przestrzeń Redis `cache.insights` (TTL 900 s) za portem aplikacyjnym, kluczowana granulacją i obydwoma końcami okna.

### Changed

- **Cache przy odczycie zamiast wygrzewania w harmonogramie** (ticket zostawiał wybór). Prekomputacja sprawdza się, gdy odczyt ma jeden ustalony kształt — kokpitowe „dziś”, kolekcja winyli. Tutaj okno wybiera wywołujący, więc harmonogram musiałby zgadywać, które z praktycznie nieograniczonej liczby zakresów wyliczyć, i tak nie trafiając w resztę. Powtarzalnie odpytywane jest domyślne okno frontendu — i to właśnie pokrywa cache przy odczycie, bez żadnego przewidywania.
- **Odczyt cross-module przez DBAL zamiast `query.bus`** (wbrew literalnemu brzmieniu ticketów HMAI-314/330) — odczyt przez szynę oznaczałby import klasy Query obcego modułu i złamanie deptrac. Ta sama decyzja co w Goals, Search, Dashboard i Notifications.
- **Kontroler w `src/Controller/Api/`** zamiast sugerowanego `src/Controller/TrendsController.php` — wymaga tego ADR-008 (trasy wersjonowane bez wersji w atrybucie).
- Reguły poszczególnych metryk: odcinek oznaczony jako obejrzany, ale **bez daty obejrzenia**, jest pomijany zamiast zgadywany na dzisiaj; wskaźnik ukończeń **pomija zadania anulowane po obu stronach ułamka** (zadanie odwołane świadomie nie jest porażką) i zwija **sumy kubełka**, a nie średnią dziennych wskaźników — inaczej dzień z jednym zadaniem ważyłby tyle, co dzień z dziewięcioma.

### Fixed

- **Zdegradowana odpowiedź przeżywała awarię, która ją wywołała** (wykryte przy przeglądzie epiku, na styku dwóch mechanizmów poprawnych z osobna). Izolacja awarii degraduje nieodczytaną metrykę do pustej serii, żeby zepsute źródło kosztowało jedną kartę zamiast dashboardu — ale cache **zapisywał tę zdegradowaną odpowiedź**, więc chwilowa awaria była przypięta na pełne 900 s i metryka raportowała „brak danych” długo po powrocie źródła do sprawności. Cache pomija teraz zapis, gdy którakolwiek seria wróciła bez punktów; koszt to jedno ponowne wyliczenie, a najbliższy odczyt ponawia próbę.

### Coverage

- **Piramida testów** (HMAI-335) — `TrendsApiTest` czyni jedną tabelę źródłową **realnie nieczytelną** (zamiast podstawiać atrapę providera), więc izolacja awarii jest dowiedziona na prawdziwym okablowaniu: seria YouTube wraca pusta, pozostałe cztery zachowują dane, odpowiedź zostaje 200. E2E `trends.{desktop,mobile}.spec.ts` (13 testów) pokrywają dashboard na obu widokach, w tym **faktycznie wyrenderowany canvas Chart.js** (zerowa szerokość znaczyłaby, że leniwie ładowana paczka nigdy się nie uruchomiła), metrykę niedostępną obok bezczynnej oraz brak przewijania w poziomie na mobile.
- Moduł dołączył do **bramki zgodności odpowiedzi ze schematem** już przy HMAI-332, więc każdy udokumentowany moduł `^/api/*` pozostawał pod bramką bez przerwy.

### Documentation

- Nowa strona Confluence modułu Insights (po polsku) — jednostka jako nośnik reguł, kubełkowanie w jednym miejscu, kontrakt „zero zamiast braku punktu”, izolacja awarii, strategia cache i świadome pominięcia.
- README odświeżony pod trzynasty moduł: tabela modułów, licznik (dwanaście → trzynaście), lista modułów na Stimulus/Encore, trasy UI, lista odczytów cross-module przez DBAL i drzewo źródeł.
- CLAUDE.md — wpis modułu Insights (siedem sekcji, jedna na podzadanie) plus akapit przeglądu epiku, tabela plików Encore i lista tras frontendu.

### Migration

1. **Brak migracji bazy** — moduł nie ma własnych tabel. `make migrate` nie jest potrzebne dla tego wydania.
2. **Brak nowych zmiennych ENV** — Insights nie korzysta z żadnej usługi zewnętrznej.
3. **Instalacja zależności frontendu** — `make node-install` (nowa paczka `chart.js`), następnie `make assets-prod`.

### Closed Jira

HMAI-314 (epik), HMAI-329, HMAI-330, HMAI-331, HMAI-332, HMAI-333, HMAI-334, HMAI-335.

### Carried forward

- **Niestabilny test integracyjny** (nietknięty, wciąż poza zakresem) — wzorzec „encja utworzona przed chwilą jest nie do znalezienia”, kandydat na osobne zgłoszenie w epiku jakościowym.
- Persystowany streak z 1.19.0 wciąż nie jest czytany przez stronę odczytu (`GetStreaks` liczy w locie).
- Tygodniowy raport aktywności (`GenerateWeeklyActivityReport`) nadal tylko loguje swoje liczby — Insights wizualizuje te same wielkości, ale zadanie harmonogramu zostało niezmienione.

## [1.26.0] — 2026-07-21

Nowy moduł domenowy **Podcasts** — lokalna historia odsłuchów podcastów obok modułu Music (epik **HMAI-313** — 7 podzadań HMAI-322…328, każde z osobnym zielonym CI). Źródłem jest **Spotify Web API** (decyzja właściciela: tam faktycznie słucha), co uczyniło ten moduł czwartym przepływem OAuth w projekcie. Kluczowa różnica wobec Music wyszła dopiero przy weryfikacji dokumentacji dostawcy: **Spotify nie udostępnia znacznika czasu odsłuchu odcinka** — endpoint historii odtwarzania obejmuje wyłącznie utwory i pomija odcinki. Odsłuchy są więc **wyprowadzane ze stanu** (punkt wznowienia odcinka), a nie pobierane jako zdarzenia, i cała reszta modułu wynika z tej jednej właściwości: zapisany moment znaczy „nie później niż wtedy”, deduplikacja idzie po dniu zamiast po sekundzie, a zastrzeżenie jest pokazywane w interfejsie, nie tylko opisane w kodzie. **1684/1684 PHP** (+141 vs 1543 w 1.25.0) + **103/103 Playwright** (+14) + **104 Vitest JS** (+19) + **43 Newman** (bez zmian) — wszystko zielone. PHPStan level 8 clean, deptrac **0 violations / 0 skip_violations**.

### Added

- **Szkielet modułu** (HMAI-322) — `src/Module/Podcasts/` z agregatami `Podcast` i `Episode`, wspólnym VO `Title`, VO `ListeningProgress` (pozycja + flaga ukończenia trzymane razem, bo osobno są mylące: pozycja zerowa *z* flagą to „ukończony i przewinięty”, bez niej — „nigdy nieotwarty”), read modelem `ListenedEpisode` oraz warstwami deptrac. `Episode` jest osobnym agregatem spiętym stringowym kluczem obcym (ADR-007) — audycja ma tysiące odcinków, więc ładowanie ich jako kolekcji byłoby złym kształtem.
- **Port źródła + adapter Spotify** (HMAI-323) — `PodcastListeningHistoryInterface` (Domain) i `SpotifyPodcastApiClient` za limiterem `spotify_api`, wraz z całym handshake'iem OAuth: `/auth/spotify`, token szyfrowany w spoczynku, odświeżanie z **scaleniem** odpowiedzi (Spotify rotuje `refresh_token` tylko okazjonalnie, więc odpowiedź bez niego nie może skasować jedynego poświadczenia zdolnego do kolejnego odświeżenia) i **stemplowaniem czasu wydania**, którego dostawca nie podaje.
- **Rejestrowanie sesji odsłuchu** (HMAI-324) — komenda `LogPodcastListeningSession` + handler na `command.bus`, agregat `PodcastListeningSession`, tabela `podcast_listening_sessions`. Brakujący katalog jest **materializowany** (nie pomijany): historia wskazuje audycję po identyfikatorze, więc pominięcie zostawiłoby wiersze wskazujące na nic, czego interfejs nie potrafiłby wyświetlić.
- **Cykliczny odczyt** (HMAI-325) — `PollPodcastListens` (routing `async`) co 30 minut w `Schedule.php` (teraz 10 zadań cyklicznych). Nieosiągalne źródło kończy przebieg po cichu, pojedynczy wadliwy odcinek jest pomijany — oba logowane zamiast przerywać zadanie.
- **Warstwa odczytu + REST** (HMAI-326) — `GetAllPodcasts`/`GetPodcastDetail` na `query.bus` (DBAL), cienki `PodcastsController` (`/api/v1/podcasts` + alias `/api/podcasts`), normalizery, nowy tag `Podcasts` w kontrakcie OpenAPI.
- **Frontend** (HMAI-327) — strona `/podcasts` w konwencji Stimulus: siatka audycji z licznikami, szczegóły z paskami postępu odcinków i historią grupowaną po dniach, przycisk „Synchronizuj”.

### Changed

- **Reguła deduplikacji świadomie różni się od Music.** `LogListeningSession` haszuje znacznik czasu z dokładnością do sekundy, bo scrobble *jest* zdarzeniem o realnym momencie. Tutaj zapisany moment to czas obserwacji — świeży przy każdym odczycie — więc ten sam schemat sprawiłby, że **każdy przebieg wstawiałby nowy wiersz i deduplikacja nie zadziałałaby ani razu**. Tożsamością odsłuchu jest audycja + odcinek + dzień (liczony w UTC).
- **Idempotencja jest świadoma postępu**, nie tylko pomijająca: powtórna obserwacja domyka się w istniejącym wpisie i wyłącznie „do przodu” — cofnięcie jest ignorowane (ponowne odtworzenie ukończonego odcinka zeruje pozycję w źródle, a przyjęcie tej wartości przepisałoby dzień na „ledwie zaczęty”), raz ukończony pozostaje ukończony, a brak zmiany nie powoduje żadnego zapisu.
- **Klucz zewnętrzny agregatów katalogu** (`externalId`) nazwany neutralnie wobec dostawcy zamiast `spotifyId` — dołożenie drugiego źródła nie wymaga zmiany schematu.

### Fixed

- **`scripts/confluence-page.ps1` cicho niszczył tytuły stron.** Helper czytał odpowiedzi zwykłym `Invoke-RestMethod`, a Windows PowerShell 5.1 ignoruje deklarowane kodowanie odpowiedzi — polski tytuł wracał jako mojibake i, ponieważ `update` odsyła tytuł, który przed chwilą odczytał, **był zapisywany z powrotem uszkodzony**. Trafiło to na stronę modułu Podcasts, zanim zostało wychwycone. Odpowiedź jest teraz dekodowana jawnie jako UTF-8, a pusta odpowiedź (204 z `delete`) zwraca `null` zamiast wywracać `ConvertFrom-Json`.
- **Rozjazd kontraktu szczegółów audycji** wychwycony przez bramkę zgodności w CI: normalizer spłaszcza pola audycji na najwyższy poziom, więc dokumentacja opisuje odpowiedź jako złożenie (`allOf`), a nie odwołanie do modelu szczegółów.

### Coverage

- **Piramida testów** (HMAI-328) — idempotencja była dotąd dowiedziona wyłącznie na atrapach w pamięci, które nie wychwycą ani błędnego mapowania, ani klucza deduplikacji, z którym nie zgadza się baza. `LogPodcastListeningSessionDedupTest` prowadzi realną szynę komend przez realne repozytoria do realnego MySQL, a `PodcastPollIdempotencyTest` domyka złożenie odczytu cyklicznego z zapisem (nakładające się okna nie tworzą duplikatów). Reguła jest egzekwowana także unikalnym indeksem, więc równoległy odczyt nie przemyci drugiego wiersza obok kontroli aplikacyjnej.
- Moduł dołączył do **bramki zgodności odpowiedzi ze schematem** już przy HMAI-326, więc każdy udokumentowany moduł `^/api/*` pozostawał pod bramką bez przerwy.

### Documentation

- Nowa strona Confluence modułu Podcasts (po polsku) — decyzja o wyprowadzaniu odsłuchów wraz z uzasadnieniem, reguła deduplikacji, materializacja katalogu, autoryzacja i świadome pominięcia.
- CLAUDE.md — wpis modułu Podcasts (siedem sekcji, jedna na podzadanie), aktualizacja tabeli schedulera (10 zadań), listy tras async, tras frontendu i tabeli plików Encore.

### Migration

1. **Migracje bazy** — `make migrate` (3 nowe: `spotify_oauth_tokens`, `podcasts`+`podcast_episodes`, `podcast_listening_sessions` + klucze zewnętrzne katalogu).
2. **Nowe zmienne ENV** — `SPOTIFY_CLIENT_ID`, `SPOTIFY_CLIENT_SECRET`, `SPOTIFY_REDIRECT_URI`, `SPOTIFY_TOKEN_KEY` (klucz 32B base64, jak pozostałe `*_TOKEN_KEY`).
3. **Autoryzacja Spotify** — jednorazowo wejść na `/auth/spotify`. Bez zakresu `user-read-playback-position` dostawca w ogóle pomija punkty wznowienia, więc integracja połączyłaby się poprawnie i nie raportowała niczego.
4. **Przebudowa assetów** — `make assets-prod`.

### Closed Jira

HMAI-313 (epik), HMAI-322, HMAI-323, HMAI-324, HMAI-325, HMAI-326, HMAI-327, HMAI-328.

### Carried forward

- **Niestabilny test integracyjny** (poza zakresem tego epiku, nietknięty) — trzy różne przypadki (`SeriesApiTest`, `OpenApiContractTest`, `ArticlesApiTest`) padły przejściowo w trakcie wydania, zawsze na wzorcu „encja utworzona przed chwilą jest nie do znalezienia”, przy deterministycznej kolejności wykonania i zieleni w izolacji oraz w CI. Zaobserwowano też wyścig przebudowy kontenera w `var/cache/test`. Kandydat na osobne zgłoszenie w epiku jakościowym.
- Persystowany streak z 1.19.0 wciąż nie jest czytany przez stronę odczytu (`GetStreaks` liczy w locie).

## [1.25.0] — 2026-07-20

Wydanie platformowe: **propagacja request-id do workerów Messenger** (epik **HMAI-367** — 5 podzadań HMAI-368…372, każde z osobnym zielonym CI). Do tej pory korelacja logów kończyła się dokładnie tam, gdzie zaczynało się to, co trwa najdłużej i najczęściej zawodzi: praca zdjęta z żądania na kolejkę. Teraz identyfikator jedzie razem z kopertą, więc `request_id` z nagłówka HTTP pojawia się także w liniach logu zapisanych przez handler na workerze — jeden `grep` w Graylogu pokrywa całą operację, od żądania po ostatni skutek uboczny. **1543/1543 PHP** (+29 vs 1514 w 1.24.0) + **89/89 Playwright** + **85 Vitest JS** + **43 Newman** (bez zmian) — wszystko zielone. PHPStan level 8 clean, deptrac **0 violations / 0 skip_violations**. Zero zmian w modelu domenowym — czysty zysk na obserwowalności.

### Added

- **Stempel korelacyjny + middleware nadawcy** (HMAI-368) — `RequestIdStamp` na kopercie Messengera, dokładany przy dispatchu, gdy koperta jeszcze go nie ma. Rejestracja **na busie**, nie per wiadomość: korelacja jest własnością infrastruktury, więc nowy moduł dostaje ją bez żadnej akcji i nie ma czego zapomnieć przy dodaniu kolejnej komendy.
- **`RequestIdHolder`** (HMAI-369) — proces-scoped przechowanie identyfikatora po stronie workera, gdzie nie ma żądania HTTP. Zwykły serwis, bez konstrukcji typu thread-local: worker konsumuje wiadomości sekwencyjnie w jednym procesie, więc pojedyncza instancja wystarcza, a cokolwiek bardziej rozbudowanego byłoby złożonością bez pokrycia w realnym problemie.
- **Middleware odbiorcy** (HMAI-370) — na czas obsługi wiadomości wystawia wartość ze stempla w holderze i czyści ją w `finally`, ale **tylko gdy ta ramka ją ustawiła**. Zagnieżdżony dispatch synchroniczny (poll Last.fm zlecający zapis sesji odsłuchu) nie ma własnego stempla — bezwarunkowe czyszczenie skasowałoby identyfikator zewnętrzny w połowie obsługi.
- **Fallback w procesorze Monologu** (HMAI-371) — najpierw żądanie HTTP (kontekst web zostaje źródłem prawdy), w jego braku holder. Gdy oba źródła puste — zachowanie bez zmian: brak pola `request_id`, a nie puste ani odziedziczone po poprzedniej wiadomości.
- **Pokrycie end-to-end** (HMAI-372) — test integracyjny na **realnych** busach i realnym transporcie prowadzi korelator od nagłówka żądania, przez stempel na kopercie, aż po log handlera; osobne przypadki pilnują, że wiadomość bez stempla loguje bez korelatora, że łańcuch wiadomości zostaje na jednym śladzie i że stemplowanie działa na obu busach.

### Fixed

- **Wiadomość łańcuchowana z handlera na workerze gubiła korelator** — middleware nadawczy czytał wyłącznie `RequestStack`, więc import ocen odpalany na końcu importu obejrzanych pozycji startował nowy, niepowiązany ślad, choć jest bezpośrednią konsekwencją tego samego żądania. Dodany fallback na `RequestIdHolder`, z zachowaniem pierwszeństwa żądania. Lukę odsłoniło dopiero złożenie części razem w teście e2e — każdy element z osobna przechodził swoje testy jednostkowe.
- **Test przeglądu powiadomień zależny od daty** — przypadek uzgadniający tożsamość zdarzenia i cyklicznego przeglądu przypinał termin zadania do sztywnej daty, podczas gdy zdarzenie stempluje własny czas z prawdziwego zegara, a ogłaszane są tylko zadania na dziś. Test przechodził dokładnie jednego dnia w roku i od następnego czerwienił CI; fikstura chodzi teraz za „dzisiaj".
- **`daily_digest` domyślnie wyłączony** — jedyny typ powiadomienia, którego wartość zależy od tego, czy użytkownik go chce; włączenie go z automatu uczy ignorowania digestów. Stan domyślny per typ pozostaje pojęciem domenowym, czytanym zarówno przez stronę zapisu, jak i politykę wysyłki.

### Documentation

- Sekcja „Request correlation" w CLAUDE.md przepisana — usunięte zdanie o świadomym pominięciu propagacji async, w zamian opis mechanizmu wraz z uzasadnieniami decyzji.
- Nowa strona Confluence dla mechanizmu korelacji (obie warstwy, decyzje projektowe i ich powody, sposób weryfikacji) oraz strony modułów **Movies** i **Notifications**, których brakowało po wydaniach 1.23.0 i 1.24.0.
- `scripts/confluence-page.ps1` — publikacja stron przez REST API v2, obejście dla konektora Rovo MCP działającego tylko do odczytu.

### Migration

Brak. Zmiana jest wyłącznie w warstwie logowania i transportu wiadomości — żadnych migracji bazy, żadnych nowych kluczy w `.env.local`, żadnych zmian kontraktu API.

### Closed Jira

HMAI-367 (epik), HMAI-368, HMAI-369, HMAI-370, HMAI-371, HMAI-372.

## [1.24.0] — 2026-07-19

Wydanie modułu **Notifications** (epik **HMAI-275** — proaktywna warstwa dostarczania; 8 podzadań HMAI-277…284, każde z osobnym zielonym CI). Portal przestaje być bierny: powiadomienia trafiają do użytkownika **e-mailem** (Symfony Mailer) i **push** (WebPush + VAPID, bez FCM ani zewnętrznego dostawcy), wyzwalane **dwutorowo** — reaktywnie ze zdarzeń domenowych i cyklicznym przeglądem schedulera. Pełne preferencje: włącznik per typ, per kanał oraz ciche godziny. **1514/1514 PHP** (+131 vs 1383 w 1.23.0) + **89/89 Playwright** (+12) + **85 Vitest JS** (+14) + **43 Newman** (bez zmian) — wszystko zielone. PHPStan level 8 clean (zero nowych baseline entries); deptrac **0 violations / 0 skip_violations** mimo czterech punktów styku cross-module.

### Added

- **Preferencje powiadomień** (HMAI-277) — jedna komenda na oś ustawienia (kanał / typ / ciche godziny), żeby przełącznik w UI nie musiał odsyłać pozostałych dwóch. Stan domyślny nieskonfigurowanego typu to pojęcie **domenowe** (`NotificationPreference::defaultFor()`), materializowane przy pierwszym zapisie. Nullowalne okno ciszy mapowane **własnym typem DBAL** `quiet_hours`, nie nullowalnym embeddable — ten drugi hydratuje kolumnę NULL jako niepusty obiekt z niezainicjowanym polem (pułapka, która wymusiła wcześniej typ `series_rating`).
- **Silnik dyspozycji** (HMAI-278) — czysta domenowa `DispatchPolicy` (bez zegara i I/O) rozstrzyga kanały; orkiestracja żyje w handlerze aplikacyjnym, bo potrzebuje generowania id i czasu, których domena nie może importować. Ciche godziny **tłumią, nie odraczają** (odroczenie wymaga infrastruktury opóźnionych komunikatów, a przypomnienie o terminie źle się starzeje). Idempotencja przez `DedupKey` (`typ:podmiot:okno`) świadoma ponowień: blokuje wyłącznie stan `SENT`, więc chwilowa awaria nie gubi powiadomienia. Zapis **przed** wysyłką — crash w trakcie zostawia rekord `PENDING` do ponowienia, nie duplikat.
- **Kanał e-mail** (HMAI-279) — treść renderowana z szablonu Twig per typ (bloki `subject`/`body`). Wysyłka **synchroniczna**: routowanie `SendEmailMessage` na async sprawiłoby, że adapter nie zobaczy odrzucenia SMTP i silnik zapisałby `SENT` mimo maila zgubionego w DLQ. Asynchroniczność wprowadzona poziom wyżej — komenda `DispatchNotification` idzie na transport `async`.
- **Kanał push** (HMAI-280) — `WebPushNotificationChannel` (VAPID) rozsyła jedno powiadomienie na wszystkie subskrybowane przeglądarki; **404/410 usuwa subskrypcję**, błąd przejściowy ją zostawia, jedno działające urządzenie liczy się jako dostarczone. Biblioteka za cienkim szwem `WebPushSenderInterface`, więc reguły kanału są testowalne bez usługi push. Service Worker w `public/sw.js` **poza buildem Encore** — zahaszowana ścieżka zawęziłaby scope i zepsuła rejestrację.
- **Wyzwalanie reaktywne** (HMAI-281) — kontrakt shared-kernel `NotifiableEvent` + `NotificationRequest`: moduł źródłowy zgłasza zdarzenie implementując interfejs, Notifications nasłuchuje **interfejsu**, więc deptrac zostaje 0/0 bez importu Tasks/Articles/Goals. O tym, czy zdarzenie zasługuje na ogłoszenie, decyduje samo zdarzenie — tylko moduł źródłowy rozumie własne dane.
- **Wyzwalanie schedulerem** (HMAI-282) — cykliczny przegląd (`0 8` + `0 20`) znajduje to, czego żadne pojedyncze zdarzenie nie ogłosi: termin zadania zaplanowanego dawniej, serię gasnącą o północy, artykuł dnia, digest. Cztery adaptery DBAL za portem domenowym. Oba tory emitują ten sam `NotificationRequest`, więc **przegląd nie deduplikuje niczego sam** — jedna reguła, jedno miejsce.
- **REST + panel ustawień** (HMAI-283) — 8 operacji pod nowym tagiem OpenAPI `Notifications`; odczyt preferencji zwraca **każdy** typ, także nieskonfigurowany, żeby panel pokazywał stan faktycznie rządzący dostarczaniem. Panel Stimulus z opt-inem push, edycją cichych godzin i historią.

### Changed

- `Tasks\Domain\Event\{TaskCreated,TaskUpdated}` implementują shared-kernelowy `NotifiableEvent` (zadanie zaplanowane na dziś → `task_due`).
- Shared kernel rośnie o `Shared/Notification/` — trzeci sankcjonowany kontrakt cross-context po `CoverUrl` i portach tokenów.
- Scheduler: 7 → **9** zadań cyklicznych.

### Coverage

- `DispatchQuietHoursDedupTest` — prawdziwy silnik na prawdziwej bazie, preferencje zapisywane przez prawdziwy `command.bus`; stubowane wyłącznie adaptery kanałów.
- `NotificationsApiTest` (15), `NotificationsApiDocTest` (11), `WebPushNotificationChannelTest`, `EmailNotificationChannelTest`, `ScheduledNotificationSweepTest`, `ReactiveNotificationTriggerTest`.
- `OpenApiContractTest` obejmuje `/notifications/preferences` i `/history` — **każdy udokumentowany moduł `^/api/*` jest pod runtime'owym gate'em konformancji**.
- E2E `notifications.{desktop,mobile}.spec.ts` — stub API **przechwytuje zapisy**, więc asercje sprawdzają, co panel faktycznie wysłał. Wyłapały realny defekt: komunikat błędu ustawiany przez `style.display` przegrywał z `.hidden { display: none !important }` i był niewidoczny.

### Migration

1. **Migracje** — `make migrate` (`notification_preferences`, `notifications`, `push_subscriptions`).
2. **ENV** — `MAILER_DSN`, `NOTIFICATIONS_MAIL_FROM`, `NOTIFICATIONS_MAIL_TO` oraz para kluczy VAPID (`VAPID_PUBLIC_KEY`, `VAPID_PRIVATE_KEY`, `VAPID_SUBJECT`). Klucze wygenerujesz: `php -r "require 'vendor/autoload.php'; var_dump(Minishlink\WebPush\VAPID::createVapidKeys());"` — **klucz prywatny wyłącznie w `.env.local`**.
3. **Zależności** — `composer install` (nowe: `symfony/mailer`, `minishlink/web-push`).
4. **Assety** — `make assets-prod`.

Brak operacji destrukcyjnych na bazie.

### Closed Jira

HMAI-275 (epik) + HMAI-277, HMAI-278, HMAI-279, HMAI-280, HMAI-281, HMAI-282, HMAI-283, HMAI-284.

### Carried forward

Decyzje produktowe zgłoszone w review, świadomie nierozstrzygnięte: digest nakłada się domyślnie z powiadomieniami jednostkowymi (wszystkie typy włączone domyślnie), oraz brak wyprzedzenia terminu T-1 („jutro mija termin"). Telegram pozostaje poza zakresem MVP — kolejny adapter kanału nie wymaga zmian rdzenia.

## [1.23.0] — 2026-07-18

Wydanie modułu **Movies** (epik **HMAI-285** — katalog filmów obok modułu Series; 7 podzadań HMAI-286…292, każde z osobnym zielonym CI). Nowy moduł mediów: ręczny CRUD filmu, oznaczanie „obejrzane" + własna ocena 1–10, metadane katalogowe (poster/rok/status/opis), lista z filtrem obejrzane/nieobejrzane + widok szczegółów, oraz **jednokierunkowy import obejrzanych filmów i ocen z Trakt.tv**. Film to płaski agregat (bez hierarchii sezon/odcinek), więc lżejszy niż Series, ale wzorzec (agregat + VO + port + Doctrine XML + CQRS + cienki kontroler + Stimulus) identyczny. Import **reużywa istniejący klient Trakt + token OAuth bez couplingu cross-module** — read-only port `TraktTokenProviderInterface` wypromowany do warstwy Shared (precedens tokenu Google HMAI-237), więc deptrac zostaje 0/0. **1383/1383 PHP** (+184 vs 1199 w 1.21.0) + **77/77 Playwright** (+6) + **71 Vitest JS** (+7) + **43 Newman** (bez zmian) — wszystko zielone. PHPStan level 8 clean (zero nowych baseline entries); deptrac 0 `skip_violations`.

> **Uwaga o numeracji.** `1.23.0` jest **wyższy** od dotychczas najwyższego tagu `1.22.0` (epik OpenAPI), więc — inaczej niż wydania 1.19.0/1.20.0/1.21.0 (numery z roadmapy niższe od 1.22.0, nieoznaczone jako „latest") — **to wydanie staje się najwyższym tagiem i jest oznaczone jako „latest"** na GitHub. Treściowo powstaje na bazie już wydanego `1.21.0` (Dashboard); bezpośredni diff zawartości: [`1.21.0...1.23.0`](https://github.com/zlotylesk/AIHomeManager/compare/1.21.0...1.23.0).

### Added

- **Moduł Movies — szkielet Domain + persystencja (HMAI-286)** — `src/Module/Movies/Domain/` z płaskim agregatem `Movie` (`Entity/` — `id` + `Title` + `createdAt`), VO `Title` (`final readonly`, trim, niepusty, `MAX_LENGTH=255` liczone w znakach `mb_strlen`, `equals()`), portem `MovieRepositoryInterface` (`Repository/`) i warstwami deptrac `Movies*` (0/0). Persystencja ships razem ze szkieletem: `DoctrineMovieRepository` (`Infrastructure/Persistence/`) nad tabelą `movies` — `Movie.orm.xml` + embeddable `Title` (`Title.orm.xml`, precedens `ISBN` z Books), migracja `Version20260715000001`. Mapowanie **wykonywane** (nie tylko `schema:validate`): `MovieRepositoryTest` (Integration) round-trip'uje embeddable przez realny save→clear→find (w tym tytuł 255-znakowy multibyte). **Bez `MovieId` VO** — Doctrine nie użyje embeddable jako identyfikatora, więc wszystkie agregaty kluczują na `string $id`, a read-side jest DBAL.
- **CRUD filmu (HMAI-287)** — CQRS command side: `Application/Command/{AddMovie,UpdateMovie,DeleteMovie}` + `*Handler` na `command.bus`. `AddMovieHandler` mintuje UUID v4 + stempluje `createdAt` i zwraca id; `UpdateMovie` tylko zmienia tytuł (płaski agregat — jedno mutowalne pole); `DeleteMovie` usuwa. Walidacja w handlerach — VO `Title` (niepusty/≤255) → `InvalidArgumentException`, brak przy update/delete → `MovieNotFoundException` (`Application/Exception/`, `extends DomainException`). Bez zmian w `services.yaml` (binding portu z HMAI-286) i bez nowej migracji.
- **Watched + własna ocena (HMAI-288)** — agregat zyskuje flagę `watched` (+ nullable `watchedAt`) i opcjonalną własną ocenę 1–10 `Rating` VO. Przejścia: `markWatched(?DateTimeImmutable)` (stempluje „teraz" gdy brak daty — furtka dla importu Trakt), `unmarkWatched()`, `rate(?Rating)` (null czyści). Komendy `Application/Command/{MarkMovieWatched,UnmarkMovieWatched,RateMovie}` + handlery. Nullable `?Rating` mapowany **customowym typem DBAL `movie_rating`** (NIE nullable embeddable — hazard NULL→zepsute VO, który wymusił `series_rating`); `MovieRepositoryTest` pinuje że nieocenionego/nieobejrzanego film hydruje realne null-e. Migracja `Version20260716000001` (`watched`/`watched_at`/`user_rating`).
- **Metadane katalogowe (HMAI-289)** — agregat zyskuje opcjonalne `coverUrl`/`year`/`status`/`description`. `coverUrl` jako walidowany `?string` przez shared `App\Shared\Domain\ValueObject\CoverUrl` (Domain → Shared, deptrac-clean); `status` = `?MovieStatus` enum (`released`/`upcoming`) mapowany customowym typem DBAL `movie_status` (wzorzec `series_status`). `Movie::updateMetadata()` to pure full-replace store; walidacja w fabryce `Application/MovieMetadata::fromRaw()` (CoverUrl VO, rok w `[1888, currentYear+5]`, status `tryFrom`, opis ≤2000 znaków). Dedykowana komenda `UpdateMovieMetadata` osobno od `UpdateMovie` (rename-only), więc goły edit tytułu nie kasuje metadanych (split `RenameSeries`/`UpdateSeriesMetadata`). Migracja `Version20260716000002`.
- **Import z Trakt (HMAI-290)** — jednokierunkowy import Trakt → AIHM obejrzanych filmów + ocen, offloadowany na transport **async** (rate-limited + I/O bound, routing w `messenger.yaml`). Współdzielenie tokenu przez shared kernel: read-only port `App\Shared\Security\TraktTokenProviderInterface` (`get(): ?array`) wypromowany do `src/Shared/Security/` (precedens Google HMAI-237), `TraktTokenRepositoryInterface` z Series go **rozszerza** dla zapisu; adapter Movies zależy od abstrakcji Shared → deptrac 0/0. `TraktMoviesApiClient` (`Infrastructure/External/`) implementuje dwa porty Domeny `WatchedMoviesProviderInterface` (`GET /sync/watched/movies?extended=full`) + `MovieRatingsProviderInterface` (`GET /sync/ratings/movies`). Import **idempotentny po `trakt_id`**: `Movie` zyskuje nullable `?traktId` + `linkTrakt()`, repo `findByTraktId()` (kolumna + unique index, migracja `Version20260717000001`). `ImportWatchedMoviesFromTraktHandler` mintuje film na pierwsze widzenie / flipuje istniejący-nieobejrzany na obejrzany / zapisuje tylko gdy coś się zmieniło; **chainuje `ImportMovieRatingsFromTrakt`** (skip-if-missing + idempotentnie).
- **REST + frontend (HMAI-291)** — read-side `Application/Query/{GetMovies,GetMovieDetails}` + `*Handler` na `query.bus` (**DBAL**; `GetMovies` z opcjonalnym filtrem `watched`) → płaski `MovieDTO`; DTO→JSON przez `MovieDTONormalizer` (`src/Serializer/`). Cienki `MoviesController` (`src/Controller/Api/`, `/api/v1/movies` + alias `/api/movies`, ADR-008): `GET /movies?watched=`, `GET /movies/{id}`, `POST /movies`, **partial-safe** `PATCH /movies/{id}` (title→`UpdateMovie`, metadane→`UpdateMovieMetadata`), `DELETE /movies/{id}`, `PATCH /movies/{id}/watched`, `PATCH /movies/{id}/rating` (null czyści), `POST /movies/import/trakt` (**202** `{status:import_started}` / **409** `{error, authUrl}` gdy brak tokenu). Parsing/422 w Glue `MoviesRequestParser`. Udokumentowane `#[OA\*]`+`#[Model(MovieDTO)]` pod tagiem `Movies`. Frontend: strona `/movies` (`app_frontend_movies`, link w navbarze) sterowana Stimulusem `movies_controller.js` (filtr, siatka plakatów, drill-down detali z selektorem oceny 1–10 + toggle watched, formularz add/edit, przycisk „Importuj z Trakt"); czyste helpery w `assets/movies/format.js` (Vitest).

### Changed

- **Przegląd epiku (HMAI-285)** — moduł dołącza do **bramki zgodności runtime**: `OpenApiContractTest` waliduje teraz odpowiedzi `GET /api/v1/movies` (lista) + `GET /api/v1/movies/{id}` (detal) — zaseedowany, w pełni wypełniony `MovieDTO` (watched + `watchedAt`, własna ocena, wszystkie opcjonalne pola metadanych) — względem udokumentowanego schematu, więc **każdy udokumentowany moduł `^/api/*` pozostaje pod bramką** (ten sam closeout co Goals/Search/Dashboard). Brak driftu — `MovieDTONormalizer` już zgadzał się ze schematem `#[Model(MovieDTO)]` (w przeciwieństwie do korekty `lastActivityDate` w Dashboard). Dodany `MoviesApiDocTest` pinuje statyczny kontrakt (każda operacja `/movies*` udokumentowana + otagowana `Movies`, schemat `MovieDTO`, ciała żądań watched/rating, kontrakt importu 202/409), osiągając parytet z `*ApiDocTest` pozostałych modułów.

### Coverage

- **+184 testów PHP** (1199 → 1383): moduł Movies (HMAI-286…292) — jednostkowe agregatu/VO/handlerów (CRUD, watched/ocena, metadane, `MovieMetadata`), `MovieRepositoryTest` (round-trip embeddable + customowe typy DBAL `movie_rating`/`movie_status`, hydracja null-i), routing async importu (`ImportWatchedMoviesRoutingTest`/`ImportMovieRatingsRoutingTest`), HTTP `MoviesApiTest` (pełny kontrakt: CRUD, filtr watched, watched/ocena 204, partial-safe rename, import 202/409, wersjonowany+alias, 401/404/422), `MoviesRequestParserTest`, `TraktMovieImportTest` (Integration — realne handlery + realne repo/MySQL, stub tylko na granicy Trakt: fresh-import mapping, skip-if-missing ocen, flip istniejącego, idempotencja), `MoviesApiDocTest`, normalizery (`NormalizersTest`), zgodność OpenAPI (`OpenApiContractTest` — runtime-walidacja `/api/v1/movies` lista + detal). Playwright 71 → 77 (+6: `movies.desktop` 4 + `movies.mobile` 2 — dodaj → obejrzany + ocena → filtr → detal → import 202/409); Vitest 64 → 71 (+7: `movies_format`); Newman 43 bez zmian.

### Migration

1. **Migracje DB** — `make migrate` (prod: `bin/console doctrine:migrations:migrate`) zakłada tabelę `movies` i jej kolumny: `Version20260715000001` (tabela + embeddable `title`), `Version20260716000001` (`watched`/`watched_at`/`user_rating`), `Version20260716000002` (`cover_url`/`year`/`status`/`description`), `Version20260717000001` (`trakt_id` + unique index).
2. **Assets** — przebuduj front (`make assets-prod`) dla nowego kontrolera Stimulus `movies` + stylów `.movie-*`.
3. **Import z Trakt (opcjonalny)** — reużywa istniejącą integrację Trakt z modułu Series; **żadne nowe klucze `.env.local`** (`TRAKT_CLIENT_ID`/`TRAKT_CLIENT_SECRET`/`TRAKT_REDIRECT_URI`/`TRAKT_TOKEN_KEY` już są). Import wymaga jednorazowej autoryzacji `/auth/trakt`; bez tokenu przycisk zwraca 409 z linkiem — brak błędu.
4. Brak nowych zależności; brak operacji destrukcyjnych na DB; brak nowych pooli Redis.

### Closed Jira

- **Epik HMAI-285** (moduł Movies — katalog filmów obok Series) + 7 podzadań: HMAI-286, HMAI-287, HMAI-288, HMAI-289, HMAI-290, HMAI-291, HMAI-292.

## [1.21.0] — 2026-07-14

Wydanie modułu **Dashboard** (epik **HMAI-257** — pulpit startowy / kokpit agregujący „obraz dnia" ze wszystkich modułów; 7 podzadań HMAI-258…264, każde z osobnym zielonym CI). Trasa `/` przestaje być pustym redirectem i staje się kokpitem zbierającym slice „na dziś" z każdego modułu w jeden ekran wejścia: zadania na dziś, artykuł dnia, postęp celów + streaki, rekomendacje (seriale w toku / czytane książki) i ostatnia aktywność muzyczna. Kokpit czyta dane ze wszystkich modułów **bez łamania granic heksagonalnych** (port `DashboardDataProviderInterface` + pięć adapterów DBAL czytających tabele źródłowe surowym SQL, deptrac 0/0). **1199/1199 PHP** (+25 vs 1174 w 1.20.0) + **71/71 Playwright** (+5) + **64 Vitest JS** (+10) + **43 Newman** (bez zmian) — wszystko zielone. PHPStan level 8 clean (zero nowych baseline entries); deptrac 0 `skip_violations`.

> **Uwaga o numeracji.** `1.21.0` to numer z roadmapy (fixVersion epiku Dashboard nadany, zanim epik OpenAPI wyszedł jako `1.22.0`). Treściowo to wydanie powstaje **na bazie już wydanych `1.22.0` i `1.20.0`** (jest ich nadzbiorem), dlatego `1.22.0` pozostaje najwyższym tagiem na GitHub, a ten release nie jest oznaczony jako „latest". Diff zawartości względem poprzedniego wydania: [`1.20.0...1.21.0`](https://github.com/zlotylesk/AIHomeManager/compare/1.20.0...1.21.0).

### Added

- **Moduł Dashboard — port agregacji (HMAI-258)** — `DashboardDataProviderInterface` (`Domain/Port/`) to jedyny kontrakt odczytu kokpitu, zwracający znormalizowane read modele per widget (`Domain/ReadModel/` — `TodayTask`, `DailyArticle`, `GoalSnapshot`, `Recommendation`, `RecentTrack`); pięć **adapterów DBAL** (`TasksTodayAdapter` zadania na dziś / `DailyArticleAdapter` artykuł dnia / `GoalsSnapshotAdapter` cele + persisted streaki / `RecommendationsAdapter` seriale w toku + czytane książki / `RecentMusicAdapter` ostatnie odsłuchy) czyta tabele modułów źródłowych (`tasks`/`article_daily_picks`+`articles`/`goals`+`streaks`/`series`+`books`/`music_listening_sessions`) surowym SQL — bez importu jakiejkolwiek klasy cross-module (deptrac 0/0) — plus `CompositeDashboardDataProvider` delegujący każdy fragment do adaptera (autowired w `services.yaml`). Dodane warstwy deptrac `Dashboard{Domain,Application,Infrastructure}`.
- **Warstwa Query (HMAI-259)** — `GetDashboard` (Query, niosący dzień referencyjny „dziś") + `GetDashboardHandler` na `query.bus` składa wszystkie widgety przez port w jeden `Application/DTO/DashboardDTO`. Handler **fault-isolated per widget**: widget, którego źródło rzuci wyjątek, degraduje się do pustej/nullowej sekcji (logowane `warning`) zamiast wywracać cały kokpit („pusta sekcja zamiast błędu całości").
- **REST API (HMAI-260)** — cienki `DashboardController` (`src/Controller/Api/`, serwowany pod `/api/v1/dashboard` + alias `/api/dashboard`, ADR-008): `GET /dashboard` dispatchuje `GetDashboard(today)` na `query.bus` i serializuje `DashboardDTO` przez `DashboardDTONormalizer` (`src/Serializer/`, HMAI-240 — czyste mapowanie pól nad złożonymi read modelami, daty ISO-8601). Bez logiki domenowej w kontrolerze. Udokumentowane `#[OA\Get]`+`#[Model(DashboardDTO)]` pod nowym tagiem OpenAPI `Dashboard`. `DashboardApiTest` (HTTP: pełny read-model / puste sekcje / wersjonowany+alias / 401) + `DashboardApiDocTest`.
- **Strona główna `/` → kokpit (HMAI-261)** — `/` **renderuje kokpit** zamiast redirectować do modułu; `FrontendController::index()` zwraca szablon `dashboard/index.html.twig` (trasa `app_frontend_dashboard`, brand w navbarze linkuje tutaj). Zgodnie z dual-track frontendem strona to cienki szablon Twig — kontener `#dashboard-content` wypełniany po stronie klienta z `/api/dashboard`, więc kontroler pozostaje cienki (bez wstrzykiwania busa). Stary redirect `→ /series` usunięty w całości (puste widgety degradują się per-sekcja w UI). `FrontendControllerTest` pinuje render kokpitu pod `/` (200 HTML + `#dashboard-content`, bez redirectu) i nienaruszoną nawigację modułów.
- **Frontend (HMAI-262)** — kontroler Stimulus `dashboard_controller.js` (`assets/controllers/`, auto-rejestrowany jako `dashboard`) pobiera `/api/dashboard` przy `connect` i renderuje pięć kart-widgetów do `#dashboard-content`: zadania na dziś (zakres czasu + tytuł), artykuł dnia (link + kategoria + czas czytania + badge „przeczytane"), postęp celów (typ + próg·okno + bieżący streak), rekomendacje (serial/książka z okładką) i ostatnie odsłuchy (artysta — tytuł + źródło). Każdy widget degraduje się do własnej karty pustego stanu (brak danych nie psuje siatki); błąd fetchu pokazuje jedną kartę błędu. Czyste helpery prezentacji (`goalTypeLabel`/`goalPeriodLabel`/`recommendationKindLabel`/`musicSourceLabel`/`emptyStateLabel`/`formatTime`/`formatTimeRange`/`readTimeLabel`/`streakLabel`) w `assets/dashboard/format.js` — eksportowane, pokryte Vitest; responsywna siatka `.dashboard-*` w `app.css`.
- **Cache odczytu (HMAI-263)** — `GetDashboardHandler` składa kokpit za **portem Application** `DashboardCacheInterface` (`Application/Cache/` — Application nie zależy od Infrastructure); `RedisDashboardCache` (`Infrastructure/Cache/`) implementuje go nad dedykowanym poolem Redis `cache.dashboard` (TTL 300s; `cache.adapter.array` w test), kluczowany per dzień referencyjny (`dashboard_{Ymd}`) — trafienie serwuje cały złożony `DashboardDTO` z Redis zamiast ponownie odpalać wszystkie adaptery, a nowy dzień startuje świeżo. Świeżość ograniczona krótkim TTL + kluczem dziennym; inwalidacja zdarzeniami cross-module **świadomie niewpięta** (import klas zdarzeń łamałby deptrac 0/0 — precedens Search HMAI-271; TTL jest granicą nieaktualności). `RedisDashboardCacheTest` pinuje hit/miss/inwalidację; `GetDashboardHandlerTest` pinuje serwowanie drugiego dispatchu tego samego dnia z cache.

### Changed

- **Przegląd epiku (HMAI-257)** — moduł dołącza do **bramki zgodności runtime**: `OpenApiContractTest` waliduje teraz odpowiedź `GET /api/v1/dashboard` (zaseedowany, w pełni wypełniony read-model — zakres czasu zadania, artykuł dnia, snapshot celu z niepustym persisted streakiem, rekomendacja serialu w toku, ostatni odsłuch) względem udokumentowanego schematu `DashboardDTO`, więc **każdy udokumentowany moduł `^/api/*` jest pod bramką** (ten sam closeout co Goals/Search w swoich przeglądach). Bramka ujawniła jeden drift, naprawiony tutaj: `lastActivityDate` w snapshotcie celu jest teraz serializowany jako **ISO-8601** (`DateTimeInterface::ATOM`), spójnie z pozostałymi polami datetime sekcji (`startsAt`/`endsAt`/`playedAt`); `NormalizersTest` doprecyzowany.

### Coverage

- **+25 testów PHP** (1174 → 1199): moduł Dashboard (HMAI-258…264) — `DashboardAdaptersTest` (pięć adapterów DBAL + kompozyt), `GetDashboardQueryTest` (kompozycja read-modelu przez query.bus), `GetDashboardHandlerTest` (fault-isolation per widget + cache hit/miss), `RedisDashboardCacheTest` (hit/miss/inwalidacja), HTTP `DashboardApiTest` (pełny read-model / puste sekcje / wersjonowany+alias / 401), `DashboardApiDocTest`, `FrontendControllerTest` (render kokpitu pod `/`), normalizery, zgodność OpenAPI (`OpenApiContractTest` — runtime-walidacja `/api/v1/dashboard`). Playwright 66 → 71 (+5: `dashboard.desktop` 3 + `dashboard.mobile` 2, kokpit pod `/` na obu viewportach); Vitest 54 → 64 (+10: `dashboard_format`); Newman 43 bez zmian.

### Migration

1. **Brak migracji DB** — moduł Dashboard nie ma własnych tabel; czyta istniejące tabele modułów źródłowych przez adaptery DBAL.
2. **Nowy pool Redis** — `cache.dashboard` (`cache.yaml`; `cache.adapter.redis` w prod/dev, `cache.adapter.array` w test) tworzony automatycznie na istniejącym Redis — brak zmian infrastruktury, brak kroków ręcznych.
3. **Assets** — przebuduj front (`make assets-prod`) dla nowego kontrolera Stimulus `dashboard` + siatki `.dashboard-*`.
4. Brak nowych kluczy `.env.local`; brak nowych zależności; brak operacji destrukcyjnych na DB.

### Closed Jira

- **Epik HMAI-257** (pulpit startowy / kokpit — moduł Dashboard) + 7 podzadań: HMAI-258, HMAI-259, HMAI-260, HMAI-261, HMAI-262, HMAI-263, HMAI-264.

## [1.20.0] — 2026-07-11

Wydanie modułu **Search** (epik **HMAI-265** — globalne wyszukiwanie po wszystkich modułach; 7 podzadań HMAI-266…272, każde z osobnym zielonym CI). Jedno pole wyszukiwania zwraca wyniki z całego produktu (Articles / Books / Series / Music / Tasks) — z rankingiem trafności, paginacją i filtrem typu — czytając dane ze wszystkich modułów **bez łamania granic heksagonalnych** (port `SearchableProviderInterface` + adaptery DBAL czytające tabele źródłowe surowym SQL, deptrac 0/0). Backend MVP oparty na **MySQL FULLTEXT** (bez nowej ciężkiej zależności); Elasticsearch świadomie poza zakresem → osobny epik 1.30.0 (HMAI-359). **1174/1174 PHP** (+55 vs 1119 w 1.19.0) + **66/66 Playwright** (+7) + **54 Vitest JS** (+5) + **43 Newman** (bez zmian) — wszystko zielone. PHPStan level 8 clean (zero nowych baseline entries); deptrac 0 `skip_violations`.

> **Uwaga o numeracji.** `1.20.0` to numer z roadmapy (fixVersion epiku Search nadany, zanim epik OpenAPI wyszedł jako `1.22.0`). Treściowo to wydanie powstaje **na bazie już wydanych `1.22.0` i `1.19.0`** (jest ich nadzbiorem), dlatego `1.22.0` pozostaje najwyższym tagiem na GitHub, a ten release nie jest oznaczony jako „latest". Diff zawartości względem poprzedniego wydania: [`1.19.0...1.20.0`](https://github.com/zlotylesk/AIHomeManager/compare/1.19.0...1.20.0).

### Added

- **Moduł Search — Domain (HMAI-266)** — VO `SearchResult` (typ + id + tytuł + fragment + url) i `SearchQuery` (fraza + opcjonalny `typeFilter` + 1-based `page`/`perPage`, `MAX_PER_PAGE=100`) w `Domain/ValueObject/` (`final readonly`, strzeżone niezmienniki + `equals()`), enum `SearchResultType` (`article`/`book`/`series`/`music`/`task`), port `SearchEngineInterface` (`Domain/Port/`) oraz warstwy deptrac `Search*` (Domain → Shared only, deptrac 0/0).
- **Indeksowanie cross-module (HMAI-267)** — port `SearchableProviderInterface` (`Domain/Port/`) zwraca znormalizowane read modele `SearchableDocument` (`type`+`id`+`title`+`content`+`url`); pięć **adapterów DBAL** (Books/Articles/Series/Tasks/Music — Music deduplikuje albumy `GROUP BY` artysta+tytuł) czyta tabele źródłowe surowym SQL bez importu klas cross-module (deptrac 0/0), plus `CompositeSearchableProvider` agregujący adaptery (`!tagged_iterator search.searchable_provider`).
- **Silnik wyszukiwania (HMAI-268)** — adapter `FulltextSearchEngine` (`Infrastructure/Engine/`) uruchamia `MATCH…AGAINST … IN NATURAL LANGUAGE MODE` na tabeli `search_documents` (indeks FULLTEXT na `title`+`content`; migracja `Version20260711000001`, wyłączona ze `schema_filter` jako tabela nie-ORM) — ranking trafności, paginacja `LIMIT/OFFSET`, opcjonalny filtr typu → `SearchResult[]`; serwowany przez `Application/Query/Search` + `SearchHandler` na `query.bus`. Indeks zasila `SearchIndexer` (`SearchIndexerInterface`) przebudowując `search_documents` z `CompositeSearchableProvider` w jednej transakcji (idempotentnie); komenda `ReindexSearchDocuments` (`command.bus`, **sync**) odpalana przez Scheduler co 15 min (`*/15 * * * *`).
- **REST API Search (HMAI-269)** — cienki `SearchController` (`src/Controller/Api/`, serwowany pod `/api/v1/search` + alias `/api/search`, ADR-008): `GET /search?q=&type=&page=&perPage=` buduje VO `SearchQuery` (422 na pustą frazę / zły zakres `page`·`perPage` / nieznany `type`), dispatchuje `Search` na `query.bus`, serializuje `SearchResult[]` przez `SearchResultDTONormalizer`. Udokumentowane `#[OA\*]`+`#[Model(SearchResult)]` pod tagiem OpenAPI `Search`.
- **Cache wyników (HMAI-271)** — `CachingSearchEngine` (`Infrastructure/Cache/`) dekoruje silnik FULLTEXT za tym samym portem — cache `SearchResult[]` w dedykowanym poolu Redis `cache.search` (TTL 300s; `cache.adapter.array` w test) kluczowany znormalizowanym zapytaniem (fraza lowercased+trim + typ + strona). Inwalidacja: `SearchIndexer.reindex()` czyści pool — reindeks jest sygnałem „dane źródłowe się zmieniły", więc trafienie z cache nigdy nie przeżyje reindeksu (bez couplingu zdarzeń cross-module).
- **Frontend Search (HMAI-270)** — **globalny pasek wyszukiwania** w navbarze (`templates/_search.html.twig`, dołączony w `base.html.twig` → na każdej stronie) sterowany Stimulusem `search_controller.js`: debounced input (250 ms, min 2 znaki) → `apiCall('/api/search?q=…')` → wyniki pogrupowane po typie w dropdownie ze stanami loading/pusty/błąd; każde trafienie linkuje do modułu źródłowego przez `safeUrl`. Czyste helpery (`typeLabel`/`groupByType`) w `assets/search/format.js` — eksportowane, pokryte Vitest.

### Coverage

- **+55 testów PHP** (1119 → 1174): moduł Search (HMAI-266…272) — VO Domain, per-adapter `SearchableAdaptersTest`, port-level `SearchIndexingTest`, HTTP `SearchApiTest` (ranking, filtr typu, paginacja, pusty, 422/401, wersjonowany+alias), `SearchCrossModuleApiTest` (seed 5 modułów → prawdziwy `SearchIndexer` → 5 typów w jednym zapytaniu), `SearchApiDocTest`, normalizery, routing sync (`ReindexSearchDocumentsRoutingTest`), zgodność OpenAPI (`OpenApiContractTest` — runtime-walidacja `/api/search`). Playwright 59 → 66 (+7: `search.desktop` 4 + `search.mobile` 3, fraza→wyniki→nawigacja do encji); Vitest 49 → 54 (+5: `search_format`); Newman 43 bez zmian.

### Migration

1. **Migracja DB** — `make migrate` (nowa tabela `search_documents` z indeksem FULLTEXT: `Version20260711000001`; nie-ORM, wyłączona ze `schema_filter`).
2. **Scheduler** — nowe zadanie `*/15 * * * *` (`ReindexSearchDocuments`) obsługiwane przez istniejący `scheduler_worker` (Scheduler: 7 zadań cyklicznych). Indeks zbuduje się przy pierwszym uruchomieniu; ręczny reindeks: `bin/console messenger:dispatch` nie jest potrzebny — wystarczy działający `scheduler_worker`.
3. Brak nowych kluczy `.env.local`; brak operacji destrukcyjnych na DB.

### Closed Jira

- **Epik HMAI-265** (globalne wyszukiwanie po modułach — moduł Search, MySQL FULLTEXT MVP) + 7 podzadań: HMAI-266, HMAI-267, HMAI-268, HMAI-269, HMAI-270, HMAI-271, HMAI-272.

### Carried forward

- **Elasticsearch backend** — świadomie poza zakresem MVP; osobny epik **HMAI-359** (fixVersion 1.30.0). Silnik FULLTEXT siedzi za portem `SearchEngineInterface`, więc swap na Elasticsearch nie wymaga zmian po stronie odczytu.

## [1.19.0] — 2026-07-11

Wydanie modułu **Goals** (epik **HMAI-248** — cele i streaki, gamifikacja ponad modułami; 8 podzadań HMAI-249…256, każde z osobnym zielonym CI). Nowy moduł pozwala definiować cele aktywności (książki / seriale / artykuły / YouTube) w oknach dzień / tydzień / miesiąc oraz śledzić postęp i streaki (ciągłość dzienną) — czytając aktywność z istniejących modułów **bez łamania granic heksagonalnych** (port `ActivityProviderInterface` + adaptery DBAL czytające tabele źródłowe surowym SQL, deptrac 0/0). Wydanie domyka też dług techniczny **HMAI-274** (redukcja phpstan-baseline do jednego celowego wpisu). **1119/1119 PHP** (+86 vs 1033 w 1.22.0) + **59/59 Playwright** (+7) + **49 Vitest JS** (+7) + **43 Newman** (bez zmian) — wszystko zielone. PHPStan level 8 clean (zero nowych baseline entries); deptrac 0 `skip_violations`.

> **Uwaga o numeracji.** `1.19.0` to numer z roadmapy (fixVersion epiku Goals nadany, zanim epik OpenAPI wyszedł jako `1.22.0`). Treściowo to wydanie powstaje **na bazie już wydanego `1.22.0`** (jest jego nadzbiorem), dlatego `1.22.0` pozostaje najwyższym tagiem na GitHub, a ten release nie jest oznaczony jako „latest". Diff zawartości: [`1.22.0...1.19.0`](https://github.com/zlotylesk/AIHomeManager/compare/1.22.0...1.19.0).

### Added

- **Moduł Goals — Domain (HMAI-249)** — agregaty `Goal` + `Streak` (`Domain/Entity/`), enumy `GoalType` + `Period` (`Domain/Enum/`), VO `GoalTarget` (`Domain/ValueObject/`), porty `GoalRepositoryInterface` + `StreakRepositoryInterface` (`Domain/Repository/`) oraz warstwy deptrac `Goals*`.
- **Port aktywności cross-module (HMAI-250)** — `ActivityProviderInterface` (`Domain/Port/`) zwraca znormalizowane read modele `ActivityEvent` (`GoalType` + `value` + `occurredAt`) dla okna `[from, to]`; cztery **adaptery DBAL** (Books/Series/Articles/YouTube) czytają tabele modułów źródłowych surowym SQL — bez importu jakiejkolwiek klasy cross-module (deptrac 0/0) — plus `CompositeActivityProvider` agregujący adaptery (`!tagged_iterator goals.activity_provider`).
- **Definiowanie celów — write side (HMAI-251)** — komendy `CreateGoal`/`UpdateGoal`/`DeleteGoal` + handlery na `command.bus` (walidacja w handlerach: `GoalType`/`Period` przez `tryFrom`, dodatni próg przez VO `GoalTarget`, `GoalNotFoundException` na miss; typ celu niemutowalny). Persystencja: `DoctrineGoalRepository` (ORM — tabela `goals` przez `Goal.orm.xml` + embeddable `GoalTarget`, enumy przez typy DBAL `goal_type`/`goal_period`), migracja `Version20260710000001`.
- **Silnik postępu i streaków (HMAI-252)** — czysty `Domain/Service/GoalProgressCalculator` (bez I/O, unit-tested): okna dzień / tydzień-ISO-od-poniedziałku / miesiąc kalendarzowy, streak dzień-do-dnia (dedup tego samego dnia, zerwanie po całym opuszczonym dniu, zachowanie najdłuższego). Query `GetGoalsProgress`/`GetStreaks` na `query.bus` (**DBAL**) → `GoalProgressDTO`/`StreakDTO` (streaki per typ aktywności, lookback 365 dni).
- **REST API Goals (HMAI-253)** — cienki `GoalsController` (`src/Controller/Api/`, serwowany pod `/api/v1/goals` + alias `/api/goals`, ADR-008): `GET /goals`, `GET /goals/streaks`, `POST /goals` (201), `PUT /goals/{id}` (204), `DELETE /goals/{id}` (204). Odczyty przez `query.bus`, zapisy przez `command.bus`; mapowanie `HandlerFailedException` → 404/422. DTO→JSON przez `GoalProgressDTONormalizer`/`StreakDTONormalizer`; udokumentowane `#[OA\*]`+`#[Model]` z tagiem `Goals`.
- **Frontend Goals (HMAI-254)** — strona `/goals` (`app_frontend_goals`, link w nawigacji) sterowana Stimulusem `goals_controller.js`: formularz nowego celu, siatka kart z paskami postępu (`achieved/target` + capped percent + wyróżnienie „met"), inline edit (próg+okno) i usuwanie, oraz karta streaka per typ aktywności. Czyste helpery prezentacji w `assets/goals/format.js` (eksportowane, pokryte Vitest).
- **Przeliczanie streaków w tle (HMAI-255)** — komenda `RecalculateStreaks` (bez payloadu) + handler `#[AsMessageHandler(bus:'command.bus')]` **routed async**; **persisted `Streak` per typ** przez `DoctrineStreakRepository` (nowa tabela `streaks` — `Streak.orm.xml`, migracja `Version20260710000002`), idempotentne, zachowuje all-time longest przez `Streak::reconcile()`. Odpalane nocnym zadaniem Schedulera `0 1 * * *` (Scheduler: 6 → 7 zadań cyklicznych).

### Changed

- **Dług techniczny — dokończenie redukcji phpstan-baseline (HMAI-274)** — ~26 pozostałych supresji typowania naprawionych **u źródła** (nie re-baseline'owanych): produkcja (DiscogsAuthController, GetMusicComparisonHandler, ApiUser/ApiUserProvider, NationalLibraryApiClient, ArticleImporter) + test-helpery. `phpstan-baseline.neon` zawiera teraz **dokładnie jeden** celowy wpis (`ApiKeyAuthTest` — tautologia kontraktu wire).

### Coverage

- **+86 testów PHP** (1033 → 1119): moduł Goals (HMAI-249…256) — Domain (`GoalProgressCalculator`, agregaty), adaptery aktywności (`ActivityAdaptersTest`), query handlery (`GoalsProgressQueryTest`), API z seeded activity (`GoalsApiTest`), normalizery, routing async (`RecalculateStreaksRoutingTest`), zgodność OpenAPI (`OpenApiContractTest` — goals list + streaks). Playwright 52 → 59 (+7: `goals.desktop` 5 + `goals.mobile` 2); Vitest 42 → 49 (+7: `goals_format`); Newman 43 bez zmian.

### Migration

1. **Migracje DB** — `make migrate` (nowe tabele `goals` + `streaks`: `Version20260710000001`, `Version20260710000002`).
2. **Scheduler** — nowe zadanie nocne `0 1 * * *` (`RecalculateStreaks`) obsługiwane przez istniejący `scheduler_worker`; brak akcji ręcznej poza działającym workerem.
3. Brak nowych kluczy `.env.local`; brak operacji destrukcyjnych na DB.

### Closed Jira

- **Epik HMAI-248** (Goals — cele i streaki, gamifikacja ponad modułami) + 8 podzadań: HMAI-249, HMAI-250, HMAI-251, HMAI-252, HMAI-253, HMAI-254, HMAI-255, HMAI-256. Dodatkowo **HMAI-274** (dokończenie redukcji phpstan-baseline).

## [1.22.0] — 2026-07-08

Wydanie kontraktu API (epik **HMAI-311** — formalny, wersjonowany kontrakt REST oparty o OpenAPI 3.1; 8 podzadań, każde z osobnym zielonym CI). Wprowadza maszynowy opis całej powierzchni `^/api/*` generowany przez **NelmioApiDocBundle** z atrybutów na kontrolerach (bez ingerencji w cienkie kontrolery i architekturę heksagonalną), interaktywną dokumentację (Swagger UI + Redoc), współdzielone komponenty (schemat `X-API-Key`, schematy błędów 401/404/409/422/429/500, paginacja, nagłówki rate-limit/`X-Request-ID`) oraz **wersjonowanie ścieżek `/api/v1`** z aliasem zgodności wstecznej `/api` (ADR-008). Kontrakt jest **bramką jakości w CI** (5. job `openapi-contract`: dump `openapi.json` jako artefakt + lint Spectral + testy zgodności odpowiedzi ze schematem przez `opis/json-schema`) — gotowy pod generowanie typowanego klienta, fundament pod aplikację mobilną Android. **1033/1033 PHP** (+56 vs 977) + **52/52 Playwright** + **43 Newman** — wszystko zielone. PHPStan level 8 clean (zero nowych baseline entries); deptrac 0 `skip_violations`. Bez zmian w modelu domenowym — poza dodaniem prefiksu `/api/v1` z zachowaniem aliasu kontrakt opisuje istniejące zachowanie API.

### Added

- **Kontrakt OpenAPI 3.1 + interaktywna dokumentacja (HMAI-336)** — `nelmio/api-doc-bundle` generuje maszynowy dokument OpenAPI 3.1 dla `^/api/*`. Trasy publiczne (bez `X-API-Key`): `/api/doc` (Swagger UI), `/api/doc/redoc` (Redoc), `/api/doc.json` (specyfikacja). Assety Swagger/Redoc z bundla (`assets_mode: bundle`), nie z CDN.
- **Współdzielone komponenty kontraktu (HMAI-337)** — `securitySchemes.ApiKeyAuth` (apiKey/header/`X-API-Key`) jako globalne domyślne `security`; reużywalny `schemas.Error` (`{error}`) + `RateLimitError` + `Pagination` (zarezerwowany); gotowe odpowiedzi 401/404/409/422/429/500; komponenty nagłówków `X-RateLimit-*`/`Retry-After`/`X-Request-ID`. `/api/health` opisany inline jako publiczny (`security: []`).
- **Opis kontraktu wszystkich modułów (HMAI-339/340/341/342)** — atrybuty `#[OA\*]` + `#[Model]` na kontrolerach `App\Controller\Api\*` (nad `#[Route]`, zero logiki): Series + `/api/health` (339), Tasks (340), Books + Articles (341), Music + YouTubeProgress (342). Schematy `#[Model]` odzwierciedlają JSON normalizerów byte-for-byte; błędy przez `$ref` do komponentów współdzielonych. Kontrakt obejmuje całą powierzchnię `^/api/*` (39 ścieżek, 7 tagów).
- **Bramka kontraktu w CI (HMAI-343)** — nowy 5. job `openapi-contract`: dump statycznego `openapi.json` (`nelmio:apidoc:dump`, artefakt `openapi-spec`) + lint **Spectral** (`.spectral.yaml` extends `spectral:oas`, `--fail-severity=error`) + testy zgodności odpowiedzi ze schematem (`OpenApiContractTest`, `opis/json-schema` — natywny JSON Schema 2020-12 = dialekt OpenAPI 3.1). Lokalnie: `make openapi-dump` / `make openapi-lint`.

### Changed

- **Wersjonowanie API — prefiks `/api/v1` + alias `/api` (HMAI-338, ADR-008)** — 6 kontrolerów API przeniesionych do `src/Controller/Api/` (`App\Controller\Api\*`) z trasami version-agnostic (`#[Route('/series')]`); `routes.yaml` importuje katalog dwukrotnie (`/api/v1` + alias `/api`), prefiks nadawany centralnie zamiast w atrybutach. `servers` w kontrakcie = `/api/v1`; `/api/health` niewersjonowane. Firewall `api` i rate-limit obejmują oba prefiksy bez zmian (wzorzec `^/api`). Nowy **ADR-008** (path-prefix + polityka zgodności wstecznej: kiedy `v2`, jak długo żyje alias).

### Coverage

- **+56 testów PHP** (977 → 1033): HMAI-336 +5, HMAI-337 +6, HMAI-338 +4 (`ApiVersioningTest`), HMAI-339 +9, HMAI-340 +7, HMAI-341 +9, HMAI-342 +9, HMAI-343 +7 — testy spec-level (`*ApiDocTest`), wersjonowanie i zgodność odpowiedzi ze schematem (`OpenApiContractTest`). Playwright 52 i Newman 43 bez zmian.

### Documentation

- Nowa strona Confluence **ADR-008 — Wersjonowanie API przez prefiks ścieżki `/api/v1`** (pod „Architektura"). Zaktualizowana strona **Dokumentacja API** — sekcje „Kontrakt OpenAPI (maszynowy)" (Swagger UI/Redoc/`doc.json` + bramka CI) i „Wersjonowanie `/api/v1`".

### Dependencies

- Bumpy Dependabota: dev group, symfony group (amqp-messenger), webpack 5.108.3, webpack-cli 7.1.0, google/apiclient 2.19.4. Nowe zależności: `nelmio/api-doc-bundle ^5.10` + `opis/json-schema ^2.6` (require-dev). Spectral przez `npx` (pinned `@stoplight/spectral-cli@6.16.1`, poza projektowym drzewem zależności → poza npm-audit gate).

### Migration

1. **Klienci API** — docelowa baza to `/api/v1`; dotychczasowy prefiks `/api` działa jako alias zgodności wstecznej (bez wymogu migracji ścieżek od zaraz).
2. **Zależności** — `composer install` (nowe `nelmio/api-doc-bundle`, `opis/json-schema`). Brak nowych kluczy `.env.local`, brak operacji destrukcyjnych na DB.
3. **Branch protection (ręcznie, admin repo)** — dodać check `OpenAPI contract (dump + Spectral lint)` do required-checks na `develop` + `master`, aby 5. job stał się twardą bramką merge.

### Closed Jira

- **Epik HMAI-311** (kontrakt REST API — OpenAPI/Swagger + wersjonowanie `/api/v1`) + 8 podzadań: HMAI-336, HMAI-337, HMAI-338, HMAI-339, HMAI-340, HMAI-341, HMAI-342, HMAI-343.

## [1.18.0] — 2026-07-02

Wydanie techniczne (epik **HMAI-232** — dług techniczny: architektura, jakość kodu, pokrycie testami; 16 podzadań, każde z osobnym zielonym CI). Spłata długu zidentyfikowanego w audycie 2026-06-23: naruszenia granic heksagonalnych zalegalizowane w `deptrac.yaml` (`skip_violations`), tłuste kontrolery i powielona serializacja, supresje w baseline PHPStan oraz luki w pokryciu testami. Po epiku **deptrac działa z zerem `skip_violations`** (architektura bez naruszeń), a frontend zyskał pierwsze unit-testy. Przy okazji domknięte odłożone od 1.16.0 **HMAI-225 — Symfony 8.0.\* → 8.1.\*** (`framework-bundle` 8.1.1 naprawia regresję `allow_no_handlers`; bumpnięte przez Dependabota na master, back-merge do develop). **977/977 PHP** (+47 vs 930) + **52/52 Playwright** + **43 Newman** + **42 nowe Vitest JS** — wszystko zielone. PHPStan level 8 clean (zero nowych baseline entries); nowa **bramka pokrycia** (baseline 93,66%, floor 90). Bez zmian w modelu domenowym.

### Added

- **Bramka pokrycia testami (HMAI-245)** — pomiar line coverage przez **pcov** (driver w `docker/php/Dockerfile`; `pcov.enabled=0` w `php.ini` → zero narzutu na bare `make test`, włączane per-run `-d pcov.enabled=1`). `make test-coverage` → clover (`var/coverage/clover.xml`) + HTML + próg; job CI wymusza minimalny próg (fail-on-regression) i uploaduje artefakt `coverage-report`. Bramka `app/bin/coverage-check.php`. Baseline 2026-06-30 = **93,66%** (3976/4245 statements), floor **90**.
- **Unit-testy frontendu JS — Vitest (HMAI-246)** — 42 testy jsdom dla czystych funkcji (`util.js` `safeUrl`/`escHtml`; Series `sortSeries`/`filterSeries`/`ratingHighlight`/`ratingFlag`/`cardRatingFlag`/`readMetadataInputs`). `make test-js` (→ `vitest run`), krok CI w jobie `tests` po `npm ci`/`npm audit`, przed buildem Encore.
- **Shared kernel — porty i VO (HMAI-235/234/237)** — `App\Shared\`: VO `CoverUrl` (Books+Series), port `TokenCipherInterface` (Music+Tasks+Series), read-only `GoogleTokenProviderInterface` (YouTube). Dedykowana warstwa `Shared` w deptrac (Domain→Shared dozwolone, Shared bez zależności wychodzących).

### Changed

#### Architektura — granice heksagonalne (deptrac zero `skip_violations`)

- **HMAI-233** — porty Domain zwracają Domain read modele (`Domain/ReadModel/`: `BookMetadata`, `Album`, `RecentTrack`, `VinylRecord`, `VideoMetadata`), nie Application DTO — usunięte 4 grandfathered `skip_violations`.
- **HMAI-234** — `App\Security\TokenCipher` przez port Shared `TokenCipherInterface` — usunięte 3 wpisy (Music/Tasks/Series Infrastructure → Shared, koniec reachu do Glue).
- **HMAI-237** — YouTube adapter czyta token Google przez port Shared `GoogleTokenProviderInterface` (zamiast sięgać do Tasks Infrastructure) — usunięty ostatni wpis → **deptrac zero `skip_violations`**.
- **HMAI-235** — powielone VO `CoverUrl` (Books + Series) wydzielone do Shared kernela.
- **HMAI-236** — YouTubeProgress czyta watchlist/sessions przez `query.bus` (`GetWatchlist`/`GetSessions` → DBAL query handlery); kontroler bez repo Domain w odczycie — spójność CQRS z resztą modułów.

#### Kontrolery / serializacja

- **HMAI-239** — `SeriesController` (655 linii) odchudzony: całe parsowanie/walidacja payloadu do stateless `App\Controller\Series\SeriesRequestParser` (rzuca `UnprocessableEntityHttpException` → kontrakt 422 bez zmian).
- **HMAI-240** — scentralizowana serializacja DTO→JSON przez normalizery Symfony Serializer (`src/Serializer/*DTONormalizer`); koniec ręcznych prywatnych `serialize*` w kontrolerach.
- **HMAI-241** — typowane helpery dispatchu `App\Messaging\{QueryBus,CommandBus}` (`HandleTrait`) — koniec null-unsafe `->dispatch(...)->last(HandledStamp::class)->getResult()`.
- **HMAI-242** — liczenie `averageRating`/`watchedCount`/`episodeCount` przeniesione z serializacji do warstwy read (`SeriesRowHydrator`); normalizer to czyste mapowanie pól.

#### Frontend

- **HMAI-243** — god-controller `series_controller.js` (976 linii) rozbity na moduły ES w `assets/series/` (`api`/`banners`/`ratings`/`list`/buildery DOM); kontroler Stimulus ~180 linii. Żaden plik Series-UI > ~260 linii.

#### Testy / analiza statyczna

- **HMAI-247** — sprzątnięcie supresji typowania w testach (`*ApiTest` helper `jsonResponse`, konwencja typów mocków `Foo&Stub`/`Foo&MockObject`); redukcja baseline PHPStan.
- **HMAI-244** — array-shape phpdoc dla wszystkich `missingType.iterableValue` w `src/` — usunięte z baseline.
- **HMAI-238** — udokumentowana ręczna persystencja agregatu Series bez asocjacji ORM (**ADR-007**) + pin kaskady delete (`SeriesRepositoryTest` na surowych row counts).

#### Zależności

- **Symfony 8.0.\* → 8.1.\*** — `framework-bundle` 8.1.1 naprawia regresję `event.bus → allow_no_handlers` (fire-and-forget domain events), która trzymała HMAI-225 przez 1.17.0. Bump Dependabota na master, back-merge do develop; **HMAI-225 domknięte**. Plus `actions/cache` 5→6 i in-range dev bumps.

#### Dokumentacja

- **HMAI-273** — `README.md` i `CLAUDE.md` przetłumaczone na angielski.

### Coverage

- **977 PHP** (+47 vs 930 przy 1.17.0) + **52 Playwright** + **43 Newman** + **42 Vitest JS** (nowa kategoria) — wszystko zielone. PHPStan level 8 clean (zero nowych baseline entries); **deptrac 0/0 `skip_violations`**; nowa bramka coverage (93,66% baseline, floor 90). Rector dry-run + CS Fixer + Deptrac + `composer audit` + `npm audit --audit-level=high` zielone.

### Migration

1. **Reinstall Composer deps** — `composer install` (Symfony 8.1.\*).
2. **Coverage lokalnie (opcjonalnie)** — `make test-coverage` (pcov włączane per-run; `pcov.enabled=0` → zero narzutu na `make test`).

Bez migracji DB, bez nowych kluczy `.env.local`, bez operacji destrukcyjnych.

### Closed Jira

Epik **HMAI-232** (16 podzadań): HMAI-233, HMAI-234, HMAI-235, HMAI-236, HMAI-237, HMAI-238, HMAI-239, HMAI-240, HMAI-241, HMAI-242, HMAI-243, HMAI-244, HMAI-245, HMAI-246, HMAI-247, HMAI-273. Plus **HMAI-225** (Symfony 8.1) domknięte przez bump.

### Carried forward

- **HMAI-274** — dokończenie redukcji `phpstan-baseline.neon` (~27 pozostałych supresji typowania: produkcja `src/` + test-helpery) — świadomie wydzielone poza epik HMAI-232, bez fixVersion.

## [1.17.0] — 2026-06-25

Wydanie utrzymaniowe (epik **HMAI-227** — aktualizacja zasobów i zależności; 6 podzadań, każde z osobnym zielonym CI). Czysta higiena runtime/infrastruktury/zależności — bez zmian w modelu domenowym i bez nowych endpointów. Runtime PHP **8.4 → 8.5**; obrazy infrastruktury podniesione lub przypięte do wspieranych linii: MySQL **8.4 LTS** (przypięcie z floatującego `:8`), Redis **8**, RabbitMQ **4.x** (3.12 było EOL), Graylog **6.3** + MongoDB **7**; build frontu zmigrowany na **Encore 7 / Babel 8 / webpack-cli 7 (ESM)**; plus in-range bumpy Composer/npm (doctrine-migrations-bundle 4, php-cs-fixer, webpack, @playwright/test). **Symfony świadomie wstrzymane na 8.0.\*** (HMAI-225 odroczone — regresja `allow_no_handlers` w `framework-bundle` 8.1.0 wciąż nienaprawiona upstream). **930/930 PHP** + **52/52 Playwright** + **43 Newman** requests — wszystko zielone, **bez zmiany liczby testów** (tylko 3 pliki testowe dotknięte fixami zgodności z PHP 8.5). PHPStan level 8 clean (zero nowych baseline entries).

### Changed

#### Runtime & zależności PHP (HMAI-224)

- **PHP runtime 8.4 → 8.5.** `docker/php/Dockerfile` (`php:8.5-fpm-alpine`) + matryca CI (4 joby `php-version: 8.5`) + constraint `composer.json` `php: >=8.5`. Fixy zgodności z PHP 8.5 w 3 testach (`array_first()` w `SeriesRepositoryTest`, byte-mask `& 0xFF` w `TokenCipherTest`, `DiscogsClockDriftDetectorTest`) — bez nowych/usuniętych przypadków.
- **`doctrine/doctrine-migrations-bundle` 3.7 → 4.0** — major bump; `composer.lock` zregenerowany.
- **In-range Composer minors/patches** — `friendsofphp/php-cs-fixer 3.95.4 → 3.95.11` i pochodne tooling-bumpy.

#### Obrazy infrastruktury

- **MySQL `mysql:8` → `mysql:8.4` (HMAI-230)** — przypięcie do linii **LTS** (floatujący `:8` skoczyłby na 9.x innovation po EOL 8.0); compose + 3 joby CI. `serverVersion=8.0` w DSN zostaje świadomie (DBAL `MySQL80Platform` w pełni zgodny z serwerem 8.4 — zero driftu `schema:validate`).
- **Redis `redis:7-alpine` → `redis:8-alpine` (HMAI-229)** — compose + 3 joby CI; cache średnich / distributed lock / rate-limiter bez zmian.
- **RabbitMQ `rabbitmq:3.12` → `rabbitmq:4-management-alpine` (HMAI-228)** — 3.12 było **EOL**; major-pin `:4` trzyma wspieraną linię (Khepri metadata backend, quorum queues default). Classic mirrored queues usunięte w 4.0 — nieużywane, więc bump niskiego ryzyka.
- **Monitoring: Graylog 5.2 → 6.3, MongoDB `mongo:6` → `mongo:7` (HMAI-231)** — OpenSearch zostaje na `:2` (Graylog 6.3 wspiera OpenSearch tylko 1.1–2.19.5, NIE 3.x). `scripts/graylog-bootstrap.sh` — fix readiness probe; GELF input / index sets / streamy bez zmian kontraktu.

#### Build frontu — Encore 7 / Babel 8 / webpack-cli 7 (ESM, HMAI-226)

- **`@symfony/webpack-encore` 6 → 7**, **`@babel/core`/`@babel/preset-env` 7 → 8**, **`webpack-cli` 6 → 7**, webpack 5.107 → 5.108. `webpack.config.js` przepisany na **ESM** (`import Encore`, top-level `await Encore.getWebpackConfig()`, `"type":"module"` w `package.json`).
- **Polyfille corejs3 przez `babel-plugin-polyfill-corejs3`** (Babel 8 usunął `useBuiltIns`/`corejs` z preset-env); targety przez `"browserslist": ["defaults"]`.
- **Stimulus bootstrap pod ESM** — `assets/bootstrap.js` ładuje kontrolery przez `import.meta.webpackContext` (nie CJS-owy `require.context`, który pod `type:module` rzuca runtime `ReferenceError`).
- **`.github/dependabot.yml`** — usunięte 3 reguły ignore-major (`webpack-cli` + `@babel/core` + `@babel/preset-env`); Encore 7 obejmuje te majory peer-range'em.

#### Epic review (HMAI-227)

- In-range bumpy `friendsofphp/php-cs-fixer 3.95.11`, `webpack 5.108.0`, `@playwright/test 1.61.0 → 1.61.1`; re-sweep `composer/npm outdated` czysty poza świadomymi pinami (Symfony 8.0.\*, newman 6.x).

### Coverage

- **930 PHP / 52 Playwright / 43 Newman — bez zmiany liczby testów.** Tylko 3 pliki testowe zmodyfikowane (fixy zgodności PHP 8.5, zero nowych/usuniętych przypadków). PHPStan level 8 clean (zero nowych baseline entries). Rector dry-run + CS Fixer + Deptrac + `composer audit` + `npm audit --audit-level=high` zielone.

### Documentation

- **CLAUDE.md**: „Stack" → PHP 8.5 / MySQL 8.4 LTS / Redis 8 / RabbitMQ 4.x; sekcje Infrastruktura / Encore / Held-dependency zaktualizowane; „Status" → 1.17.0. **README.md**: tabela wersji stacku zaktualizowana.

### Migration

1. **Przebuduj obraz PHP** — `docker compose build php` (bazowy obraz `php:8.5-fpm-alpine`).
2. **Podnieś obrazy infrastruktury** — `docker compose pull && docker compose up -d` (MySQL 8.4, Redis 8, RabbitMQ 4.x; metadata RabbitMQ efemeryczna — Messenger auto-deklaruje exchange/kolejki przy połączeniu).
3. **Reinstaluj zależności frontu** — `make node-install` (`npm install` po bumpie Encore 7 / Babel 8), następnie `make assets-prod`.
4. **Monitoring (opcjonalnie)** — `make monitoring-up && make monitoring-bootstrap` (Graylog 6.3 + MongoDB 7).
5. Brak migracji DB, brak nowych kluczy `.env.local`, brak operacji destrukcyjnych.

### Closed Jira

- **HMAI-227** (epik) — Aktualizacja zasobów i zależności aplikacji (runtime, Composer, npm)
- **HMAI-224** — bump zależności Composer/npm + runtime PHP 8.5
- **HMAI-226** — migracja build frontu na Encore 7 / Babel 8 / webpack-cli 7 (ESM)
- **HMAI-228** — RabbitMQ 3.12 (EOL) → 4.x
- **HMAI-229** — Redis 7 → 8
- **HMAI-230** — MySQL pin → 8.4 LTS
- **HMAI-231** — monitoring: Graylog 5.2 → 6.3 + MongoDB 7

### Carried forward

- **HMAI-225 (Symfony 8.0 → 8.1)** — odroczone, wciąż zablokowane upstream: `framework-bundle` 8.1.0 gubi `event.bus → default_middleware.allow_no_handlers: true` (fire-and-forget domain eventy bez handlera → `NoHandlerForMessageException`). Odblokować gdy wyjdzie 8.1.1+ z fixem.

## [1.16.0] — 2026-06-21

Domknięcie **dwóch** epików GUI: **HMAI-194** (Books — uzupełnienie GUI, 5 podzadań) i **HMAI-195** (Music — uzupełnienie GUI, 2 podzadania) — każdy z epic review. Moduły Books i Music dostają pełne panele zarządzania nad istniejącym REST API. **Books** (tor Encore + Stimulus): edycja metadanych, usuwanie (z potwierdzeniem), widok szczegółów z historią sesji czytania, eksport CSV/PDF oraz dodawanie pełnym payloadem (tryb ręczny, bez ISBN). **Music** (tor Twig + vanilla JS): widok lokalnej historii odsłuchów (filtr from/to/source) i ręczne rejestrowanie sesji odsłuchu. Wszystkie podzadania to czysta warstwa frontu nad gotowym REST — bez zmian w modelu domenowym. Epic review każdego modułu domknął higienę testów: Books — konsolidacja redundantnego `CoverUrlTest` do kanonicznej lokalizacji `Domain/ValueObject/`; Music — mobilny spec E2E `music.mobile.spec.ts` (Music był ostatnim modułem GUI bez mobilnego speca) plus testy jednostkowe VO `AlbumArtist`/`AlbumTitle`. **Tym wydaniem cały backlog uzupełnienia GUI — 4 epiki (Tasks, Articles, Books, Music) — jest domknięty**: każdy moduł domenowy ma teraz pełny panel zarządzania i mobilny E2E overflow guard. **930/930 PHP** (+9) + **52/52 Playwright** (+10) + **43 Newman** requests — wszystko zielone. PHPStan level 8 clean (zero nowych baseline entries).

### Added

#### Books — uzupełnienie panelu GUI (Encore + Stimulus nad istniejącym REST)

- **Widok szczegółów książki (HMAI-214).** Toggle lista↔detal („View" w karcie) → `GET /api/books/{id}` z historią sesji czytania (`sessions[]` posortowane malejąco po dacie); nowe DTO `BookDetailDTO`/`ReadingSessionDTO`.
- **Dodawanie pełnym payloadem (HMAI-216).** Modal „Add book" dostał przełącznik ISBN/ręczny; tryb ręczny wysyła pełny payload do `POST /api/books` (frontend-only — endpoint już to wspierał, GUI miało tylko ścieżkę ISBN).
- **Edycja metadanych (HMAI-212).** Przycisk „✎ Edit details" w detalu → modal pre-fill → `PUT /api/books/{id}` → odświeżenie detalu i listy (frontend-only).
- **Eksport biblioteki CSV/PDF (HMAI-215).** Przyciski w nagłówku Books → `GET /api/books/export?format=` z autoryzowanym pobraniem (blob download, wzorzec z eksportu Articles).
- **Usuwanie książki (HMAI-213).** `DELETE /api/books/{id}` z `confirm()` w widoku szczegółów, powrót do listy.

#### Music — uzupełnienie panelu GUI (Twig + vanilla JS nad istniejącym REST)

- **Historia odsłuchów (HMAI-217).** Nowa sekcja „Listening History" w `/music` z filtrami from/to/source → `GET /api/music/history`; ładowana niezależnie od wolnych sekcji zewnętrznych (top-albumy/Discogs). Dodany `music.desktop.spec.ts`.
- **Ręczny zapis sesji (HMAI-218).** Formularz „Log a play" w karcie historii → `POST /api/music/sessions` → przeładowanie historii (source domyślnie `manual`).

#### Epic review — higiena testów

- **Books (HMAI-194).** Konsolidacja dwóch redundantnych klas `CoverUrlTest` (legacy `Domain/` + kanoniczna `Domain/ValueObject/` — ta sama krótka nazwa w dwóch namespace'ach, ten sam VO) do kanonicznej lokalizacji; zachowany mocniejszy dataProvider złośliwych schematów, bez utraty pokrycia.
- **Music (HMAI-195).** `tests-e2e/music.mobile.spec.ts` (Pixel 5 horizontal-overflow guard — Music był ostatnim modułem GUI bez mobilnego speca) + `AlbumArtistTest`/`AlbumTitleTest` (kanoniczne `Domain/ValueObject/`; realna walidacja empty/trim/max-length/equals pokryta dotąd tylko pośrednio).

### Changed

- **CLAUDE.md**: „Status" → 1.16.0; backlog uzupełnienia GUI zamknięty (4/4 epiki).

### Coverage

- **930 PHP tests** (vs 921) — netto +9: podzadania GUI Books/Music dołożyły testy integracyjne (`BooksApiTest` detail/update/delete, `BooksExportApiTest`, `MusicApiTest` history/sessions); epic review Music dodał +14 (VO `AlbumArtist`/`AlbumTitle`), epic review Books skonsolidował redundantny `CoverUrlTest` (−7 zduplikowanych przypadków).
- **52 Playwright** (vs 42) — +10 w `books.desktop`/`books.mobile`/`music.desktop`/`music.mobile` (CRUD/detal/eksport Books, historia/log Music, dwa mobilne overflow guardy).
- **43 Newman** requests (bez zmian — kontrakt REST Books/Music niezmieniony, wszystko addytywne po stronie GUI/testów).
- PHPStan level 8 clean (zero nowych baseline entries). Rector dry-run + CS Fixer + Deptrac + `composer audit` + `npm audit` zielone.

### Documentation

- **Confluence** „Wymagania funkcjonalne" już dokumentuje funkcjonalnie moduły Books (edycja/usuwanie/szczegóły/eksport) i Music (historia odsłuchów/ręczny zapis) — strona aktualna, bez zmian (governance: opis zdolności funkcjonalnych, nie warstwy GUI).

### Migration

- Brak — release czysto frontendowy plus testy. Bez zmian schematu DB, bez nowych kluczy `.env.local`, bez operacji destrukcyjnych.

### Closed Jira

- **Epic HMAI-194** — Books — uzupełnienie GUI (edycja, usuwanie, szczegóły, eksport, dodawanie bez ISBN).
- **Epic HMAI-195** — Music — uzupełnienie GUI (historia odsłuchów, ręczny zapis sesji).
- **HMAI-212, HMAI-213, HMAI-214, HMAI-215, HMAI-216** — 5 podzadań Books GUI.
- **HMAI-217, HMAI-218** — 2 podzadania Music GUI.

## [1.15.0] — 2026-06-20

Domknięcie epica **HMAI-193** (Articles — uzupełnienie GUI) — 7 podzadań + epic review. Moduł Articles dostaje pełny panel zarządzania nad istniejącym REST API: dodawanie, edycja, usuwanie (z potwierdzeniem), widok szczegółów (modal), eksport CSV/PDF, import z CSV/Pocket przez upload oraz filtr read/unread (obok istniejącego filtra kategorii) — wszystko na torze Twig + vanilla JS. Jedyna zmiana po stronie PHP to nowy endpoint `POST /api/articles/import` (self-service import w GUI zamiast wyłącznie CLI); reszta podzadań to czysta warstwa frontu nad gotowym REST. Epic review dołożył mobilny spec E2E (Pixel 5 horizontal-overflow guard) — Articles był ostatnim modułem GUI bez mobilnego speca; layout okazał się już responsywny (`flex-wrap` na `.article-row`/`.header-actions`), więc bez zmian CSS. **921/921 PHP** (+6) + **42/42 Playwright** (+7) + **43 Newman** requests — wszystko zielone. PHPStan level 8 clean (zero nowych baseline entries).

### Added

#### Articles — panel GUI (Twig + vanilla JS nad istniejącym REST)

- **Dodawanie artykułu (HMAI-205).** Formularz „New Article" → `POST /api/articles`; po sukcesie odświeżenie listy + info banner.
- **Edycja artykułu (HMAI-206).** Modal pre-fill z wiersza → `PUT /api/articles/{id}` (tytuł/kategoria/czas czytania; URL niezmienny); walidacja 422 w error bannerze.
- **Usuwanie artykułu (HMAI-207).** `DELETE /api/articles/{id}` z `confirm()` i odświeżeniem listy.
- **Eksport CSV/PDF (HMAI-208).** Przyciski „Export CSV"/„Export PDF" → `GET /api/articles/export?format=` z autoryzowanym pobraniem pliku (blob download, wzorzec z panelu Tasks).
- **Filtr read/unread (HMAI-209).** Select All/Unread/Read łączony client-side z istniejącym filtrem kategorii; po `mark as read` lista respektuje aktywny filtr.
- **Widok szczegółów (HMAI-210).** Modal read-only (URL, kategoria, czas czytania, data dodania, status) z `GET /api/articles/{id}`; XSS-safe (`textContent`/`escHtml`).
- **Import z CSV/Pocket w GUI (HMAI-211).** Upload pliku (`POST /api/articles/import`, multipart `file`/`encoding`/`dry_run`) z auto-detekcją kodowania i trybem dry-run; wynik (imported/skipped/errors) w panelu — self-service zamiast wyłącznie komendy CLI.
- **Mobilny E2E + audyt responsywności (HMAI-193 epic review).** `tests-e2e/articles.mobile.spec.ts` (Pixel 5 horizontal-overflow guard — Articles był jedynym modułem GUI bez mobilnego speca). Guard przechodzi bez zmian CSS — istniejący `flex-wrap` na wierszu i nagłówku już zawija 4 przyciski akcji oraz selektory na ~393px.

### Changed

- **`POST /api/articles/import` (HMAI-211).** Nowy endpoint REST nad istniejącym `ArticleImporter` (Application service) — reużycie logiki parsowania/dedup z CLI w warstwie HTTP dla GUI (multipart upload, opcjonalne kodowanie, dry-run).
- **CLAUDE.md**: „Status" → 1.15.0.

### Coverage

- **921 PHP tests** (vs 915) — +6 w `ArticlesImportApiTest` (endpoint import: persist+counts, brak pliku→422, duplikaty pominięte, dry-run bez persist, nieobsługiwane kodowanie→422, błędny wiersz→error). Pozostałe podzadania to front nad pokrytym już REST.
- **42 Playwright** (vs 35) — +7 w `articles.desktop.spec.ts` (6: create, detail, edit, export, delete, filtr read/unread) i `articles.mobile.spec.ts` (1: Pixel 5 overflow guard).
- **43 Newman** requests (bez zmian — kontrakt REST Articles bez zmian poza addytywnym endpointem import).
- PHPStan level 8 clean (zero nowych baseline entries). Rector dry-run + CS Fixer + Deptrac + `composer audit` + `npm audit` zielone.

### Documentation

- **Confluence** „Articles — Pocket CSV import, »Today« picker, REST CRUD" — dopisana sekcja „Panel GUI" (CRUD/eksport/import/filtr/szczegóły), endpointy `PUT /api/articles/{id}` i `POST /api/articles/import` w tabeli endpointów, nota o imporcie GUI vs CLI oraz responsywności mobilnej.

### Migration

- Brak — release czysto frontendowy plus addytywny endpoint `POST /api/articles/import`. Bez zmian schematu DB, bez nowych kluczy `.env.local`, bez operacji destrukcyjnych.

### Closed Jira

- **Epic HMAI-193** — Articles — uzupełnienie GUI (CRUD, eksport, filtr read/unread, szczegóły, import).
- **HMAI-205, HMAI-206, HMAI-207, HMAI-208, HMAI-209, HMAI-210, HMAI-211** — 7 podzadań GUI.

## [1.14.0] — 2026-06-18

Domknięcie epica **HMAI-192** (Tasks — panel GUI zarządzania zadaniami) — 9 podzadań GUI + dwa cross-cutting chore (**HMAI-222** pin webpack-cli, **HMAI-223** docs governance) + epic review. Moduł Tasks dostaje pełny panel zarządzania nad istniejącym REST API: lista zadań, tworzenie, edycja, usuwanie, oznaczanie ukończone/anulowane, filtr statusu, podgląd szczegółów (modal) i eksport CSV/PDF — wszystko na torze Twig + vanilla JS. Epic review dołożył mobilny spec E2E (Pixel 5) i naprawił rzeczywisty defekt responsywności (tabela zadań przepełniała viewport 393px → układ etykietowanych kart na mobile). Bez zmian w modelu domenowym ani w PHP — czysty zysk GUI. **915/915 PHP** (bez zmian vs 1.13.0) + **35/35 Playwright** (+12) + **43 Newman** requests — wszystko zielone. PHPStan level 8 clean (zero nowych baseline entries).

### Added

#### Tasks — panel GUI (Twig + vanilla JS nad istniejącym REST)

- **Lista zadań (HMAI-196).** `GET /api/tasks` renderowane w tabeli z badge statusu (pending/completed/cancelled); akcje per-wiersz zależne od statusu; pusta lista → explicit empty-state zamiast spinnera; błąd listy → wspólny error banner.
- **Tworzenie zadania (HMAI-197).** Formularz „New Task" (tytuł + start/end) → `POST /api/tasks`; po sukcesie odświeżenie listy + info banner.
- **Edycja zadania (HMAI-198).** Modal pre-fill z wiersza → `PATCH /api/tasks/{id}`; edytowalne tylko `pending` (completed/cancelled immutable w domenie); aktualizacja wiersza in place.
- **Usuwanie zadania (HMAI-199).** `DELETE /api/tasks/{id}` z `confirm()`.
- **Oznaczenie ukończone (HMAI-200).** `POST /api/tasks/{id}/complete`; flip badge, akcje stanu znikają.
- **Oznaczenie anulowane (HMAI-201).** `POST /api/tasks/{id}/cancel` z `confirm()`.
- **Filtr statusu (HMAI-202).** Select pending/completed/cancelled → re-query `GET /api/tasks?status=`.
- **Podgląd szczegółów (HMAI-203).** Modal read-only (tytuł, status, start/end, czas trwania, stan sync z Google Calendar) z `GET /api/tasks/{id}`.
- **Eksport CSV/PDF (HMAI-204).** Przyciski → `GET /api/tasks/export?format=csv|pdf` (file download).
- **Responsywność mobilna + mobilny E2E (HMAI-192 epic review).** `tests-e2e/tasks.mobile.spec.ts` (Pixel 5 horizontal-overflow guard — Tasks był jedynym modułem GUI bez mobilnego speca). Na ≤480px `#tasks-table` składa wiersze w etykietowane karty (`data-label` + media query, scoped wyłącznie do tabeli zadań) — pełny detal nadal dostępny przez modal szczegółów.

### Changed

- **Pin webpack-cli 6.x (HMAI-222).** Dependabot podbił `webpack-cli` do 7.0.3 (niezgodny z peer-dep `@symfony/webpack-encore@6` → `ERESOLVE` w `npm ci`, czerwone CI w jobach budujących assety). Revert do `^6.0.1` + reguła `ignore` na major w `.github/dependabot.yml` (zapobiega nawrotowi do czasu bumpa Encore).
- **Docs governance (HMAI-223).** Strip komentarzy prozą z całego repo (PHP/JS/E2E/config — zachowane PHPDoc z anotacjami typów wymagane przez PHPStan level 8), parafraza usuwanej wiedzy do Confluence (generycznie, bez kluczy HMAI-\*), scrub referencji HMAI-\* z CLAUDE.md (informacja o zadaniach wyłącznie w CHANGELOG).
- **CLAUDE.md**: „Status" → 1.14.0.
- **chore**: skill `/start-task` — tryb sugestii (3 tickety: najszerszy / najwęższy / najbardziej blokujący scope) gdy wywołany bez klucza.

### Coverage

- **915 PHP tests** (bez zmian vs 1.13.0) — epic Tasks GUI to warstwa frontu nad istniejącym REST; backend (agregat, VO, handlery komend, integracja CRUD/export/time-report) był już pokryty we wcześniejszych wydaniach.
- **35 Playwright** (vs 23) — +12 w `tasks.desktop.spec.ts` (11: lista, create, complete, cancel, edit, delete, filtr statusu, export CSV, modal szczegółów + empty-state + error banner) i `tasks.mobile.spec.ts` (1: Pixel 5 overflow guard).
- **43 Newman** requests (bez zmian — REST Tasks bez zmian kontraktu).
- PHPStan level 8 clean (zero nowych baseline entries). Rector dry-run + CS Fixer + Deptrac + `composer audit` + `npm audit` zielone.

### Documentation

- Confluence: nowa strona „Tasks — GUI panel" (#73629698).

### Migration

1. `make assets-prod` — rebuild globalnego `app.css` (responsywna tabela Tasks na mobile). `tasks.js` serwowany statycznie z `public/js/` (bez buildu).
2. Brak migracji DB, brak nowych kluczy `.env.local`, brak operacji destrukcyjnych.

### Closed Jira

Epic **HMAI-192** + HMAI-196, HMAI-197, HMAI-198, HMAI-199, HMAI-200, HMAI-201, HMAI-202, HMAI-203, HMAI-204, HMAI-222, HMAI-223.

## [1.13.0] — 2026-06-15

Domknięcie epica **HMAI-178** (Series — domknięcie braków MVP modułu) — 16 podzadań + bug **HMAI-219** + dwa follow-upy (**HMAI-220** import ocen z Trakt, **HMAI-221** kolorowanie kart). Moduł Series wychodzi z MVP do dojrzałego trackera seriali: pełny CRUD (usuwanie + edycja serial/sezon/odcinek z kaskadą), realny numer odcinka, flaga „obejrzane", własna ocena sezonu/serialu + jej czyszczenie, metadane katalogowe (poster/rok/status/opis), wzbogacona lista (wyszukiwarka + sortowanie + oceny na kartach + kolorowanie rozbieżności/niekompletności) oraz jednokierunkowy import biblioteki z Trakt.tv (OAuth2 + szyfrowany token, obejrzane odcinki z realną datą, oceny 1–10) offloadowany na Messenger. **915/915 PHP** (+149 vs 1.12.0) + **23/23 Playwright** (+9) + **43 Newman** requests — wszystko zielone. PHPStan level 8 clean (zero nowych baseline entries).

### Added

#### Series — CRUD i model danych

- **Usuwanie serialu / sezonu / odcinka (HMAI-185).** `DELETE /api/series/{id}`, `.../seasons/{seasonId}`, `.../episodes/{episodeId}` (wszystkie 204, 404 gdy brak). Kaskada jawnie w repo (`EntityManager::remove` + flush — agregat nie ma asocjacji ORM, encje persystowane ręcznie przez string-FK). Handlery inwalidują Redis `series:avg:{id}` / `season:avg:{id}`. UI: przyciski kosza (🗑) z `confirm()`.
- **Edycja tytułu serialu / numeru sezonu / tytułu odcinka (HMAI-186).** `PATCH` per byt (204; 422 dla pustego/`>255` tytułu lub numeru `<1`). Renumber sezonu na numer już zajęty → **409 Conflict** (dedykowany `SeasonNumberAlreadyTaken extends DomainException`). UI: inline-edit (klik → input, Enter/blur zapis, Esc anuluj).
- **Realny numer odcinka (HMAI-187).** `Episode::number` (kolumna `number`, unikatowy w sezonie, walidacja w `Season::addEpisode()` → 422 przy duplikacie). Migracja backfilluje istniejące odcinki `ROW_NUMBER() OVER (PARTITION BY season_id ORDER BY id)`, potem `MODIFY ... NOT NULL`. Odczyt sortuje po `number`, UI renderuje realny numer (nie indeks pętli).
- **Flaga „obejrzane" odcinka (HMAI-188).** `Episode.watched` (bool NOT NULL DEFAULT 0) + nullable `watchedAt`. `PATCH .../episodes/{id}/watched` (204; 422 dla nie-boola). `GET /api/series/{id}` zwraca per-odcinek `watched`/`watchedAt` + liczniki `watchedCount`/`episodeCount` na poziomie sezonu i serialu. UI: kolumna „Watched" + licznik `x/y watched` w nagłówku sezonu.

#### Series — ocenianie i panel

- **Ocena istniejącego odcinka z web UI (HMAI-177).** Selektor 10 przycisków w wierszu odcinka.
- **Własna ocena sezonu i całego serialu (HMAI-179).** `Series`/`Season` mają własny, opcjonalny `?Rating` (kolumna `rating_value`), niezależny od średniej z odcinków. Komendy `RateSeries`/`RateSeason`, `PATCH .../rating`. Kontrolki „My rating" w nagłówku serialu i sezonu.
- **Czyszczenie własnej oceny (HMAI-191).** `PATCH .../rating` z `{rating:null}` → 204 czyści (`Series::clearRating()` / `clearSeasonRating()`). UI: przycisk „✕" przy ustawionej ocenie.
- **Lista: wyszukiwarka + sortowanie + własna ocena na karcie (HMAI-189).** Frontend-only — `GET /api/series` zwraca komplet. Toolbar (input + select), filtr po tytule na żywo, sort (`title` / `rating-desc` / `own-desc` / `created-desc`). Karta pokazuje rozłączne „My" i „Avg".
- **Metadane katalogowe (HMAI-190).** `Series` zyskuje opcjonalne `coverUrl` (VO `CoverUrl`), `year`, `status` (enum `ongoing`/`ended` przez custom DBAL type `series_status`), `description`. Miniatura postera na karcie + poster/rok/status/opis w nagłówku detalu, formularz „✎ Edit details" (PATCH partial-safe).
- **Kolorowanie kart/sezonów wg ocen (HMAI-221).** Frontend-only: **bursztyn** gdy serial/sezon niekompletny (nie wszystkie odcinki obejrzane → średnia cząstkowa), **czerwony** gdy własna ocena ≠ zaokrąglona średnia z odcinków (pierwszeństwo: bursztyn > czerwony). Tooltipy `title` (a11y — kolor nie jest jedynym nośnikiem).

#### Series — import z Trakt.tv (jednokierunkowy Trakt → AIHM, offload na Messenger)

- **OAuth2 + szyfrowany token (HMAI-180).** Flow `/auth/trakt` + `/auth/trakt/callback`, `TraktOAuthTokenRepository` szyfruje cały `token_json` przez `app.trakt_token_cipher` (osobny klucz `TRAKT_TOKEN_KEY`). `TraktTokenProvider` robi refresh-on-expiry. Tabela `trakt_oauth_tokens` (DBAL, nie-ORM).
- **`TraktApiClient` + limiter `trakt_api` (HMAI-181).** `fetchWatchedShows()` za dekoratorem `app.trakt_http_client` (token_bucket 1000/5min).
- **Dedup-klucz `trakt_id` na Series (HMAI-182).** Nullable unique `trakt_id` (migracja + ORM XML) — klucz idempotencji importu.
- **`ImportWatchedShowsFromTrakt` command + handler (HMAI-183).** Async (RabbitMQ), idempotentny: dedup serialu po `trakt_id`, sezonu po `number`, odcinka po `number`. Mapuje obejrzane odcinki z realną `watchedAt` (`last_watched_at`); pustych seriali/sezonów nie materializuje.
- **Przycisk „Import from Trakt" + endpoint (HMAI-184).** `POST /api/series/import/trakt` → **202** `import_started` (dispatch async), **409** `{authUrl:/auth/trakt}` gdy brak tokenu (przed dispatchem). UI: banner info/error.
- **Import ocen z Trakt (HMAI-220).** `TraktApiClient::fetchRatings()` (3× `/sync/ratings/{shows,seasons,episodes}`) + port `RatingsProviderInterface`. Komenda `ImportRatingsFromTrakt` (async) **chainowana na końcu** importu obejrzanych: mapuje oceny 1–10 na własne oceny agregatu, **skip-if-missing** + idempotentnie. Oceny odcinków świadomie **nie** dispatchują `EpisodeRated` (bulk = tysiące eventów; średnie liczone live).

#### Series — odporność

- **`/series` resilient (HMAI-176).** GELF logging graceful-degrade (`IgnoreErrorTransportWrapper` — brak Graylog ≠ 500) + wymóg API key na mutacjach Series.

### Changed

- **Bug HMAI-219 — `Import from Trakt` zwracał 500.** Przyczyna konfiguracyjna: `TRAKT_TOKEN_KEY` w `.env.local` był hex zamiast base64 → `TokenCipher` (libsodium, wymaga 32B) rzucał już przy odczycie tokenu. Fix commitowalny: `scripts/doctor.sh` waliduje `TRAKT_TOKEN_KEY` w pętli kluczy cipherów (preflight łapie zły format przed runtime). Endpoint zwraca teraz 409, nie 500.
- **Mapowanie `?Rating` — nullable embeddable → custom DBAL type `series_rating` (`RatingType`, HMAI-220).** Nullable embeddable hydruje się jako non-null obiekt z niezainicjalizowanym `$value` przy kolumnie NULL — wywalało każdy odczyt zhydratyzowanego VO (import ocen). Custom type round-tripuje `null` czysto. Kolumna bez zmian (`INTEGER` nullable) → **brak migracji**.
- **CLAUDE.md**: rozbudowane sekcje Series (CRUD, watched, realny numer, metadane, lista, import obejrzanych + ocen, kolorowanie), nowy custom type `series_rating`, sekcje Trakt OAuth + import w „Encryption" / „Rate limiting"; „Status" → 1.13.0.
- **chore**: nowy skill `/create-task` (struktura zadań Jira: 5 sekcji + fixVersion/epic), skill `/start-task` (problem-analysis comment dla bugów), nginx healthcheck probe `127.0.0.1` (unika flakiness DNS/IPv6 w VM).

### Coverage

- **915 PHP tests** (vs 766 at 1.12.0) — +149 w pełnym pokryciu epiku Series: agregat (CRUD/watched/rating/metadane), VO (`CoverUrl`, `SeriesStatus`, `RatingType`), `TraktApiClient` (watched + ratings z mockiem HTTP), handlery importu (świeży/idempotencja/skip-missing/chain), kontroler API, routing piny (`ImportWatchedShowsRoutingTest`, `ImportRatingsRoutingTest`), `TraktTokenProviderTest`, `TraktAuthControllerTest`.
- **23 Playwright** (vs 14) — +9 w `series.desktop.spec.ts`: import 202/409 (info banner / connect link), lista filtr+sort, kolorowanie mismatch/incomplete, edycja inline, watched toggle, delete, realny numer.
- **43 Newman** requests (bez zmian) — dociągnięte do kontraktu (wymagany `number` odcinka, HMAI-187).
- PHPStan level 8 clean (zero nowych baseline entries). Rector dry-run + CS Fixer + Deptrac + `composer audit` + `npm audit` zielone.

### Documentation

- Confluence: Series Domain v3 (#50069505), Application/CQRS v3 (#49479682), nowa strona „Import z Trakt" (#71925762).

### Migration

1. `make migrate` + `make migrate-test` — 6 migracji:
    - `Version20260611000001` — `rating_value` (własna ocena) na `series` + `series_seasons`.
    - `Version20260612000001` — tabela `trakt_oauth_tokens` (Trakt OAuth2).
    - `Version20260612000002` — nullable unique `trakt_id` na `series` (dedup importu).
    - `Version20260612000003` — `watched` (TINYINT NOT NULL DEFAULT 0) + `watched_at` na `series_episodes`.
    - `Version20260612000004` — `number` na `series_episodes` (backfill per sezon `ROW_NUMBER`, potem `NOT NULL`).
    - `Version20260613000001` — `cover_url` / `year` / `status` / `description` (metadane) na `series`.
2. **ENV (`.env.local`):** `TRAKT_CLIENT_ID`, `TRAKT_CLIENT_SECRET`, `TRAKT_REDIRECT_URI` + `TRAKT_TOKEN_KEY` (base64 32B — `php -r "echo base64_encode(sodium_crypto_secretbox_keygen());"`). User raz przechodzi `/auth/trakt` by połączyć konto.
3. **Po każdej zmianie `*_TOKEN_KEY` — restart workerów** (`docker compose restart messenger_worker scheduler_worker`; długo-żyjący proces cache'uje env przy starcie).
4. `make assets-prod` — rebuild `series_controller.js` (Stimulus).

### Closed Jira

Epic **HMAI-178** + HMAI-176, HMAI-177, HMAI-179, HMAI-180, HMAI-181, HMAI-182, HMAI-183, HMAI-184, HMAI-185, HMAI-186, HMAI-187, HMAI-188, HMAI-189, HMAI-190, HMAI-191, HMAI-219 (Błąd), HMAI-220, HMAI-221.

## [1.12.0] — 2026-06-10

Domknięcie epica **HMAI-160** (YouTubeProgress — manager watchlisty z auto-podziałem na sesje 30-min) — 13/13 podzadań. Szósty moduł aplikacji: synchronizuje playlistę "AIHM Watchlist" z YouTube do lokalnej tabeli `videos`, deterministycznie pakuje nieobejrzane filmy w sesje ≤30 min pogrupowane po kanale (`WatchSessionSplitter`), śledzi postęp (`started`/`watched`) i potrafi wypchnąć wygenerowaną sesję z powrotem na YouTube jako nową playlistę. Pełna heksagonalna ścieżka (Domain → Application CQRS → Infrastructure) z dwoma agregatami (`Video`, `WatchSession`), poszerzeniem scope'u Google OAuth o `youtube` (read/write), klientem YouTube Data API v3 (read + write) za rate-limiterem, oraz panelem frontowym `/youtube-progress` (Webpack Encore + Stimulus). **766/766 PHP** (+121 vs 1.11.1) + **14/14 Playwright** (+5) + **43 Newman** requests / 87 assertions (vs 36/66). PHPStan level 8 clean.

### Added

- **YouTubeProgress Domain — `Video` aggregate (HMAI-161).** Szkielet modułu `src/Module/YouTubeProgress/{Domain,Application,Infrastructure}` + agregat `Video` z VO (`VideoId`, `Duration`, `WatchStatus`) i unit testami. Domain bez frameworka (Deptrac `YouTubeProgressDomain → []`).
- **`Video` persistence (HMAI-162).** Doctrine XML mapping (`Infrastructure/Persistence/Doctrine/*.orm.xml`), `DoctrineVideoRepository`, migracja `Version20260606000001` → tabela `videos`.
- **Google OAuth `youtube` scope (HMAI-163).** `GoogleClientFactory::create()` requestuje kumulatywnie `calendar.events` (Tasks) + `youtube` (full read/write — wymagane przez `createPlaylist` w HMAI-171), `setPrompt('consent')` wymusza re-consent. Jeden token szyfrowany przez `app.google_token_cipher`, dwa moduły. Regresja: `GoogleClientYouTubeScopeTest`.
- **YouTube Data API client — read (HMAI-164).** `fetchPlaylistVideos()` — pobiera pozycje playlisty watchlisty (paginacja, mapowanie na Domain). Za dekoratorem `app.youtube_http_client` (`RateLimitedHttpClient`).
- **`SyncWatchlist` command + handler (HMAI-165).** Zaciąga playlistę z YouTube i upsertuje do `videos` (idempotentnie po `VideoId`). 400 gdy `YOUTUBE_WATCHLIST_PLAYLIST_ID` puste.
- **`WatchSession` aggregate (HMAI-166).** Drugi agregat + VO (`SessionId`, `SessionDuration`) z regułą ≤30 min i unit testami.
- **`WatchSession` persistence (HMAI-167).** Doctrine XML + `DoctrineWatchSessionRepository`, migracja `Version20260607000001` → tabele `watch_sessions` + `watch_session_videos`.
- **`WatchSessionSplitter` (HMAI-168).** Czysty algorytm (bez I/O): deterministyczny, channel-grouped greedy packer — grupuje nieobejrzane filmy po kanale i pakuje w sesje ≤30 min. Pokryty unit testami na granicach (overflow, single video > 30 min, kolejność deterministyczna).
- **`RegenerateSessions` command + handler (HMAI-169).** Przebudowuje sesje z aktualnego stanu watchlisty przez `WatchSessionSplitter` (wywoływane razem z `SyncWatchlist` z endpointu `/sync`).
- **`MarkVideoStarted` + `MarkVideoWatched` commands + handlers (HMAI-170).** Przejścia stanu `WatchStatus` na agregacie `Video` (404/idempotencja w handlerach).
- **YouTube Data API client — write (HMAI-171).** `createPlaylist()` + `addItems()` — tworzy nową playlistę na koncie YouTube i dorzuca filmy sesji.
- **`PushSessionToYouTube` command + handler (HMAI-172).** Materializuje wybraną `WatchSession` jako realną playlistę YouTube przez klienta write.
- **Frontend panel `/youtube-progress` (HMAI-173).** `YouTubeProgressController` (`^/api/youtube-progress/*`): `GET watchlist` + `GET sessions` czytają wprost przez Domain repos (brak query layer); `POST sync`, `POST videos/{id}/start|watched`, `POST sessions/{id}/push-to-youtube` dispatchują command handlery (unwrap przez `ApiExceptionListener`). UI: `assets/controllers/youtube_progress_controller.js` (Stimulus) na `templates/youtube_progress/index.html.twig`, route przez `FrontendController`, nav jak reszta. Nowy limiter `youtube_api` (token_bucket, 60/min — soft HTTP fallback pod unit-based quota 10000/dzień).

### Changed

- **Zależności (Dependabot).** Bump `webpack` 5.106.2 → 5.107.2, `@babel/core` 7.29.0 → 7.29.7, `@babel/preset-env` 7.29.5 → 7.29.7, `regenerator-runtime` 0.13.11 → 0.14.1 (`/app`); `actions/checkout` 4 → 6, `actions/cache` 4 → 5, `actions/upload-artifact` 4 → 7 (GitHub Actions). `webpack-cli` świadomie przypięty na `^6` (cofnięty z auto-bumpu 7.0.3) — `@symfony/webpack-encore@6` ma peer dependency na webpack-cli 6.x; 7.x wywalał build.
- **Books — Postman smoke alignment.** Newman smoke dla Books dociągnięty do faktycznego kontraktu API (drobna korekta asercji, bez zmian w kodzie produkcyjnym).
- **CLAUDE.md**: nowy moduł YouTubeProgress w opisie stacku/frontendu, sekcja panelu `/youtube-progress`, tabela Stimulus controllers + `youtube_progress_controller.js`, limiter `youtube_api` + `app.youtube_http_client`, OAuth scope `youtube` na tokenie Google, "Wydania" → 1.12.0.

### Coverage

- **766 PHP tests** passing (vs 645 at 1.11.1) — +121 nowych w pełnym pokryciu modułu YouTubeProgress: unit testy obu agregatów + VO, `WatchSessionSplitter` (algorytm na granicach), handlery (command + sync), klient YouTube API (read + write z mockiem HTTP), persistence repo (integration), kontroler API (`YouTubeProgressControllerTest`) i regresja scope'u OAuth.
- **14 Playwright** (vs 9 at 1.11.1) — +5: `youtube-progress.desktop.spec.ts` (4 scenariusze: panel render, sync, start/watched, push) + `youtube-progress.mobile.spec.ts` (1).
- **43 Newman** requests / 87 assertions (vs 36/66) — +7 requestów: smoke YouTubeProgress API (watchlist/sessions/sync/start/watched/push).
- PHPStan level 8 clean, baseline bez nowych entries. Rector dry-run + CS Fixer + Deptrac + `composer audit` + `npm audit` wszystkie zielone.

### Documentation

- **Confluence id 69926915** — strona modułu YouTubeProgress (epic review): architektura heksagonalna, oba agregaty, algorytm `WatchSessionSplitter`, przepływ sync → split → push, kontrakt API, OAuth scope, ENV.
- **CHANGELOG.md**: ta sekcja.
- **CLAUDE.md**: HMAI-160 oznaczone jako zamknięte 2026-06-10; "Wydania" → 1.12.0.

### Migration

1. **DB migration** — `make migrate` (dev) / `make migrate-test`. Tworzy tabele `videos`, `watch_sessions`, `watch_session_videos`. Brak destructive ops.
2. **Nowy ENV** — `YOUTUBE_WATCHLIST_PLAYLIST_ID` w `app/.env.local` (ID playlisty "AIHM Watchlist", np. `PLrAXtmRdnEQy-...`). Puste = endpoint `/sync` zwraca 400. Placeholder dodany w `app/.env`.
3. **OAuth re-consent (KRYTYCZNE)** — po deployu HMAI-163 user **MUSI raz przejść `/auth/google`**, żeby uzyskać token z poszerzonym scope `youtube`. Bez tego każdy call do YouTube API zwróci 403. Scope claims kumulatywne — Tasks/Calendar dalej działa na tym samym tokenie.
4. **Frontend build** — `make assets-prod` (nowy `youtube_progress_controller.js` w bundlu Encore). CI buduje assety w jobach `tests` + `e2e-playwright`.

### Closed Jira

| ID | Tytuł | PR |
|---|---|---|
| [HMAI-161](https://honemanager.atlassian.net/browse/HMAI-161) | YouTubeProgress — Domain skeleton + Video aggregate + VO | [#171](https://github.com/zlotylesk/AIHomeManager/pull/171) |
| [HMAI-162](https://honemanager.atlassian.net/browse/HMAI-162) | YouTubeProgress — Video persistence (Doctrine XML + repo + migracja) | [#172](https://github.com/zlotylesk/AIHomeManager/pull/172) |
| [HMAI-163](https://honemanager.atlassian.net/browse/HMAI-163) | YouTubeProgress — Google OAuth youtube scope expansion | [#178](https://github.com/zlotylesk/AIHomeManager/pull/178) |
| [HMAI-164](https://honemanager.atlassian.net/browse/HMAI-164) | YouTubeProgress — YouTube API client read (fetchPlaylistVideos) | [#180](https://github.com/zlotylesk/AIHomeManager/pull/180) |
| [HMAI-165](https://honemanager.atlassian.net/browse/HMAI-165) | YouTubeProgress — SyncWatchlist command + handler | [#184](https://github.com/zlotylesk/AIHomeManager/pull/184) |
| [HMAI-166](https://honemanager.atlassian.net/browse/HMAI-166) | YouTubeProgress — WatchSession aggregate + VO | [#174](https://github.com/zlotylesk/AIHomeManager/pull/174) |
| [HMAI-167](https://honemanager.atlassian.net/browse/HMAI-167) | YouTubeProgress — WatchSession persistence (XML + repo + migracja) | [#175](https://github.com/zlotylesk/AIHomeManager/pull/175) |
| [HMAI-168](https://honemanager.atlassian.net/browse/HMAI-168) | YouTubeProgress — WatchSessionSplitter (algorytm + unit testy) | [#176](https://github.com/zlotylesk/AIHomeManager/pull/176) |
| [HMAI-169](https://honemanager.atlassian.net/browse/HMAI-169) | YouTubeProgress — RegenerateSessions command + handler | [#181](https://github.com/zlotylesk/AIHomeManager/pull/181) |
| [HMAI-170](https://honemanager.atlassian.net/browse/HMAI-170) | YouTubeProgress — MarkVideoStarted + MarkVideoWatched | [#182](https://github.com/zlotylesk/AIHomeManager/pull/182) |
| [HMAI-171](https://honemanager.atlassian.net/browse/HMAI-171) | YouTubeProgress — YouTube API client write (createPlaylist + addItems) | [#183](https://github.com/zlotylesk/AIHomeManager/pull/183) |
| [HMAI-172](https://honemanager.atlassian.net/browse/HMAI-172) | YouTubeProgress — PushSessionToYouTube command + handler | [#185](https://github.com/zlotylesk/AIHomeManager/pull/185) |
| [HMAI-173](https://honemanager.atlassian.net/browse/HMAI-173) | YouTubeProgress — Frontend /youtube-progress panel | [#186](https://github.com/zlotylesk/AIHomeManager/pull/186) |
| [HMAI-160](https://honemanager.atlassian.net/browse/HMAI-160) | YouTubeProgress watchlist manager (epic review) | [#187](https://github.com/zlotylesk/AIHomeManager/pull/187) |

### Carried forward

- **HMAI-174** — Monitor newman 7.x release; re-enable npm audit gate dla root `package.json` gdy newman wyjdzie z czystym drzewem zależności. Świadomie tracking-only, re-eval Q3 2026.

## [1.11.1] — 2026-06-07

Patch release wycelowany w dwa realne usterki end-userowe odkryte zaraz po wydaniu 1.11.0. Bez nowych modułów, bez DB migrations, bez nowych ENV. **645/645 PHP tests** (+7 nowych w `NationalLibraryApiClientTest`) — Playwright/Newman bez zmian. PHPStan level 8 clean.

### Fixed

- **Books — Biblioteka Narodowa API migration (HMAI-175).** `api.bn.org.pl` zwracał NXDOMAIN — BN wyłącząło stary endpoint, każde dodawanie książki po ISBN przez modal "Add book" wybuchało komunikatem "National Library API is unavailable". `NationalLibraryApiClient::API_URL` przeniesione na `https://data.bn.org.pl/api/bibs.xml`. `parseBib()` przerobiony z Dublin Core (`<dc:title>`, `<dc:creator>`, …) na nowy schemat plain-elements (`<title>`, `<author>`, `<publisher>`, `<publicationYear>`) opakowany w `<resp>` → `<bibs>` → `<bib>`. Pages przeniosło się do osobnego MARC21 namespace — wyciągane przez XPath z `<datafield tag="300"><subfield code="a">`. Tolerancja na warianty BN: `"320 s."`, `"320 s. ;"`, `"200, [4] s."`, `"150 stron"`. Non-paginowane media (CD-ROM, e-booki) → null, handler dalej rzuca grzeczne "fill total_pages manually". XXE guard (HMAI-96) i Redis cache layer nietknięte.
- **Frontend — API key dispatch przez meta tag.** `window.apiCall` w `public/js/util.js` i `apiCall` w `assets/util.js` nie wstrzykiwały headera `X-API-Key`, więc Tasks/Articles/Music/Books bombardowały toast'ami "API key missing" przy każdym wejściu na stronę. Twig eksportuje `%env(API_KEY)%` jako global `api_key`, `base.html.twig` wkłada w `<meta name="api-key" content="…">`, a obie wersje `apiCall` czytają meta-tag i dorzucają header, nie nadpisując jeśli caller już swój ustawił. Brak osobnego ticketu — hotfix chore commit bezpośrednio na develop, świadomie wycięty do tagu 1.11.1.

### Coverage

- **645 PHP tests** passing (vs 638 at 1.11.0) — +7 w `NationalLibraryApiClientTest`: 7-wariantowy `#[DataProvider]` `testExtractsTotalPagesFromMarcDatafield300` plus 1 nowy `testThrowsNotFoundWhenBibHasNoTitle`, minus 1 zlikwidowany `testParsesTotalPagesFromFormatString` (relikt Dublin Core). Pozostałe oryginalne testy BN przepisane na nowy format response'u.
- **9 Playwright** (bez zmian — brak nowych e2e scenariuszy w tym patchu).
- **36 Newman** requests / 66 assertions (bez zmian — brak nowych endpointów).
- PHPStan level 8 clean — usunięty jeden baseline entry dla `makeXml` po dodaniu `@param` typu. Rector dry-run + CS Fixer + Deptrac + `composer audit` + `npm audit` zielone.

### Migration

Brak migracji DB i ENV. Po deploy opcjonalnie wyczyść stale entries w Redis cache (24h TTL i tak je zje):

```bash
docker exec aihm-redis-1 sh -c 'redis-cli --scan --pattern "book:metadata:*" | xargs -r redis-cli del'
```

### Closed Jira

| ID | Tytuł | PR |
|---|---|---|
| [HMAI-175](https://honemanager.atlassian.net/browse/HMAI-175) | Books — migrate National Library API from api.bn.org.pl to data.bn.org.pl | [#177](https://github.com/zlotylesk/AIHomeManager/pull/177) |

## [1.11.0] — 2026-06-06

Domknięcie epica **HMAI-159** (CI hardening, dependency safety, observability & DX quick wins) — 10/10 podzadań. Wydanie maintenance — brak zmian funkcjonalnych, same poprawki "fundamentu": dwie nowe bramki security (`composer audit` + `npm audit`), Dependabot per ekosystem, CI hygiene (concurrency + per-job timeouts), PHPUnit gate na deprecations, 3-state disk probe w `/api/health`, request correlation header z propagacją do logów Monolog, i `make doctor`/`make logs-*` dla onboardingu. 638/638 PHP (+8 vs 1.10.0) + 9/9 Playwright + 36/36 Newman.

### Added

- **`composer audit` CI gate (HMAI-149).** Krok w jobie `static-analysis` po Deptrac — query do FriendsOfPHP/security-advisories. Advisory na zainstalowanej wersji paczki blokuje merge. Lokalnie: `make audit`. Fix = bump dep, nie suppress.
- **`npm audit --audit-level=high` CI gate (HMAI-150).** Krok po każdym `npm ci` w jobach `tests` + `e2e-playwright` (oba w `app/`). Low/moderate dla devDeps przepuszczane jako noise; high+critical blokują merge. Root `package.json` (Playwright + Newman) świadomie poza gate — newman 6.x ciągnie deep-transitive CVE bez forward-fixu. Lokalnie: `make node-audit`. Re-eval w HMAI-174.
- **Dependabot config (HMAI-152).** `.github/dependabot.yml` — 4 ekosystemy: composer (`/app`, weekly Mon z grupami `symfony/*`/`doctrine/*`/dev), npm (`/app` + `/`, weekly), github-actions (`/`, monthly). PR-y od `dependabot[bot]` przechodzą ten sam CI gate co user commits. Komplementarne z audit gates: freshness vs severity-gated regression.
- **Health endpoint disk probe (HMAI-155).** `App\Health\HealthChecker::checkDisk()` z 3-state ratio: `< 80%` → `up`, `80–95%` → `degraded` (HTTP 200 + body sygnal), `> 95%` → `down` (HTTP 503). Thresholdy hardcoded jako consts (`DISK_DEGRADED_RATIO=0.80`, `DISK_DOWN_RATIO=0.95`). Rationale: MySQL buffer pool flush + binlog ginie przy braku headroomu — `degraded` page'uje monitoring przed eskalacją bez drenowania ruchu. `disk_free_space('/')` mierzy overlayfs Dockera (single-volume setup).
- **Request correlation ID (HMAI-158).** `App\EventListener\RequestIdListener` (`kernel.request` priority 256 — przed `ApiRateLimitListener` @100, żeby 429 niosło correlator). Czyta `X-Request-ID` z requestu lub generuje UUID v4. Walidacja inbound regex `^[A-Za-z0-9._-]{1,128}$` (ochrona przed log injection). `App\Logging\RequestIdProcessor` (`#[AsMonologProcessor]`) dodaje `extra.request_id` do każdego `LogRecord` emitowanego w request lifecycle. Worker/CLI context — passthrough. Async propagacja Messenger świadomie poza scope.
- **`make doctor` preflight env check (HMAI-157).** `scripts/doctor.sh` — read-only check: docker daemon up, stan 8 kontenerów, `app/.env.local` istnieje, `DISCOGS_TOKEN_KEY` + `GOOGLE_TOKEN_KEY` base64-decoded length = 32. Onboarding na nowym laptopie skraca się z 15 min manual debug do jednej komendy.
- **Per-service logs Makefile targets (HMAI-156).** `make logs-{php,nginx,mysql,redis,rabbitmq,worker,scheduler,node}` — shortcuty zamiast `make logs` (cały stack). Mapping `logs-worker` → `messenger_worker`, `logs-scheduler` → `scheduler_worker` (jak się czyta na głos).

### Changed

- **CI concurrency (HMAI-151).** Workflow ma top-level `concurrency` block grupujący po `github.ref` z `cancel-in-progress: true`. Push nowego commita na ten sam branch ubija poprzedniego runa. Oszczędza minuty CI i daje feedback na najnowszym kodzie.
- **CI job timeouts (HMAI-154).** Każdy job ma explicit `timeout-minutes`: `static-analysis: 10`, `tests: 15`, `e2e-playwright: 20`, `e2e-newman: 10`. Cap ≈ 2-3× obserwowanego peaku. Default GitHub Actions to 360 min — runaway/deadlock bez bound zjada cały budżet darmowych minut na pojedynczy hang.
- **PHPUnit deprecation gate (HMAI-153).** `phpunit.dist.xml` ma `failOnDeprecation="true"` + `failOnPhpunitDeprecation="true"` + `failOnNotice="true"` + `failOnWarning="true"`. Nowe PHP deprecations w `src/` ORAZ deprecations samego PHPUnit (`->expects(self::any())`, `with()` bez `expects()`) blokują CI. `<source>` ma `ignoreIndirectDeprecations="true"` — vendor noise (`google/apiclient`) świadomie filtrowany. Pierwsza ofiara gate'u: `UpdateTaskHandlerTest::any()` → `once()` (preserves the with-constraint).
- **CLAUDE.md**: nowe sekcje per ticket — Health endpoint (3-state), Request correlation, CI timeouts/concurrency, npm audit gate; tabela komend Makefile rozszerzona o `make doctor`, `make logs-*`, `make node-audit`; "Wydania" → 1.11.0; sekcja Static Analysis dopisuje Composer audit + Dependabot.

### Coverage

- **638 PHP tests** passing (vs 630 at 1.10.0) — +8 nowych: 4 w `HealthCheckerTest` + `HealthControllerTest` (3-state disk probe paths), 4 w `RequestIdListenerTest` (UUID generation, valid echo, invalid replace via regex guard, `LogRecord.extra.request_id` carry).
- **9 Playwright** (bez zmian — wydanie nie dotyka frontendu).
- **36 Newman** requests / 66 assertions (bez zmian — brak nowych endpointów).
- PHPStan level 8 clean, baseline bez nowych entries. Rector dry-run + CS Fixer + Deptrac + `composer audit` + `npm audit` wszystkie zielone w CI.

### Documentation

- **Confluence id 52658177** "Code quality — narzędzia i bramki CI" — v6. Tabela bramek CI rozszerzona o `composer audit` (static-analysis) i `npm audit` (tests + e2e), nowa sekcja "Security — automated dependency scanning" z Dependabot, "CI hygiene — concurrency & cancel-in-progress", `PHPUnit deprecation gate`, lokalne komendy uzupełnione o `make audit` i `make node-audit`.
- **Confluence id 49119253** "Graylog and OpenSearch — centralized logging" — v5. Nowa sekcja "Request correlation — X-Request-ID + request_id" — przepływ Listener → Processor, walidacja regex, graceful degrade w CLI/worker, async propagacja jako future work, przykłady query w Graylog.
- **Confluence id 68812803** "Health endpoint — /api/health readiness probe" — nowa strona. Kontrakt JSON, tabela HTTP semantics, 3-state disk probe, Docker healthcheck, API key/rate limit bypass, regresja testowa.
- **CHANGELOG.md**: ta sekcja.
- **CLAUDE.md**: HMAI-159 oznaczone jako zamknięte 2026-06-06; "Wydania" → 1.11.0.

### Migration

Brak. Wydanie maintenance — brak nowych ENV, brak DB migrations, brak destructive ops. Zmiany dotykają tylko CI (`.github/`), config (`phpunit.dist.xml`, `composer.lock`, `package-lock.json`), `Makefile`, dwóch listenerów Symfony, jednego processora Monolog i dwóch metod w `HealthChecker`.

### Closed Jira

| ID | Tytuł | PR |
|---|---|---|
| [HMAI-149](https://honemanager.atlassian.net/browse/HMAI-149) | composer audit CI gate | [#153](https://github.com/zlotylesk/AIHomeManager/pull/153) |
| [HMAI-150](https://honemanager.atlassian.net/browse/HMAI-150) | npm audit CI gate for frontend deps | [#156](https://github.com/zlotylesk/AIHomeManager/pull/156) |
| [HMAI-151](https://honemanager.atlassian.net/browse/HMAI-151) | CI concurrency cancel in-progress | [#154](https://github.com/zlotylesk/AIHomeManager/pull/154) |
| [HMAI-152](https://honemanager.atlassian.net/browse/HMAI-152) | Dependabot config | [#158](https://github.com/zlotylesk/AIHomeManager/pull/158) |
| [HMAI-153](https://honemanager.atlassian.net/browse/HMAI-153) | PHPUnit failOnPhpunitDeprecation gate | [#162](https://github.com/zlotylesk/AIHomeManager/pull/162) |
| [HMAI-154](https://honemanager.atlassian.net/browse/HMAI-154) | Timeout-minutes per CI job | [#159](https://github.com/zlotylesk/AIHomeManager/pull/159) |
| [HMAI-155](https://honemanager.atlassian.net/browse/HMAI-155) | Health endpoint disk space probe (3-state) | [#157](https://github.com/zlotylesk/AIHomeManager/pull/157) |
| [HMAI-156](https://honemanager.atlassian.net/browse/HMAI-156) | Per-service logs Makefile targets | [#160](https://github.com/zlotylesk/AIHomeManager/pull/160) |
| [HMAI-157](https://honemanager.atlassian.net/browse/HMAI-157) | make doctor preflight env check | [#161](https://github.com/zlotylesk/AIHomeManager/pull/161) |
| [HMAI-158](https://honemanager.atlassian.net/browse/HMAI-158) | Request correlation header + Monolog processor | [#155](https://github.com/zlotylesk/AIHomeManager/pull/155) |
| [HMAI-159](https://honemanager.atlassian.net/browse/HMAI-159) | 1.11.0 epic — quick wins (epic review) | — |

### Carried forward

- **HMAI-174** — Monitor newman 7.x release; re-enable npm audit gate dla root `package.json` gdy newman wyjdzie z czystym drzewem zależności. Świadomie tracking-only.

## [1.10.0] — 2026-06-01

Domknięcie epica **HMAI-145** (Application audit follow-up — features, hardening, DevOps) — 12/12 podzadań. Pierwszy release po HMAI-44 maintenance milestone, niosący nową funkcjonalność (Tasks REST CRUD z Google Calendar, PDF export, lokalny aggregate listening sessions), drugi moduł na Encore track (Books), automatyzację DevOps (backup MySQL, retencja Graylog, CI E2E jobs, formalizacja granic Deptrac) i defense-in-depth security headers. 630/630 PHP (+88 vs 1.9.0) + 9/9 Playwright (+4) + 36/36 Newman (+2). PHPStan level 8 clean (zero new baseline entries).

### Added

- **Tasks REST CRUD + Google Calendar sync (HMAI-135).** Pełny REST `POST/GET/GET{id}/PATCH{id}/DELETE{id} /api/tasks` + `POST {id}/complete` + `POST {id}/cancel`. Google Calendar sync przez port domenowy `CalendarServiceInterface` z graceful degrade (degraded mode loguje warning zamiast wywalać request gdy Google API niedostępne). Pierwszy moduł korzystający z `EventDispatcherInterface` poza Series.
- **PDF export dla Books/Articles/Tasks (HMAI-138).** Endpointy `/api/{books,articles,tasks}/export?format=pdf` (CSV pozostaje default). Backend: `App\Pdf\PdfBuilder` wrap nad `dompdf/dompdf` (~3.1). Twig templates `exports/{books,articles,tasks}_pdf.html.twig`. Domyka deferred scope HMAI-132 (CSV-only w 1.9.0).
- **Music local listening sessions aggregate (HMAI-144).** Scheduler poll Last.fm `user.getRecentTracks` co 30 min → dispatch `LogListeningSession` per track na sync `command.bus`. Idempotencja przez `dedup_hash` (SHA256 z artist+album+title+timestamp). Lokalna tabela `listening_session` staje się autorytatywną historią — endpoint `/api/music/listening-sessions` zwraca lokalne dane, eliminując zależność od dostępności Last.fm dla odczytów historycznych.
- **Books frontend migration to Encore + Stimulus (HMAI-139).** Drugi moduł na Encore track po Series (HMAI-41/1.7.1). Nowy controller `app/assets/controllers/books_controller.js` z targets/actions, ES importy `apiCall, safeUrl, escHtml, TOAST_TIMEOUT_MS` z `../util.js`, brak `window.*` globals. Template `app/templates/books/index.html.twig` przepisany na `data-controller="books"` + `data-action`. Vanilla `app/public/js/books.js` usunięty. 2 nowe Playwright specs (`books.desktop.spec.ts` + `books.mobile.spec.ts`). Pre-existing CSS bug naprawiony przy okazji: `.book-card { min-width: 0 }` + `.progress-wrap progress { min-width: 0 }` — `<progress>` element ma UA default intrinsic width ~10em który rozciągał grid track past mobile viewport.
- **Nginx security headers + Symfony listener defense-in-depth (HMAI-137).** Dual-layer: `docker/nginx/default.conf` ustawia `X-Content-Type-Options: nosniff`, `X-Frame-Options: DENY`, `Referrer-Policy: strict-origin-when-cross-origin`, `Permissions-Policy: geolocation=(), microphone=(), camera=(), payment=()` + `server_tokens off`. `App\EventListener\SecurityHeadersListener` (`kernel.response`, priority -128) ustawia te same nagłówki — chroni przed brakiem nginxa (np. testy WebTestCase). HSTS w nginx zakomentowane do czasu skonfigurowania HTTPS. 4 regression testy w `SecurityHeadersTest`.
- **MySQL backup automation (HMAI-136).** Scheduler task `App\Application\Scheduled\BackupDatabase` (cron `0 3 * * *`) — `mysqldump | gzip` do `/backups/homemanager-YYYY-MM-DD.sql.gz`. Retention: 30 daily + 12 monthly (1-szy każdego miesiąca pozostaje, reszta usuwana po 30 dniach). Targety `make backup-now` + `make restore BACKUP=...`. Restore runbook udokumentowany w `docs/backup-runbook.md`.
- **Graylog retention policy (HMAI-142).** Skrypt `scripts/graylog-bootstrap.sh` (idempotentny) tworzy GELF UDP input, index sets `auth-events` (90 dni, time-based rotation) i `series-events` (30 dni), stream'y filtrujące po `channel`. `make monitoring-bootstrap` target. Dokumentacja sizing/disk budget w `docs/graylog-runbook.md`.
- **CI Playwright + Newman jobs (HMAI-140).** `.github/workflows/ci.yml` rozszerzone o `e2e-playwright` i `e2e-newman` joby (oba `needs: tests`). Joby spinają stack przez `docker-compose-test.yaml` + `symfony server:start --no-tls --port=8080` (gołe `php -S` nie łączyło routingu z statycznymi assetami Encore). `APP_ENV=test` w CI kieruje kanały `series`/`auth` Monologu na handlery null → Graylog niepotrzebny.
- **Deptrac architecture boundaries (HMAI-146).** Deptrac 4.6 jako dev dep. `app/deptrac.yaml` definiuje 17 layerów (5 modułów × 3 warstwy + Glue + Vendor). Domain → [] na poziomie tokenów, cross-module coupling zakazany. Scalony baseline z 6 pre-existing violations udokumentowany jako follow-up. `make deptrac` + `make deptrac-baseline` targets. Krok Deptrac dodany do CI job `static-analysis`. CLAUDE.md zaktualizowane (sekcja Static Analysis + linia ZASADY NIENARUSZALNE).

### Changed

- **`Books\Domain\Event\BookCompleted` routing decision (HMAI-141).** Sync (in-memory) zostaje świadomie — brak handlera, brak I/O side-effects → async transport overhead bez benefitu (ADR-006). Decyzja pinowana przez `BookCompletedRoutingTest` (asercja, że event NIE jest w `async` transport mapping i `event.bus` z `allow_no_handlers: true` go zaakceptuje bez handlera).
- **`tests-e2e/series.mobile.spec.ts` mobile overflow guard (HMAI-147).** Pierwszy fix (`html, body { overflow-x: clip }` w `app/assets/styles/app.css`) zatrzymał scroll dokumentu na Pixel 5, ale element-level check wciąż łapał sub-pixel `394.73 vs 393`. Tolerancja element-level podniesiona z `+1px` na `+2px` — absorbuje sub-pixel grid track rounding (1fr = 176.5px, Linux Chromium rounding ~1.7px), realny breakout (471px past viewport) wciąż łapany. Books mobile spec używa tej samej tolerancji.
- **`tests-e2e/postman/AIHomeManager.postman_collection.json`** (HMAI-144): +2 Music listening session requesty (`POST /api/music/listening-sessions` + `GET ...`). 34 → 36 requestów, 54 → 66 asercji.
- **CLAUDE.md**: Books przeniesione z sekcji "Twig + vanilla JS" do "Encore + Stimulus", dodana linia Deptrac w ZASADY NIENARUSZALNE, "Wydania" → 1.10.0, tabela epików → HMAI-145 zamknięty.

### Coverage

- **630 PHP tests** passing (vs 542 at 1.9.0) — +88 nowych: ~38 Tasks REST CRUD + Google Calendar (HMAI-135), ~12 PDF export (HMAI-138), ~25 Music ListeningSession aggregate (HMAI-144), ~7 Nginx security headers (HMAI-137), ~4 BookCompleted routing + sync test (HMAI-141), +2 backup retention (HMAI-136).
- **9 Playwright** (vs 5) — +4 z HMAI-139: `books.desktop.spec.ts` (3 testy: list smoke, modal open/close bez page reload, 422 error banner) + `books.mobile.spec.ts` (1 test: Pixel 5 overflow guard z 2px sub-pixel tolerance).
- **36 Newman** requests / 66 assertions (vs 34/54) — +2 Music listening sessions.
- PHPStan level 8 clean, baseline bez nowych entries. Rector dry-run + CS Fixer + Deptrac wszystkie zielone w CI.

### Documentation

- **Confluence id 52297730** "Frontend Web — architektura" — v3. Books dodane do Encore track, nowa sekcja "Migracja per moduł" (Series → Books → reszta odroczona), lesson learned: backdrop guard vs Cancel button na osobnych akcjach Stimulus.
- **Confluence ADR-005** (HMAI-143) — formalizacja decyzji CSRF dla stateless `^/api/*` z header-based auth (link w CLAUDE.md sekcja Security).
- **`docs/backup-runbook.md`** (HMAI-136) — restore procedure, retention rationale, monitoring.
- **`docs/graylog-runbook.md`** (HMAI-142) — index set sizing, disk budget per retention period.
- **CHANGELOG.md**: ta sekcja.
- **CLAUDE.md**: HMAI-145 oznaczone jako zamknięte 2026-06-01; "Wydania" → 1.10.0.

### Migration

1. **Backup directory** (HMAI-136) — utworzyć `./backups/` z prawem zapisu dla kontenera `php` (entrypoint robi `mkdir -p` ale wolumin musi istnieć).
2. **Scheduler worker** (HMAI-136, HMAI-144) — `docker compose up -d scheduler_worker` konsumuje `scheduler_default` transport. Wymaga restartu po pull jeśli `Schedule.php` zmienione.
3. **Graylog bootstrap** (HMAI-142) — `make monitoring-up && make monitoring-bootstrap` jednorazowo, tylko jeśli monitoring profile uruchamiany lokalnie. CI używa `APP_ENV=test` → Graylog niepotrzebny.
4. **Encore rebuild** — `make assets-prod` po pull (zmieniony `app/assets/styles/app.css` + nowy controller); CI to robi automatycznie.

Brak nowych ENV. Brak DB migrations. Brak destructive ops.

### Closed Jira

| ID | Tytuł | PR |
|---|---|---|
| [HMAI-135](https://honemanager.atlassian.net/browse/HMAI-135) | Tasks — REST CRUD + Google Calendar sync | [#138](https://github.com/zlotylesk/AIHomeManager/pull/138) |
| [HMAI-136](https://honemanager.atlassian.net/browse/HMAI-136) | MySQL backup automation | [#139](https://github.com/zlotylesk/AIHomeManager/pull/139) |
| [HMAI-137](https://honemanager.atlassian.net/browse/HMAI-137) | Nginx security headers + Symfony listener | [#140](https://github.com/zlotylesk/AIHomeManager/pull/140) |
| [HMAI-138](https://honemanager.atlassian.net/browse/HMAI-138) | PDF export for Books/Articles/Tasks | [#141](https://github.com/zlotylesk/AIHomeManager/pull/141) |
| [HMAI-139](https://honemanager.atlassian.net/browse/HMAI-139) | Books frontend migration to Encore + Stimulus | [#151](https://github.com/zlotylesk/AIHomeManager/pull/151) |
| [HMAI-140](https://honemanager.atlassian.net/browse/HMAI-140) | CI Playwright + Newman jobs | [#145](https://github.com/zlotylesk/AIHomeManager/pull/145) |
| [HMAI-141](https://honemanager.atlassian.net/browse/HMAI-141) | BookCompleted event routing — explicit sync | [#142](https://github.com/zlotylesk/AIHomeManager/pull/142) |
| [HMAI-142](https://honemanager.atlassian.net/browse/HMAI-142) | Audit log retention policy (Graylog) | [#144](https://github.com/zlotylesk/AIHomeManager/pull/144) |
| [HMAI-143](https://honemanager.atlassian.net/browse/HMAI-143) | ADR-005 Confluence — stateless CSRF | [#143](https://github.com/zlotylesk/AIHomeManager/pull/143) |
| [HMAI-144](https://honemanager.atlassian.net/browse/HMAI-144) | Music local listening sessions aggregate | [#146](https://github.com/zlotylesk/AIHomeManager/pull/146) |
| [HMAI-146](https://honemanager.atlassian.net/browse/HMAI-146) | Deptrac architecture boundaries | [#148](https://github.com/zlotylesk/AIHomeManager/pull/148) |
| [HMAI-147](https://honemanager.atlassian.net/browse/HMAI-147) | Mobile overflow tolerance + clip fix | [#147](https://github.com/zlotylesk/AIHomeManager/pull/147) |
| [HMAI-145](https://honemanager.atlassian.net/browse/HMAI-145) | Application audit follow-up — epic close | — |

### Carried forward

Brak. Wszystkie podzadania HMAI-145 zamknięte w tym release.

## [1.9.0] — 2026-05-23

Domknięcie dwóch epików: **HMAI-131** (Domain model & DDD purity — 12/12 podzadań) i **HMAI-132** (Features — eksport CSV, 1/1 podzadanie). Pierwsza Major-level emisja domain eventu poza modułem Series (Books → `BookCompleted`), spójne `equals()` na wszystkich ośmiu Value Objects + testy regresji, dead-code dla event-recording zablokowany reflection guardami w Articles i Tasks, three CSV export endpoints (`/api/{books,tasks,articles}/export`) dzielące shared `App\Csv\CsvBuilder` (UTF-8 BOM + RFC 4180). 542/542 PHP (+47 vs 1.8.0) + 5/5 Playwright + 34/34 Newman.

### Added

- **`Books\Domain\Event\BookCompleted`** + dispatch w `LogReadingSessionHandler` (HMAI-58). One-shot guard: emisja tylko przy *pierwszym* osiągnięciu 100% (warunek `&& BookStatus::COMPLETED !== $this->status`) — kolejne sesje po ukończeniu książki już nie wybudzają eventu. 7 nowych testów (BookAggregateTest scenariusze + LogReadingSessionHandlerTest dispatch).
- **`equals(self $other): bool` w 8 immutable Value Objects** (HMAI-83 + HMAI-131): `Rating`, `AverageRating`, `ReadingProgress`, `ISBN` (porównanie znormalizowanej formy), `TaskTitle`, `TimeSlot` (porównanie UTC timestamps — TZ-blind), `ArticleUrl`, `CoverUrl` (uzupełnione przy epic review). +8 unit testów (1 per VO) + nowy `CoverUrlTest` z pełnym pokryciem konstrukcji/walidacji/equality (9 testów).
- **Reflection regression tests** dla dead-code prevention (HMAI-59, HMAI-134): `ArticleTest::testArticleHasNoEventRecordingInfrastructure` i `TaskAggregateTest::testTaskHasNoEventRecordingInfrastructure` — pinują brak `recordedEvents` field i `releaseEvents()` method via `ReflectionClass`. Re-introdukcja wymaga jednoczesnego usunięcia guard testa + dopięcia handlera w tej samej PR.
- **CSV export endpoints (HMAI-36):**
  - `GET /api/books/export` — kolumny: `isbn, title, author, status, percentage, totalPages`. `percentage` liczone w PHP (silent degrade do 0.0 przy `total_pages=0` — żaden divide-by-zero).
  - `GET /api/tasks/export?from=&to=` — kolumny: `title, startTime, endTime, durationMinutes, googleEventId`. Filtr po `time_start` (kiedy praca się wydarzyła).
  - `GET /api/articles/export?status=` — kolumny: `title, url, category, readAt, isRead`. Filtr po stanie `read|unread`.
- **`App\Csv\CsvBuilder`** — shared helper (BOM + `fputcsv` z `escape: ''` zgodny z PHP 8.4 + RFC 4180). Per-moduł `*CsvExporter` w `Application/Service/` z `rows()` jako generatorem na DBAL cursor (`executeQuery + fetchAssociative` w pętli — nie `fetchAllAssociative`, żeby duże eksporty nie pożerały pamięci).
- **`ImportArticlesCommand --dry-run`** (HMAI-119) — flaga `--dry-run` waliduje CSV i wypisuje co byłoby zaimportowane, bez insertów. Wzorzec do replikacji w przyszłych importach.

### Changed

- **`Tasks\Domain\Entity\Task`** (HMAI-134): usunięte martwe `recordedEvents` field, `releaseEvents()` method i emisja `TaskScheduled` z `schedule()`. Klasa `TaskScheduled` zostawiona jako kontrakt na przyszłość. 3 stare testy z `TaskAggregateTest` usunięte, 1 reflection regression dodany. Orphan PHPStan baseline entry usunięty.
- **`Articles\Domain\Entity\Article`** (HMAI-110): `updateMetadata()` waliduje invariants (`title`/`url`/`category` po `trim` non-empty, `mb_strlen <= 255`) + komunikat `'Article "%s" field "%s" cannot be empty.'` z kontekstem id+pola — debug-friendly w logach. `\InvalidArgumentException` przy naruszeniu.
- **`Books\Application\Handler\AddBookHandler`** (HMAI-91): fail-fast na pusty title (`?? ''` fallback usunięty). Pełen kontekst do logu zamiast cichego pustego rekordu.
- **`Series\Domain\Entity\Series::rateEpisode()`** (HMAI-89): komunikat wyjątku `'Season "%s" not found in series "%s".'` zamiast `'Season "%s" not found.'` — kontekst series id dla łatwiejszego debugu w Graylog.
- **`Articles\Application\Query\GetAllArticles` + `GetArticleOfTheDay`** (HMAI-120): promoted do `final readonly class`. `ImportResult` świadomie zostaje mutable (counter increment w pętli) — dodany komentarz wyjaśniający.
- **`BooksController::create()`** (HMAI-108): `str_contains($e->getMessage(), 'not found')` zastąpione przez typed `BookNotFoundException` instanceof check. Nie-fragile mapping na 404.
- **`Tasks\Application\QueryHandler\GetTimeReportHandler`** (HMAI-117): `@return list<array{date: string, hours: float, taskCount: int}>` w PHPDoc — PHPStan teraz typecheckuje rezultat.
- **`ISBN` VO** (HMAI-111): local variable `$normalizedValue` (nie `$normalized` jak property) — czytelność dla reviewerów.
- **`tests-e2e/postman/AIHomeManager.postman_collection.json`** (HMAI-132): +3 export requesty (Books/Tasks/Articles) z asercjami status + content-type + filename + CSV header row. 31 → 34 requestów, 42 → 54 asercji.
- **CLAUDE.md**: epiki HMAI-131 + HMAI-132 → zamknięte; "Wydania" → 1.9.0; tabela domain events zaktualizowana o `BookCompleted`.

### Coverage

- **542 PHP tests** passing (vs 495 at 1.8.0) — +47 nowych: 7 Book/BookCompleted (HMAI-58), 8 VO equals() (HMAI-83), 9 CoverUrlTest (HMAI-131 epic), 1 Article reflection guard (HMAI-59), 1 Task reflection guard (HMAI-134) − 3 dead Task tests (HMAI-134), 6 Article updateMetadata invariants (HMAI-110), 4 AddBookHandler title fail-fast (HMAI-91), 11 export endpoints (HMAI-36), 3 import dry-run (HMAI-119).
- **5 Playwright** (bez zmian).
- **34 Newman** requests / 54 assertions (vs 28/42 — dodane 3 export + 4 asercji × 3, plus drobne korekty).
- PHPStan level 8 clean, baseline bez nowych entries.

### Documentation

- **Confluence id 49053698** "Architektura heksagonalna i DDD w PHP" — v4. Dodane: Sekcja 7 "DDD purity hardening" (VO equals() z tabelą per-VO strategii, aggregate event emission contracts + one-shot guard pattern, dead-code reflection guards, invariant validation z kontekstem, `final readonly` consistency).
- **Confluence id 46891009** "Dokumentacja API" — v5. Dodane: sekcja "CSV Export — wspólne wzorce" (BOM + RFC 4180 + buffered Response trade-off + PDF deferral) + per-module export endpoint rows.
- **`tests-e2e/postman/README.md`**: bump request/assertion count, note o export coverage.
- **CHANGELOG.md**: ta sekcja.
- **CLAUDE.md**: epiki HMAI-131 + HMAI-132 oznaczone jako zamknięte 2026-05-23; "Wydania" → 1.9.0.

### Migration

Brak. Wszystkie zmiany w warstwach Domain + Application + Infrastructure controllers — brak nowych ENV, brak DB migrations, brak Redis schema changes. Klienci konsumujący `/api/books`, `/api/tasks/time-report`, `/api/articles` mają teraz dodatkowy endpoint `/export` (GET, CSV) — nieinwazyjne.

### Closed Jira

| ID | Tytuł | PR |
|---|---|---|
| [HMAI-119](https://honemanager.atlassian.net/browse/HMAI-119) | Add --dry-run flag to ImportArticlesCommand | [#128](https://github.com/zlotylesk/AIHomeManager/pull/128) |
| [HMAI-110](https://honemanager.atlassian.net/browse/HMAI-110) | Validate Article::updateMetadata invariants with explicit context | [#129](https://github.com/zlotylesk/AIHomeManager/pull/129) |
| [HMAI-59](https://honemanager.atlassian.net/browse/HMAI-59) | Pin no-event-recording on Article entity to prevent dead-code regression | [#130](https://github.com/zlotylesk/AIHomeManager/pull/130) |
| [HMAI-58](https://honemanager.atlassian.net/browse/HMAI-58) | Emit BookCompleted event when reading hits 100% and dispatch via event bus | [#131](https://github.com/zlotylesk/AIHomeManager/pull/131) |
| [HMAI-83](https://honemanager.atlassian.net/browse/HMAI-83) | Add value-based equals() to all seven domain value objects | [#132](https://github.com/zlotylesk/AIHomeManager/pull/132) |
| [HMAI-120](https://honemanager.atlassian.net/browse/HMAI-120) | Promote marker queries to final readonly and document ImportResult mutability | [#133](https://github.com/zlotylesk/AIHomeManager/pull/133) |
| [HMAI-134](https://honemanager.atlassian.net/browse/HMAI-134) | Remove dead releaseEvents() from Task and pin regression via reflection | [#134](https://github.com/zlotylesk/AIHomeManager/pull/134) |
| [HMAI-36](https://honemanager.atlassian.net/browse/HMAI-36) | Add CSV export endpoints for Books, Tasks and Articles | [#135](https://github.com/zlotylesk/AIHomeManager/pull/135) |
| [HMAI-131](https://honemanager.atlassian.net/browse/HMAI-131) | Domain model & DDD purity — epic close | [#136](https://github.com/zlotylesk/AIHomeManager/pull/136) |
| [HMAI-132](https://honemanager.atlassian.net/browse/HMAI-132) | Features — epic close | [#137](https://github.com/zlotylesk/AIHomeManager/pull/137) |

Pre-existing (carried forward): [HMAI-130](https://honemanager.atlassian.net/browse/HMAI-130) (Gotowe od 1.3.0 — Rate limiting epic, świadomie w fixVersion 1.9.0 jako historical reference).

## [1.8.0] — 2026-05-21

Domknięcie epica **HMAI-129** (API hardening — input validation, error contracts, exception handling) — 8/8 podzadań (HMAI-43, 57, 65, 66, 67, 68, 79, 109). Najszerszy zakres: nowy globalny `ApiExceptionListener` (HMAI-79) konwertujący uncaught throwables na `^/api/*` na JSON z generycznym 500, nowy PATCH endpoint `/api/series/.../rating` (HMAI-43), spójna walidacja per moduł (Music limit, Series/Episode title length, Books pages_read/date), CSRF decision doc dla stateless+API key (HMAI-57). 495/495 PHP (+42 vs 1.7.1) + 5/5 Playwright + 28/28 Newman. PHPStan level 8 clean (zero new baseline entries).

### Added

- **`App\EventListener\ApiExceptionListener`** (HMAI-79) — `kernel.exception` priority 64, scoped do `^/api/*`. Unwrap `HandlerFailedException` (Messenger), preserve `HttpExceptionInterface` status/message, dla pozostałych throwables 500 z generycznym `Internal server error.` (oryginalny message tylko w logu). Non-API paths przechodzą bez zmian — Twig frontend zachowuje swoje strony błędu. 5 unit + 2 integration testów.
- **PATCH `/api/series/{seriesId}/seasons/{seasonId}/episodes/{episodeId}/rating`** (HMAI-43) — `SeriesController::rateEpisode()` dispatchuje `AddEpisodeRating` command. Pre-validation `is_int($rating) && 1..10` zwraca 422 przed wywołaniem aggregate (omija `HandlerFailedException` unwrap noise). 204 No Content przy sukcesie. 4 integration testy (happy path + 422 invalid rating + 404 series/episode not found).
- **`docs/HMAI-57.md`** — CSRF decision dokument: dlaczego `^/api/*` świadomie nie używa `#[IsCsrfTokenValid]` (firewall `stateless: true` + autoryzacja przez `X-API-Key` header — przeglądarka nie ustawia custom headerów cross-origin). Plan migracji jeśli wprowadzimy stateful session/cookie auth.
- **`tests/Integration/Security/ApiKeyAuthCsrfTest.php`** (HMAI-57) — 4 regression tests: POST/PUT/DELETE z `PHPSESSID` cookie ale bez `X-API-Key` → 401, plus stateless invariant (no `Set-Cookie` w response).
- **`Articles\Domain\Exception\InvalidArticleData`** (HMAI-109) — nowy exception markerujący dane od usera w `CreateArticle` aggregate. Pozwala kontrolerowi rozróżnić "twoje dane są złe" (mapowany na generic 422) od "coś się zepsuło" (500 z generic message).

### Changed

- **`MusicController`** (HMAI-65): nowe stałe `MAX_TOP_ALBUMS_LIMIT=1000`, `MAX_COMPARISON_LIMIT=200`, `DEFAULT_LIMIT=50`. Private helper `parseLimit(?string $raw, int $max): ?int` z `ctype_digit` — odrzuca floats/scientific notation/negatywne/zero przez 422 zamiast cichego clampowania do 1 (`max(1, min(MAX, (int) $raw))` było buggy). Komunikat: `Field "limit" must be a positive integer between 1 and {max}.`
- **`SeriesController::create()` + `SeriesController::addEpisode()`** (HMAI-66): `mb_strlen($title) > 255` → 422 z komunikatem `Title must be at most 255 characters.`. `mb_strlen` (nie `strlen`) liczy znaki, nie bajty — 255-znakowy emoji tytuł mieści się w `VARCHAR(255) utf8mb4`.
- **`BooksController` log reading session** (HMAI-67): `pages_read` walidowane jako `is_int($value) && $value > 0` — odrzuca floaty (`1.5`), stringi numeryczne, ujemne, zero przez 422. Pre-validation przed dispatchem `LogReadingSession`.
- **`BooksController` log reading session** (HMAI-68): pole `date` walidowane przez `DateTimeImmutable::createFromFormat('!Y-m-d', $raw)` + round-trip equality (`$dt->format('Y-m-d') === $raw`) — wyłapuje `2026-02-30`, `2026/05/21`, ISO 8601 z czasem. 422 z komunikatem `Field "date" must be a date in Y-m-d format.`
- **`ArticlesController::create()`** (HMAI-109): `InvalidArgumentException` z aggregate już nie leakuje raw message w response. Zamiast tego logger warning + generyczny `'error' => 'Invalid article data.'`. Domain exception message wraca do logów (Graylog), nie do klienta.
- **CLAUDE.md**: dodana sekcja "API exception listener (HMAI-79)" (kontrakty 4xx vs 5xx, HandlerFailedException unwrap pattern). Status epica HMAI-129 → epik zamknięty (8/8). "Wydania" → 1.8.0.

### Coverage

- **495 PHP tests** passing (vs 453 at 1.7.1) — +42 nowych: 6 ApiExceptionListener (5 unit + 2 integration), 4 PATCH episode rating, 4 CSRF regression, 10 Music limit validation (2× 5-case data provider), 4 Series/Episode title length, 6 Books pages_read int, 5 Books date Y-m-d format, 3 Articles generic error.
- **5 Playwright** (Series desktop + mobile — bez zmian).
- **28 Newman** requests / 42 assertions (bez zmian — nowy PATCH endpoint nie wpięty w `tests-e2e/postman/AIHomeManager.postman_collection.json`; osobny follow-up HMAI-33 deferred do 1.9.x).
- PHPStan level 8 clean, baseline bez nowych entries. CS Fixer + Rector dry-run green.

### Documentation

- **Confluence id 46891009** "Dokumentacja API" — v4. Dodane: sekcja CSRF decision (HMAI-57), sekcja Global exception handling (HMAI-79, kontrakty 4xx vs generic 500), PATCH rating endpoint w Series, walidacje per moduł (HMAI-65/66/67/68/109), 500 status code row.
- **Confluence id 49643522** "Series — Warstwa HTTP REST API Controller" — v2. PATCH rating endpoint, tabela walidacji (`mb_strlen`, `is_int 1..10`), pre-validation pattern note, nowe test scenarios.
- **CHANGELOG.md**: ta sekcja.
- **CLAUDE.md**: epik HMAI-129 → zamknięty 2026-05-20; "Wydania" → 1.8.0.

### Migration

Brak. Czysto warstwa kontrolerów + kernel.exception listener — brak nowych ENV, brak DB migrations, brak Redis schema changes. Klienci API, którzy wcześniej polegali na `getMessage()` z 500 (n.b. tego nie powinni byli robić), zobaczą teraz `Internal server error.` zamiast oryginalnego komunikatu — sprawdzaj logi (Graylog kanał default) by zobaczyć przyczynę.

### Closed Jira

| ID | Tytuł | PR |
|---|---|---|
| [HMAI-67](https://honemanager.atlassian.net/browse/HMAI-67) | Validate `pages_read` is a positive integer in log reading session | [#119](https://github.com/zlotylesk/AIHomeManager/pull/119) |
| [HMAI-68](https://honemanager.atlassian.net/browse/HMAI-68) | Validate reading session date format as `Y-m-d` | [#120](https://github.com/zlotylesk/AIHomeManager/pull/120) |
| [HMAI-65](https://honemanager.atlassian.net/browse/HMAI-65) | Validate Music limit query param as positive integer | [#121](https://github.com/zlotylesk/AIHomeManager/pull/121) |
| [HMAI-66](https://honemanager.atlassian.net/browse/HMAI-66) | Validate series and episode title length up to 255 characters | [#122](https://github.com/zlotylesk/AIHomeManager/pull/122) |
| [HMAI-109](https://honemanager.atlassian.net/browse/HMAI-109) | Replace leaked exception message with generic article validation error | [#123](https://github.com/zlotylesk/AIHomeManager/pull/123) |
| [HMAI-79](https://honemanager.atlassian.net/browse/HMAI-79) | Add global API exception listener returning JSON for `^/api/*` | [#124](https://github.com/zlotylesk/AIHomeManager/pull/124) |
| [HMAI-43](https://honemanager.atlassian.net/browse/HMAI-43) | Add PATCH episode rating endpoint wired to existing aggregate | [#125](https://github.com/zlotylesk/AIHomeManager/pull/125) |
| [HMAI-57](https://honemanager.atlassian.net/browse/HMAI-57) | Document stateless API key decision and add CSRF regression tests | [#126](https://github.com/zlotylesk/AIHomeManager/pull/126) |
| [HMAI-129](https://honemanager.atlassian.net/browse/HMAI-129) | API hardening (epic close) | [#127](https://github.com/zlotylesk/AIHomeManager/pull/127) |

### Carried forward

Brak — fixVersion 1.8.0 100% Done. Postman/Newman update dla nowego PATCH endpointu = HMAI-33 follow-up (deferred). Frontend Series UI button dla inline rating edit = osobny follow-up (deferred).

## [1.7.1] — 2026-05-19

Domknięcie epica **HMAI-128** (Frontend hardening — JS quality, CSP/SRI, build pipeline) — 12/12 podzadań. Druga partia po batchu 1.7.0: HMAI-41 (Webpack Encore + Stimulus pilot dla Series UI) + epic review (wpięcie `window.apiCall` w pozostałe 4 moduły, regression tests dla CSP/Encore manifest, full rewrite Confluence patterns id 52297730 v2). 453/453 PHP (+2 vs 1.7.0 — regression guards) + 5/5 Playwright + 28/28 Newman. PHPStan level 8 clean (zero new baseline entries).

### Added

- **Webpack Encore + Stimulus** dla Series UI (HMAI-41):
    - `app/webpack.config.js` — Encore config z entry `app`, splitEntryChunks, Stimulus bridge, versioning prod-only.
    - `app/assets/app.js` (główny entry), `bootstrap.js` (Stimulus auto-discovery), `util.js` (ES module port), `controllers/series_controller.js` (Stimulus controller — port z `public/js/series.js`), `styles/app.css`.
    - `aihm-node-1` (`node:24-alpine`) jako long-running sidecar (`tail -f /dev/null`) dla `make assets*` shell exec.
    - Twig: `{{ encore_entry_link_tags('app') }}` + `{{ encore_entry_script_tags('app') }}` zamiast manual `<link>`/`<script>`.
    - CI: `actions/setup-node@v4` + `npm ci` + `npm run build` przed PHPUnit (entry tags wymagają `public/build/entrypoints.json`).
    - Makefile: `make assets` (dev), `make assets-watch`, `make assets-prod`, `make node-install`.
- **2 regression tests** w `FrontendControllerTest` (HMAI-128 epic review):
    - `testBaseLayoutContainsCSPMetaTag` — guards na meta CSP (HMAI-100 DoD).
    - `testBaseLayoutLoadsEncoreEntryAssets` — guards na `<script src="/build/...">` + `<link href="/build/...">` (HMAI-41 DoD).

### Changed

- **`window.apiCall` wpięty w 4 modułach** (HMAI-128 epic review, DoD: "zostają wpięcia w books/articles/tasks/music"):
    - `articles.js`: `loadArticles` (2× via `Promise.allSettled`), `markAsRead` (surfaces `err.message` z payload).
    - `books.js`: `loadBooks`, add-book submit, reading-session submit.
    - `music.js`: `loadMusic` (3× via `Promise.allSettled`, `readSection` zsynchronizowany).
    - `tasks.js`: `loadReport`.
    - Boilerplate `if (!res.ok) { const err = await res.json(); showError(err.error || ...); }` zastąpiony przez `try/catch` z `err.message` z helpera. Net -18 LOC w 4 plikach JS.
- **`base.html.twig`**: pre-existing manualne `<link>`/`<script>` zastąpione przez `encore_entry_*` helpery (HMAI-41).
- **`templates/series/index.html.twig`**: `data-controller="series"` na root + usunięcie `<script src="/js/series.js">` — kontrola przez Stimulus auto-discovery (HMAI-41).
- **CLAUDE.md**: sekcja "Frontend" — dual track (Encore+Stimulus dla Series; vanilla JS dla pozostałych). Nowa sekcja "Webpack Encore (HMAI-41)" z opisem plików + komend Makefile. Status epica HMAI-128 → epik zamknięty (12/12).
- **`docker-compose.yml`**: dodany serwis `node` (long-running, mount `./app`).
- **`.gitignore`**: `public/build/` + `node_modules/`.

### Coverage

- **453 PHP tests** passing (vs 451 at 1.7.0) — +2 regression guards w FrontendControllerTest.
- **5 Playwright** (Series desktop + mobile — bez zmian).
- **28 Newman** requests / 42 assertions (bez zmian).

### Documentation

- **Confluence id 52297730** "Frontend Web — architektura i decyzje techniczne" — full rewrite v2 po zamknięciu epica HMAI-128. Dual-track architektura, sekcja "Frontend hardening patterns" (CSP, safeUrl, Promise.allSettled, event delegation, util.js helpers, URLSearchParams), Webpack Encore docs, aktualizacja sekcji Testy, historia zmian.
- **CHANGELOG.md**: ta sekcja.
- **CLAUDE.md**: epik HMAI-128 → zamknięty 2026-05-19; "Wydania" → 1.7.1.

### Migration

Brak. Build assets w CI ustawione w 1.7.1 — deploy wymaga `npm ci && npm run build` przed PHPUnit (już skonfigurowane w `.github/workflows/ci.yml`).

### Closed Jira

| ID | Tytuł | PR |
|---|---|---|
| [HMAI-41](https://honemanager.atlassian.net/browse/HMAI-41) | Webpack Encore + Stimulus dla Series UI | [#116](https://github.com/zlotylesk/AIHomeManager/pull/116) |
| [HMAI-128](https://honemanager.atlassian.net/browse/HMAI-128) | Frontend hardening — JS quality, CSP/SRI, build pipeline (epic close) | [#117](https://github.com/zlotylesk/AIHomeManager/pull/117) |

### Carried forward

Brak — fixVersion 1.7.1 100% Done.

## [1.7.0] — 2026-05-18

Pierwsza partia epica **HMAI-128** (Frontend hardening — JS quality). Dziewięć zadań pokrywających minor/major findings w warstwie JS: shared `util.js` (timeout + `safeUrl` + `apiCall`), CSP meta tag, `URLSearchParams`, walidacja protokołu URL przed renderowaniem (XSS), `Promise.allSettled` zamiast `Promise.all`, event delegation zamiast per-element bindowania. Wszystkie 9 podzadań zamknięte (HMAI-69, 70, 71, 72, 77, 78, 98, 100, 115). 451/451 PHP + 5/5 Playwright + 28/28 Newman — bez zmian liczby testów (czysto frontendowa zmiana). PHPStan level 8 clean (bez nowych entries w baseline). Pozostałe podzadania HMAI-128 (HMAI-41 Webpack Encore + Stimulus, oraz sam epic review) przesunięte do **1.7.1**.

### Added

- **`app/public/js/util.js`** — wspólny helper ładowany przez wszystkie szablony modułów (`articles/books/music/tasks/index.html.twig`):
    - `window.TOAST_TIMEOUT_MS = 5000` — globalna stała zastępująca rozjazd `6000ms` (tasks.js) / `5000ms` (series.js).
    - `window.safeUrl(url)` — sprawdza `new URL(url, document.baseURI).protocol` przeciw `http:`/`https:` i zwraca `null` dla `javascript:`, `data:`, `vbscript:`, itp. XSS guard przed renderowaniem `<a href>` / `<img src>`.
    - `window.apiCall(url, options)` — wrapper na `fetch` z normalizacją błędów (`error.status`/`error.body` z payload `{error: "..."}`) i obsługą 204. [HMAI-98]
- **Content-Security-Policy meta tag** w `base.html.twig` — `default-src 'self'`, `script-src 'self'` (+ `https://cdn.jsdelivr.net` tylko w dev), `style-src 'self' 'unsafe-inline'`, `img-src 'self' data: https:`, `connect-src 'self'`, `font-src 'self'`, `base-uri 'self'`, `object-src 'none'`. XSS injection nie wyleci do dowolnej domeny, eval zablokowany. [HMAI-100]

### Changed

- **`articles.js`**: `<a href>` przechodzi przez `window.safeUrl()` (HMAI-71, XSS guard przed `javascript:`). `Promise.allSettled([list, today])` zamiast `Promise.all` — częściowe 500 z `/api/articles/today` nie zabija renderowania listy (HMAI-69). Event delegation `document.body` matching `.btn-mark-read` zamiast per-element binding w `renderList` (HMAI-77).
- **`books.js`**: `coverUrl` przez `window.safeUrl()` (HMAI-72, XSS guard analogiczny do articles). Event delegation `.btn-log-session` (HMAI-78).
- **`music.js`**: `Promise.allSettled([topAlbums, collection, comparison])` zamiast `Promise.all` — awaria Last.fm nie blokuje panelu Discogs collection i odwrotnie. Per-section `readSection()` helper raportuje błędy granularnie. (HMAI-70)
- **`tasks.js`**: `URLSearchParams({from, to})` zamiast string concatenation `?from=${from}&to=${to}` — escapowanie znaków specjalnych. (HMAI-115)
- **`music.js`**: `URLSearchParams({period, limit})` dla `/top-albums` i `/comparison` (HMAI-115).
- **`books.js`**: `URLSearchParams({status})` dla `/api/books?status=...` (HMAI-115).
- Magic timeouts w `tasks.js`/`series.js` → `window.TOAST_TIMEOUT_MS`. (HMAI-98)
- **CLAUDE.md**: brak zmian konwencji architektonicznych — wszystkie zmiany w warstwie JS bez nowych wzorców backendowych.

### Coverage

- 451/451 PHP (bez zmian — czysto frontendowy release; istniejące unit/integration nie miały być modyfikowane).
- 5 Playwright E2E + 28 Newman REST — bez zmian (selektory testów nie były dotknięte).
- PHPStan level 8 clean, baseline bez nowych entries.

### Closed Jira

| Klucz | Tytuł | PR |
|---|---|---|
| [HMAI-98](https://honemanager.atlassian.net/browse/HMAI-98) | Niespójne magic timeouts w JS — extract `TOAST_TIMEOUT_MS` | #106 |
| [HMAI-115](https://honemanager.atlassian.net/browse/HMAI-115) | `URLSearchParams` w `tasks.js`/`music.js`/`books.js` | #107 |
| [HMAI-100](https://honemanager.atlassian.net/browse/HMAI-100) | CSP meta tag w `base.html.twig` | #108 |
| [HMAI-72](https://honemanager.atlassian.net/browse/HMAI-72) | `books.js` walidacja protokołu `coverUrl` | #109 |
| [HMAI-71](https://honemanager.atlassian.net/browse/HMAI-71) | `articles.js` walidacja protokołu URL przed `href` | #110 |
| [HMAI-69](https://honemanager.atlassian.net/browse/HMAI-69) | `articles.js` `Promise.allSettled` | #111 |
| [HMAI-70](https://honemanager.atlassian.net/browse/HMAI-70) | `music.js` `Promise.allSettled` | #112 |
| [HMAI-77](https://honemanager.atlassian.net/browse/HMAI-77) | `articles.js` event delegation | #113 |
| [HMAI-78](https://honemanager.atlassian.net/browse/HMAI-78) | `books.js` event delegation | #114 |

### Migration

Brak. Czysto frontendowy release — żadnych nowych ENV, żadnych migracji DB, żadnej re-auth.

### Carried forward to 1.7.1

- [HMAI-41](https://honemanager.atlassian.net/browse/HMAI-41) — Webpack Encore + Stimulus (build pipeline; szersza zmiana ergonomiki frontu).
- [HMAI-128](https://honemanager.atlassian.net/browse/HMAI-128) — epic review (dopełnienie po landowaniu HMAI-41).

## [1.6.0] — 2026-05-17

Domknięcie epica **HMAI-126** (Operability & observability). Sześć zadań pokrywających operowanie systemem w produkcji: healthcheck, harmonogram zadań cyklicznych, fixtures dla łatwego startu, audit log OAuth, metryki latencji external API, weryfikacja `messenger_worker`. Wszystkie 6 podzadań zamknięte (HMAI-133, 107, 112, 37, 39, 35). 451/451 PHP + 5/5 Playwright + 28/28 Newman — wszystkie zielone. PHPStan level 8 clean (bez nowych entries w baseline).

### Added

- **`GET /api/health`** — publiczny readiness probe (bypass firewall w `ApiKeyAuthenticator::supports`), trzy probe'y: MySQL `SELECT 1`, Redis `PING`, RabbitMQ TCP socket do hosta z `MESSENGER_TRANSPORT_DSN`. 200 + `{"status":"healthy", "components":{...}, "timestamp":...}` lub 503 + `"unhealthy"`. Docker healthcheck na `nginx` (`wget --spider`) jako end-to-end stack probe. Tests: 5 unit `HealthChecker` + 2 unit `HealthController` + 1 integration without API key. [HMAI-37]
- **`auth` Monolog channel + OAuth audit log** — `GoogleAuthController` i `DiscogsAuthController` używają `monolog.logger.auth` przez `#[Autowire]`. `info('OAuth authorize initiated' | 'OAuth callback success')` + `warning('OAuth callback failed', ['reason' => 'invalid_state' | 'missing_code' | 'missing_params' | 'token_exchange' | 'empty_token'])`. Dev/prod: `auth_gelf` handler (info, Graylog); prod również `auth_stream` (stderr JSON); test: `auth_null`. Tests: 10 unit (5 per provider). [HMAI-107]
- **API duration metrics** — `LastFmApiClient` i `DiscogsApiClient` emitują `info('External API call', ['provider', 'endpoint', 'duration_ms', 'status', 'error?'])` na kanale `music` dla każdego HTTP callu (success + failure tagged `error=transport_error | client_error | transport_or_server_error`). Logger via `#[Autowire(service: 'monolog.logger.music')]` z `NullLogger` default dla backward compat z testami. Tests: 4 unit. [HMAI-112]
- **Doctrine Fixtures bundle (dev+test)** — `doctrine/doctrine-fixtures-bundle` + 4 klasy: `SeriesFixtures` (3 × 2 sezony × 5 ocenianych odcinków), `BookFixtures` (5 książek pokrywających każdy `BookStatus`), `ArticleFixtures` (10 artykułów / 4 kategorie / 3 read), `TaskFixtures` (4 taski today+yesterday). Routed przez domain repositories — invariants agregatów respektowane. `make fixtures` target + `app/fixtures/sample-articles.csv` dla CSV import path. Tests: 4 integration. [HMAI-39]
- **Symfony Scheduler + cron-expression** — `src/Schedule.php` (`#[AsSchedule]`) z 3 zadaniami:
    - `0 0 * * *` — `ResetDailyArticleCache` (Articles): `DEL articles:today` Redis + `DELETE article_daily_picks WHERE picked_date < CURDATE() - INTERVAL 7 DAY`.
    - `0 8 * * 1` — `GenerateWeeklyActivityReport` (App\Application\Scheduled): DBAL counts z ostatnich 7 dni (`read_articles`, `pages_read`, `completed_tasks`) + `rated_episodes_total` → log `scheduled_task=weekly_report`.
    - `0 */6 * * *` — `RefreshDiscogsCollection` (Music) per `DISCOGS_USERNAME`: pre-warm cache przed 6h TTL.

    Nowy serwis docker `scheduler_worker` (`messenger:consume scheduler_default`). Stateful na `cache.app` (filesystem, host mount), `processOnlyLastMissedRun(true)` — restart workera odpala max 1 zaległe okno. Tests: 4 unit (2 per handler). [HMAI-35]

### Changed

- **CLAUDE.md**: nowa sekcja "Health endpoint (HMAI-37)", "Symfony Scheduler (HMAI-35)", `scheduler_worker` row w tabeli Infrastruktura, `make fixtures` w Komendach, `ApiKeyAuthenticator::supports` skip `/api/health` notatka, FixturesLoadTest w sekcji Testy.
- **`ApiKeyAuthenticator::supports()`** zwraca `false` dla dokładnie `/api/health` — bez tej zmiany firewall `^/api/*` blokowałby healthcheck na 401. [HMAI-37]

### Verified (no code change)

- **HMAI-133** — `symfony/amqp-messenger:8.0.*` już w `composer.json/lock` od `f52e33dd` (HMAI-42 Playwright Series E2E, 2026-05-16). `docker compose ps` pokazuje `messenger_worker` Up; `[OK] Consuming messages from transport "async".` Ticket zamknięty bez nowego commitu — fix już w produkcji.

### Upgrade notes (manual steps)

1. **`composer install`** — nowe paczki: `symfony/scheduler`, `dragonmantank/cron-expression`, `doctrine/doctrine-fixtures-bundle` (dev).
2. **`docker compose up -d`** — pełny rebuild zwiększa stos o `scheduler_worker` (ten sam image co `messenger_worker`).
3. **Graylog wiring** — jeśli profil monitoring działa, kanały `auth` i `music` (już istnieje) zaczną emitować nowe info-level events. Filtry/saved searches do utworzenia:
   - `scheduled_task:*` — widok cyklicznych zadań.
   - `provider:lastfm OR provider:discogs` — latency dashboard (`duration_ms` field).
   - `provider:google OR provider:discogs AND reason:*` — failed OAuth callbacks.
4. **Live healthcheck:** `curl http://localhost:8080/api/health` powinien zwrócić 200 z `"status":"healthy"`. Docker `nginx` zacznie reportować healthy po `start_period=30s`.
5. **Scheduler walidacja:** `make shell` → `php bin/console debug:scheduler` powinno pokazać 3 triggers.

### Coverage

- 451 PHP testy (z 421 baseline → +30: HMAI-37 (+8), HMAI-39 (+4), HMAI-35 (+4), HMAI-107 (+10), HMAI-112 (+4)).
- 5 Playwright E2E + 28 Newman REST.
- PHPStan level 8 clean, CS Fixer + Rector clean.

### Closed Jira

| Klucz | Tytuł | PR |
|---|---|---|
| [HMAI-133](https://honemanager.atlassian.net/browse/HMAI-133) | messenger_worker crashloop — brak symfony/amqp-messenger | — (już w `f52e33dd`) |
| [HMAI-107](https://honemanager.atlassian.net/browse/HMAI-107) | OAuth audit log | #100 |
| [HMAI-112](https://honemanager.atlassian.net/browse/HMAI-112) | API duration metrics | #101 |
| [HMAI-37](https://honemanager.atlassian.net/browse/HMAI-37) | /api/health endpoint | #102 |
| [HMAI-39](https://honemanager.atlassian.net/browse/HMAI-39) | Doctrine Fixtures | #103 |
| [HMAI-35](https://honemanager.atlassian.net/browse/HMAI-35) | Symfony Scheduler | #104 |
| [HMAI-126](https://honemanager.atlassian.net/browse/HMAI-126) | Operability & observability — **epic zamknięty** | (this commit) |

## [1.5.0] — 2026-05-17

Domknięcie epica **HMAI-124** (Persistence & DB integrity) — kompletny przegląd warstwy persystencji: N+1 queries, brakujące indeksy FK, race conditions, transakcyjność wielokrokowych zapisów, DBAL parameter hygiene, fragile row→DTO mapping. Wszystkie 9 podzadań zamknięte (HMAI-60, 61, 75, 86, 88, 92, 102, 103, 122). Dodatkowo siedem mniejszych fixów `ai_code_review` z parent epików HMAI-131 (DDD purity) i HMAI-128 (Frontend hardening) trafiło tutaj okolicznościowo (HMAI-89, 91, 101, 108, 111, 117, 118). 421/421 PHP tests + 5/5 Playwright + 28/28 Newman — wszystkie zielone. PHPStan level 8 baseline zregenerowany (-24 stale entries z naprawionych PR-ów).

### Added

- **Bulk IN-query w `DoctrineSeriesRepository`** — `attachSeasonsAndEpisodes()` ładuje seasons i episodes po dwóch batchowanych `WHERE …Id IN (…)` zapytaniach zamiast pętli per agregat. `findById` i `findAll` używają stałej liczby 3 zapytań niezależnie od liczby seriali/sezonów (było `1 + N + N*M`). ORM-managed state zachowany — `save()` działa bez zmian. [HMAI-60]
- **Lookup indexes w XML mapping** — `<indexes>` blok w `Episode.orm.xml` (`idx_episode_season_id`), `Series.orm.xml` (`idx_series_created_at`), `Article.orm.xml` (`idx_article_added_at`). Migracja `Version20260517000001`. Eliminuje full scan na rosnących tabelach w hot-path JOIN/ORDER BY. [HMAI-61]
- **`SeriesRowHydrator` service** — wspólny mapping rows → `SeriesDetailDTO` dla `GetAllSeriesHandler` i `GetSeriesDetailHandler`. Test `SeriesRowHydratorTest` (3 cases: empty, LEFT JOIN null seasons, multi-series grouping). [HMAI-103]
- **`ArticleDTO::fromRow` PHPDoc shape + `requireString()`** — `@param array{...}` deklaracja struktury wiersza + walidacja required columns (`id`, `title`, `url`, `added_at`) z `RuntimeException` zamiast cichych nulli. Test `ArticleDTOTest` (3 cases: full mapping, nullable omission, missing required). [HMAI-102]
- **`BookNotFoundException`** — typed domain exception zamiast `str_contains($e->getMessage(), 'not found')` w `BooksController`. Rzucany przez `LogReadingSessionHandler`, `RemoveBookHandler`, `UpdateBookHandler`. [HMAI-108]
- **`window.apiCall(url, options)` helper w `public/js/util.js`** — wrapper nad `fetch` rzucający typed Error z `.status` i `.body` zamiast cryptic JSON.parse error przy 500 z HTML response. Wpięte w `series.js` GET fetches; `templates/series/index.html.twig` ładuje `util.js`. [HMAI-118]
- **`GetArticleOfTheDayHandlerTest`** — 5 integration cases pokrywających `ArrayParameterType::STRING` named binding (regression guard HMAI-88), preferred-category branch, cache hit short-circuit, fallback i empty state. Domyka jedyną lukę test coverage HMAI-124 odkrytą w epic review. [HMAI-124 epic review]
- **Confluence section 9 w "Doctrine ORM i XML Mapping"** (page id 49119233 → v3) — 9 patternów persistence: bulk IN-query, transactional save, ArrayParameterType, FK indexes, single-query conditional aggregate, row hydrator service, `DTO::fromRow` walidacja, cache pool hygiene, query DoD. [HMAI-124 epic review]

### Changed

- **`DiscogsTokenRepository::save` jest transakcyjny** — `Connection::transactional(fn (Connection $c) => …)` wokół `DELETE` + `INSERT`. Wyklucza okno race w którym między DELETE a INSERT inny request widział pustą tabelę i traktował usera jako wylogowanego. [HMAI-92]
- **`EpisodeRatedHandler` single AVG query** — sezon + serial-wide avg liczone jednym SELECTem z `AVG(CASE WHEN …)` zamiast dwóch osobnych zapytań. [HMAI-86]
- **`GetArticleOfTheDayHandler` używa `ArrayParameterType::STRING`** dla `excludeIds` z named binding (`:excludeIds`) zamiast mieszać positional `?` z named. Bez tego dwa array params w jednym query nie wiążą się poprawnie. [HMAI-88]
- **`GetAllSeriesHandler` i `GetSeriesDetailHandler`** delegują mapping do `SeriesRowHydrator` — query handlery zostają cienkie (SELECT + delegate). [HMAI-103]
- **`AddBookHandler` fail-fast na pustym tytule** — `$title ?? ''` fallback zastąpiony przez `if ('' === trim($title)) throw new InvalidArgumentException(...)`. Książka z pustym tytułem nie wejdzie do bazy. [HMAI-91]
- **`Series::rateEpisode` exception message ma id series** — dotąd `'Season "%s" not found.'`, teraz `'Season "%s" not found in series "%s".'` jak `addEpisode`. Spójność w logach. [HMAI-89]
- **`ISBN` constructor — local var rename** — `$normalized` → `$normalizedValue` (parameter shadowed property o tej samej nazwie). Brak zmiany semantycznej, czytelność. [HMAI-111]
- **`GetTimeReportHandler` PHPDoc** zwężone do `list<TaskTimeDTO>` z `@var list<array{...}>` shape annotation na fetchowane wiersze — PHPStan teraz typecheckuje rezultat. [HMAI-117]
- **Hot-reload `<script>` gated `{% if app.environment == 'dev' %}`** w `templates/base.html.twig` — `idiomorph` i `frankenphp-hot-reload` z CDN nie idą do prod responses (eliminuje wektor wstrzyknięcia przez kompromitację CDN). [HMAI-101]
- **`cache.yaml`: pool `series.ratings.cache` usunięty** — pool był deklarowany ale `EpisodeRatedHandler` iniektuje raw `\Redis` (nie `CacheItemPoolInterface`). Dead config czyszczony. CLAUDE.md infrastructure note zaktualizowane: rating keys (`series:avg:{id}`, `season:avg:{id}`) ustawiane bezpośrednio przez `\Redis::setex` z TTL 3600. [HMAI-122]
- **`phpstan-baseline.neon` zregenerowane** — usuniętych 24 stale entries z PR-ów HMAI-91/102/103 które już naprawiły kod. Net 213 entries baseline (poprzednio 237 z dryftem).
- **`ArticleDTO::fromRow` nullable fields** — `isset() ? (string) … : null` → `… ?? null` (Rector RecastingRemovalRector + TernaryToNullCoalescingRector; PHPDoc shape już deklaruje `string|null`).

### Upgrade notes (manual steps)

1. **Migracje DB:** uruchom `make migrate` (oraz `make migrate-test`). Migracja `Version20260517000001` dodaje 3 indeksy (`idx_episode_season_id`, `idx_series_created_at`, `idx_article_added_at`) — operacja `CREATE INDEX` na dotychczas małych tabelach, sub-second.

2. **Brak nowych env vars i zależności composera** — release jest czysto kodowo-konfiguracyjny.

3. **Frontend:** żadne zmiany schemy template'ów ani route'ów. `public/js/util.js` jest nowym plikiem statycznym ładowanym z `templates/series/index.html.twig`; pozostałe moduły JS bez zmian (przeniesienie na helper to follow-up dla książek/articles/tasks/music — patrz Not in this release).

### Coverage

- **Testy PHP:** 421/421 zielono (vs 408/408 przy 1.4.0). +13 nowych: `ArticleDTOTest` (3), `SeriesRowHydratorTest` (3), `SeriesRepositoryTest::testFindAllLoadsSeasonsAndEpisodes` (1), `EpisodeRatedHandlerTest` rewrite (3 nadal), `GetArticleOfTheDayHandlerTest` (5), drobne adjusty istniejących.
- **Playwright (Series UI):** 5/5 zielono (bez zmian od 1.4.0).
- **Newman (Postman REST):** 28 requestów / 42 assertions / 100% zielono (bez zmian od 1.4.0).
- **PHPStan:** level 8 czysty, baseline 213 errors (regenerowany — drop 24 stale entries).
- **PHP-CS-Fixer:** wszystkie pliki w diff zgodne z `@Symfony` + `@PHP84Migration` + `global_namespace_import`.
- **Rector:** dry-run czysty po refactorze `ArticleDTO::fromRow`.

### Closed Jira epics

- [HMAI-124] Persistence & DB integrity — DBAL, ORM, indexes, transactions (9/9 podzadań)

### Not in this release

Wciąż otwarte pod label `ai_code_review`: HMAI-126 (operability, 6), HMAI-128 (frontend — pozostały bez HMAI-101/118, ~10), HMAI-129 (API hardening / CSRF, 8), HMAI-131 (DDD purity — pozostały bez HMAI-89/91/108/111/117, ~6), HMAI-132 (exports / missing endpoints, 1). `apiCall` helper wpięty tylko w `series.js` — books/articles/tasks/music to follow-up w epiku HMAI-128.

### Contributors

- Leszek Koziatek

## [1.4.0] — 2026-05-16

Domknięcie epica **HMAI-125** (Test coverage) — pełen audit luk w pokryciu testowym i ich uzupełnienie. Dwie nowe warstwy testowe wychodzą poza dotychczasowy zakres PHPUnit: **Playwright** (browser-driven Series UI) i **Newman/Postman** (smoke całej REST powierzchni). Wszystkie 12 podzadań batcha zamknięte (HMAI-33, 42, 73, 74, 76, 82, 93, 94, 95, 97, 99, 116) + audit-driven ReadingSession test. 408/408 PHP tests + 5/5 Playwright + 28 Newman requests (42 assertions) — wszystkie zielone.

### Added

- **Playwright E2E suite** dla Series UI (`tests-e2e/`) — 2 projects (desktop 1440×900 + Pixel 5 mobile), 5 scenariuszy: lista seriali, formularz dodawania bez reload, ocena → średnia natychmiast, błąd 422 → komunikat, layout 375px bez overflow. `BrowserContext.extraHTTPHeaders` wstrzykuje `X-API-Key` na każdy request browsera. [HMAI-42]
- **Newman/Postman collection** (`tests-e2e/postman/AIHomeManager.postman_collection.json`) — 28 requestów, 42 asercje, 100% zielono. `make test-newman` truncate + run z `--ignore-redirects` (niezbędne dla 302 OAuth bez podążania do `accounts.google.com`). Pominięte: Tasks CRUD (HMAI-43), Books ISBN-aware testy mają fallback 503 gdy National Library API jest nieosiągalne. [HMAI-33]
- **Integration testy dla obu OAuth controllerów** — `Auth/GoogleAuthControllerTest` (8 testów) + `Auth/DiscogsAuthControllerTest` (8 testów) pokrywające pełen authorize/callback flow z `disableReboot() + container->set()` patternem. [HMAI-73, HMAI-74]
- **MusicApi happy-path tests** — wcześniej tylko ścieżki 422/503, teraz override portów `MusicListeningHistoryInterface` + `VinylCollectionInterface` przez `installMusicPortMocks()` + Redis cache cleanup. Pokrywa top-albums, collection, comparison. [HMAI-76]
- **GoogleCalendarService refresh-flow tests** — happy path + branch "refresh token missing"; `tokenRepository->save()` weryfikowane po udanym refreshu. [HMAI-82]
- **Isolated unit testy entities** — `SeasonTest` + `EpisodeTest` (Series), `ArticleDailyPickTest` (Articles), `ReadingSessionTest` (Books). Każdy aggregate root i embedded entity ma teraz własny test (DoD HMAI-125). [HMAI-93, HMAI-94, HMAI-125]
- **GoogleClientFactory** — testy pokrywające `access_type=offline`, `prompt=consent` w auth URL + symetryczny whitespace guard dla `clientSecret`. [HMAI-95]
- **DiscogsTokenRepository** — test tampered-ciphertext (`get()` rzuca po mutacji SQL) + assercja `created_at`/`updated_at`. [HMAI-97]
- **DoctrineTaskRepository::findByDateRange** — 3 testy pinujące inclusive boundary semantykę embedded VO `TimeSlot`. [HMAI-116]
- **`symfony/amqp-messenger`** — composer require (wcześniej brakowało; w dev `POST .../episodes` zwracał 500 "No transport supports Messenger DSN amqp://"; test env był OK dzięki `when@test` → `in-memory://`). Discovered podczas HMAI-42. [HMAI-42]

### Changed

- **Test count: 366 → 408** (+42 testów, +6 assertions w nowych testach HMAI-125 ReadingSession). +5 Playwright scenariuszy + 28 Newman requestów.
- **Tooling**: PHPUnit 13 + `@playwright/test` 1.49 + Newman 6.x. `package.json` pojawia się w roocie (devDependencies only — aplikacja PHP pozostaje bez Node runtime).
- **`GetMusicComparisonHandlerTest`** — magic literals (`50.0`, `42.5`, `0.0`) zamienione na nazwane stałe (`HALF_MATCH_SCORE`, `CACHED_MATCH_SCORE_MARKER`, `NO_MATCH_SCORE`) z one-line rationale. [HMAI-99]
- **Makefile**: nowe targety `test-e2e-install` / `test-e2e` (Playwright) i `test-newman-install` / `test-newman` (Newman). Obie pre-truncate odpowiednie tabele przed runem.

### Upgrade notes (manual steps)

> **WAŻNE:** Po deployu zweryfikuj poniższe — kilka wymagań ustawień `.env.local` ujawniło się dopiero podczas pisania E2E. Bez nich Music endpoint i nawet niezależne `/api/series` rozsypują się w runtime.

1. **Migracje DB:** brak (1.4.0 to wyłącznie test coverage + composer deps).

2. **Env vars — `.env.local` musi mieć niepuste wartości (HMAI-42 discovery):**

   ```
   API_KEY=...                       # bez tego JS UI dostaje 401 i Newman nie autentykuje sie
   DISCOGS_TOKEN_KEY=<base64 32B>    # bez tego DI Music nie boot'uje (TokenCipher)
   GOOGLE_TOKEN_KEY=<base64 32B>     # j.w. dla Google
   DISCOGS_CONSUMER_KEY=...          # DiscogsCredentials VO rejects empty
   DISCOGS_CONSUMER_SECRET=...
   LASTFM_API_KEY=...                # placeholder wystarcza (testy toleruja 503)
   LASTFM_USERNAME=...
   DISCOGS_USERNAME=...
   ```

   Wygenerowanie sodium keys: `php -r "echo base64_encode(sodium_crypto_secretbox_keygen()), PHP_EOL;"`. Pusta wartość → `InvalidArgumentException` boot-time (HMAI-90/113).

3. **Composer dependency:** `composer install` zainstaluje nowy `symfony/amqp-messenger` (wpisany w `composer.lock`). Bez tego pakietu routed `EpisodeRated` event nie ma transportu w dev.

4. **Graylog GELF UDP input (HMAI-42 discovery):** kanał `series` w Monologu pisze do GELF; bez skonfigurowanego wejścia każdy `/api/series` returns 500 ECONNREFUSED. Po `make monitoring-up`:

   ```
   curl -u admin:admin -H "Content-Type: application/json" -H "X-Requested-By: cli" \
        -X POST -d '{"title":"GELF UDP","type":"org.graylog2.inputs.gelf.udp.GELFUDPInput",
                     "global":true,"configuration":{"recv_buffer_size":262144,
                     "bind_address":"0.0.0.0","port":12201}}' \
        http://localhost:9000/api/system/inputs
   ```

5. **E2E pre-req:** `make test-e2e-install` (Playwright + browser) i `make test-newman-install` jednorazowo; potem `make test-e2e` / `make test-newman` przy każdym sprawdzeniu pełnego pokrycia.

### Coverage

- **Testy PHP:** 408/408 zielono (vs 366/366 przy 1.3.0). +42 nowych w `tests/Unit/Module/{Articles,Books,Series}/Domain` + `tests/Integration/{Auth,Music,Series,Tasks}`.
- **Playwright (Series UI):** 5/5 zielono — desktop + mobile projects.
- **Newman (Postman REST):** 28 requestów / 42 assertions / 100% zielono.
- **PHPStan:** level 8 czysty (baseline 182 errors zachowany).
- **PHP-CS-Fixer:** `@Symfony` + `@PHP84Migration` + `global_namespace_import` — wszystkie pliki w diff zgodne.

### Closed Jira epics

- [HMAI-125] Test coverage — unit, integration, E2E gaps (12/12 podzadań + audit ReadingSessionTest)

### Contributors

- Leszek Koziatek

[1.4.0]: https://github.com/zlotylesk/AIHomeManager/compare/1.3.0...1.4.0
[HMAI-33]: https://honemanager.atlassian.net/browse/HMAI-33
[HMAI-42]: https://honemanager.atlassian.net/browse/HMAI-42
[HMAI-73]: https://honemanager.atlassian.net/browse/HMAI-73
[HMAI-74]: https://honemanager.atlassian.net/browse/HMAI-74
[HMAI-76]: https://honemanager.atlassian.net/browse/HMAI-76
[HMAI-82]: https://honemanager.atlassian.net/browse/HMAI-82
[HMAI-93]: https://honemanager.atlassian.net/browse/HMAI-93
[HMAI-94]: https://honemanager.atlassian.net/browse/HMAI-94
[HMAI-95]: https://honemanager.atlassian.net/browse/HMAI-95
[HMAI-97]: https://honemanager.atlassian.net/browse/HMAI-97
[HMAI-99]: https://honemanager.atlassian.net/browse/HMAI-99
[HMAI-116]: https://honemanager.atlassian.net/browse/HMAI-116
[HMAI-125]: https://honemanager.atlassian.net/browse/HMAI-125

---

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
