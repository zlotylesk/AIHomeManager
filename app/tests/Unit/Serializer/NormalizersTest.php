<?php

declare(strict_types=1);

namespace App\Tests\Unit\Serializer;

use App\Module\Articles\Application\DTO\ArticleDTO;
use App\Module\Books\Application\DTO\BookDetailDTO;
use App\Module\Books\Application\DTO\BookDTO;
use App\Module\Books\Application\DTO\ReadingSessionDTO;
use App\Module\Music\Application\DTO\AlbumDTO;
use App\Module\Music\Application\DTO\ListeningSessionDTO;
use App\Module\Music\Application\DTO\VinylRecordDTO;
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
use App\Serializer\ListeningSessionDTONormalizer;
use App\Serializer\SeriesDetailDTONormalizer;
use App\Serializer\TaskDTONormalizer;
use App\Serializer\VideoDTONormalizer;
use App\Serializer\VinylRecordDTONormalizer;
use App\Serializer\WatchSessionDTONormalizer;
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
        $dto = new AlbumDTO('Pink Floyd', 'Animals', 42, 'https://img.test/a.jpg');

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
        $dto = new VinylRecordDTO('Pink Floyd', 'Animals', 1977, 'Vinyl', 12345);

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

    public function testSeriesDetailNormalizerComputesAverages(): void
    {
        $n = new SeriesDetailDTONormalizer();
        $dto = new SeriesDetailDTO(
            id: 'sr1',
            title: 'Show',
            createdAt: '2026-01-01',
            seasons: [
                new SeasonDTO('se1', 1, [
                    new EpisodeDTO('e1', 'Pilot', 1, 8, true, '2026-01-02'),
                    new EpisodeDTO('e2', 'Second', 2, 6, false, null),
                ], 7),
            ],
            rating: 7,
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

    public function testSeriesDetailNormalizerNullAveragesWhenNoRatings(): void
    {
        $n = new SeriesDetailDTONormalizer();
        $dto = new SeriesDetailDTO(
            id: 'sr2',
            title: 'Empty',
            createdAt: '2026-01-01',
            seasons: [new SeasonDTO('se1', 1, [new EpisodeDTO('e1', 'Pilot', 1, null)])],
        );

        $result = $n->normalize($dto);

        self::assertNull($result['averageRating']);
        self::assertNull($result['seasons'][0]['averageRating']);
        self::assertSame(0, $result['watchedCount']);
    }

    private function book(): BookDTO
    {
        return new BookDTO('b1', '978-3-16-148410-0', 'Clean Code', 'Martin', 'PH', 2008, null, 464, 100, 21.55, 'reading');
    }
}
