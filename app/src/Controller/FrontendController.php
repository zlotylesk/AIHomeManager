<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class FrontendController extends AbstractController
{
    /**
     * The cockpit is the application's home page (HMAI-261): `/` renders the
     * dashboard shell instead of redirecting to a module. The widgets load
     * client-side from `/api/dashboard` (which goes through query.bus), matching
     * the dual-track frontend pattern — the controller stays thin. The old
     * redirect is removed entirely; empty widgets degrade per-section in the UI.
     */
    #[Route('/', name: 'app_frontend_dashboard', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('dashboard/index.html.twig');
    }

    #[Route('/series', name: 'app_frontend_series', methods: ['GET'])]
    public function series(): Response
    {
        return $this->render('series/index.html.twig');
    }

    #[Route('/movies', name: 'app_frontend_movies', methods: ['GET'])]
    public function movies(): Response
    {
        return $this->render('movies/index.html.twig');
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

    #[Route('/podcasts', name: 'app_frontend_podcasts', methods: ['GET'])]
    public function podcasts(): Response
    {
        return $this->render('podcasts/index.html.twig');
    }

    #[Route('/youtube-progress', name: 'app_frontend_youtube_progress', methods: ['GET'])]
    public function youtubeProgress(): Response
    {
        return $this->render('youtube_progress/index.html.twig');
    }

    #[Route('/goals', name: 'app_frontend_goals', methods: ['GET'])]
    public function goals(): Response
    {
        return $this->render('goals/index.html.twig');
    }

    #[Route('/notifications', name: 'app_frontend_notifications', methods: ['GET'])]
    public function notifications(): Response
    {
        return $this->render('notifications/index.html.twig');
    }
}
