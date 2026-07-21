<?php

declare(strict_types=1);

namespace App\Serializer;

use App\Module\Insights\Application\DTO\TrendPointDTO;
use App\Module\Insights\Application\DTO\TrendsDTO;
use App\Module\Insights\Application\DTO\TrendSeriesDTO;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * Normalizes a TrendsDTO to its API array shape (HMAI-240). Pure field mapping —
 * the folds (`total`/`average`/`headline`) are computed upstream in the read
 * layer, never here (HMAI-242).
 */
final class TrendsDTONormalizer implements NormalizerInterface
{
    /**
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    public function normalize(mixed $data, ?string $format = null, array $context = []): array
    {
        \assert($data instanceof TrendsDTO);

        return [
            'from' => $data->from,
            'to' => $data->to,
            'granularity' => $data->granularity,
            'series' => array_map(
                static fn (TrendSeriesDTO $series): array => [
                    'metric' => $series->metric,
                    'unit' => $series->unit,
                    'total' => $series->total,
                    'average' => $series->average,
                    'headline' => $series->headline,
                    'points' => array_map(
                        static fn (TrendPointDTO $point): array => [
                            'bucketStart' => $point->bucketStart,
                            'value' => $point->value,
                        ],
                        $series->points,
                    ),
                ],
                $data->series,
            ),
        ];
    }

    /** @param array<string, mixed> $context */
    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        return $data instanceof TrendsDTO;
    }

    /** @return array<class-string, bool> */
    public function getSupportedTypes(?string $format): array
    {
        return [TrendsDTO::class => true];
    }
}
