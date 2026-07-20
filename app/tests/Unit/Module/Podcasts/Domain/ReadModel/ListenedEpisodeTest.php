<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Podcasts\Domain\ReadModel;

use App\Module\Podcasts\Domain\ReadModel\ListenedEpisode;
use App\Module\Podcasts\Domain\ValueObject\ListeningProgress;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ListenedEpisodeTest extends TestCase
{
    private function make(
        string $podcastExternalId = 'show-1',
        string $podcastTitle = 'Radio Naukowe',
        string $episodeExternalId = 'ep-1',
        string $episodeTitle = 'Odcinek 100',
        ?int $durationMs = null,
    ): ListenedEpisode {
        return new ListenedEpisode(
            $podcastExternalId,
            $podcastTitle,
            $episodeExternalId,
            $episodeTitle,
            new DateTimeImmutable('2026-07-20 20:15:00'),
            ListeningProgress::completed(),
            durationMs: $durationMs,
        );
    }

    public function testExposesNormalizedListen(): void
    {
        $listened = $this->make();

        self::assertSame('show-1', $listened->podcastExternalId);
        self::assertSame('Radio Naukowe', $listened->podcastTitle);
        self::assertSame('ep-1', $listened->episodeExternalId);
        self::assertSame('Odcinek 100', $listened->episodeTitle);
        self::assertTrue($listened->progress->fullyPlayed());
        self::assertSame('2026-07-20 20:15:00', $listened->listenedAt->format('Y-m-d H:i:s'));
    }

    public function testOptionalCatalogFieldsDefaultToNull(): void
    {
        $listened = $this->make();

        self::assertNull($listened->publisher);
        self::assertNull($listened->coverUrl);
        self::assertNull($listened->publishedAt);
        self::assertNull($listened->durationMs);
    }

    /**
     * The external ids are what the poll deduplicates on, so a blank one would
     * silently collapse unrelated listens into a single record.
     */
    public function testRejectsBlankPodcastExternalId(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must carry a podcast id');

        $this->make(podcastExternalId: '  ');
    }

    public function testRejectsBlankEpisodeExternalId(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must carry an episode id');

        $this->make(episodeExternalId: '');
    }

    public function testRejectsBlankPodcastTitle(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must carry a podcast title');

        $this->make(podcastTitle: ' ');
    }

    public function testRejectsBlankEpisodeTitle(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must carry an episode title');

        $this->make(episodeTitle: '');
    }

    public function testRejectsNegativeDuration(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must not be negative');

        $this->make(durationMs: -1);
    }
}
