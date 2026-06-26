# AIHomeManager

Single-user system automatyzacji codziennych czynności — telewizja (Series), kalendarz (Tasks), czytelnictwo (Books / Articles), kolekcja muzyczna (Music) i postęp oglądania YouTube (YouTubeProgress). Modularny monolit Symfony 8 z heksagonalną architekturą i CQRS.

---

## Spis treści

- [O projekcie](#o-projekcie)
- [Moduły](#moduły)
- [Architektura](#architektura)
- [Stack technologiczny](#stack-technologiczny)
- [Wymagania](#wymagania)
- [Szybki start](#szybki-start)
- [Konfiguracja](#konfiguracja)
- [Komendy Makefile](#komendy-makefile)
- [Rozwój](#rozwój)
- [Testy](#testy)
- [Analiza statyczna](#analiza-statyczna)
- [Monitoring](#monitoring)
- [Struktura projektu](#struktura-projektu)
- [API](#api)
- [Linki](#linki)
- [Licencja](#licencja)

---

## O projekcie

AIHomeManager agreguje codzienne aktywności jednego użytkownika w sześciu modułach domenowych. Każdy moduł jest niezależny architektonicznie (Domain bez frameworka, własny język ubiquitous), połączone luźno przez CQRS bus i Symfony Messenger. Frontend dual-track: Series + Books + YouTubeProgress UI używają Webpack Encore + Stimulus, pozostałe moduły (Tasks/Articles/Music) wciąż na Twig + vanilla JS — wspólny `window.apiCall` z `public/js/util.js`.

**Podstawowe założenia:**

- Pojedynczy użytkownik (brak multi-tenant).
- API stateless chronione kluczem (`X-API-Key`); UI publiczne.
- Heksagonalna architektura — `Domain` nie zna Doctrine ani Symfony, granice egzekwowane przez Deptrac w CI.
- Doctrine XML mapping (ADR-001 — nie migrujemy na atrybuty PHP).
- CQRS z dwoma busami: `command.bus` i `query.bus`, plus `event.bus` dla domain events (Series.`EpisodeRated`, Books.`BookCompleted`).
- Per-IP rate limiting na `^/api/*` (60/min), proaktywny throttle external API klientów (Last.fm, Discogs, Biblioteka Narodowa, YouTube Data API, Trakt).
- OAuth tokens szyfrowane at rest (libsodium secretbox, osobne klucze per provider: Google, Discogs, Trakt).
- Defense-in-depth security headers (dual-layer: nginx + Symfony listener).
- Codzienny mysqldump → gzip + retention (30 daily + 12 monthly).

---

## Moduły

| Moduł | Co robi | Kluczowe integracje |
|---|---|---|
| **Series** | Katalog seriali, sezony, odcinki, własna ocena 1–10 + średnia z odcinków, flaga „obejrzane", edycja/usuwanie, import obejrzanych z Trakt | Trakt.tv API (OAuth2) |
| **Tasks** | Pełny REST CRUD zadań z `TimeSlot`, raport czasowy, eksport CSV/PDF, sync z Google Calendar | Google Calendar API (OAuth2) |
| **Books** | Biblioteka, status (`to_read` / `reading` / `completed`), sesje czytania, metadane po ISBN, eksport CSV/PDF | API Biblioteki Narodowej (XML) |
| **Articles** | Codzienny artykuł do przeczytania, import CSV z Pocket, kategorie, eksport CSV/PDF | — |
| **Music** | Top albumów + lokalna historia odsłuchów (Last.fm), kolekcja winyli (Discogs), porównanie posiadanych vs słuchanych | Last.fm API, Discogs OAuth1 |
| **YouTubeProgress** | Sync playlisty „watchlist" z YouTube, auto-podział nieobejrzanych filmów na sesje ≤30 min (grupowane po kanale), śledzenie postępu, wypchnięcie sesji z powrotem jako nowa playlista | YouTube Data API v3 (OAuth2) |

---

## Architektura

```
src/Module/{Name}/
├── Domain/             ← czysty PHP, agregaty, VO, eventy, repository interfaces
│   ├── Entity/
│   ├── ValueObject/
│   ├── Event/
│   └── Repository/
├── Application/        ← orchestration: commands, queries, handlers, DTOs
│   ├── Command/
│   ├── Handler/
│   ├── Query/
│   ├── QueryHandler/
│   └── DTO/
└── Infrastructure/     ← Doctrine, klienty HTTP, integracje zewnętrzne
    ├── Persistence/Doctrine/    ← .orm.xml mappings
    ├── External/                ← API clients
    └── Messenger/               ← async event handlers
```

**Reguły nienaruszalne:**

- `grep -r "use Doctrine" src/Module/*/Domain/` MUSI zwracać pusty wynik. Egzekwowane przez `make deptrac` w CI — Domain → [], cross-module coupling zakazany.
- Aggregate root gromadzi eventy w `$recordedEvents`, handler dispatchuje po `releaseEvents()` (wzorzec: `Series` aggregate).
- Query handlery używają DBAL bezpośrednio — nie hydratujemy agregatów do odczytu.
- Command handler: `#[AsMessageHandler(bus: 'command.bus')]`. Query handler: `#[AsMessageHandler(bus: 'query.bus')]`. Event handler: `#[AsMessageHandler]` (default bus).

Decyzje architektoniczne (ADR): patrz Confluence space `H` → ADRs.

---

## Stack technologiczny

| Warstwa | Technologia                                              |
|---|----------------------------------------------------------|
| Język | PHP 8.5                                                  |
| Framework | Symfony 8                                                |
| ORM | Doctrine ORM (XML mapping)                               |
| DB | MySQL 8                                                  |
| Cache / KV | Redis 8                                                  |
| Async messaging | RabbitMQ 4.x + Symfony Messenger                         |
| Frontend (Series, Books, YouTubeProgress) | Webpack Encore + Stimulus (Node.js 24 LTS w kontenerze)  |
| Frontend (Tasks, Articles, Music) | Twig + vanilla JavaScript (`public/js/`)                 |
| Testy backendu | PHPUnit 13                                               |
| Testy E2E | Playwright 1.49 (`tests-e2e/`)                           |
| Testy smoke API | Newman / Postman v2.1 (`tests-e2e/postman/`)             |
| Logowanie | Monolog → Graylog 6.3 (GELF UDP) + opcjonalnie New Relic |
| PDF | dompdf/dompdf ^3.1                                       |
| Konteneryzacja | Docker + Docker Compose                                  |

**Static analysis:** PHPStan level 8 (`phpstan-symfony` + `phpstan-doctrine` + `phpstan-phpunit`), PHP CS Fixer (`@Symfony` + `@PHP84Migration`), Rector (`withPhpSets()` + `deadCode`), Deptrac (hexagonal boundaries).

---

## Wymagania

- **Docker Desktop** 4.x lub Docker Engine 24+ z Docker Compose v2.
- **GNU Make** (Windows: `choco install make` lub WSL).
- **Git** 2.40+.
- ~4 GB wolnego RAM dla kontenerów (8 GB jeśli włączasz monitoring stack).

Bezpośrednio na hoście **nie** musisz mieć PHP, Composera, MySQL ani Redisa — wszystko działa w kontenerach.

---

## Szybki start

Pierwsze uruchomienie zajmuje ~5 minut na świeżym hoście (głównie pull obrazów Docker i `composer install`).

### 1. Sklonuj repo i przygotuj sekrety

```bash
git clone git@github.com:zlotylesk/AIHomeManager.git
cd AIHomeManager
cp app/.env app/.env.local
```

Uzupełnij `app/.env.local` zgodnie z sekcją [Konfiguracja](#konfiguracja). **Bez poprawnych kluczy `API_KEY` + `DISCOGS_TOKEN_KEY` + `GOOGLE_TOKEN_KEY` + `TRAKT_TOKEN_KEY` aplikacja nie wystartuje** (DI nie zbootuje value objects z pustymi argumentami — klucze szyfrujące muszą być poprawnym base64 32-bajtowym, inaczej `TokenCipher` rzuca wyjątek). OAuth/API keys (`GOOGLE_CLIENT_*`, `DISCOGS_CONSUMER_*`, `LASTFM_*`, `TRAKT_CLIENT_*`, `YOUTUBE_WATCHLIST_PLAYLIST_ID`) mogą zostać puste do czasu, aż chcesz używać konkretnego modułu — zależne endpointy zwrócą wtedy 503/400 zamiast 500.

### 2. Wystartuj stack

```bash
make setup
```

`make setup` w jednej komendzie: build obrazów Docker → `docker compose up -d` → `composer install` → `npm install` (Node container, dla Webpack Encore) → migracje MySQL → cache warmup.

```bash
make services            # lista kontenerów + porty
make logs                # tail logów wszystkich serwisów
make messenger-status    # czy worker konsumuje async transport
```

### 3. Zbuduj frontend (Webpack Encore)

```bash
make assets-prod         # build artefaktów do public/build/
```

Wymagane — bez `entrypoints.json` Twig wywala 500 na `encore_entry_*` helpers (`base.html.twig` ich używa dla wszystkich stron).

### 4. Adresy serwisów

| Serwis | Adres |
|---|---|
| Aplikacja (UI + API) | http://localhost:8080 |
| Health check (publiczny, bez auth) | http://localhost:8080/api/health |
| RabbitMQ Management | http://localhost:15672 (guest/guest) |
| MySQL | localhost:3306 (homemanager/homemanager, DB `homemanager`) |
| Redis | localhost:6379 |
| Graylog (opcjonalnie) | http://localhost:9000 (admin/admin) — wymaga `make monitoring-up` |

Routes UI: `/` (redirect → `/series`), `/series`, `/tasks`, `/books`, `/articles`, `/music`, `/youtube-progress`.

### 5. (Opcjonalnie) załaduj fixtures + zweryfikuj testy

```bash
make fixtures            # demo data dla dev env
make test                # PHPUnit (unit + integration)
make test-e2e            # Playwright (desktop + mobile)
make test-newman         # Newman/Postman smoke
```

> **Pierwsze uruchomienie modułów wymagających OAuth / API keys** (Google Calendar, YouTube, Discogs, Last.fm, Trakt) krok po kroku opisane na Confluence: [First boot — configuring external services](https://honemanager.atlassian.net/wiki/spaces/H/pages/50659329/First+boot+configuring+external+services).

---

## Konfiguracja

Aplikacja czyta zmienne z `app/.env` (commitowane, placeholdery) i `app/.env.local` (gitignored, faktyczne sekrety).

### Wymagane sekrety w `.env.local`

```dotenv
# Klucz API chroniący /api/* (dowolny silny, losowy ciąg)
API_KEY=...

# Klucze szyfrowania tokenów OAuth at rest (libsodium secretbox) — 32 bajty base64.
# WYMAGANE do startu aplikacji (TokenCipher rzuca dla innej długości klucza).
# Wygeneruj KAŻDY osobno (patrz niżej).
DISCOGS_TOKEN_KEY=...
GOOGLE_TOKEN_KEY=...
TRAKT_TOKEN_KEY=...

# OAuth2 — Google Calendar (Tasks) + YouTube Data API (YouTubeProgress); jeden klient, dwa scope'y
# https://console.cloud.google.com
GOOGLE_CLIENT_ID=...
GOOGLE_CLIENT_SECRET=...
GOOGLE_REDIRECT_URI=http://localhost:8080/auth/google/callback

# YouTubeProgress — ID playlisty "AIHM Watchlist" (część URL po `list=`); puste = /sync zwraca 400
YOUTUBE_WATCHLIST_PLAYLIST_ID=...

# OAuth1 — Discogs (Music) — https://www.discogs.com/settings/developers
DISCOGS_CONSUMER_KEY=...
DISCOGS_CONSUMER_SECRET=...
DISCOGS_USERNAME=...
DISCOGS_CALLBACK_URL=http://localhost:8080/auth/discogs/callback

# Last.fm (Music) — read-only API key — https://www.last.fm/api/account/create
LASTFM_API_KEY=...
LASTFM_USERNAME=...

# OAuth2 — Trakt.tv (Series — import obejrzanych) — https://trakt.tv/oauth/applications
TRAKT_CLIENT_ID=...
TRAKT_CLIENT_SECRET=...
TRAKT_REDIRECT_URI=http://localhost:8080/auth/trakt/callback
```

### Generowanie kluczy szyfrujących

```bash
docker compose exec php php -r "echo base64_encode(sodium_crypto_secretbox_keygen()), PHP_EOL;"
```

Wygeneruj **trzy różne** klucze — osobny dla Discogs, Google i Trakt. Osobne klucze izolują blast radius przy kompromitacji jednego providera.

### Jak zdobyć klucze i tokeny

Pełny przewodnik krok-po-kroku (scope'y, ekran zgody, typowe błędy) jest na Confluence: [First boot — configuring external services](https://honemanager.atlassian.net/wiki/spaces/H/pages/50659329/First+boot+configuring+external+services). Skrót:

| Provider | Moduł | Gdzie założyć | Co trafia do `.env.local` |
|---|---|---|---|
| **Google Cloud** (Calendar + YouTube) | Tasks, YouTubeProgress | [console.cloud.google.com](https://console.cloud.google.com) → nowy projekt → włącz **Google Calendar API** i **YouTube Data API v3** → skonfiguruj ekran zgody (typ *External*, dodaj swoje konto jako *test user*) → *Credentials* → OAuth client ID typu **Web application** z redirect URI `http://localhost:8080/auth/google/callback` | `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET` |
| **YouTube playlist** | YouTubeProgress | Na koncie YouTube utwórz playlistę „AIHM Watchlist" i skopiuj jej ID z URL (część po `list=`) | `YOUTUBE_WATCHLIST_PLAYLIST_ID` |
| **Discogs** | Music | [discogs.com/settings/developers](https://www.discogs.com/settings/developers) → *Create an Application* → callback `http://localhost:8080/auth/discogs/callback` | `DISCOGS_CONSUMER_KEY`, `DISCOGS_CONSUMER_SECRET`, `DISCOGS_USERNAME` |
| **Last.fm** | Music | [last.fm/api/account/create](https://www.last.fm/api/account/create) → utwórz API account | `LASTFM_API_KEY`, `LASTFM_USERNAME` |
| **Trakt.tv** | Series | [trakt.tv/oauth/applications](https://trakt.tv/oauth/applications) → *New Application* → Redirect URI `http://localhost:8080/auth/trakt/callback` | `TRAKT_CLIENT_ID`, `TRAKT_CLIENT_SECRET` |
| **Biblioteka Narodowa** | Books | brak rejestracji — publiczne API `data.bn.org.pl` (throttled 60/min przez współdzielony klient) | — |

Google żąda kumulatywnie scope `calendar.events` **oraz** `youtube` (read/write) na jednym tokenie; po pierwszej autoryzacji oba moduły (Tasks + YouTubeProgress) działają na tym samym, zaszyfrowanym tokenie.

### Pierwsze podłączenie OAuth

Po starcie aplikacji i uzupełnieniu `.env.local` wejdź w przeglądarce na:

- `http://localhost:8080/auth/google` — OAuth2 Google (Calendar + YouTube; wymusza ekran zgody na oba scope)
- `http://localhost:8080/auth/discogs` — OAuth1 Discogs
- `http://localhost:8080/auth/trakt` — OAuth2 Trakt.tv

Tokeny zostaną zaszyfrowane (libsodium secretbox) i zapisane w MySQL. Last.fm i Biblioteka Narodowa nie wymagają flow OAuth — Last.fm działa od razu po ustawieniu klucza API, BN bez żadnych kluczy.

---

## Komendy Makefile

| Akcja | Komenda |
|---|---|
| Start środowiska (pełny stack + monitoring) | `make up` |
| Start środowiska (lean, bez monitoringu) | `make min-up` |
| Pełna inicjalizacja (build + migracje + node install) | `make setup` |
| Stop środowiska | `make down` |
| Preflight env health check | `make doctor` |
| Shell w kontenerze PHP | `make shell` |
| Wszystkie testy | `make test` |
| Tylko unit (Domain) | `make test-unit` |
| Tylko integration | `make test-integration` |
| E2E Playwright (install + run) | `make test-e2e-install` / `make test-e2e` |
| Newman/Postman smoke | `make test-newman-install` / `make test-newman` |
| Migracje dev / test | `make migrate` / `make migrate-test` |
| Cache clear | `make cc` |
| Routing | `make routes` |
| Lista serwisów DI | `make services` |
| Status workera | `make messenger-status` |
| Logi | `make logs` |
| Fixtures (demo data, dev only) | `make fixtures` |
| Webpack Encore (Series + Books) | `make assets` / `make assets-watch` / `make assets-prod` |
| Reinstall npm w node container | `make node-install` |
| Analiza statyczna (CS Fixer + PHPStan + Deptrac) | `make analyse` |
| PHPStan | `make phpstan` / `make phpstan-baseline` |
| CS Fixer | `make cs-check` / `make cs-fix` |
| Rector | `make rector-dry` / `make rector` |
| Deptrac (architecture boundaries) | `make deptrac` / `make deptrac-baseline` |
| Composer / npm audit (CVE gate) | `make audit` / `make node-audit` |
| Doctrine schema validate (ORM XML ↔ MySQL) | `make schema-validate` |
| Backup MySQL (ręczny) | `make backup-now` |
| Restore MySQL z gzipa | `make restore BACKUP=backups/homemanager-YYYY-MM-DD.sql.gz` |
| Monitoring up/down/logs | `make monitoring-up` / `make monitoring-down` / `make monitoring-logs` |
| Graylog bootstrap (inputs + indexes + streams) | `make monitoring-bootstrap` |

---

## Rozwój

### Branche

```
master   ← stable, tylko merge z develop
develop  ← integracja, default dla PR-ów
feature/fix branche  ← tworzone z develop
```

Branche tworzymy z `develop`. Merge do `develop` przez PR.

### Worker Symfony Messenger

Dwa workery: `messenger_worker` (async — `EpisodeRated`, `RefreshDiscogsCollection`, `PollLastFmRecentTracks`) i `scheduler_worker` (`scheduler_default` transport — backup, weekly report, daily article reset itp.). Komenda:

```
bin/console messenger:consume async --time-limit=3600 -vv
bin/console messenger:consume scheduler_default --time-limit=3600 -vv
```

Routing zdefiniowany w `app/config/packages/messenger.yaml`. W test envie transporty są przepięte na `in-memory://`.

### Konwencje nazewnictwa

| Element | Wzorzec | Lokalizacja |
|---|---|---|
| Aggregate Root | `Series`, `Task`, `Book`, `Article` | `Domain/Entity/` |
| Value Object (`final readonly`) | `Rating`, `ISBN`, `CoverUrl`, `TimeSlot` | `Domain/ValueObject/` |
| Command | `CreateSeries`, `LogReadingSession` | `Application/Command/` |
| Command Handler | `*Handler` | `Application/Handler/` |
| Query | `GetAllSeries`, `GetSeriesDetail` | `Application/Query/` |
| Query Handler | `*Handler` | `Application/QueryHandler/` |
| DTO | `*DTO` | `Application/DTO/` |
| Repository Interface | `*RepositoryInterface` | `Domain/Repository/` |
| Repository Implementation | `Doctrine*Repository` | `Infrastructure/Persistence/` |

---

## Testy

```bash
make test               # PHPUnit (Unit + Integration)
make test-unit          # tylko Domain
make test-integration   # tylko integration
make test-e2e           # Playwright (desktop + mobile)
make test-newman        # Newman/Postman smoke
```

- **Unit:** `tests/Unit/Module/{Name}/Domain/` — wzorzec `tests/Unit/Module/Series/Domain/SeriesAggregateTest.php` (gold standard).
- **Integration:** `tests/Integration/` — realna baza + Redis + in-memory transport (`when@test` w `messenger.yaml`).
- Testy `*ApiTest` używają `App\Tests\Support\AuthenticatedApiTrait` — header `X-API-Key: test-api-key` (zob. `app/.env.test`).
- **E2E (Playwright)** w `tests-e2e/`, TypeScript. Files matching `*.desktop.spec.ts` (1440×900) lub `*.mobile.spec.ts` (Pixel 5 viewport).
- **Smoke (Newman)** w `tests-e2e/postman/AIHomeManager.postman_collection.json`. Uruchamiać przez `make test-newman` (truncate + newman z `--ignore-redirects`).
- **E2E/Newman pre-req:** `API_KEY=e2e-test-key` w `app/.env.local`, Discogs/Last.fm/Google placeholders ustawione na cokolwiek niepuste (DI nie zboot'uje się z pustymi VO).

---

## Analiza statyczna

```bash
make analyse              # CS Fixer (dry-run) + PHPStan + Deptrac
make phpstan              # PHPStan analyse
make phpstan-baseline     # regeneruj baseline po naprawie błędów
make cs-check / cs-fix    # PHP CS Fixer
make rector-dry / rector  # Rector
make deptrac              # Deptrac architecture boundaries
```

PHPStan baseline (`app/phpstan-baseline.neon`) trzyma istniejący dług. Nowe błędy wymagają fixu lub explicit dodania do baseline'u przez `make phpstan-baseline`.

Deptrac formalizuje granice heksagonalne: każdy moduł ma osobne layery Domain/Application/Infrastructure. Domain → [] (zero zależności poza PHP core), cross-module coupling zakazany. Pre-existing violations w `skip_violations` (Domain ports zwracające Application DTOs w Books/Music; Music/Tasks Infrastructure → `App\Security\TokenCipher`).

CI (`.github/workflows/ci.yml`) uruchamia na każdym push i PR cztery joby: `static-analysis` (Rector dry-run + CS Fixer + PHPStan level 8 + Deptrac), `tests` (PHPUnit), `e2e-playwright` (Playwright desktop + mobile) oraz `e2e-newman` (Newman API smoke). Joby E2E startują aplikację przez `symfony server:start` (env `test`, `in-memory://` transport) i uploadują raporty HTML jako artifacts (retencja 30 dni).

---

## Monitoring

Stack `graylog + mongodb + opensearch` chodzi pod profilem Compose `monitoring`. `make up` startuje **pełny** stack (wraz z monitoringiem); `make min-up` to wariant lean bez monitoringu. Profilem można też sterować ręcznie:

```bash
make monitoring-up           # start (gdy wcześniej odpaliłeś lean przez make min-up)
make monitoring-bootstrap    # GELF UDP input + index sets + streams (idempotent)
make monitoring-logs         # podgląd
make monitoring-down         # stop
```

Po pierwszym uruchomieniu zaloguj się do http://localhost:9000 (admin/admin). `make monitoring-bootstrap` tworzy GELF UDP input (port 12201), index sets `auth-events` (90 dni retention) i `series-events` (30 dni) plus stream'y filtrujące po `channel`. Brak działającego Graylog nie wywala aplikacji — kanały logów `series`/`auth` są wtedy po cichu dropowane (graceful degrade).

`NewRelicMonologHandler` (`src/Module/Series/Infrastructure/Logging/`) — graceful degrade gdy brak rozszerzenia `newrelic` (logi nie są wysyłane, ale aplikacja nie pada).

### Backup MySQL

Symfony Scheduler odpala `App\Application\Scheduled\BackupDatabase` codziennie o 03:00:

```bash
make backup-now                                         # backup ad-hoc
make restore BACKUP=backups/homemanager-2026-06-01.sql.gz
```

Retention: 30 daily + 12 monthly (1-szy każdego miesiąca pozostaje). Runbook: Confluence → Disaster recovery — MySQL restore.

---

## Struktura projektu

```
.
├── app/                            ← Symfony root
│   ├── assets/                     ← Webpack Encore source
│   │   ├── app.js
│   │   ├── bootstrap.js
│   │   ├── util.js                 ← ES module helpers
│   │   ├── controllers/            ← Stimulus controllers
│   │   └── styles/app.css
│   ├── bin/console
│   ├── config/
│   │   └── packages/
│   │       ├── security.yaml       ← API Key authenticator
│   │       ├── messenger.yaml      ← async transport, routing
│   │       └── rate_limiter.yaml
│   ├── deptrac.yaml                ← architecture boundary config
│   ├── migrations/
│   ├── public/
│   │   ├── index.php
│   │   ├── build/                  ← Encore build output (gitignored)
│   │   └── js/                     ← vanilla JS (Tasks/Articles/Music)
│   ├── src/
│   │   ├── Controller/
│   │   ├── EventListener/
│   │   ├── Health/
│   │   ├── Http/                   ← RateLimitedHttpClient
│   │   ├── Module/                 ← {Series,Tasks,Books,Articles,Music,YouTubeProgress}
│   │   │   └── {Name}/{Domain,Application,Infrastructure}/
│   │   ├── Schedule.php
│   │   └── Security/
│   ├── templates/                  ← Twig
│   ├── tests/
│   │   ├── Unit/
│   │   └── Integration/
│   ├── webpack.config.js
│   ├── composer.json
│   └── phpunit.dist.xml
├── docker/                         ← Dockerfiles, nginx config
├── docker-compose.yml
├── scripts/
│   └── graylog-bootstrap.sh
├── tests-e2e/                      ← Playwright + Newman
│   └── postman/
├── Makefile
├── CHANGELOG.md
├── CLAUDE.md                       ← kontekst dla Claude Code
└── README.md
```

---

## API

### Autentykacja

Endpointy `^/api/*` są chronione firewallem `api` (stateless, custom authenticator). Dodaj header:

```
X-API-Key: <wartość z .env.local>
```

Brak / błędny klucz → `401 {"error": "..."}`.

Wyjątek: `GET /api/health` — publiczny readiness probe (MySQL + Redis + RabbitMQ + 3-stanowy disk probe).

Endpointy `/auth/google*`, `/auth/discogs*`, `/auth/trakt*` oraz UI (`/`, `/series`, …) są publiczne.

### Przykład — Series

```bash
# Lista seriali
curl -H "X-API-Key: $API_KEY" http://localhost:8080/api/series

# Utworzenie serialu
curl -X POST http://localhost:8080/api/series \
  -H "X-API-Key: $API_KEY" \
  -H "Content-Type: application/json" \
  -d '{"title": "Severance"}'

# Ocena odcinka (skala 1–10)
curl -X POST http://localhost:8080/api/series/{seriesId}/seasons/{seasonId}/episodes/{episodeId}/rate \
  -H "X-API-Key: $API_KEY" \
  -H "Content-Type: application/json" \
  -d '{"rating": 9}'
```

### Eksport — Books / Articles / Tasks

```bash
curl -H "X-API-Key: $API_KEY" "http://localhost:8080/api/books/export?format=csv" -o books.csv
curl -H "X-API-Key: $API_KEY" "http://localhost:8080/api/books/export?format=pdf" -o books.pdf
```

Pełna lista endpointów: `make routes`. Szczegółowa dokumentacja API: Confluence → Dokumentacja API.

---

## Linki

- **Confluence hub:** https://honemanager.atlassian.net/wiki/spaces/H/pages/46661633
- **Repozytorium:** https://github.com/zlotylesk/AIHomeManager
- **Dokumentacja kontekstu Claude Code:** [`CLAUDE.md`](CLAUDE.md)
- **Changelog:** [`CHANGELOG.md`](CHANGELOG.md)

---

## Licencja

Projekt prywatny / single-user. Brak publicznej licencji — kontakt z autorem przed użyciem.
