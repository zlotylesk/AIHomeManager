<?php

declare(strict_types=1);

namespace App\Module\Search\Infrastructure\Console;

use App\Module\Search\Domain\Port\SearchIndexerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * Fills the search index from every source module (HMAI-363).
 *
 * The scheduler already runs the same rebuild every 15 minutes; this is the
 * operator's handle on it — after a restore, after switching backends, or
 * simply to see the document count without waiting for the next window. Safe to
 * run at any time: the rebuild upserts under a deterministic id and then
 * removes only what it did not touch, so it never empties the index and a
 * repeat run changes nothing.
 *
 * Distinct from `app:search:index`, which manages the index *schema*; this one
 * moves data.
 */
#[AsCommand(
    name: 'app:search:populate',
    description: 'Rebuild the search index from every source module (idempotent, no downtime)',
)]
final class PopulateSearchIndexCommand extends Command
{
    public function __construct(private readonly SearchIndexerInterface $indexer)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $indexed = $this->indexer->reindex();
        } catch (Throwable $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->success(sprintf('Indexed %d document(s).', $indexed));

        return Command::SUCCESS;
    }
}
