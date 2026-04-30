<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class FrontendController extends AbstractController
{
    #[Route('/', methods: ['GET'])]
    public function index(): RedirectResponse
    {
        return $this->redirectToRoute('app_frontend_series');
    }

    #[Route('/series', name: 'app_frontend_series', methods: ['GET'])]
    public function series(): Response
    {
        return $this->render('series/index.html.twig');
    }
}
