<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Scheduled;

use App\Application\Scheduled\BackupDatabase;
use App\Application\Scheduled\BackupDatabaseHandler;
use App\Infrastructure\Backup\DatabaseBackupService;
use App\Infrastructure\Backup\Destination\BackupDestinationInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

final class BackupDatabaseHandlerTest extends TestCase
{
    public function testHandlerDumpsPrunesAndCopiesOffHost(): void
    {
        $service = $this->createMock(DatabaseBackupService::class);
        $service->expects(self::once())->method('backup')->willReturn('/backups/homemanager-2026-08-12.sql.gz.enc');
        $service->expects(self::once())->method('cleanup');
        $service->expects(self::once())->method('pushOffsite')->with('/backups/homemanager-2026-08-12.sql.gz.enc');

        (new BackupDatabaseHandler($service, new NullLogger()))(new BackupDatabase());
    }

    /**
     * An unreachable destination must not cost the local backup.
     *
     * Letting this propagate would send the message back through the retry chain
     * and re-dump the whole database three more times over a failure that had
     * nothing to do with the database — and then park it in the dead-letter
     * queue, where the alert would be about a failed message rather than about
     * backups no longer leaving the machine.
     */
    public function testAFailedOffHostCopyDoesNotFailTheBackup(): void
    {
        $service = $this->createMock(DatabaseBackupService::class);
        $service->method('backup')->willReturn('/backups/homemanager-2026-08-12.sql.gz.enc');
        // Local retention still has to have run — it is ordered before the
        // upload precisely so a remote outage cannot cost the local backups.
        $service->expects(self::once())->method('cleanup');
        $service->method('pushOffsite')->willThrowException(new RuntimeException('mount is gone'));
        $service->method('destination')->willReturn($this->createStub(BackupDestinationInterface::class));

        (new BackupDatabaseHandler($service, new NullLogger()))(new BackupDatabase());
    }

    /**
     * Not propagating is not the same as not reporting. The upload failure has to
     * leave a trace at error level, because that log line is what an operator
     * lands on after BackupOffsiteProbe mails them about a copy that stopped
     * arriving — it is where the reason lives.
     */
    public function testAFailedOffHostCopyIsLoggedAsAnError(): void
    {
        // A stub, not a mock: the assertion here is entirely about what the
        // logger receives, and the service is only scenery.
        $service = $this->createStub(DatabaseBackupService::class);
        $service->method('backup')->willReturn('/backups/homemanager-2026-08-12.sql.gz.enc');
        $service->method('pushOffsite')->willThrowException(new RuntimeException('mount is gone'));
        $service->method('destination')->willReturn($this->createStub(BackupDestinationInterface::class));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('error')
            ->with(
                'Off-host backup copy failed',
                self::callback(static fn (array $context): bool => 'mount is gone' === ($context['error'] ?? null)),
            );

        (new BackupDatabaseHandler($service, $logger))(new BackupDatabase());
    }
}
