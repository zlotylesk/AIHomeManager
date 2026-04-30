<?php

declare(strict_types=1);

namespace App\Tests\Integration\Frontend;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class FrontendControllerTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    public function testRootRedirectsToSeries(): void
    {
        $this->client->request('GET', '/');

        self::assertResponseRedirects('/series', 302);
    }

    public function testSeriesPageReturns200WithHtml(): void
    {
        $this->client->request('GET', '/series');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('content-type', 'text/html; charset=UTF-8');
        self::assertSelectorExists('nav.navbar');
        self::assertSelectorExists('#series-list');
    }

    public function testTasksPageReturns200WithHtml(): void
    {
        $this->client->request('GET', '/tasks');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('content-type', 'text/html; charset=UTF-8');
        self::assertSelectorExists('nav.navbar');
        self::assertSelectorExists('#form-time-report');
    }

    public function testBooksPageReturns200WithHtml(): void
    {
        $this->client->request('GET', '/books');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('content-type', 'text/html; charset=UTF-8');
        self::assertSelectorExists('nav.navbar');
        self::assertSelectorExists('#books-grid');
    }

    public function testArticlesPageReturns200WithHtml(): void
    {
        $this->client->request('GET', '/articles');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('content-type', 'text/html; charset=UTF-8');
        self::assertSelectorExists('nav.navbar');
        self::assertSelectorExists('#articles-list');
    }

    public function testMusicPageReturns200WithHtml(): void
    {
        $this->client->request('GET', '/music');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('content-type', 'text/html; charset=UTF-8');
        self::assertSelectorExists('nav.navbar');
        self::assertSelectorExists('#music-content');
    }

    public function testNavbarContainsAllModuleLinks(): void
    {
        $this->client->request('GET', '/series');

        self::assertSelectorExists('a[href="/series"]');
        self::assertSelectorExists('a[href="/tasks"]');
        self::assertSelectorExists('a[href="/books"]');
        self::assertSelectorExists('a[href="/articles"]');
        self::assertSelectorExists('a[href="/music"]');
    }

    public function testActiveNavLinkMarkedOnSeriesPage(): void
    {
        $this->client->request('GET', '/series');

        $crawler = $this->client->getCrawler();
        $activeLink = $crawler->filter('nav.navbar a.active');
        self::assertCount(1, $activeLink);
        self::assertStringContainsString('/series', $activeLink->attr('href'));
    }
}
