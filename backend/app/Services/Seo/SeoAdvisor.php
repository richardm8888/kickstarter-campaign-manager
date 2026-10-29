<?php

namespace App\Services\Seo;

use App\AI\Contracts\AiProvider;
use App\AI\JsonList;
use App\Launch\ContentWriter;
use App\Launch\Playbook;
use App\Models\Project;

/**
 * Turns the SEO analysis into a short, ranked to-do list.
 *
 * The analyser has already found the opportunities; this decides which
 * matter most for this project and says what to do about each in plain
 * words. Every recommendation is shaped like a launch task, so the page
 * can add it to the plan with one tap.
 */
class SeoAdvisor
{
    private const MAX_RECOMMENDATIONS = 8;

    private const MAX_TOKENS = 3000;

    private const PRIORITIES = ['high', 'medium', 'low'];

    /** Only the categories SEO work lands in; anything else becomes "website". */
    private const CATEGORIES = ['website', 'outreach', 'social', 'email', 'bgg'];

    private const CONTENT_TYPES = ['blog_post', 'outreach_email', 'social_post', 'email'];

    public function __construct(private readonly AiProvider $ai) {}

    public function available(): bool
    {
        return $this->ai->isConfigured();
    }

    /**
     * @param  array<string, mixed>  $analysis
     * @return list<array<string, mixed>>|null null when the AI gave nothing usable
     */
    public function recommend(Project $project, array $analysis): ?array
    {
        $reply = $this->ai->complete($this->system($project), $this->prompt($analysis), self::MAX_TOKENS);

        if (blank($reply)) {
            return null;
        }

        $recommendations = [];

        foreach (JsonList::parse($reply) as $item) {
            if (blank($item['title'] ?? null) || blank($item['action'] ?? null)) {
                continue;
            }

            $category = (string) ($item['category'] ?? '');
            $type = (string) ($item['content_type'] ?? '');
            $priority = mb_strtolower((string) ($item['priority'] ?? 'medium'));

            $recommendations[] = [
                'title' => mb_substr((string) $item['title'], 0, 255),
                'why' => (string) ($item['why'] ?? ''),
                'action' => (string) $item['action'],
                'priority' => in_array($priority, self::PRIORITIES, true) ? $priority : 'medium',
                'category' => in_array($category, self::CATEGORIES, true) ? $category : 'website',
                'content_type' => in_array($type, self::CONTENT_TYPES, true) ? $type : null,
            ];

            if (count($recommendations) >= self::MAX_RECOMMENDATIONS) {
                break;
            }
        }

        return $recommendations === [] ? null : $recommendations;
    }

    private function system(Project $project): string
    {
        $categories = implode(', ', array_map(fn ($key) => "{$key} (".Playbook::CATEGORIES[$key].')', self::CATEGORIES));
        $types = implode(', ', array_map(fn ($key) => "{$key} (".ContentWriter::TYPES[$key]['label'].')', self::CONTENT_TYPES));

        $brief = filled($project->content_brief) ? "\n\nAbout the project, from its creators:\n{$project->content_brief}" : '';

        return <<<PROMPT
        You are an experienced SEO consultant advising the creators of a crowdfunding project ("{$project->name}") whose website exists to grow their Kickstarter followers and mailing list.

        You are given their Google Search Console analysis. Recommend at most 8 actions, most valuable first. Base every recommendation on numbers in the analysis and quote them in "why". Prefer specific, achievable actions over general advice: which page, which search, what to change.

        Typical levers: rewriting a page's title and meta description to match a search it already appears for; adding a section or FAQ that answers a search; one page per topic when several compete; internal links; a new post for a search with demand but no good page; asking sites that rank for a search to mention the project.

        Reply with only a JSON array. Each item:
        {"title": "short imperative task name", "why": "the evidence, with numbers", "action": "exactly what to do", "priority": "high|medium|low", "category": one of [{$categories}], "content_type": one of [{$types}] or null}

        Use content_type only when the action is mainly writing one of those things. British English. Never invent numbers that are not in the analysis.{$brief}
        PROMPT;
    }

    /** @param array<string, mixed> $analysis */
    private function prompt(array $analysis): string
    {
        // The full analysis is small (a few dozen rows per list), and every
        // row is evidence the advice can cite.
        return "Search Console analysis (positions: 1 is the top result; ctr in %):\n"
            .json_encode($analysis, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
