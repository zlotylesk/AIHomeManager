<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Podcasts\Domain;

use App\Module\Podcasts\Domain\Entity\Podcast;
use App\Module\Podcasts\Domain\ValueObject\Title;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class PodcastTest extends TestCase
{
    public function testExposesIdentityAndTitle(): void
    {
        $createdAt = new DateTimeImmutable('2026-07-20 21:00:00');
        $podcast = new Podcast('p-1', new Title('Radio Naukowe'), $createdAt);

        self::assertSame('p-1', $podcast->id());
        self::assertSame('Radio Naukowe', $podcast->title()->value());
        self::assertSame($createdAt, $podcast->createdAt());
    }

    public function testRejectsEmptyId(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Podcast id cannot be empty.');

        new Podcast('   ', new Title('Radio Naukowe'), new DateTimeImmutable());
    }

    public function testMetadataDefaultsToNull(): void
    {
        $podcast = new Podcast('p-1', new Title('Radio Naukowe'), new DateTimeImmutable());

        self::assertNull($podcast->publisher());
        self::assertNull($podcast->coverUrl());
        self::assertNull($podcast->description());
    }

    public function testRenameReplacesTitle(): void
    {
        $podcast = new Podcast('p-1', new Title('Old name'), new DateTimeImmutable());

        $podcast->rename(new Title('New name'));

        self::assertSame('New name', $podcast->title()->value());
    }

    public function testUpdateMetadataStoresEveryField(): void
    {
        $podcast = new Podcast('p-1', new Title('Radio Naukowe'), new DateTimeImmutable());

        $podcast->updateMetadata('Karolina Głowacka', 'https://example.test/cover.jpg', 'Nauka po ludzku.');

        self::assertSame('Karolina Głowacka', $podcast->publisher());
        self::assertSame('https://example.test/cover.jpg', $podcast->coverUrl());
        self::assertSame('Nauka po ludzku.', $podcast->description());
    }

    /**
     * Full replace, not a merge: the catalog is re-read on every poll, so a field
     * the source stopped returning has to disappear here rather than linger.
     */
    public function testUpdateMetadataClearsFieldsWhenGivenNull(): void
    {
        $podcast = new Podcast('p-1', new Title('Radio Naukowe'), new DateTimeImmutable());
        $podcast->updateMetadata('Karolina Głowacka', 'https://example.test/cover.jpg', 'Nauka po ludzku.');

        $podcast->updateMetadata(null, null, null);

        self::assertNull($podcast->publisher());
        self::assertNull($podcast->coverUrl());
        self::assertNull($podcast->description());
    }

    public function testUpdateMetadataLeavesTitleAlone(): void
    {
        $podcast = new Podcast('p-1', new Title('Radio Naukowe'), new DateTimeImmutable());

        $podcast->updateMetadata(null, null, null);

        self::assertSame('Radio Naukowe', $podcast->title()->value());
    }
}
