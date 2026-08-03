<?php

declare(strict_types=1);

namespace App\Serializer;

use App\Module\Recipes\Application\DTO\RecipeIngredientDTO;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * Normalizes a RecipeIngredientDTO — the line nested inside RecipeDetailDTO —
 * to its API array shape (HMAI-240). Not a top-level DTO on its own, but it
 * still gets a registered normalizer so the detail can delegate to it through
 * the serializer chain instead of duplicating the field map (the
 * CategoryBudgetDTO precedent).
 */
final class RecipeIngredientDTONormalizer implements NormalizerInterface
{
    /**
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    public function normalize(mixed $data, ?string $format = null, array $context = []): array
    {
        \assert($data instanceof RecipeIngredientDTO);

        return [
            'name' => $data->name,
            'quantity' => $data->quantity,
            'unit' => $data->unit,
        ];
    }

    /** @param array<string, mixed> $context */
    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        return $data instanceof RecipeIngredientDTO;
    }

    /** @return array<class-string, bool> */
    public function getSupportedTypes(?string $format): array
    {
        return [RecipeIngredientDTO::class => true];
    }
}
