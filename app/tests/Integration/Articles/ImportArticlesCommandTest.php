<?php

declare(strict_types=1);

namespace App\Tests\Integration\Articles;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Establishes the first CLI-level test in the project. Covers HMAI-81 wiring:
 * the `--encoding` flag must reach ArticleImporter::import() and the allowlist
 * error must surface as a user-friendly CLI message instead of a stack trace.
 */
final class ImportArticlesCommandTest extends KernelTestCase
{
    private CommandTester $tester;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $kernel = self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->em->getConnection()->executeStatement('TRUNCATE TABLE articles');

        $application = new Application($kernel);
        $command = $application->find('app:articles:import');
        $this->tester = new CommandTester($command);
    }

    public function testEncodingOptionIsWiredToImporter(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'cli_import_');
        file_put_contents($file, "title,url,time_added,tags,status\n"
            .iconv('UTF-8', 'Windows-1250', "Książka żółć,https://example.com/cli-pl,1641750653,,unread\n"));

        $exit = $this->tester->execute(['--file' => $file, '--encoding' => 'Windows-1250']);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertStringContainsString('Imported: 1', $this->tester->getDisplay());

        $row = $this->em->getConnection()->fetchAssociative(
            'SELECT title FROM articles WHERE url = ?',
            ['https://example.com/cli-pl'],
        );
        self::assertNotFalse($row);
        self::assertStringContainsString('ż', $row['title']);
        self::assertStringContainsString('ó', $row['title']);
    }

    public function testDryRunFlagPreviewsImportWithoutPersisting(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'cli_import_');
        file_put_contents(
            $file,
            "title,url,time_added,tags,status\nDry,https://example.com/dry-cli,1641750653,,unread\n"
        );

        $exit = $this->tester->execute(['--file' => $file, '--dry-run' => true]);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertStringContainsString('[DRY RUN] Imported: 1', $this->tester->getDisplay());

        $count = $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM articles');
        self::assertSame('0', (string) $count);
    }

    public function testRejectsUnsupportedEncodingWithFriendlyMessage(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'cli_import_');
        file_put_contents($file, "title,url,time_added,tags,status\nx,https://example.com/x,1,,unread\n");

        $exit = $this->tester->execute(['--file' => $file, '--encoding' => 'UTF-7']);

        self::assertSame(Command::FAILURE, $exit);
        self::assertStringContainsString('Unsupported encoding', $this->tester->getDisplay());
    }
}
