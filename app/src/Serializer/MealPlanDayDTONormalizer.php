<?php

declare(strict_types=1);

namespace App\Serializer;

use App\Module\Recipes\Application\DTO\MealPlanDayDTO;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareTrait;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * Normalizes a MealPlanDayDTO (HMAI-240), delegating each slot to its own
 * normalizer.
 */
final class MealPlanDayDTONormalizer implements NormalizerInterface, NormalizerAwareInterface
{
    use NormalizerAwareTrait;

    /**
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    public function normalize(mixed $data, ?string $format = null, array $context = []): array
    {
        \assert($data instanceof MealPlanDayDTO);

        return [
            'date' => $data->date,
            'slots' => $this->normalizer->normalize($data->slots, $format, $context),
        ];
    }

    /** @param array<string, mixed> $context */
    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        return $data instanceof MealPlanDayDTO;
    }

    /** @return array<class-string, bool> */
    public function getSupportedTypes(?string $format): array
    {
        return [MealPlanDayDTO::class => true];
    }
}
