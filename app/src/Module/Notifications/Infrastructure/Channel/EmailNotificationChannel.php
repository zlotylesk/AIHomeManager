<?php

declare(strict_types=1);

namespace App\Module\Notifications\Infrastructure\Channel;

use App\Module\Notifications\Domain\Entity\Notification;
use App\Module\Notifications\Domain\Enum\Channel;
use App\Module\Notifications\Domain\Port\NotificationChannelInterface;
use RuntimeException;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Twig\Environment;
use Twig\Error\Error as TwigError;

/**
 * Delivers notifications over e-mail: renders the message from a per-type Twig
 * template and hands it to Symfony Mailer.
 *
 * The send is deliberately synchronous. Routing Mailer's own SendEmailMessage to
 * the async transport would make Mailer::send() return before the transport ran,
 * so a rejected message would surface in the worker's DLQ while the dispatch
 * engine had already recorded the notification as SENT. Asynchrony belongs one
 * level up instead — DispatchNotification is the async-routed command (see
 * messenger.yaml), which keeps the triggering path non-blocking *and* keeps the
 * transport failure observable here, where it can be reported by throwing.
 */
final readonly class EmailNotificationChannel implements NotificationChannelInterface
{
    private const string TEMPLATE_DIR = 'notifications/email';

    public function __construct(
        private MailerInterface $mailer,
        private Environment $twig,
        private string $from,
        private string $to,
    ) {
    }

    public function channel(): Channel
    {
        return Channel::EMAIL;
    }

    public function send(Notification $notification): void
    {
        $template = sprintf('%s/%s.html.twig', self::TEMPLATE_DIR, $notification->type()->value);
        $context = ['payload' => $notification->payload()];

        try {
            $rendered = $this->twig->load($template);
            $subject = trim($rendered->renderBlock('subject', $context));
            $html = $rendered->render($context);
        } catch (TwigError $error) {
            throw new RuntimeException(sprintf('Could not render the "%s" notification e-mail: %s', $template, $error->getMessage()), previous: $error);
        }

        $email = new Email()
            ->from($this->from)
            ->to($this->to)
            ->subject($subject)
            ->html($html);

        try {
            $this->mailer->send($email);
        } catch (TransportExceptionInterface $failure) {
            // Reported by throwing so the dispatch engine records the failure and
            // the next trigger retries this notification instead of losing it.
            throw new RuntimeException(sprintf('Could not send the notification e-mail: %s', $failure->getMessage()), previous: $failure);
        }
    }
}
