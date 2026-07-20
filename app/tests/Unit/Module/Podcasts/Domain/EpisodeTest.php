<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Podcasts\Domain;

use App\Module\Podcasts\Domain\Entity\Episode;
use App\Module\Podcasts\Domain\ValueObject\Title;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class EpisodeTest extends TestCase
{
    public function testExposesIdentityPodcastAndTitle(): void
    {
        $createdAt = new DateTimeImmutable('2026-07-20 21:00:00');
        $episode = new Episode('e-1', 'p-1', new Title('Odcinek 100'), $createdAt);

        self::assertSame('e-1', $episode->id());
        self::assertSame('p-1', $episode->podcastId());
        self::assertSame('Odcinek 100', $episode->title()->value());
        self::assertSame($createdAt, $episode->createdAt());
    }

    public function testRejectsEmptyId(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Episode id cannot be empty.');

        new Episode(' ', 'p-1', new Title('Odcinek 100'), new DateTimeImmutable());
    }

    /**
     * The show FK is the only thing tying an episode to its aggregate — there is
     * no ORM association to fall back on (ADR-007), so an orphan must not be
     * constructible.
     */
    public function testRejectsMissingPodcastId(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Episode must belong to a podcast.');

        new Episode('e-1', '', new Title('Odcinek 100'), new DateTimeImmutable());
    }

    public function testMetadataDefaultsToNull(): void
    {
        $episode = new Episode('e-1', 'p-1', new Title('Odcinek 100'), new DateTimeImmutable());

        self::assertNull($episode->publishedAt());
        self::assertNull($episode->durationMs());
    }

    public function testRenameReplacesTitle(): void
    {
        $episode = new Episode('e-1', 'p-1', new Title('Odcinek 100'), new DateTimeImmutable());

        $episode->rename(new Title('Odcinek 100 — poprawiony'));

        self::assertSame('Odcinek 100 — poprawiony', $episode->title()->value());
    }

    public function testUpdateMetadataStoresPublicationAndDuration(): void
    {
        $episode = new Episode('e-1', 'p-1', new Title('Odcinek 100'), new DateTimeImmutable());
        $publishedAt = new DateTimeImmutable('2026-07-01 06:00:00');

        $episode->updateMetadata($publishedAt, 3_600_000);

        self::assertSame($publishedAt, $episode->publishedAt());
        self::assertSame(3_600_000, $episode->durationMs());
    }

    public function testUpdateMetadataClearsFieldsWhenGivenNull(): void
    {
        $episode = new Episode('e-1', 'p-1', new Title('Odcinek 100'), new DateTimeImmutable());
        $episode->updateMetadata(new DateTimeImmutable('2026-07-01 06:00:00'), 3_600_000);

        $episode->updateMetadata(null, null);

        self::assertNull($episode->publishedAt());
        self::assertNull($episode->durationMs());
    }

    public function testUpdateMetadataRejectsNegativeDuration(): void
    {
        $episode = new Episode('e-1', 'p-1', new Title('Odcinek 100'), new DateTimeImmutable());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Episode duration must not be negative.');

        $episode->updateMetadata(null, -1);
    }

    public function testUpdateMetadataAcceptsZeroDuration(): void
    {
        $episode = new Episode('e-1', 'p-1', new Title('Odcinek 100'), new DateTimeImmutable());

        $episode->updateMetadata(null, 0);

        self::assertSame(0, $episode->durationMs());
    }
}
