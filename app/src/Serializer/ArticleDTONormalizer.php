<?php

declare(strict_types=1);

namespace App\Serializer;

use App\Module\Articles\Application\DTO\ArticleDTO;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * Normalizes an ArticleDTO to its API array shape (HMAI-240) — extracted
 * verbatim from the former ArticlesController::serializeDTO so the JSON contract
 * is unchanged.
 */
final class ArticleDTONormalizer implements NormalizerInterface
{
    /**
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    public function normalize(mixed $data, ?string $format = null, array $context = []): array
    {
        \assert($data instanceof ArticleDTO);

        return [
            'id' => $data->id,
            'title' => $data->title,
            'url' => $data->url,
            'category' => $data->category,
            'estimatedReadTime' => $data->estimatedReadTime,
            'addedAt' => $data->addedAt,
            'readAt' => $data->readAt,
            'isRead' => $data->isRead,
        ];
    }

    /** @param array<string, mixed> $context */
    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        return $data instanceof ArticleDTO;
    }

    /** @return array<class-string, bool> */
    public function getSupportedTypes(?string $format): array
    {
        return [ArticleDTO::class => true];
    }
}
