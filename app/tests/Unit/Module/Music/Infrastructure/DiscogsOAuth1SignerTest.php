<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Music\Infrastructure;

use App\Module\Music\Infrastructure\External\DiscogsOAuth1Signer;
use PHPUnit\Framework\TestCase;

final class DiscogsOAuth1SignerTest extends TestCase
{
    private DiscogsOAuth1Signer $signer;

    protected function setUp(): void
    {
        $this->signer = new DiscogsOAuth1Signer();
    }

    public function testHeaderContainsRequiredOAuthFields(): void
    {
        $header = $this->signer->buildAuthorizationHeader(
            method: 'GET',
            url: 'https://api.discogs.com/test',
            consumerKey: 'my_key',
            consumerSecret: 'my_secret',
            tokenSecret: 'token_secret',
            oauthToken: 'access_token',
        );

        self::assertStringStartsWith('OAuth ', $header);
        self::assertStringContainsString('oauth_consumer_key="my_key"', $header);
        self::assertStringContainsString('oauth_signature_method="HMAC-SHA1"', $header);
        self::assertStringContainsString('oauth_version="1.0"', $header);
        self::assertStringContainsString('oauth_token="access_token"', $header);
        self::assertStringContainsString('oauth_signature=', $header);
        self::assertStringContainsString('oauth_nonce=', $header);
        self::assertStringContainsString('oauth_timestamp=', $header);
    }

    public function testHeaderDoesNotContainTokenWhenEmpty(): void
    {
        $header = $this->signer->buildAuthorizationHeader(
            method: 'POST',
            url: 'https://api.discogs.com/oauth/request_token',
            consumerKey: 'my_key',
            consumerSecret: 'my_secret',
            tokenSecret: '',
        );

        self::assertStringNotContainsString('oauth_token=', $header);
    }

    public function testKnownAnswerTest(): void
    {
        $method = 'GET';
        $url = 'https://api.discogs.com/users/testuser/collection/folders/0/releases';
        $consumerKey = 'consumer_key';
        $consumerSecret = 'consumer_secret';
        $tokenSecret = 'token_secret';
        $oauthToken = 'access_token';
        $nonce = 'test_nonce_abc';
        $timestamp = 1700000000;
        $extraParams = ['per_page' => '100', 'page' => '1'];

        $header = $this->signer->buildAuthorizationHeader(
            method: $method,
            url: $url,
            consumerKey: $consumerKey,
            consumerSecret: $consumerSecret,
            tokenSecret: $tokenSecret,
            oauthToken: $oauthToken,
            extraParams: $extraParams,
            overrideNonce: $nonce,
            overrideTimestamp: $timestamp,
        );

        // Compute expected signature independently
        $oauthBaseParams = [
            'oauth_consumer_key' => $consumerKey,
            'oauth_nonce' => $nonce,
            'oauth_signature_method' => 'HMAC-SHA1',
            'oauth_timestamp' => (string) $timestamp,
            'oauth_token' => $oauthToken,
            'oauth_version' => '1.0',
        ];

        $allParams = array_merge($oauthBaseParams, array_map('strval', $extraParams));
        ksort($allParams);

        $paramString = implode('&', array_map(
            fn($k, $v) => rawurlencode($k) . '=' . rawurlencode($v),
            array_keys($allParams),
            array_values($allParams)
        ));

        $baseString = strtoupper($method)
            . '&' . rawurlencode($url)
            . '&' . rawurlencode($paramString);

        $signingKey = rawurlencode($consumerSecret) . '&' . rawurlencode($tokenSecret);
        $expectedSig = base64_encode(hash_hmac('sha1', $baseString, $signingKey, true));
        $expectedSigEncoded = rawurlencode($expectedSig);

        self::assertStringContainsString(sprintf('oauth_signature="%s"', $expectedSigEncoded), $header);
    }

    public function testSameInputsProduceSameSignature(): void
    {
        $args = [
            'GET', 'https://api.discogs.com/test',
            'key', 'secret', 'tokensecret', 'token', [],
            'fixed_nonce', 1700000001,
        ];

        $header1 = $this->signer->buildAuthorizationHeader(...$args);
        $header2 = $this->signer->buildAuthorizationHeader(...$args);

        self::assertSame($header1, $header2);
    }
}
