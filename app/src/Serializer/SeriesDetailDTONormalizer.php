<?php

declare(strict_types=1);

namespace App\Serializer;

use App\Module\Series\Application\DTO\SeriesDetailDTO;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * Normalizes a SeriesDetailDTO to its API array shape (HMAI-240). Pure field
 * mapping with no logic: the read-model fields (per-season / per-show
 * averageRating + watchedCount + episodeCount) are computed upstream in the read
 * layer (SeriesRowHydrator) and merely copied here (HMAI-242).
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

        $seasons = array_map(static fn ($season) => [
            'id' => $season->id,
            'number' => $season->number,
            'rating' => $season->rating,
            'averageRating' => $season->averageRating,
            'watchedCount' => $season->watchedCount,
            'episodeCount' => $season->episodeCount,
            'episodes' => array_map(static fn ($e) => [
                'id' => $e->id,
                'title' => $e->title,
                'number' => $e->number,
                'rating' => $e->rating,
                'watched' => $e->watched,
                'watchedAt' => $e->watchedAt,
            ], $season->episodes),
        ], $dto->seasons);

        return [
            'id' => $dto->id,
            'title' => $dto->title,
            'createdAt' => $dto->createdAt,
            'coverUrl' => $dto->coverUrl,
            'year' => $dto->year,
            'status' => $dto->status,
            'description' => $dto->description,
            'rating' => $dto->rating,
            'averageRating' => $dto->averageRating,
            'watchedCount' => $dto->watchedCount,
            'episodeCount' => $dto->episodeCount,
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
