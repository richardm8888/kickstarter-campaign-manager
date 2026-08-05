<?php

namespace App\Services\PageAudit;

use App\Models\LandingPageAnalysis;
use App\Models\Project;

/**
 * Shared shape for auditing any page a creator points us at.
 *
 * Two passes, deliberately separated. The checks are deterministic and
 * rule-based, so the same page always scores the same and a creator can
 * see progress between runs. The UX walk is the AI reading the page as a
 * visitor would, and it never touches the score — a language model's
 * opinion should not silently move a number people track week to week.
 */
abstract class PageAnalyser
{
    public function __construct(
        protected readonly PageFetcher $fetcher,
        protected readonly UxCritic $critic,
    ) {}

    /** Stored on the analysis so the client can label and route it. */
    abstract public function pageType(): string;

    /** @return list<PageCheck> */
    abstract protected function checks(PageContent $content, FetchedPage $page): array;

    public function analyse(Project $project, string $url): LandingPageAnalysis
    {
        $page = $this->fetcher->fetch($url);
        $content = PageContent::parse($page->html);

        $checks = $this->checks($content, $page);
        $score = $this->score($checks);

        return $project->landingPageAnalyses()->create([
            'url' => $page->url,
            'page_type' => $this->pageType(),
            'score' => $score,
            'checks' => array_map(fn (PageCheck $c) => $c->toArray(), $checks),
            'findings' => $this->critic->critique($content, $this->pageType()),
            'summary' => $this->summarise($checks, $score),
        ]);
    }

    /**
     * Undetermined checks are left out of both sides of the fraction, so a
     * page we could only partly read is scored on what we could read
     * rather than penalised for the rest.
     *
     * @param  list<PageCheck>  $checks
     */
    protected function score(array $checks): int
    {
        $earned = 0;
        $total = 0;

        foreach ($checks as $check) {
            if (! $check->scored()) {
                continue;
            }

            $total += $check->weight;
            $earned += $check->passed() ? $check->weight : 0;
        }

        return $total > 0 ? (int) round($earned / $total * 100) : 0;
    }

    /**
     * Names the single most valuable next action rather than listing check
     * labels. A label describes the thing being tested, so a failed one
     * reads backwards in a summary — "biggest wins: has a video" sounds
     * like the page has one.
     *
     * @param  list<PageCheck>  $checks
     */
    protected function summarise(array $checks, int $score): string
    {
        $failed = array_values(array_filter(
            $checks,
            fn (PageCheck $c) => $c->scored() && ! $c->passed(),
        ));

        if ($failed === []) {
            return "Your page passes every check (score {$score}). Focus on traffic and copy testing next.";
        }

        usort($failed, fn (PageCheck $a, PageCheck $b) => $b->weight <=> $a->weight);

        $count = count($failed);
        $noun = $count === 1 ? 'check needs' : 'checks need';

        return "Score {$score}. {$count} {$noun} work. Start here: {$failed[0]->recommendation}";
    }
}
