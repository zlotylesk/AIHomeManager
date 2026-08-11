<?php

declare(strict_types=1);

namespace App\Tests\Unit\Monitoring;

use App\Monitoring\AlertNotice;
use App\Monitoring\AlertSeverity;
use App\Monitoring\AlertTransition;
use App\Monitoring\EmailAlertChannel;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;

final class EmailAlertChannelTest extends TestCase
{
    public function testFiringMailNamesTheSeverityAndCarriesTheRunbookDetail(): void
    {
        $email = $this->sendAndCapture($this->notice(AlertTransition::FIRING, AlertSeverity::CRITICAL));

        self::assertSame('[AIHM] CRITICAL — Health component "mysql" is down', $email->getSubject());
        self::assertSame('ops@aihm.test', $email->getFrom()[0]->getAddress());
        self::assertSame('owner@aihm.test', $email->getTo()[0]->getAddress());

        $body = (string) $email->getTextBody();
        self::assertStringContainsString('health:mysql', $body);
        self::assertStringContainsString('critical', $body);
        self::assertStringContainsString('check the container', $body);
        self::assertStringContainsString('docs/operations.md', $body, 'An alert without a next step is only half a message.');
    }

    public function testEscalationSaysSoInTheSubject(): void
    {
        $email = $this->sendAndCapture($this->notice(AlertTransition::ESCALATED, AlertSeverity::CRITICAL));

        self::assertSame('[AIHM] ESCALATED to CRITICAL — Health component "mysql" is down', $email->getSubject());
    }

    public function testRecoveryIsAnnouncedWithHowLongItWasBroken(): void
    {
        $email = $this->sendAndCapture(new AlertNotice(
            key: 'health:mysql',
            transition: AlertTransition::RESOLVED,
            severity: AlertSeverity::CRITICAL,
            title: 'Health component "mysql" is down',
            detail: '',
            at: new DateTimeImmutable('2026-08-11 11:30:00+02:00'),
            since: new DateTimeImmutable('2026-08-11 09:00:00+02:00'),
        ));

        self::assertSame('[AIHM] RESOLVED — Health component "mysql" is down', $email->getSubject());
        self::assertStringContainsString('Back to normal after 2h 30m', (string) $email->getTextBody());
    }

    public function testRecoveryAfterMoreThanADayCountsTheDays(): void
    {
        $email = $this->sendAndCapture(new AlertNotice(
            key: 'backup:stale',
            transition: AlertTransition::RESOLVED,
            severity: AlertSeverity::CRITICAL,
            title: 'The newest database backup is 172 h old',
            detail: '',
            at: new DateTimeImmutable('2026-08-13 12:05:00+02:00'),
            since: new DateTimeImmutable('2026-08-11 09:00:00+02:00'),
        ));

        self::assertStringContainsString('Back to normal after 2d 3h 5m', (string) $email->getTextBody());
    }

    public function testATransportFailureIsReportedByThrowing(): void
    {
        $mailer = $this->createStub(MailerInterface::class);
        $mailer->method('send')->willThrowException(new TransportException('smtp refused the connection'));

        $channel = new EmailAlertChannel($mailer, 'ops@aihm.test', 'owner@aihm.test');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/smtp refused the connection/');

        $channel->send($this->notice(AlertTransition::FIRING, AlertSeverity::CRITICAL));
    }

    private function sendAndCapture(AlertNotice $notice): Email
    {
        $sent = null;
        $mailer = $this->createStub(MailerInterface::class);
        $mailer->method('send')->willReturnCallback(static function (RawMessage $message) use (&$sent): void {
            $sent = $message;
        });

        new EmailAlertChannel($mailer, 'ops@aihm.test', 'owner@aihm.test')->send($notice);

        self::assertInstanceOf(Email::class, $sent);

        return $sent;
    }

    private function notice(AlertTransition $transition, AlertSeverity $severity): AlertNotice
    {
        return new AlertNotice(
            key: 'health:mysql',
            transition: $transition,
            severity: $severity,
            title: 'Health component "mysql" is down',
            detail: 'The database is unreachable. check the container',
            at: new DateTimeImmutable('2026-08-11 09:05:00+02:00'),
            since: new DateTimeImmutable('2026-08-11 09:00:00+02:00'),
        );
    }
}
