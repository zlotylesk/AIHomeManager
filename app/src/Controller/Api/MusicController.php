<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Messaging\CommandBus;
use App\Messaging\QueryBus;
use App\Module\Music\Application\Command\LogListeningSession;
use App\Module\Music\Application\DTO\ListeningSessionDTO;
use App\Module\Music\Application\DTO\MusicComparisonDTO;
use App\Module\Music\Application\Exception\DiscogsAuthException;
use App\Module\Music\Application\Exception\DiscogsRateLimitException;
use App\Module\Music\Application\Query\GetListeningHistory;
use App\Module\Music\Application\Query\GetMusicComparison;
use App\Module\Music\Domain\Enum\ListeningSource;
use App\Module\Music\Domain\Port\MusicListeningHistoryInterface;
use App\Module\Music\Domain\Port\VinylCollectionInterface;
use App\Module\Music\Domain\ReadModel\Album;
use App\Module\Music\Domain\ReadModel\VinylRecord;
use DateTimeImmutable;
use Exception;
use InvalidArgumentException;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

#[Route('/music')]
final class MusicController extends AbstractController
{
    private const array VALID_PERIODS = ['7day', '1month', '3month', '6month', '12month', 'overall'];
    private const int MAX_TOP_ALBUMS_LIMIT = 1000;
    private const int MAX_COMPARISON_LIMIT = 200;
    private const int MAX_HISTORY_LIMIT = 500;
    private const int DEFAULT_LIMIT = 50;

    public function __construct(
        private readonly MusicListeningHistoryInterface $listeningHistory,
        private readonly VinylCollectionInterface $vinylCollection,
        private readonly QueryBus $queryBus,
        private readonly CommandBus $commandBus,
        private readonly string $lastfmUsername,
        private readonly string $discogsUsername,
        private readonly NormalizerInterface $normalizer,
    ) {
    }

    #[Route('/top-albums', methods: ['GET'])]
    #[OA\Get(
        summary: 'Top albums (Last.fm)',
        description: 'Returns the most-played albums for a period, read live from Last.fm. 503 when the provider is unreachable.',
        tags: ['Music'],
        parameters: [
            new OA\QueryParameter(
                name: 'period',
                description: 'Last.fm period window.',
                required: false,
                schema: new OA\Schema(type: 'string', enum: ['7day', '1month', '3month', '6month', '12month', 'overall'], default: '1month'),
            ),
            new OA\QueryParameter(
                name: 'limit',
                description: 'Maximum number of albums (1–1000).',
                required: false,
                schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 1000, default: 50),
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'The top albums.',
                content: new OA\JsonContent(type: 'array', items: new OA\Items(ref: new Model(type: Album::class))),
            ),
            new OA\Response(response: 401, ref: '#/components/responses/UnauthorizedError'),
            new OA\Response(response: 422, ref: '#/components/responses/UnprocessableEntityError'),
            new OA\Response(response: 503, description: 'The Last.fm provider is unavailable.', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
        ],
    )]
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

        return new JsonResponse($this->normalizer->normalize($albums));
    }

    #[Route('/comparison', methods: ['GET'])]
    #[OA\Get(
        summary: 'Owned-vs-listened comparison',
        description: 'Cross-references the Discogs collection with Last.fm listening: a match score plus owned+listened, want-list, dusty-shelf and recently-played-not-owned buckets.',
        tags: ['Music'],
        parameters: [
            new OA\QueryParameter(
                name: 'period',
                required: false,
                schema: new OA\Schema(type: 'string', enum: ['7day', '1month', '3month', '6month', '12month', 'overall'], default: '1month'),
            ),
            new OA\QueryParameter(
                name: 'limit',
                description: 'Maximum number of albums considered (1–200).',
                required: false,
                schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 200, default: 50),
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'The comparison result.',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'matchScore', type: 'number', format: 'float'),
                    new OA\Property(property: 'ownedAndListened', type: 'array', items: new OA\Items(ref: new Model(type: Album::class))),
                    new OA\Property(property: 'wantList', type: 'array', items: new OA\Items(ref: new Model(type: Album::class))),
                    new OA\Property(property: 'dustyShelf', type: 'array', items: new OA\Items(ref: new Model(type: VinylRecord::class))),
                    new OA\Property(property: 'recentlyPlayedNotOwned', type: 'array', items: new OA\Items(ref: new Model(type: Album::class))),
                ]),
            ),
            new OA\Response(response: 401, ref: '#/components/responses/UnauthorizedError'),
            new OA\Response(response: 422, ref: '#/components/responses/UnprocessableEntityError'),
            new OA\Response(response: 429, ref: '#/components/responses/TooManyRequestsError'),
            new OA\Response(response: 503, description: 'A music provider (Discogs/Last.fm) is unavailable.', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
        ],
    )]
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
            $result = $this->queryBus->ask(new GetMusicComparison($period, $limit));
        } catch (DiscogsAuthException $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_UNAUTHORIZED);
        } catch (DiscogsRateLimitException $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_TOO_MANY_REQUESTS);
        } catch (RuntimeException $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        return new JsonResponse([
            'matchScore' => $result->matchScore,
            'ownedAndListened' => $this->normalizer->normalize($result->ownedAndListened),
            'wantList' => $this->normalizer->normalize($result->wantList),
            'dustyShelf' => $this->normalizer->normalize($result->dustyShelf),
            'recentlyPlayedNotOwned' => $this->normalizer->normalize($result->recentlyPlayedNotOwned),
        ]);
    }

    #[Route('/collection', methods: ['GET'])]
    #[OA\Get(
        summary: 'Vinyl collection (Discogs)',
        description: 'Returns the owned vinyl collection from Discogs. Served from cache with an async refresh dispatched on a miss.',
        tags: ['Music'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'The vinyl collection.',
                content: new OA\JsonContent(type: 'array', items: new OA\Items(ref: new Model(type: VinylRecord::class))),
            ),
            new OA\Response(response: 401, ref: '#/components/responses/UnauthorizedError'),
            new OA\Response(response: 429, ref: '#/components/responses/TooManyRequestsError'),
            new OA\Response(response: 503, description: 'Discogs is unavailable.', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
        ],
    )]
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

        return new JsonResponse($this->normalizer->normalize($records));
    }

    /**
     * Local play history — served from our own DB, never from Last.fm. This is
     * the authoritative source that survives the external API going away (HMAI-144).
     */
    #[Route('/history', methods: ['GET'])]
    #[OA\Get(
        summary: 'Local listening history',
        description: 'Returns the authoritative local play history (from our own DB, never Last.fm), optionally filtered by source and date range.',
        tags: ['Music'],
        parameters: [
            new OA\QueryParameter(
                name: 'limit',
                description: 'Maximum number of sessions (1–500).',
                required: false,
                schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 500, default: 50),
            ),
            new OA\QueryParameter(
                name: 'source',
                description: 'Filter by listening source.',
                required: false,
                schema: new OA\Schema(type: 'string', enum: ['lastfm_scrobble', 'lastfm_top_delta', 'manual']),
            ),
            new OA\QueryParameter(
                name: 'from',
                description: 'Inclusive lower bound (ISO 8601).',
                required: false,
                schema: new OA\Schema(type: 'string', format: 'date-time'),
            ),
            new OA\QueryParameter(
                name: 'to',
                description: 'Inclusive upper bound (ISO 8601).',
                required: false,
                schema: new OA\Schema(type: 'string', format: 'date-time'),
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'The listening history.',
                content: new OA\JsonContent(type: 'array', items: new OA\Items(ref: new Model(type: ListeningSessionDTO::class))),
            ),
            new OA\Response(response: 401, ref: '#/components/responses/UnauthorizedError'),
            new OA\Response(response: 422, ref: '#/components/responses/UnprocessableEntityError'),
        ],
    )]
    public function history(Request $request): JsonResponse
    {
        $limit = $this->parseLimit($request->query->get('limit'), self::MAX_HISTORY_LIMIT);

        if (null === $limit) {
            return new JsonResponse(
                ['error' => sprintf('Field "limit" must be a positive integer between 1 and %d.', self::MAX_HISTORY_LIMIT)],
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        $source = null;
        $rawSource = $request->query->get('source');
        if (null !== $rawSource && '' !== $rawSource) {
            $source = ListeningSource::tryFrom($rawSource);
            if (null === $source) {
                return new JsonResponse(['error' => $this->invalidSourceMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
        }

        try {
            $from = $this->parseDate($request->query->get('from'));
            $to = $this->parseDate($request->query->get('to'));
        } catch (Exception) {
            return new JsonResponse(
                ['error' => 'Invalid date. Use ISO 8601 (e.g. 2026-05-28 or 2026-05-28T12:00:00Z).'],
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        /** @var ListeningSessionDTO[] $sessions */
        $sessions = $this->queryBus->ask(new GetListeningHistory($from, $to, $source, $limit));

        return new JsonResponse($this->normalizer->normalize($sessions));
    }

    /**
     * Manual scrobble entry — lets the user record a play Last.fm never captured
     * (HMAI-144). Idempotent: re-posting the same album+time is a silent no-op.
     */
    #[Route('/sessions', methods: ['POST'])]
    #[OA\Post(
        summary: 'Log a listening session',
        description: 'Manually records a play Last.fm never captured. Idempotent — re-posting the same album+time is a silent no-op.',
        tags: ['Music'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['artist', 'title', 'playedAt'],
                properties: [
                    new OA\Property(property: 'artist', type: 'string', example: 'Radiohead'),
                    new OA\Property(property: 'title', type: 'string', example: 'In Rainbows'),
                    new OA\Property(property: 'playedAt', type: 'string', format: 'date-time'),
                    new OA\Property(property: 'source', type: 'string', enum: ['lastfm_scrobble', 'lastfm_top_delta', 'manual'], default: 'manual'),
                    new OA\Property(property: 'playCount', type: 'integer', minimum: 0, nullable: true),
                ],
            ),
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Listening session logged.',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'artist', type: 'string'),
                    new OA\Property(property: 'title', type: 'string'),
                    new OA\Property(property: 'playedAt', type: 'string', format: 'date-time'),
                    new OA\Property(property: 'source', type: 'string'),
                    new OA\Property(property: 'playCount', type: 'integer', nullable: true),
                ]),
            ),
            new OA\Response(response: 400, description: 'The request body is not a JSON object.', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
            new OA\Response(response: 401, ref: '#/components/responses/UnauthorizedError'),
            new OA\Response(response: 422, ref: '#/components/responses/UnprocessableEntityError'),
        ],
    )]
    public function createSession(Request $request): JsonResponse
    {
        $payload = json_decode($request->getContent(), true);

        if (!is_array($payload)) {
            return new JsonResponse(['error' => 'Request body must be a JSON object.'], Response::HTTP_BAD_REQUEST);
        }

        $artist = $payload['artist'] ?? null;
        $title = $payload['title'] ?? null;
        $playedAtRaw = $payload['playedAt'] ?? null;

        if (!is_string($artist) || !is_string($title) || !is_string($playedAtRaw)) {
            return new JsonResponse(
                ['error' => 'Fields "artist", "title" and "playedAt" are required strings.'],
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        try {
            $playedAt = new DateTimeImmutable($playedAtRaw);
        } catch (Exception) {
            return new JsonResponse(
                ['error' => 'Field "playedAt" must be a valid ISO 8601 date-time.'],
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        $source = ListeningSource::MANUAL;
        $rawSource = $payload['source'] ?? null;
        if (null !== $rawSource) {
            if (!is_string($rawSource) || null === ($parsed = ListeningSource::tryFrom($rawSource))) {
                return new JsonResponse(['error' => $this->invalidSourceMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
            $source = $parsed;
        }

        $playCount = null;
        $rawPlayCount = $payload['playCount'] ?? null;
        if (null !== $rawPlayCount) {
            if (!is_int($rawPlayCount) || $rawPlayCount < 0) {
                return new JsonResponse(['error' => 'Field "playCount" must be a non-negative integer.'], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
            $playCount = $rawPlayCount;
        }

        try {
            $this->commandBus->dispatch(new LogListeningSession($artist, $title, $playedAt, $source, $playCount));
        } catch (HandlerFailedException $e) {
            if ($e->getPrevious() instanceof InvalidArgumentException) {
                return new JsonResponse(['error' => $e->getPrevious()->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            throw $e;
        }

        return new JsonResponse([
            'artist' => $artist,
            'title' => $title,
            'playedAt' => $playedAt->format(DATE_ATOM),
            'source' => $source->value,
            'playCount' => $playCount,
        ], Response::HTTP_CREATED);
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

    private function parseDate(?string $raw): ?DateTimeImmutable
    {
        if (null === $raw || '' === $raw) {
            return null;
        }

        return new DateTimeImmutable($raw);
    }

    private function invalidSourceMessage(): string
    {
        return 'Invalid source. Allowed: '.implode(
            ', ',
            array_map(static fn (ListeningSource $s): string => $s->value, ListeningSource::cases())
        );
    }
}
