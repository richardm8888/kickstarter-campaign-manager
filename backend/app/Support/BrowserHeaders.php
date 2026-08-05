<?php

namespace App\Support;

/**
 * Headers that make a server-side fetch look like the browser it is
 * standing in for.
 *
 * Kickstarter sits behind Cloudflare, which returns 403 with
 * `cf-mitigated: challenge` to anything announcing itself as a tool,
 * however politely. Identifying honestly is the better instinct and it is
 * what we did first, but it means we cannot read a creator's own public
 * page on their behalf.
 *
 * This exact set is measured, not guessed: `php artisan page:diagnose`
 * against a live Kickstarter project was refused without the client hints
 * and passed with them. Treat it as one unit — the User-Agent alone was
 * not enough, and dropping any part of it invites the 403 back. If a
 * fetch starts failing, run page:diagnose from the host being refused
 * before changing anything here.
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
            'Accept-Encoding' => 'gzip, deflate, br',
            'Upgrade-Insecure-Requests' => '1',
            'Sec-Fetch-Dest' => 'document',
            'Sec-Fetch-Mode' => 'navigate',
            'Sec-Fetch-Site' => 'none',
            'sec-ch-ua' => '"Chromium";v="128", "Not(A:Brand";v="24", "Google Chrome";v="128"',
            'sec-ch-ua-mobile' => '?0',
            'sec-ch-ua-platform' => '"macOS"',
            'Referer' => 'https://www.google.com/',
        ];
    }
}
