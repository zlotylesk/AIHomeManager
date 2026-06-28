<?php

declare(strict_types=1);

namespace App\Controller;

use App\Controller\Series\SeriesRequestParser;
use App\Messaging\CommandBus;
use App\Messaging\QueryBus;
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
use App\Module\Series\Domain\Exception\SeasonNumberAlreadyTaken;
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
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/series')]
final class SeriesController extends AbstractController
{
    public function __construct(
        private readonly CommandBus $commandBus,
        private readonly QueryBus $queryBus,
        #[Target('series')]
        private readonly LoggerInterface $logger,
        private readonly TraktTokenRepositoryInterface $traktTokens,
        private readonly SeriesRequestParser $parser,
    ) {
    }

    #[Route('', methods: ['GET'])]
    public function list(): JsonResponse
    {
        $this->logger->debug('GET /api/series requested');

        /** @var SeriesDetailDTO[] $series */
        $series = $this->queryBus->ask(new GetAllSeries());

        $this->logger->debug('GET /api/series returned {count} series', ['count' => count($series)]);

        return new JsonResponse(array_map($this->serializeDTO(...), $series));
    }

    #[Route('/{id}', methods: ['GET'])]
    public function detail(string $id): JsonResponse
    {
        /** @var SeriesDetailDTO|null $dto */
        $dto = $this->queryBus->ask(new GetSeriesDetail($id));

        if (null === $dto) {
            return new JsonResponse(['error' => 'Series not found.'], Response::HTTP_NOT_FOUND);
        }

        return new JsonResponse($this->serializeDTO($dto));
    }

    #[Route('', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $data = $this->parser->decode($request);
        $title = $this->parser->parseTitle($data);
        $metadata = $this->parser->parseMetadata($data);

        $id = $this->commandBus->dispatchAndReturn(new CreateSeries(
            title: $title,
            coverUrl: $metadata->coverUrl,
            year: $metadata->year,
            status: $metadata->status,
            description: $metadata->description,
        ));

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
        $number = $this->parser->parseSeasonNumber($this->parser->decode($request));

        try {
            $id = $this->commandBus->dispatchAndReturn(new AddSeason($seriesId, $number));
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
        $data = $this->parser->decode($request);
        $title = $this->parser->parseTitle($data);
        $number = $this->parser->parseEpisodeNumber($data);
        $rating = $this->parser->parseOptionalEpisodeRating($data);

        try {
            $id = $this->commandBus->dispatchAndReturn(new AddEpisode($seriesId, $seasonId, $title, $number, $rating));
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
        $rating = $this->parser->parseRequiredRating($this->parser->decode($request));

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
        $watched = $this->parser->parseWatched($this->parser->decode($request));

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
        $rating = $this->parser->parseNullableRating($this->parser->decode($request));

        try {
            $this->commandBus->dispatch(new RateSeries(seriesId: $seriesId, rating: $rating));
        } catch (HandlerFailedException $e) {
            return $this->mapRatingFailure($e);
        }

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/{seriesId}/seasons/{seasonId}/rating', methods: ['PATCH'])]
    public function rateSeason(string $seriesId, string $seasonId, Request $request): JsonResponse
    {
        $rating = $this->parser->parseNullableRating($this->parser->decode($request));

        try {
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
        $data = $this->parser->decode($request);
        $title = $this->parser->parseTitle($data);
        $metadata = $this->parser->parseMetadata($data);

        try {
            $this->commandBus->dispatch(new RenameSeries($id, $title));

            if ($metadata->hasAnyField) {
                $this->commandBus->dispatch(new UpdateSeriesMetadata(
                    seriesId: $id,
                    coverUrl: $metadata->coverUrl,
                    year: $metadata->year,
                    status: $metadata->status,
                    description: $metadata->description,
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
        $number = $this->parser->parseSeasonNumber($this->parser->decode($request));

        try {
            $this->commandBus->dispatch(new RenumberSeason($seriesId, $seasonId, $number));
        } catch (HandlerFailedException $e) {
            $original = $e->getPrevious();

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
        $title = $this->parser->parseTitle($this->parser->decode($request));

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
