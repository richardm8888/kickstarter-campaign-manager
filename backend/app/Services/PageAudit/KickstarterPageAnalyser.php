<?php

namespace App\Services\PageAudit;

use App\Models\LandingPageAnalysis;
use App\Models\Project;
use App\Services\Kickstarter\KickstarterFollowers;
use InvalidArgumentException;

/**
 * Audits a Kickstarter project page — the pre-launch one before you go
 * live, the campaign itself afterwards.
 *
 * A Kickstarter page fails differently from a landing page. A pre-launch
 * page has almost nothing on it, and the whole job is the video, the title
 * and one image good enough to survive being a thumbnail. A live page has
 * the opposite problem: too much, too many tiers, and a risks section
 * written the night before launch.
 *
 * Kickstarter publishes no API and changes its markup without notice, so
 * everything here is read defensively. Where a signal cannot be found we
 * record that we could not tell rather than scoring it as absent — a
 * markup change should not tell a creator their video has disappeared.
 */
class KickstarterPageAnalyser extends PageAnalyser
{
    /** Beyond this, choosing becomes work and backers stall. */
    private const MAX_TIERS = 8;

    private const MIN_TIERS = 2;

    /** A story shorter than this is not doing the selling a page must. */
    private const MIN_STORY_WORDS = 300;

    /** Kickstarter truncates long titles in cards and search. */
    private const MAX_TITLE = 60;

    public function pageType(): string
    {
        return 'kickstarter';
    }

    public function analyse(Project $project, string $url): LandingPageAnalysis
    {
        if (! KickstarterFollowers::isValidUrl($url)) {
            throw new InvalidArgumentException(
                'That is not a kickstarter.com URL. Paste the address of your project or pre-launch page.',
            );
        }

        return parent::analyse($project, $url);
    }

    /** @return list<PageCheck> */
    protected function checks(PageContent $content, FetchedPage $page): array
    {
        $isLive = $this->looksLive($content);

        return [
            PageCheck::when($page->ok(), 'reachable', 'Page loads', 15,
                'Kickstarter did not return the page. Check the URL, and that the project is public.',
                "HTTP {$page->status}"),

            $this->video($content),
            $this->title($content),
            $this->images($content),

            PageCheck::when($content->wordCount > 40, 'blurb', 'Explains what it is', 10,
                'Add a short description saying what the game is and who it is for. This is what people read first.',
                "{$content->wordCount} words on the page"),

            ...($isLive ? $this->liveChecks($content) : $this->preLaunchChecks($content)),
        ];
    }

    /**
     * Reward tiers and a pledge total only exist once a campaign is live,
     * so their absence is the signal that this is still a pre-launch page
     * rather than a fault to report.
     */
    private function looksLive(PageContent $content): bool
    {
        return $content->mentions(['pledged of', 'backers', 'days to go', 'select this reward', 'funding period']);
    }

    /** @return list<PageCheck> */
    private function preLaunchChecks(PageContent $content): array
    {
        return [
            PageCheck::when(
                $content->mentions(['notify me on launch', 'notify me', 'get notified', 'follow']),
                'notify_cta', 'Invites people to follow', 15,
                'The pre-launch page exists to collect followers. Drive every ad and post to it until you launch.'),

            PageCheck::unknown('story_depth', 'Story sells the game',
                'Pre-launch pages carry no story section. It is worth drafting now — the day you launch is the worst time to start writing.'),

            PageCheck::unknown('reward_tiers', 'Reward tiers are focused',
                'Tiers are not published until launch. Aim for three to five: one core pledge, one deluxe, and little else.'),
        ];
    }

    /** @return list<PageCheck> */
    private function liveChecks(PageContent $content): array
    {
        return [
            $this->tiers($content),
            $this->story($content),

            PageCheck::when($content->mentions(['risks and challenges', 'risks & challenges']),
                'risks', 'Answers risks honestly', 5,
                'Fill in Risks and Challenges properly. Backers read it, and a thin one reads as inexperience.'),

            PageCheck::when($content->mentions(['faq', 'frequently asked']), 'faq', 'Has an FAQ', 5,
                'Add an FAQ. Every question you leave unanswered is asked in the comments instead, or not at all.'),

            PageCheck::when($content->mentions(['shipping', 'delivery', 'postage']), 'shipping', 'Shipping is addressed', 10,
                'State shipping costs and destinations. Unclear postage is one of the most common reasons a pledge is abandoned.'),

            PageCheck::when($content->mentions(['about the creator', 'created by', 'first created', 'previous project']),
                'creator', 'Introduces the creator', 5,
                'Say who you are and what you have made before. Backers are betting on delivery, not just on the game.'),
        ];
    }

    private function video(PageContent $content): PageCheck
    {
        // Kickstarter's own guidance, and the one signal that most reliably
        // separates funded projects from unfunded ones.
        return PageCheck::when($content->hasVideo, 'video', 'Has a video', 20,
            'Add a video. It is the single strongest predictor of funding on Kickstarter, and a phone-shot playthrough beats no video at all.');
    }

    private function title(PageContent $content): PageCheck
    {
        $title = $content->headline() ?? $content->title;

        if ($title === '') {
            return PageCheck::unknown('title', 'Title is clear',
                'Could not read the project title from the page.');
        }

        // Kickstarter appends its own suffix to the document title.
        $title = trim(preg_replace('/\s*[—|]\s*Kickstarter\s*$/iu', '', $title) ?? $title);

        return PageCheck::when(mb_strlen($title) <= self::MAX_TITLE, 'title', 'Title is clear', 10,
            'Shorten the title. Kickstarter truncates it in search and category listings, which is where most people meet it.',
            mb_strlen($title).' characters: "'.$title.'"');
    }

    private function images(PageContent $content): PageCheck
    {
        return PageCheck::when($content->imageCount >= 3, 'images', 'Shows the game', 10,
            'Add more photographs of the actual components. Renders and mock-ups do not convince tabletop backers.',
            $content->imageCount.' '.($content->imageCount === 1 ? 'image' : 'images'));
    }

    private function tiers(PageContent $content): PageCheck
    {
        $count = $this->countTiers($content);

        if ($count === 0) {
            return PageCheck::unknown('reward_tiers', 'Reward tiers are focused',
                'Could not read the reward tiers — Kickstarter loads them separately. Aim for three to five.');
        }

        if ($count > self::MAX_TIERS) {
            return PageCheck::fail('reward_tiers', 'Reward tiers are focused', 10,
                'Cut the tiers back to three to five. Every extra option is another decision between a backer and their pledge.',
                "{$count} tiers");
        }

        return PageCheck::when($count >= self::MIN_TIERS, 'reward_tiers', 'Reward tiers are focused', 10,
            'Add at least a core pledge and a deluxe version. A single option leaves money on the table.',
            "{$count} tiers");
    }

    private function story(PageContent $content): PageCheck
    {
        return PageCheck::when($content->wordCount >= self::MIN_STORY_WORDS, 'story_depth', 'Story sells the game', 10,
            'Expand the story: how it plays, what is in the box, why it exists. Thin campaign pages read as unfinished.',
            "{$content->wordCount} words");
    }

    /**
     * Tier counting is a best effort against markup we do not control, so
     * two independent signals are tried and the larger wins; zero means we
     * could not tell rather than that there are none.
     */
    private function countTiers(PageContent $content): int
    {
        preg_match_all('/select this reward/iu', $content->text, $byButton);
        preg_match_all('/data-pledge-amount|pledge_amount"\s*:/iu', $content->html, $byMarkup);

        return max(count($byButton[0]), count($byMarkup[0]));
    }
}
