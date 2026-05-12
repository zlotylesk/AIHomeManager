<?php

declare(strict_types=1);

namespace App\Tests\Integration\Articles;

use App\Module\Articles\Application\Service\ArticleImporter;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class ArticleImporterTest extends KernelTestCase
{
    private ArticleImporter $importer;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->importer = $container->get(ArticleImporter::class);
        $this->em = $container->get(EntityManagerInterface::class);
        $this->em->getConnection()->executeStatement('TRUNCATE TABLE articles');
    }

    public function testImportsPocketCsvRows(): void
    {
        $file = $this->createCsvFile(
            "title,url,time_added,tags,status\n".
            "GNU make,https://www.gnu.org/software/make/manual/make.html,1641750653,,unread\n".
            "Pearl Jam,https://pl.wikipedia.org/wiki/Pearl_Jam,1611961999,,unread\n"
        );

        $result = $this->importer->import($file);

        self::assertSame(2, $result->imported);
        self::assertSame(0, $result->skipped);
        self::assertSame(0, $result->errors);
    }

    public function testSkipsDuplicatesByUrl(): void
    {
        $csv = "title,url,time_added,tags,status\n".
               "GNU make,https://www.gnu.org/software/make/manual/make.html,1641750653,,unread\n";
        $file = $this->createCsvFile($csv);

        $this->importer->import($file);
        $result = $this->importer->import($file);

        self::assertSame(0, $result->imported);
        self::assertSame(1, $result->skipped);
        self::assertSame(0, $result->errors);

        $count = $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM articles');
        self::assertSame('1', (string) $count);
    }

    public function testArchiveStatusSetsIsReadAndReadAt(): void
    {
        $file = $this->createCsvFile(
            "title,url,time_added,tags,status\n".
            "Old Article,https://example.com/old,1393833523,,archive\n"
        );

        $this->importer->import($file);

        $row = $this->em->getConnection()->fetchAssociative('SELECT * FROM articles WHERE url = ?', ['https://example.com/old']);
        self::assertNotFalse($row);
        self::assertSame('1', (string) $row['is_read']);
        self::assertNotNull($row['read_at']);
        self::assertSame($row['added_at'], $row['read_at']);
    }

    public function testUnreadStatusSetsIsReadFalse(): void
    {
        $file = $this->createCsvFile(
            "title,url,time_added,tags,status\n".
            "New Article,https://example.com/new,1641750653,,unread\n"
        );

        $this->importer->import($file);

        $row = $this->em->getConnection()->fetchAssociative('SELECT * FROM articles WHERE url = ?', ['https://example.com/new']);
        self::assertNotFalse($row);
        self::assertSame('0', (string) $row['is_read']);
        self::assertNull($row['read_at']);
    }

    public function testSkipsRowWithInvalidUrl(): void
    {
        $file = $this->createCsvFile(
            "title,url,time_added,tags,status\n".
            "Valid,https://example.com,1641750653,,unread\n".
            "Bad URL,not-a-url,1641750653,,unread\n"
        );

        $result = $this->importer->import($file);

        self::assertSame(1, $result->imported);
        self::assertSame(0, $result->skipped);
        self::assertSame(1, $result->errors);
    }

    public function testSkipsRowWithMissingTitle(): void
    {
        $file = $this->createCsvFile(
            "title,url,time_added,tags,status\n".
            ",https://example.com/no-title,1641750653,,unread\n"
        );

        $result = $this->importer->import($file);

        self::assertSame(0, $result->imported);
        self::assertSame(1, $result->errors);
    }

    public function testTitleAsUrlIsImported(): void
    {
        $file = $this->createCsvFile(
            "title,url,time_added,tags,status\n".
            "http://kunststube.net/isset/,http://kunststube.net/isset/,1568813038,,unread\n"
        );

        $result = $this->importer->import($file);

        self::assertSame(1, $result->imported);
        self::assertSame(0, $result->errors);
    }

    public function testImportsIso88592EncodedFile(): void
    {
        $line = iconv('UTF-8', 'ISO-8859-2', "Ząbek czosnku,https://example.com/czosnek,1641750653,,unread\n");
        $header = "title,url,time_added,tags,status\n";
        $file = $this->createRawFile($header.$line);

        $result = $this->importer->import($file);

        self::assertSame(1, $result->imported);
        $row = $this->em->getConnection()->fetchAssociative('SELECT title FROM articles WHERE url = ?', ['https://example.com/czosnek']);
        self::assertNotFalse($row);
        self::assertStringContainsString('czosnku', $row['title']);
    }

    public function testImportsWindows1250EncodedFileWithExplicitEncoding(): void
    {
        // Polish Windows exports commonly produce Windows-1250. mbstring does NOT
        // expose -1250 to mb_detect_encoding (only -1251/-1252/-1254), so the
        // caller must pass the encoding explicitly. iconv handles the actual
        // decode. Regression guard for HMAI-81.
        $line = iconv('UTF-8', 'Windows-1250', "Książka żółć,https://example.com/zolc,1641750653,,unread\n");
        $header = "title,url,time_added,tags,status\n";
        $file = $this->createRawFile($header.$line);

        $result = $this->importer->import($file, 'Windows-1250');

        self::assertSame(1, $result->imported);
        $row = $this->em->getConnection()->fetchAssociative('SELECT title FROM articles WHERE url = ?', ['https://example.com/zolc']);
        self::assertNotFalse($row);
        // Asserting on individual diacritics rather than the full string —
        // //TRANSLIT may simplify some glyphs but the core PL letters survive.
        self::assertStringContainsString('ż', $row['title']);
        self::assertStringContainsString('ó', $row['title']);
    }

    public function testExplicitEncodingOverridesAutoDetection(): void
    {
        // Bytes are valid UTF-8 "ąć" (C4 85 C4 87). Without override auto-detect
        // would pick UTF-8 and keep the diacritics intact. We force Windows-1252
        // to prove the override path actually re-decodes: the same bytes read as
        // Windows-1252 yield "Ä…Ä‡" — visibly different from "ąć". A naive
        // implementation that ignored $encoding and always auto-detected would
        // fail this assertion.
        $file = $this->createRawFile("title,url,time_added,tags,status\nąć,https://example.com/forced,1641750653,,unread\n");

        $result = $this->importer->import($file, 'Windows-1252');

        self::assertSame(1, $result->imported);
        $row = $this->em->getConnection()->fetchAssociative('SELECT title FROM articles WHERE url = ?', ['https://example.com/forced']);
        self::assertNotFalse($row);
        self::assertStringStartsWith('Ä', $row['title']);
        self::assertStringNotContainsString('ąć', $row['title']);
    }

    public function testRejectsUnsupportedExplicitEncoding(): void
    {
        // Allowlist guard: a typo or an unsupported encoding name must fail loudly
        // at the boundary rather than producing silently garbled data downstream.
        $file = $this->createCsvFile("title,url,time_added,tags,status\nx,https://example.com/x,1,,unread\n");

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported encoding');

        $this->importer->import($file, 'UTF-7');
    }

    public function testReturnsCorrectSummaryForMixedRows(): void
    {
        $file = $this->createCsvFile(
            "title,url,time_added,tags,status\n".
            "Good,https://example.com/good,1641750653,,unread\n".
            "Good 2,https://example.com/good2,1641750653,,unread\n".
            "Bad URL,not-a-url,1641750653,,unread\n"
        );

        $this->importer->import($file);

        $file2 = $this->createCsvFile(
            "title,url,time_added,tags,status\n".
            "Good,https://example.com/good,1641750653,,unread\n".
            "New,https://example.com/new,1641750653,,unread\n".
            ",https://example.com/no-title,1641750653,,unread\n"
        );

        $result = $this->importer->import($file2);

        self::assertSame(1, $result->imported);
        self::assertSame(1, $result->skipped);
        self::assertSame(1, $result->errors);
    }

    private function createCsvFile(string $content): string
    {
        $file = tempnam(sys_get_temp_dir(), 'articles_test_');
        file_put_contents($file, $content);

        return $file;
    }

    private function createRawFile(string $content): string
    {
        $file = tempnam(sys_get_temp_dir(), 'articles_test_');
        file_put_contents($file, $content);

        return $file;
    }
}
