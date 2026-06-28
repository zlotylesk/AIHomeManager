<?php

declare(strict_types=1);

namespace App\Serializer;

use App\Module\Music\Application\DTO\ListeningSessionDTO;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * Normalizes a ListeningSessionDTO to its API array shape (HMAI-240) —
 * extracted verbatim from the former MusicController::serializeSession.
 */
final class ListeningSessionDTONormalizer implements NormalizerInterface
{
    /**
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    public function normalize(mixed $data, ?string $format = null, array $context = []): array
    {
        \assert($data instanceof ListeningSessionDTO);

        return [
            'id' => $data->id,
            'artist' => $data->artist,
            'title' => $data->title,
            'playedAt' => $data->playedAt,
            'source' => $data->source,
            'playCount' => $data->playCount,
        ];
    }

    /** @param array<string, mixed> $context */
    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        return $data instanceof ListeningSessionDTO;
    }

    /** @return array<class-string, bool> */
    public function getSupportedTypes(?string $format): array
    {
        return [ListeningSessionDTO::class => true];
    }
}
