<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Pagination;

use App\Shared\Pagination\Page;
use App\Shared\Pagination\PageRequest;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * The window every list endpoint speaks. These rules are shared by fourteen
 * endpoints, so they are pinned here rather than re-asserted per module — a
 * regression in the offset arithmetic or in the guards would otherwise surface
 * as fourteen unrelated-looking API failures.
 */
final class PageTest extends TestCase
{
    public function testOffsetSkipsThePagesBeforeTheRequestedOne(): void
    {
        self::assertSame(0, new PageRequest(1, 50)->offset());
        self::assertSame(50, new PageRequest(2, 50)->offset());
        self::assertSame(40, new PageRequest(3, 20)->offset());
    }

    public function testPageBelowOneIsRejected(): void
    {
        // Page 0 would compute a negative offset, which MySQL refuses outright —
        // better a 422 naming the parameter than a database error.
        $this->expectException(InvalidArgumentException::class);

        new PageRequest(0);
    }

    public function testPerPageAboveTheMaximumIsRejected(): void
    {
        // The cap is the whole point of the change: without it a client could
        // ask for the unbounded response the pagination was added to prevent.
        $this->expectException(InvalidArgumentException::class);

        new PageRequest(1, PageRequest::MAX_PER_PAGE + 1);
    }

    public function testPerPageBelowOneIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new PageRequest(1, 0);
    }

    public function testTotalPagesRoundsUpSoAPartialLastPageStillCounts(): void
    {
        self::assertSame(3, new Page([], 101, 1, 50)->totalPages());
        self::assertSame(2, new Page([], 100, 1, 50)->totalPages());
    }

    public function testAnEmptyResultIsOnePageRatherThanZero(): void
    {
        // "Strona 1 z 0" reads as broken; an empty library is one empty page.
        self::assertSame(1, new Page([], 0, 1, 50)->totalPages());
    }

    public function testOfReportsTheWindowThatWasActuallyRequested(): void
    {
        $page = Page::of(['a', 'b'], 137, new PageRequest(3, 20));

        self::assertSame(['a', 'b'], $page->items);
        self::assertSame(137, $page->total);
        self::assertSame(3, $page->page);
        self::assertSame(20, $page->perPage);
    }

    public function testSliceWindowsAnInMemoryListAndReportsTheWholeSetAsTotal(): void
    {
        $all = range(1, 10);

        $page = Page::slice($all, new PageRequest(2, 4));

        self::assertSame([5, 6, 7, 8], $page->items);
        self::assertSame(10, $page->total, 'The total is the whole collection, not the slice.');
        self::assertSame(3, $page->totalPages());
    }

    public function testSlicePastTheEndIsAnEmptyPageRatherThanAnError(): void
    {
        // A client paging past the end (the collection shrank between reads)
        // gets an empty page with an honest total, not a failure.
        $page = Page::slice(range(1, 5), new PageRequest(9, 5));

        self::assertSame([], $page->items);
        self::assertSame(5, $page->total);
    }
}
