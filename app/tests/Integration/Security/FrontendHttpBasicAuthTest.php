<?php

declare(strict_types=1);

namespace App\Tests\Integration\Security;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

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
 *
 * `WebTestCase::createClient()` cannot be used for the `dev` boot: it needs
 * the `test.client` service, which only exists when `framework.test: true` —
 * itself a `when@test`-only setting (framework.yaml). So these three cases
 * drive the kernel directly as an `HttpKernelInterface`
 * (`$kernel->handle($request)`, exactly what `public/index.php` does), which
 * needs no test-only wiring and goes through the identical firewall/exception
 * pipeline a real request would.
 */
final class FrontendHttpBasicAuthTest extends WebTestCase
{
    public function testSeriesPageWithoutCredentialsReturns401(): void
    {
        self::bootKernel(['environment' => 'dev', 'debug' => false]);
        $kernel = self::$kernel;
        self::assertNotNull($kernel);

        $response = $kernel->handle(Request::create('/series', 'GET'));

        self::assertSame(401, $response->getStatusCode());
        self::assertStringContainsString(
            'Basic realm="AIHomeManager"',
            (string) $response->headers->get('WWW-Authenticate'),
        );
    }

    public function testSeriesPageWithValidCredentialsReturns200WithPreviousContent(): void
    {
        self::bootKernel(['environment' => 'dev', 'debug' => false]);
        $kernel = self::$kernel;
        self::assertNotNull($kernel);

        $response = $kernel->handle(Request::create('/series', 'GET', [], [], [], [
            'PHP_AUTH_USER' => 'admin',
            'PHP_AUTH_PW' => 'test',
        ]));

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('id="series-list"', (string) $response->getContent());
    }

    public function testSeriesPageWithInvalidPasswordReturns401(): void
    {
        self::bootKernel(['environment' => 'dev', 'debug' => false]);
        $kernel = self::$kernel;
        self::assertNotNull($kernel);

        $response = $kernel->handle(Request::create('/series', 'GET', [], [], [], [
            'PHP_AUTH_USER' => 'admin',
            'PHP_AUTH_PW' => 'wrong-password',
        ]));

        self::assertSame(401, $response->getStatusCode());
    }

    /**
     * HMAI-395 (epic review): `/auth/*` was the half of this firewall nobody
     * proved. `security.yaml` puts the OAuth entry points under `main` (no
     * `pattern`, so it is the catch-all) and `access_control`'s `^/` ROLE_USER
     * rule, and both this class's docblock and the config comment claim they
     * are gated — but every existing case only exercised `/series`, and the
     * `httpCredentials` in `playwright.config.ts` never fire (E2E runs
     * `APP_ENV=test`, where `main.security: false`). That is worse than an
     * untested path: it is configuration that reads as if it were covered.
     *
     * This is the security-critical half — an anonymous OAuth entry point
     * would let a stranger start (and, on callback, complete) an
     * authorization flow that binds *this* server's account.
     */
    public function testOAuthEntryPointWithoutCredentialsReturns401(): void
    {
        self::bootKernel(['environment' => 'dev', 'debug' => false]);
        $kernel = self::$kernel;
        self::assertNotNull($kernel);

        $response = $kernel->handle(Request::create('/auth/trakt', 'GET'));

        self::assertSame(401, $response->getStatusCode());
        self::assertStringContainsString(
            'Basic realm="AIHomeManager"',
            (string) $response->headers->get('WWW-Authenticate'),
        );
    }

    /**
     * The counterpart: valid credentials must get *past* the firewall, so a
     * logged-in browser can still complete an OAuth round trip (it resends the
     * same Basic credentials for the rest of the realm automatically).
     *
     * Deliberately a negative assertion rather than a check for 302: `.env`
     * ships an empty `TRAKT_CLIENT_ID`, so what the controller does next is an
     * environment detail, not the claim under test. Pinning a redirect here
     * would make this case fail for a reason that has nothing to do with
     * authentication. `assertNotSame(401, …)` states exactly the invariant
     * HMAI-404 must preserve — the same idiom the API case below uses.
     */
    public function testOAuthEntryPointWithValidCredentialsPassesTheFirewall(): void
    {
        self::bootKernel(['environment' => 'dev', 'debug' => false]);
        $kernel = self::$kernel;
        self::assertNotNull($kernel);

        $response = $kernel->handle(Request::create('/auth/trakt', 'GET', [], [], [], [
            'PHP_AUTH_USER' => 'admin',
            'PHP_AUTH_PW' => 'test',
        ]));

        self::assertNotSame(401, $response->getStatusCode());
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
