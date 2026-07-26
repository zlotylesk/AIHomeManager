<?php

declare(strict_types=1);

namespace App\Module\Search\Application\EventHandler;

use App\Module\Search\Application\Command\IndexSearchDocument;
use App\Shared\Search\AffectsSearchIndex;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * The whole incremental trigger: one handler, listening for the shared-kernel
 * {@see AffectsSearchIndex} interface rather than for any source module's event
 * class (HMAI-363, mirroring the Notifications rail of HMAI-281). Messenger
 * resolves handlers through a message's interfaces, so a module opting an event
 * in is picked up without a line of wiring — and Search imports no Tasks class.
 *
 * Nothing is decided here beyond "did anything searchable change". What the
 * document should now contain, and whether it should exist at all, is read from
 * the source by the command's handler — which is also why this only translates
 * and forwards.
 */
#[AsMessageHandler]
final readonly class ReindexAffectedDocument
{
    public function __construct(private MessageBusInterface $commandBus)
    {
    }

    public function __invoke(AffectsSearchIndex $event): void
    {
        $ref = $event->toSearchDocumentRef();

        if (null === $ref) {
            return;
        }

        $this->commandBus->dispatch(new IndexSearchDocument($ref->type, $ref->id));
    }
}
