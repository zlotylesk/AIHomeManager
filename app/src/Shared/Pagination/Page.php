<?php

declare(strict_types=1);

namespace App\Shared\Pagination;

/**
 * One page of a list read: the {@see $items} themselves plus the counters a
 * client needs to know where it is and whether there is more.
 *
 * {@see $total} is the size of the whole match set, not of this page — that is
 * what lets a UI render "page 2 of 7" without asking for every row, which is
 * the entire point of paginating.
 *
 * @template T
 */
final readonly class Page
{
    /**
     * @param list<T> $items
     */
    public function __construct(
        public array $items,
        public int $total,
        public int $page,
        public int $perPage,
    ) {
    }

    /**
     * Builds a page from the window that was requested, so a handler never has
     * to restate page/perPage and cannot report a window other than the one it
     * actually applied.
     *
     * @template TItem
     *
     * @param list<TItem> $items
     *
     * @return self<TItem>
     */
    public static function of(array $items, int $total, PageRequest $request): self
    {
        return new self($items, $total, $request->page, $request->perPage);
    }

    /**
     * Builds a page by windowing a list that is **already** fully in memory.
     *
     * For a database read the window belongs in the SQL, so this is not the
     * usual path — it exists for a source that can only hand back the whole set
     * (a cached third-party collection, say). It still bounds what goes over the
     * wire, which is the part the mobile client and the PWA's cache budget care
     * about; it just cannot make the read itself cheaper.
     *
     * Takes any array rather than a list, because a port handing back a whole
     * collection makes no promise about its keys; the reindex below is what
     * turns it into the list the envelope must serialize as a JSON array (a
     * gapped array would come out as an object and break every client).
     *
     * @template TItem
     *
     * @param array<TItem> $all
     *
     * @return self<TItem>
     */
    public static function slice(array $all, PageRequest $request): self
    {
        return new self(
            array_values(array_slice($all, $request->offset(), $request->perPage)),
            count($all),
            $request->page,
            $request->perPage,
        );
    }

    /**
     * Total number of pages in the match set. An empty result is one (empty)
     * page rather than zero — a UI showing "page 1 of 0" reads as broken.
     */
    public function totalPages(): int
    {
        return max(1, (int) ceil($this->total / $this->perPage));
    }
}
