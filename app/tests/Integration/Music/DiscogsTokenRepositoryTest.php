<?php

declare(strict_types=1);

namespace App\Tests\Integration\Music;

use App\Module\Music\Infrastructure\Persistence\DiscogsTokenRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class DiscogsTokenRepositoryTest extends KernelTestCase
{
    private DiscogsTokenRepository $repository;
    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->repository = self::getContainer()->get(DiscogsTokenRepository::class);
        $this->connection = self::getContainer()->get(EntityManagerInterface::class)->getConnection();
        $this->connection->executeStatement('TRUNCATE TABLE discogs_oauth_tokens');
    }

    public function testSaveAndGetReturnsPlaintextValues(): void
    {
        $this->repository->save('access_token_xyz', 'access_token_secret_abc');

        $result = $this->repository->get();

        self::assertNotNull($result);
        self::assertSame('access_token_xyz', $result['oauth_token']);
        self::assertSame('access_token_secret_abc', $result['oauth_token_secret']);
    }

    public function testStoredColumnsAreCiphertextNotPlaintext(): void
    {
        $token = 'plain_access_token';
        $secret = 'plain_access_secret';

        $this->repository->save($token, $secret);

        $row = $this->connection->fetchAssociative('SELECT oauth_token, oauth_token_secret FROM discogs_oauth_tokens LIMIT 1');

        self::assertNotFalse($row);
        self::assertNotSame($token, $row['oauth_token']);
        self::assertNotSame($secret, $row['oauth_token_secret']);
        self::assertStringNotContainsString($token, $row['oauth_token']);
        self::assertStringNotContainsString($secret, $row['oauth_token_secret']);
    }

    public function testGetReturnsNullWhenNoTokenStored(): void
    {
        self::assertNull($this->repository->get());
    }

    public function testSaveOverwritesExistingToken(): void
    {
        $this->repository->save('first_token', 'first_secret');
        $this->repository->save('second_token', 'second_secret');

        $result = $this->repository->get();

        self::assertNotNull($result);
        self::assertSame('second_token', $result['oauth_token']);
        self::assertSame('second_secret', $result['oauth_token_secret']);

        $count = $this->connection->fetchOne('SELECT COUNT(*) FROM discogs_oauth_tokens');
        self::assertSame(1, (int) $count);
    }

    public function testEachEncryptionUsesUniqueNonce(): void
    {
        $this->repository->save('same_token', 'same_secret');
        $first = $this->connection->fetchAssociative('SELECT oauth_token FROM discogs_oauth_tokens LIMIT 1');

        $this->repository->save('same_token', 'same_secret');
        $second = $this->connection->fetchAssociative('SELECT oauth_token FROM discogs_oauth_tokens LIMIT 1');

        self::assertNotFalse($first);
        self::assertNotFalse($second);
        self::assertNotSame($first['oauth_token'], $second['oauth_token']);
    }
}
