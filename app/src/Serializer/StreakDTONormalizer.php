<?php

declare(strict_types=1);

namespace App\Serializer;

use App\Module\Goals\Application\DTO\StreakDTO;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * Normalizes a StreakDTO to its API array shape (HMAI-240) — pure field mapping.
 */
final class StreakDTONormalizer implements NormalizerInterface
{
    /**
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    public function normalize(mixed $data, ?string $format = null, array $context = []): array
    {
        \assert($data instanceof StreakDTO);

        return [
            'type' => $data->type,
            'currentLength' => $data->currentLength,
            'longestLength' => $data->longestLength,
            'lastActivityDate' => $data->lastActivityDate,
        ];
    }

    /** @param array<string, mixed> $context */
    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        return $data instanceof StreakDTO;
    }

    /** @return array<class-string, bool> */
    public function getSupportedTypes(?string $format): array
    {
        return [StreakDTO::class => true];
    }
}
