<?php

namespace App\Services\LandingPage;

use App\Models\LandingPageAnalysis;
use App\Models\Project;
use App\Support\PublicUrl;
use DOMDocument;
use DOMXPath;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Audits a creator's own landing page against the things that decide
 * pre-launch conversion: does it capture emails, prove credibility, load
 * fast, and is any of it measurable?
 *
 * Checks are deterministic and rule-based so the same page always scores
 * the same; the AI layer only adds prose on top.
 */
class LandingPageAnalyser
{
    private const TIMEOUT = 15;
    private const MAX_REDIRECTS = 3;

    public function analyse(Project $project, string $url): LandingPageAnalysis
    {
        PublicUrl::assertSafe($url);

        [$response, $finalUrl, $elapsedMs] = $this->fetch($url);

        $html = $response->body();
        $checks = $this->runChecks($html, $finalUrl, $response->status(), $elapsedMs);

        $earned = 0;
        $total = 0;

        foreach ($checks as $check) {
            $total += $check->weight;
            $earned += $check->passed ? $check->weight : 0;
        }

        $score = $total > 0 ? (int) round($earned / $total * 100) : 0;

        return $project->landingPageAnalyses()->create([
            'url' => $finalUrl,
            'score' => $score,
            'checks' => array_map(fn (PageCheck $c) => $c->toArray(), $checks),
            'summary' => $this->summarise($checks, $score),
        ]);
    }

    /** @return array{0: \Illuminate\Http\Client\Response, 1: string, 2: int} */
    private function fetch(string $url): array
    {
        $startedAt = microtime(true);
        $current = $url;

        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            try {
                $response = Http::withoutRedirecting()
                    ->timeout(self::TIMEOUT)
                    ->withHeaders(['User-Agent' => 'KickstarterLaunchOS/1.0 (landing page analyser)'])
                    ->get($current);
            } catch (Throwable $e) {
                throw new RuntimeException("Could not reach that URL: {$e->getMessage()}");
            }

            if (! $response->redirect()) {
                return [$response, $current, (int) ((microtime(true) - $startedAt) * 1000)];
            }

            $location = $response->header('Location');

            if ($location === '') {
                throw new RuntimeException('That URL redirected without a destination.');
            }

            // Redirect targets are user-influenced too, so re-check each hop.
            $current = str_starts_with($location, 'http')
                ? $location
                : rtrim($current, '/').'/'.ltrim($location, '/');

            PublicUrl::assertSafe($current);
        }

        throw new RuntimeException('That URL redirected too many times.');
    }

    /** @return list<PageCheck> */
    private function runChecks(string $html, string $url, int $status, int $elapsedMs): array
    {
        $document = new DOMDocument;
        libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8">'.$html);
        libxml_clear_errors();

        $xpath = new DOMXPath($document);
        $text = strtolower(strip_tags($html));

        $has = fn (string $query) => $xpath->query($query)->length > 0;

        return [
            new PageCheck('reachable', 'Page loads', $status >= 200 && $status < 300, 15,
                'The page must return a success response — check for errors or redirects.'),

            new PageCheck('https', 'Served over HTTPS', str_starts_with($url, 'https://'), 10,
                'Serve over HTTPS. Browsers warn on insecure forms, which kills signups.'),

            new PageCheck('email_capture', 'Captures email addresses',
                $has('//input[@type="email"]')
                    || $has('//form//input[contains(@name, "mail")]')
                    || $this->hasEmbeddedFormScript($html), 20,
                'Add an email capture form — your list is the single biggest predictor of day-one funding.'),

            new PageCheck('headline', 'Has a clear headline', $has('//h1'), 10,
                'Add an H1 headline stating what the product is in one line.'),

            new PageCheck('meta_description', 'Has a meta description',
                $has('//meta[@name="description"]'), 5,
                'Add a meta description — it is the preview text when your page is shared.'),

            new PageCheck('mobile_viewport', 'Mobile ready',
                $has('//meta[@name="viewport"]'), 10,
                'Add a viewport meta tag. Most pre-launch ad traffic is on mobile.'),

            new PageCheck('video', 'Includes a video',
                $has('//video') || str_contains($text, 'youtube') || str_contains($text, 'vimeo'), 5,
                'Add a short product video — campaigns with video raise significantly more.'),

            new PageCheck('social_proof', 'Shows social proof',
                $this->mentionsAny($text, ['testimonial', 'review', 'as seen', 'backers', 'loved by', 'trusted by']), 5,
                'Add testimonials or press mentions; strangers need a reason to believe you.'),

            new PageCheck('faq', 'Answers objections',
                $this->mentionsAny($text, ['faq', 'frequently asked', 'questions']), 5,
                'Add an FAQ covering shipping, timelines and price — unanswered doubts cost signups.'),

            new PageCheck('kickstarter_cta', 'Mentions the Kickstarter launch',
                $this->mentionsAny($text, ['kickstarter', 'notify me', 'launch', 'early bird']), 5,
                'Say clearly that you are launching on Kickstarter and invite people to be notified.'),

            new PageCheck('tracking', 'Has analytics or pixel tracking',
                $this->mentionsAny($html, ['gtag(', 'googletagmanager', 'fbq(', 'connect.facebook.net', 'plausible', 'posthog']), 5,
                'Install GA4 and the Meta pixel, otherwise ad spend cannot be measured or optimised.'),

            new PageCheck('fast', 'Responds quickly', $elapsedMs < 2000, 5,
                'The page took over two seconds to respond. Slow pages lose paid traffic before it arrives.'),
        ];
    }

    /**
     * Most email tools inject their form with JavaScript, so the markup we
     * fetch has no <input> at all — only the embed script. Detecting the
     * embed avoids telling a creator their working form does not exist.
     */
    private function hasEmbeddedFormScript(string $html): bool
    {
        return $this->mentionsAny($html, [
            'mailerlite',        // ml-form, webforms.mailerlite.com, ml_webform
            'ck.convertkit',
            'convertkit',
            'list-manage.com',   // Mailchimp
            'mc-embedded',
            'beehiiv',
            'klaviyo',
            'substack',
            'omnisend',
            'kickstarter.com/projects', // "Notify me" embed
        ]);
    }

    private function mentionsAny(string $haystack, array $needles): bool
    {
        $haystack = strtolower($haystack);

        foreach ($needles as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }

    /** @param  list<PageCheck>  $checks */
    private function summarise(array $checks, int $score): string
    {
        $failed = array_values(array_filter($checks, fn (PageCheck $c) => ! $c->passed));

        if ($failed === []) {
            return "Your page passes every check (score {$score}). Focus on traffic and copy testing next.";
        }

        usort($failed, fn (PageCheck $a, PageCheck $b) => $b->weight <=> $a->weight);

        $headline = array_slice(array_map(fn (PageCheck $c) => strtolower($c->label), $failed), 0, 3);

        return "Score {$score}. Biggest wins: ".implode(', ', $headline).'.';
    }
}
