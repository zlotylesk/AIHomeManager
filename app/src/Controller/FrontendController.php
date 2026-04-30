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

    #[Route('/tasks', name: 'app_frontend_tasks', methods: ['GET'])]
    public function tasks(): Response
    {
        return $this->render('tasks/index.html.twig');
    }

    #[Route('/books', name: 'app_frontend_books', methods: ['GET'])]
    public function books(): Response
    {
        return $this->render('books/index.html.twig');
    }

    #[Route('/articles', name: 'app_frontend_articles', methods: ['GET'])]
    public function articles(): Response
    {
        return $this->render('articles/index.html.twig');
    }

    #[Route('/music', name: 'app_frontend_music', methods: ['GET'])]
    public function music(): Response
    {
        return $this->render('music/index.html.twig');
    }
}
