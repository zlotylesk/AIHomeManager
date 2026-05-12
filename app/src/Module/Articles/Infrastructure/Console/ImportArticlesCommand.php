<?php

declare(strict_types=1);

namespace App\Module\Articles\Infrastructure\Console;

use App\Module\Articles\Application\Service\ArticleImporter;
use InvalidArgumentException;
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
        $this->addOption(
            'encoding',
            null,
            InputOption::VALUE_REQUIRED,
            'Source file encoding (e.g. "Windows-1250" for Polish-Windows Pocket exports). '
            .'Omit to auto-detect — but auto-detect cannot identify Windows-1250 (mbstring '
            .'limitation), so pass it explicitly for Polish-Windows files.',
        );
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

        $encoding = $input->getOption('encoding');

        try {
            $result = $this->importer->import($file, $encoding);
        } catch (InvalidArgumentException $e) {
            // Surface the allowlist error from ArticleImporter as a user-friendly
            // CLI message — without this the user sees a raw PHP exception trace.
            $output->writeln(sprintf('<error>%s</error>', $e->getMessage()));

            return Command::FAILURE;
        }

        $output->writeln(sprintf(
            'Imported: %d | Skipped (duplicates): %d | Errors: %d',
            $result->imported,
            $result->skipped,
            $result->errors,
        ));

        return Command::SUCCESS;
    }
}
