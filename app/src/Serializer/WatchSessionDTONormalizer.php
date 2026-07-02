<?php

declare(strict_types=1);

namespace App\Serializer;

use App\Module\YouTubeProgress\Application\DTO\WatchSessionDTO;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareTrait;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * Normalizes a WatchSessionDTO to its API array shape (HMAI-240) — extracted
 * verbatim from the former YouTubeProgressController::serializeSession. The
 * embedded videos are delegated to the VideoDTO normalizer (no duplicated
 * mapping).
 */
final class WatchSessionDTONormalizer implements NormalizerInterface, NormalizerAwareInterface
{
    use NormalizerAwareTrait;

    /**
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    public function normalize(mixed $data, ?string $format = null, array $context = []): array
    {
        \assert($data instanceof WatchSessionDTO);

        return [
            'id' => $data->id,
            'createdAt' => $data->createdAt,
            'totalDurationSeconds' => $data->totalDurationSeconds,
            'youtubePlaylistId' => $data->youtubePlaylistId,
            'videos' => $this->normalizer->normalize($data->videos, $format, $context),
        ];
    }

    /** @param array<string, mixed> $context */
    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        return $data instanceof WatchSessionDTO;
    }

    /** @return array<class-string, bool> */
    public function getSupportedTypes(?string $format): array
    {
        return [WatchSessionDTO::class => true];
    }
}
