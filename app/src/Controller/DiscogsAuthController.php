<?php

declare(strict_types=1);

namespace App\Controller;

use App\Module\Music\Infrastructure\External\DiscogsClockDriftDetector;
use App\Module\Music\Infrastructure\External\DiscogsCredentials;
use App\Module\Music\Infrastructure\External\DiscogsOAuth1Signer;
use App\Module\Music\Infrastructure\Persistence\DiscogsTokenRepositoryInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class DiscogsAuthController extends AbstractController
{
    private const string REQUEST_TOKEN_URL = 'https://api.discogs.com/oauth/request_token';
    private const string ACCESS_TOKEN_URL = 'https://api.discogs.com/oauth/access_token';
    private const string AUTHORIZE_URL = 'https://www.discogs.com/oauth/authorize';
    private const string USER_AGENT = 'AIHomeManager/1.0 +https://github.com/zlotylesk/AIHomeManager';
    private const string SESSION_STATE_KEY = 'discogs_oauth_state';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly DiscogsTokenRepositoryInterface $tokenRepository,
        private readonly DiscogsOAuth1Signer $signer,
        private readonly LoggerInterface $logger,
        private readonly DiscogsCredentials $credentials,
        private readonly DiscogsClockDriftDetector $driftDetector,
        private readonly string $callbackUrl,
    ) {
    }

    #[Route('/auth/discogs', methods: ['GET'])]
    public function authorize(Request $request): Response
    {
        $state = bin2hex(random_bytes(32));
        $request->getSession()->set(self::SESSION_STATE_KEY, $state);
        $callbackWithState = $this->callbackUrl.'?state='.urlencode($state);

        $authHeader = $this->signer->buildAuthorizationHeader(
            'POST',
            self::REQUEST_TOKEN_URL,
            $this->credentials->consumerKey,
            $this->credentials->consumerSecret,
            '',
            '',
            ['oauth_callback' => $callbackWithState],
        );

        $response = $this->httpClient->request('POST', self::REQUEST_TOKEN_URL, [
            'headers' => [
                'Authorization' => $authHeader,
                'User-Agent' => self::USER_AGENT,
                'Content-Type' => 'application/x-www-form-urlencoded',
            ],
        ]);

        $this->driftDetector->inspect($response);

        // HMAI-105: explicit status check so a 401/500 from Discogs surfaces as a
        // user-facing 502 with a log entry, instead of letting getContent() bubble
        // a HttpExceptionInterface into the kernel's generic 500 handler. Sample
        // the body so the operator can see Discogs' error message in the log.
        if (200 !== $response->getStatusCode()) {
            $this->logger->warning('Discogs request_token returned non-200', [
                'status' => $response->getStatusCode(),
                'body_sample' => substr($response->getContent(false), 0, 500),
            ]);

            return new Response(sprintf('Failed to obtain Discogs request token. HTTP %d', $response->getStatusCode()), Response::HTTP_BAD_GATEWAY);
        }

        parse_str($response->getContent(), $params);
        $requestToken = $params['oauth_token'] ?? '';

        return $this->redirect(self::AUTHORIZE_URL.'?oauth_token='.urlencode($requestToken));
    }

    #[Route('/auth/discogs/callback', methods: ['GET'])]
    public function callback(Request $request): Response
    {
        $session = $request->getSession();
        $expectedState = $session->get(self::SESSION_STATE_KEY);
        $session->remove(self::SESSION_STATE_KEY);
        $receivedState = $request->query->get('state');

        if (!is_string($expectedState) || '' === $expectedState
            || !is_string($receivedState) || '' === $receivedState
            || !hash_equals($expectedState, $receivedState)) {
            return new Response('Invalid or missing OAuth state.', Response::HTTP_BAD_REQUEST);
        }

        $oauthToken = $request->query->get('oauth_token', '');
        $oauthVerifier = $request->query->get('oauth_verifier', '');

        if ('' === $oauthToken || '' === $oauthVerifier) {
            return new Response('Missing OAuth parameters.', Response::HTTP_BAD_REQUEST);
        }

        $authHeader = $this->signer->buildAuthorizationHeader(
            'POST',
            self::ACCESS_TOKEN_URL,
            $this->credentials->consumerKey,
            $this->credentials->consumerSecret,
            '',
            $oauthToken,
            ['oauth_verifier' => $oauthVerifier],
        );

        $response = $this->httpClient->request('POST', self::ACCESS_TOKEN_URL, [
            'headers' => [
                'Authorization' => $authHeader,
                'User-Agent' => self::USER_AGENT,
                'Content-Type' => 'application/x-www-form-urlencoded',
            ],
        ]);

        $this->driftDetector->inspect($response);

        // HMAI-105: see authorize() — same fail-friendly path for access_token.
        if (200 !== $response->getStatusCode()) {
            $this->logger->warning('Discogs access_token returned non-200', [
                'status' => $response->getStatusCode(),
                'body_sample' => substr($response->getContent(false), 0, 500),
            ]);

            return new Response(sprintf('Failed to obtain Discogs access token. HTTP %d', $response->getStatusCode()), Response::HTTP_BAD_GATEWAY);
        }

        parse_str($response->getContent(), $params);
        $accessToken = $params['oauth_token'] ?? '';
        $accessTokenSecret = $params['oauth_token_secret'] ?? '';

        if ('' === $accessToken || '' === $accessTokenSecret) {
            return new Response('Failed to obtain Discogs access token.', Response::HTTP_BAD_GATEWAY);
        }

        $this->tokenRepository->save($accessToken, $accessTokenSecret);
        $session->migrate(true);

        return new Response('Discogs connected successfully. You can now use GET /api/music/collection.', Response::HTTP_OK);
    }
}
