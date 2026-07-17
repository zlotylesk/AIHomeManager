<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Controller\Movies\MoviesRequestParser;
use App\Messaging\CommandBus;
use App\Messaging\QueryBus;
use App\Module\Movies\Application\Command\AddMovie;
use App\Module\Movies\Application\Command\DeleteMovie;
use App\Module\Movies\Application\Command\ImportWatchedMoviesFromTrakt;
use App\Module\Movies\Application\Command\MarkMovieWatched;
use App\Module\Movies\Application\Command\RateMovie;
use App\Module\Movies\Application\Command\UnmarkMovieWatched;
use App\Module\Movies\Application\Command\UpdateMovie;
use App\Module\Movies\Application\Command\UpdateMovieMetadata;
use App\Module\Movies\Application\DTO\MovieDTO;
use App\Module\Movies\Application\Exception\MovieNotFoundException;
use App\Module\Movies\Application\Query\GetMovieDetails;
use App\Module\Movies\Application\Query\GetMovies;
use App\Shared\Security\TraktTokenProviderInterface;
use InvalidArgumentException;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * Thin REST surface for the Movies module: reads via query.bus, writes via
 * command.bus, no domain logic (payload parsing lives in MoviesRequestParser,
 * metadata validation in the MovieMetadata Application factory). Version-agnostic
 * paths — served under /api/v1/movies and the /api/movies alias (ADR-008).
 */
#[Route('/movies')]
final class MoviesController extends AbstractController
{
    public function __construct(
        private readonly CommandBus $commandBus,
        private readonly QueryBus $queryBus,
        private readonly NormalizerInterface $normalizer,
        private readonly MoviesRequestParser $parser,
        private readonly TraktTokenProviderInterface $traktTokens,
    ) {
    }

    #[Route('', methods: ['GET'])]
    #[OA\Get(
        summary: 'List movies',
        description: 'Returns all films, optionally filtered by whether they have been watched.',
        tags: ['Movies'],
        parameters: [
            new OA\QueryParameter(
                name: 'watched',
                description: 'Filter by watched flag. Omit to return every movie.',
                required: false,
                schema: new OA\Schema(type: 'boolean'),
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'The list of movies.',
                content: new OA\JsonContent(type: 'array', items: new OA\Items(ref: new Model(type: MovieDTO::class))),
            ),
            new OA\Response(response: 401, ref: '#/components/responses/UnauthorizedError'),
            new OA\Response(response: 422, ref: '#/components/responses/UnprocessableEntityError'),
        ],
    )]
    public function list(Request $request): JsonResponse
    {
        /** @var MovieDTO[] $movies */
        $movies = $this->queryBus->ask(new GetMovies($this->parseWatchedFilter($request)));

        return new JsonResponse($this->normalizer->normalize($movies));
    }

    #[Route('/{id}', methods: ['GET'], requirements: ['id' => '[0-9a-f\-]{36}'])]
    #[OA\Get(
        summary: 'Get a movie by id',
        tags: ['Movies'],
        parameters: [
            new OA\PathParameter(name: 'id', description: 'Movie UUID.', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'The movie.', content: new OA\JsonContent(ref: new Model(type: MovieDTO::class))),
            new OA\Response(response: 401, ref: '#/components/responses/UnauthorizedError'),
            new OA\Response(response: 404, ref: '#/components/responses/NotFoundError'),
        ],
    )]
    public function detail(string $id): JsonResponse
    {
        /** @var MovieDTO|null $dto */
        $dto = $this->queryBus->ask(new GetMovieDetails($id));

        if (null === $dto) {
            return new JsonResponse(['error' => 'Movie not found.'], Response::HTTP_NOT_FOUND);
        }

        return new JsonResponse($this->normalizer->normalize($dto));
    }

    #[Route('', methods: ['POST'])]
    #[OA\Post(
        summary: 'Add a movie',
        description: 'Adds a film by title, with optional catalog metadata (cover/year/status/description).',
        tags: ['Movies'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['title'],
                properties: [
                    new OA\Property(property: 'title', type: 'string', example: 'Blade Runner 2049'),
                    new OA\Property(property: 'coverUrl', type: 'string', format: 'uri', nullable: true),
                    new OA\Property(property: 'year', type: 'integer', nullable: true, example: 2017),
                    new OA\Property(property: 'status', type: 'string', enum: ['released', 'upcoming'], nullable: true),
                    new OA\Property(property: 'description', type: 'string', nullable: true),
                ],
            ),
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Movie added.',
                content: new OA\JsonContent(required: ['id'], properties: [new OA\Property(property: 'id', type: 'string', format: 'uuid')]),
            ),
            new OA\Response(response: 401, ref: '#/components/responses/UnauthorizedError'),
            new OA\Response(response: 422, ref: '#/components/responses/UnprocessableEntityError'),
        ],
    )]
    public function create(Request $request): JsonResponse
    {
        $data = $this->parser->decode($request);
        $title = $this->parser->requireTitle($data);

        try {
            $id = $this->commandBus->dispatchAndReturn(new AddMovie(
                title: $title,
                coverUrl: $this->parser->metadataCoverUrl($data),
                year: $this->parser->metadataYear($data),
                status: $this->parser->metadataStatus($data),
                description: $this->parser->metadataDescription($data),
            ));
        } catch (HandlerFailedException $e) {
            if ($e->getPrevious() instanceof InvalidArgumentException) {
                return new JsonResponse(['error' => $e->getPrevious()->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
            throw $e;
        }

        return new JsonResponse(['id' => $id], Response::HTTP_CREATED);
    }

    #[Route('/{id}', methods: ['PATCH'], requirements: ['id' => '[0-9a-f\-]{36}'])]
    #[OA\Patch(
        summary: 'Update a movie',
        description: 'Partial-safe edit: renames when `title` is present and replaces the catalog metadata when any of coverUrl/year/status/description is present, so a bare title edit never wipes the metadata (the Series precedent).',
        tags: ['Movies'],
        parameters: [
            new OA\PathParameter(name: 'id', description: 'Movie UUID.', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'title', type: 'string'),
                    new OA\Property(property: 'coverUrl', type: 'string', format: 'uri', nullable: true),
                    new OA\Property(property: 'year', type: 'integer', nullable: true),
                    new OA\Property(property: 'status', type: 'string', enum: ['released', 'upcoming'], nullable: true),
                    new OA\Property(property: 'description', type: 'string', nullable: true),
                ],
            ),
        ),
        responses: [
            new OA\Response(response: 204, description: 'Movie updated.'),
            new OA\Response(response: 401, ref: '#/components/responses/UnauthorizedError'),
            new OA\Response(response: 404, ref: '#/components/responses/NotFoundError'),
            new OA\Response(response: 422, ref: '#/components/responses/UnprocessableEntityError'),
        ],
    )]
    public function update(string $id, Request $request): JsonResponse
    {
        $data = $this->parser->decode($request);
        $hasTitle = \array_key_exists('title', $data);
        $hasMetadata = $this->parser->hasMetadataFields($data);

        if (!$hasTitle && !$hasMetadata) {
            throw new UnprocessableEntityHttpException('At least one field (title, coverUrl, year, status, description) is required.');
        }

        $title = $hasTitle ? $this->parser->requireTitle($data) : null;

        try {
            if (null !== $title) {
                $this->commandBus->dispatch(new UpdateMovie($id, $title));
            }

            if ($hasMetadata) {
                $this->commandBus->dispatch(new UpdateMovieMetadata(
                    id: $id,
                    coverUrl: $this->parser->metadataCoverUrl($data),
                    year: $this->parser->metadataYear($data),
                    status: $this->parser->metadataStatus($data),
                    description: $this->parser->metadataDescription($data),
                ));
            }
        } catch (HandlerFailedException $e) {
            return $this->mapWriteFailure($e);
        }

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/{id}', methods: ['DELETE'], requirements: ['id' => '[0-9a-f\-]{36}'])]
    #[OA\Delete(
        summary: 'Delete a movie',
        tags: ['Movies'],
        parameters: [
            new OA\PathParameter(name: 'id', description: 'Movie UUID.', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 204, description: 'Movie deleted.'),
            new OA\Response(response: 401, ref: '#/components/responses/UnauthorizedError'),
            new OA\Response(response: 404, ref: '#/components/responses/NotFoundError'),
        ],
    )]
    public function delete(string $id): JsonResponse
    {
        try {
            $this->commandBus->dispatch(new DeleteMovie($id));
        } catch (HandlerFailedException $e) {
            if ($e->getPrevious() instanceof MovieNotFoundException) {
                return new JsonResponse(['error' => 'Movie not found.'], Response::HTTP_NOT_FOUND);
            }
            throw $e;
        }

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/{id}/watched', methods: ['PATCH'], requirements: ['id' => '[0-9a-f\-]{36}'])]
    #[OA\Patch(
        summary: 'Set a movie watched flag',
        tags: ['Movies'],
        parameters: [
            new OA\PathParameter(name: 'id', description: 'Movie UUID.', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
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
    public function setWatched(string $id, Request $request): JsonResponse
    {
        $watched = $this->parser->parseWatched($this->parser->decode($request));

        try {
            $this->commandBus->dispatch($watched ? new MarkMovieWatched($id) : new UnmarkMovieWatched($id));
        } catch (HandlerFailedException $e) {
            if ($e->getPrevious() instanceof MovieNotFoundException) {
                return new JsonResponse(['error' => 'Movie not found.'], Response::HTTP_NOT_FOUND);
            }
            throw $e;
        }

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/{id}/rating', methods: ['PATCH'], requirements: ['id' => '[0-9a-f\-]{36}'])]
    #[OA\Patch(
        summary: 'Set or clear the movie rating',
        description: 'Sets the user\'s own rating (1–10). Send `{"rating": null}` to clear it.',
        tags: ['Movies'],
        parameters: [
            new OA\PathParameter(name: 'id', description: 'Movie UUID.', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
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
    public function rate(string $id, Request $request): JsonResponse
    {
        $rating = $this->parser->parseNullableRating($this->parser->decode($request));

        try {
            $this->commandBus->dispatch(new RateMovie($id, $rating));
        } catch (HandlerFailedException $e) {
            return $this->mapWriteFailure($e);
        }

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/import/trakt', methods: ['POST'])]
    #[OA\Post(
        summary: 'Import watched movies from Trakt',
        description: 'Kicks off an async Trakt → AIHM import of watched movies and ratings. Returns 202 immediately (the import runs on the worker); 409 when no Trakt token is stored.',
        tags: ['Movies'],
        responses: [
            new OA\Response(
                response: 202,
                description: 'Import started.',
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
                Response::HTTP_CONFLICT,
            );
        }

        $this->commandBus->dispatch(new ImportWatchedMoviesFromTrakt());

        return new JsonResponse(['status' => 'import_started'], Response::HTTP_ACCEPTED);
    }

    private function parseWatchedFilter(Request $request): ?bool
    {
        $param = $request->query->get('watched');

        if (null === $param) {
            return null;
        }

        return match ($param) {
            'true', '1' => true,
            'false', '0' => false,
            default => throw new UnprocessableEntityHttpException('Query parameter "watched" must be true or false.'),
        };
    }

    private function mapWriteFailure(HandlerFailedException $e): JsonResponse
    {
        $previous = $e->getPrevious();

        if ($previous instanceof MovieNotFoundException) {
            return new JsonResponse(['error' => 'Movie not found.'], Response::HTTP_NOT_FOUND);
        }

        if ($previous instanceof InvalidArgumentException) {
            return new JsonResponse(['error' => $previous->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        throw $e;
    }

    /**
     * True when a Trakt OAuth token is stored. A pure DB read — the heavy import
     * (and any token refresh) happens in the async worker, so the synchronous
     * endpoint never blocks on the network here (the Series import precedent).
     */
    private function isTraktConnected(): bool
    {
        $token = $this->traktTokens->get();

        return null !== $token && isset($token['access_token']);
    }
}
