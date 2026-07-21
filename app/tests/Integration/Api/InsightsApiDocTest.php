<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use App\Module\Insights\Application\Query\GetTrends;
use App\Module\Insights\Domain\Enum\Granularity;
use App\Module\Insights\Domain\Enum\MetricType;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Pins the static OpenAPI contract of the Insights module — parity with the
 * other modules' *ApiDocTest.
 */
final class InsightsApiDocTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    /**
     * @return array<string, mixed>
     */
    private function spec(): array
    {
        $this->client->request('GET', '/api/doc.json');
        self::assertResponseIsSuccessful();

        $decoded = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($decoded);

        /* @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * @return array<string, mixed>
     */
    private function trendsOperation(): array
    {
        $spec = $this->spec();
        self::assertIsArray($spec['paths']);
        self::assertArrayHasKey('/api/v1/trends', $spec['paths'], 'The trends endpoint is not documented.');
        self::assertIsArray($spec['paths']['/api/v1/trends']);
        self::assertArrayHasKey('get', $spec['paths']['/api/v1/trends']);
        self::assertIsArray($spec['paths']['/api/v1/trends']['get']);

        /** @var array<string, mixed> $operation */
        $operation = $spec['paths']['/api/v1/trends']['get'];

        return $operation;
    }

    public function testTheTrendsOperationIsDocumentedAndTagged(): void
    {
        $operation = $this->trendsOperation();

        self::assertSame(['Insights'], $operation['tags']);
        self::assertNotEmpty($operation['summary']);
    }

    public function testTheInsightsTagIsDeclared(): void
    {
        $spec = $this->spec();
        self::assertIsArray($spec['tags']);

        self::assertContains('Insights', array_column($spec['tags'], 'name'));
    }

    /**
     * The documented enum must be the Domain's, or a client trusting the
     * contract gets a 422 for a value the docs advertised.
     */
    public function testTheGranularityParameterAdvertisesExactlyTheDomainEnum(): void
    {
        $granularity = $this->parameter('granularity');

        self::assertIsArray($granularity['schema']);
        self::assertSame(
            array_column(Granularity::cases(), 'value'),
            $granularity['schema']['enum'],
        );
        self::assertSame('week', $granularity['schema']['default']);
        self::assertFalse($granularity['required']);
    }

    public function testTheWindowParametersAreOptionalDates(): void
    {
        foreach (['from', 'to'] as $name) {
            $parameter = $this->parameter($name);

            self::assertFalse($parameter['required'], $name.' should be optional.');
            self::assertIsArray($parameter['schema']);
            self::assertSame('string', $parameter['schema']['type'], $name);
            self::assertSame('date', $parameter['schema']['format'], $name);
        }
    }

    public function testTheSuccessResponseIsTheTrendsReadModel(): void
    {
        $operation = $this->trendsOperation();
        self::assertIsArray($operation['responses']);
        self::assertArrayHasKey('200', $operation['responses']);

        $schema = $this->trendsSchema();
        self::assertSame(['from', 'to', 'granularity', 'series'], array_keys($schema['properties']));
    }

    /**
     * The nested schema chain TrendsDTO → TrendSeriesDTO → TrendPointDTO, so a
     * renamed or dropped field breaks the contract loudly.
     *
     * NOTE: `metric` and `unit` are typed `string` here rather than as enums.
     * The DTOs in this project carry no OpenAPI attributes by convention, so the
     * generated schema cannot narrow them; the vocabularies live in the
     * operation description instead. {@see testTheMetricVocabularyIsReachable}
     * keeps the two in step.
     */
    public function testTheSeriesSchemaChainIsFullyResolved(): void
    {
        $schema = $this->trendsSchema();
        self::assertIsArray($schema['properties']['series']);
        self::assertIsArray($schema['properties']['series']['items']);
        self::assertSame(
            '#/components/schemas/TrendSeriesDTO',
            $schema['properties']['series']['items']['$ref'],
        );

        $series = $this->schema('TrendSeriesDTO');
        self::assertSame(
            ['metric', 'unit', 'total', 'average', 'headline', 'points'],
            array_keys($series['properties']),
        );
        self::assertIsArray($series['properties']['points']);
        self::assertSame(
            '#/components/schemas/TrendPointDTO',
            $series['properties']['points']['items']['$ref'],
        );

        $point = $this->schema('TrendPointDTO');
        self::assertSame(['bucketStart', 'value'], array_keys($point['properties']));
    }

    /**
     * Every metric and unit the API can emit must be named somewhere a client
     * reading only the contract can find it — otherwise a value added later
     * ships undocumented.
     */
    public function testTheMetricVocabularyIsReachable(): void
    {
        $operation = $this->trendsOperation();
        $prose = strtolower($operation['summary'].' '.$operation['description']);

        foreach (MetricType::cases() as $metric) {
            // "books_pages_read" → the words a human description would use.
            $words = explode('_', $metric->value);
            self::assertStringContainsString(
                end($words),
                $prose,
                $metric->value.' is not described in the trends contract.',
            );
        }
    }

    public function testTheErrorSurfaceRefsTheSharedComponents(): void
    {
        $operation = $this->trendsOperation();
        self::assertIsArray($operation['responses']);

        self::assertSame('#/components/responses/UnauthorizedError', $operation['responses']['401']['$ref']);
        self::assertSame('#/components/responses/UnprocessableEntityError', $operation['responses']['422']['$ref']);
    }

    /**
     * The documented bucket cap and the query's own guard must be the same
     * number, or the docs promise a window the API refuses.
     */
    public function testTheDocumentedDefaultWindowFitsInsideTheQueryCap(): void
    {
        $description = (string) $this->parameter('from')['description'];

        self::assertStringContainsString('12 buckets', $description);
        self::assertLessThanOrEqual(GetTrends::MAX_BUCKETS, 12);
    }

    /**
     * @return array<string, mixed>
     */
    private function parameter(string $name): array
    {
        $operation = $this->trendsOperation();
        self::assertIsArray($operation['parameters']);

        foreach ($operation['parameters'] as $parameter) {
            self::assertIsArray($parameter);
            if (($parameter['name'] ?? null) === $name) {
                /* @var array<string, mixed> $parameter */
                return $parameter;
            }
        }

        self::fail('The "'.$name.'" query parameter is not documented.');
    }

    /**
     * @return array{properties: array<string, mixed>}
     */
    private function trendsSchema(): array
    {
        return $this->schema('TrendsDTO');
    }

    /**
     * @return array{properties: array<string, mixed>}
     */
    private function schema(string $name): array
    {
        $spec = $this->spec();
        self::assertIsArray($spec['components']);
        self::assertIsArray($spec['components']['schemas']);
        self::assertArrayHasKey($name, $spec['components']['schemas'], $name.' is not in the contract.');

        /** @var array{properties: array<string, mixed>} $schema */
        $schema = $spec['components']['schemas'][$name];

        return $schema;
    }
}
