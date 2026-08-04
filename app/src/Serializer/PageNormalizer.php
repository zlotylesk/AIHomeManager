<?php

declare(strict_types=1);

namespace App\Serializer;

use App\Shared\Pagination\Page;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareTrait;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * Normalizes a {@see Page} to the API's list envelope `{data, pagination}`
 * (HMAI-240 pattern).
 *
 * There is exactly one of these for every paginated endpoint in the project:
 * the items are delegated back through the serializer, so each DTO keeps being
 * mapped by its own normalizer and the envelope cannot drift per module.
 */
final class PageNormalizer implements NormalizerInterface, NormalizerAwareInterface
{
    use NormalizerAwareTrait;

    /**
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    public function normalize(mixed $data, ?string $format = null, array $context = []): array
    {
        \assert($data instanceof Page);

        return [
            'data' => array_map(
                fn (mixed $item): mixed => $this->normalizer->normalize($item, $format, $context),
                $data->items,
            ),
            'pagination' => [
                'page' => $data->page,
                'perPage' => $data->perPage,
                'total' => $data->total,
                'totalPages' => $data->totalPages(),
            ],
        ];
    }

    /** @param array<string, mixed> $context */
    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        return $data instanceof Page;
    }

    /** @return array<class-string, bool> */
    public function getSupportedTypes(?string $format): array
    {
        return [Page::class => true];
    }
}
