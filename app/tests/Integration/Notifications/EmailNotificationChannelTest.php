<?php

declare(strict_types=1);

namespace App\Tests\Integration\Notifications;

use App\Module\Notifications\Domain\Entity\Notification;
use App\Module\Notifications\Domain\Enum\Channel;
use App\Module\Notifications\Domain\Enum\NotificationType;
use App\Module\Notifications\Infrastructure\Channel\EmailNotificationChannel;
use DateTimeImmutable;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Bundle\FrameworkBundle\Test\MailerAssertionsTrait;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Twig\Environment;

final class EmailNotificationChannelTest extends KernelTestCase
{
    use MailerAssertionsTrait;

    private EmailNotificationChannel $channel;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->channel = static::getContainer()->get(EmailNotificationChannel::class);
    }

    public function testDeliversTheEmailChannel(): void
    {
        self::assertSame(Channel::EMAIL, $this->channel->channel());
    }

    public function testRendersTheTaskDueTemplateAndSends(): void
    {
        $this->channel->send($this->notification(NotificationType::TASK_DUE, [
            'title' => 'Zapłacić czynsz',
            'dueAt' => '2026-07-16 18:00',
            'url' => 'https://aihm.local/tasks/42',
        ]));

        self::assertEmailCount(1);
        $email = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $email);

        self::assertSame('Zbliża się termin: Zapłacić czynsz', $email->getSubject());
        self::assertStringContainsString('Zapłacić czynsz', (string) $email->getHtmlBody());
        self::assertStringContainsString('2026-07-16 18:00', (string) $email->getHtmlBody());
        self::assertStringContainsString('https://aihm.local/tasks/42', (string) $email->getHtmlBody());
    }

    public function testSenderAndRecipientComeFromConfiguration(): void
    {
        $this->channel->send($this->notification(NotificationType::DAILY_DIGEST));

        $email = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $email);
        self::assertSame('aihm@localhost', $email->getFrom()[0]->getAddress());
        self::assertSame('owner@localhost', $email->getTo()[0]->getAddress());
    }

    /**
     * A type without a template would only blow up the first time that type is
     * actually announced, so every case is rendered here instead.
     */
    public function testEveryNotificationTypeRendersASubjectAndBody(): void
    {
        foreach (NotificationType::cases() as $type) {
            $this->channel->send($this->notification($type, ['title' => 'Tytuł']));
        }

        self::assertEmailCount(\count(NotificationType::cases()));

        foreach (self::getMailerMessages() as $email) {
            self::assertInstanceOf(Email::class, $email);
            self::assertNotSame('', trim((string) $email->getSubject()));
            self::assertNotSame('', trim((string) $email->getHtmlBody()));
        }
    }

    /**
     * Payload URLs can originate from user-entered data (an imported article's
     * own address), so anything outside http(s) must not become a live link.
     */
    public function testOnlyHttpUrlsAreLinked(): void
    {
        $this->channel->send($this->notification(NotificationType::ARTICLE_DAILY, [
            'title' => 'Podejrzany artykuł',
            'url' => 'javascript:alert(1)',
        ]));

        $email = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $email);
        self::assertStringNotContainsString('javascript:', (string) $email->getHtmlBody());
        self::assertStringNotContainsString('<a href', (string) $email->getHtmlBody());
    }

    /**
     * Payload keys are per-trigger and optional; with strict_variables on in the
     * test env a template that assumed one would fail here.
     */
    public function testAnEmptyPayloadStillRenders(): void
    {
        foreach (NotificationType::cases() as $type) {
            $this->channel->send($this->notification($type));
        }

        self::assertEmailCount(\count(NotificationType::cases()));
    }

    public function testATransportFailureIsReportedByThrowing(): void
    {
        $mailer = $this->createStub(MailerInterface::class);
        $mailer->method('send')->willThrowException(new TransportException('SMTP refused the message'));

        $twig = static::getContainer()->get(Environment::class);

        $channel = new EmailNotificationChannel($mailer, $twig, 'aihm@localhost', 'owner@localhost');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/SMTP refused the message/');

        $channel->send($this->notification(NotificationType::TASK_DUE, ['title' => 'Zapłacić czynsz']));
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function notification(NotificationType $type, array $payload = []): Notification
    {
        return new Notification(
            'n0000009-0000-0000-0000-000000000001',
            $type,
            Channel::EMAIL,
            $payload,
            sprintf('%s:subject:2026-07-19:email', $type->value),
            new DateTimeImmutable('2026-07-19 08:15:00'),
        );
    }
}
