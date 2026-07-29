<?php

declare(strict_types=1);

namespace App\Serializer;

use App\Module\Budget\Application\DTO\MonthlyBudgetReportDTO;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareTrait;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * Normalizes a MonthlyBudgetReportDTO to its API array shape (HMAI-240). The
 * embedded per-category rows are delegated to the CategoryBudgetDTO
 * normalizer (no duplicated mapping) — the WatchSessionDTO precedent.
 */
final class MonthlyBudgetReportDTONormalizer implements NormalizerInterface, NormalizerAwareInterface
{
    use NormalizerAwareTrait;

    /**
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    public function normalize(mixed $data, ?string $format = null, array $context = []): array
    {
        \assert($data instanceof MonthlyBudgetReportDTO);

        return [
            'month' => $data->month,
            'totalIncomeInCents' => $data->totalIncomeInCents,
            'totalExpensesInCents' => $data->totalExpensesInCents,
            'balanceInCents' => $data->balanceInCents,
            'categories' => $this->normalizer->normalize($data->categories, $format, $context),
        ];
    }

    /** @param array<string, mixed> $context */
    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        return $data instanceof MonthlyBudgetReportDTO;
    }

    /** @return array<class-string, bool> */
    public function getSupportedTypes(?string $format): array
    {
        return [MonthlyBudgetReportDTO::class => true];
    }
}
