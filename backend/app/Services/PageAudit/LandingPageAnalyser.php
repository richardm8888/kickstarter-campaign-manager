<?php

namespace App\Services\PageAudit;

/**
 * Audits a creator's own landing page against the things that decide
 * pre-launch conversion.
 *
 * Two layers. The plumbing checks ask whether the machinery exists at all
 * — a form, a pixel, HTTPS. The experience checks ask whether a stranger
 * would actually use it: one decision or four, two form fields or six, a
 * next step in sight or four hundred words of story first. The second set
 * is where most pages lose their signups, and none of it is visible to a
 * yes/no markup test.
 */
class LandingPageAnalyser extends PageAnalyser
{
    /** More than this and the page is asking a visitor to choose. */
    private const MAX_CTAS = 3;

    /** Every field past an address measurably costs signups. */
    private const MAX_FORM_FIELDS = 2;

    private const GENERIC_HEADLINES = [
        'home', 'welcome', 'coming soon', 'landing page', 'untitled',
        'my site', 'index', 'hello world',
    ];

    public function pageType(): string
    {
        return 'landing';
    }

    /** @return list<PageCheck> */
    protected function checks(PageContent $content, FetchedPage $page): array
    {
        return [
            ...$this->plumbingChecks($content, $page),
            ...$this->experienceChecks($content),
        ];
    }

    /** @return list<PageCheck> */
    private function plumbingChecks(PageContent $content, FetchedPage $page): array
    {
        $hasForm = $content->formFields !== [] || $this->hasEmbeddedFormScript($content);

        return [
            PageCheck::when($page->ok(), 'reachable', 'Page loads', 15,
                'The page must return a success response — check for errors or redirects.',
                "HTTP {$page->status}"),

            PageCheck::when(str_starts_with($page->url, 'https://'), 'https', 'Served over HTTPS', 10,
                'Serve over HTTPS. Browsers warn on insecure forms, which kills signups.'),

            PageCheck::when($hasForm, 'email_capture', 'Captures email addresses', 20,
                'Add an email capture form — your list is the single biggest predictor of day-one funding.'),

            PageCheck::when($content->headline() !== null, 'headline', 'Has a clear headline', 10,
                'Add an H1 headline stating what the product is in one line.',
                $content->headline()),

            PageCheck::when($content->markupMentions(['name="description"']), 'meta_description',
                'Has a meta description', 5,
                'Add a meta description — it is the preview text when your page is shared.'),

            PageCheck::when($content->markupMentions(['name="viewport"']), 'mobile_viewport', 'Mobile ready', 10,
                'Add a viewport meta tag. Most pre-launch ad traffic is on mobile.'),

            PageCheck::when($content->hasVideo, 'video', 'Includes a video', 5,
                'Add a short product video — campaigns with video raise significantly more.'),

            PageCheck::when(
                $content->mentions(['testimonial', 'review', 'as seen', 'backers', 'loved by', 'trusted by']),
                'social_proof', 'Shows social proof', 5,
                'Add testimonials or press mentions; strangers need a reason to believe you.'),

            PageCheck::when($content->mentions(['faq', 'frequently asked', 'questions']),
                'faq', 'Answers objections', 5,
                'Add an FAQ covering shipping, timelines and price — unanswered doubts cost signups.'),

            PageCheck::when($content->mentions(['kickstarter', 'notify me', 'launch', 'early bird']),
                'kickstarter_cta', 'Mentions the Kickstarter launch', 5,
                'Say clearly that you are launching on Kickstarter and invite people to be notified.'),

            PageCheck::when(
                $content->markupMentions(['gtag(', 'googletagmanager', 'fbq(', 'connect.facebook.net', 'plausible', 'posthog']),
                'tracking', 'Has analytics or pixel tracking', 5,
                'Install GA4 and the Meta pixel, otherwise ad spend cannot be measured or optimised.'),

            PageCheck::when($page->elapsedMs < 2000, 'fast', 'Responds quickly', 5,
                'The page took over two seconds to respond. Slow pages lose paid traffic before it arrives.',
                "{$page->elapsedMs}ms"),
        ];
    }

    /** @return list<PageCheck> */
    private function experienceChecks(PageContent $content): array
    {
        return [
            $this->singleDecision($content),
            $this->shortForm($content),
            $this->ctaInSight($content),
            $this->specificHeadline($content),
            $this->headingStructure($content),
            $this->scannable($content),
            $this->imageAltText($content),
        ];
    }

    /**
     * A pre-launch page has exactly one job. Every extra button splits the
     * traffic you paid for between outcomes you do not want.
     */
    private function singleDecision(PageContent $content): PageCheck
    {
        $count = count($content->ctas);

        if ($count === 0) {
            return PageCheck::fail('single_decision', 'Asks for one thing', 10,
                'There is no clear call to action. Add one button that asks for an email address.',
                'no calls to action found');
        }

        return PageCheck::when($count <= self::MAX_CTAS, 'single_decision', 'Asks for one thing', 10,
            'Cut the page down to one ask. Competing buttons split the traffic you paid for.',
            $count.' distinct '.($count === 1 ? 'call to action' : 'calls to action'));
    }

    private function shortForm(PageContent $content): PageCheck
    {
        $count = count($content->formFields);

        if ($count === 0) {
            return PageCheck::unknown('short_form', 'Form is short',
                'The form is loaded by JavaScript, so its fields cannot be counted from the markup. Keep it to an email address.');
        }

        return PageCheck::when($count <= self::MAX_FORM_FIELDS, 'short_form', 'Form is short', 10,
            'Ask for the email address and nothing else. Every extra field costs signups, and you can collect the rest later.',
            $count.' '.($count === 1 ? 'field' : 'fields').': '.implode(', ', array_slice($content->formFields, 0, 6)));
    }

    /**
     * Reading order stands in for the fold: if a visitor must wade through
     * several hundred words before meeting a next step, most never do.
     */
    private function ctaInSight(PageContent $content): PageCheck
    {
        $words = $content->wordsBeforeFirstCta;

        if ($words === null) {
            return PageCheck::fail('cta_in_sight', 'Next step is visible early', 10,
                'Put a signup button near the top. A visitor should never have to hunt for the next step.',
                'no call to action anywhere');
        }

        return PageCheck::when($words <= PageContent::FOLD_WORDS, 'cta_in_sight', 'Next step is visible early', 10,
            'Move a signup button above the story. Most paid traffic never scrolls far enough to find it.',
            "{$words} words of copy before the first call to action");
    }

    private function specificHeadline(PageContent $content): PageCheck
    {
        $headline = $content->headline();

        if ($headline === null) {
            return PageCheck::unknown('specific_headline', 'Headline says something',
                'No H1 to judge — add one first.');
        }

        $lower = mb_strtolower($headline);
        $generic = in_array($lower, self::GENERIC_HEADLINES, true) || mb_strlen($headline) < 12;

        return PageCheck::when(! $generic, 'specific_headline', 'Headline says something', 10,
            'Replace the headline with what the game is and who it is for. A stranger should understand it without scrolling.',
            '"'.$headline.'"');
    }

    private function headingStructure(PageContent $content): PageCheck
    {
        $h1 = $content->countHeadings(1);

        return PageCheck::when($h1 === 1, 'heading_structure', 'One clear headline', 5,
            $h1 === 0
                ? 'Add a single H1. Screen readers and search engines both use it as the page title.'
                : 'Use one H1 only. Several competing top-level headings blur what the page is about.',
            $h1.' H1 '.($h1 === 1 ? 'heading' : 'headings'));
    }

    /**
     * Long copy is fine; long copy with nothing to break it up is not —
     * nobody reads a wall of text on a phone.
     */
    private function scannable(PageContent $content): PageCheck
    {
        if ($content->wordCount < 200) {
            return PageCheck::pass('scannable', 'Copy is scannable', 5, "{$content->wordCount} words");
        }

        $subheadings = $content->countHeadings(2) + $content->countHeadings(3);
        $wordsPerSection = $subheadings > 0 ? (int) round($content->wordCount / ($subheadings + 1)) : $content->wordCount;

        return PageCheck::when($wordsPerSection <= 200, 'scannable', 'Copy is scannable', 5,
            'Break the copy up with subheadings. Visitors scan before they read, and an unbroken block gets skipped.',
            "{$content->wordCount} words across ".($subheadings + 1).' sections');
    }

    private function imageAltText(PageContent $content): PageCheck
    {
        if ($content->imageCount === 0) {
            return PageCheck::unknown('image_alt', 'Images are described',
                'No images found — a product page should show the product.');
        }

        return PageCheck::when($content->imagesWithoutAlt === 0, 'image_alt', 'Images are described', 5,
            'Add alt text to every image. It is what screen readers announce, and what shows when an image fails to load.',
            "{$content->imagesWithoutAlt} of {$content->imageCount} images missing alt text");
    }

    /**
     * Most email tools inject their form with JavaScript, so the markup we
     * fetch has no <input> at all — only the embed script. Detecting the
     * embed avoids telling a creator their working form does not exist.
     */
    private function hasEmbeddedFormScript(PageContent $content): bool
    {
        return $content->markupMentions([
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
}
