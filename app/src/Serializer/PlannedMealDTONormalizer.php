<?php

declare(strict_types=1);

namespace App\Serializer;

use App\Module\Recipes\Application\DTO\PlannedMealDTO;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * Normalizes a PlannedMealDTO — the innermost level of the meal-plan
 * calendar — to its API array shape (HMAI-240).
 */
final class PlannedMealDTONormalizer implements NormalizerInterface
{
    /**
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    public function normalize(mixed $data, ?string $format = null, array $context = []): array
    {
        \assert($data instanceof PlannedMealDTO);

        return [
            'id' => $data->id,
            'recipeId' => $data->recipeId,
            'recipeTitle' => $data->recipeTitle,
            'servings' => $data->servings,
        ];
    }

    /** @param array<string, mixed> $context */
    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        return $data instanceof PlannedMealDTO;
    }

    /** @return array<class-string, bool> */
    public function getSupportedTypes(?string $format): array
    {
        return [PlannedMealDTO::class => true];
    }
}
