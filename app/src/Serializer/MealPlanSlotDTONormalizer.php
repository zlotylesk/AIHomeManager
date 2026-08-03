<?php

declare(strict_types=1);

namespace App\Serializer;

use App\Module\Recipes\Application\DTO\MealPlanSlotDTO;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareTrait;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * Normalizes a MealPlanSlotDTO (HMAI-240), delegating the meals to their own
 * normalizer. An empty slot is serialized with an empty `meals` list rather
 * than being omitted — see the DTO for why every slot is always present.
 */
final class MealPlanSlotDTONormalizer implements NormalizerInterface, NormalizerAwareInterface
{
    use NormalizerAwareTrait;

    /**
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    public function normalize(mixed $data, ?string $format = null, array $context = []): array
    {
        \assert($data instanceof MealPlanSlotDTO);

        return [
            'slot' => $data->slot,
            'meals' => $this->normalizer->normalize($data->meals, $format, $context),
        ];
    }

    /** @param array<string, mixed> $context */
    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        return $data instanceof MealPlanSlotDTO;
    }

    /** @return array<class-string, bool> */
    public function getSupportedTypes(?string $format): array
    {
        return [MealPlanSlotDTO::class => true];
    }
}
