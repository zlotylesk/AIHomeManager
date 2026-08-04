<?php

declare(strict_types=1);

namespace App\Shared\Pagination;

use InvalidArgumentException;

/**
 * The normalized pagination window a list query is asked for: the 1-based
 * {@see $page} and the {@see $perPage} size.
 *
 * It lives in the shared kernel because every module's list query carries one,
 * and a per-module copy is exactly how two endpoints would end up disagreeing
 * about what `perPage=0` means. The vocabulary deliberately matches the older
 * {@see \App\Module\Search\Domain\ValueObject\SearchQuery}, which already had
 * page/perPage — the project gets one pagination convention, not a second.
 *
 * {@see $perPage} is capped rather than merely defaulted: a default alone still
 * lets a caller ask for the whole table, which is the thing being fixed.
 */
final readonly class PageRequest
{
    public const int DEFAULT_PER_PAGE = 50;
    public const int MAX_PER_PAGE = 100;

    public function __construct(
        public int $page = 1,
        public int $perPage = self::DEFAULT_PER_PAGE,
    ) {
        if ($page < 1) {
            throw new InvalidArgumentException(sprintf('Page must be >= 1, %d given.', $page));
        }
        if ($perPage < 1 || $perPage > self::MAX_PER_PAGE) {
            throw new InvalidArgumentException(sprintf('perPage must be between 1 and %d, %d given.', self::MAX_PER_PAGE, $perPage));
        }
    }

    /** The SQL OFFSET this window starts at. */
    /**
     * Rows to skip before this page.
     *
     * A query using this MUST order by something unique (append the primary key
     * if the sort column is not). `LIMIT`/`OFFSET` over a sort with ties lets
     * the engine return them in any order it likes, and it need not pick the
     * same one twice — so a row can come back on two consecutive pages while
     * another is never returned at all. Without a window that non-determinism
     * is invisible, because every row is in the response either way; with one it
     * silently loses data. Same rule the OpenSearch adapter follows for equally
     * scored hits (HMAI-362).
     */
    public function offset(): int
    {
        return ($this->page - 1) * $this->perPage;
    }

    public function equals(self $other): bool
    {
        return $this->page === $other->page && $this->perPage === $other->perPage;
    }
}
