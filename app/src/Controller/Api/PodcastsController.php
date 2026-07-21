<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Messaging\CommandBus;
use App\Messaging\QueryBus;
use App\Module\Podcasts\Application\Command\PollPodcastListens;
use App\Module\Podcasts\Application\DTO\PodcastDetailDTO;
use App\Module\Podcasts\Application\DTO\PodcastDTO;
use App\Module\Podcasts\Application\DTO\PodcastEpisodeDTO;
use App\Module\Podcasts\Application\DTO\PodcastListeningSessionDTO;
use App\Module\Podcasts\Application\Query\GetAllPodcasts;
use App\Module\Podcasts\Application\Query\GetPodcastDetail;
use App\Module\Podcasts\Infrastructure\Persistence\SpotifyTokenRepositoryInterface;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * Thin REST surface for the Podcasts module: reads via query.bus (DBAL), the
 * sync trigger via command.bus, no domain logic. Version-agnostic paths — served
 * under /api/v1/podcasts and the /api/podcasts alias (ADR-008).
 */
#[Route('/podcasts')]
final class PodcastsController extends AbstractController
{
    public function __construct(
        private readonly CommandBus $commandBus,
        private readonly QueryBus $queryBus,
        private readonly NormalizerInterface $normalizer,
        private readonly SpotifyTokenRepositoryInterface $spotifyTokens,
    ) {
    }

    #[Route('', methods: ['GET'])]
    #[OA\Get(
        summary: 'List followed podcasts',
        description: 'Every show in the local catalog, with its episode and listening counters. Ordered by the most recent listening first, shows never listened to last.',
        tags: ['Podcasts'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'The followed shows.',
                content: new OA\JsonContent(type: 'array', items: new OA\Items(ref: new Model(type: PodcastDTO::class))),
            ),
            new OA\Response(response: 401, ref: '#/components/responses/UnauthorizedError'),
        ],
    )]
    public function list(): JsonResponse
    {
        /** @var list<PodcastDTO> $dtos */
        $dtos = $this->queryBus->ask(new GetAllPodcasts());

        return new JsonResponse($this->normalizer->normalize($dtos));
    }

    #[Route('/{id}', methods: ['GET'])]
    #[OA\Get(
        summary: 'Podcast detail with episodes and listening history',
        description: "The show's fields flattened to the top level, plus its episodes (each carrying the furthest progress ever recorded) and every listening session, newest first.",
        tags: ['Podcasts'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [
            // allOf, not a $ref to PodcastDetailDTO: the normalizer FLATTENS the
            // show's fields to the top level and appends the two collections, so
            // the DTO's own `podcast` property never appears in the JSON (the
            // BookDetailDTO precedent — the runtime conformance gate catches the
            // difference).
            new OA\Response(
                response: 200,
                description: 'The show with its episodes and listening history.',
                content: new OA\JsonContent(allOf: [
                    new OA\Schema(ref: new Model(type: PodcastDTO::class)),
                    new OA\Schema(properties: [
                        new OA\Property(
                            property: 'episodes',
                            type: 'array',
                            items: new OA\Items(ref: new Model(type: PodcastEpisodeDTO::class)),
                        ),
                        new OA\Property(
                            property: 'sessions',
                            type: 'array',
                            items: new OA\Items(ref: new Model(type: PodcastListeningSessionDTO::class)),
                        ),
                    ]),
                ]),
            ),
            new OA\Response(response: 404, ref: '#/components/responses/NotFoundError'),
            new OA\Response(response: 401, ref: '#/components/responses/UnauthorizedError'),
        ],
    )]
    public function detail(string $id): JsonResponse
    {
        /** @var PodcastDetailDTO|null $dto */
        $dto = $this->queryBus->ask(new GetPodcastDetail($id));

        if (null === $dto) {
            return new JsonResponse(['error' => 'Podcast not found.'], Response::HTTP_NOT_FOUND);
        }

        return new JsonResponse($this->normalizer->normalize($dto));
    }

    #[Route('/sync', methods: ['POST'])]
    #[OA\Post(
        summary: 'Sync podcast listening from Spotify',
        description: 'Kicks off the async Spotify sweep that records listening. Returns 202 immediately (it runs on the worker); 409 when Spotify is not connected.',
        tags: ['Podcasts'],
        responses: [
            new OA\Response(
                response: 202,
                description: 'Sync started.',
                content: new OA\JsonContent(properties: [new OA\Property(property: 'status', type: 'string', enum: ['sync_started'])]),
            ),
            new OA\Response(
                response: 409,
                description: 'Spotify is not connected — authorize at /auth/spotify first.',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'error', type: 'string'),
                    new OA\Property(property: 'authUrl', type: 'string', example: '/auth/spotify'),
                ]),
            ),
            new OA\Response(response: 401, ref: '#/components/responses/UnauthorizedError'),
        ],
    )]
    public function sync(): JsonResponse
    {
        // Reads the stored token, not the network — the same cheap connectivity
        // check the Trakt import does. A refresh, if one is due, happens on the
        // worker where the sweep actually runs.
        if (null === $this->spotifyTokens->get()) {
            return new JsonResponse(
                ['error' => 'Spotify is not connected. Authorize at /auth/spotify first.', 'authUrl' => '/auth/spotify'],
                Response::HTTP_CONFLICT,
            );
        }

        $this->commandBus->dispatch(new PollPodcastListens());

        return new JsonResponse(['status' => 'sync_started'], Response::HTTP_ACCEPTED);
    }
}
