<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Guards server-side fetches of user-supplied URLs.
 *
 * Without this, a creator could point the analyser at 127.0.0.1 or at a
 * cloud metadata endpoint (169.254.169.254) and have the server fetch it
 * on their behalf — classic SSRF. Only public HTTP(S) hosts are allowed.
 */
final class PublicUrl
{
    /** Returns the URL unchanged when safe, otherwise throws. */
    public static function assertSafe(string $url): string
    {
        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['host'])) {
            throw new InvalidArgumentException('That does not look like a valid URL.');
        }

        if (! in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)) {
            throw new InvalidArgumentException('Only http and https URLs can be analysed.');
        }

        $host = $parts['host'];

        foreach (self::resolve($host) as $ip) {
            if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                throw new InvalidArgumentException('That URL points to a private address and cannot be analysed.');
            }
        }

        return $url;
    }

    public static function isSafe(string $url): bool
    {
        try {
            self::assertSafe($url);

            return true;
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    /** @return list<string> every address the host resolves to */
    private static function resolve(string $host): array
    {
        // A literal address needs no lookup.
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return [$host];
        }

        $addresses = array_merge(
            gethostbynamel($host) ?: [],
            array_column(@dns_get_record($host, DNS_AAAA) ?: [], 'ipv6'),
        );

        if ($addresses === []) {
            throw new InvalidArgumentException('That domain could not be resolved.');
        }

        return $addresses;
    }
}
