<?php

declare(strict_types=1);

namespace App\Serializer;

use App\Module\Series\Application\DTO\SeriesDetailDTO;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * Normalizes a SeriesDetailDTO to its API array shape (HMAI-240) — extracted
 * verbatim from the former SeriesController::serializeDTO. The per-season /
 * per-show averageRating + watchedCount are still computed here; relocating that
 * read-model logic to a query handler is the follow-up HMAI-242.
 */
final class SeriesDetailDTONormalizer implements NormalizerInterface
{
    /**
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    public function normalize(mixed $data, ?string $format = null, array $context = []): array
    {
        \assert($data instanceof SeriesDetailDTO);
        $dto = $data;

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

    /** @param array<string, mixed> $context */
    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        return $data instanceof SeriesDetailDTO;
    }

    /** @return array<class-string, bool> */
    public function getSupportedTypes(?string $format): array
    {
        return [SeriesDetailDTO::class => true];
    }
}
