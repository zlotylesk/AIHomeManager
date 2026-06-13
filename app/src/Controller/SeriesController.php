<?php

declare(strict_types=1);

namespace App\Controller;

use App\Module\Series\Application\Command\AddEpisode;
use App\Module\Series\Application\Command\AddEpisodeRating;
use App\Module\Series\Application\Command\AddSeason;
use App\Module\Series\Application\Command\CreateSeries;
use App\Module\Series\Application\Command\DeleteEpisode;
use App\Module\Series\Application\Command\DeleteSeason;
use App\Module\Series\Application\Command\DeleteSeries;
use App\Module\Series\Application\Command\ImportWatchedShowsFromTrakt;
use App\Module\Series\Application\Command\RateSeason;
use App\Module\Series\Application\Command\RateSeries;
use App\Module\Series\Application\Command\RenameEpisode;
use App\Module\Series\Application\Command\RenameSeries;
use App\Module\Series\Application\Command\RenumberSeason;
use App\Module\Series\Application\Command\SetEpisodeWatched;
use App\Module\Series\Application\Command\UpdateSeriesMetadata;
use App\Module\Series\Application\DTO\SeriesDetailDTO;
use App\Module\Series\Application\Query\GetAllSeries;
use App\Module\Series\Application\Query\GetSeriesDetail;
use App\Module\Series\Domain\Enum\SeriesStatus;
use App\Module\Series\Domain\Exception\SeasonNumberAlreadyTaken;
use App\Module\Series\Domain\ValueObject\CoverUrl;
use App\Module\Series\Infrastructure\Persistence\TraktTokenRepositoryInterface;
use DomainException;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/series')]
final class SeriesController extends AbstractController
{
    private const int MAX_TITLE_LENGTH = 255;
    private const int MAX_DESCRIPTION_LENGTH = 2000;
    private const int MIN_YEAR = 1900;

    public function __construct(
        private readonly MessageBusInterface $commandBus,
        #[Target('query.bus')]
        private readonly MessageBusInterface $queryBus,
        #[Target('series')]
        private readonly LoggerInterface $logger,
        private readonly TraktTokenRepositoryInterface $traktTokens,
    ) {
    }

    #[Route('', methods: ['GET'])]
    public function list(): JsonResponse
    {
        $this->logger->debug('GET /api/series requested');

        /** @var SeriesDetailDTO[] $series */
        $series = $this->queryBus->dispatch(new GetAllSeries())->last(HandledStamp::class)->getResult();

        $this->logger->debug('GET /api/series returned {count} series', ['count' => count($series)]);

        return new JsonResponse(array_map($this->serializeDTO(...), $series));
    }

    #[Route('/{id}', methods: ['GET'])]
    public function detail(string $id): JsonResponse
    {
        /** @var SeriesDetailDTO|null $dto */
        $dto = $this->queryBus->dispatch(new GetSeriesDetail($id))->last(HandledStamp::class)->getResult();

        if (null === $dto) {
            return new JsonResponse(['error' => 'Series not found.'], Response::HTTP_NOT_FOUND);
        }

        return new JsonResponse($this->serializeDTO($dto));
    }

    #[Route('', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $title = $this->parseTitle($request);
        if ($title instanceof JsonResponse) {
            return $title;
        }

        $data = json_decode($request->getContent(), true);
        $metadata = $this->parseMetadata(is_array($data) ? $data : []);
        if ($metadata instanceof JsonResponse) {
            return $metadata;
        }

        $id = $this->commandBus->dispatch(new CreateSeries(
            title: $title,
            coverUrl: $metadata['coverUrl'],
            year: $metadata['year'],
            status: $metadata['status'],
            description: $metadata['description'],
        ))->last(HandledStamp::class)->getResult();

        $this->logger->info('Series created', ['id' => $id, 'title' => $title]);

        return new JsonResponse(['id' => $id], Response::HTTP_CREATED);
    }

    /**
     * Kicks off a Trakt → AIHM import of watched shows (HMAI-184, closes the
     * HMAI-178 epic). The work is rate-limited + I/O bound so it runs async
     * (RabbitMQ) — this returns 202 Accepted immediately and never waits for
     * the import to finish. Feedback is "started", not "imported N".
     *
     * A 409 short-circuits before dispatch when no Trakt token is stored, so the
     * UI can prompt the user to connect rather than silently queueing a job the
     * worker can only fail. authUrl points at the public OAuth init route.
     */
    #[Route('/import/trakt', methods: ['POST'])]
    public function importFromTrakt(): JsonResponse
    {
        if (!$this->isTraktConnected()) {
            return new JsonResponse(
                ['error' => 'Trakt is not connected. Authorize at /auth/trakt first.', 'authUrl' => '/auth/trakt'],
                Response::HTTP_CONFLICT
            );
        }

        $this->commandBus->dispatch(new ImportWatchedShowsFromTrakt());

        $this->logger->info('Trakt watched-shows import dispatched');

        return new JsonResponse(['status' => 'import_started'], Response::HTTP_ACCEPTED);
    }

    #[Route('/{seriesId}/seasons', methods: ['POST'])]
    public function addSeason(string $seriesId, Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true) ?? [];
        $number = $data['number'] ?? null;

        if (!is_int($number) || $number < 1) {
            return new JsonResponse(['error' => 'Season number must be a positive integer.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $id = $this->commandBus->dispatch(new AddSeason($seriesId, $number))->last(HandledStamp::class)->getResult();
        } catch (HandlerFailedException $e) {
            if ($e->getPrevious() instanceof DomainException) {
                return new JsonResponse(['error' => 'Series not found.'], Response::HTTP_NOT_FOUND);
            }
            throw $e;
        }

        return new JsonResponse(['id' => $id], Response::HTTP_CREATED);
    }

    #[Route('/{seriesId}/seasons/{seasonId}/episodes', methods: ['POST'])]
    public function addEpisode(string $seriesId, string $seasonId, Request $request): JsonResponse
    {
        $title = $this->parseTitle($request);
        if ($title instanceof JsonResponse) {
            return $title;
        }

        $data = json_decode($request->getContent(), true) ?? [];
        $title = trim($data['title'] ?? '');
        $number = $data['number'] ?? null;
        $rating = isset($data['rating']) ? (int) $data['rating'] : null;

        if ('' === $title) {
            return new JsonResponse(['error' => 'Title is required.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if (mb_strlen($title) > self::MAX_TITLE_LENGTH) {
            return new JsonResponse(
                ['error' => sprintf('Title must be at most %d characters.', self::MAX_TITLE_LENGTH)],
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        if (!is_int($number) || $number < 1) {
            return new JsonResponse(['error' => 'Episode number must be a positive integer.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $id = $this->commandBus->dispatch(new AddEpisode($seriesId, $seasonId, $title, $number, $rating))
                ->last(HandledStamp::class)
                ->getResult();
        } catch (HandlerFailedException $e) {
            $original = $e->getPrevious();
            if ($original instanceof DomainException) {
                return new JsonResponse(['error' => $original->getMessage()], Response::HTTP_NOT_FOUND);
            }
            if ($original instanceof InvalidArgumentException) {
                return new JsonResponse(['error' => $original->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
            throw $e;
        }

        return new JsonResponse(['id' => $id], Response::HTTP_CREATED);
    }

    #[Route('/{seriesId}/seasons/{seasonId}/episodes/{episodeId}/rating', methods: ['PATCH'])]
    public function rateEpisode(string $seriesId, string $seasonId, string $episodeId, Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true) ?? [];
        $rating = $data['rating'] ?? null;

        // is_int + range check matches HMAI-67's pages_read contract — pre-empts
        // the Rating VO's InvalidArgumentException so we return a clean 422
        // without HandlerFailedException unwrap noise on the happy invalid path.
        if (!is_int($rating) || $rating < 1 || $rating > 10) {
            return new JsonResponse(
                ['error' => 'Field "rating" must be an integer between 1 and 10.'],
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        try {
            $this->commandBus->dispatch(new AddEpisodeRating(
                seriesId: $seriesId,
                seasonId: $seasonId,
                episodeId: $episodeId,
                rating: $rating,
            ));
        } catch (HandlerFailedException $e) {
            $original = $e->getPrevious();
            if ($original instanceof DomainException) {
                return new JsonResponse(['error' => $original->getMessage()], Response::HTTP_NOT_FOUND);
            }
            if ($original instanceof InvalidArgumentException) {
                return new JsonResponse(['error' => $original->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
            throw $e;
        }

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/{seriesId}/seasons/{seasonId}/episodes/{episodeId}/watched', methods: ['PATCH'])]
    public function setEpisodeWatched(string $seriesId, string $seasonId, string $episodeId, Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true) ?? [];
        $watched = $data['watched'] ?? null;

        if (!is_bool($watched)) {
            return new JsonResponse(
                ['error' => 'Field "watched" must be a boolean.'],
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        try {
            $this->commandBus->dispatch(new SetEpisodeWatched(
                seriesId: $seriesId,
                seasonId: $seasonId,
                episodeId: $episodeId,
                watched: $watched,
            ));
        } catch (HandlerFailedException $e) {
            $original = $e->getPrevious();
            if ($original instanceof DomainException) {
                return new JsonResponse(['error' => $original->getMessage()], Response::HTTP_NOT_FOUND);
            }
            throw $e;
        }

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/{seriesId}/rating', methods: ['PATCH'])]
    public function rateSeries(string $seriesId, Request $request): JsonResponse
    {
        $rating = $this->parseRating($request);
        if ($rating instanceof JsonResponse) {
            return $rating;
        }

        try {
            // $rating may be null here — an explicit `{"rating": null}` clears
            // the user's own score (HMAI-191).
            $this->commandBus->dispatch(new RateSeries(seriesId: $seriesId, rating: $rating));
        } catch (HandlerFailedException $e) {
            return $this->mapRatingFailure($e);
        }

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/{seriesId}/seasons/{seasonId}/rating', methods: ['PATCH'])]
    public function rateSeason(string $seriesId, string $seasonId, Request $request): JsonResponse
    {
        $rating = $this->parseRating($request);
        if ($rating instanceof JsonResponse) {
            return $rating;
        }

        try {
            // $rating may be null here — an explicit `{"rating": null}` clears
            // the season's own score (HMAI-191).
            $this->commandBus->dispatch(new RateSeason(seriesId: $seriesId, seasonId: $seasonId, rating: $rating));
        } catch (HandlerFailedException $e) {
            return $this->mapRatingFailure($e);
        }

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/{id}', methods: ['DELETE'])]
    public function deleteSeries(string $id): JsonResponse
    {
        try {
            $this->commandBus->dispatch(new DeleteSeries($id));
        } catch (HandlerFailedException $e) {
            return $this->mapDeleteFailure($e);
        }

        $this->logger->info('Series deleted', ['id' => $id]);

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/{seriesId}/seasons/{seasonId}', methods: ['DELETE'])]
    public function deleteSeason(string $seriesId, string $seasonId): JsonResponse
    {
        try {
            $this->commandBus->dispatch(new DeleteSeason($seriesId, $seasonId));
        } catch (HandlerFailedException $e) {
            return $this->mapDeleteFailure($e);
        }

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/{seriesId}/seasons/{seasonId}/episodes/{episodeId}', methods: ['DELETE'])]
    public function deleteEpisode(string $seriesId, string $seasonId, string $episodeId): JsonResponse
    {
        try {
            $this->commandBus->dispatch(new DeleteEpisode($seriesId, $seasonId, $episodeId));
        } catch (HandlerFailedException $e) {
            return $this->mapDeleteFailure($e);
        }

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    /** A missing series/season/episode surfaces as DomainException → 404. */
    private function mapDeleteFailure(HandlerFailedException $e): JsonResponse
    {
        if ($e->getPrevious() instanceof DomainException) {
            return new JsonResponse(['error' => $e->getPrevious()->getMessage()], Response::HTTP_NOT_FOUND);
        }
        throw $e;
    }

    #[Route('/{id}', methods: ['PATCH'])]
    public function renameSeries(string $id, Request $request): JsonResponse
    {
        $title = $this->parseTitle($request);
        if ($title instanceof JsonResponse) {
            return $title;
        }

        $data = json_decode($request->getContent(), true);
        $data = is_array($data) ? $data : [];

        // Validate before any dispatch so an invalid metadata field returns 422
        // without first half-applying the rename (HMAI-190).
        $metadata = $this->parseMetadata($data);
        if ($metadata instanceof JsonResponse) {
            return $metadata;
        }

        // A bare {title} (the inline title-edit) carries no metadata keys and
        // must leave the catalog fields untouched; a full edit-form save sends
        // the four keys and replaces them wholesale.
        $hasMetadata = array_key_exists('coverUrl', $data)
            || array_key_exists('year', $data)
            || array_key_exists('status', $data)
            || array_key_exists('description', $data);

        try {
            $this->commandBus->dispatch(new RenameSeries($id, $title));

            if ($hasMetadata) {
                $this->commandBus->dispatch(new UpdateSeriesMetadata(
                    seriesId: $id,
                    coverUrl: $metadata['coverUrl'],
                    year: $metadata['year'],
                    status: $metadata['status'],
                    description: $metadata['description'],
                ));
            }
        } catch (HandlerFailedException $e) {
            if ($e->getPrevious() instanceof DomainException) {
                return new JsonResponse(['error' => 'Series not found.'], Response::HTTP_NOT_FOUND);
            }
            throw $e;
        }

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/{seriesId}/seasons/{seasonId}', methods: ['PATCH'])]
    public function renumberSeason(string $seriesId, string $seasonId, Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true) ?? [];
        $number = $data['number'] ?? null;

        if (!is_int($number) || $number < 1) {
            return new JsonResponse(['error' => 'Season number must be a positive integer.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $this->commandBus->dispatch(new RenumberSeason($seriesId, $seasonId, $number));
        } catch (HandlerFailedException $e) {
            $original = $e->getPrevious();
            // SeasonNumberAlreadyTaken extends DomainException — check it first so
            // a uniqueness clash answers 409, not the generic 404.
            if ($original instanceof SeasonNumberAlreadyTaken) {
                return new JsonResponse(['error' => $original->getMessage()], Response::HTTP_CONFLICT);
            }
            if ($original instanceof DomainException) {
                return new JsonResponse(['error' => $original->getMessage()], Response::HTTP_NOT_FOUND);
            }
            throw $e;
        }

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/{seriesId}/seasons/{seasonId}/episodes/{episodeId}', methods: ['PATCH'])]
    public function renameEpisode(string $seriesId, string $seasonId, string $episodeId, Request $request): JsonResponse
    {
        $title = $this->parseTitle($request);
        if ($title instanceof JsonResponse) {
            return $title;
        }

        try {
            $this->commandBus->dispatch(new RenameEpisode($seriesId, $seasonId, $episodeId, $title));
        } catch (HandlerFailedException $e) {
            if ($e->getPrevious() instanceof DomainException) {
                return new JsonResponse(['error' => $e->getPrevious()->getMessage()], Response::HTTP_NOT_FOUND);
            }
            throw $e;
        }

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    /**
     * Validates the JSON `title` field (non-empty, ≤ MAX_TITLE_LENGTH). Returns
     * the trimmed title or a ready-to-send 422 — shared by create, add episode
     * and the rename endpoints (HMAI-186). mb_strlen counts characters not bytes,
     * so a 255-char multibyte title still fits VARCHAR(255) in utf8mb4.
     */
    private function parseTitle(Request $request): string|JsonResponse
    {
        $data = json_decode($request->getContent(), true) ?? [];
        $title = trim($data['title'] ?? '');

        if ('' === $title) {
            return new JsonResponse(['error' => 'Title is required.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if (mb_strlen($title) > self::MAX_TITLE_LENGTH) {
            return new JsonResponse(
                ['error' => sprintf('Title must be at most %d characters.', self::MAX_TITLE_LENGTH)],
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        return $title;
    }

    /**
     * Validates the optional catalog-metadata fields (HMAI-190) from an already
     * JSON-decoded body. Returns a normalized bag (coverUrl VO, year int, status
     * enum, description string — each nullable) or a ready-to-send 422. Absent
     * keys yield null; this validates only what is present, so a caller that
     * sends none gets all-null back.
     *
     * @param array<string, mixed> $data
     *
     * @return array{coverUrl: CoverUrl|null, year: int|null, status: SeriesStatus|null, description: string|null}|JsonResponse
     */
    private function parseMetadata(array $data): array|JsonResponse
    {
        try {
            $coverUrl = $this->parseCoverUrl($data['coverUrl'] ?? null);
        } catch (InvalidArgumentException $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $year = null;
        if (array_key_exists('year', $data) && null !== $data['year']) {
            $year = $data['year'];
            if (!is_int($year) || $year < self::MIN_YEAR || $year > $this->maxYear()) {
                return new JsonResponse(
                    ['error' => sprintf('Field "year" must be an integer between %d and %d.', self::MIN_YEAR, $this->maxYear())],
                    Response::HTTP_UNPROCESSABLE_ENTITY
                );
            }
        }

        $status = null;
        if (array_key_exists('status', $data) && null !== $data['status']) {
            $status = is_string($data['status']) ? SeriesStatus::tryFrom($data['status']) : null;
            if (null === $status) {
                return new JsonResponse(
                    ['error' => 'Field "status" must be one of: ongoing, ended.'],
                    Response::HTTP_UNPROCESSABLE_ENTITY
                );
            }
        }

        $description = null;
        if (array_key_exists('description', $data) && null !== $data['description']) {
            $description = trim((string) $data['description']);
            if (mb_strlen($description) > self::MAX_DESCRIPTION_LENGTH) {
                return new JsonResponse(
                    ['error' => sprintf('Description must be at most %d characters.', self::MAX_DESCRIPTION_LENGTH)],
                    Response::HTTP_UNPROCESSABLE_ENTITY
                );
            }
            $description = '' === $description ? null : $description;
        }

        return ['coverUrl' => $coverUrl, 'year' => $year, 'status' => $status, 'description' => $description];
    }

    /**
     * Builds the CoverUrl VO from a raw JSON value (HMAI-190), mirroring
     * BooksController::parseCoverUrl: null or an empty/whitespace string means
     * "no cover", anything else is validated by the VO (which throws on a bad
     * URL → caught as 422 by parseMetadata).
     */
    private function parseCoverUrl(mixed $raw): ?CoverUrl
    {
        if (null === $raw) {
            return null;
        }

        $trimmed = trim((string) $raw);

        return '' === $trimmed ? null : new CoverUrl($trimmed);
    }

    /** Upper bound for the year field — a few years ahead for not-yet-aired shows. */
    private function maxYear(): int
    {
        return (int) date('Y') + 5;
    }

    /**
     * Validates the JSON `rating` field. Returns the int (1–10) on a set,
     * `null` on an explicit `{"rating": null}` clear (HMAI-191), or a
     * ready-to-send 422 JsonResponse — pre-empting the Rating VO's
     * InvalidArgumentException so the invalid path stays free of unwrap noise.
     *
     * An explicit null is a deliberate clear; an absent key is a malformed
     * request — hence array_key_exists (isset() can't tell the two apart).
     */
    private function parseRating(Request $request): int|JsonResponse|null
    {
        $data = json_decode($request->getContent(), true) ?? [];

        if (!is_array($data) || !array_key_exists('rating', $data)) {
            return $this->invalidRatingResponse();
        }

        $rating = $data['rating'];
        if (null === $rating) {
            return null;
        }

        if (!is_int($rating) || $rating < 1 || $rating > 10) {
            return $this->invalidRatingResponse();
        }

        return $rating;
    }

    private function invalidRatingResponse(): JsonResponse
    {
        return new JsonResponse(
            ['error' => 'Field "rating" must be an integer between 1 and 10, or null to clear.'],
            Response::HTTP_UNPROCESSABLE_ENTITY
        );
    }

    private function mapRatingFailure(HandlerFailedException $e): JsonResponse
    {
        $original = $e->getPrevious();
        if ($original instanceof DomainException) {
            return new JsonResponse(['error' => $original->getMessage()], Response::HTTP_NOT_FOUND);
        }
        if ($original instanceof InvalidArgumentException) {
            return new JsonResponse(['error' => $original->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        throw $e;
    }

    /**
     * True when a Trakt OAuth token is stored. A pure DB read — the heavy import
     * (and any token refresh) happens in the async worker, so the synchronous
     * endpoint never blocks on the network here.
     */
    private function isTraktConnected(): bool
    {
        $token = $this->traktTokens->get();

        return null !== $token && isset($token['access_token']);
    }

    private function serializeDTO(SeriesDetailDTO $dto): array
    {
        $seasons = array_map(function ($season) {
            $ratedEpisodes = array_filter($season->episodes, fn ($e) => null !== $e->rating);
            $seasonAvg = count($ratedEpisodes) > 0
                ? round(array_sum(array_map(fn ($e) => $e->rating, $ratedEpisodes)) / count($ratedEpisodes), 2)
                : null;

            return [
                'id' => $season->id,
                'number' => $season->number,
                'rating' => $season->rating,
                'averageRating' => $seasonAvg,
                'watchedCount' => count(array_filter($season->episodes, fn ($e) => $e->watched)),
                'episodeCount' => count($season->episodes),
                'episodes' => array_map(fn ($e) => [
                    'id' => $e->id,
                    'title' => $e->title,
                    'number' => $e->number,
                    'rating' => $e->rating,
                    'watched' => $e->watched,
                    'watchedAt' => $e->watchedAt,
                ], $season->episodes),
            ];
        }, $dto->seasons);

        $allEpisodes = array_merge(...array_map(fn ($s) => $s->episodes, $dto->seasons));
        $allRated = array_filter($allEpisodes, fn ($e) => null !== $e->rating);
        $seriesAvg = count($allRated) > 0
            ? round(array_sum(array_map(fn ($e) => $e->rating, $allRated)) / count($allRated), 2)
            : null;

        return [
            'id' => $dto->id,
            'title' => $dto->title,
            'createdAt' => $dto->createdAt,
            'coverUrl' => $dto->coverUrl,
            'year' => $dto->year,
            'status' => $dto->status,
            'description' => $dto->description,
            'rating' => $dto->rating,
            'averageRating' => $seriesAvg,
            'watchedCount' => count(array_filter($allEpisodes, fn ($e) => $e->watched)),
            'episodeCount' => count($allEpisodes),
            'seasons' => $seasons,
        ];
    }
}
