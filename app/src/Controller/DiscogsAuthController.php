<?php

declare(strict_types=1);

namespace App\Controller;

use App\Module\Music\Infrastructure\External\DiscogsOAuth1Signer;
use App\Module\Music\Infrastructure\Persistence\DiscogsTokenRepositoryInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
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
        private readonly string $consumerKey,
        private readonly string $consumerSecret,
        private readonly string $callbackUrl,
    ) {
    }

    #[Route('/auth/discogs', methods: ['GET'])]
    public function authorize(Request $request): RedirectResponse
    {
        $state = bin2hex(random_bytes(32));
        $request->getSession()->set(self::SESSION_STATE_KEY, $state);
        $callbackWithState = $this->callbackUrl.'?state='.urlencode($state);

        $authHeader = $this->signer->buildAuthorizationHeader(
            'POST',
            self::REQUEST_TOKEN_URL,
            $this->consumerKey,
            $this->consumerSecret,
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

        parse_str($response->getContent(), $params);
        $requestToken = $params['oauth_token'] ?? '';

        return new RedirectResponse(self::AUTHORIZE_URL.'?oauth_token='.urlencode($requestToken));
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
            $this->consumerKey,
            $this->consumerSecret,
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
