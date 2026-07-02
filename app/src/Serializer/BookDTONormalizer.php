<?php

declare(strict_types=1);

namespace App\Serializer;

use App\Module\Books\Application\DTO\BookDTO;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * Normalizes a BookDTO to its API array shape (HMAI-240) — extracted verbatim
 * from the former BooksController::serializeDTO. Reused by the BookDetailDTO
 * normalizer for the embedded book payload.
 */
final class BookDTONormalizer implements NormalizerInterface
{
    /**
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    public function normalize(mixed $data, ?string $format = null, array $context = []): array
    {
        \assert($data instanceof BookDTO);

        return [
            'id' => $data->id,
            'isbn' => $data->isbn,
            'title' => $data->title,
            'author' => $data->author,
            'publisher' => $data->publisher,
            'year' => $data->year,
            'coverUrl' => $data->coverUrl,
            'totalPages' => $data->totalPages,
            'currentPage' => $data->currentPage,
            'percentage' => $data->percentage,
            'status' => $data->status,
        ];
    }

    /** @param array<string, mixed> $context */
    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        return $data instanceof BookDTO;
    }

    /** @return array<class-string, bool> */
    public function getSupportedTypes(?string $format): array
    {
        return [BookDTO::class => true];
    }
}
