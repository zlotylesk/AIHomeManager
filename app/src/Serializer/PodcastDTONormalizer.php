<?php

declare(strict_types=1);

namespace App\Serializer;

use App\Module\Podcasts\Application\DTO\PodcastDTO;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * Normalizes a PodcastDTO to its API array shape (HMAI-240). Pure field
 * mapping — the counters are computed upstream in the read query, never here.
 */
final class PodcastDTONormalizer implements NormalizerInterface
{
    /**
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    public function normalize(mixed $data, ?string $format = null, array $context = []): array
    {
        \assert($data instanceof PodcastDTO);

        return [
            'id' => $data->id,
            'title' => $data->title,
            'publisher' => $data->publisher,
            'coverUrl' => $data->coverUrl,
            'description' => $data->description,
            'episodeCount' => $data->episodeCount,
            'listenedEpisodeCount' => $data->listenedEpisodeCount,
            'lastListenedAt' => $data->lastListenedAt,
            'createdAt' => $data->createdAt,
        ];
    }

    /** @param array<string, mixed> $context */
    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        return $data instanceof PodcastDTO;
    }

    /** @return array<class-string, bool> */
    public function getSupportedTypes(?string $format): array
    {
        return [PodcastDTO::class => true];
    }
}
