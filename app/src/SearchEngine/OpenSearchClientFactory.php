<?php

declare(strict_types=1);

namespace App\SearchEngine;

use OpenSearch\Client;
use OpenSearch\ClientBuilder;

/**
 * HMAI-360 (epic HMAI-359): builds the app-data search engine client.
 *
 * The engine is a dedicated OpenSearch instance (see docker-compose `search`),
 * separate from the Graylog log store. opensearch-php talks to it over its own
 * ringphp/curl transport — NOT a Symfony HttpClientInterface — so the client is
 * deliberately not wrapped in {@see \App\Http\RateLimitedHttpClient}: it is
 * internal single-node traffic with no external quota (the Google Calendar SDK
 * precedent). The build is lazy — no connection is opened until the first
 * request — so constructing the service costs nothing when the engine is down.
 *
 * Short per-request timeouts keep a probe (e.g. the /api/health search check)
 * from hanging when the engine is unreachable.
 */
final class OpenSearchClientFactory
{
    public static function create(string $dsn): Client
    {
        return ClientBuilder::create()
            ->setHosts([$dsn])
            ->setConnectionParams(['client' => ['timeout' => 2, 'connect_timeout' => 1]])
            ->build();
    }
}
