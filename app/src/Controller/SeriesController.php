<?php

declare(strict_types=1);

namespace App\Controller;

use App\Module\Series\Application\Command\AddEpisode;
use App\Module\Series\Application\Command\AddSeason;
use App\Module\Series\Application\Command\CreateSeries;
use App\Module\Series\Application\DTO\SeriesDetailDTO;
use App\Module\Series\Application\Query\GetAllSeries;
use App\Module\Series\Application\Query\GetSeriesDetail;
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
    public function __construct(
        private readonly MessageBusInterface $commandBus,
        #[Target('query.bus')] private readonly MessageBusInterface $queryBus,
        #[Target('series')] private readonly LoggerInterface $logger,
    ) {}

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

        if ($dto === null) {
            return new JsonResponse(['error' => 'Series not found.'], Response::HTTP_NOT_FOUND);
        }

        return new JsonResponse($this->serializeDTO($dto));
    }

    #[Route('', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true) ?? [];
        $title = trim($data['title'] ?? '');

        if ($title === '') {
            $this->logger->warning('POST /api/series failed: title is empty');

            return new JsonResponse(['error' => 'Title is required.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $id = $this->commandBus->dispatch(new CreateSeries($title))->last(HandledStamp::class)->getResult();

        $this->logger->info('Series created', ['id' => $id, 'title' => $title]);

        return new JsonResponse(['id' => $id], Response::HTTP_CREATED);
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
            if ($e->getPrevious() instanceof \DomainException) {
                return new JsonResponse(['error' => 'Series not found.'], Response::HTTP_NOT_FOUND);
            }
            throw $e;
        }

        return new JsonResponse(['id' => $id], Response::HTTP_CREATED);
    }

    #[Route('/{seriesId}/seasons/{seasonId}/episodes', methods: ['POST'])]
    public function addEpisode(string $seriesId, string $seasonId, Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true) ?? [];
        $title = trim($data['title'] ?? '');
        $rating = isset($data['rating']) ? (int) $data['rating'] : null;

        if ($title === '') {
            return new JsonResponse(['error' => 'Title is required.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $id = $this->commandBus->dispatch(new AddEpisode($seriesId, $seasonId, $title, $rating))
                ->last(HandledStamp::class)
                ->getResult();
        } catch (HandlerFailedException $e) {
            $original = $e->getPrevious();
            if ($original instanceof \DomainException) {
                return new JsonResponse(['error' => $original->getMessage()], Response::HTTP_NOT_FOUND);
            }
            if ($original instanceof \InvalidArgumentException) {
                return new JsonResponse(['error' => $original->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
            throw $e;
        }

        return new JsonResponse(['id' => $id], Response::HTTP_CREATED);
    }

    private function serializeDTO(SeriesDetailDTO $dto): array
    {
        $seasons = array_map(function ($season) {
            $ratedEpisodes = array_filter($season->episodes, fn($e) => $e->rating !== null);
            $seasonAvg = count($ratedEpisodes) > 0
                ? round(array_sum(array_map(fn($e) => $e->rating, $ratedEpisodes)) / count($ratedEpisodes), 2)
                : null;

            return [
                'id' => $season->id,
                'number' => $season->number,
                'averageRating' => $seasonAvg,
                'episodes' => array_map(fn($e) => [
                    'id' => $e->id,
                    'title' => $e->title,
                    'rating' => $e->rating,
                ], $season->episodes),
            ];
        }, $dto->seasons);

        $allRated = array_merge(...array_map(
            fn($s) => array_filter($s->episodes, fn($e) => $e->rating !== null),
            $dto->seasons
        ));
        $seriesAvg = count($allRated) > 0
            ? round(array_sum(array_map(fn($e) => $e->rating, $allRated)) / count($allRated), 2)
            : null;

        return [
            'id' => $dto->id,
            'title' => $dto->title,
            'createdAt' => $dto->createdAt,
            'averageRating' => $seriesAvg,
            'seasons' => $seasons,
        ];
    }
}
