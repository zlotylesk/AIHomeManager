<?php

declare(strict_types=1);

namespace App\Module\Recipes\Application\DTO;

/**
 * The shopping list for a window.
 *
 * Unlike the calendar, this read is NOT gap-filled: there is no such thing as
 * an ingredient nobody planned, so an empty list means the window holds no
 * meals rather than that something could not be read. The window is echoed
 * back for the same reason it is on the calendar — so an empty answer is
 * distinguishable from a request interpreted differently than it meant.
 */
final readonly class ShoppingListDTO
{
    /** @param list<ShoppingListItemDTO> $items */
    public function __construct(
        public string $from,
        public string $to,
        public array $items,
    ) {
    }
}
