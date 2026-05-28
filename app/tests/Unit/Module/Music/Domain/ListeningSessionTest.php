<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Music\Domain;

use App\Module\Music\Domain\Entity\ListeningSession;
use App\Module\Music\Domain\Enum\ListeningSource;
use App\Module\Music\Domain\ValueObject\AlbumArtist;
use App\Module\Music\Domain\ValueObject\AlbumTitle;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ListeningSessionTest extends TestCase
{
    public function testAlbumArtistTrimsAndExposesValue(): void
    {
        self::assertSame('Pink Floyd', new AlbumArtist('  Pink Floyd  ')->value());
    }

    public function testAlbumArtistRejectsEmpty(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new AlbumArtist('   ');
    }

    public function testAlbumArtistRejectsOversized(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new AlbumArtist(str_repeat('x', 256));
    }

    public function testAlbumArtistEquality(): void
    {
        self::assertTrue(new AlbumArtist('Radiohead')->equals(new AlbumArtist('Radiohead')));
        self::assertFalse(new AlbumArtist('Radiohead')->equals(new AlbumArtist('Muse')));
    }

    public function testAlbumTitleRejectsEmpty(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new AlbumTitle('');
    }

    public function testAlbumTitleAllowsLongTitles(): void
    {
        self::assertSame('OK Computer', new AlbumTitle('OK Computer')->value());
    }

    public function testExposesConstructorValues(): void
    {
        $playedAt = new DateTimeImmutable('2026-05-20 10:00:00', new DateTimeZone('UTC'));
        $session = new ListeningSession(
            id: 'abc',
            artist: new AlbumArtist('Pink Floyd'),
            title: new AlbumTitle('The Wall'),
            playedAt: $playedAt,
            source: ListeningSource::LASTFM_SCROBBLE,
            playCount: 3,
        );

        self::assertSame('abc', $session->id());
        self::assertSame('Pink Floyd', $session->artist()->value());
        self::assertSame('The Wall', $session->title()->value());
        self::assertSame(ListeningSource::LASTFM_SCROBBLE, $session->source());
        self::assertSame(3, $session->playCount());
        self::assertSame($playedAt, $session->playedAt());
    }

    public function testPlayCountDefaultsToNull(): void
    {
        $session = $this->makeSession('Pink Floyd', 'The Wall', '2026-05-20 10:00:00');

        self::assertNull($session->playCount());
    }

    public function testDedupHashIsDeterministicForSameIdentity(): void
    {
        $a = $this->makeSession('Pink Floyd', 'The Wall', '2026-05-20 10:00:00');
        $b = $this->makeSession('Pink Floyd', 'The Wall', '2026-05-20 10:00:00');

        self::assertSame($a->dedupHash(), $b->dedupHash());
        self::assertTrue($a->equals($b));
    }

    public function testDedupHashIsTimezoneIndependent(): void
    {
        // Same instant expressed in two timezones must hash identically — the
        // dedup key normalizes to UTC so a tz mismatch can't create a duplicate.
        $utc = $this->makeSession('Pink Floyd', 'The Wall', '2026-05-20 10:00:00', new DateTimeZone('UTC'));
        $warsaw = $this->makeSession('Pink Floyd', 'The Wall', '2026-05-20 12:00:00', new DateTimeZone('Europe/Warsaw'));

        self::assertSame($utc->dedupHash(), $warsaw->dedupHash());
    }

    public function testDedupHashDiffersForDifferentAlbum(): void
    {
        $a = $this->makeSession('Pink Floyd', 'The Wall', '2026-05-20 10:00:00');
        $b = $this->makeSession('Pink Floyd', 'Animals', '2026-05-20 10:00:00');

        self::assertNotSame($a->dedupHash(), $b->dedupHash());
        self::assertFalse($a->equals($b));
    }

    public function testDedupHashIsCaseInsensitive(): void
    {
        $a = $this->makeSession('Pink Floyd', 'The Wall', '2026-05-20 10:00:00');
        $b = $this->makeSession('pink floyd', 'the wall', '2026-05-20 10:00:00');

        self::assertSame($a->dedupHash(), $b->dedupHash());
    }

    public function testDedupHashDiffersForDifferentSource(): void
    {
        $scrobble = new ListeningSession(
            id: '1',
            artist: new AlbumArtist('Pink Floyd'),
            title: new AlbumTitle('The Wall'),
            playedAt: new DateTimeImmutable('2026-05-20 10:00:00', new DateTimeZone('UTC')),
            source: ListeningSource::LASTFM_SCROBBLE,
        );
        $manual = new ListeningSession(
            id: '2',
            artist: new AlbumArtist('Pink Floyd'),
            title: new AlbumTitle('The Wall'),
            playedAt: new DateTimeImmutable('2026-05-20 10:00:00', new DateTimeZone('UTC')),
            source: ListeningSource::MANUAL,
        );

        self::assertNotSame($scrobble->dedupHash(), $manual->dedupHash());
    }

    private function makeSession(string $artist, string $title, string $playedAt, ?DateTimeZone $tz = null): ListeningSession
    {
        return new ListeningSession(
            id: 'id-'.$artist.$title.$playedAt,
            artist: new AlbumArtist($artist),
            title: new AlbumTitle($title),
            playedAt: new DateTimeImmutable($playedAt, $tz ?? new DateTimeZone('UTC')),
            source: ListeningSource::LASTFM_SCROBBLE,
        );
    }
}
