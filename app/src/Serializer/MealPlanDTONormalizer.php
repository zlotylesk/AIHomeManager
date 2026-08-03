<?php

declare(strict_types=1);

namespace App\Serializer;

use App\Module\Recipes\Application\DTO\MealPlanDTO;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareTrait;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * Normalizes a MealPlanDTO to its API array shape (HMAI-240).
 *
 * The calendar is three levels deep (day → slot → meal), and each level is
 * delegated to its own registered normalizer rather than being folded into one
 * nested array_map here: a triple-nested map is where a field quietly stops
 * matching the DTO it came from.
 */
final class MealPlanDTONormalizer implements NormalizerInterface, NormalizerAwareInterface
{
    use NormalizerAwareTrait;

    /**
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    public function normalize(mixed $data, ?string $format = null, array $context = []): array
    {
        \assert($data instanceof MealPlanDTO);

        return [
            'from' => $data->from,
            'to' => $data->to,
            'days' => $this->normalizer->normalize($data->days, $format, $context),
        ];
    }

    /** @param array<string, mixed> $context */
    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        return $data instanceof MealPlanDTO;
    }

    /** @return array<class-string, bool> */
    public function getSupportedTypes(?string $format): array
    {
        return [MealPlanDTO::class => true];
    }
}
