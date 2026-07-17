<?php

declare(strict_types=1);

namespace App\Tests\Integration\Movies;

use App\Shared\Security\TraktTokenProviderInterface;
use App\Tests\Support\AuthenticatedApiTrait;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class MoviesApiTest extends WebTestCase
{
    use AuthenticatedApiTrait;

    private const string UNKNOWN_UUID = '00000000-0000-0000-0000-000000000000';

    private KernelBrowser $client;
    private Connection $connection;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->authenticate($this->client);
        $this->connection = static::getContainer()->get(EntityManagerInterface::class)->getConnection();

        $this->connection->executeStatement('SET FOREIGN_KEY_CHECKS=0');
        foreach (['movies', 'trakt_oauth_tokens'] as $table) {
            $this->connection->executeStatement('TRUNCATE TABLE '.$table);
        }
        $this->connection->executeStatement('SET FOREIGN_KEY_CHECKS=1');
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function createMovie(array $overrides = []): string
    {
        $payload = array_merge(['title' => 'Blade Runner 2049'], $overrides);
        $this->client->request('POST', '/api/movies', content: (string) json_encode($payload));
        self::assertResponseStatusCodeSame(201);

        $body = $this->jsonResponse($this->client);
        self::assertArrayHasKey('id', $body);
        self::assertIsString($body['id']);

        return $body['id'];
    }

    public function testListReturnsEmptyArrayWhenNoMovies(): void
    {
        $this->client->request('GET', '/api/movies');

        self::assertResponseIsSuccessful();
        self::assertSame([], $this->jsonResponse($this->client));
    }

    public function testCreatedMovieAppearsInListAndDetail(): void
    {
        $id = $this->createMovie([
            'coverUrl' => 'https://img.test/br.jpg',
            'year' => 2017,
            'status' => 'released',
            'description' => 'Neo-noir sci-fi.',
        ]);

        $this->client->request('GET', '/api/movies');
        self::assertResponseIsSuccessful();
        $list = $this->jsonResponse($this->client);
        self::assertCount(1, $list);
        self::assertSame($id, $list[0]['id']);
        self::assertSame('Blade Runner 2049', $list[0]['title']);
        self::assertFalse($list[0]['watched']);
        self::assertNull($list[0]['rating']);

        $this->client->request('GET', '/api/movies/'.$id);
        self::assertResponseIsSuccessful();
        $detail = $this->jsonResponse($this->client);
        self::assertSame('https://img.test/br.jpg', $detail['coverUrl']);
        self::assertSame(2017, $detail['year']);
        self::assertSame('released', $detail['status']);
        self::assertSame('Neo-noir sci-fi.', $detail['description']);
        self::assertNull($detail['watchedAt']);
    }

    public function testCreateWithEmptyTitleReturns422(): void
    {
        $this->client->request('POST', '/api/movies', content: (string) json_encode(['title' => '   ']));

        self::assertResponseStatusCodeSame(422);
    }

    public function testCreateWithOutOfRangeYearReturns422(): void
    {
        $this->client->request('POST', '/api/movies', content: (string) json_encode(['title' => 'Old', 'year' => 1000]));

        self::assertResponseStatusCodeSame(422);
    }

    public function testCreateWithInvalidCoverUrlReturns422(): void
    {
        $this->client->request('POST', '/api/movies', content: (string) json_encode(['title' => 'Bad', 'coverUrl' => 'not-a-url']));

        self::assertResponseStatusCodeSame(422);
    }

    public function testListFiltersByWatchedFlag(): void
    {
        $watchedId = $this->createMovie(['title' => 'Seen']);
        $this->client->request('PATCH', '/api/movies/'.$watchedId.'/watched', content: (string) json_encode(['watched' => true]));
        self::assertResponseStatusCodeSame(204);

        $unwatchedId = $this->createMovie(['title' => 'Unseen']);

        $this->client->request('GET', '/api/movies?watched=true');
        $watchedList = $this->jsonResponse($this->client);
        self::assertCount(1, $watchedList);
        self::assertSame($watchedId, $watchedList[0]['id']);

        $this->client->request('GET', '/api/movies?watched=false');
        $unwatchedList = $this->jsonResponse($this->client);
        self::assertCount(1, $unwatchedList);
        self::assertSame($unwatchedId, $unwatchedList[0]['id']);

        $this->client->request('GET', '/api/movies');
        self::assertCount(2, $this->jsonResponse($this->client));
    }

    public function testListRejectsInvalidWatchedFilter(): void
    {
        $this->client->request('GET', '/api/movies?watched=maybe');

        self::assertResponseStatusCodeSame(422);
    }

    public function testRenameDoesNotWipeMetadata(): void
    {
        $id = $this->createMovie(['year' => 2017, 'status' => 'released', 'description' => 'keep me']);

        // A bare title edit must not clear the metadata (partial-safe PATCH).
        $this->client->request('PATCH', '/api/movies/'.$id, content: (string) json_encode(['title' => 'Renamed']));
        self::assertResponseStatusCodeSame(204);

        $this->client->request('GET', '/api/movies/'.$id);
        $detail = $this->jsonResponse($this->client);
        self::assertSame('Renamed', $detail['title']);
        self::assertSame(2017, $detail['year']);
        self::assertSame('released', $detail['status']);
        self::assertSame('keep me', $detail['description']);
    }

    public function testUpdateMetadataReplacesFields(): void
    {
        $id = $this->createMovie(['year' => 2017]);

        $this->client->request('PATCH', '/api/movies/'.$id, content: (string) json_encode([
            'coverUrl' => 'https://img.test/new.jpg',
            'year' => 2020,
            'status' => 'upcoming',
            'description' => 'updated',
        ]));
        self::assertResponseStatusCodeSame(204);

        $this->client->request('GET', '/api/movies/'.$id);
        $detail = $this->jsonResponse($this->client);
        self::assertSame('https://img.test/new.jpg', $detail['coverUrl']);
        self::assertSame(2020, $detail['year']);
        self::assertSame('upcoming', $detail['status']);
        self::assertSame('updated', $detail['description']);
    }

    public function testUpdateWithNoFieldsReturns422(): void
    {
        $id = $this->createMovie();

        $this->client->request('PATCH', '/api/movies/'.$id, content: (string) json_encode(['foo' => 'bar']));

        self::assertResponseStatusCodeSame(422);
    }

    public function testUpdateUnknownMovieReturns404(): void
    {
        $this->client->request('PATCH', '/api/movies/'.self::UNKNOWN_UUID, content: (string) json_encode(['title' => 'X']));

        self::assertResponseStatusCodeSame(404);
    }

    public function testWatchedToggleStampsAndClearsWatchedAt(): void
    {
        $id = $this->createMovie();

        $this->client->request('PATCH', '/api/movies/'.$id.'/watched', content: (string) json_encode(['watched' => true]));
        self::assertResponseStatusCodeSame(204);

        $this->client->request('GET', '/api/movies/'.$id);
        $detail = $this->jsonResponse($this->client);
        self::assertTrue($detail['watched']);
        self::assertIsString($detail['watchedAt']);

        $this->client->request('PATCH', '/api/movies/'.$id.'/watched', content: (string) json_encode(['watched' => false]));
        self::assertResponseStatusCodeSame(204);

        $this->client->request('GET', '/api/movies/'.$id);
        $detail = $this->jsonResponse($this->client);
        self::assertFalse($detail['watched']);
        self::assertNull($detail['watchedAt']);
    }

    public function testWatchedRejectsNonBoolean(): void
    {
        $id = $this->createMovie();

        $this->client->request('PATCH', '/api/movies/'.$id.'/watched', content: (string) json_encode(['watched' => 'yes']));

        self::assertResponseStatusCodeSame(422);
    }

    public function testRatingSetAndClear(): void
    {
        $id = $this->createMovie();

        $this->client->request('PATCH', '/api/movies/'.$id.'/rating', content: (string) json_encode(['rating' => 8]));
        self::assertResponseStatusCodeSame(204);

        $this->client->request('GET', '/api/movies/'.$id);
        self::assertSame(8, $this->jsonResponse($this->client)['rating']);

        $this->client->request('PATCH', '/api/movies/'.$id.'/rating', content: (string) json_encode(['rating' => null]));
        self::assertResponseStatusCodeSame(204);

        $this->client->request('GET', '/api/movies/'.$id);
        self::assertNull($this->jsonResponse($this->client)['rating']);
    }

    public function testRatingRejectsOutOfRange(): void
    {
        $id = $this->createMovie();

        $this->client->request('PATCH', '/api/movies/'.$id.'/rating', content: (string) json_encode(['rating' => 11]));

        self::assertResponseStatusCodeSame(422);
    }

    public function testDeleteMovieRemovesIt(): void
    {
        $id = $this->createMovie();

        $this->client->request('DELETE', '/api/movies/'.$id);
        self::assertResponseStatusCodeSame(204);

        $this->client->request('GET', '/api/movies/'.$id);
        self::assertResponseStatusCodeSame(404);
    }

    public function testDeleteUnknownMovieReturns404(): void
    {
        $this->client->request('DELETE', '/api/movies/'.self::UNKNOWN_UUID);

        self::assertResponseStatusCodeSame(404);
    }

    public function testDetailUnknownMovieReturns404(): void
    {
        $this->client->request('GET', '/api/movies/'.self::UNKNOWN_UUID);

        self::assertResponseStatusCodeSame(404);
    }

    public function testImportFromTraktReturns409WhenNotConnected(): void
    {
        $this->client->request('POST', '/api/movies/import/trakt');

        self::assertResponseStatusCodeSame(409);
        $body = $this->jsonResponse($this->client);
        self::assertSame('/auth/trakt', $body['authUrl']);
    }

    public function testImportFromTraktReturns202WhenConnected(): void
    {
        // Stub the shared Trakt token port so the connectivity check passes without
        // a real OAuth token. The import command is async-routed (in-memory in tests),
        // so the trigger returns 202 immediately without running the import inline.
        $this->client->disableReboot();
        $token = $this->createStub(TraktTokenProviderInterface::class);
        $token->method('get')->willReturn(['access_token' => 'stub-token']);
        self::getContainer()->set(TraktTokenProviderInterface::class, $token);

        $this->client->request('POST', '/api/movies/import/trakt');

        self::assertResponseStatusCodeSame(202);
        self::assertSame('import_started', $this->jsonResponse($this->client)['status']);
    }

    public function testVersionedAndLegacyAliasShareData(): void
    {
        $this->client->request('POST', '/api/v1/movies', content: (string) json_encode(['title' => 'Dune']));
        self::assertResponseStatusCodeSame(201);

        $this->client->request('GET', '/api/movies');
        $list = $this->jsonResponse($this->client);
        self::assertCount(1, $list);
        self::assertSame('Dune', $list[0]['title']);
    }

    public function testMoviesEndpointRejectsInvalidApiKey(): void
    {
        $this->client->setServerParameter('HTTP_X_API_KEY', 'wrong-key');
        $this->client->request('GET', '/api/movies');

        self::assertResponseStatusCodeSame(401);
    }
}
