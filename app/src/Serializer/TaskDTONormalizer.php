<?php

declare(strict_types=1);

namespace App\Serializer;

use App\Module\Tasks\Application\DTO\TaskDTO;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * Normalizes a TaskDTO to its API array shape (HMAI-240) — extracted verbatim
 * from the former TasksController::serializeDTO.
 */
final class TaskDTONormalizer implements NormalizerInterface
{
    /**
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    public function normalize(mixed $data, ?string $format = null, array $context = []): array
    {
        \assert($data instanceof TaskDTO);

        return [
            'id' => $data->id,
            'title' => $data->title,
            'start' => $data->start,
            'end' => $data->end,
            'durationMinutes' => $data->durationMinutes,
            'status' => $data->status,
            'googleEventId' => $data->googleEventId,
        ];
    }

    /** @param array<string, mixed> $context */
    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        return $data instanceof TaskDTO;
    }

    /** @return array<class-string, bool> */
    public function getSupportedTypes(?string $format): array
    {
        return [TaskDTO::class => true];
    }
}
