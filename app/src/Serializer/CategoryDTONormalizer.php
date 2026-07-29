<?php

declare(strict_types=1);

namespace App\Serializer;

use App\Module\Budget\Application\DTO\CategoryDTO;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * Normalizes a CategoryDTO to its API array shape (HMAI-240). Pure field
 * mapping — the monthly limit is already split into its two raw parts by the
 * read layer, both null when unlimited.
 */
final class CategoryDTONormalizer implements NormalizerInterface
{
    /**
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    public function normalize(mixed $data, ?string $format = null, array $context = []): array
    {
        \assert($data instanceof CategoryDTO);

        return [
            'id' => $data->id,
            'name' => $data->name,
            'type' => $data->type,
            'monthlyLimitAmountInCents' => $data->monthlyLimitAmountInCents,
            'monthlyLimitCurrency' => $data->monthlyLimitCurrency,
        ];
    }

    /** @param array<string, mixed> $context */
    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        return $data instanceof CategoryDTO;
    }

    /** @return array<class-string, bool> */
    public function getSupportedTypes(?string $format): array
    {
        return [CategoryDTO::class => true];
    }
}
