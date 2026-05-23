# AIHomeManager

Single-user system automatyzacji codziennych czynności — telewizja (Series), kalendarz (Tasks), czytelnictwo (Books / Articles) i kolekcja muzyczna (Music). Zbudowany jako modularny monolit Symfony 8 z heksagonalną architekturą i CQRS.

> **Status:** wszystkie 5 modułów wdrożone (HMAI-1—HMAI-30). Cały backlog code review HMAI-44 (59 follow-upów) zamknięty w wydaniu **1.9.0** (2026-05-23). 542/542 PHP + 5/5 Playwright + 34/34 Newman — wszystkie zielone, PHPStan level 8 clean. Projekt w fazie utrzymania.

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
- [Workflow Jira](#workflow-jira)
- [Roadmap](#roadmap)
- [Linki](#linki)
- [Licencja](#licencja)

---

## O projekcie

AIHomeManager agreguje codzienne aktywności jednego użytkownika w pięciu modułach domenowych. Każdy moduł jest niezależny architektonicznie (Domain bez frameworka, własny język ubiquitous), połączone luźno przez CQRS bus i Symfony Messenger. Frontend dual-track: Series UI używa Webpack Encore + Stimulus (od 1.7.0), pozostałe moduły (Tasks/Books/Articles/Music) wciąż na Twig + vanilla JS — wspólny `window.apiCall` z `public/js/util.js`.

**Podstawowe założenia:**

- Pojedynczy użytkownik (brak multi-tenant).
- API stateless chronione kluczem (`X-API-Key`); UI publiczne.
- Heksagonalna architektura — `Domain` nie zna Doctrine ani Symfony.
- Doctrine XML mapping (decyzja architektoniczna ADR-001 — nie migrujemy na atrybuty PHP).
- CQRS z dwoma busami: `command.bus` (default) i `query.bus`, plus `event.bus` dla domain events (Series.`EpisodeRated`, Books.`BookCompleted`).
- Per-IP rate limiting na `^/api/*` (60/min), proaktywny throttle external API klientów (Last.fm, Discogs, BN).
- OAuth tokens szyfrowane at rest (libsodium secretbox, osobne klucze per provider).

---

## Moduły

| Moduł | Co robi | Kluczowe integracje |
|---|---|---|
| **Series** | Katalog seriali, sezony, odcinki, oceny 1–10, średnie wyliczane przez worker | — |
| **Tasks** | Zadania z `TimeSlot`, raport czasowy, sync z Google Calendar | Google Calendar API (OAuth2) |
| **Books** | Lista książek, status (`to_read` / `reading` / `completed`), sesje czytania, metadane po ISBN | API Biblioteki Narodowej (XML) |
| **Articles** | Codzienny artykuł do przeczytania, import CSV, kategorie, deterministyczny wybór "artykułu na dziś" z cache | — |
| **Music** | Top albumów (Last.fm), kolekcja winyli (Discogs), porównanie posiadanych vs słuchanych | Last.fm API, Discogs OAuth1 |

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

- `grep -r "use Doctrine" src/Module/*/Domain/` MUSI zwracać pusty wynik.
- Aggregate root gromadzi eventy w `$recordedEvents`, handler dispatchuje po `releaseEvents()` (wzorzec: `Series` aggregate).
- Query handlery używają DBAL bezpośrednio — nie hydratujemy agregatów do odczytu.
- Command handler: `#[AsMessageHandler(bus: 'command.bus')]`. Query handler: `#[AsMessageHandler(bus: 'query.bus')]`. Event handler: `#[AsMessageHandler]` (default bus).

Pełny opis wzorców i decyzji architektonicznych: [`docs/code-review/HMAI-44-app-review.md`](docs/code-review/HMAI-44-app-review.md).

---

## Stack technologiczny

| Warstwa | Technologia |
|---|---|
| Język | PHP 8.4 |
| Framework | Symfony 8 |
| ORM | Doctrine ORM (XML mapping) |
| DB | MySQL 8 |
| Cache / KV | Redis 7 |
| Async messaging | RabbitMQ 3.12 + Symfony Messenger |
| Frontend (Series) | Webpack Encore + Stimulus (Node.js 24 LTS w kontenerze) |
| Frontend (pozostałe moduły) | Twig + vanilla JavaScript (`public/js/`) |
| Testy backendu | PHPUnit 13 |
| Testy E2E | Playwright 1.49 (`tests-e2e/`) |
| Testy smoke API | Newman / Postman v2.1 (`tests-e2e/postman/`) |
| Logowanie | Monolog → Graylog 5.2 (GELF UDP) + opcjonalnie New Relic |
| Konteneryzacja | Docker + Docker Compose |

**Static analysis:** PHPStan level 8 (`phpstan-symfony` + `phpstan-doctrine` + `phpstan-phpunit`), PHP CS Fixer (`@Symfony` + `@PHP84Migration`), Rector (`withPhpSets()` + `deadCode`).

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

Uzupełnij `app/.env.local` zgodnie z sekcją [Konfiguracja](#konfiguracja). **Bez poprawnych kluczy `API_KEY` + `DISCOGS_TOKEN_KEY` + `GOOGLE_TOKEN_KEY` aplikacja nie wystartuje** (DI nie zbootuje value objects z pustymi argumentami). OAuth keys (`GOOGLE_CLIENT_*`, `DISCOGS_CONSUMER_*`, `LASTFM_*`) mogą zostać puste do czasu, aż chcesz używać konkretnego modułu — Music/Tasks endpointy zwrócą wtedy 503 zamiast 500.

### 2. Wystartuj stack

```bash
make setup
```

`make setup` w jednej komendzie: build obrazów Docker → `docker compose up -d` → `composer install` → `npm install` (Node container, dla Webpack Encore) → migracje MySQL → cache warmup. Sprawdź status:

```bash
make services            # lista kontenerów + porty
make logs                # tail logów wszystkich serwisów
make messenger-status    # czy worker konsumuje async transport
```

### 3. Zbuduj frontend (Webpack Encore)

```bash
make assets-prod         # build artefaktów do public/build/
```

Wymagane tylko dla Series UI (Stimulus controller w `app/assets/`). Pozostałe moduły renderują się z `public/js/*.js` (bez build step).

### 4. Adresy serwisów

| Serwis | Adres |
|---|---|
| Aplikacja (UI + API) | http://localhost:8080 |
| Health check (publiczny, bez auth) | http://localhost:8080/api/health |
| RabbitMQ Management | http://localhost:15672 (guest/guest) |
| MySQL | localhost:3306 (homemanager/homemanager, DB `homemanager`) |
| Redis | localhost:6379 |
| Graylog (opcjonalnie) | http://localhost:9000 (admin/admin) — wymaga `make monitoring-up` |

Routes UI: `/` (redirect → `/series`), `/series`, `/tasks`, `/books`, `/articles`, `/music`.

### 5. (Opcjonalnie) załaduj fixtures + zweryfikuj testy

```bash
make fixtures            # demo data dla dev env
make test                # 542/542 PHPUnit
make test-e2e            # 5/5 Playwright (Series desktop + mobile)
make test-newman         # 34/34 Newman/Postman smoke
```

> **Pierwsze uruchomienie poszczególnych modułów** (OAuth Google/Discogs, Last.fm, fixtures, dane testowe) jest opisane krok-po-kroku na Confluence: [Pierwsze uruchomienie — konfiguracja zewnętrznych serwisów](https://honemanager.atlassian.net/wiki/spaces/H/pages/50659329/Pierwsze+uruchomienie+konfiguracja+zewn+trznych+serwis+w).

---

## Konfiguracja

Aplikacja czyta zmienne z `app/.env` (commitowane, placeholdery) i `app/.env.local` (gitignored, faktyczne sekrety).

### Wymagane sekrety w `.env.local`

```dotenv
# Klucz API chroniący /api/* (dowolny silny ciąg)
API_KEY=...

# OAuth — Google Calendar (https://console.cloud.google.com)
GOOGLE_CLIENT_ID=...
GOOGLE_CLIENT_SECRET=...
GOOGLE_REDIRECT_URI=http://localhost:8080/auth/google/callback

# OAuth — Discogs (https://www.discogs.com/settings/developers)
DISCOGS_CONSUMER_KEY=...
DISCOGS_CONSUMER_SECRET=...
DISCOGS_USERNAME=...
DISCOGS_CALLBACK_URL=http://localhost:8080/auth/discogs/callback

# Last.fm (https://www.last.fm/api/account/create)
LASTFM_API_KEY=...
LASTFM_USERNAME=...

# Klucze szyfrowania tokenów OAuth at rest (libsodium secretbox)
# Wygeneruj: php -r "echo base64_encode(sodium_crypto_secretbox_keygen()), PHP_EOL;"
DISCOGS_TOKEN_KEY=...
GOOGLE_TOKEN_KEY=...
```

### Generowanie kluczy szyfrujących

```bash
docker compose exec php php -r "echo base64_encode(sodium_crypto_secretbox_keygen()), PHP_EOL;"
```

Wygeneruj **dwa różne** klucze — jeden dla Discogs, drugi dla Google. Osobne klucze izolują blast radius przy kompromitacji.

### Pierwsze podłączenie OAuth

Po starcie aplikacji wejdź w przeglądarce na:

- `http://localhost:8080/auth/google` — OAuth flow Google Calendar
- `http://localhost:8080/auth/discogs` — OAuth1 flow Discogs

Tokeny zostaną zaszyfrowane i zapisane w MySQL.

---

## Komendy Makefile

| Akcja | Komenda |
|---|---|
| Start środowiska | `make up` |
| Pełna inicjalizacja (build + migracje + node install) | `make setup` |
| Stop środowiska | `make down` |
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
| Webpack Encore (Series UI) | `make assets` / `make assets-watch` / `make assets-prod` |
| Reinstall npm w node container | `make node-install` |
| Analiza statyczna (CS Fixer dry-run + PHPStan) | `make analyse` |
| PHPStan | `make phpstan` / `make phpstan-baseline` |
| CS Fixer | `make cs-check` / `make cs-fix` |
| Rector | `make rector-dry` / `make rector` |
| Monitoring up/down/logs | `make monitoring-up` / `make monitoring-down` / `make monitoring-logs` |

---

## Rozwój

### Branche

```
master   ← stable, tylko merge z develop
develop  ← integracja, default dla PR-ów
HMAI-XX-krotki-opis  ← feature/fix branch
```

Branche tworzymy z `develop`. Merge do `develop` przez PR.

### Worker Symfony Messenger

Kontener `messenger_worker` konsumuje async eventy (`EpisodeRated`, `RefreshDiscogsCollection`) z RabbitMQ. Komenda:

```
bin/console messenger:consume async --time-limit=3600 -vv
```

Routing zdefiniowany w `app/config/packages/messenger.yaml`. W test envie transport jest przepięty na `in-memory://`.

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
make test               # 542/542 PHPUnit (Unit + Integration)
make test-unit          # tylko Domain
make test-integration   # tylko integration
make test-e2e           # 5/5 Playwright (Series desktop + mobile)
make test-newman        # 34/34 Newman/Postman smoke
```

- **Unit:** `tests/Unit/Module/{Name}/Domain/` — wzorzec `tests/Unit/Module/Series/Domain/SeriesAggregateTest.php` (gold standard).
- **Integration:** `tests/Integration/` — używają realnej bazy + Redis + in-memory transport (`when@test` w `messenger.yaml`).
- Testy `*ApiTest` używają `App\Tests\Support\AuthenticatedApiTrait` — dodaje header `X-API-Key: test-api-key` (zob. `app/.env.test`).
- **E2E (Playwright)** w `tests-e2e/`, TypeScript. Files matching `*.desktop.spec.ts` (1440×900) lub `*.mobile.spec.ts` (Pixel 5 viewport).
- **Smoke (Newman)** w `tests-e2e/postman/AIHomeManager.postman_collection.json` — 34 requesty / 54 asercji. Uruchamiać przez `make test-newman` (truncate + newman z `--ignore-redirects`).
- **E2E/Newman pre-req:** `API_KEY=e2e-test-key` w `app/.env.local`, Discogs/Last.fm/Google placeholders ustawione na cokolwiek niepuste (DI nie zboot'uje się z pustymi VO).

---

## Analiza statyczna

```bash
make analyse              # CS Fixer (dry-run) + PHPStan
make phpstan              # PHPStan analyse
make phpstan-baseline     # regeneruj baseline po naprawie błędów
make cs-check / cs-fix    # PHP CS Fixer
make rector-dry / rector  # Rector
```

PHPStan baseline (`app/phpstan-baseline.neon`) trzyma istniejący dług — celowo, by nie blokować mergy. Nowe błędy wymagają fixu lub explicit dodania do baseline'u przez `make phpstan-baseline`.

CI (`.github/workflows/static-analysis.yml`) uruchamia CS Fixer + PHPStan na każdym push i PR.

---

## Monitoring

Stack `graylog + mongodb + opensearch` chodzi pod profilem Compose `monitoring` — **nie** startuje z `make up`:

```bash
make monitoring-up        # start
make monitoring-logs      # podgląd
make monitoring-down      # stop
```

Po pierwszym uruchomieniu zaloguj się do http://localhost:9000 (admin/admin) i ręcznie skonfiguruj GELF UDP input: **System → Inputs → GELF UDP → Launch new input**, port 12201.

`NewRelicMonologHandler` (`src/Module/Series/Infrastructure/Logging/`) — graceful degrade gdy brak rozszerzenia `newrelic` (logi nie są wysyłane, ale aplikacja nie pada).

---

## Struktura projektu

```
.
├── app/                            ← Symfony root
│   ├── bin/console
│   ├── config/
│   │   └── packages/
│   │       ├── security.yaml       ← API Key authenticator
│   │       └── messenger.yaml      ← async transport, routing
│   ├── migrations/
│   ├── public/
│   │   ├── index.php
│   │   ├── css/
│   │   └── js/                     ← vanilla JS, jeden plik per moduł
│   ├── src/
│   │   ├── Controller/
│   │   ├── Module/                 ← {Series,Tasks,Books,Articles,Music}
│   │   │   └── {Name}/{Domain,Application,Infrastructure}/
│   │   └── Security/
│   ├── templates/                  ← Twig
│   ├── tests/
│   │   ├── Unit/
│   │   └── Integration/
│   ├── composer.json
│   └── phpunit.dist.xml
├── docker/                         ← Dockerfiles, nginx config
├── docker-compose.yml
├── docs/
│   └── code-review/
├── Makefile
├── CLAUDE.md                       ← kontekst dla Claude Code (architektura, konwencje)
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

Endpointy `/auth/google*`, `/auth/discogs*` oraz UI (`/`, `/series`, …) są publiczne.

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

Pełna lista endpointów: `make routes`.

---

## Workflow Jira

Każde zadanie HMAI-XX = jeden branch + jeden PR. Schemat:

1. `git checkout develop && git pull`
2. `git checkout -b HMAI-XX-krotki-opis`
3. Implementacja zgodnie z hexagonal + Doctrine XML.
4. `make test` musi być zielony.
5. PR do `develop` z tytułem `HMAI-XX - {Title EN}`.
6. Status Jira → Code Review.

Code review HMAI-44 (kwiecień 2026, 9 epików tematycznych [HMAI-123..132](https://honemanager.atlassian.net/jira/software/projects/HMAI/boards)) — wszystkie 59 follow-upów **zamknięte 2026-05-23 w release 1.9.0**. Aktywny backlog: brak; nowe pomysły idą do project board jako standalone tickety.

---

## Roadmap

**Status:** wszystkie domknięte epiki code review (HMAI-123 .. HMAI-132) są zamknięte i wydane. Pełna oś czasu wydań w [`CHANGELOG.md`](CHANGELOG.md). Kluczowe ostatnie wydania:

| Tag | Data | Tema |
|---|---|---|
| **1.9.0** | 2026-05-23 | Domain & DDD purity (HMAI-131) + CSV exports (HMAI-132) — zamyka backlog HMAI-44 |
| 1.8.0 | 2026-05-21 | API hardening (HMAI-129) — `ApiExceptionListener`, walidacje per moduł |
| 1.7.1/1.7.0 | 2026-05-19/18 | Frontend hardening (HMAI-128) + Webpack Encore + Stimulus dla Series |
| 1.6.0 | 2026-05-17 | Operability (HMAI-126) — `/api/health`, Scheduler, fixtures, API metrics |
| 1.5.0 | 2026-05-17 | Persistence (HMAI-124) — N+1 fix, lookup indexes, hydrator extraction |
| 1.4.0 | 2026-05-16 | Test coverage (HMAI-125) — +120 testów po code review |
| 1.3.0 | 2026-05-16 | External API resilience (HMAI-127) + rate limiting (HMAI-130) |
| 1.2.0 | 2026-05-07 | Critical findings (HMAI-123) — wszystkie 12 P0 blockers |

**Co dalej:** projekt jest w fazie utrzymania. Nowe ficzery + zmiany architektoniczne wymagają nowego sourcing'u ticketów (np. po kolejnym audytcie albo z user-facing feedback). Pojedyncze tickety bez epica: dodaj bezpośrednio do project board, oznacz `fixVersion` przy mergu PR-a.

---

## Linki

- **Confluence hub:** https://honemanager.atlassian.net/wiki/spaces/H/pages/46661633
- **Code review HMAI-44:** https://honemanager.atlassian.net/wiki/spaces/H/pages/52658177
- **Jira board:** https://honemanager.atlassian.net/jira/software/projects/HMAI/boards
- **Repozytorium:** https://github.com/zlotylesk/AIHomeManager
- **Dokumentacja kontekstu Claude Code:** [`CLAUDE.md`](CLAUDE.md)

---

## Licencja

Projekt prywatny / single-user. Brak publicznej licencji — kontakt z autorem przed użyciem.
