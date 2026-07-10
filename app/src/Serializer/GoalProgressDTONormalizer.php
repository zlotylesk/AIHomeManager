<?php

declare(strict_types=1);

namespace App\Serializer;

use App\Module\Goals\Application\DTO\GoalProgressDTO;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * Normalizes a GoalProgressDTO to its API array shape (HMAI-240) — pure field
 * mapping; the progress numbers are computed upstream by the read layer.
 */
final class GoalProgressDTONormalizer implements NormalizerInterface
{
    /**
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    public function normalize(mixed $data, ?string $format = null, array $context = []): array
    {
        \assert($data instanceof GoalProgressDTO);

        return [
            'goalId' => $data->goalId,
            'type' => $data->type,
            'period' => $data->period,
            'target' => $data->target,
            'achieved' => $data->achieved,
            'percent' => $data->percent,
            'met' => $data->met,
        ];
    }

    /** @param array<string, mixed> $context */
    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        return $data instanceof GoalProgressDTO;
    }

    /** @return array<class-string, bool> */
    public function getSupportedTypes(?string $format): array
    {
        return [GoalProgressDTO::class => true];
    }
}
