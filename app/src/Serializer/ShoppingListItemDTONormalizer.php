<?php

declare(strict_types=1);

namespace App\Serializer;

use App\Module\Recipes\Application\DTO\ShoppingListItemDTO;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * Normalizes a ShoppingListItemDTO — one line of the shopping list — to its
 * API array shape (HMAI-240).
 *
 * The quantity is passed through unrounded, deliberately: rounding is a
 * presentation concern and the API cannot know what precision a unit deserves
 * (see the DTO). A normalizer that rounded here would bake one precision into
 * the contract for every unit at once.
 */
final class ShoppingListItemDTONormalizer implements NormalizerInterface
{
    /**
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    public function normalize(mixed $data, ?string $format = null, array $context = []): array
    {
        \assert($data instanceof ShoppingListItemDTO);

        return [
            'name' => $data->name,
            'unit' => $data->unit,
            'quantity' => $data->quantity,
        ];
    }

    /** @param array<string, mixed> $context */
    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        return $data instanceof ShoppingListItemDTO;
    }

    /** @return array<class-string, bool> */
    public function getSupportedTypes(?string $format): array
    {
        return [ShoppingListItemDTO::class => true];
    }
}
