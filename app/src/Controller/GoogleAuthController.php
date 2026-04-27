<?php

declare(strict_types=1);

namespace App\Controller;

use App\Module\Tasks\Infrastructure\Persistence\GoogleTokenRepositoryInterface;
use Google\Client;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/auth/google')]
final class GoogleAuthController extends AbstractController
{
    public function __construct(
        private readonly Client $client,
        private readonly GoogleTokenRepositoryInterface $tokenRepository,
    ) {}

    #[Route('', methods: ['GET'])]
    public function authorize(): RedirectResponse
    {
        $authUrl = $this->client->createAuthUrl();

        return $this->redirect($authUrl);
    }

    #[Route('/callback', methods: ['GET'])]
    public function callback(Request $request): JsonResponse
    {
        $code = $request->query->get('code');

        if ($code === null) {
            return new JsonResponse(
                ['error' => 'Authorization code missing.'],
                Response::HTTP_BAD_REQUEST
            );
        }

        $token = $this->client->fetchAccessTokenWithAuthCode($code);

        if (isset($token['error'])) {
            return new JsonResponse(
                ['error' => 'OAuth2 token exchange failed: ' . $token['error']],
                Response::HTTP_BAD_REQUEST
            );
        }

        $this->tokenRepository->save($token);

        return new JsonResponse(['status' => 'authenticated']);
    }
}
