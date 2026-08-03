<?php

declare(strict_types=1);

namespace App\Serializer;

use App\Module\Recipes\Application\DTO\RecipeDTO;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * Normalizes a RecipeDTO to its API array shape (HMAI-240). Pure field
 * mapping — `ingredientCount` is computed in the read query, not here.
 */
final class RecipeDTONormalizer implements NormalizerInterface
{
    /**
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    public function normalize(mixed $data, ?string $format = null, array $context = []): array
    {
        \assert($data instanceof RecipeDTO);

        return [
            'id' => $data->id,
            'title' => $data->title,
            'servings' => $data->servings,
            'prepTimeMinutes' => $data->prepTimeMinutes,
            'tags' => $data->tags,
            'ingredientCount' => $data->ingredientCount,
        ];
    }

    /** @param array<string, mixed> $context */
    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        return $data instanceof RecipeDTO;
    }

    /** @return array<class-string, bool> */
    public function getSupportedTypes(?string $format): array
    {
        return [RecipeDTO::class => true];
    }
}
