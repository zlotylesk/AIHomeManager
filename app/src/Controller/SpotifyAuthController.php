<?php

declare(strict_types=1);

namespace App\Controller;

use App\Module\Podcasts\Infrastructure\Persistence\SpotifyTokenRepositoryInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Spotify OAuth2 (Authorization Code) flow — the Podcasts module's connection to
 * its listening source (HMAI-323). Mirrors the Trakt/Discogs/Google auth
 * controllers: a CSRF-style `state` is planted in the session on authorize and
 * verified on callback, the code→token exchange runs server-side, and the token
 * is stored encrypted at rest by the repository. Lives on the public `main`
 * firewall.
 *
 * Unlike Trakt, Spotify wants the client credentials in an HTTP Basic header and
 * the grant parameters form-encoded — not a JSON body.
 */
final class SpotifyAuthController extends AbstractController
{
    private const string AUTHORIZE_URL = 'https://accounts.spotify.com/authorize';
    private const string TOKEN_URL = 'https://accounts.spotify.com/api/token';
    private const string SESSION_STATE_KEY = 'spotify_oauth_state';
    private const string PROVIDER = 'spotify';

    /**
     * user-library-read           — the saved shows we walk.
     * user-read-playback-position — without it Spotify omits `resume_point`
     *                               entirely, and resume points ARE the listening
     *                               history here.
     * user-read-currently-playing — the only source of a real listen timestamp.
     */
    private const string SCOPES = 'user-library-read user-read-playback-position user-read-currently-playing';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly SpotifyTokenRepositoryInterface $tokenRepository,
        #[Autowire(service: 'monolog.logger.auth')]
        private readonly LoggerInterface $logger,
        private readonly string $clientId,
        private readonly string $clientSecret,
        private readonly string $redirectUri,
    ) {
    }

    #[Route('/auth/spotify', methods: ['GET'])]
    public function authorize(Request $request): Response
    {
        $state = bin2hex(random_bytes(32));
        $request->getSession()->set(self::SESSION_STATE_KEY, $state);

        $this->logger->info('OAuth authorize initiated', ['provider' => self::PROVIDER]);

        $authUrl = self::AUTHORIZE_URL.'?'.http_build_query([
            'response_type' => 'code',
            'client_id' => $this->clientId,
            'redirect_uri' => $this->redirectUri,
            'scope' => self::SCOPES,
            'state' => $state,
        ]);

        return $this->redirect($authUrl);
    }

    #[Route('/auth/spotify/callback', methods: ['GET'])]
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
            'headers' => [
                'Authorization' => 'Basic '.base64_encode($this->clientId.':'.$this->clientSecret),
                'Content-Type' => 'application/x-www-form-urlencoded',
            ],
            'body' => [
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => $this->redirectUri,
            ],
        ]);

        if (200 !== $response->getStatusCode()) {
            $this->logger->warning('Spotify token exchange returned non-200', [
                'provider' => self::PROVIDER,
                'status' => $response->getStatusCode(),
                'body_sample' => substr($response->getContent(false), 0, 500),
            ]);

            return new Response(
                sprintf('Failed to obtain Spotify access token. HTTP %d', $response->getStatusCode()),
                Response::HTTP_BAD_GATEWAY
            );
        }

        $token = json_decode($response->getContent(false), true);

        if (!is_array($token) || !isset($token['access_token'])) {
            $this->logger->warning('OAuth callback failed', [
                'provider' => self::PROVIDER,
                'reason' => 'empty_token',
            ]);

            return new Response('Failed to obtain Spotify access token.', Response::HTTP_BAD_GATEWAY);
        }

        $this->tokenRepository->save($token);
        $session->migrate(true);

        $this->logger->info('OAuth callback success', ['provider' => self::PROVIDER]);

        // The cockpit, not /podcasts — that page arrives with the module's
        // frontend (HMAI-327), and sending a freshly connected user to a route
        // that does not exist yet would answer the successful handshake with a
        // 404. Repoint it once the page is there.
        return $this->redirect('/');
    }
}
