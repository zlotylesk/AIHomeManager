<?php

declare(strict_types=1);

namespace App\Module\Search\Application\CommandHandler;

use App\Module\Search\Application\Command\IndexSearchDocument;
use App\Module\Search\Domain\Enum\SearchResultType;
use App\Module\Search\Domain\Port\SearchableProviderInterface;
use App\Module\Search\Domain\Port\SearchIndexerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Re-reads one document from its source and writes the index to match
 * (HMAI-363).
 *
 * Deletion needs no separate message: if the source no longer has the entity,
 * the document is removed. That keeps the source modules from having to state
 * "this was a delete" in their events, and it self-corrects — a document whose
 * entity vanished while the message sat in the queue still ends up gone.
 */
#[AsMessageHandler(bus: 'command.bus')]
final readonly class IndexSearchDocumentHandler
{
    public function __construct(
        private SearchableProviderInterface $provider,
        private SearchIndexerInterface $indexer,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(IndexSearchDocument $command): void
    {
        $type = SearchResultType::tryFrom($command->type);
        if (null === $type) {
            // The reference crossed a context boundary as a plain string, so an
            // unknown value means a source module named a type this index does
            // not carry. Nothing to do, and nothing worth failing a worker over.
            $this->logger->warning('Ignoring a search index request for an unknown document type.', [
                'type' => $command->type,
                'id' => $command->id,
            ]);

            return;
        }

        $document = $this->provider->documentFor($type, $command->id);

        if (null === $document) {
            $this->indexer->remove($type, $command->id);

            return;
        }

        $this->indexer->index($document);
    }
}
