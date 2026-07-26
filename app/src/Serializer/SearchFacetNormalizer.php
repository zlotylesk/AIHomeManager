<?php

declare(strict_types=1);

namespace App\Serializer;

use App\Module\Search\Domain\ReadModel\SearchFacet;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * Normalizes a Search Domain SearchFacet to its API array shape (HMAI-240,
 * HMAI-364). Keyed on the Domain read model like the Music Album/VinylRecord
 * normalizers (HMAI-233).
 */
final class SearchFacetNormalizer implements NormalizerInterface
{
    /**
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    public function normalize(mixed $data, ?string $format = null, array $context = []): array
    {
        \assert($data instanceof SearchFacet);

        return [
            'type' => $data->type->value,
            'count' => $data->count,
        ];
    }

    /** @param array<string, mixed> $context */
    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        return $data instanceof SearchFacet;
    }

    /** @return array<class-string, bool> */
    public function getSupportedTypes(?string $format): array
    {
        return [SearchFacet::class => true];
    }
}
