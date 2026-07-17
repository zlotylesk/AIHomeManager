<?php

declare(strict_types=1);

namespace App\Module\Movies\Application;

use App\Module\Movies\Domain\Enum\MovieStatus;
use App\Shared\Domain\ValueObject\CoverUrl;
use InvalidArgumentException;

/**
 * Validated catalog metadata for a film, built from the raw command inputs. One
 * place validates the optional cover URL (through the shared CoverUrl VO), the
 * production year range, the status enum and the description length, so the
 * AddMovie and UpdateMovieMetadata handlers do not duplicate the rules.
 *
 * Each field is nullable — a null means "not set / clear it".
 */
final readonly class MovieMetadata
{
    public const int MIN_YEAR = 1888;
    public const int MAX_DESCRIPTION_LENGTH = 2000;

    private function __construct(
        public ?string $coverUrl,
        public ?int $year,
        public ?MovieStatus $status,
        public ?string $description,
    ) {
    }

    public static function fromRaw(
        ?string $coverUrl,
        ?int $year,
        ?string $status,
        ?string $description,
    ): self {
        $normalizedCover = null === $coverUrl ? null : new CoverUrl($coverUrl)->value();

        if (null !== $year && ($year < self::MIN_YEAR || $year > self::maxYear())) {
            throw new InvalidArgumentException(sprintf('Movie year must be between %d and %d, %d given.', self::MIN_YEAR, self::maxYear(), $year));
        }

        $resolvedStatus = null === $status
            ? null
            : (MovieStatus::tryFrom($status) ?? throw new InvalidArgumentException(sprintf('Unknown movie status "%s".', $status)));

        if (null !== $description && mb_strlen($description) > self::MAX_DESCRIPTION_LENGTH) {
            throw new InvalidArgumentException(sprintf('Movie description cannot exceed %d characters.', self::MAX_DESCRIPTION_LENGTH));
        }

        return new self($normalizedCover, $year, $resolvedStatus, $description);
    }

    private static function maxYear(): int
    {
        return (int) date('Y') + 5;
    }
}
