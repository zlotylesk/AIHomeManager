<?php

declare(strict_types=1);

namespace App\Tests\Unit\Monitoring;

use App\Monitoring\Alert;
use App\Monitoring\AlertSeverity;
use App\Monitoring\SystemMonitor;
use App\Tests\Support\Monitoring\FakeAlertProbe;
use App\Tests\Support\Monitoring\InMemoryAlertStateStore;
use App\Tests\Support\Monitoring\RecordingAlertChannel;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class SystemMonitorTest extends TestCase
{
    private FakeAlertProbe $probe;
    private RecordingAlertChannel $channel;
    private InMemoryAlertStateStore $state;
    private SystemMonitor $monitor;

    protected function setUp(): void
    {
        $this->probe = new FakeAlertProbe('health');
        $this->channel = new RecordingAlertChannel();
        $this->state = new InMemoryAlertStateStore();
        $this->monitor = new SystemMonitor([$this->probe], [$this->channel], $this->state, new NullLogger());
    }

    public function testAnnouncesANewFailureOnce(): void
    {
        $this->probe->reports($this->alert('mysql', AlertSeverity::CRITICAL));

        $this->monitor->run($this->at('09:00'));
        $this->monitor->run($this->at('09:05'));
        $this->monitor->run($this->at('09:10'));

        self::assertSame(['health:mysql'], $this->channel->sentKeys(), 'A failure that is still failing must not be announced again — it is the same outage.');
        self::assertSame(['firing'], $this->channel->sentTransitions());
    }

    public function testNamespacesKeysByProbeSoTwoSourcesCannotCollide(): void
    {
        $other = new FakeAlertProbe('backup');
        $monitor = new SystemMonitor([$this->probe, $other], [$this->channel], $this->state, new NullLogger());

        $this->probe->reports($this->alert('stale', AlertSeverity::CRITICAL));
        $other->reports($this->alert('stale', AlertSeverity::CRITICAL));

        $monitor->run($this->at('09:00'));

        self::assertSame(['health:stale', 'backup:stale'], $this->channel->sentKeys());
    }

    public function testAnnouncesAgainWhenTheSameFailureGetsWorse(): void
    {
        $this->probe->reports($this->alert('disk', AlertSeverity::WARNING));
        $this->monitor->run($this->at('09:00'));

        $this->probe->reports($this->alert('disk', AlertSeverity::CRITICAL));
        $this->monitor->run($this->at('09:05'));

        self::assertSame(['firing', 'escalated'], $this->channel->sentTransitions(), 'A disk at 82 % and a disk at 96 % are different situations.');
        self::assertSame(AlertSeverity::CRITICAL, $this->channel->sent[1]->severity);
    }

    public function testEscalationKeepsTheOriginalStartTime(): void
    {
        $this->probe->reports($this->alert('disk', AlertSeverity::WARNING));
        $this->monitor->run($this->at('09:00'));

        $this->probe->reports($this->alert('disk', AlertSeverity::CRITICAL));
        $this->monitor->run($this->at('09:45'));

        self::assertSame('09:00', $this->channel->sent[1]->since->format('H:i'), 'The outage started when it started; escalating is not a new incident.');
    }

    public function testStaysSilentWhenTheSameFailureGetsLessSevere(): void
    {
        $this->probe->reports($this->alert('disk', AlertSeverity::CRITICAL));
        $this->monitor->run($this->at('09:00'));

        $this->probe->reports($this->alert('disk', AlertSeverity::WARNING));
        $this->monitor->run($this->at('09:05'));

        self::assertSame(['firing'], $this->channel->sentTransitions(), 'Still broken is not news; only recovery and deterioration are.');
    }

    public function testAnnouncesRecoveryAndThenGoesQuiet(): void
    {
        $this->probe->reports($this->alert('mysql', AlertSeverity::CRITICAL));
        $this->monitor->run($this->at('09:00'));

        $this->probe->reports();
        $this->monitor->run($this->at('09:30'));
        $this->monitor->run($this->at('09:35'));

        self::assertSame(['firing', 'resolved'], $this->channel->sentTransitions());
        self::assertSame([], $this->state->load(), 'A recovered alert must leave the state, or it can never fire again.');
    }

    public function testRecoveryDescribesTheLatestReadingNotTheFirstOne(): void
    {
        $this->probe->reports(new Alert('backup', AlertSeverity::CRITICAL, 'the backup is 26 h old', ''));
        $this->monitor->run($this->at('09:00'));

        $this->probe->reports(new Alert('backup', AlertSeverity::CRITICAL, 'the backup is 340 h old', ''));
        $this->monitor->run($this->at('09:05'));

        $this->probe->reports();
        $this->monitor->run($this->at('09:10'));

        self::assertSame(['firing', 'resolved'], $this->channel->sentTransitions(), 'The middle sweep said nothing — it was the same outage at the same severity.');
        self::assertSame('the backup is 340 h old', $this->channel->sent[1]->title, 'Resolving with the first reading would understate how bad it got.');
    }

    public function testRecoveryReportsHowLongItWasBroken(): void
    {
        $this->probe->reports($this->alert('mysql', AlertSeverity::CRITICAL));
        $this->monitor->run($this->at('09:00'));

        $this->probe->reports();
        $this->monitor->run($this->at('11:30'));

        self::assertSame('09:00', $this->channel->sent[1]->since->format('H:i'));
        self::assertSame('11:30', $this->channel->sent[1]->at->format('H:i'));
    }

    public function testTheSameFailureCanFireAgainAfterRecovering(): void
    {
        $this->probe->reports($this->alert('mysql', AlertSeverity::CRITICAL));
        $this->monitor->run($this->at('09:00'));

        $this->probe->reports();
        $this->monitor->run($this->at('09:30'));

        $this->probe->reports($this->alert('mysql', AlertSeverity::CRITICAL));
        $this->monitor->run($this->at('10:00'));

        self::assertSame(['firing', 'resolved', 'firing'], $this->channel->sentTransitions());
    }

    public function testAnUndeliveredAlertIsRetriedRatherThanRecordedAsAnnounced(): void
    {
        $this->probe->reports($this->alert('mysql', AlertSeverity::CRITICAL));

        $this->channel->rejectEverything();
        $this->monitor->run($this->at('09:00'));

        self::assertSame([], $this->state->load(), 'Nothing was delivered, so nothing was announced.');

        $this->channel->acceptEverything();
        $this->monitor->run($this->at('09:05'));

        self::assertSame(['firing'], $this->channel->sentTransitions(), 'The alert must survive a mail outage rather than being silently swallowed.');
    }

    public function testAnUndeliveredRecoveryIsRetried(): void
    {
        $this->probe->reports($this->alert('mysql', AlertSeverity::CRITICAL));
        $this->monitor->run($this->at('09:00'));

        $this->probe->reports();
        $this->channel->rejectEverything();
        $this->monitor->run($this->at('09:30'));

        self::assertArrayHasKey('health:mysql', $this->state->load());

        $this->channel->acceptEverything();
        $this->monitor->run($this->at('09:35'));

        self::assertSame(['firing', 'resolved'], $this->channel->sentTransitions());
    }

    public function testOneRefusingChannelDoesNotStopAnother(): void
    {
        $broken = new RecordingAlertChannel();
        $broken->rejectEverything();
        $monitor = new SystemMonitor([$this->probe], [$broken, $this->channel], $this->state, new NullLogger());

        $this->probe->reports($this->alert('mysql', AlertSeverity::CRITICAL));
        $monitor->run($this->at('09:00'));

        self::assertSame(['health:mysql'], $this->channel->sentKeys());
    }

    public function testAProbeThatThrowsBecomesAnAlertOfItsOwn(): void
    {
        $this->probe->breaksWith('cannot reach anything');

        $this->monitor->run($this->at('09:00'));

        self::assertSame(['probe:health'], $this->channel->sentKeys(), 'A monitor that quietly stops monitoring is the exact failure this exists to break.');
        self::assertSame(AlertSeverity::CRITICAL, $this->channel->sent[0]->severity);
        self::assertStringContainsString('cannot reach anything', $this->channel->sent[0]->detail);
    }

    public function testAProbeThatThrowsDoesNotReportItsStandingAlertsAsRecovered(): void
    {
        $this->probe->reports($this->alert('mysql', AlertSeverity::CRITICAL));
        $this->monitor->run($this->at('09:00'));
        $this->channel->forget();

        $this->probe->breaksWith('cannot reach anything');
        $this->monitor->run($this->at('09:05'));

        self::assertSame(['probe:health'], $this->channel->sentKeys(), 'A blind probe reports nothing wrong, which is not the same as nothing being wrong.');
        self::assertArrayHasKey('health:mysql', $this->state->load());
    }

    public function testStandingAlertsResolveOnceTheProbeWorksAgain(): void
    {
        $this->probe->reports($this->alert('mysql', AlertSeverity::CRITICAL));
        $this->monitor->run($this->at('09:00'));

        $this->probe->breaksWith('cannot reach anything');
        $this->monitor->run($this->at('09:05'));
        $this->channel->forget();

        $this->probe->reports();
        $this->monitor->run($this->at('09:10'));

        self::assertSame(['probe:health', 'health:mysql'], $this->channel->sentKeys());
        self::assertSame(['resolved', 'resolved'], $this->channel->sentTransitions());
        self::assertSame([], $this->state->load());
    }

    public function testOneBrokenProbeDoesNotSilenceAnother(): void
    {
        $backup = new FakeAlertProbe('backup');
        $monitor = new SystemMonitor([$this->probe, $backup], [$this->channel], $this->state, new NullLogger());

        $this->probe->breaksWith('cannot reach anything');
        $backup->reports($this->alert('stale', AlertSeverity::CRITICAL));

        $monitor->run($this->at('09:00'));

        self::assertContains('backup:stale', $this->channel->sentKeys());
        self::assertContains('probe:health', $this->channel->sentKeys());
    }

    public function testABrokenProbeDoesNotHoldAnotherProbesRecovery(): void
    {
        $backup = new FakeAlertProbe('backup');
        $monitor = new SystemMonitor([$this->probe, $backup], [$this->channel], $this->state, new NullLogger());

        $backup->reports($this->alert('stale', AlertSeverity::CRITICAL));
        $monitor->run($this->at('09:00'));
        $this->channel->forget();

        $this->probe->breaksWith('cannot reach anything');
        $backup->reports();
        $monitor->run($this->at('09:05'));

        self::assertSame(['probe:health', 'backup:stale'], $this->channel->sentKeys());
        self::assertSame(['firing', 'resolved'], $this->channel->sentTransitions());
    }

    public function testSummaryNamesWhatWasAnnouncedAndWhatIsStillStanding(): void
    {
        $this->probe->reports(
            $this->alert('mysql', AlertSeverity::CRITICAL),
            $this->alert('disk', AlertSeverity::WARNING),
        );

        $summary = $this->monitor->run($this->at('09:00'));

        self::assertSame(['health:mysql', 'health:disk'], $summary->announced);
        self::assertSame(['health:mysql', 'health:disk'], $summary->standing);

        $this->probe->reports($this->alert('mysql', AlertSeverity::CRITICAL));
        $summary = $this->monitor->run($this->at('09:05'));

        self::assertSame(['health:disk'], $summary->announced);
        self::assertSame(['health:mysql'], $summary->standing);
    }

    private function alert(string $key, AlertSeverity $severity): Alert
    {
        return new Alert($key, $severity, sprintf('%s is unhappy', $key), 'do something about it');
    }

    private function at(string $time): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-08-11 '.$time.':00');
    }
}
