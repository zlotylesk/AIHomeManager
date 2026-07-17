<?php

declare(strict_types=1);

namespace App\Serializer;

use App\Module\Movies\Application\DTO\MovieDTO;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * Normalizes a MovieDTO to its API array shape (HMAI-240). Pure field mapping —
 * the read layer already resolves datetimes to ISO-8601 and the nullable
 * metadata; the normalizer only maps to camelCase keys.
 */
final class MovieDTONormalizer implements NormalizerInterface
{
    /**
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    public function normalize(mixed $data, ?string $format = null, array $context = []): array
    {
        \assert($data instanceof MovieDTO);

        return [
            'id' => $data->id,
            'title' => $data->title,
            'watched' => $data->watched,
            'watchedAt' => $data->watchedAt,
            'rating' => $data->rating,
            'coverUrl' => $data->coverUrl,
            'year' => $data->year,
            'status' => $data->status,
            'description' => $data->description,
            'createdAt' => $data->createdAt,
        ];
    }

    /** @param array<string, mixed> $context */
    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        return $data instanceof MovieDTO;
    }

    /** @return array<class-string, bool> */
    public function getSupportedTypes(?string $format): array
    {
        return [MovieDTO::class => true];
    }
}
