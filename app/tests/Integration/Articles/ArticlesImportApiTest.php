<?php

declare(strict_types=1);

namespace App\Tests\Integration\Articles;

use App\Tests\Support\AuthenticatedApiTrait;
use Doctrine\ORM\EntityManagerInterface;
use Redis;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class ArticlesImportApiTest extends WebTestCase
{
    use AuthenticatedApiTrait;

    private KernelBrowser $client;
    private Redis $redis;

    /** @var list<string> */
    private array $tmpFiles = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->authenticate($this->client);
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->redis = static::getContainer()->get('app.redis');

        $conn = $em->getConnection();
        $conn->executeStatement('SET FOREIGN_KEY_CHECKS=0');
        $conn->executeStatement('TRUNCATE TABLE article_daily_picks');
        $conn->executeStatement('TRUNCATE TABLE articles');
        $conn->executeStatement('SET FOREIGN_KEY_CHECKS=1');

        $this->redis->del('articles:today');
    }

    protected function tearDown(): void
    {
        foreach ($this->tmpFiles as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
        $this->tmpFiles = [];

        parent::tearDown();
    }

    public function testImportPersistsRowsAndReturnsCounts(): void
    {
        $csv = "title,url\nFirst Article,https://example.com/first\nSecond Article,https://example.com/second\n";

        $this->client->request('POST', '/api/articles/import', files: ['file' => $this->csvUpload($csv)]);

        self::assertResponseStatusCodeSame(200);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame(2, $data['imported']);
        self::assertSame(0, $data['skipped']);
        self::assertSame(0, $data['errors']);
        self::assertFalse($data['dryRun']);

        $this->client->request('GET', '/api/articles');
        $articles = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertCount(2, $articles);
    }

    public function testImportWithoutFileReturns422(): void
    {
        $this->client->request('POST', '/api/articles/import');

        self::assertResponseStatusCodeSame(422);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertArrayHasKey('error', $data);
    }

    public function testImportDuplicateUrlsAreSkipped(): void
    {
        $csv = "title,url\nDup,https://example.com/dup\n";

        $this->client->request('POST', '/api/articles/import', files: ['file' => $this->csvUpload($csv)]);
        self::assertResponseStatusCodeSame(200);

        $this->client->request('POST', '/api/articles/import', files: ['file' => $this->csvUpload($csv)]);
        self::assertResponseStatusCodeSame(200);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame(0, $data['imported']);
        self::assertSame(1, $data['skipped']);
    }

    public function testImportDryRunReturnsCountsWithoutPersisting(): void
    {
        $csv = "title,url\nDry,https://example.com/dry\nRun,https://example.com/run\n";

        $this->client->request(
            'POST',
            '/api/articles/import',
            parameters: ['dry_run' => '1'],
            files: ['file' => $this->csvUpload($csv)],
        );

        self::assertResponseStatusCodeSame(200);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame(2, $data['imported']);
        self::assertTrue($data['dryRun']);

        $this->client->request('GET', '/api/articles');
        $articles = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertCount(0, $articles);
    }

    public function testImportUnsupportedEncodingReturns422(): void
    {
        $csv = "title,url\nEnc,https://example.com/enc\n";

        $this->client->request(
            'POST',
            '/api/articles/import',
            parameters: ['encoding' => 'UTF-16'],
            files: ['file' => $this->csvUpload($csv)],
        );

        self::assertResponseStatusCodeSame(422);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertStringContainsStringIgnoringCase('encoding', $data['error']);
    }

    public function testImportRowMissingUrlCountedAsError(): void
    {
        $csv = "title,url\nValid,https://example.com/valid\nBroken,\n";

        $this->client->request('POST', '/api/articles/import', files: ['file' => $this->csvUpload($csv)]);

        self::assertResponseStatusCodeSame(200);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame(1, $data['imported']);
        self::assertSame(1, $data['errors']);
    }

    private function csvUpload(string $content, string $name = 'import.csv'): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'aihm_csv_');
        self::assertNotFalse($path);
        file_put_contents($path, $content);
        $this->tmpFiles[] = $path;

        return new UploadedFile($path, $name, 'text/csv', null, true);
    }
}
