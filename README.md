# AIHomeManager

Single-user system automatyzacji codziennych czynności — telewizja (Series), kalendarz (Tasks), czytelnictwo (Books / Articles) i kolekcja muzyczna (Music). Zbudowany jako modularny monolit Symfony 8 z heksagonalną architekturą i CQRS.

> **Status:** wszystkie 5 modułów zaimplementowane (HMAI-1—HMAI-30). Critical findings z code review (HMAI-44) zamknięte w 2026-05-07. Pre-prod release blokowany przez wybrane Major issues — patrz [Roadmap](#roadmap).

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

AIHomeManager agreguje codzienne aktywności jednego użytkownika w pięciu modułach domenowych. Każdy moduł jest niezależny architektonicznie (Domain bez frameworka, własny język ubiquitous), połączone luzem przez CQRS bus i Symfony Messenger. Frontend to Twig + vanilla JS — bez Webpack/Node.js.

**Podstawowe założenia:**

- Pojedynczy użytkownik (brak multi-tenant).
- API stateless chronione kluczem (`X-API-Key`); UI publiczne.
- Heksagonalna architektura — `Domain` nie zna Doctrine ani Symfony.
- Doctrine XML mapping (decyzja architektoniczna ADR-001 — nie migrujemy na atrybuty PHP).
- CQRS z dwoma busami: `command.bus` (default) i `query.bus`, plus `event.bus` dla domain events.

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
| Frontend | Twig + vanilla JavaScript (bez Webpack/Node.js) |
| Testy | PHPUnit 13 |
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

```bash
git clone git@github.com:zlotylesk/AIHomeManager.git
cd AIHomeManager
cp app/.env app/.env.local         # uzupełnij sekrety (patrz "Konfiguracja")
make setup                          # build + up + composer install + migracje
```

Po `make setup`:

| Serwis | Adres |
|---|---|
| Aplikacja (UI + API) | http://localhost:8080 |
| RabbitMQ Management | http://localhost:15672 (guest/guest) |
| MySQL | localhost:3306 (homemanager/homemanager, DB `homemanager`) |
| Redis | localhost:6379 |
| Graylog (opcjonalnie) | http://localhost:9000 (admin/admin) — wymaga `make monitoring-up` |

Routes UI: `/` (redirect), `/series`, `/tasks`, `/books`, `/articles`, `/music`.

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
| Pełna inicjalizacja (build + migracje) | `make setup` |
| Stop środowiska | `make down` |
| Shell w kontenerze PHP | `make shell` |
| Wszystkie testy | `make test` |
| Tylko unit (Domain) | `make test-unit` |
| Tylko integration | `make test-integration` |
| Migracje dev / test | `make migrate` / `make migrate-test` |
| Cache clear | `make cc` |
| Routing | `make routes` |
| Lista serwisów DI | `make services` |
| Status workera | `make messenger-status` |
| Logi | `make logs` |
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
make test               # 299/299 passing
make test-unit          # tylko Domain
make test-integration   # tylko integration
```

- **Unit:** `tests/Unit/Module/{Name}/Domain/` — wzorzec `tests/Unit/Module/Series/Domain/SeriesAggregateTest.php` (gold standard).
- **Integration:** `tests/Integration/` — używają realnej bazy + Redis + in-memory transport.
- Testy `*ApiTest` używają `App\Tests\Support\AuthenticatedApiTrait` — dodaje header `X-API-Key: test-api-key`.

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

Restruktura follow-upów z code review HMAI-44 (2026-05-07): 9 epików tematycznych [HMAI-123..132](https://honemanager.atlassian.net/jira/software/projects/HMAI/boards) — patrz `CLAUDE.md`.

---

## Roadmap

**Pre-prod blokery (P1):**

- M1 (CSRF) → [HMAI-129](https://honemanager.atlassian.net/browse/HMAI-129)
- M17/M18 (testy OAuth) → [HMAI-125](https://honemanager.atlassian.net/browse/HMAI-125)
- M23 (exception handler — JSON zamiast HTML na 500) → [HMAI-129](https://honemanager.atlassian.net/browse/HMAI-129)

**Operability:**

- `/health` endpoint → [HMAI-37](https://honemanager.atlassian.net/browse/HMAI-37) (epic [HMAI-126](https://honemanager.atlassian.net/browse/HMAI-126))
- Symfony Scheduler → [HMAI-35](https://honemanager.atlassian.net/browse/HMAI-35)
- Webpack Encore + Stimulus → [HMAI-41](https://honemanager.atlassian.net/browse/HMAI-41)

**Features:**

- Eksport CSV/PDF → [HMAI-36](https://honemanager.atlassian.net/browse/HMAI-36) (epic [HMAI-132](https://honemanager.atlassian.net/browse/HMAI-132))
- PATCH endpoint dla rating → [HMAI-43](https://honemanager.atlassian.net/browse/HMAI-43)

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
