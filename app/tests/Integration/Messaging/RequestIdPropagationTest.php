<?php

declare(strict_types=1);

namespace App\Tests\Integration\Messaging;

use App\EventListener\RequestIdListener;
use App\Messaging\RequestIdStamp;
use App\Module\Goals\Application\Command\RecalculateStreaks;
use App\Module\Movies\Application\Command\ImportMovieRatingsFromTrakt;
use App\Module\Movies\Application\Command\ImportWatchedMoviesFromTrakt;
use App\Module\Movies\Domain\Port\MovieRatingsProviderInterface;
use App\Module\Movies\Domain\Port\WatchedMoviesProviderInterface;
use App\Shared\Security\TraktTokenProviderInterface;
use App\Tests\Support\AuthenticatedApiTrait;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Monolog\LogRecord;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * End-to-end cover for the request-id rail (HMAI-367): an id that entered over
 * HTTP must still be attached to a message the request offloaded to the worker,
 * and must reappear in the log lines the handler writes on the other side.
 *
 * The unit tests pin each part in isolation (stamp, both middlewares, the
 * processor fallback); this test wires them together through the real buses and
 * the real transport, because the value of the feature is precisely that the
 * parts meet — a correlator that survives the dispatch but is never read on the
 * worker would still pass every unit test.
 */
final class RequestIdPropagationTest extends WebTestCase
{
    use AuthenticatedApiTrait;

    /**
     * The sender rail, driven by a real request: the correlator the client sent
     * travels out of the HTTP boundary on the envelope of the command the
     * endpoint offloads.
     */
    public function testAnIdFromAnHttpRequestIsStampedOnTheDispatchedMessage(): void
    {
        $client = static::createClient();
        $this->authenticate($client);
        $client->disableReboot();

        // The endpoint refuses with 409 unless Trakt is connected; stubbing the
        // shared token port keeps the test on the dispatch path without OAuth.
        $token = $this->createStub(TraktTokenProviderInterface::class);
        $token->method('get')->willReturn(['access_token' => 'stub-token']);
        static::getContainer()->set(TraktTokenProviderInterface::class, $token);

        $client->request('POST', '/api/movies/import/trakt', server: ['HTTP_X-Request-ID' => 'http-corr-77']);

        self::assertResponseStatusCodeSame(202);

        $stamp = $this->sentEnvelope(ImportWatchedMoviesFromTrakt::class)->last(RequestIdStamp::class);
        self::assertInstanceOf(RequestIdStamp::class, $stamp);
        self::assertSame('http-corr-77', $stamp->requestId);
    }

    /**
     * The receiver rail: handling a stamped envelope (what the worker does after
     * pulling it off the transport) puts the id into the logs the handler emits,
     * even though there is no HTTP request anywhere in sight.
     */
    public function testTheWorkerLogsCarryTheIdOfTheRequestThatDispatchedTheMessage(): void
    {
        self::bootKernel();
        $this->givenNoTraktRatings();
        $records = $this->captureLogs();

        $this->handleAsWorker(new ImportMovieRatingsFromTrakt(), new RequestIdStamp('worker-corr-9'));

        self::assertSame('worker-corr-9', $this->importLogRecord($records)->extra['request_id'] ?? null);
    }

    /**
     * A message nobody dispatched from a request — the nightly scheduler sweep —
     * carries no stamp, and must log exactly as before rather than inheriting a
     * stale correlator from whatever ran previously in the same worker process.
     */
    public function testAMessageWithoutAStampLogsWithoutACorrelator(): void
    {
        self::bootKernel();
        $this->givenNoTraktRatings();
        $records = $this->captureLogs();

        $this->handleAsWorker(new ImportMovieRatingsFromTrakt());

        self::assertArrayNotHasKey('request_id', $this->importLogRecord($records)->extra);
    }

    /**
     * The trail survives a hop: the watched-movies import chains the ratings
     * import from inside the worker handler, where there is no HTTP request to
     * read the correlator from. Both messages belong to the same user action, so
     * both must carry the same id.
     */
    public function testAMessageChainedByAWorkerHandlerKeepsTheSameCorrelator(): void
    {
        self::bootKernel();
        $this->givenNoWatchedMovies();

        $this->handleAsWorker(new ImportWatchedMoviesFromTrakt(), new RequestIdStamp('chained-corr-5'));

        $stamp = $this->sentEnvelope(ImportMovieRatingsFromTrakt::class)->last(RequestIdStamp::class);
        self::assertInstanceOf(RequestIdStamp::class, $stamp, 'The chained ratings import lost the correlation stamp');
        self::assertSame('chained-corr-5', $stamp->requestId);
    }

    /**
     * The stamping is a bus-wide middleware, not a per-message opt-in: every
     * message a request offloads is correlated, so a module added later inherits
     * the rail without touching it.
     */
    public function testEveryMessageDispatchedDuringARequestIsStamped(): void
    {
        self::bootKernel();
        $this->givenCurrentRequestId('bus-wide-3');

        $bus = $this->commandBus();
        $bus->dispatch(new ImportWatchedMoviesFromTrakt());
        $bus->dispatch(new RecalculateStreaks());

        foreach ([ImportWatchedMoviesFromTrakt::class, RecalculateStreaks::class] as $message) {
            $stamp = $this->sentEnvelope($message)->last(RequestIdStamp::class);
            self::assertInstanceOf(RequestIdStamp::class, $stamp, $message.' was dispatched without a correlation stamp');
            self::assertSame('bus-wide-3', $stamp->requestId);
        }
    }

    /**
     * Runs a message the way the worker does: the ReceivedStamp tells the sender
     * middleware the envelope already came off the transport, so the bus handles
     * it inline instead of re-sending it.
     */
    private function handleAsWorker(object $message, ?RequestIdStamp $stamp = null): void
    {
        $stamps = [new ReceivedStamp('async')];
        if (null !== $stamp) {
            $stamps[] = $stamp;
        }

        $this->commandBus()->dispatch(new Envelope($message, $stamps));
    }

    /**
     * The container's concrete types satisfy these return declarations, which is
     * what keeps the callers free of narrowing asserts.
     */
    private function commandBus(): MessageBusInterface
    {
        return static::getContainer()->get('command.bus');
    }

    private function givenNoTraktRatings(): void
    {
        $provider = $this->createStub(MovieRatingsProviderInterface::class);
        $provider->method('fetchMovieRatings')->willReturn([]);
        static::getContainer()->set(MovieRatingsProviderInterface::class, $provider);
    }

    private function givenNoWatchedMovies(): void
    {
        $provider = $this->createStub(WatchedMoviesProviderInterface::class);
        $provider->method('fetchWatchedMovies')->willReturn([]);
        static::getContainer()->set(WatchedMoviesProviderInterface::class, $provider);
    }

    private function givenCurrentRequestId(string $requestId): void
    {
        $request = new Request();
        $request->attributes->set(RequestIdListener::ATTRIBUTE_NAME, $requestId);

        $stack = static::getContainer()->get(RequestStack::class);
        $stack->push($request);
    }

    private function captureLogs(): TestHandler
    {
        /** @var Logger $logger MonologBundle aliases LoggerInterface to a Monolog Logger. */
        $logger = static::getContainer()->get(LoggerInterface::class);

        $handler = new TestHandler();
        $logger->pushHandler($handler);

        return $handler;
    }

    private function importLogRecord(TestHandler $handler): LogRecord
    {
        $record = array_find(
            $handler->getRecords(),
            static fn ($record) => 'Trakt movie ratings imported' === $record->message,
        );
        self::assertNotNull($record, 'The import handler did not run');

        return $record;
    }

    /**
     * @param class-string $messageClass
     */
    private function sentEnvelope(string $messageClass): Envelope
    {
        $transport = static::getContainer()->get('messenger.transport.async');
        \assert($transport instanceof InMemoryTransport);

        $envelope = array_find(
            $transport->getSent(),
            static fn (Envelope $envelope) => $envelope->getMessage() instanceof $messageClass,
        );
        self::assertNotNull($envelope, $messageClass.' was not routed to the async transport');

        return $envelope;
    }
}
