<?php

declare(strict_types=1);

namespace App\Serializer;

use App\Module\Music\Application\DTO\AlbumDTO;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * Normalizes an AlbumDTO to its API array shape (HMAI-240) — extracted verbatim
 * from the former MusicController::serializeAlbum.
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
        \assert($data instanceof AlbumDTO);

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
        return $data instanceof AlbumDTO;
    }

    /** @return array<class-string, bool> */
    public function getSupportedTypes(?string $format): array
    {
        return [AlbumDTO::class => true];
    }
}
