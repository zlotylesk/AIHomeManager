<?php

declare(strict_types=1);

namespace App\Serializer;

use App\Module\Search\Domain\ValueObject\SearchResult;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * Normalizes a Search Domain SearchResult to its API array shape (HMAI-240).
 * Keyed on the Domain value like the Music Album/VinylRecord normalizers (HMAI-233).
 */
final class SearchResultDTONormalizer implements NormalizerInterface
{
    /**
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    public function normalize(mixed $data, ?string $format = null, array $context = []): array
    {
        \assert($data instanceof SearchResult);

        return [
            'type' => $data->type->value,
            'id' => $data->id,
            'title' => $data->title,
            'snippet' => $data->snippet,
            'url' => $data->url,
        ];
    }

    /** @param array<string, mixed> $context */
    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        return $data instanceof SearchResult;
    }

    /** @return array<class-string, bool> */
    public function getSupportedTypes(?string $format): array
    {
        return [SearchResult::class => true];
    }
}
