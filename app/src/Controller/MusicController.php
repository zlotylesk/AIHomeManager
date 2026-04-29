<?php

declare(strict_types=1);

namespace App\Controller;

use App\Module\Music\Application\DTO\AlbumDTO;
use App\Module\Music\Domain\Port\MusicListeningHistoryInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/music')]
final class MusicController extends AbstractController
{
    private const VALID_PERIODS = ['7day', '1month', '3month', '6month', '12month', 'overall'];

    public function __construct(
        private readonly MusicListeningHistoryInterface $listeningHistory,
        private readonly string $lastfmUsername,
    ) {}

    #[Route('/top-albums', methods: ['GET'])]
    public function topAlbums(Request $request): JsonResponse
    {
        $period = $request->query->get('period', '1month');
        $limit = max(1, min(1000, (int) $request->query->get('limit', 50)));

        if (!in_array($period, self::VALID_PERIODS, true)) {
            return new JsonResponse(
                ['error' => 'Invalid period. Allowed: ' . implode(', ', self::VALID_PERIODS)],
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        try {
            $albums = $this->listeningHistory->getTopAlbums($this->lastfmUsername, $period, $limit);
        } catch (\RuntimeException $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        return new JsonResponse(array_map(
            fn(AlbumDTO $a) => [
                'artist' => $a->artist,
                'title' => $a->title,
                'playCount' => $a->playCount,
                'imageUrl' => $a->imageUrl,
            ],
            $albums
        ));
    }
}
