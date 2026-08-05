<?php

namespace App\Services\PageAudit;

use App\AI\Contracts\AiProvider;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Reads a page the way a first-time visitor would and says what is wrong
 * with it.
 *
 * The deterministic checks answer "is the plumbing there" — a form, a
 * headline, a pixel. They cannot answer the questions that decide whether
 * a stranger signs up: is it obvious what this thing is, does the headline
 * say anything, is there a reason to act now. That is what this pass is
 * for, and it is kept out of the score on purpose: a model's opinion
 * should not move a number a creator tracks week to week.
 *
 * Findings are useless unless specific, so the prompt demands the quoted
 * words being criticised and a concrete replacement. "Improve your
 * headline" helps nobody.
 */
class UxCritic
{
    /** Enough prose for the model to judge the pitch without paying for the lot. */
    private const MAX_TEXT = 6000;

    private const MAX_FINDINGS = 6;

    private const SEVERITIES = ['critical', 'warning', 'idea'];

    public function __construct(private readonly AiProvider $ai) {}

    /** @return list<array<string, string>> */
    public function critique(PageContent $content, string $pageType): array
    {
        if (! $this->ai->isConfigured()) {
            return [];
        }

        try {
            $raw = $this->ai->complete($this->system($pageType), $this->prompt($content, $pageType));
        } catch (Throwable $e) {
            Log::warning('UX critique failed', ['error' => $e->getMessage()]);

            return [];
        }

        return $raw === null ? [] : $this->parse($raw);
    }

    private function system(string $pageType): string
    {
        $subject = $pageType === 'kickstarter'
            ? "a Kickstarter project page for a tabletop game"
            : "a pre-launch landing page for a tabletop game heading to Kickstarter";

        return <<<PROMPT
        You are a blunt conversion reviewer looking at {$subject}. You have
        five seconds of a stranger's attention and you are judging whether
        the page earns the next five.

        Rules:
        - Quote the exact words you are criticising. Never say "the headline
          is weak" without saying which headline and why.
        - Every finding ends with a concrete replacement or action the
          creator can do today.
        - Say nothing you cannot support from the content given. You are
          seeing extracted text and structure, not a screenshot, so do not
          comment on colour, spacing or imagery you cannot see.
        - Rank by what costs the most signups. Ignore nitpicks.
        - British English. No preamble, no praise sandwich.

        Reply as a JSON array of at most 6 objects, nothing else:
        [{"severity":"critical|warning|idea","title":"short claim",
          "body":"what is wrong and why it costs signups",
          "fix":"the specific change to make"}]
        PROMPT;
    }

    private function prompt(PageContent $content, string $pageType): string
    {
        $headings = implode("\n", array_map(
            fn (array $h) => str_repeat('  ', $h['level'] - 1).'H'.$h['level'].': '.$h['text'],
            array_slice($content->headings, 0, 30),
        ));

        $ctas = $content->ctas === []
            ? '(none found)'
            : implode(' | ', array_slice($content->ctas, 0, 15));

        $fields = $content->formFields === []
            ? '(no form fields found)'
            : implode(', ', $content->formFields);

        $fold = $content->wordsBeforeFirstCta === null
            ? 'no call to action anywhere on the page'
            : "{$content->wordsBeforeFirstCta} words of copy before the first call to action";

        $text = mb_substr($content->text, 0, self::MAX_TEXT);

        return <<<PROMPT
        Page type: {$pageType}
        Title tag: {$content->title}

        Headings in order:
        {$headings}

        Calls to action, in the order a reader meets them:
        {$ctas}

        Form asks for: {$fields}
        Reading order: {$fold}
        Length: {$content->wordCount} words, {$content->imageCount} images
        Video present: {$this->yesNo($content->hasVideo)}

        Visible text:
        {$text}
        PROMPT;
    }

    /**
     * Models wrap JSON in prose or fences however firmly you ask them not
     * to, so pull the array out rather than trusting the whole reply.
     *
     * @return list<array<string, string>>
     */
    private function parse(string $raw): array
    {
        $start = strpos($raw, '[');
        $end = strrpos($raw, ']');

        if ($start === false || $end === false || $end < $start) {
            return [];
        }

        $decoded = json_decode(substr($raw, $start, $end - $start + 1), true);

        if (! is_array($decoded)) {
            return [];
        }

        $findings = [];

        foreach ($decoded as $item) {
            if (! is_array($item) || blank($item['title'] ?? null)) {
                continue;
            }

            $severity = mb_strtolower((string) ($item['severity'] ?? 'idea'));

            $findings[] = [
                'severity' => in_array($severity, self::SEVERITIES, true) ? $severity : 'idea',
                'title' => (string) $item['title'],
                'body' => (string) ($item['body'] ?? ''),
                'fix' => (string) ($item['fix'] ?? ''),
            ];

            if (count($findings) >= self::MAX_FINDINGS) {
                break;
            }
        }

        return $findings;
    }

    private function yesNo(bool $value): string
    {
        return $value ? 'yes' : 'no';
    }
}
