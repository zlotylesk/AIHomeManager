<?php

declare(strict_types=1);

namespace App\Serializer;

use App\Module\YouTubeProgress\Application\DTO\VideoDTO;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * Normalizes a VideoDTO to its API array shape (HMAI-240) — extracted verbatim
 * from the former YouTubeProgressController::serializeVideo. Reused by the
 * WatchSessionDTO normalizer for the embedded videos.
 */
final class VideoDTONormalizer implements NormalizerInterface
{
    /**
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    public function normalize(mixed $data, ?string $format = null, array $context = []): array
    {
        \assert($data instanceof VideoDTO);

        return [
            'youtubeId' => $data->youtubeId,
            'title' => $data->title,
            'channel' => $data->channel,
            'durationSeconds' => $data->durationSeconds,
            'status' => $data->status,
            'startedAt' => $data->startedAt,
            'watchedAt' => $data->watchedAt,
        ];
    }

    /** @param array<string, mixed> $context */
    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        return $data instanceof VideoDTO;
    }

    /** @return array<class-string, bool> */
    public function getSupportedTypes(?string $format): array
    {
        return [VideoDTO::class => true];
    }
}
