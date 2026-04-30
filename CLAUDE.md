# AIHomeManager — Claude Code Context

## Projekt
System automatyzacji codziennych czynności. Backend: PHP 8.4 + Symfony 8 + MySQL.
Moduły (zaimplementowane): Series, Articles (import CSV). [PLANNED]: Tasks, Books, Music. Frontend: Twig + vanilla JS (Series UI).

## Frontend
- Silnik szablonów: `symfony/twig-bundle` (zainstalowany)
- Statyczne assety: `public/css/app.css`, `public/js/series.js` — bez Webpack/Node.js
- Frontend controller: `src/Controller/FrontendController.php` — `GET /` → redirect, `GET /series` → Twig SPA shell
- Szablony: `templates/base.html.twig`, `templates/series/index.html.twig`
- JS komunikuje się z REST API przez `fetch()` (vanilla JS, brak frameworka)
- Selektor ocen: 10 przycisków (1–10), nie `<input type=number>`
- Brak CORS — frontend i API serwowane z tego samego kontenera
- Brakujący zakres (Jira): HMAI-41 (Webpack Encore + Stimulus), HMAI-42 (testy E2E), HMAI-43 (PATCH rating endpoint)

## Uruchamianie i testowanie
- Środowisko: `make up` (uruchom kontenery), `make setup` (pełna inicjalizacja)
- Shell PHP: `make shell`
- Testy: `make test` (wszystkie), `make test-unit` (Domain), `make test-integration` (API)
- Cache: `make cc`
- Migracje: `make migrate` (dev), `make migrate-test` (test)
- Logi: `make logs`
- Routing: `make routes`
- Kontenery: `make services`
- Messenger: `make messenger-status`

## Architektura — ZASADY NIENARUSZALNE
- Architektura heksagonalna: Domain / Application / Infrastructure
- Struktura modułu: `src/Module/{Name}/Domain/`, `Application/`, `Infrastructure/`
- Doctrine XML mapping w `Infrastructure/Persistence/Doctrine/*.orm.xml` — NIGDY nie zmieniać na atrybuty PHP
- Weryfikacja: `grep -r "use Doctrine" src/Module/*/Domain/` musi zwracać pusty wynik
- Domain Events: gromadzone w `$recordedEvents` na agregacie, dispatchowane przez handler po `releaseEvents()`
- Query handlery: czytają przez DBAL (nie ORM) — nie hydratuj agregatów do odczytu

## Konwencje nazewnictwa
- Aggregate Root: `Series`, `Task`, `Book`
- Value Object: `Rating`, `ISBN`, `TimeSlot` (immutable, bez setterów)
- Command: `CreateSeries`, `AddEpisodeRating`
- Command Handler: `CreateSeriesHandler` (#[AsMessageHandler(bus: 'command.bus')])
- Query Handler: `GetAllSeriesHandler` (#[AsMessageHandler(bus: 'query.bus')])
- Query: `GetAllSeries`, `GetSeriesDetail`
- DTO: `SeriesDetailDTO`, `EpisodeDTO`
- Repository Interface: `SeriesRepositoryInterface`
- Repository Impl: `DoctrineSeriesRepository`

## Struktura testów
- Testy jednostkowe: `tests/Unit/Module/{Name}/Domain/`
- Testy integracyjne: `tests/Integration/`
- Framework: PHPUnit 13
- Wzorzec: `app/tests/Unit/Module/Series/Domain/SeriesAggregateTest.php`

## Kluczowe zmienne środowiskowe (.env)
- `DATABASE_URL=mysql://homemanager:homemanager@mysql:3306/homemanager?serverVersion=8.0&charset=utf8mb4`
- `MESSENGER_TRANSPORT_DSN=amqp://guest:guest@rabbitmq:5672/%2f/messages`
- `REDIS_URL=redis://redis:6379`

## Infrastruktura — Redis
- Kontener: `redis:7-alpine`, port 6379
- Serwis Redis: `app.redis` via `RedisAdapter::createConnection('%env(REDIS_URL)%')`
- Cache pool: `series.ratings.cache` (Redis, TTL 3600)
- Klucze Redis: `series:avg:{id}`, `season:avg:{id}` — ustawiane przez `EpisodeRatedHandler`

## Infrastruktura — RabbitMQ + Messenger Worker
- Kontener: `rabbitmq:3.12-management-alpine`, porty 5672 (AMQP) i 15672 (Management UI, guest/guest)
- Worker: `messenger_worker` konsumuje transport `async` (`bin/console messenger:consume async --time-limit=3600 -vv`)
- Transport `async`: AMQP, exchange `series_events` (topic), retry 3× (1s→2s→4s, max 30s), DLQ: `failed`
- `MESSENGER_TRANSPORT_DSN=amqp://guest:guest@rabbitmq:5672/%2f/messages`
- Event handlery w `Infrastructure/Messenger/` — `#[AsMessageHandler]` bez `bus:` (domyślny command.bus)
- W testach: transport `async` i `failed` nadpisywane przez `in-memory://` (`when@test` w messenger.yaml)

## Infrastruktura — Graylog + New Relic (centralne logowanie i APM)

- Serwisy uruchamiane **wyłącznie przez profil monitoring**: `make monitoring-up` / `make monitoring-down`
- Domyślny `make up` **nie uruchamia** Grayloga (za ciężki na codzienne dev)
- Stack monitoring: `mongodb:6` + `opensearch:2` + `graylog/graylog:5.2`
- Graylog UI: http://localhost:9000 (login: admin / admin)
- GELF UDP input: port 12201 — **musi być skonfigurowany ręcznie** w Graylog UI po pierwszym uruchomieniu:
  System → Inputs → GELF UDP → Launch new input
- Kanał Monolog: `series` — logują do niego: `SeriesController`, `CreateSeriesHandler`, `AddEpisodeHandler`, `AddEpisodeRatingHandler`
- Poziomy logowania: dev → debug+, prod → warning+
- `NewRelicMonologHandler` w `src/Module/Series/Infrastructure/Logging/` — jeśli extension `newrelic` nie jest zainstalowane, handler cicho pomija (bez wyjątku)
- Zmienne środowiskowe: `GRAYLOG_HOST`, `GRAYLOG_PORT=12201`, `NEW_RELIC_LICENSE_KEY`, `NEW_RELIC_APP_NAME`
- Makefile: `make monitoring-up`, `make monitoring-down`, `make monitoring-logs`

## Infrastruktura — MCP Servers

- Node.js wymagany: v18+ (zainstalowane: v24.x LTS)
- Konfiguracja w `.mcp.json` w katalogu projektu (nie globalnie)
- Serwery skonfigurowane:
  - `sequential-thinking` — `npx @modelcontextprotocol/server-sequential-thinking`
  - `github` — `npx @modelcontextprotocol/server-github` (wymaga `GITHUB_PERSONAL_ACCESS_TOKEN`)
  - `context7` — `npx @upstash/context7-mcp`
  - `filesystem` — `npx @modelcontextprotocol/server-filesystem` (root: projekt AIHM)
- Atlassian Rovo MCP konfigurowany przez claude.ai (nie przez `.mcp.json`)

## Zasady pracy z Claude Code
- Przed każdym git commit pokaż mi pełny diff i zaproponowany commit message. Nie commituj bez mojej zgody.
- Po każdej zmianie kodu uruchom make test. Jeśli testy nie przechodzą, napraw błędy przed zgłoszeniem gotowości.
- Zawsze zaczynaj od przeczytania pliku CLAUDE.md i opisania planu przed implementacją.
- Jedno zadanie Jira = jedna sesja Claude Code.