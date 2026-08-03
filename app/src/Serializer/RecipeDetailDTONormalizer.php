<?php

declare(strict_types=1);

namespace App\Serializer;

use App\Module\Recipes\Application\DTO\RecipeDetailDTO;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareTrait;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * Normalizes a RecipeDetailDTO (HMAI-240). The recipe half is delegated to the
 * RecipeDTO normalizer so the list and the detail cannot drift apart, and —
 * like BookDetailDTO and PodcastDetailDTO — its fields are flattened to the top
 * level rather than nested under an envelope key. The DTO's own `recipe`
 * property therefore never reaches the wire, which is why the OpenAPI schema
 * documents this response as `allOf[RecipeDTO, {ingredients, steps}]` rather
 * than `#[Model(RecipeDetailDTO)]`.
 */
final class RecipeDetailDTONormalizer implements NormalizerInterface, NormalizerAwareInterface
{
    use NormalizerAwareTrait;

    /**
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    public function normalize(mixed $data, ?string $format = null, array $context = []): array
    {
        \assert($data instanceof RecipeDetailDTO);

        $recipe = $this->normalizer->normalize($data->recipe, $format, $context);
        \assert(\is_array($recipe));

        return $recipe + [
            'ingredients' => $this->normalizer->normalize($data->ingredients, $format, $context),
            'steps' => $data->steps,
        ];
    }

    /** @param array<string, mixed> $context */
    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        return $data instanceof RecipeDetailDTO;
    }

    /** @return array<class-string, bool> */
    public function getSupportedTypes(?string $format): array
    {
        return [RecipeDetailDTO::class => true];
    }
}
