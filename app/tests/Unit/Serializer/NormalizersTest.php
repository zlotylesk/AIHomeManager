<?php

declare(strict_types=1);

namespace App\Tests\Unit\Serializer;

use App\Module\Articles\Application\DTO\ArticleDTO;
use App\Module\Books\Application\DTO\BookDetailDTO;
use App\Module\Books\Application\DTO\BookDTO;
use App\Module\Books\Application\DTO\ReadingSessionDTO;
use App\Module\Dashboard\Application\DTO\DashboardDTO;
use App\Module\Dashboard\Domain\ReadModel\DailyArticle;
use App\Module\Dashboard\Domain\ReadModel\GoalSnapshot;
use App\Module\Dashboard\Domain\ReadModel\RecentTrack;
use App\Module\Dashboard\Domain\ReadModel\Recommendation;
use App\Module\Dashboard\Domain\ReadModel\TodayTask;
use App\Module\Goals\Application\DTO\GoalProgressDTO;
use App\Module\Goals\Application\DTO\StreakDTO;
use App\Module\Movies\Application\DTO\MovieDTO;
use App\Module\Music\Application\DTO\ListeningSessionDTO;
use App\Module\Music\Domain\ReadModel\Album;
use App\Module\Music\Domain\ReadModel\VinylRecord;
use App\Module\Notifications\Application\DTO\NotificationDTO;
use App\Module\Notifications\Application\DTO\NotificationPreferenceDTO;
use App\Module\Podcasts\Application\DTO\PodcastDetailDTO;
use App\Module\Podcasts\Application\DTO\PodcastDTO;
use App\Module\Podcasts\Application\DTO\PodcastEpisodeDTO;
use App\Module\Podcasts\Application\DTO\PodcastListeningSessionDTO;
use App\Module\Search\Domain\Enum\SearchResultType;
use App\Module\Search\Domain\ValueObject\SearchResult;
use App\Module\Series\Application\DTO\EpisodeDTO;
use App\Module\Series\Application\DTO\SeasonDTO;
use App\Module\Series\Application\DTO\SeriesDetailDTO;
use App\Module\Tasks\Application\DTO\TaskDTO;
use App\Module\YouTubeProgress\Application\DTO\VideoDTO;
use App\Module\YouTubeProgress\Application\DTO\WatchSessionDTO;
use App\Serializer\AlbumDTONormalizer;
use App\Serializer\ArticleDTONormalizer;
use App\Serializer\BookDetailDTONormalizer;
use App\Serializer\BookDTONormalizer;
use App\Serializer\DashboardDTONormalizer;
use App\Serializer\GoalProgressDTONormalizer;
use App\Serializer\ListeningSessionDTONormalizer;
use App\Serializer\MovieDTONormalizer;
use App\Serializer\NotificationDTONormalizer;
use App\Serializer\NotificationPreferenceDTONormalizer;
use App\Serializer\PodcastDetailDTONormalizer;
use App\Serializer\PodcastDTONormalizer;
use App\Serializer\SearchResultDTONormalizer;
use App\Serializer\SeriesDetailDTONormalizer;
use App\Serializer\StreakDTONormalizer;
use App\Serializer\TaskDTONormalizer;
use App\Serializer\VideoDTONormalizer;
use App\Serializer\VinylRecordDTONormalizer;
use App\Serializer\WatchSessionDTONormalizer;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\Serializer\Serializer;

final class NormalizersTest extends TestCase
{
    public function testArticleNormalizer(): void
    {
        $n = new ArticleDTONormalizer();
        $dto = new ArticleDTO('a1', 'Title', 'https://x.test', 'tech', 7, '2026-01-01', null, false);

        self::assertTrue($n->supportsNormalization($dto));
        self::assertFalse($n->supportsNormalization(new stdClass()));
        self::assertArrayHasKey(ArticleDTO::class, $n->getSupportedTypes(null));
        self::assertSame([
            'id' => 'a1',
            'title' => 'Title',
            'url' => 'https://x.test',
            'category' => 'tech',
            'estimatedReadTime' => 7,
            'addedAt' => '2026-01-01',
            'readAt' => null,
            'isRead' => false,
        ], $n->normalize($dto));
    }

    public function testTaskNormalizer(): void
    {
        $n = new TaskDTONormalizer();
        $dto = new TaskDTO('t1', 'Do it', '2026-01-01T08:00', '2026-01-01T09:00', 60, 'pending', null);

        self::assertTrue($n->supportsNormalization($dto));
        self::assertFalse($n->supportsNormalization(new stdClass()));
        self::assertArrayHasKey(TaskDTO::class, $n->getSupportedTypes(null));
        self::assertSame([
            'id' => 't1',
            'title' => 'Do it',
            'start' => '2026-01-01T08:00',
            'end' => '2026-01-01T09:00',
            'durationMinutes' => 60,
            'status' => 'pending',
            'googleEventId' => null,
        ], $n->normalize($dto));
    }

    public function testAlbumNormalizer(): void
    {
        $n = new AlbumDTONormalizer();
        $dto = new Album('Pink Floyd', 'Animals', 42, 'https://img.test/a.jpg');

        self::assertTrue($n->supportsNormalization($dto));
        self::assertFalse($n->supportsNormalization(new stdClass()));
        self::assertSame([
            'artist' => 'Pink Floyd',
            'title' => 'Animals',
            'playCount' => 42,
            'imageUrl' => 'https://img.test/a.jpg',
        ], $n->normalize($dto));
    }

    public function testVinylRecordNormalizer(): void
    {
        $n = new VinylRecordDTONormalizer();
        $dto = new VinylRecord('Pink Floyd', 'Animals', 1977, 'Vinyl', 12345);

        self::assertTrue($n->supportsNormalization($dto));
        self::assertFalse($n->supportsNormalization(new stdClass()));
        self::assertSame([
            'artist' => 'Pink Floyd',
            'title' => 'Animals',
            'year' => 1977,
            'format' => 'Vinyl',
            'discogsId' => 12345,
        ], $n->normalize($dto));
    }

    public function testListeningSessionNormalizer(): void
    {
        $n = new ListeningSessionDTONormalizer();
        $dto = new ListeningSessionDTO('s1', 'Artist', 'Song', '2026-01-01T10:00', 'lastfm', 3);

        self::assertTrue($n->supportsNormalization($dto));
        self::assertFalse($n->supportsNormalization(new stdClass()));
        self::assertSame([
            'id' => 's1',
            'artist' => 'Artist',
            'title' => 'Song',
            'playedAt' => '2026-01-01T10:00',
            'source' => 'lastfm',
            'playCount' => 3,
        ], $n->normalize($dto));
    }

    public function testBookNormalizer(): void
    {
        $n = new BookDTONormalizer();
        $dto = $this->book();

        self::assertTrue($n->supportsNormalization($dto));
        self::assertFalse($n->supportsNormalization(new stdClass()));
        self::assertSame([
            'id' => 'b1',
            'isbn' => '978-3-16-148410-0',
            'title' => 'Clean Code',
            'author' => 'Martin',
            'publisher' => 'PH',
            'year' => 2008,
            'coverUrl' => null,
            'totalPages' => 464,
            'currentPage' => 100,
            'percentage' => 21.55,
            'status' => 'reading',
        ], $n->normalize($dto));
    }

    public function testBookDetailNormalizerDelegatesEmbeddedBook(): void
    {
        $serializer = new Serializer([new BookDTONormalizer(), new BookDetailDTONormalizer()]);
        $dto = new BookDetailDTO($this->book(), [
            new ReadingSessionDTO('rs1', '2026-01-02', 30, 'good'),
        ]);

        $result = $serializer->normalize($dto);

        self::assertIsArray($result);
        // embedded book fields are present (delegated to BookDTONormalizer)
        self::assertSame('b1', $result['id']);
        self::assertSame('Clean Code', $result['title']);
        self::assertSame([
            ['id' => 'rs1', 'date' => '2026-01-02', 'pagesRead' => 30, 'notes' => 'good'],
        ], $result['sessions']);
    }

    public function testVideoNormalizer(): void
    {
        $n = new VideoDTONormalizer();
        $dto = new VideoDTO('yt1', 'Clip', 'Chan', 120, 'watchlist', null, null);

        self::assertTrue($n->supportsNormalization($dto));
        self::assertFalse($n->supportsNormalization(new stdClass()));
        self::assertSame([
            'youtubeId' => 'yt1',
            'title' => 'Clip',
            'channel' => 'Chan',
            'durationSeconds' => 120,
            'status' => 'watchlist',
            'startedAt' => null,
            'watchedAt' => null,
        ], $n->normalize($dto));
    }

    public function testWatchSessionNormalizerDelegatesVideos(): void
    {
        $serializer = new Serializer([new VideoDTONormalizer(), new WatchSessionDTONormalizer()]);
        $dto = new WatchSessionDTO('ws1', '2026-01-01T00:00:00+00:00', 120, 'PL123', [
            new VideoDTO('yt1', 'Clip', 'Chan', 120, 'watchlist', null, null),
        ]);

        $result = $serializer->normalize($dto);

        self::assertIsArray($result);
        self::assertSame('ws1', $result['id']);
        self::assertSame(120, $result['totalDurationSeconds']);
        self::assertSame('PL123', $result['youtubePlaylistId']);
        self::assertSame([
            [
                'youtubeId' => 'yt1',
                'title' => 'Clip',
                'channel' => 'Chan',
                'durationSeconds' => 120,
                'status' => 'watchlist',
                'startedAt' => null,
                'watchedAt' => null,
            ],
        ], $result['videos']);
    }

    public function testSeriesDetailNormalizerMapsPrecomputedReadModelFields(): void
    {
        // The averages/counters are computed upstream (SeriesRowHydrator) and
        // carried on the DTO; the normalizer is a pure field map (HMAI-242).
        $n = new SeriesDetailDTONormalizer();
        $dto = new SeriesDetailDTO(
            id: 'sr1',
            title: 'Show',
            createdAt: '2026-01-01',
            seasons: [
                new SeasonDTO('se1', 1, [
                    new EpisodeDTO('e1', 'Pilot', 1, 8, true, '2026-01-02'),
                    new EpisodeDTO('e2', 'Second', 2, 6, false),
                ], rating: 7, averageRating: 7.0, watchedCount: 1, episodeCount: 2),
            ],
            rating: 7,
            averageRating: 7.0,
            watchedCount: 1,
            episodeCount: 2,
        );

        $result = $n->normalize($dto);

        self::assertSame('sr1', $result['id']);
        self::assertSame(7, $result['rating']);
        self::assertSame(7.0, $result['averageRating']);
        self::assertSame(1, $result['watchedCount']);
        self::assertSame(2, $result['episodeCount']);

        $season = $result['seasons'][0];
        self::assertSame(7.0, $season['averageRating']);
        self::assertSame(1, $season['watchedCount']);
        self::assertSame(2, $season['episodeCount']);
        self::assertCount(2, $season['episodes']);
    }

    public function testSeriesDetailNormalizerMapsNullAverageAndZeroCounters(): void
    {
        $n = new SeriesDetailDTONormalizer();
        $dto = new SeriesDetailDTO(
            id: 'sr2',
            title: 'Empty',
            createdAt: '2026-01-01',
            seasons: [new SeasonDTO('se1', 1, [new EpisodeDTO('e1', 'Pilot', 1, null)], episodeCount: 1)],
            episodeCount: 1,
        );

        $result = $n->normalize($dto);

        self::assertNull($result['averageRating']);
        self::assertNull($result['seasons'][0]['averageRating']);
        self::assertSame(0, $result['watchedCount']);
        self::assertSame(1, $result['episodeCount']);
        self::assertSame(0, $result['seasons'][0]['watchedCount']);
        self::assertSame(1, $result['seasons'][0]['episodeCount']);
    }

    public function testMovieNormalizer(): void
    {
        $n = new MovieDTONormalizer();
        $dto = new MovieDTO(
            id: 'm1',
            title: 'Blade Runner 2049',
            watched: true,
            watchedAt: '2026-07-10T20:00:00+00:00',
            rating: 9,
            coverUrl: 'https://img.test/br.jpg',
            year: 2017,
            status: 'released',
            description: 'Neo-noir sci-fi.',
            createdAt: '2026-07-01T00:00:00+00:00',
        );

        self::assertTrue($n->supportsNormalization($dto));
        self::assertFalse($n->supportsNormalization(new stdClass()));
        self::assertArrayHasKey(MovieDTO::class, $n->getSupportedTypes(null));
        self::assertSame([
            'id' => 'm1',
            'title' => 'Blade Runner 2049',
            'watched' => true,
            'watchedAt' => '2026-07-10T20:00:00+00:00',
            'rating' => 9,
            'coverUrl' => 'https://img.test/br.jpg',
            'year' => 2017,
            'status' => 'released',
            'description' => 'Neo-noir sci-fi.',
            'createdAt' => '2026-07-01T00:00:00+00:00',
        ], $n->normalize($dto));
    }

    public function testMovieNormalizerNullMetadata(): void
    {
        $n = new MovieDTONormalizer();
        $dto = new MovieDTO('m2', 'Untitled', false, null, null, null, null, null, null, '2026-07-01T00:00:00+00:00');

        self::assertSame([
            'id' => 'm2',
            'title' => 'Untitled',
            'watched' => false,
            'watchedAt' => null,
            'rating' => null,
            'coverUrl' => null,
            'year' => null,
            'status' => null,
            'description' => null,
            'createdAt' => '2026-07-01T00:00:00+00:00',
        ], $n->normalize($dto));
    }

    public function testGoalProgressNormalizer(): void
    {
        $n = new GoalProgressDTONormalizer();
        $dto = new GoalProgressDTO('g1', 'book_pages', 'daily', 50, 30, 60, false);

        self::assertSame([
            'goalId' => 'g1',
            'type' => 'book_pages',
            'period' => 'daily',
            'target' => 50,
            'achieved' => 30,
            'percent' => 60,
            'met' => false,
        ], $n->normalize($dto));
    }

    public function testStreakNormalizer(): void
    {
        $n = new StreakDTONormalizer();
        $dto = new StreakDTO('book_pages', 3, 7, '2026-07-10');

        self::assertSame([
            'type' => 'book_pages',
            'currentLength' => 3,
            'longestLength' => 7,
            'lastActivityDate' => '2026-07-10',
        ], $n->normalize($dto));
    }

    public function testNotificationPreferenceNormalizer(): void
    {
        $n = new NotificationPreferenceDTONormalizer();
        $dto = new NotificationPreferenceDTO('task_due', true, ['email', 'push'], '22:00', '07:00');

        self::assertTrue($n->supportsNormalization($dto));
        self::assertFalse($n->supportsNormalization(new stdClass()));
        self::assertArrayHasKey(NotificationPreferenceDTO::class, $n->getSupportedTypes(null));
        self::assertSame([
            'type' => 'task_due',
            'enabled' => true,
            'channels' => ['email', 'push'],
            'quietFrom' => '22:00',
            'quietTo' => '07:00',
        ], $n->normalize($dto));
    }

    public function testNotificationNormalizer(): void
    {
        $n = new NotificationDTONormalizer();
        $dto = new NotificationDTO(
            'n-1',
            'task_due',
            'email',
            'sent',
            ['title' => 'Czynsz'],
            '2026-07-19T08:15:00+02:00',
            '2026-07-19T08:15:03+02:00',
            null,
        );

        self::assertTrue($n->supportsNormalization($dto));
        self::assertArrayHasKey(NotificationDTO::class, $n->getSupportedTypes(null));
        self::assertSame([
            'id' => 'n-1',
            'type' => 'task_due',
            'channel' => 'email',
            'status' => 'sent',
            'payload' => ['title' => 'Czynsz'],
            'createdAt' => '2026-07-19T08:15:00+02:00',
            'sentAt' => '2026-07-19T08:15:03+02:00',
            'failureReason' => null,
        ], $n->normalize($dto));
    }

    public function testSearchResultNormalizer(): void
    {
        $n = new SearchResultDTONormalizer();
        $result = new SearchResult(SearchResultType::BOOK, 'b1', 'Dune', 'desert planet', '/books');

        self::assertTrue($n->supportsNormalization($result));
        self::assertFalse($n->supportsNormalization(new stdClass()));
        self::assertArrayHasKey(SearchResult::class, $n->getSupportedTypes(null));
        self::assertSame([
            'type' => 'book',
            'id' => 'b1',
            'title' => 'Dune',
            'snippet' => 'desert planet',
            'url' => '/books',
        ], $n->normalize($result));
    }

    public function testDashboardNormalizer(): void
    {
        $n = new DashboardDTONormalizer();
        $start = new DateTimeImmutable('2026-07-13 09:00:00', new DateTimeZone('UTC'));
        $dto = new DashboardDTO(
            '2026-07-13',
            [new TodayTask('t1', 'Standup', $start, $start->modify('+15 minutes'))],
            new DailyArticle('Daily read', 'https://x.test/a', 'tech', 7, false),
            [new GoalSnapshot('book_pages', 50, 'daily', 3, 9, new DateTimeImmutable('2026-07-12', new DateTimeZone('UTC')))],
            [new Recommendation('series', 'Ongoing Show', null, '2020')],
            [new RecentTrack('Artist', 'Track', $start, 'manual')],
        );

        self::assertTrue($n->supportsNormalization($dto));
        self::assertFalse($n->supportsNormalization(new stdClass()));
        self::assertArrayHasKey(DashboardDTO::class, $n->getSupportedTypes(null));
        self::assertSame([
            'date' => '2026-07-13',
            'tasks' => [[
                'id' => 't1',
                'title' => 'Standup',
                'startsAt' => '2026-07-13T09:00:00+00:00',
                'endsAt' => '2026-07-13T09:15:00+00:00',
            ]],
            'article' => [
                'title' => 'Daily read',
                'url' => 'https://x.test/a',
                'category' => 'tech',
                'estimatedReadTime' => 7,
                'isRead' => false,
            ],
            'goals' => [[
                'type' => 'book_pages',
                'target' => 50,
                'period' => 'daily',
                'currentStreak' => 3,
                'longestStreak' => 9,
                'lastActivityDate' => '2026-07-12T00:00:00+00:00',
            ]],
            'recommendations' => [[
                'kind' => 'series',
                'title' => 'Ongoing Show',
                'coverUrl' => null,
                'detail' => '2020',
            ]],
            'recentTracks' => [[
                'artist' => 'Artist',
                'title' => 'Track',
                'playedAt' => '2026-07-13T09:00:00+00:00',
                'source' => 'manual',
            ]],
        ], $n->normalize($dto));
    }

    public function testPodcastNormalizer(): void
    {
        $n = new PodcastDTONormalizer();
        $dto = $this->podcast();

        self::assertTrue($n->supportsNormalization($dto));
        self::assertFalse($n->supportsNormalization(new stdClass()));
        self::assertArrayHasKey(PodcastDTO::class, $n->getSupportedTypes(null));
        self::assertSame([
            'id' => 'pod-1',
            'title' => 'Radio Nowak',
            'publisher' => 'Studio Nowak',
            'coverUrl' => 'https://img.test/show.jpg',
            'description' => 'Rozmowy.',
            'episodeCount' => 3,
            'listenedEpisodeCount' => 2,
            'lastListenedAt' => '2026-07-20T19:00:00+00:00',
            'createdAt' => '2026-07-01T10:00:00+00:00',
        ], $n->normalize($dto));
    }

    public function testPodcastNormalizerNullMetadata(): void
    {
        $n = new PodcastDTONormalizer();
        $dto = new PodcastDTO('pod-2', 'Cisza', null, null, null, 0, 0, null, '2026-07-01T10:00:00+00:00');

        self::assertSame([
            'id' => 'pod-2',
            'title' => 'Cisza',
            'publisher' => null,
            'coverUrl' => null,
            'description' => null,
            'episodeCount' => 0,
            'listenedEpisodeCount' => 0,
            'lastListenedAt' => null,
            'createdAt' => '2026-07-01T10:00:00+00:00',
        ], $n->normalize($dto));
    }

    /**
     * The show half is delegated to the PodcastDTO normalizer and flattened to
     * the top level (the BookDetailDTO shape), not nested under an envelope.
     */
    public function testPodcastDetailNormalizerFlattensTheShowAndDelegatesIt(): void
    {
        $n = new PodcastDetailDTONormalizer();
        $n->setNormalizer(new PodcastDTONormalizer());

        $dto = new PodcastDetailDTO(
            $this->podcast(),
            [new PodcastEpisodeDTO('ep-1', 'Odcinek', '2026-07-01T06:00:00+00:00', 1_800_000, true, 1_700_000, true)],
            [new PodcastListeningSessionDTO('s-1', 'ep-1', 'Odcinek', '2026-07-20T19:00:00+00:00', 1_700_000, true)],
        );

        $result = $n->normalize($dto);

        self::assertTrue($n->supportsNormalization($dto));
        self::assertArrayHasKey(PodcastDetailDTO::class, $n->getSupportedTypes(null));
        self::assertSame('Radio Nowak', $result['title'], 'Flattened, not nested under a "podcast" key.');
        self::assertArrayNotHasKey('podcast', $result);
        self::assertSame([[
            'id' => 'ep-1',
            'title' => 'Odcinek',
            'publishedAt' => '2026-07-01T06:00:00+00:00',
            'durationMs' => 1_800_000,
            'listened' => true,
            'resumePositionMs' => 1_700_000,
            'fullyPlayed' => true,
        ]], $result['episodes']);
        self::assertSame([[
            'id' => 's-1',
            'episodeId' => 'ep-1',
            'episodeTitle' => 'Odcinek',
            'listenedAt' => '2026-07-20T19:00:00+00:00',
            'resumePositionMs' => 1_700_000,
            'fullyPlayed' => true,
        ]], $result['sessions']);
    }

    private function podcast(): PodcastDTO
    {
        return new PodcastDTO(
            'pod-1',
            'Radio Nowak',
            'Studio Nowak',
            'https://img.test/show.jpg',
            'Rozmowy.',
            3,
            2,
            '2026-07-20T19:00:00+00:00',
            '2026-07-01T10:00:00+00:00',
        );
    }

    private function book(): BookDTO
    {
        return new BookDTO('b1', '978-3-16-148410-0', 'Clean Code', 'Martin', 'PH', 2008, null, 464, 100, 21.55, 'reading');
    }
}
