<?php

declare(strict_types=1);

namespace App\Module\Tasks\Infrastructure\Google;

use Google\Client;
use Google\Service\Calendar;
use Google\Service\YouTube;
use InvalidArgumentException;

final readonly class GoogleClientFactory
{
    public function __construct(
        private string $clientId,
        private string $clientSecret,
        private string $redirectUri,
    ) {
        if ('' === trim($this->clientId)) {
            throw new InvalidArgumentException('GOOGLE_OAUTH_CLIENT_ID is empty');
        }
        if ('' === trim($this->clientSecret)) {
            throw new InvalidArgumentException('GOOGLE_OAUTH_CLIENT_SECRET is empty');
        }
        if (false === filter_var($this->redirectUri, FILTER_VALIDATE_URL)) {
            throw new InvalidArgumentException(sprintf('GOOGLE_OAUTH_REDIRECT_URI is not a valid URL: "%s"', $this->redirectUri));
        }
    }

    public function create(): Client
    {
        $client = new Client();
        $client->setClientId($this->clientId);
        $client->setClientSecret($this->clientSecret);
        $client->setRedirectUri($this->redirectUri);
        $client->addScope(Calendar::CALENDAR_EVENTS);

        $client->addScope(YouTube::YOUTUBE);
        $client->setAccessType('offline');

        $client->setPrompt('consent');

        return $client;
    }
}
