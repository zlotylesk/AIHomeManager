<?php

declare(strict_types=1);

namespace App\Tests\Integration\Module\YouTubeProgress\Application;

use App\Module\YouTubeProgress\Application\Command\PushSessionToYouTube;
use App\Module\YouTubeProgress\Application\Handler\PushSessionToYouTubeHandler;
use App\Module\YouTubeProgress\Domain\Entity\WatchSession;
use App\Module\YouTubeProgress\Domain\Port\YouTubePlaylistWriterInterface;
use App\Module\YouTubeProgress\Domain\ValueObject\YoutubeVideoId;
use App\Module\YouTubeProgress\Infrastructure\Persistence\DoctrineWatchSessionRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\AbstractLogger;
use RuntimeException;
use Stringable;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class PushSessionToYouTubeHandlerTest extends KernelTestCase
{
    private const string MISSING_SESSION_ID = '11111111-1111-4111-8111-111111111111';

    private DoctrineWatchSessionRepository $sessions;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->sessions = new DoctrineWatchSessionRepository($em->getConnection());

        $this->sessions->deleteAll();
    }

    public function testPushCreatesPlaylistAndAddsVideos(): void
    {
        $session = $this->persistSession(['vid00000001', 'vid00000002', 'vid00000003'], '2026-06-04 21:35:00');
        $writer = $this->fakeWriter();

        $this->handler($writer)(new PushSessionToYouTube($session->id()->toString()));

        self::assertSame(1, $writer->createPlaylistCalls);
        self::assertSame(['AIHM Session 2026-06-04 21:35'], $writer->createPlaylistNames);
        self::assertSame(
            ['vid00000001', 'vid00000002', 'vid00000003'],
            array_map(static fn (YoutubeVideoId $v): string => $v->value(), $writer->addedVideoIds),
        );
    }

    public function testPushNamesPlaylistWithSessionTimestamp(): void
    {
        $session = $this->persistSession(['vid00000001'], '2026-06-04 21:35:00');
        $writer = $this->fakeWriter();

        $this->handler($writer)(new PushSessionToYouTube($session->id()->toString()));

        self::assertSame('AIHM Session 2026-06-04 21:35', $writer->createPlaylistNames[0]);
    }

    public function testPushMarksSessionAsPushedAfterSuccess(): void
    {
        $session = $this->persistSession(['vid00000001', 'vid00000002'], '2026-06-04 21:35:00');
        $writer = $this->fakeWriter();
        $writer->returnPlaylistId = 'PLgenerated123';

        $this->handler($writer)(new PushSessionToYouTube($session->id()->toString()));

        $reloaded = $this->sessions->findById($session->id());
        self::assertNotNull($reloaded);
        self::assertTrue($reloaded->isPushedToYouTube());
        self::assertSame('PLgenerated123', $reloaded->youtubePlaylistId());
    }

    public function testPushIsNoOpForAlreadyPushedSession(): void
    {
        $session = $this->persistSession(['vid00000001'], '2026-06-04 21:35:00', 'PLalreadyPushed');
        $writer = $this->fakeWriter();
        $logger = new RecordingLogger();

        $this->handler($writer, $logger)(new PushSessionToYouTube($session->id()->toString()));

        self::assertSame(0, $writer->createPlaylistCalls);
        self::assertSame(0, $writer->addVideosCalls);
        self::assertContains('warning', array_column($logger->records, 'level'));
    }

    public function testPushThrows404WhenSessionNotFound(): void
    {
        $this->expectException(NotFoundHttpException::class);

        $this->handler($this->fakeWriter())(new PushSessionToYouTube(self::MISSING_SESSION_ID));
    }

    public function testPushRollbackOnWriterFailure(): void
    {
        $session = $this->persistSession(['vid00000001', 'vid00000002'], '2026-06-04 21:35:00');
        $writer = $this->fakeWriter();
        $writer->throwOnAddVideos = new RuntimeException('youtube boom');

        try {
            $this->handler($writer)(new PushSessionToYouTube($session->id()->toString()));
            self::fail('Expected RuntimeException to bubble up');
        } catch (RuntimeException $e) {
            self::assertSame('youtube boom', $e->getMessage());
        }

        // The playlist was created on YouTube, but the DB write never ran — the
        // session must stay unmarked so a retry is safe.
        self::assertSame(1, $writer->createPlaylistCalls);
        $reloaded = $this->sessions->findById($session->id());
        self::assertNotNull($reloaded);
        self::assertFalse($reloaded->isPushedToYouTube());
        self::assertNull($reloaded->youtubePlaylistId());
    }

    /**
     * @param list<string> $videoIdStrings
     */
    private function persistSession(array $videoIdStrings, string $createdAt, ?string $playlistId = null): WatchSession
    {
        $videoIds = array_map(static fn (string $id): YoutubeVideoId => new YoutubeVideoId($id), $videoIdStrings);
        $session = WatchSession::create($videoIds, 600, new DateTimeImmutable($createdAt));

        if (null !== $playlistId) {
            $session->markPushedToYouTube($playlistId);
        }

        $this->sessions->save($session);

        return $session;
    }

    private function handler(YouTubePlaylistWriterInterface $writer, ?AbstractLogger $logger = null): PushSessionToYouTubeHandler
    {
        return new PushSessionToYouTubeHandler($this->sessions, $writer, $logger ?? new RecordingLogger());
    }

    private function fakeWriter(): FakePlaylistWriter
    {
        return new FakePlaylistWriter();
    }
}

/**
 * In-memory YouTube playlist writer test double — records calls and can be made
 * to fail on addVideosToPlaylist to exercise the no-rollback path.
 */
final class FakePlaylistWriter implements YouTubePlaylistWriterInterface
{
    public int $createPlaylistCalls = 0;
    public int $addVideosCalls = 0;
    /** @var list<string> */
    public array $createPlaylistNames = [];
    /** @var list<YoutubeVideoId> */
    public array $addedVideoIds = [];
    public string $returnPlaylistId = 'PLfake000000';
    public ?RuntimeException $throwOnAddVideos = null;

    public function createPlaylist(string $name, bool $private = true): string
    {
        ++$this->createPlaylistCalls;
        $this->createPlaylistNames[] = $name;

        return $this->returnPlaylistId;
    }

    public function addVideosToPlaylist(string $playlistId, array $videoIds): void
    {
        ++$this->addVideosCalls;

        if (null !== $this->throwOnAddVideos) {
            throw $this->throwOnAddVideos;
        }

        $this->addedVideoIds = $videoIds;
    }
}

/**
 * Minimal PSR-3 logger spy — keeps a flat list of {level, message} records so
 * tests can assert that a warning was emitted on the no-op path.
 */
final class RecordingLogger extends AbstractLogger
{
    /** @var list<array{level: string, message: string}> */
    public array $records = [];

    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->records[] = ['level' => (string) $level, 'message' => (string) $message];
    }
}
