<?php

declare(strict_types=1);

namespace App\Module\Search\Infrastructure\Engine;

use App\Module\Search\Domain\Port\SearchEngineInterface;
use InvalidArgumentException;

/**
 * The ES/FULLTEXT feature flag (HMAI-361, epic HMAI-359): picks which adapter
 * backs the {@see SearchEngineInterface} port, so a backend cutover is a
 * configuration change rather than a code change — nothing in Application or the
 * UI knows which engine answered.
 *
 * A factory rather than an alias, because a Symfony alias is resolved when the
 * container is compiled and therefore cannot read an environment variable. Both
 * engines are passed in and only one is returned; that costs nothing at runtime
 * — the DBAL connection and the OpenSearch client are both built lazily, so the
 * discarded engine never opens a connection.
 *
 * The parameters are typed as the port on purpose: selecting is all this class
 * does, and the concrete engines are named in `services.yaml` where the wiring
 * lives.
 */
final class SearchEngineFactory
{
    public const string BACKEND_FULLTEXT = 'fulltext';
    public const string BACKEND_OPENSEARCH = 'opensearch';

    public static function create(
        string $backend,
        SearchEngineInterface $fulltext,
        SearchEngineInterface $openSearch,
    ): SearchEngineInterface {
        return match (mb_strtolower(trim($backend))) {
            self::BACKEND_FULLTEXT => $fulltext,
            self::BACKEND_OPENSEARCH => $openSearch,
            // A typo must not silently fall back to the default: the operator
            // asked for a specific backend and would otherwise never learn the
            // switch did nothing.
            default => throw self::unknownBackend($backend),
        };
    }

    private static function unknownBackend(string $backend): InvalidArgumentException
    {
        return new InvalidArgumentException(sprintf(
            'Unknown search backend "%s". Set SEARCH_ENGINE_BACKEND to "%s" or "%s".',
            $backend,
            self::BACKEND_FULLTEXT,
            self::BACKEND_OPENSEARCH,
        ));
    }
}
