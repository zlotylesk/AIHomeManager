<?php

declare(strict_types=1);

namespace App\Tests\Integration\Security;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * HMAI-404: the `main` firewall (frontend pages + /auth/* OAuth callbacks) now
 * requires HTTP Basic, because the page it serves carries the production
 * API_KEY in a <meta name="api-key"> tag — an anonymous GET of e.g. /series
 * used to hand that key over, and with it full ^/api/* access.
 *
 * The `test` environment deliberately keeps `main.security: false` (see
 * security.yaml's `when@test` block): FrontendControllerTest,
 * SecurityHeadersTest and every *AuthControllerTest hitting /auth/* exercise
 * the application, not Basic auth itself, and adding PHP_AUTH_USER/PHP_AUTH_PW
 * to every one of those pre-existing call sites would be churn for no gain.
 *
 * This class instead boots the kernel in the `dev` environment, where that
 * `when@test` override does not apply, to prove the *real* firewall config
 * actually gates the page — the same DB-free `environment: dev` + dummy-DSN
 * pattern this repo's CI already relies on (the `openapi-contract` job's
 * `nelmio:apidoc:dump` dump and the PHPStan-Symfony cache-warmup step both
 * boot `dev` without touching MySQL/Redis). `/series` is a pure static Twig
 * render (`FrontendController::series()`) with no Doctrine access, so the
 * unmigrated "homemanager" (unsuffixed) database in CI's `dev`-environment
 * container never comes into play, unlike an /api/* route.
 */
final class FrontendHttpBasicAuthTest extends WebTestCase
{
    public function testSeriesPageWithoutCredentialsReturns401(): void
    {
        $client = static::createClient(['environment' => 'dev', 'debug' => false]);

        $client->request('GET', '/series');

        self::assertSame(401, $client->getResponse()->getStatusCode());
        self::assertStringContainsString(
            'Basic realm="AIHomeManager"',
            (string) $client->getResponse()->headers->get('WWW-Authenticate'),
        );
    }

    public function testSeriesPageWithValidCredentialsReturns200WithPreviousContent(): void
    {
        $client = static::createClient(['environment' => 'dev', 'debug' => false]);
        $client->setServerParameter('PHP_AUTH_USER', 'admin');
        $client->setServerParameter('PHP_AUTH_PW', 'test');

        $client->request('GET', '/series');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('nav.navbar');
        self::assertSelectorExists('#series-list');
    }

    public function testSeriesPageWithInvalidPasswordReturns401(): void
    {
        $client = static::createClient(['environment' => 'dev', 'debug' => false]);
        $client->setServerParameter('PHP_AUTH_USER', 'admin');
        $client->setServerParameter('PHP_AUTH_PW', 'wrong-password');

        $client->request('GET', '/series');

        self::assertSame(401, $client->getResponse()->getStatusCode());
    }

    /**
     * The `api` and `main` firewalls are disjoint — this HMAI-404 change must
     * not affect the API surface. Uses the standard `test`-environment client
     * (the real, migrated `homemanager_test` database), the same environment
     * every other *ApiTest already runs against.
     */
    public function testApiSeriesWithOnlyApiKeyStillWorksUnchanged(): void
    {
        $client = static::createClient();
        $client->setServerParameter('HTTP_X_API_KEY', 'test-api-key');

        $client->request('GET', '/api/series');

        self::assertNotSame(401, $client->getResponse()->getStatusCode());
    }
}
