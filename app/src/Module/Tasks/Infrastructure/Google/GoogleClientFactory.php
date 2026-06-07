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
        // Fail loudly at boot when GOOGLE_OAUTH_* env vars are blank or the
        // redirect URI is malformed. Without this, the misconfig surfaces only
        // mid-OAuth flow as an opaque 400 from Google (HMAI-90).
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
        // YouTubeProgress (HMAI-163) reuses this same OAuth client. Google scope
        // claims are cumulative on the refresh token, so adding the full-access
        // youtube scope here lets one token serve both Calendar (Tasks) and
        // YouTube (YouTubeProgress). Full youtube (not youtube.readonly) is
        // required for write operations such as playlist creation.
        $client->addScope(YouTube::YOUTUBE);
        $client->setAccessType('offline');
        // prompt=consent forces the consent screen on re-authorization so an
        // existing Calendar-only user re-grants with the widened scope; without
        // it Google skips consent and the youtube scope never lands in the token.
        $client->setPrompt('consent');

        return $client;
    }
}
