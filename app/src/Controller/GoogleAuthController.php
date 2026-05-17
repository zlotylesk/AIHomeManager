<?php

declare(strict_types=1);

namespace App\Controller;

use App\Module\Tasks\Infrastructure\Persistence\GoogleTokenRepositoryInterface;
use Google\Client;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Throwable;

#[Route('/auth/google')]
final class GoogleAuthController extends AbstractController
{
    private const string SESSION_STATE_KEY = 'google_oauth_state';
    private const string PROVIDER = 'google';

    public function __construct(
        private readonly Client $client,
        private readonly GoogleTokenRepositoryInterface $tokenRepository,
        #[Autowire(service: 'monolog.logger.auth')]
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('', methods: ['GET'])]
    public function authorize(Request $request): RedirectResponse
    {
        $state = bin2hex(random_bytes(32));
        $request->getSession()->set(self::SESSION_STATE_KEY, $state);

        $this->logger->info('OAuth authorize initiated', ['provider' => self::PROVIDER]);

        // HMAI-106: createAuthUrl() throws on missing/invalid client config
        // (clientId, redirectUri). GoogleClientFactory now validates these at
        // boot (HMAI-90), but third-party SDK upgrades can introduce new
        // failure modes — surface them as a friendly redirect with an error
        // flag instead of a kernel 500.
        try {
            $this->client->setState($state);
            $authUrl = $this->client->createAuthUrl();
        } catch (Throwable $e) {
            $this->logger->warning('Google OAuth init failed', [
                'provider' => self::PROVIDER,
                'exception' => $e->getMessage(),
                'class' => $e::class,
            ]);

            return new RedirectResponse('/tasks?error=oauth_unavailable');
        }

        return $this->redirect($authUrl);
    }

    #[Route('/callback', methods: ['GET'])]
    public function callback(Request $request): JsonResponse
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

            return new JsonResponse(
                ['error' => 'Invalid or missing OAuth state.'],
                Response::HTTP_BAD_REQUEST
            );
        }

        $code = $request->query->get('code');

        if (null === $code) {
            $this->logger->warning('OAuth callback failed', [
                'provider' => self::PROVIDER,
                'reason' => 'missing_code',
            ]);

            return new JsonResponse(
                ['error' => 'Authorization code missing.'],
                Response::HTTP_BAD_REQUEST
            );
        }

        $token = $this->client->fetchAccessTokenWithAuthCode($code);

        if (isset($token['error'])) {
            $this->logger->warning('OAuth callback failed', [
                'provider' => self::PROVIDER,
                'reason' => 'token_exchange',
                'error' => (string) $token['error'],
            ]);

            return new JsonResponse(
                ['error' => 'OAuth2 token exchange failed: '.$token['error']],
                Response::HTTP_BAD_REQUEST
            );
        }

        $this->tokenRepository->save($token);
        $session->migrate(true);

        $this->logger->info('OAuth callback success', ['provider' => self::PROVIDER]);

        return new JsonResponse(['status' => 'authenticated']);
    }
}
