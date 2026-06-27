<?php

declare(strict_types=1);

namespace App\Controller\Series;

use App\Module\Series\Domain\Enum\SeriesStatus;
use App\Shared\Domain\ValueObject\CoverUrl;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Parses and validates the JSON payloads of the Series API (HMAI-239).
 *
 * Extracted out of SeriesController so the controller actions stay thin
 * (decode → parse → dispatch → respond) and the validation rules can be unit
 * tested in isolation. Every rule violation throws UnprocessableEntityHttpException,
 * which ApiExceptionListener turns into the exact same `{"error": "..."}` 422
 * the controller used to build by hand — the API contract is unchanged.
 */
final class SeriesRequestParser
{
    private const int MAX_TITLE_LENGTH = 255;
    private const int MAX_DESCRIPTION_LENGTH = 2000;
    private const int MIN_YEAR = 1900;

    /**
     * Decodes the JSON request body to an associative array, falling back to an
     * empty array for an absent or non-object body (so callers can read keys
     * uniformly without re-checking the decode result).
     *
     * @return array<string, mixed>
     */
    public function decode(Request $request): array
    {
        $data = json_decode($request->getContent(), true);

        return is_array($data) ? $data : [];
    }

    /**
     * Validates the `title` field (non-empty, ≤ MAX_TITLE_LENGTH). mb_strlen
     * counts characters not bytes, so a 255-char multibyte title still fits
     * VARCHAR(255) in utf8mb4.
     *
     * @param array<string, mixed> $data
     */
    public function parseTitle(array $data): string
    {
        $raw = $data['title'] ?? '';
        $title = is_scalar($raw) ? trim((string) $raw) : '';

        if ('' === $title) {
            throw new UnprocessableEntityHttpException('Title is required.');
        }

        if (mb_strlen($title) > self::MAX_TITLE_LENGTH) {
            throw new UnprocessableEntityHttpException(sprintf('Title must be at most %d characters.', self::MAX_TITLE_LENGTH));
        }

        return $title;
    }

    /** @param array<string, mixed> $data */
    public function parseSeasonNumber(array $data): int
    {
        return $this->requirePositiveInt($data['number'] ?? null, 'Season number must be a positive integer.');
    }

    /** @param array<string, mixed> $data */
    public function parseEpisodeNumber(array $data): int
    {
        return $this->requirePositiveInt($data['number'] ?? null, 'Episode number must be a positive integer.');
    }

    /**
     * The optional `rating` on add-episode — a loose cast, mirroring the prior
     * behaviour: absent means "no rating", anything present is coerced to int
     * and the Rating VO validates the range in the handler.
     *
     * @param array<string, mixed> $data
     */
    public function parseOptionalEpisodeRating(array $data): ?int
    {
        return isset($data['rating']) ? (int) $data['rating'] : null;
    }

    /**
     * Validates a required `rating` (integer 1–10), used by the episode-rating
     * endpoint.
     *
     * @param array<string, mixed> $data
     */
    public function parseRequiredRating(array $data): int
    {
        $rating = $data['rating'] ?? null;

        if (!is_int($rating) || $rating < 1 || $rating > 10) {
            throw new UnprocessableEntityHttpException('Field "rating" must be an integer between 1 and 10.');
        }

        return $rating;
    }

    /** @param array<string, mixed> $data */
    public function parseWatched(array $data): bool
    {
        $watched = $data['watched'] ?? null;

        if (!is_bool($watched)) {
            throw new UnprocessableEntityHttpException('Field "watched" must be a boolean.');
        }

        return $watched;
    }

    /**
     * Validates the `rating` field for the show/season own-rating endpoints.
     * Returns the int (1–10) on a set, or null on an explicit `{"rating": null}`
     * clear. An absent key is a malformed request — array_key_exists tells an
     * explicit null apart from absence (isset() cannot).
     *
     * @param array<string, mixed> $data
     */
    public function parseNullableRating(array $data): ?int
    {
        if (!array_key_exists('rating', $data)) {
            throw $this->invalidNullableRating();
        }

        $rating = $data['rating'];
        if (null === $rating) {
            return null;
        }

        if (!is_int($rating) || $rating < 1 || $rating > 10) {
            throw $this->invalidNullableRating();
        }

        return $rating;
    }

    /**
     * Validates the optional catalog-metadata fields (HMAI-190). Returns a
     * normalized bag (CoverUrl VO, year int, status enum, description string —
     * each nullable) plus whether any metadata key was present. Absent keys
     * yield null; only what is present is validated.
     *
     * @param array<string, mixed> $data
     */
    public function parseMetadata(array $data): SeriesMetadataInput
    {
        return new SeriesMetadataInput(
            coverUrl: $this->parseCoverUrl($data['coverUrl'] ?? null),
            year: $this->parseYear($data),
            status: $this->parseStatus($data),
            description: $this->parseDescription($data),
            hasAnyField: array_key_exists('coverUrl', $data)
                || array_key_exists('year', $data)
                || array_key_exists('status', $data)
                || array_key_exists('description', $data),
        );
    }

    private function requirePositiveInt(mixed $value, string $message): int
    {
        if (!is_int($value) || $value < 1) {
            throw new UnprocessableEntityHttpException($message);
        }

        return $value;
    }

    private function invalidNullableRating(): UnprocessableEntityHttpException
    {
        return new UnprocessableEntityHttpException(
            'Field "rating" must be an integer between 1 and 10, or null to clear.'
        );
    }

    /**
     * Builds the CoverUrl VO from a raw JSON value: null or an empty/whitespace
     * string means "no cover", anything else is validated by the VO (which
     * throws on a bad URL → surfaced as 422).
     */
    private function parseCoverUrl(mixed $raw): ?CoverUrl
    {
        if (null === $raw) {
            return null;
        }

        $trimmed = trim((string) $raw);
        if ('' === $trimmed) {
            return null;
        }

        try {
            return new CoverUrl($trimmed);
        } catch (InvalidArgumentException $e) {
            throw new UnprocessableEntityHttpException($e->getMessage());
        }
    }

    /** @param array<string, mixed> $data */
    private function parseYear(array $data): ?int
    {
        if (!array_key_exists('year', $data) || null === $data['year']) {
            return null;
        }

        $year = $data['year'];
        if (!is_int($year) || $year < self::MIN_YEAR || $year > $this->maxYear()) {
            throw new UnprocessableEntityHttpException(sprintf('Field "year" must be an integer between %d and %d.', self::MIN_YEAR, $this->maxYear()));
        }

        return $year;
    }

    /** @param array<string, mixed> $data */
    private function parseStatus(array $data): ?SeriesStatus
    {
        if (!array_key_exists('status', $data) || null === $data['status']) {
            return null;
        }

        $status = is_string($data['status']) ? SeriesStatus::tryFrom($data['status']) : null;
        if (null === $status) {
            throw new UnprocessableEntityHttpException('Field "status" must be one of: ongoing, ended.');
        }

        return $status;
    }

    /** @param array<string, mixed> $data */
    private function parseDescription(array $data): ?string
    {
        if (!array_key_exists('description', $data) || null === $data['description']) {
            return null;
        }

        $description = trim((string) $data['description']);
        if (mb_strlen($description) > self::MAX_DESCRIPTION_LENGTH) {
            throw new UnprocessableEntityHttpException(sprintf('Description must be at most %d characters.', self::MAX_DESCRIPTION_LENGTH));
        }

        return '' === $description ? null : $description;
    }

    /** Upper bound for the year field — a few years ahead for not-yet-aired shows. */
    private function maxYear(): int
    {
        return (int) date('Y') + 5;
    }
}
