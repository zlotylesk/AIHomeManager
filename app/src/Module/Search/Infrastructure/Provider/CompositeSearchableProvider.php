<?php

declare(strict_types=1);

namespace App\Module\Search\Infrastructure\Provider;

use App\Module\Search\Domain\Enum\SearchResultType;
use App\Module\Search\Domain\Port\SearchableProviderInterface;
use App\Module\Search\Domain\ReadModel\SearchableDocument;

/**
 * Fans the document query out to every per-module adapter and concatenates the
 * results into a single indexable stream. Wired as the SearchableProviderInterface
 * implementation, receiving the tagged adapters via a tagged iterator.
 */
final readonly class CompositeSearchableProvider implements SearchableProviderInterface
{
    /**
     * @param iterable<SearchableProviderInterface> $providers
     */
    public function __construct(private iterable $providers)
    {
    }

    public function documents(): array
    {
        $documents = [];
        foreach ($this->providers as $provider) {
            foreach ($provider->documents() as $document) {
                $documents[] = $document;
            }
        }

        return $documents;
    }

    public function documentFor(SearchResultType $type, string $id): ?SearchableDocument
    {
        foreach ($this->providers as $provider) {
            $document = $provider->documentFor($type, $id);
            if (null !== $document) {
                return $document;
            }
        }

        // Either no adapter owns this type, or the source row is gone. Both mean
        // the same thing to the indexer: there is no document to write.
        return null;
    }
}
