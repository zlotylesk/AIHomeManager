<?php

declare(strict_types=1);

namespace App\Serializer;

use App\Module\Podcasts\Application\DTO\PodcastDetailDTO;
use App\Module\Podcasts\Application\DTO\PodcastEpisodeDTO;
use App\Module\Podcasts\Application\DTO\PodcastListeningSessionDTO;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareTrait;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * Normalizes a PodcastDetailDTO (HMAI-240). The show half is delegated to the
 * PodcastDTO normalizer so the list and detail cannot drift apart, and — like
 * BookDetailDTO — its fields are flattened to the top level rather than nested
 * under an envelope key.
 */
final class PodcastDetailDTONormalizer implements NormalizerInterface, NormalizerAwareInterface
{
    use NormalizerAwareTrait;

    /**
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    public function normalize(mixed $data, ?string $format = null, array $context = []): array
    {
        \assert($data instanceof PodcastDetailDTO);

        $podcast = $this->normalizer->normalize($data->podcast, $format, $context);
        \assert(is_array($podcast));

        return $podcast + [
            'episodes' => array_map(
                static fn (PodcastEpisodeDTO $episode): array => [
                    'id' => $episode->id,
                    'title' => $episode->title,
                    'publishedAt' => $episode->publishedAt,
                    'durationMs' => $episode->durationMs,
                    'listened' => $episode->listened,
                    'resumePositionMs' => $episode->resumePositionMs,
                    'fullyPlayed' => $episode->fullyPlayed,
                ],
                $data->episodes,
            ),
            'sessions' => array_map(
                static fn (PodcastListeningSessionDTO $session): array => [
                    'id' => $session->id,
                    'episodeId' => $session->episodeId,
                    'episodeTitle' => $session->episodeTitle,
                    'listenedAt' => $session->listenedAt,
                    'resumePositionMs' => $session->resumePositionMs,
                    'fullyPlayed' => $session->fullyPlayed,
                ],
                $data->sessions,
            ),
        ];
    }

    /** @param array<string, mixed> $context */
    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        return $data instanceof PodcastDetailDTO;
    }

    /** @return array<class-string, bool> */
    public function getSupportedTypes(?string $format): array
    {
        return [PodcastDetailDTO::class => true];
    }
}
