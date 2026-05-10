<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller;

use App\Controller\MusicController;
use App\Module\Music\Application\Exception\DiscogsAuthException;
use App\Module\Music\Application\Exception\DiscogsNotFoundException;
use App\Module\Music\Application\Exception\DiscogsRateLimitException;
use App\Module\Music\Application\Exception\DiscogsUnavailableException;
use App\Module\Music\Domain\Port\MusicListeningHistoryInterface;
use App\Module\Music\Domain\Port\VinylCollectionInterface;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Verifies that the controller maps Discogs exception types to the right HTTP status:
 *   DiscogsAuthException        → 401
 *   DiscogsRateLimitException   → 429
 *   anything else (RuntimeException, DiscogsNotFoundException,
 *   DiscogsUnavailableException, generic refresh-in-progress)  → 503
 */
final class MusicControllerTest extends TestCase
{
    private function makeController(VinylCollectionInterface $vinylCollection): MusicController
    {
        $listeningHistory = $this->createStub(MusicListeningHistoryInterface::class);
        $queryBus = $this->createStub(MessageBusInterface::class);

        $controller = new MusicController(
            listeningHistory: $listeningHistory,
            vinylCollection: $vinylCollection,
            queryBus: $queryBus,
            lastfmUsername: 'lf-user',
            discogsUsername: 'disco-user',
        );

        // Controllers extending AbstractController need a container reference even
        // when the action under test doesn't touch it — bare TestCase has none.
        $controller->setContainer(new Container());

        return $controller;
    }

    public function testCollectionReturns401WhenDiscogsAuthFails(): void
    {
        $vinyl = $this->createStub(VinylCollectionInterface::class);
        $vinyl->method('getUserCollection')->willThrowException(new DiscogsAuthException('re-auth required'));

        $response = $this->makeController($vinyl)->collection();

        self::assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
        self::assertSame('re-auth required', json_decode((string) $response->getContent(), true)['error']);
    }

    public function testCollectionReturns429WhenDiscogsRateLimited(): void
    {
        $vinyl = $this->createStub(VinylCollectionInterface::class);
        $vinyl->method('getUserCollection')->willThrowException(new DiscogsRateLimitException('slow down'));

        $response = $this->makeController($vinyl)->collection();

        self::assertSame(Response::HTTP_TOO_MANY_REQUESTS, $response->getStatusCode());
        self::assertSame('slow down', json_decode((string) $response->getContent(), true)['error']);
    }

    public function testCollectionReturns503WhenDiscogsNotFound(): void
    {
        // 404 is mapped to 503 by the generic RuntimeException catch — it is not an
        // auth or rate-limit problem, and exposing "user not found" upstream would
        // leak whether a Discogs account exists. Tightening this later is a UX call.
        $vinyl = $this->createStub(VinylCollectionInterface::class);
        $vinyl->method('getUserCollection')->willThrowException(new DiscogsNotFoundException('user not found'));

        $response = $this->makeController($vinyl)->collection();

        self::assertSame(Response::HTTP_SERVICE_UNAVAILABLE, $response->getStatusCode());
    }

    public function testCollectionReturns503WhenDiscogsUnavailable(): void
    {
        $vinyl = $this->createStub(VinylCollectionInterface::class);
        $vinyl->method('getUserCollection')->willThrowException(new DiscogsUnavailableException('5xx'));

        $response = $this->makeController($vinyl)->collection();

        self::assertSame(Response::HTTP_SERVICE_UNAVAILABLE, $response->getStatusCode());
    }

    public function testCollectionReturns503WhenCollectionIsBeingRefreshed(): void
    {
        // Cache-miss path: DiscogsApiClient::getUserCollection schedules an async refresh
        // and throws a generic RuntimeException — that should still map to 503.
        $vinyl = $this->createStub(VinylCollectionInterface::class);
        $vinyl->method('getUserCollection')->willThrowException(new RuntimeException('Discogs collection is being refreshed.'));

        $response = $this->makeController($vinyl)->collection();

        self::assertSame(Response::HTTP_SERVICE_UNAVAILABLE, $response->getStatusCode());
    }

    public function testCollectionReturnsRecordsOnSuccess(): void
    {
        $vinyl = $this->createStub(VinylCollectionInterface::class);
        $vinyl->method('getUserCollection')->willReturn([]);

        $response = $this->makeController($vinyl)->collection();

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
    }
}
