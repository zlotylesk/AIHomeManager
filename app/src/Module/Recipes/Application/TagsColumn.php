<?php

declare(strict_types=1);

namespace App\Module\Recipes\Application;

/**
 * Both directions of the `recipes.tags` JSON column.
 *
 * Extracted rather than left as a private helper because three readers need the
 * decode — the list query, the detail query and the repository's own hydration
 * — and a decode duplicated per reader is how one of them quietly ends up
 * tolerating a shape the others reject (the Budget `MoneyColumn` / `MonthRange`
 * lesson). The encode lives here too so the column's format is described in one
 * place rather than having its two halves drift apart.
 *
 * Reading is deliberately defensive: the column is written by the aggregate,
 * which normalises tags to lower-case non-empty strings, but a hand-edited row
 * or a future writer must not be able to put a number or a nested array into a
 * `list<string>` and have it surface as a broken tag halfway up the stack.
 */
final readonly class TagsColumn
{
    private function __construct()
    {
    }

    /**
     * @param list<string> $tags
     */
    public static function encode(array $tags): string
    {
        return json_encode($tags, JSON_THROW_ON_ERROR);
    }

    /**
     * @return list<string>
     */
    public static function parse(mixed $raw): array
    {
        if (!is_string($raw)) {
            return [];
        }

        $decoded = json_decode($raw, true);

        if (!is_array($decoded)) {
            return [];
        }

        $tags = [];
        foreach ($decoded as $tag) {
            if (is_string($tag)) {
                $tags[] = $tag;
            }
        }

        return $tags;
    }
}
