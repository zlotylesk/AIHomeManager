<?php

declare(strict_types=1);

namespace App\Controller;

use App\Module\Music\Application\DTO\AlbumDTO;
use App\Module\Music\Application\DTO\MusicComparisonDTO;
use App\Module\Music\Application\DTO\VinylRecordDTO;
use App\Module\Music\Application\Exception\DiscogsAuthException;
use App\Module\Music\Application\Exception\DiscogsRateLimitException;
use App\Module\Music\Application\Query\GetMusicComparison;
use App\Module\Music\Domain\Port\MusicListeningHistoryInterface;
use App\Module\Music\Domain\Port\VinylCollectionInterface;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/music')]
final class MusicController extends AbstractController
{
    private const array VALID_PERIODS = ['7day', '1month', '3month', '6month', '12month', 'overall'];
    private const int MAX_TOP_ALBUMS_LIMIT = 1000;
    private const int MAX_COMPARISON_LIMIT = 200;
    private const int DEFAULT_LIMIT = 50;

    public function __construct(
        private readonly MusicListeningHistoryInterface $listeningHistory,
        private readonly VinylCollectionInterface $vinylCollection,
        #[Target('query.bus')]
        private readonly MessageBusInterface $queryBus,
        private readonly string $lastfmUsername,
        private readonly string $discogsUsername,
    ) {
    }

    #[Route('/top-albums', methods: ['GET'])]
    public function topAlbums(Request $request): JsonResponse
    {
        $period = $request->query->get('period', '1month');
        $limit = $this->parseLimit($request->query->get('limit'), self::MAX_TOP_ALBUMS_LIMIT);

        if (null === $limit) {
            return new JsonResponse(
                ['error' => sprintf('Field "limit" must be a positive integer between 1 and %d.', self::MAX_TOP_ALBUMS_LIMIT)],
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        if (!in_array($period, self::VALID_PERIODS, true)) {
            return new JsonResponse(
                ['error' => 'Invalid period. Allowed: '.implode(', ', self::VALID_PERIODS)],
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        try {
            $albums = $this->listeningHistory->getTopAlbums($this->lastfmUsername, $period, $limit);
        } catch (RuntimeException $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        return new JsonResponse(array_map(
            fn (AlbumDTO $a) => [
                'artist' => $a->artist,
                'title' => $a->title,
                'playCount' => $a->playCount,
                'imageUrl' => $a->imageUrl,
            ],
            $albums
        ));
    }

    #[Route('/comparison', methods: ['GET'])]
    public function comparison(Request $request): JsonResponse
    {
        $period = $request->query->get('period', '1month');
        $limit = $this->parseLimit($request->query->get('limit'), self::MAX_COMPARISON_LIMIT);

        if (null === $limit) {
            return new JsonResponse(
                ['error' => sprintf('Field "limit" must be a positive integer between 1 and %d.', self::MAX_COMPARISON_LIMIT)],
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        if (!in_array($period, self::VALID_PERIODS, true)) {
            return new JsonResponse(
                ['error' => 'Invalid period. Allowed: '.implode(', ', self::VALID_PERIODS)],
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        try {
            /** @var MusicComparisonDTO $result */
            $result = $this->queryBus->dispatch(new GetMusicComparison($period, $limit))->last(HandledStamp::class)->getResult();
        } catch (DiscogsAuthException $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_UNAUTHORIZED);
        } catch (DiscogsRateLimitException $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_TOO_MANY_REQUESTS);
        } catch (RuntimeException $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        return new JsonResponse([
            'matchScore' => $result->matchScore,
            'ownedAndListened' => array_map($this->serializeAlbum(...), $result->ownedAndListened),
            'wantList' => array_map($this->serializeAlbum(...), $result->wantList),
            'dustyShelf' => array_map($this->serializeRecord(...), $result->dustyShelf),
        ]);
    }

    #[Route('/collection', methods: ['GET'])]
    public function collection(): JsonResponse
    {
        try {
            $records = $this->vinylCollection->getUserCollection($this->discogsUsername);
        } catch (DiscogsAuthException $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_UNAUTHORIZED);
        } catch (DiscogsRateLimitException $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_TOO_MANY_REQUESTS);
        } catch (RuntimeException $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        return new JsonResponse(array_map($this->serializeRecord(...), $records));
    }

    /**
     * Validates `?limit=` query strings strictly. ctype_digit rejects negatives,
     * decimals ("1.5"), scientific notation ("1e3"), and non-numeric strings —
     * is_numeric would accept those and the subsequent (int) cast would silently
     * floor or coerce them, recording smaller values than the caller intended.
     * Null raw → default. Out-of-range or non-digit → null (caller returns 422).
     */
    private function parseLimit(?string $raw, int $max): ?int
    {
        if (null === $raw) {
            return self::DEFAULT_LIMIT;
        }

        if (!ctype_digit($raw)) {
            return null;
        }

        $value = (int) $raw;

        return ($value >= 1 && $value <= $max) ? $value : null;
    }

    private function serializeAlbum(AlbumDTO $a): array
    {
        return [
            'artist' => $a->artist,
            'title' => $a->title,
            'playCount' => $a->playCount,
            'imageUrl' => $a->imageUrl,
        ];
    }

    private function serializeRecord(VinylRecordDTO $r): array
    {
        return [
            'artist' => $r->artist,
            'title' => $r->title,
            'year' => $r->year,
            'format' => $r->format,
            'discogsId' => $r->discogsId,
        ];
    }
}
