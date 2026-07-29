<?php

declare(strict_types=1);

namespace App\Serializer;

use App\Module\Budget\Application\DTO\CategoryBudgetDTO;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * Normalizes a CategoryBudgetDTO — the per-category row nested inside
 * MonthlyBudgetReportDTO — to its API array shape (HMAI-240). Not a
 * top-level DTO on its own (unlike CategoryDTO), but still gets its own
 * registered normalizer so MonthlyBudgetReportDTONormalizer can delegate to
 * it through the serializer chain instead of duplicating the field map.
 */
final class CategoryBudgetDTONormalizer implements NormalizerInterface
{
    /**
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    public function normalize(mixed $data, ?string $format = null, array $context = []): array
    {
        \assert($data instanceof CategoryBudgetDTO);

        return [
            'categoryId' => $data->categoryId,
            'categoryName' => $data->categoryName,
            'type' => $data->type,
            'spentInCents' => $data->spentInCents,
            'monthlyLimitInCents' => $data->monthlyLimitInCents,
            'monthlyLimitCurrency' => $data->monthlyLimitCurrency,
            'percentUsed' => $data->percentUsed,
            'overLimit' => $data->overLimit,
        ];
    }

    /** @param array<string, mixed> $context */
    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        return $data instanceof CategoryBudgetDTO;
    }

    /** @return array<class-string, bool> */
    public function getSupportedTypes(?string $format): array
    {
        return [CategoryBudgetDTO::class => true];
    }
}
