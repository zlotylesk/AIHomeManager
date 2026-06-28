<?php

declare(strict_types=1);

namespace App\Serializer;

use App\Module\Music\Application\DTO\VinylRecordDTO;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * Normalizes a VinylRecordDTO to its API array shape (HMAI-240) — extracted
 * verbatim from the former MusicController::serializeRecord.
 */
final class VinylRecordDTONormalizer implements NormalizerInterface
{
    /**
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    public function normalize(mixed $data, ?string $format = null, array $context = []): array
    {
        \assert($data instanceof VinylRecordDTO);

        return [
            'artist' => $data->artist,
            'title' => $data->title,
            'year' => $data->year,
            'format' => $data->format,
            'discogsId' => $data->discogsId,
        ];
    }

    /** @param array<string, mixed> $context */
    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        return $data instanceof VinylRecordDTO;
    }

    /** @return array<class-string, bool> */
    public function getSupportedTypes(?string $format): array
    {
        return [VinylRecordDTO::class => true];
    }
}
