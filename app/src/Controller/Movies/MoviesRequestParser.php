<?php

declare(strict_types=1);

namespace App\Controller\Movies;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Stateless payload parsing + shape validation for the Movies REST surface, kept
 * out of the thin MoviesController (the SeriesRequestParser precedent, HMAI-239).
 * On invalid input it throws UnprocessableEntityHttpException, which
 * ApiExceptionListener turns into the {"error": …} 422 the API contract expects.
 *
 * Domain-level metadata validation (cover URL format, year range, description
 * length, status enum) stays in the MovieMetadata Application factory — this
 * parser only extracts raw values and enforces request-shape rules.
 */
final class MoviesRequestParser
{
    public const int MAX_TITLE_LENGTH = 255;

    private const array METADATA_KEYS = ['coverUrl', 'year', 'status', 'description'];

    /** @return array<string, mixed> */
    public function decode(Request $request): array
    {
        $decoded = json_decode((string) $request->getContent(), true);

        return \is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string, mixed> $data
     */
    public function requireTitle(array $data): string
    {
        $title = $data['title'] ?? null;

        if (!\is_string($title) || '' === trim($title)) {
            throw new UnprocessableEntityHttpException('Field "title" is required.');
        }

        $trimmed = trim($title);

        if (mb_strlen($trimmed) > self::MAX_TITLE_LENGTH) {
            throw new UnprocessableEntityHttpException(sprintf('Field "title" cannot exceed %d characters.', self::MAX_TITLE_LENGTH));
        }

        return $trimmed;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function parseWatched(array $data): bool
    {
        $watched = $data['watched'] ?? null;

        if (!\is_bool($watched)) {
            throw new UnprocessableEntityHttpException('Field "watched" must be a boolean.');
        }

        return $watched;
    }

    /**
     * The rating key must be present; an explicit null clears the rating, a value
     * must be an integer 1–10 (array_key_exists distinguishes null from absence).
     *
     * @param array<string, mixed> $data
     */
    public function parseNullableRating(array $data): ?int
    {
        if (!\array_key_exists('rating', $data)) {
            throw new UnprocessableEntityHttpException('Field "rating" is required.');
        }

        $rating = $data['rating'];

        if (null === $rating) {
            return null;
        }

        if (!\is_int($rating) || $rating < 1 || $rating > 10) {
            throw new UnprocessableEntityHttpException('Field "rating" must be an integer between 1 and 10, or null.');
        }

        return $rating;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function hasMetadataFields(array $data): bool
    {
        return array_any(self::METADATA_KEYS, fn ($key): bool => \array_key_exists((string) $key, $data));
    }

    /**
     * @param array<string, mixed> $data
     */
    public function metadataCoverUrl(array $data): ?string
    {
        return $this->rawString($data['coverUrl'] ?? null);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function metadataYear(array $data): ?int
    {
        $year = $data['year'] ?? null;

        // A non-numeric value casts to 0, which MovieMetadata rejects as out of range (→ 422).
        return null === $year ? null : (int) $year;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function metadataStatus(array $data): ?string
    {
        return $this->rawString($data['status'] ?? null);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function metadataDescription(array $data): ?string
    {
        $description = $data['description'] ?? null;

        return \is_string($description) && '' !== $description ? $description : null;
    }

    private function rawString(mixed $value): ?string
    {
        if (!\is_scalar($value)) {
            return null;
        }

        $trimmed = trim((string) $value);

        return '' === $trimmed ? null : $trimmed;
    }
}
