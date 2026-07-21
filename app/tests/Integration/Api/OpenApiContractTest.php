<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use App\Tests\Support\AuthenticatedApiTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\ValidationResult;
use Opis\JsonSchema\Validator;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * HMAI-343 — OpenAPI response↔schema conformance.
 *
 * Closes the contract epic (HMAI-311) as a CI quality gate: representative
 * endpoint responses are validated against the schema the generated OpenAPI 3.1
 * contract declares for the status code each one actually returns. Any drift
 * between a normalizer's real JSON and its documented schema fails here — so the
 * contract stops being documentation-only and becomes an enforced boundary.
 *
 * The contract is OpenAPI 3.1 (its schemas are JSON Schema 2020-12), so
 * validation uses opis/json-schema (native 2020-12) rather than a
 * cebe/php-openapi-based validator, which only understands 3.0.
 */
final class OpenApiContractTest extends WebTestCase
{
    use AuthenticatedApiTrait;

    /** Base URI the whole spec is registered under, so intra-document $refs resolve. */
    private const string SPEC_URI = 'https://homemanager.local/openapi.json';

    private KernelBrowser $client;

    /** @var array<mixed> The decoded OpenAPI document (array form, for navigation). */
    private array $spec;

    private Validator $validator;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->authenticate($this->client);
        $this->truncate('series_episodes', 'series_seasons', 'series', 'tasks', 'articles', 'goals', 'book_reading_sessions', 'movies', 'notifications', 'notification_preferences', 'podcast_listening_sessions', 'podcast_episodes', 'podcasts');

        $content = $this->fetchSpecContent();

        $specArray = json_decode($content, true);
        self::assertIsArray($specArray);
        $this->spec = $specArray;

        // opis needs the object form (stdClass maps) so #/components/* $refs resolve.
        $specObject = json_decode($content);
        self::assertIsObject($specObject);

        $this->validator = new Validator();
        $resolver = $this->validator->resolver();
        self::assertNotNull($resolver);
        $resolver->registerRaw($specObject, self::SPEC_URI);
    }

    public function testHealthResponseConformsToContract(): void
    {
        // Public probe: validated against whichever documented status the test
        // environment reports (200 healthy/degraded, or 503 unhealthy).
        $this->assertResponseConformsToContract('GET', '/api/health', '/api/health');
    }

    public function testSeriesListResponseConformsToContract(): void
    {
        $this->seedSeries();
        $this->assertResponseConformsToContract('GET', '/api/v1/series', '/api/v1/series');
    }

    public function testSeriesDetailResponseConformsToContract(): void
    {
        // The richest DTO in the contract: nested seasons/episodes plus the disjoint
        // own/season/episode ratings, the computed averageRating and the watched flag.
        $id = $this->seedSeries();
        $this->assertResponseConformsToContract('GET', '/api/v1/series/'.$id, '/api/v1/series/{id}');
    }

    public function testTasksListResponseConformsToContract(): void
    {
        $this->seedTask();
        $this->assertResponseConformsToContract('GET', '/api/v1/tasks', '/api/v1/tasks');
    }

    public function testTasksDetailResponseConformsToContract(): void
    {
        $id = $this->seedTask();
        $this->assertResponseConformsToContract('GET', '/api/v1/tasks/'.$id, '/api/v1/tasks/{id}');
    }

    public function testArticlesListResponseConformsToContract(): void
    {
        $this->seedArticle();
        $this->assertResponseConformsToContract('GET', '/api/v1/articles', '/api/v1/articles');
    }

    public function testGoalsListResponseConformsToContract(): void
    {
        // Goals is the cross-module gamification layer: a goal plus seeded activity
        // yields a populated GoalProgressDTO (non-zero achieved/percent, met flag).
        $this->seedGoalWithActivity();
        $this->assertResponseConformsToContract('GET', '/api/v1/goals', '/api/v1/goals');
    }

    public function testGoalsStreaksResponseConformsToContract(): void
    {
        // The streak read model carries the day-continuity run and a non-null
        // lastActivityDate once activity exists — the populated branch of StreakDTO.
        $this->seedGoalWithActivity();
        $this->assertResponseConformsToContract('GET', '/api/v1/goals/streaks', '/api/v1/goals/streaks');
    }

    public function testPodcastsListResponseConformsToContract(): void
    {
        // A show with listening behind it, so the counters and lastListenedAt
        // are populated rather than the empty-catalog branch.
        $this->seedPodcast();
        $this->assertResponseConformsToContract('GET', '/api/v1/podcasts', '/api/v1/podcasts');
    }

    public function testPodcastsDetailResponseConformsToContract(): void
    {
        // The flattened show plus both nested collections — the episode carrying
        // aggregated progress and the session carrying its episode title.
        $id = $this->seedPodcast();
        $this->assertResponseConformsToContract('GET', '/api/v1/podcasts/'.$id, '/api/v1/podcasts/{id}');
    }

    public function testSearchResponseConformsToContract(): void
    {
        // Global search: one indexed document makes the query return a populated
        // SearchResult (type enum + snippet), the non-empty branch of the contract.
        $connection = static::getContainer()->get(EntityManagerInterface::class)->getConnection();
        $connection->executeStatement('DELETE FROM search_documents');
        $connection->insert('search_documents', [
            'type' => 'book', 'source_id' => 'contract-book', 'title' => 'Contract Guide',
            'content' => 'contract conformance handbook', 'url' => '/books',
        ]);

        $this->assertResponseConformsToContract('GET', '/api/v1/search?q=contract', '/api/v1/search');
    }

    public function testTrendsResponseConformsToContract(): void
    {
        // Seed one reading session and one completed-vs-pending task pair so both
        // shapes a series can take are validated: a cumulative count metric and a
        // rate metric, each with a populated point list rather than the all-zero
        // window an empty database would return.
        $connection = static::getContainer()->get(EntityManagerInterface::class)->getConnection();
        $connection->insert('book_reading_sessions', [
            'id' => 'contract-session', 'book_id' => 'contract-book', 'date' => '2026-07-08', 'pages_read' => 40,
        ]);
        $connection->insert('tasks', [
            'id' => 'contract-task-done', 'title' => 'Done', 'status' => 'completed',
            'time_start' => '2026-07-08 09:00:00', 'time_end' => '2026-07-08 10:00:00',
        ]);
        $connection->insert('tasks', [
            'id' => 'contract-task-open', 'title' => 'Open', 'status' => 'pending',
            'time_start' => '2026-07-08 11:00:00', 'time_end' => '2026-07-08 12:00:00',
        ]);

        $this->assertResponseConformsToContract(
            'GET',
            '/api/v1/trends?granularity=week&from=2026-07-01&to=2026-07-31',
            '/api/v1/trends',
        );
    }

    public function testDashboardResponseConformsToContract(): void
    {
        // The cockpit composes every module's "today" slice. Seed one row per widget
        // so each section's populated branch — task time range, the daily article, a
        // goal snapshot with a non-null streak, a series recommendation, a recent
        // track — is validated against the documented DashboardDTO schema, not just
        // the empty arrays/null a bare cockpit would return.
        $this->seedDashboardToday();
        $this->assertResponseConformsToContract('GET', '/api/v1/dashboard', '/api/v1/dashboard');
    }

    public function testMoviesListResponseConformsToContract(): void
    {
        // A fully-populated flat MovieDTO: watched with a timestamp, an own rating
        // and every optional metadata field, so the response exercises the non-null
        // branch of each field rather than the nulls a bare movie would return.
        $this->seedMovie();
        $this->assertResponseConformsToContract('GET', '/api/v1/movies', '/api/v1/movies');
    }

    public function testMoviesDetailResponseConformsToContract(): void
    {
        $id = $this->seedMovie();
        $this->assertResponseConformsToContract('GET', '/api/v1/movies/'.$id, '/api/v1/movies/{id}');
    }

    public function testNotificationsPreferencesResponseConformsToContract(): void
    {
        // The panel always returns every type, so the populated branch needs one
        // configured preference (a narrowed channel set and a quiet window)
        // alongside the defaults the other types fall back to.
        $this->seedNotificationPreference();
        $this->assertResponseConformsToContract('GET', '/api/v1/notifications/preferences', '/api/v1/notifications/preferences');
    }

    public function testNotificationsHistoryResponseConformsToContract(): void
    {
        // Both delivery outcomes: a sent notification (non-null sentAt, null
        // failureReason) and a failed one (the inverse), so neither nullable
        // branch of NotificationDTO goes unchecked.
        $this->seedNotificationHistory();
        $this->assertResponseConformsToContract('GET', '/api/v1/notifications/history', '/api/v1/notifications/history');
    }

    public function testContractValidationRejectsDriftingResponse(): void
    {
        // Negative control: a payload that breaks EpisodeDTO.number (integer) must be
        // rejected — proof the checks above would catch a real contract↔response drift.
        $schema = $this->responseSchema('/api/v1/series/{id}', 'get', 200);
        self::assertNotNull($schema, 'The series-detail 200 schema must be documented.');

        $drifted = json_decode('{"id":"x","title":"x","createdAt":"x","seasons":'
            .'[{"id":"s","number":1,"episodes":[{"id":"e","title":"E","number":"not-an-integer"}]}]}');

        self::assertFalse(
            $this->validator->validate($drifted, $schema)->isValid(),
            'The contract validator must reject a response that drifts from the schema.',
        );
    }

    /**
     * Request the endpoint and validate its body against the schema documented for
     * the status code it actually returned — the drift-catching core of this suite.
     */
    private function assertResponseConformsToContract(string $method, string $requestUri, string $specPath): void
    {
        $this->client->request($method, $requestUri);
        $status = $this->client->getResponse()->getStatusCode();
        $verb = strtolower($method);

        $responses = $this->dig($this->spec, 'paths', $specPath, $verb, 'responses');
        self::assertIsArray($responses, sprintf('Operation %s %s is not documented in the contract.', $method, $specPath));
        self::assertArrayHasKey($status, $responses, sprintf('%s %s returned undocumented status %d.', $method, $specPath, $status));

        $schema = $this->responseSchema($specPath, $verb, $status);
        if (null === $schema) {
            // Documented status with no JSON body (e.g. 204) — nothing to validate.
            return;
        }

        $body = json_decode((string) $this->client->getResponse()->getContent());
        $result = $this->validator->validate($body, $schema);

        self::assertTrue(
            $result->isValid(),
            sprintf(
                "%s %s (HTTP %d) response does not conform to its OpenAPI schema:\n%s",
                $method,
                $requestUri,
                $status,
                $this->formatErrors($result),
            ),
        );
    }

    /**
     * The application/json response schema documented for a status, as an opis-ready
     * object with intra-document $refs made absolute against the registered spec, or
     * null when the status carries no JSON body.
     */
    private function responseSchema(string $path, string $method, int $status): ?object
    {
        $node = $this->dig($this->spec, 'paths', $path, $method, 'responses', $status, 'content', 'application/json', 'schema');
        if (!is_array($node)) {
            return null;
        }

        $decoded = json_decode((string) json_encode($this->absolutizeRefs($node), \JSON_UNESCAPED_SLASHES));
        self::assertIsObject($decoded);

        return $decoded;
    }

    /**
     * Rewrite intra-document "#/..." $refs to absolute "<spec-uri>#/..." so the
     * extracted schema fragment can resolve components against the registered spec.
     * Local "#/components/*" pointers carry no path templates, so this also sidesteps
     * opis reading a "{id}" path key as an RFC-6570 URI template.
     */
    private function absolutizeRefs(mixed $node): mixed
    {
        if (!is_array($node)) {
            return $node;
        }

        $out = [];
        foreach ($node as $key => $value) {
            if ('$ref' === $key && is_string($value) && str_starts_with($value, '#/')) {
                $out[$key] = self::SPEC_URI.$value;
            } else {
                $out[$key] = $this->absolutizeRefs($value);
            }
        }

        return $out;
    }

    /** Walk a decoded-JSON tree by keys, returning null the moment a level is absent. */
    private function dig(mixed $node, int|string ...$keys): mixed
    {
        foreach ($keys as $key) {
            if (!is_array($node) || !array_key_exists($key, $node)) {
                return null;
            }
            $node = $node[$key];
        }

        return $node;
    }

    private function formatErrors(ValidationResult $result): string
    {
        $error = $result->error();
        if (null === $error) {
            return '(no error details)';
        }

        return (string) json_encode(new ErrorFormatter()->format($error), \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES);
    }

    private function fetchSpecContent(): string
    {
        $this->client->request('GET', '/api/doc.json');
        self::assertResponseIsSuccessful();

        $content = $this->client->getResponse()->getContent();
        self::assertIsString($content);

        return $content;
    }

    private function seedSeries(): string
    {
        $seriesId = $this->postForId('/api/v1/series', [
            'title' => 'Breaking Bad',
            'year' => 2008,
            'status' => 'ended',
            'coverUrl' => 'https://example.com/bb.jpg',
            'description' => 'A high-school chemistry teacher turned manufacturer.',
        ]);
        $seasonId = $this->postForId('/api/v1/series/'.$seriesId.'/seasons', ['number' => 1]);
        $episodeId = $this->postForId(
            '/api/v1/series/'.$seriesId.'/seasons/'.$seasonId.'/episodes',
            ['title' => 'Pilot', 'number' => 1, 'rating' => 8],
        );

        // Exercise the disjoint own ratings + the watched flag on the read model.
        $this->patch('/api/v1/series/'.$seriesId.'/rating', ['rating' => 9]);
        $this->patch('/api/v1/series/'.$seriesId.'/seasons/'.$seasonId.'/rating', ['rating' => 7]);
        $this->patch('/api/v1/series/'.$seriesId.'/seasons/'.$seasonId.'/episodes/'.$episodeId.'/watched', ['watched' => true]);

        return $seriesId;
    }

    private function seedMovie(): string
    {
        $id = $this->postForId('/api/v1/movies', [
            'title' => 'Blade Runner 2049',
            'year' => 2017,
            'status' => 'released',
            'coverUrl' => 'https://example.com/br2049.jpg',
            'description' => 'A young blade runner uncovers a long-buried secret.',
        ]);

        // Populate the watched flag (+ watchedAt) and the own rating on the read model.
        $this->patch('/api/v1/movies/'.$id.'/watched', ['watched' => true]);
        $this->patch('/api/v1/movies/'.$id.'/rating', ['rating' => 9]);

        return $id;
    }

    private function seedTask(): string
    {
        return $this->postForId('/api/v1/tasks', [
            'title' => 'Contract conformance task',
            'start' => '2026-06-01T09:00:00+02:00',
            'end' => '2026-06-01T10:30:00+02:00',
        ]);
    }

    private function seedArticle(): string
    {
        return $this->postForId('/api/v1/articles', [
            'title' => 'A representative article',
            'url' => 'https://example.com/article',
        ]);
    }

    /**
     * Define a book-pages goal and seed one same-day reading session, so both the
     * progress (achieved/percent/met) and streak (currentLength/lastActivityDate)
     * read models carry populated, non-null values rather than zeros.
     */
    private function seedGoalWithActivity(): void
    {
        $this->postForId('/api/v1/goals', ['type' => 'book_pages', 'target' => 50, 'period' => 'daily']);

        $connection = static::getContainer()->get(EntityManagerInterface::class)->getConnection();
        $connection->insert('book_reading_sessions', [
            'id' => 'contract-goal-session',
            'book_id' => 'contract-book',
            'date' => new DateTimeImmutable('today')->format('Y-m-d'),
            'pages_read' => 30,
        ]);
    }

    /**
     * Populate every cockpit widget for "today" so the DashboardDTO response
     * exercises each section's non-empty branch: a pending task with a time range,
     * the day's article pick, a goal snapshot joined to a persisted streak (non-null
     * counters + last-activity date), an ongoing-series recommendation with a cover,
     * and a recent listening session. The Dashboard reads these source tables via
     * DBAL, so the fixtures are inserted directly and dated to the current day.
     */
    private function seedDashboardToday(): void
    {
        $this->truncate('tasks', 'article_daily_picks', 'articles', 'goals', 'streaks', 'series', 'books', 'music_listening_sessions');

        $day = new DateTimeImmutable('today')->format('Y-m-d');
        $connection = static::getContainer()->get(EntityManagerInterface::class)->getConnection();

        $connection->insert('tasks', [
            'id' => 'dash-task', 'title' => 'Standup', 'time_start' => $day.' 09:00:00', 'time_end' => $day.' 09:15:00', 'status' => 'pending',
        ]);
        $connection->insert('articles', [
            'id' => 'dash-article', 'title' => 'Article of the day', 'url' => 'https://example.test/a', 'added_at' => $day.' 06:00:00', 'is_read' => 0,
        ]);
        $connection->insert('article_daily_picks', [
            'id' => 'dash-pick', 'article_id' => 'dash-article', 'picked_at' => $day.' 05:00:00',
        ]);
        $connection->insert('goals', [
            'id' => 'dash-goal', 'type' => 'book_pages', 'target_value' => 50, 'period' => 'daily',
        ]);
        $connection->insert('streaks', [
            'id' => 'dash-streak', 'type' => 'book_pages', 'current_length' => 3, 'longest_length' => 9, 'last_activity_date' => $day.' 00:00:00',
        ]);
        $connection->insert('series', [
            'id' => 'dash-series', 'title' => 'Ongoing Show', 'created_at' => $day.' 00:00:00', 'status' => 'ongoing', 'cover_url' => 'https://example.test/cover.jpg', 'year' => 2020,
        ]);
        $connection->insert('music_listening_sessions', [
            'id' => 'dash-track', 'artist' => 'Artist', 'title' => 'Track', 'played_at' => $day.' 08:00:00', 'source' => 'manual', 'dedup_hash' => 'dash-h1', 'created_at' => $day.' 08:00:00',
        ]);
    }

    /**
     * POST a JSON body, assert a 201 create, and return the new resource id.
     *
     * @param array<string, mixed> $payload
     */
    private function postForId(string $uri, array $payload): string
    {
        $this->client->request('POST', $uri, [], [], ['CONTENT_TYPE' => 'application/json'], (string) json_encode($payload));
        self::assertResponseStatusCodeSame(201);

        $id = $this->jsonResponse($this->client)['id'] ?? null;
        self::assertIsString($id);

        return $id;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function patch(string $uri, array $payload): void
    {
        $this->client->request('PATCH', $uri, [], [], ['CONTENT_TYPE' => 'application/json'], (string) json_encode($payload));
        self::assertResponseStatusCodeSame(204);
    }

    /**
     * Configure one type through the real write endpoints, so the response mixes
     * a stored preference with the defaults the untouched types fall back to.
     */
    private function seedNotificationPreference(): void
    {
        $this->patch('/api/v1/notifications/preferences/task_due/channels/push', ['enabled' => false]);

        $this->client->request(
            'PUT',
            '/api/v1/notifications/preferences/task_due/quiet-hours',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            (string) json_encode(['from' => '22:00', 'to' => '07:00']),
        );
        self::assertResponseStatusCodeSame(204);
    }

    private function seedNotificationHistory(): void
    {
        $connection = static::getContainer()->get(EntityManagerInterface::class)->getConnection();

        $connection->insert('notifications', [
            'id' => 'contract-notification-sent',
            'type' => 'task_due',
            'channel' => 'email',
            'payload' => (string) json_encode(['title' => 'Zapłacić czynsz', 'dueAt' => '2026-07-16 18:00']),
            'dedup_key' => 'task_due:contract-task:2026-07-16:email',
            'created_at' => '2026-07-16 08:00:00',
            'status' => 'sent',
            'sent_at' => '2026-07-16 08:00:02',
            'failure_reason' => null,
        ]);

        $connection->insert('notifications', [
            'id' => 'contract-notification-failed',
            'type' => 'article_daily',
            'channel' => 'push',
            'payload' => (string) json_encode(['title' => 'Artykuł dnia']),
            'dedup_key' => 'article_daily:contract-article:2026-07-16:push',
            'created_at' => '2026-07-16 09:00:00',
            'status' => 'failed',
            'sent_at' => null,
            'failure_reason' => 'push endpoint gone',
        ]);
    }

    /**
     * Podcasts has no write API — the catalog is materialized by the Spotify
     * sweep — so the fixture goes in through the tables the read query reads.
     * One show, one episode, one finished listen: every nullable field on both
     * read models populated, so neither branch goes unchecked.
     */
    private function seedPodcast(): string
    {
        $connection = static::getContainer()->get(EntityManagerInterface::class)->getConnection();

        $connection->insert('podcasts', [
            'id' => 'contract-pod-1',
            'title' => 'Radio Naukowe',
            'external_id' => 'spotify-show-contract',
            'publisher' => 'Karolina Głowacka',
            'cover_url' => 'https://example.com/podcast-cover.jpg',
            'description' => 'Nauka po ludzku.',
            'created_at' => '2026-07-01 10:00:00',
        ]);

        $connection->insert('podcast_episodes', [
            'id' => 'contract-ep-1',
            'podcast_id' => 'contract-pod-1',
            'external_id' => 'spotify-episode-contract',
            'title' => 'Odcinek kontraktowy',
            'published_at' => '2026-07-01 06:00:00',
            'duration_ms' => 1_800_000,
            'created_at' => '2026-07-01 10:00:00',
        ]);

        $connection->insert('podcast_listening_sessions', [
            'id' => 'contract-session-1',
            'podcast_id' => 'contract-pod-1',
            'episode_id' => 'contract-ep-1',
            'listened_at' => '2026-07-20 19:00:00',
            'resume_position_ms' => 1_750_000,
            'fully_played' => 1,
            'dedup_hash' => hash('sha256', 'contract-session-1'),
            'created_at' => '2026-07-20 19:05:00',
        ]);

        return 'contract-pod-1';
    }

    private function truncate(string ...$tables): void
    {
        $connection = static::getContainer()->get(EntityManagerInterface::class)->getConnection();
        $connection->executeStatement('SET FOREIGN_KEY_CHECKS=0');
        foreach ($tables as $table) {
            $connection->executeStatement('TRUNCATE TABLE '.$table);
        }
        $connection->executeStatement('SET FOREIGN_KEY_CHECKS=1');
    }
}
