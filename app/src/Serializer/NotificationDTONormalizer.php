<?php

declare(strict_types=1);

namespace App\Serializer;

use App\Module\Notifications\Application\DTO\NotificationDTO;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * Normalizes a NotificationDTO to its API array shape (HMAI-240) — pure field
 * mapping; the datetimes are already ISO-8601 from the read layer.
 */
final class NotificationDTONormalizer implements NormalizerInterface
{
    /**
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    public function normalize(mixed $data, ?string $format = null, array $context = []): array
    {
        \assert($data instanceof NotificationDTO);

        return [
            'id' => $data->id,
            'type' => $data->type,
            'channel' => $data->channel,
            'status' => $data->status,
            'payload' => $data->payload,
            'createdAt' => $data->createdAt,
            'sentAt' => $data->sentAt,
            'failureReason' => $data->failureReason,
        ];
    }

    /** @param array<string, mixed> $context */
    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        return $data instanceof NotificationDTO;
    }

    /** @return array<class-string, bool> */
    public function getSupportedTypes(?string $format): array
    {
        return [NotificationDTO::class => true];
    }
}
