<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Module\Search\Domain\Enum\SearchResultType;
use App\Module\Search\Domain\Port\SearchIndexerInterface;
use App\Module\Search\Domain\ReadModel\SearchableDocument;
use RuntimeException;

/**
 * Records what reached one side of the dual write (HMAI-365).
 *
 * A named class rather than an anonymous one because two mocks of a single
 * interface share a class name, so identity comparison could not tell the
 * primary from the standby — and both the factory test and the dual-write test
 * need exactly that distinction.
 */
final class RecordingSearchIndexer implements SearchIndexerInterface
{
    public int $reindexed = 0;

    /** @var list<string> */
    public array $indexed = [];

    /** @var list<string> */
    public array $removed = [];

    public function __construct(
        private readonly int $documentCount = 0,
        private readonly bool $broken = false,
    ) {
    }

    public function reindex(): int
    {
        $this->failIfBroken();
        ++$this->reindexed;

        return $this->documentCount;
    }

    public function index(SearchableDocument $document): void
    {
        $this->failIfBroken();
        $this->indexed[] = $document->type->value.':'.$document->id;
    }

    public function remove(SearchResultType $type, string $id): void
    {
        $this->failIfBroken();
        $this->removed[] = $type->value.':'.$id;
    }

    private function failIfBroken(): void
    {
        if ($this->broken) {
            throw new RuntimeException('The index is unreachable.');
        }
    }
}
