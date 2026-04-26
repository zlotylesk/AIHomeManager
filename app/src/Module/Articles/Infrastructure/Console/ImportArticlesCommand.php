<?php

declare(strict_types=1);

namespace App\Module\Articles\Infrastructure\Console;

use App\Module\Articles\Application\Service\ArticleImporter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:articles:import',
    description: 'Import articles from a CSV file (Pocket export or custom format)',
)]
final class ImportArticlesCommand extends Command
{
    public function __construct(private readonly ArticleImporter $importer)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('file', null, InputOption::VALUE_REQUIRED, 'Path to the CSV file to import');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $file = $input->getOption('file');

        if (!$file) {
            $output->writeln('<error>Option --file is required.</error>');

            return Command::FAILURE;
        }

        if (!file_exists($file)) {
            $output->writeln(sprintf('<error>File not found: %s</error>', $file));

            return Command::FAILURE;
        }

        $result = $this->importer->import($file);

        $output->writeln(sprintf(
            'Imported: %d | Skipped (duplicates): %d | Errors: %d',
            $result->imported,
            $result->skipped,
            $result->errors,
        ));

        return Command::SUCCESS;
    }
}
