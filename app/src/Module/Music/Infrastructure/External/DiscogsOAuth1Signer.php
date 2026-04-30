<?php

declare(strict_types=1);

namespace App\Module\Music\Infrastructure\External;

final class DiscogsOAuth1Signer
{
    public function buildAuthorizationHeader(
        string $method,
        string $url,
        string $consumerKey,
        string $consumerSecret,
        string $tokenSecret,
        string $oauthToken = '',
        array $extraParams = [],
        ?string $overrideNonce = null,
        ?int $overrideTimestamp = null,
    ): string {
        $oauthParams = [
            'oauth_consumer_key' => $consumerKey,
            'oauth_nonce' => $overrideNonce ?? bin2hex(random_bytes(16)),
            'oauth_signature_method' => 'HMAC-SHA1',
            'oauth_timestamp' => (string) ($overrideTimestamp ?? time()),
            'oauth_version' => '1.0',
        ];

        if ($oauthToken !== '') {
            $oauthParams['oauth_token'] = $oauthToken;
        }

        $allParams = array_merge($oauthParams, array_map('strval', $extraParams));
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

        $oauthParams['oauth_signature'] = base64_encode(hash_hmac('sha1', $baseString, $signingKey, true));

        $headerParts = array_map(
            fn($k, $v) => rawurlencode($k) . '="' . rawurlencode($v) . '"',
            array_keys($oauthParams),
            array_values($oauthParams)
        );

        return 'OAuth ' . implode(', ', $headerParts);
    }
}
