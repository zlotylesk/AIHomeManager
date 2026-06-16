<?php

declare(strict_types=1);

namespace App\Controller;

use App\Module\Music\Application\Command\LogListeningSession;
use App\Module\Music\Application\DTO\AlbumDTO;
use App\Module\Music\Application\DTO\ListeningSessionDTO;
use App\Module\Music\Application\DTO\MusicComparisonDTO;
use App\Module\Music\Application\DTO\VinylRecordDTO;
use App\Module\Music\Application\Exception\DiscogsAuthException;
use App\Module\Music\Application\Exception\DiscogsRateLimitException;
use App\Module\Music\Application\Query\GetListeningHistory;
use App\Module\Music\Application\Query\GetMusicComparison;
use App\Module\Music\Domain\Enum\ListeningSource;
use App\Module\Music\Domain\Port\MusicListeningHistoryInterface;
use App\Module\Music\Domain\Port\VinylCollectionInterface;
use DateTimeImmutable;
use Exception;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/music')]
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
        #[Target('query.bus')]
        private readonly MessageBusInterface $queryBus,
        private readonly MessageBusInterface $commandBus,
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
            'recentlyPlayedNotOwned' => array_map($this->serializeAlbum(...), $result->recentlyPlayedNotOwned),
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
     * Local play history — served from our own DB, never from Last.fm. This is
     * the authoritative source that survives the external API going away (HMAI-144).
     */
    #[Route('/history', methods: ['GET'])]
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
        $sessions = $this->queryBus
            ->dispatch(new GetListeningHistory($from, $to, $source, $limit))
            ->last(HandledStamp::class)
            ?->getResult() ?? [];

        return new JsonResponse(array_map($this->serializeSession(...), $sessions));
    }

    /**
     * Manual scrobble entry — lets the user record a play Last.fm never captured
     * (HMAI-144). Idempotent: re-posting the same album+time is a silent no-op.
     */
    #[Route('/sessions', methods: ['POST'])]
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

    /**
     * @return array<string, mixed>
     */
    private function serializeSession(ListeningSessionDTO $s): array
    {
        return [
            'id' => $s->id,
            'artist' => $s->artist,
            'title' => $s->title,
            'playedAt' => $s->playedAt,
            'source' => $s->source,
            'playCount' => $s->playCount,
        ];
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
