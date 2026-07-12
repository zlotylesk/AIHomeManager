<?php

declare(strict_types=1);

namespace App\Module\Search\Infrastructure\Provider;

use App\Module\Search\Domain\Port\SearchableProviderInterface;

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
}
