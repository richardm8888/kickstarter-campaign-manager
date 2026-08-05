<?php

namespace App\Support;

/**
 * Headers that make a server-side fetch look like the browser it is
 * standing in for.
 *
 * Kickstarter sits behind bot protection that returns 403 to anything
 * announcing itself as a tool, however politely. Identifying honestly is
 * the better instinct and it is what we did first, but it means we cannot
 * read a creator's own public page on their behalf — so we send what a
 * browser sends. A missing Accept-Language is enough on its own to fail
 * the check, which is why this is a full set and not just a User-Agent.
 */
final class BrowserHeaders
{
    /** @return array<string, string> */
    public static function get(): array
    {
        return [
            'User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) '
                .'AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36',
            'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Accept-Language' => 'en-GB,en;q=0.9',
            'Upgrade-Insecure-Requests' => '1',
            'Sec-Fetch-Dest' => 'document',
            'Sec-Fetch-Mode' => 'navigate',
            'Sec-Fetch-Site' => 'none',
        ];
    }
}
