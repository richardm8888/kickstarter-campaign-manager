<?php

namespace App\Services\PageAudit;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;

/**
 * A page parsed once into the things a reader actually encounters:
 * headings, calls to action, form fields, prose and images.
 *
 * The original analyser asked the markup yes/no questions — is there an
 * H1, is there a form. That catches missing plumbing but not the failures
 * that actually cost signups: four buttons competing for one decision, a
 * form asking six questions, a wall of text with no CTA in sight. Those
 * need the page read in order, which is what this builds.
 */
class PageContent
{
    /** Words of body copy a visitor plausibly sees before scrolling. */
    public const FOLD_WORDS = 120;

    private const CTA_VERBS = [
        'sign up', 'signup', 'subscribe', 'join', 'notify', 'get notified',
        'follow', 'back this', 'pre-order', 'preorder', 'reserve', 'claim',
        'buy', 'shop', 'order', 'download', 'register', 'get early',
        'learn more', 'read more', 'find out', 'contact', 'donate',
    ];

    private function __construct(
        public readonly string $title,
        /** @var list<array{level: int, text: string}> */
        public readonly array $headings,
        /** @var list<string> visible label of every button and prominent link */
        public readonly array $ctas,
        /** @var list<string> input types/names a visitor must fill in */
        public readonly array $formFields,
        public readonly string $text,
        public readonly int $wordCount,
        /** Words of prose before the first call to action, null when none. */
        public readonly ?int $wordsBeforeFirstCta,
        public readonly int $imageCount,
        public readonly int $imagesWithoutAlt,
        public readonly bool $hasVideo,
        public readonly string $html,
    ) {}

    public static function parse(string $html): self
    {
        $document = new DOMDocument;
        libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8">'.$html);
        libxml_clear_errors();

        $xpath = new DOMXPath($document);

        // Script and style text is not content; leaving it in wrecks every
        // word count and reading-order measurement below.
        foreach (iterator_to_array($xpath->query('//script | //style | //noscript')) as $node) {
            $node->parentNode?->removeChild($node);
        }

        $headings = [];

        foreach ($xpath->query('//h1 | //h2 | //h3') as $node) {
            $text = self::clean($node->textContent);

            if ($text !== '') {
                $headings[] = ['level' => (int) substr($node->nodeName, 1), 'text' => $text];
            }
        }

        [$ctas, $wordsBeforeFirstCta] = self::readInOrder($xpath);

        $formFields = [];

        foreach ($xpath->query('//input | //textarea | //select') as $node) {
            /** @var DOMElement $node */
            $type = strtolower($node->getAttribute('type'));

            // Hidden fields and the submit button are not asks of the visitor.
            if (in_array($type, ['hidden', 'submit', 'button', 'image', 'reset'], true)) {
                continue;
            }

            $formFields[] = $node->getAttribute('name') ?: ($type ?: $node->nodeName);
        }

        $images = $xpath->query('//img');
        $withoutAlt = 0;

        foreach ($images as $image) {
            /** @var DOMElement $image */
            if (trim($image->getAttribute('alt')) === '') {
                $withoutAlt++;
            }
        }

        $text = self::clean($document->textContent ?? '');
        $lower = strtolower($html);

        return new self(
            title: self::clean($xpath->query('//title')->item(0)?->textContent ?? ''),
            headings: $headings,
            ctas: $ctas,
            formFields: $formFields,
            text: $text,
            wordCount: str_word_count($text),
            wordsBeforeFirstCta: $wordsBeforeFirstCta,
            imageCount: $images->length,
            imagesWithoutAlt: $withoutAlt,
            hasVideo: $xpath->query('//video | //iframe[contains(@src, "youtube") or contains(@src, "vimeo")]')->length > 0
                || str_contains($lower, 'youtube.com/embed')
                || str_contains($lower, 'player.vimeo'),
            html: $html,
        );
    }

    /**
     * Walk the body in document order, collecting calls to action and
     * noting how much prose came before the first one — the closest we get
     * to "would a visitor see a next step without scrolling" from markup
     * alone.
     *
     * @return array{0: list<string>, 1: int|null}
     */
    private static function readInOrder(DOMXPath $xpath): array
    {
        $ctas = [];
        $words = 0;
        $wordsBeforeFirstCta = null;

        $nodes = $xpath->query('//body//a | //body//button | //body//input[@type="submit"] | //body//p | //body//li');

        foreach ($nodes as $node) {
            /** @var DOMNode $node */
            if (in_array($node->nodeName, ['p', 'li'], true)) {
                $words += str_word_count(self::clean($node->textContent));

                continue;
            }

            $label = $node instanceof DOMElement && $node->nodeName === 'input'
                ? self::clean($node->getAttribute('value'))
                : self::clean($node->textContent);

            if ($label === '' || ! self::looksLikeCta($label, $node)) {
                continue;
            }

            $ctas[] = $label;
            $wordsBeforeFirstCta ??= $words;
        }

        return [array_values(array_unique($ctas)), $wordsBeforeFirstCta];
    }

    /**
     * Navigation links are not calls to action. A button always counts; a
     * link only when its wording asks for a decision, which keeps a header
     * full of menu items from reading as fifteen competing CTAs.
     */
    private static function looksLikeCta(string $label, DOMNode $node): bool
    {
        if (mb_strlen($label) > 60) {
            return false;
        }

        if ($node->nodeName !== 'a') {
            return true;
        }

        $lower = mb_strtolower($label);

        foreach (self::CTA_VERBS as $verb) {
            if (str_contains($lower, $verb)) {
                return true;
            }
        }

        return false;
    }

    /** @param  list<string>  $needles */
    public function mentions(array $needles): bool
    {
        $haystack = mb_strtolower($this->text);

        foreach ($needles as $needle) {
            if (str_contains($haystack, mb_strtolower($needle))) {
                return true;
            }
        }

        return false;
    }

    /** @param  list<string>  $needles  matched against raw markup, not prose */
    public function markupMentions(array $needles): bool
    {
        $haystack = mb_strtolower($this->html);

        foreach ($needles as $needle) {
            if (str_contains($haystack, mb_strtolower($needle))) {
                return true;
            }
        }

        return false;
    }

    public function headline(): ?string
    {
        foreach ($this->headings as $heading) {
            if ($heading['level'] === 1) {
                return $heading['text'];
            }
        }

        return null;
    }

    public function countHeadings(int $level): int
    {
        return count(array_filter($this->headings, fn (array $h) => $h['level'] === $level));
    }

    private static function clean(string $text): string
    {
        return trim(preg_replace('/\s+/u', ' ', $text) ?? '');
    }
}
