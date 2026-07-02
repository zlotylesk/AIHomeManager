<?php

declare(strict_types=1);

namespace App\Serializer;

use App\Module\Music\Domain\ReadModel\Album;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * Normalizes an Album read model to its API array shape (HMAI-240) — extracted
 * verbatim from the former MusicController::serializeAlbum.
 */
final class AlbumDTONormalizer implements NormalizerInterface
{
    /**
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    public function normalize(mixed $data, ?string $format = null, array $context = []): array
    {
        \assert($data instanceof Album);

        return [
            'artist' => $data->artist,
            'title' => $data->title,
            'playCount' => $data->playCount,
            'imageUrl' => $data->imageUrl,
        ];
    }

    /** @param array<string, mixed> $context */
    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        return $data instanceof Album;
    }

    /** @return array<class-string, bool> */
    public function getSupportedTypes(?string $format): array
    {
        return [Album::class => true];
    }
}
