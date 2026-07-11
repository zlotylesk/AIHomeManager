<?php

declare(strict_types=1);

namespace App\Module\Search\Domain\ValueObject;

use App\Module\Search\Domain\Enum\SearchResultType;
use InvalidArgumentException;

/**
 * The normalized input of a global search: the {@see $term} phrase, an optional
 * {@see $typeFilter} narrowing to one module/entity kind (null = search all),
 * and the 1-based {@see $page} / {@see $perPage} pagination window.
 */
final readonly class SearchQuery
{
    public const int MAX_PER_PAGE = 100;

    public function __construct(
        public string $term,
        public ?SearchResultType $typeFilter = null,
        public int $page = 1,
        public int $perPage = 20,
    ) {
        if ('' === trim($term)) {
            throw new InvalidArgumentException('Search term must not be empty.');
        }
        if ($page < 1) {
            throw new InvalidArgumentException(sprintf('Search page must be >= 1, %d given.', $page));
        }
        if ($perPage < 1 || $perPage > self::MAX_PER_PAGE) {
            throw new InvalidArgumentException(sprintf('Search perPage must be between 1 and %d, %d given.', self::MAX_PER_PAGE, $perPage));
        }
    }

    public function hasTypeFilter(): bool
    {
        return null !== $this->typeFilter;
    }

    public function equals(self $other): bool
    {
        return $this->term === $other->term
            && $this->typeFilter === $other->typeFilter
            && $this->page === $other->page
            && $this->perPage === $other->perPage;
    }
}
