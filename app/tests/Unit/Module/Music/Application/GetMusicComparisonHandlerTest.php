<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Music\Application;

use App\Module\Music\Application\Query\GetMusicComparison;
use App\Module\Music\Application\QueryHandler\GetMusicComparisonHandler;
use App\Module\Music\Application\Service\AlbumNormalizer;
use App\Module\Music\Domain\Port\MusicListeningHistoryInterface;
use App\Module\Music\Domain\Port\VinylCollectionInterface;
use App\Module\Music\Domain\ReadModel\Album;
use App\Module\Music\Domain\ReadModel\VinylRecord;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Result;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Redis;

final class GetMusicComparisonHandlerTest extends TestCase
{
    private const float HALF_MATCH_SCORE = 50.0;

    private const float CACHED_MATCH_SCORE_MARKER = 42.5;

    private const float NO_MATCH_SCORE = 0.0;

    private Redis $redis;
    private MusicListeningHistoryInterface&Stub $lastfm;
    private VinylCollectionInterface&Stub $discogs;
    private Connection $connection;

    protected function setUp(): void
    {
        $this->redis = $this->createStub(Redis::class);
        $this->redis->method('get')->willReturn(false);
        $this->redis->method('setex')->willReturn(true);

        $this->lastfm = $this->createStub(MusicListeningHistoryInterface::class);
        $this->discogs = $this->createStub(VinylCollectionInterface::class);

        $emptyResult = $this->createStub(Result::class);
        $emptyResult->method('fetchAllAssociative')->willReturn([]);
        $this->connection = $this->createStub(Connection::class);
        $this->connection->method('executeQuery')->willReturn($emptyResult);
    }

    private function makeHandler(): GetMusicComparisonHandler
    {
        return new GetMusicComparisonHandler(
            $this->lastfm,
            $this->discogs,
            new AlbumNormalizer(new NullLogger()),
            $this->redis,
            $this->connection,
            'lastfm_user',
            'discogs_user',
        );
    }

    public function testSplitsAlbumsIntoOwnedAndWantList(): void
    {
        $this->lastfm->method('getTopAlbums')->willReturnCallback(function (string $user, string $period, int $limit) {
            if (2 === $limit) {
                return [
                    new Album('Pink Floyd', 'The Wall', 200, null),
                    new Album('Radiohead', 'OK Computer', 150, null),
                ];
            }

            return [
                new Album('Pink Floyd', 'The Wall', 200, null),
            ];
        });

        $this->discogs->method('getUserCollection')->willReturn([
            new VinylRecord('Pink Floyd', 'The Wall', 1979, 'Vinyl', 1),
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
                new Album('Artist A', 'Album A', 100, null),
                new Album('Artist B', 'Album B', 90, null),
                new Album('Artist C', 'Album C', 80, null),
                new Album('Artist D', 'Album D', 70, null),
            ] : []
        );

        $this->discogs->method('getUserCollection')->willReturn([
            new VinylRecord('Artist A', 'Album A', 2000, 'Vinyl', 1),
            new VinylRecord('Artist B', 'Album B', 2001, 'Vinyl', 2),
        ]);

        $result = $this->makeHandler()(new GetMusicComparison(period: '1month', limit: 4));

        self::assertSame(self::HALF_MATCH_SCORE, $result->matchScore);
    }

    public function testIdentifiesDustyShelf(): void
    {
        $this->lastfm->method('getTopAlbums')->willReturnCallback(
            fn ($u, $p, $limit) => 500 === $limit ? [new Album('Artist A', 'Album A', 100, null)] : []
        );

        $this->discogs->method('getUserCollection')->willReturn([
            new VinylRecord('Artist A', 'Album A', 2000, 'Vinyl', 1),
            new VinylRecord('Forgotten', 'Forgotten Album', 1980, 'Vinyl', 2),
        ]);

        $result = $this->makeHandler()(new GetMusicComparison(limit: 50));

        self::assertCount(1, $result->dustyShelf);
        self::assertSame('Forgotten Album', $result->dustyShelf[0]->title);
    }

    public function testMatchingIgnoresParenthesesFormatDifferences(): void
    {
        $this->lastfm->method('getTopAlbums')->willReturnCallback(
            fn ($u, $p, $limit) => 1 === $limit ? [new Album('Radiohead', 'OK Computer (Remastered 2009)', 100, null)] : []
        );

        $this->discogs->method('getUserCollection')->willReturn([
            new VinylRecord('Radiohead', 'OK Computer', 1997, 'Vinyl', 1),
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
            'matchScore' => self::CACHED_MATCH_SCORE_MARKER,
            'recentlyPlayedNotOwned' => [],
        ]);

        $redis = $this->createMock(Redis::class);
        $redis->method('get')->willReturn($payload);
        $redis->expects(self::never())->method('setex');

        $handler = new GetMusicComparisonHandler(
            $this->lastfm,
            $this->discogs,
            new AlbumNormalizer(new NullLogger()),
            $redis,
            $this->connection,
            'user',
            'user'
        );

        $result = $handler(new GetMusicComparison());

        self::assertSame(self::CACHED_MATCH_SCORE_MARKER, $result->matchScore);
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
            'matchScore' => self::HALF_MATCH_SCORE,
            'recentlyPlayedNotOwned' => [
                ['artist' => 'Beach House', 'title' => 'Bloom', 'playCount' => 12, 'imageUrl' => null],
            ],
        ]);

        $redis = $this->createMock(Redis::class);
        $redis->method('get')->willReturn($payload);

        $handler = new GetMusicComparisonHandler($this->lastfm, $this->discogs, new AlbumNormalizer(new NullLogger()), $redis, $this->connection, 'u', 'u');
        $result = $handler(new GetMusicComparison());

        self::assertCount(1, $result->ownedAndListened);
        self::assertSame('Pink Floyd', $result->ownedAndListened[0]->artist);
        self::assertSame('https://img.example/wall.jpg', $result->ownedAndListened[0]->imageUrl);
        self::assertCount(1, $result->wantList);
        self::assertNull($result->wantList[0]->imageUrl);
        self::assertCount(2, $result->dustyShelf);
        self::assertSame(1975, $result->dustyShelf[0]->year);
        self::assertNull($result->dustyShelf[1]->year);
        self::assertSame(self::HALF_MATCH_SCORE, $result->matchScore);
        self::assertCount(1, $result->recentlyPlayedNotOwned);
        self::assertSame('Bloom', $result->recentlyPlayedNotOwned[0]->title);
        self::assertSame(12, $result->recentlyPlayedNotOwned[0]->playCount);
    }

    public function testIgnoresMalformedJsonAndRecomputes(): void
    {
        $this->lastfm->method('getTopAlbums')->willReturn([new Album('A', 'B', 1, null)]);
        $this->discogs->method('getUserCollection')->willReturn([]);

        $redis = $this->createMock(Redis::class);
        $redis->method('get')->willReturn('{not valid json');
        $redis->expects(self::once())->method('setex');

        $handler = new GetMusicComparisonHandler($this->lastfm, $this->discogs, new AlbumNormalizer(new NullLogger()), $redis, $this->connection, 'u', 'u');
        $result = $handler(new GetMusicComparison(limit: 1));

        self::assertCount(1, $result->wantList);
    }

    public function testIgnoresWrongStructureAndRecomputes(): void
    {
        $this->lastfm->method('getTopAlbums')->willReturn([new Album('A', 'B', 1, null)]);
        $this->discogs->method('getUserCollection')->willReturn([]);

        $redis = $this->createMock(Redis::class);
        $redis->method('get')->willReturn(json_encode(['ownedAndListened' => 'not-an-array']));
        $redis->expects(self::once())->method('setex');

        $handler = new GetMusicComparisonHandler($this->lastfm, $this->discogs, new AlbumNormalizer(new NullLogger()), $redis, $this->connection, 'u', 'u');
        $result = $handler(new GetMusicComparison(limit: 1));

        self::assertCount(1, $result->wantList);
    }

    public function testCacheKeyIncludesDiscogsUsername(): void
    {
        $this->lastfm->method('getTopAlbums')->willReturn([]);
        $this->discogs->method('getUserCollection')->willReturn([]);

        $observedKeys = [];
        $redis = $this->createMock(Redis::class);
        $redis->method('get')->willReturnCallback(function (string $key) use (&$observedKeys) {
            $observedKeys[] = $key;

            return false;
        });

        $makeHandler = fn (string $discogsUsername) => new GetMusicComparisonHandler(
            $this->lastfm,
            $this->discogs,
            new AlbumNormalizer(new NullLogger()),
            $redis,
            $this->connection,
            'same_lastfm_user',
            $discogsUsername,
        );

        $makeHandler('alice_discogs')(new GetMusicComparison(period: '1month', limit: 10));
        $makeHandler('bob_discogs')(new GetMusicComparison(period: '1month', limit: 10));

        self::assertCount(2, $observedKeys);
        self::assertNotSame($observedKeys[0], $observedKeys[1], 'Cache key must differ when discogsUsername changes');
        self::assertStringContainsString('alice_discogs', $observedKeys[0]);
        self::assertStringContainsString('bob_discogs', $observedKeys[1]);
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
            'matchScore' => self::NO_MATCH_SCORE,
        ]));
        $redis->expects(self::once())->method('setex');

        $handler = new GetMusicComparisonHandler($this->lastfm, $this->discogs, new AlbumNormalizer(new NullLogger()), $redis, $this->connection, 'u', 'u');
        $result = $handler(new GetMusicComparison());

        self::assertSame(self::NO_MATCH_SCORE, $result->matchScore);
    }
}
