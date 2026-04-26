# AIHomeManager — Claude Code Context

## Projekt
System automatyzacji codziennych czynności. Backend: PHP 8.4 + Symfony 8 + MySQL.
Moduły: Series, Tasks, Books, Music, Articles. Frontend: Twig/Stimulus lub React.

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
- Handler: `CreateSeriesHandler` (#[AsMessageHandler])
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
- `DATABASE_URL=mysql://homemanager:homemanager@mysql:3306/homemanager`
- `MESSENGER_TRANSPORT_DSN=doctrine://default?auto_setup=0`
- `REDIS_URL=redis://redis:6379`

## Infrastruktura — Redis
- Kontener: `redis:7-alpine`, port 6379
- Worker: `messenger_worker` konsumuje transport `async` (`bin/console messenger:consume async`)
- Event handlery w `Infrastructure/Messenger/` — `#[AsMessageHandler]` bez `bus:` (domyślny command.bus)
- Serwis Redis: `app.redis` via `RedisAdapter::createConnection('%env(REDIS_URL)%')`
- Cache pool: `series.ratings.cache` (Redis, TTL 3600)
- Klucze Redis: `series:avg:{id}`, `season:avg:{id}` — ustawiane przez `EpisodeRatedHandler`
- W testach: transport `async` nadpisywany przez `in-memory://` (`when@test` w messenger.yaml)

## Zasady pracy z Claude Code
- Przed każdym git commit pokaż mi pełny diff i zaproponowany commit message. Nie commituj bez mojej zgody.
- Po każdej zmianie kodu uruchom make test. Jeśli testy nie przechodzą, napraw błędy przed zgłoszeniem gotowości.
- Zawsze zaczynaj od przeczytania pliku CLAUDE.md i opisania planu przed implementacją.
- Jedno zadanie Jira = jedna sesja Claude Code.