<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Music\Infrastructure;

use App\Module\Music\Application\Command\RefreshDiscogsCollection;
use App\Module\Music\Application\Exception\DiscogsAuthException;
use App\Module\Music\Application\Exception\DiscogsNotFoundException;
use App\Module\Music\Application\Exception\DiscogsRateLimitException;
use App\Module\Music\Application\Exception\DiscogsUnavailableException;
use App\Module\Music\Infrastructure\External\DiscogsApiClient;
use App\Module\Music\Infrastructure\External\DiscogsClockDriftDetector;
use App\Module\Music\Infrastructure\External\DiscogsCredentials;
use App\Module\Music\Infrastructure\External\DiscogsOAuth1Signer;
use App\Module\Music\Infrastructure\Persistence\DiscogsTokenRepositoryInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Redis;
use RuntimeException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Throwable;

final class DiscogsApiClientTest extends TestCase
{
    private DiscogsTokenRepositoryInterface $tokenRepo;
    private DiscogsOAuth1Signer $signer;

    protected function setUp(): void
    {
        $this->tokenRepo = $this->createStub(DiscogsTokenRepositoryInterface::class);
        $this->tokenRepo->method('get')->willReturn([
            'oauth_token' => 'test-token',
            'oauth_token_secret' => 'test-secret',
        ]);

        $this->signer = new DiscogsOAuth1Signer();
    }

    private function makeReleasePage(array $releases, int $page = 1, int $totalPages = 1): string
    {
        return json_encode([
            'pagination' => ['page' => $page, 'pages' => $totalPages, 'per_page' => 100],
            'releases' => $releases,
        ]);
    }

    private function makeRelease(string $artist, string $title, int $year, string $format, int $id): array
    {
        return [
            'id' => $id,
            'basic_information' => [
                'title' => $title,
                'year' => $year,
                'artists' => [['name' => $artist]],
                'formats' => [['name' => $format]],
            ],
        ];
    }

    private function nullBus(): MessageBusInterface
    {
        $bus = $this->createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(static fn (object $message) => new Envelope($message));

        return $bus;
    }

    /**
     * @param class-string<Throwable> $expectedException
     */
    private function assertHttpErrorTranslatesTo(int $httpStatus, string $expectedException, string $expectedMessageFragment): void
    {
        $redis = $this->createMock(Redis::class);
        // setex must NOT be called when the upstream request fails — guards against
        // silently caching empty results from an error response.
        $redis->expects(self::never())->method('setex');

        $httpClient = new MockHttpClient(new MockResponse('{"error":"upstream"}', ['http_code' => $httpStatus]));
        $client = new DiscogsApiClient($httpClient, $redis, $this->tokenRepo, $this->signer, $this->nullBus(), new DiscogsCredentials('key', 'secret'), new DiscogsClockDriftDetector(new NullLogger()));

        $this->expectException($expectedException);
        $this->expectExceptionMessageMatches('/'.preg_quote($expectedMessageFragment, '/').'/i');

        $client->fetchAndCacheCollection('testuser');
    }

    public function testGetUserCollectionReturnsCachedRecordsWithoutHttpCall(): void
    {
        $cachePayload = json_encode([[
            'artist' => 'Artist',
            'title' => 'Album',
            'year' => 2000,
            'format' => 'Vinyl',
            'discogsId' => 1,
        ]], JSON_THROW_ON_ERROR);

        $redis = $this->createMock(Redis::class);
        $redis->method('get')->willReturn($cachePayload);
        $redis->expects(self::never())->method('setex');

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::never())->method('dispatch');

        $client = new DiscogsApiClient(new MockHttpClient(), $redis, $this->tokenRepo, $this->signer, $bus, new DiscogsCredentials('key', 'secret'), new DiscogsClockDriftDetector(new NullLogger()));

        $records = $client->getUserCollection('testuser');

        self::assertCount(1, $records);
        self::assertSame('Artist', $records[0]->artist);
        self::assertSame('Album', $records[0]->title);
        self::assertSame(2000, $records[0]->year);
    }

    public function testGetUserCollectionDispatchesRefreshOnCacheMissAndThrows(): void
    {
        $redis = $this->createMock(Redis::class);
        $redis->method('get')->willReturn(false);
        $redis->expects(self::never())->method('setex');

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::once())
            ->method('dispatch')
            ->with(self::callback(static fn (object $msg) => $msg instanceof RefreshDiscogsCollection && 'testuser' === $msg->username))
            ->willReturnCallback(static fn (object $msg) => new Envelope($msg));

        $client = new DiscogsApiClient(new MockHttpClient(), $redis, $this->tokenRepo, $this->signer, $bus, new DiscogsCredentials('key', 'secret'), new DiscogsClockDriftDetector(new NullLogger()));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Discogs collection is being refreshed');

        $client->getUserCollection('testuser');
    }

    public function testGetUserCollectionDispatchesRefreshWhenCacheCorrupted(): void
    {
        $redis = $this->createMock(Redis::class);
        $redis->method('get')->willReturn('not-valid-json');

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::once())
            ->method('dispatch')
            ->willReturnCallback(static fn (object $msg) => new Envelope($msg));

        $client = new DiscogsApiClient(new MockHttpClient(), $redis, $this->tokenRepo, $this->signer, $bus, new DiscogsCredentials('key', 'secret'), new DiscogsClockDriftDetector(new NullLogger()));

        $this->expectException(RuntimeException::class);

        $client->getUserCollection('testuser');
    }

    public function testFetchAndCacheCollectionWritesSinglePageToCache(): void
    {
        $json = $this->makeReleasePage([
            $this->makeRelease('Pink Floyd', 'The Wall', 1979, 'Vinyl', 12345),
            $this->makeRelease('Radiohead', 'OK Computer', 1997, 'Vinyl', 67890),
        ]);

        $redis = $this->createMock(Redis::class);
        $redis->expects(self::once())
            ->method('setex')
            ->with('discogs:collection:testuser', 21600, self::callback(static function (string $payload): bool {
                $rows = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);

                return is_array($rows)
                    && 2 === count($rows)
                    && 'Pink Floyd' === $rows[0]['artist']
                    && 'OK Computer' === $rows[1]['title'];
            }))
            ->willReturn(true);

        $httpClient = new MockHttpClient(new MockResponse($json));
        $client = new DiscogsApiClient($httpClient, $redis, $this->tokenRepo, $this->signer, $this->nullBus(), new DiscogsCredentials('key', 'secret'), new DiscogsClockDriftDetector(new NullLogger()));

        $client->fetchAndCacheCollection('testuser');
    }

    public function testFetchAndCacheCollectionCombinesMultiplePages(): void
    {
        $page1 = $this->makeReleasePage(
            [$this->makeRelease('Artist A', 'Album A', 2000, 'Vinyl', 1)],
            1,
            2
        );
        $page2 = $this->makeReleasePage(
            [$this->makeRelease('Artist B', 'Album B', 2001, 'CD', 2)],
            2,
            2
        );

        $redis = $this->createMock(Redis::class);
        $redis->expects(self::once())
            ->method('setex')
            ->with('discogs:collection:testuser', 21600, self::callback(static function (string $payload): bool {
                $rows = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);

                return is_array($rows)
                    && 2 === count($rows)
                    && 'Artist A' === $rows[0]['artist']
                    && 'Artist B' === $rows[1]['artist'];
            }))
            ->willReturn(true);

        $httpClient = new MockHttpClient([new MockResponse($page1), new MockResponse($page2)]);
        $client = new DiscogsApiClient($httpClient, $redis, $this->tokenRepo, $this->signer, $this->nullBus(), new DiscogsCredentials('key', 'secret'), new DiscogsClockDriftDetector(new NullLogger()));

        $client->fetchAndCacheCollection('testuser');
    }

    public function testFetchAndCacheCollectionThrowsWhenNoTokenStored(): void
    {
        $tokenRepo = $this->createStub(DiscogsTokenRepositoryInterface::class);
        $tokenRepo->method('get')->willReturn(null);

        $redis = $this->createMock(Redis::class);
        $redis->expects(self::never())->method('setex');

        $client = new DiscogsApiClient(new MockHttpClient(), $redis, $tokenRepo, $this->signer, $this->nullBus(), new DiscogsCredentials('key', 'secret'), new DiscogsClockDriftDetector(new NullLogger()));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Discogs not authorized');

        $client->fetchAndCacheCollection('testuser');
    }

    public function testFetchAndCacheCollectionTranslates401ToDiscogsAuthException(): void
    {
        $this->assertHttpErrorTranslatesTo(401, DiscogsAuthException::class, 'authorization failed');
    }

    public function testFetchAndCacheCollectionTranslates403ToDiscogsAuthException(): void
    {
        $this->assertHttpErrorTranslatesTo(403, DiscogsAuthException::class, 'authorization failed');
    }

    public function testFetchAndCacheCollectionTranslates404ToDiscogsNotFoundException(): void
    {
        $this->assertHttpErrorTranslatesTo(404, DiscogsNotFoundException::class, 'not found');
    }

    public function testFetchAndCacheCollectionTranslates429ToDiscogsRateLimitException(): void
    {
        $this->assertHttpErrorTranslatesTo(429, DiscogsRateLimitException::class, 'rate limit');
    }

    public function testFetchAndCacheCollectionTranslates500ToDiscogsUnavailableException(): void
    {
        $this->assertHttpErrorTranslatesTo(500, DiscogsUnavailableException::class, 'unavailable');
    }

    public function testFetchAndCacheCollectionMapsZeroYearToNull(): void
    {
        $json = $this->makeReleasePage([$this->makeRelease('Artist', 'Album', 0, 'Vinyl', 1)]);

        $redis = $this->createMock(Redis::class);
        $redis->expects(self::once())
            ->method('setex')
            ->with(self::anything(), self::anything(), self::callback(static function (string $payload): bool {
                $rows = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);

                return null === $rows[0]['year'];
            }))
            ->willReturn(true);

        $httpClient = new MockHttpClient(new MockResponse($json));
        $client = new DiscogsApiClient($httpClient, $redis, $this->tokenRepo, $this->signer, $this->nullBus(), new DiscogsCredentials('key', 'secret'), new DiscogsClockDriftDetector(new NullLogger()));

        $client->fetchAndCacheCollection('testuser');
    }
}
