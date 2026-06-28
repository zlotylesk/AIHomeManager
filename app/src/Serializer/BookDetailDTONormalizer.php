<?php

declare(strict_types=1);

namespace App\Serializer;

use App\Module\Books\Application\DTO\BookDetailDTO;
use App\Module\Books\Application\DTO\ReadingSessionDTO;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareTrait;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * Normalizes a BookDetailDTO to its API array shape (HMAI-240) — extracted
 * verbatim from the former BooksController::serializeDetailDTO. The embedded
 * book is delegated to the BookDTO normalizer (no duplicated mapping); the
 * reading sessions are inlined as before.
 */
final class BookDetailDTONormalizer implements NormalizerInterface, NormalizerAwareInterface
{
    use NormalizerAwareTrait;

    /**
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    public function normalize(mixed $data, ?string $format = null, array $context = []): array
    {
        \assert($data instanceof BookDetailDTO);

        $book = $this->normalizer->normalize($data->book, $format, $context);
        \assert(is_array($book));

        return $book + [
            'sessions' => array_map(
                static fn (ReadingSessionDTO $session): array => [
                    'id' => $session->id,
                    'date' => $session->date,
                    'pagesRead' => $session->pagesRead,
                    'notes' => $session->notes,
                ],
                $data->sessions,
            ),
        ];
    }

    /** @param array<string, mixed> $context */
    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        return $data instanceof BookDetailDTO;
    }

    /** @return array<class-string, bool> */
    public function getSupportedTypes(?string $format): array
    {
        return [BookDetailDTO::class => true];
    }
}
