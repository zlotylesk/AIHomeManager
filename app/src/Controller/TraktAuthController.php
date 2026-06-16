<?php

declare(strict_types=1);

namespace App\Controller;

use App\Module\Series\Infrastructure\Persistence\TraktTokenRepositoryInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Trakt.tv OAuth2 (Authorization Code) flow — layer 1/5 of the Trakt import
 * epic (HMAI-178). Mirrors the Discogs/Google auth controllers: CSRF-style
 * `state` is planted in the session on authorize and verified on callback, the
 * code→token exchange runs server-side, and the resulting token is stored
 * encrypted at rest by the repository. Lives on the public `main` firewall.
 */
final class TraktAuthController extends AbstractController
{
    private const string AUTHORIZE_URL = 'https://trakt.tv/oauth/authorize';
    private const string TOKEN_URL = 'https://api.trakt.tv/oauth/token';
    private const string SESSION_STATE_KEY = 'trakt_oauth_state';
    private const string PROVIDER = 'trakt';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly TraktTokenRepositoryInterface $tokenRepository,
        #[Autowire(service: 'monolog.logger.auth')]
        private readonly LoggerInterface $logger,
        private readonly string $clientId,
        private readonly string $clientSecret,
        private readonly string $redirectUri,
    ) {
    }

    #[Route('/auth/trakt', methods: ['GET'])]
    public function authorize(Request $request): Response
    {
        $state = bin2hex(random_bytes(32));
        $request->getSession()->set(self::SESSION_STATE_KEY, $state);

        $this->logger->info('OAuth authorize initiated', ['provider' => self::PROVIDER]);

        $authUrl = self::AUTHORIZE_URL.'?'.http_build_query([
            'response_type' => 'code',
            'client_id' => $this->clientId,
            'redirect_uri' => $this->redirectUri,
            'state' => $state,
        ]);

        return $this->redirect($authUrl);
    }

    #[Route('/auth/trakt/callback', methods: ['GET'])]
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

        $code = $request->query->get('code');

        if (!is_string($code) || '' === $code) {
            $this->logger->warning('OAuth callback failed', [
                'provider' => self::PROVIDER,
                'reason' => 'missing_code',
            ]);

            return new Response('Authorization code missing.', Response::HTTP_BAD_REQUEST);
        }

        $response = $this->httpClient->request('POST', self::TOKEN_URL, [
            'json' => [
                'code' => $code,
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
                'redirect_uri' => $this->redirectUri,
                'grant_type' => 'authorization_code',
            ],
        ]);

        if (200 !== $response->getStatusCode()) {
            $this->logger->warning('Trakt token exchange returned non-200', [
                'provider' => self::PROVIDER,
                'status' => $response->getStatusCode(),
                'body_sample' => substr($response->getContent(false), 0, 500),
            ]);

            return new Response(
                sprintf('Failed to obtain Trakt access token. HTTP %d', $response->getStatusCode()),
                Response::HTTP_BAD_GATEWAY
            );
        }

        $token = json_decode($response->getContent(false), true);

        if (!is_array($token) || !isset($token['access_token'])) {
            $this->logger->warning('OAuth callback failed', [
                'provider' => self::PROVIDER,
                'reason' => 'empty_token',
            ]);

            return new Response('Failed to obtain Trakt access token.', Response::HTTP_BAD_GATEWAY);
        }

        $this->tokenRepository->save($token);
        $session->migrate(true);

        $this->logger->info('OAuth callback success', ['provider' => self::PROVIDER]);

        return $this->redirect('/series');
    }
}
