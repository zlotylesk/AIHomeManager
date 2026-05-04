<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Music\Application;

use App\Module\Music\Application\DTO\AlbumDTO;
use App\Module\Music\Application\DTO\VinylRecordDTO;
use App\Module\Music\Application\Query\GetMusicComparison;
use App\Module\Music\Application\QueryHandler\GetMusicComparisonHandler;
use App\Module\Music\Domain\Port\MusicListeningHistoryInterface;
use App\Module\Music\Domain\Port\VinylCollectionInterface;
use PHPUnit\Framework\TestCase;
use Redis;

final class GetMusicComparisonHandlerTest extends TestCase
{
    private Redis $redis;
    private MusicListeningHistoryInterface $lastfm;
    private VinylCollectionInterface $discogs;

    protected function setUp(): void
    {
        $this->redis = $this->createStub(Redis::class);
        $this->redis->method('get')->willReturn(false);
        $this->redis->method('setex')->willReturn(true);

        $this->lastfm = $this->createStub(MusicListeningHistoryInterface::class);
        $this->discogs = $this->createStub(VinylCollectionInterface::class);
    }

    private function makeHandler(): GetMusicComparisonHandler
    {
        return new GetMusicComparisonHandler(
            $this->lastfm,
            $this->discogs,
            $this->redis,
            'lastfm_user',
            'discogs_user',
        );
    }

    public function testSplitsAlbumsIntoOwnedAndWantList(): void
    {
        $this->lastfm->method('getTopAlbums')->willReturnCallback(function (string $user, string $period, int $limit) {
            if (2 === $limit) {
                return [
                    new AlbumDTO('Pink Floyd', 'The Wall', 200, null),
                    new AlbumDTO('Radiohead', 'OK Computer', 150, null),
                ];
            }

            return [
                new AlbumDTO('Pink Floyd', 'The Wall', 200, null),
            ];
        });

        $this->discogs->method('getUserCollection')->willReturn([
            new VinylRecordDTO('Pink Floyd', 'The Wall', 1979, 'Vinyl', 1),
        ]);

        $handler = $this->makeHandler();
        $result = $handler(new GetMusicComparison(period: '1month', limit: 2));

        self::assertCount(1, $result->ownedAndListened);
        self::assertSame('The Wall', $result->ownedAndListened[0]->title);
        self::assertCount(1, $result->wantList);
        self::assertSame('OK Computer', $result->wantList[0]->title);
    }

    public function testCalculatesMatchScore(): void
    {
        $this->lastfm->method('getTopAlbums')->willReturnCallback(
            fn ($u, $p, $limit) => 4 === $limit ? [
                new AlbumDTO('Artist A', 'Album A', 100, null),
                new AlbumDTO('Artist B', 'Album B', 90, null),
                new AlbumDTO('Artist C', 'Album C', 80, null),
                new AlbumDTO('Artist D', 'Album D', 70, null),
            ] : []
        );

        $this->discogs->method('getUserCollection')->willReturn([
            new VinylRecordDTO('Artist A', 'Album A', 2000, 'Vinyl', 1),
            new VinylRecordDTO('Artist B', 'Album B', 2001, 'Vinyl', 2),
        ]);

        $result = $this->makeHandler()(new GetMusicComparison(period: '1month', limit: 4));

        self::assertSame(50.0, $result->matchScore);
    }

    public function testIdentifiesDustyShelf(): void
    {
        $this->lastfm->method('getTopAlbums')->willReturnCallback(
            fn ($u, $p, $limit) => 500 === $limit ? [new AlbumDTO('Artist A', 'Album A', 100, null)] : []
        );

        $this->discogs->method('getUserCollection')->willReturn([
            new VinylRecordDTO('Artist A', 'Album A', 2000, 'Vinyl', 1),
            new VinylRecordDTO('Forgotten', 'Forgotten Album', 1980, 'Vinyl', 2),
        ]);

        $result = $this->makeHandler()(new GetMusicComparison(limit: 50));

        self::assertCount(1, $result->dustyShelf);
        self::assertSame('Forgotten Album', $result->dustyShelf[0]->title);
    }

    public function testMatchingIgnoresParenthesesFormatDifferences(): void
    {
        $this->lastfm->method('getTopAlbums')->willReturnCallback(
            fn ($u, $p, $limit) => 1 === $limit ? [new AlbumDTO('Radiohead', 'OK Computer (Remastered 2009)', 100, null)] : []
        );

        $this->discogs->method('getUserCollection')->willReturn([
            new VinylRecordDTO('Radiohead', 'OK Computer', 1997, 'Vinyl', 1),
        ]);

        $result = $this->makeHandler()(new GetMusicComparison(limit: 1));

        self::assertCount(1, $result->ownedAndListened);
        self::assertCount(0, $result->wantList);
    }

    public function testReturnsCachedResult(): void
    {
        $payload = json_encode([
            'ownedAndListened' => [],
            'wantList' => [],
            'dustyShelf' => [],
            'matchScore' => 42.5,
        ]);

        $redis = $this->createMock(Redis::class);
        $redis->method('get')->willReturn($payload);
        $redis->expects(self::never())->method('setex');

        $handler = new GetMusicComparisonHandler(
            $this->lastfm,
            $this->discogs,
            $redis,
            'user',
            'user'
        );

        $result = $handler(new GetMusicComparison());

        self::assertSame(42.5, $result->matchScore);
    }

    public function testCachedResultRoundtripPreservesAllFields(): void
    {
        $payload = json_encode([
            'ownedAndListened' => [
                ['artist' => 'Pink Floyd', 'title' => 'The Wall', 'playCount' => 200, 'imageUrl' => 'https://img.example/wall.jpg'],
            ],
            'wantList' => [
                ['artist' => 'Radiohead', 'title' => 'OK Computer', 'playCount' => 150, 'imageUrl' => null],
            ],
            'dustyShelf' => [
                ['artist' => 'Forgotten', 'title' => 'Old Album', 'year' => 1975, 'format' => 'Vinyl', 'discogsId' => 99],
                ['artist' => 'Unknown', 'title' => 'No Year', 'year' => null, 'format' => 'CD', 'discogsId' => 100],
            ],
            'matchScore' => 50.0,
        ]);

        $redis = $this->createMock(Redis::class);
        $redis->method('get')->willReturn($payload);

        $handler = new GetMusicComparisonHandler($this->lastfm, $this->discogs, $redis, 'u', 'u');
        $result = $handler(new GetMusicComparison());

        self::assertCount(1, $result->ownedAndListened);
        self::assertSame('Pink Floyd', $result->ownedAndListened[0]->artist);
        self::assertSame('https://img.example/wall.jpg', $result->ownedAndListened[0]->imageUrl);
        self::assertCount(1, $result->wantList);
        self::assertNull($result->wantList[0]->imageUrl);
        self::assertCount(2, $result->dustyShelf);
        self::assertSame(1975, $result->dustyShelf[0]->year);
        self::assertNull($result->dustyShelf[1]->year);
        self::assertSame(50.0, $result->matchScore);
    }

    public function testIgnoresMalformedJsonAndRecomputes(): void
    {
        $this->lastfm->method('getTopAlbums')->willReturn([new AlbumDTO('A', 'B', 1, null)]);
        $this->discogs->method('getUserCollection')->willReturn([]);

        $redis = $this->createMock(Redis::class);
        $redis->method('get')->willReturn('{not valid json');
        $redis->expects(self::once())->method('setex');

        $handler = new GetMusicComparisonHandler($this->lastfm, $this->discogs, $redis, 'u', 'u');
        $result = $handler(new GetMusicComparison(limit: 1));

        self::assertCount(1, $result->wantList);
    }

    public function testIgnoresWrongStructureAndRecomputes(): void
    {
        $this->lastfm->method('getTopAlbums')->willReturn([new AlbumDTO('A', 'B', 1, null)]);
        $this->discogs->method('getUserCollection')->willReturn([]);

        $redis = $this->createMock(Redis::class);
        $redis->method('get')->willReturn(json_encode(['ownedAndListened' => 'not-an-array']));
        $redis->expects(self::once())->method('setex');

        $handler = new GetMusicComparisonHandler($this->lastfm, $this->discogs, $redis, 'u', 'u');
        $result = $handler(new GetMusicComparison(limit: 1));

        self::assertCount(1, $result->wantList);
    }

    public function testIgnoresInvalidAlbumItemAndRecomputes(): void
    {
        $this->lastfm->method('getTopAlbums')->willReturn([]);
        $this->discogs->method('getUserCollection')->willReturn([]);

        $redis = $this->createMock(Redis::class);
        $redis->method('get')->willReturn(json_encode([
            'ownedAndListened' => [['artist' => 'A', 'title' => 'B', 'playCount' => 'not-int', 'imageUrl' => null]],
            'wantList' => [],
            'dustyShelf' => [],
            'matchScore' => 0.0,
        ]));
        $redis->expects(self::once())->method('setex');

        $handler = new GetMusicComparisonHandler($this->lastfm, $this->discogs, $redis, 'u', 'u');
        $result = $handler(new GetMusicComparison());

        self::assertSame(0.0, $result->matchScore);
    }
}
