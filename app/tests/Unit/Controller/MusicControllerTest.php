<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller;

use App\Controller\Api\MusicController;
use App\Controller\PaginationRequestParser;
use App\Messaging\CommandBus;
use App\Messaging\QueryBus;
use App\Module\Music\Application\Exception\DiscogsAuthException;
use App\Module\Music\Application\Exception\DiscogsNotFoundException;
use App\Module\Music\Application\Exception\DiscogsRateLimitException;
use App\Module\Music\Application\Exception\DiscogsUnavailableException;
use App\Module\Music\Domain\Port\MusicListeningHistoryInterface;
use App\Module\Music\Domain\Port\VinylCollectionInterface;
use App\Module\Music\Domain\ReadModel\VinylRecord;
use App\Serializer\PageNormalizer;
use App\Serializer\VinylRecordDTONormalizer;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Serializer\Serializer;

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
        $messageBus = $this->createStub(MessageBusInterface::class);

        $controller = new MusicController(
            listeningHistory: $listeningHistory,
            vinylCollection: $vinylCollection,
            queryBus: new QueryBus($messageBus),
            commandBus: new CommandBus($messageBus),
            lastfmUsername: 'lf-user',
            discogsUsername: 'disco-user',
            // PageNormalizer first: it handles the envelope and delegates each
            // record back through the serializer, so both must be present or the
            // paginated collection response has nothing to normalize it.
            normalizer: new Serializer([new PageNormalizer(), new VinylRecordDTONormalizer()]),
            pagination: new PaginationRequestParser(),
        );

        $controller->setContainer(new Container());

        return $controller;
    }

    public function testCollectionReturns401WhenDiscogsAuthFails(): void
    {
        $vinyl = $this->createStub(VinylCollectionInterface::class);
        $vinyl->method('getUserCollection')->willThrowException(new DiscogsAuthException('re-auth required'));

        $response = $this->makeController($vinyl)->collection(new Request());

        self::assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
        self::assertSame('re-auth required', json_decode((string) $response->getContent(), true)['error']);
    }

    public function testCollectionReturns429WhenDiscogsRateLimited(): void
    {
        $vinyl = $this->createStub(VinylCollectionInterface::class);
        $vinyl->method('getUserCollection')->willThrowException(new DiscogsRateLimitException('slow down'));

        $response = $this->makeController($vinyl)->collection(new Request());

        self::assertSame(Response::HTTP_TOO_MANY_REQUESTS, $response->getStatusCode());
        self::assertSame('slow down', json_decode((string) $response->getContent(), true)['error']);
    }

    public function testCollectionReturns503WhenDiscogsNotFound(): void
    {
        $vinyl = $this->createStub(VinylCollectionInterface::class);
        $vinyl->method('getUserCollection')->willThrowException(new DiscogsNotFoundException('user not found'));

        $response = $this->makeController($vinyl)->collection(new Request());

        self::assertSame(Response::HTTP_SERVICE_UNAVAILABLE, $response->getStatusCode());
    }

    public function testCollectionReturns503WhenDiscogsUnavailable(): void
    {
        $vinyl = $this->createStub(VinylCollectionInterface::class);
        $vinyl->method('getUserCollection')->willThrowException(new DiscogsUnavailableException('5xx'));

        $response = $this->makeController($vinyl)->collection(new Request());

        self::assertSame(Response::HTTP_SERVICE_UNAVAILABLE, $response->getStatusCode());
    }

    public function testCollectionReturns503WhenCollectionIsBeingRefreshed(): void
    {
        $vinyl = $this->createStub(VinylCollectionInterface::class);
        $vinyl->method('getUserCollection')->willThrowException(new RuntimeException('Discogs collection is being refreshed.'));

        $response = $this->makeController($vinyl)->collection(new Request());

        self::assertSame(Response::HTTP_SERVICE_UNAVAILABLE, $response->getStatusCode());
    }

    public function testCollectionReturnsRecordsOnSuccess(): void
    {
        $vinyl = $this->createStub(VinylCollectionInterface::class);
        $vinyl->method('getUserCollection')->willReturn([]);

        $response = $this->makeController($vinyl)->collection(new Request());

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
    }

    public function testCollectionWindowsTheCachedCollection(): void
    {
        // The port can only return the whole collection, so the page is applied
        // here. `total` must still report all four records — a client cannot tell
        // there is a second page if the count shrinks to the size of the first.
        $vinyl = $this->createStub(VinylCollectionInterface::class);
        $vinyl->method('getUserCollection')->willReturn([
            new VinylRecord('A', 'One', 1970, 'Vinyl', 1),
            new VinylRecord('B', 'Two', 1971, 'Vinyl', 2),
            new VinylRecord('C', 'Three', 1972, 'Vinyl', 3),
            new VinylRecord('D', 'Four', 1973, 'Vinyl', 4),
        ]);

        $response = $this->makeController($vinyl)->collection(new Request(['page' => '2', 'perPage' => '3']));

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());

        $body = json_decode((string) $response->getContent(), true);
        self::assertIsArray($body);
        self::assertSame(['Four'], array_column((array) $body['data'], 'title'));
        self::assertSame(
            ['page' => 2, 'perPage' => 3, 'total' => 4, 'totalPages' => 2],
            $body['pagination'],
        );
    }

    public function testCollectionRejectsAnOutOfRangeWindowRatherThanClamping(): void
    {
        // Parsed before the Discogs read, so a malformed window is answered
        // without paying for (or depending on) the upstream call.
        $vinyl = $this->createStub(VinylCollectionInterface::class);

        $this->expectException(UnprocessableEntityHttpException::class);

        $this->makeController($vinyl)->collection(new Request(['perPage' => '101']));
    }
}
