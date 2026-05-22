<?php

declare(strict_types=1);

namespace App\Module\Articles\Application\DTO;

// Intentionally NOT readonly: ArticleImporter mutates these counters per row
// during the import pass (++$result->imported, etc.). Promoting to readonly
// would break that accumulator. The mutable counters are the whole point of
// this DTO — it's a tally, not a snapshot.
final class ImportResult
{
    public int $imported = 0;
    public int $skipped = 0;
    public int $errors = 0;
}
