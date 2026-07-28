<?php

declare(strict_types=1);

namespace App\Serializer;

use App\Module\Tasks\Application\DTO\TaskTimeDTO;
use App\Module\Tasks\Application\DTO\TimeReportDTO;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * Normalizes a TimeReportDTO to its API array shape (HMAI-240) — pure field
 * mapping, no logic. Replaces the hand-rolled mapping that used to live in
 * TasksController::timeReport() (HMAI-405).
 */
final class TimeReportDTONormalizer implements NormalizerInterface
{
    /**
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    public function normalize(mixed $data, ?string $format = null, array $context = []): array
    {
        \assert($data instanceof TimeReportDTO);

        return [
            'totalMinutes' => $data->totalMinutes,
            'totalHours' => $data->totalHours,
            'breakdown' => array_map(
                static fn (TaskTimeDTO $t) => [
                    'taskId' => $t->taskId,
                    'title' => $t->title,
                    'minutes' => $t->minutes,
                ],
                $data->breakdown,
            ),
        ];
    }

    /** @param array<string, mixed> $context */
    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        return $data instanceof TimeReportDTO;
    }

    /** @return array<class-string, bool> */
    public function getSupportedTypes(?string $format): array
    {
        return [TimeReportDTO::class => true];
    }
}
