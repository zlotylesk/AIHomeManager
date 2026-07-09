<?php

declare(strict_types=1);

namespace App\Controller;

use App\Module\Music\Infrastructure\External\DiscogsClockDriftDetector;
use App\Module\Music\Infrastructure\External\DiscogsCredentials;
use App\Module\Music\Infrastructure\External\DiscogsOAuth1Signer;
use App\Module\Music\Infrastructure\Persistence\DiscogsTokenRepositoryInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
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
    private const string PROVIDER = 'discogs';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly DiscogsTokenRepositoryInterface $tokenRepository,
        private readonly DiscogsOAuth1Signer $signer,
        #[Autowire(service: 'monolog.logger.auth')]
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

        $this->logger->info('OAuth authorize initiated', ['provider' => self::PROVIDER]);

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

        if (200 !== $response->getStatusCode()) {
            $this->logger->warning('Discogs request_token returned non-200', [
                'status' => $response->getStatusCode(),
                'body_sample' => substr($response->getContent(false), 0, 500),
            ]);

            return new Response(sprintf('Failed to obtain Discogs request token. HTTP %d', $response->getStatusCode()), Response::HTTP_BAD_GATEWAY);
        }

        parse_str($response->getContent(), $params);
        $requestToken = is_string($params['oauth_token'] ?? null) ? $params['oauth_token'] : '';

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
            $this->logger->warning('OAuth callback failed', [
                'provider' => self::PROVIDER,
                'reason' => 'invalid_state',
            ]);

            return new Response('Invalid or missing OAuth state.', Response::HTTP_BAD_REQUEST);
        }

        $oauthToken = $request->query->get('oauth_token', '');
        $oauthVerifier = $request->query->get('oauth_verifier', '');

        if ('' === $oauthToken || '' === $oauthVerifier) {
            $this->logger->warning('OAuth callback failed', [
                'provider' => self::PROVIDER,
                'reason' => 'missing_params',
            ]);

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

        if (200 !== $response->getStatusCode()) {
            $this->logger->warning('Discogs access_token returned non-200', [
                'status' => $response->getStatusCode(),
                'body_sample' => substr($response->getContent(false), 0, 500),
            ]);

            return new Response(sprintf('Failed to obtain Discogs access token. HTTP %d', $response->getStatusCode()), Response::HTTP_BAD_GATEWAY);
        }

        parse_str($response->getContent(), $params);
        $accessToken = is_string($params['oauth_token'] ?? null) ? $params['oauth_token'] : '';
        $accessTokenSecret = is_string($params['oauth_token_secret'] ?? null) ? $params['oauth_token_secret'] : '';

        if ('' === $accessToken || '' === $accessTokenSecret) {
            $this->logger->warning('OAuth callback failed', [
                'provider' => self::PROVIDER,
                'reason' => 'empty_token',
            ]);

            return new Response('Failed to obtain Discogs access token.', Response::HTTP_BAD_GATEWAY);
        }

        $this->tokenRepository->save($accessToken, $accessTokenSecret);
        $session->migrate(true);

        $this->logger->info('OAuth callback success', ['provider' => self::PROVIDER]);

        return new Response('Discogs connected successfully. You can now use GET /api/music/collection.', Response::HTTP_OK);
    }
}
