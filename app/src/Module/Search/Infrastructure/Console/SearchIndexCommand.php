<?php

declare(strict_types=1);

namespace App\Module\Search\Infrastructure\Console;

use App\Module\Search\Infrastructure\Index\SearchIndexDefinition;
use App\Module\Search\Infrastructure\Index\SearchIndexManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * Declarative provisioning of the OpenSearch index (HMAI-362).
 *
 * The index schema is code, so applying it has to be a command someone can run
 * on a fresh box or after a mapping change — the same role `doctrine:migrations`
 * plays for MySQL. Default run creates the index if the alias is missing and
 * does nothing otherwise; `--reindex` rebuilds it under the current definition
 * and moves the alias without an outage.
 */
#[AsCommand(
    name: 'app:search:index',
    description: 'Create or reindex the OpenSearch search index (alias-based, zero-downtime)',
)]
final class SearchIndexCommand extends Command
{
    public function __construct(
        private readonly SearchIndexManager $manager,
        private readonly SearchIndexDefinition $definition,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'reindex',
            null,
            InputOption::VALUE_NONE,
            'Rebuild the index under the current mappings and switch the alias to it '
            .'(use after changing analyzers or field mappings). Without this the '
            .'command only provisions a missing index.',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $reindex = true === $input->getOption('reindex');
        $before = null;

        try {
            $before = $this->manager->currentIndex();
            $index = $reindex ? $this->manager->reindex() : $this->manager->createIfMissing();
        } catch (Throwable $e) {
            // The message carries the operator's next step (an occupied alias
            // name, an unreachable engine), so it is worth more than a stack
            // trace here.
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $alias = $this->definition->alias();

        if (!$reindex && $index === $before) {
            $io->success(sprintf('Index already provisioned: %s -> %s (schema v%d).', $alias, $index, SearchIndexDefinition::SCHEMA_VERSION));

            return Command::SUCCESS;
        }

        $io->success(sprintf('Alias %s now points to %s (schema v%d).', $alias, $index, SearchIndexDefinition::SCHEMA_VERSION));

        if ($reindex && null === $before) {
            $io->note('There was no existing index to copy from, so the new one is empty — run the indexing pipeline to fill it.');
        }

        return Command::SUCCESS;
    }
}
