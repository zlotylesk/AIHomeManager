<?php

declare(strict_types=1);

namespace App\Module\Tasks\Infrastructure\Google;

use Google\Client;
use Google\Service\Calendar;

final readonly class GoogleClientFactory
{
    public function __construct(
        private string $clientId,
        private string $clientSecret,
        private string $redirectUri,
    ) {
    }

    public function create(): Client
    {
        $client = new Client();
        $client->setClientId($this->clientId);
        $client->setClientSecret($this->clientSecret);
        $client->setRedirectUri($this->redirectUri);
        $client->addScope(Calendar::CALENDAR_EVENTS);
        $client->setAccessType('offline');
        $client->setPrompt('consent');

        return $client;
    }
}
