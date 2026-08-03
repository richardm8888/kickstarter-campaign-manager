<?php

namespace App\Integrations\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Mints short-lived Google API access tokens from a service account key.
 *
 * Google access tokens expire after an hour, so a pasted token is useless
 * for an unattended integration. A service account key is durable: we sign
 * a JWT with it and exchange that for a fresh token whenever one is needed.
 */
class GoogleServiceAccount
{
    private const TOKEN_ENDPOINT = 'https://oauth2.googleapis.com/token';
    private const GRANT_TYPE = 'urn:ietf:params:oauth:grant-type:jwt-bearer';
    private const LIFETIME = 3600;

    /**
     * @param  array{client_email: string, private_key: string}  $key
     */
    public function accessToken(array $key, string $scope): string
    {
        return Cache::remember(
            'google-token:'.sha1($key['client_email'].$scope),
            self::LIFETIME - 300,
            fn () => $this->requestToken($key, $scope),
        );
    }

    /** Decode and sanity-check a service account JSON key. */
    public static function parseKey(string $json): array
    {
        $key = json_decode($json, true);

        if (! is_array($key)) {
            throw new RuntimeException('Service account key is not valid JSON.');
        }

        foreach (['client_email', 'private_key'] as $field) {
            if (! isset($key[$field]) || ! is_string($key[$field]) || $key[$field] === '') {
                throw new RuntimeException("Service account key is missing \"{$field}\".");
            }
        }

        if (! str_contains($key['private_key'], 'PRIVATE KEY')) {
            throw new RuntimeException('Service account key does not contain a private key.');
        }

        return $key;
    }

    private function requestToken(array $key, string $scope): string
    {
        $response = Http::asForm()->post(self::TOKEN_ENDPOINT, [
            'grant_type' => self::GRANT_TYPE,
            'assertion' => $this->assertion($key, $scope),
        ])->throw()->json();

        return $response['access_token']
            ?? throw new RuntimeException('Google did not return an access token.');
    }

    /** Build the RS256-signed JWT Google exchanges for an access token. */
    private function assertion(array $key, string $scope): string
    {
        $issuedAt = time();

        $segments = [
            $this->encode(['alg' => 'RS256', 'typ' => 'JWT']),
            $this->encode([
                'iss' => $key['client_email'],
                'scope' => $scope,
                'aud' => self::TOKEN_ENDPOINT,
                'iat' => $issuedAt,
                'exp' => $issuedAt + self::LIFETIME,
            ]),
        ];

        $input = implode('.', $segments);

        if (! openssl_sign($input, $signature, $key['private_key'], OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Could not sign the Google assertion; check the private key.');
        }

        return $input.'.'.$this->base64Url($signature);
    }

    private function encode(array $payload): string
    {
        return $this->base64Url(json_encode($payload, JSON_UNESCAPED_SLASHES));
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
