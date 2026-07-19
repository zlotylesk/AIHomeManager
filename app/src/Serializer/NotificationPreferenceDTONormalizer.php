<?php

declare(strict_types=1);

namespace App\Serializer;

use App\Module\Notifications\Application\DTO\NotificationPreferenceDTO;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * Normalizes a NotificationPreferenceDTO to its API array shape (HMAI-240) —
 * pure field mapping.
 */
final class NotificationPreferenceDTONormalizer implements NormalizerInterface
{
    /**
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    public function normalize(mixed $data, ?string $format = null, array $context = []): array
    {
        \assert($data instanceof NotificationPreferenceDTO);

        return [
            'type' => $data->type,
            'enabled' => $data->enabled,
            'channels' => $data->channels,
            'quietFrom' => $data->quietFrom,
            'quietTo' => $data->quietTo,
        ];
    }

    /** @param array<string, mixed> $context */
    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        return $data instanceof NotificationPreferenceDTO;
    }

    /** @return array<class-string, bool> */
    public function getSupportedTypes(?string $format): array
    {
        return [NotificationPreferenceDTO::class => true];
    }
}
