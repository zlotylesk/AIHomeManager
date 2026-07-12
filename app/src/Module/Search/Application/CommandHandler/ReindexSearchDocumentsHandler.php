<?php

declare(strict_types=1);

namespace App\Module\Search\Application\CommandHandler;

use App\Module\Search\Application\Command\ReindexSearchDocuments;
use App\Module\Search\Domain\Port\SearchIndexerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Rebuilds the search index by delegating to the {@see SearchIndexerInterface}
 * port. Deptrac-clean: the Application handler depends on the Domain port, never
 * on the Infrastructure indexer directly.
 */
#[AsMessageHandler(bus: 'command.bus')]
final readonly class ReindexSearchDocumentsHandler
{
    public function __construct(private SearchIndexerInterface $indexer)
    {
    }

    public function __invoke(ReindexSearchDocuments $command): void
    {
        $this->indexer->reindex();
    }
}
