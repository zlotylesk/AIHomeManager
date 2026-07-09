<?php

declare(strict_types=1);

namespace App\Controller\Api;

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
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

#[Route('/series')]
final class SeriesController extends AbstractController
{
    public function __construct(
        private readonly CommandBus $commandBus,
        private readonly QueryBus $queryBus,
        #[Target('series')]
        private readonly LoggerInterface $logger,
        private readonly TraktTokenRepositoryInterface $traktTokens,
        private readonly SeriesRequestParser $parser,
        private readonly NormalizerInterface $normalizer,
    ) {
    }

    #[Route('', methods: ['GET'])]
    #[OA\Get(
        summary: 'List all series',
        description: 'Returns every series with its seasons and episodes, own and computed average ratings, and watched counters.',
        tags: ['Series'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'The list of series.',
                content: new OA\JsonContent(
                    type: 'array',
                    items: new OA\Items(ref: new Model(type: SeriesDetailDTO::class)),
                ),
            ),
            new OA\Response(response: 401, ref: '#/components/responses/UnauthorizedError'),
        ],
    )]
    public function list(): JsonResponse
    {
        $this->logger->debug('GET /api/series requested');

        /** @var SeriesDetailDTO[] $series */
        $series = $this->queryBus->ask(new GetAllSeries());

        $this->logger->debug('GET /api/series returned {count} series', ['count' => count($series)]);

        return new JsonResponse($this->normalizer->normalize($series));
    }

    #[Route('/{id}', methods: ['GET'])]
    #[OA\Get(
        summary: 'Get a series by id',
        description: 'Returns the full series aggregate: seasons, episodes, own `rating` and computed `averageRating` (disjoint), plus `watchedCount`/`episodeCount` at the show and season level.',
        tags: ['Series'],
        parameters: [
            new OA\PathParameter(name: 'id', description: 'Series UUID.', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'The series.',
                content: new OA\JsonContent(ref: new Model(type: SeriesDetailDTO::class)),
            ),
            new OA\Response(response: 401, ref: '#/components/responses/UnauthorizedError'),
            new OA\Response(response: 404, ref: '#/components/responses/NotFoundError'),
        ],
    )]
    public function detail(string $id): JsonResponse
    {
        /** @var SeriesDetailDTO|null $dto */
        $dto = $this->queryBus->ask(new GetSeriesDetail($id));

        if (null === $dto) {
            return new JsonResponse(['error' => 'Series not found.'], Response::HTTP_NOT_FOUND);
        }

        return new JsonResponse($this->normalizer->normalize($dto));
    }

    #[Route('', methods: ['POST'])]
    #[OA\Post(
        summary: 'Create a series',
        description: 'Creates a series with an optional catalog-metadata block (cover, year, status, description).',
        tags: ['Series'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['title'],
                properties: [
                    new OA\Property(property: 'title', type: 'string', maxLength: 255, example: 'Breaking Bad'),
                    new OA\Property(property: 'coverUrl', type: 'string', format: 'uri', nullable: true, example: 'https://example.com/cover.jpg'),
                    new OA\Property(property: 'year', type: 'integer', nullable: true, example: 2008),
                    new OA\Property(property: 'status', type: 'string', enum: ['ongoing', 'ended'], nullable: true),
                    new OA\Property(property: 'description', type: 'string', maxLength: 2000, nullable: true),
                ],
            ),
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Series created.',
                content: new OA\JsonContent(required: ['id'], properties: [new OA\Property(property: 'id', type: 'string', format: 'uuid')]),
            ),
            new OA\Response(response: 401, ref: '#/components/responses/UnauthorizedError'),
            new OA\Response(response: 422, ref: '#/components/responses/UnprocessableEntityError'),
        ],
    )]
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
    #[OA\Post(
        summary: 'Import watched shows from Trakt',
        description: 'Kicks off an async Trakt → AIHM import of watched shows and ratings. Returns 202 immediately (the import runs on the worker); 409 when no Trakt token is stored.',
        tags: ['Series'],
        responses: [
            new OA\Response(
                response: 202,
                description: 'Import started (async).',
                content: new OA\JsonContent(properties: [new OA\Property(property: 'status', type: 'string', enum: ['import_started'])]),
            ),
            new OA\Response(
                response: 409,
                description: 'Trakt is not connected — authorize at /auth/trakt first.',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'error', type: 'string'),
                    new OA\Property(property: 'authUrl', type: 'string', example: '/auth/trakt'),
                ]),
            ),
            new OA\Response(response: 401, ref: '#/components/responses/UnauthorizedError'),
        ],
    )]
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
    #[OA\Post(
        summary: 'Add a season',
        tags: ['Series'],
        parameters: [
            new OA\PathParameter(name: 'seriesId', description: 'Series UUID.', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(required: ['number'], properties: [new OA\Property(property: 'number', type: 'integer', minimum: 1, example: 1)]),
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Season created.',
                content: new OA\JsonContent(required: ['id'], properties: [new OA\Property(property: 'id', type: 'string', format: 'uuid')]),
            ),
            new OA\Response(response: 401, ref: '#/components/responses/UnauthorizedError'),
            new OA\Response(response: 404, ref: '#/components/responses/NotFoundError'),
            new OA\Response(response: 422, ref: '#/components/responses/UnprocessableEntityError'),
        ],
    )]
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
    #[OA\Post(
        summary: 'Add an episode',
        description: 'Adds an episode with a season-unique number and an optional initial rating.',
        tags: ['Series'],
        parameters: [
            new OA\PathParameter(name: 'seriesId', description: 'Series UUID.', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\PathParameter(name: 'seasonId', description: 'Season UUID.', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['number', 'title'],
                properties: [
                    new OA\Property(property: 'number', type: 'integer', minimum: 1, example: 1),
                    new OA\Property(property: 'title', type: 'string', maxLength: 255, example: 'Pilot'),
                    new OA\Property(property: 'rating', type: 'integer', minimum: 1, maximum: 10, nullable: true),
                ],
            ),
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Episode created.',
                content: new OA\JsonContent(required: ['id'], properties: [new OA\Property(property: 'id', type: 'string', format: 'uuid')]),
            ),
            new OA\Response(response: 401, ref: '#/components/responses/UnauthorizedError'),
            new OA\Response(response: 404, ref: '#/components/responses/NotFoundError'),
            new OA\Response(response: 422, ref: '#/components/responses/UnprocessableEntityError'),
        ],
    )]
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
    #[OA\Patch(
        summary: 'Rate an episode',
        tags: ['Series'],
        parameters: [
            new OA\PathParameter(name: 'seriesId', description: 'Series UUID.', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\PathParameter(name: 'seasonId', description: 'Season UUID.', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\PathParameter(name: 'episodeId', description: 'Episode UUID.', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(required: ['rating'], properties: [new OA\Property(property: 'rating', type: 'integer', minimum: 1, maximum: 10, example: 8)]),
        ),
        responses: [
            new OA\Response(response: 204, description: 'Rating set.'),
            new OA\Response(response: 401, ref: '#/components/responses/UnauthorizedError'),
            new OA\Response(response: 404, ref: '#/components/responses/NotFoundError'),
            new OA\Response(response: 422, ref: '#/components/responses/UnprocessableEntityError'),
        ],
    )]
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
    #[OA\Patch(
        summary: 'Set an episode watched flag',
        tags: ['Series'],
        parameters: [
            new OA\PathParameter(name: 'seriesId', description: 'Series UUID.', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\PathParameter(name: 'seasonId', description: 'Season UUID.', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\PathParameter(name: 'episodeId', description: 'Episode UUID.', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(required: ['watched'], properties: [new OA\Property(property: 'watched', type: 'boolean')]),
        ),
        responses: [
            new OA\Response(response: 204, description: 'Watched flag updated.'),
            new OA\Response(response: 401, ref: '#/components/responses/UnauthorizedError'),
            new OA\Response(response: 404, ref: '#/components/responses/NotFoundError'),
            new OA\Response(response: 422, ref: '#/components/responses/UnprocessableEntityError'),
        ],
    )]
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
    #[OA\Patch(
        summary: 'Set or clear the series own rating',
        description: 'Sets the series own rating (1–10). Send `{"rating": null}` to clear it — disjoint from the computed `averageRating`.',
        tags: ['Series'],
        parameters: [
            new OA\PathParameter(name: 'seriesId', description: 'Series UUID.', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(required: ['rating'], properties: [new OA\Property(property: 'rating', type: 'integer', minimum: 1, maximum: 10, nullable: true)]),
        ),
        responses: [
            new OA\Response(response: 204, description: 'Rating set or cleared.'),
            new OA\Response(response: 401, ref: '#/components/responses/UnauthorizedError'),
            new OA\Response(response: 404, ref: '#/components/responses/NotFoundError'),
            new OA\Response(response: 422, ref: '#/components/responses/UnprocessableEntityError'),
        ],
    )]
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
    #[OA\Patch(
        summary: 'Set or clear a season own rating',
        description: 'Sets a season own rating (1–10). Send `{"rating": null}` to clear it.',
        tags: ['Series'],
        parameters: [
            new OA\PathParameter(name: 'seriesId', description: 'Series UUID.', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\PathParameter(name: 'seasonId', description: 'Season UUID.', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(required: ['rating'], properties: [new OA\Property(property: 'rating', type: 'integer', minimum: 1, maximum: 10, nullable: true)]),
        ),
        responses: [
            new OA\Response(response: 204, description: 'Rating set or cleared.'),
            new OA\Response(response: 401, ref: '#/components/responses/UnauthorizedError'),
            new OA\Response(response: 404, ref: '#/components/responses/NotFoundError'),
            new OA\Response(response: 422, ref: '#/components/responses/UnprocessableEntityError'),
        ],
    )]
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
    #[OA\Delete(
        summary: 'Delete a series (cascades seasons + episodes)',
        tags: ['Series'],
        parameters: [
            new OA\PathParameter(name: 'id', description: 'Series UUID.', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 204, description: 'Series deleted.'),
            new OA\Response(response: 401, ref: '#/components/responses/UnauthorizedError'),
            new OA\Response(response: 404, ref: '#/components/responses/NotFoundError'),
        ],
    )]
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
    #[OA\Delete(
        summary: 'Delete a season (cascades episodes)',
        tags: ['Series'],
        parameters: [
            new OA\PathParameter(name: 'seriesId', description: 'Series UUID.', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\PathParameter(name: 'seasonId', description: 'Season UUID.', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 204, description: 'Season deleted.'),
            new OA\Response(response: 401, ref: '#/components/responses/UnauthorizedError'),
            new OA\Response(response: 404, ref: '#/components/responses/NotFoundError'),
        ],
    )]
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
    #[OA\Delete(
        summary: 'Delete an episode',
        tags: ['Series'],
        parameters: [
            new OA\PathParameter(name: 'seriesId', description: 'Series UUID.', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\PathParameter(name: 'seasonId', description: 'Season UUID.', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\PathParameter(name: 'episodeId', description: 'Episode UUID.', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 204, description: 'Episode deleted.'),
            new OA\Response(response: 401, ref: '#/components/responses/UnauthorizedError'),
            new OA\Response(response: 404, ref: '#/components/responses/NotFoundError'),
        ],
    )]
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
    #[OA\Patch(
        summary: 'Rename a series and/or update its metadata',
        description: 'Renames the series (title) and, when any metadata key is present, updates the catalog metadata. Partial-safe: a bare `{title}` does not clear the metadata.',
        tags: ['Series'],
        parameters: [
            new OA\PathParameter(name: 'id', description: 'Series UUID.', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['title'],
                properties: [
                    new OA\Property(property: 'title', type: 'string', maxLength: 255, example: 'Breaking Bad'),
                    new OA\Property(property: 'coverUrl', type: 'string', format: 'uri', nullable: true),
                    new OA\Property(property: 'year', type: 'integer', nullable: true),
                    new OA\Property(property: 'status', type: 'string', enum: ['ongoing', 'ended'], nullable: true),
                    new OA\Property(property: 'description', type: 'string', maxLength: 2000, nullable: true),
                ],
            ),
        ),
        responses: [
            new OA\Response(response: 204, description: 'Series updated.'),
            new OA\Response(response: 401, ref: '#/components/responses/UnauthorizedError'),
            new OA\Response(response: 404, ref: '#/components/responses/NotFoundError'),
            new OA\Response(response: 422, ref: '#/components/responses/UnprocessableEntityError'),
        ],
    )]
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
    #[OA\Patch(
        summary: 'Renumber a season',
        description: 'Changes a season number. Renumbering to a number already used in the show returns 409 Conflict.',
        tags: ['Series'],
        parameters: [
            new OA\PathParameter(name: 'seriesId', description: 'Series UUID.', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\PathParameter(name: 'seasonId', description: 'Season UUID.', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(required: ['number'], properties: [new OA\Property(property: 'number', type: 'integer', minimum: 1, example: 2)]),
        ),
        responses: [
            new OA\Response(response: 204, description: 'Season renumbered.'),
            new OA\Response(response: 401, ref: '#/components/responses/UnauthorizedError'),
            new OA\Response(response: 404, ref: '#/components/responses/NotFoundError'),
            new OA\Response(response: 409, ref: '#/components/responses/ConflictError'),
            new OA\Response(response: 422, ref: '#/components/responses/UnprocessableEntityError'),
        ],
    )]
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
    #[OA\Patch(
        summary: 'Rename an episode',
        tags: ['Series'],
        parameters: [
            new OA\PathParameter(name: 'seriesId', description: 'Series UUID.', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\PathParameter(name: 'seasonId', description: 'Season UUID.', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\PathParameter(name: 'episodeId', description: 'Episode UUID.', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(required: ['title'], properties: [new OA\Property(property: 'title', type: 'string', maxLength: 255, example: 'Pilot')]),
        ),
        responses: [
            new OA\Response(response: 204, description: 'Episode renamed.'),
            new OA\Response(response: 401, ref: '#/components/responses/UnauthorizedError'),
            new OA\Response(response: 404, ref: '#/components/responses/NotFoundError'),
            new OA\Response(response: 422, ref: '#/components/responses/UnprocessableEntityError'),
        ],
    )]
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
}
