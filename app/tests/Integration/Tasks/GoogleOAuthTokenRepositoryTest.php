<?php

declare(strict_types=1);

namespace App\Tests\Integration\Tasks;

use App\Module\Tasks\Infrastructure\Persistence\GoogleOAuthTokenRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class GoogleOAuthTokenRepositoryTest extends KernelTestCase
{
    private GoogleOAuthTokenRepository $repository;
    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->repository = self::getContainer()->get(GoogleOAuthTokenRepository::class);
        $this->connection = self::getContainer()->get(EntityManagerInterface::class)->getConnection();
        $this->connection->executeStatement('TRUNCATE TABLE google_oauth_tokens');
    }

    public function testSaveAndGetReturnsPlaintextValues(): void
    {
        $token = [
            'access_token' => 'ya29.access_token_value',
            'refresh_token' => '1//refresh_token_value',
            'expires_in' => 3600,
            'token_type' => 'Bearer',
        ];

        $this->repository->save($token);

        self::assertSame($token, $this->repository->get());
    }

    public function testStoredColumnIsCiphertextNotPlaintext(): void
    {
        $token = [
            'access_token' => 'plain_access_token_marker',
            'refresh_token' => 'plain_refresh_token_marker',
        ];

        $this->repository->save($token);

        $row = $this->connection->fetchAssociative('SELECT token_json FROM google_oauth_tokens LIMIT 1');

        self::assertNotFalse($row);
        self::assertStringNotContainsString('plain_access_token_marker', $row['token_json']);
        self::assertStringNotContainsString('plain_refresh_token_marker', $row['token_json']);
    }

    public function testGetReturnsNullWhenNoTokenStored(): void
    {
        self::assertNull($this->repository->get());
    }

    public function testSaveOverwritesExistingToken(): void
    {
        $this->repository->save(['access_token' => 'first']);
        $this->repository->save(['access_token' => 'second']);

        $result = $this->repository->get();

        self::assertNotNull($result);
        self::assertSame('second', $result['access_token']);

        $count = $this->connection->fetchOne('SELECT COUNT(*) FROM google_oauth_tokens');
        self::assertSame(1, (int) $count);
    }

    public function testEachEncryptionUsesUniqueNonce(): void
    {
        $token = ['access_token' => 'same_token'];

        $this->repository->save($token);
        $first = $this->connection->fetchAssociative('SELECT token_json FROM google_oauth_tokens LIMIT 1');

        $this->repository->save($token);
        $second = $this->connection->fetchAssociative('SELECT token_json FROM google_oauth_tokens LIMIT 1');

        self::assertNotFalse($first);
        self::assertNotFalse($second);
        self::assertNotSame($first['token_json'], $second['token_json']);
    }
}
