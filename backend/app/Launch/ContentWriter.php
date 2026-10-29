<?php

namespace App\Launch;

use App\AI\Contracts\AiProvider;
use App\Models\LaunchTask;
use App\Models\Project;
use Illuminate\Support\Carbon;

/**
 * Drafts the thing a launch task asks for: a diary, an email, a post.
 *
 * The draft is a starting point the creators edit, never something posted
 * unread. So the prompt's main job is honesty: use only the facts it is
 * given, and leave a visible [placeholder] wherever it would otherwise
 * have to guess. An invented review quote or a wrong launch date is far
 * more expensive than a gap the creator has to fill.
 */
class ContentWriter
{
    /** Enough for the longest type (a diary) without being cut off mid-sentence. */
    private const MAX_TOKENS = 2500;

    public const TYPES = [
        'bgg_diary' => [
            'label' => 'BGG designer diary',
            'guide' => "A BoardGameGeek designer diary, 500–800 words, written by the designers in the first person plural. Open with a hook, not a greeting. Tell it as a story: what you were trying to do, what went wrong, what you changed and why. Use short paragraphs and a few subheadings. BGG formatting: [b]bold[/b], [i]italic[/i], [url=https://…]text[/url]. No hard sell; end with one line pointing to where to follow the game.",
        ],
        'bgg_forum_post' => [
            'label' => 'BGG forum post',
            'guide' => "A BoardGameGeek forum post, 100–250 words. Friendly and direct, written by the designers. BGG formatting: [b]bold[/b], [url=https://…]text[/url]. Start with a subject line on its own line, prefixed 'Subject: '.",
        ],
        'outreach_email' => [
            'label' => 'Outreach email',
            'guide' => "A short, personal email to a reviewer, blogger or list author, under 170 words. Start with 'Subject: ' on its own line. Leave [name] and [something specific about their work] as placeholders for the creator to fill. One clear ask. No attachments mentioned, no pressure, no exaggerated claims.",
        ],
        'email' => [
            'label' => 'Mailing list email',
            'guide' => "An email to the mailing list, 120–250 words. Start with 'Subject: ' and 'Preview: ' lines. Warm and personal, written by the creators. One main call to action, as a clear link line.",
        ],
        'social_post' => [
            'label' => 'Social posts',
            'guide' => "Three short social posts: one for Instagram (with up to 5 relevant hashtags at the end), one for Facebook, and one for X (under 270 characters). Label each. Each ends with the call to action.",
        ],
        'blog_post' => [
            'label' => 'Blog post',
            'guide' => "A helpful, search-friendly blog post in Markdown, 700–1,000 words, that genuinely answers the question in the task before mentioning the game. Start with a one-paragraph direct answer, use ## subheadings, and include a short FAQ at the end. Suggest a title and a meta description (under 155 characters) at the top.",
        ],
        'kickstarter_update' => [
            'label' => 'Kickstarter update',
            'guide' => "A Kickstarter backer update, 200–400 words, with a title line. Grateful, specific and upbeat. Share real progress, then what is next.",
        ],
    ];

    public function __construct(private readonly AiProvider $ai) {}

    public function available(): bool
    {
        return $this->ai->isConfigured();
    }

    public function write(LaunchTask $task, ?string $notes = null): ?string
    {
        $type = self::TYPES[$task->content_type] ?? null;

        if ($type === null) {
            return null;
        }

        $content = $this->ai->complete($this->system($task->project), $this->prompt($task, $type, $notes), self::MAX_TOKENS);

        return filled($content) ? trim($content) : null;
    }

    private function system(Project $project): string
    {
        $facts = array_filter([
            'Project' => $project->name,
            'Description' => $project->description,
            'Kickstarter page' => $project->kickstarter_url,
            'Website' => $project->external_landing_url,
            'Launch date' => $this->launchFact($project),
            'Team' => $project->team ? implode(', ', $project->team) : null,
        ]);

        $lines = collect($facts)->map(fn ($value, $label) => "- {$label}: {$value}")->implode("\n");

        $brief = filled($project->content_brief)
            ? "\n\nThe creators' brief (their voice, facts and things to avoid):\n".$project->content_brief
            : '';

        return <<<PROMPT
        You write launch content for a crowdfunding campaign, in the creators' own voice. Your drafts are edited by the creators before anything is published.

        Rules:
        - Use only the facts given here. Never invent quotes, reviews, statistics, prices, dates, backer numbers or people.
        - Where something specific is needed and not given, leave a short placeholder in square brackets, like [early-bird price], so the creators can see what to fill in.
        - British English. Plain, warm and specific. No clichés like "game-changer" or "revolutionary".
        - Return only the content itself, with no preamble or commentary.

        Facts:
        {$lines}{$brief}
        PROMPT;
    }

    /**
     * A provisional date still schedules the plan, but must not reach the
     * public: a draft that announces it is one copy-paste from a promise.
     */
    private function launchFact(Project $project): string
    {
        if ($project->launch_date === null) {
            return 'not set — say "coming soon to Kickstarter" and do not give a date';
        }

        $date = Carbon::parse($project->launch_date)->format('l j F Y');

        return $project->launch_date_confirmed
            ? $date.' (confirmed)'
            : 'provisional and not public — do not mention any launch date; say "coming soon to Kickstarter"';
    }

    /** @param array{label: string, guide: string} $type */
    private function prompt(LaunchTask $task, array $type, ?string $notes): string
    {
        $parts = [
            "Write: {$type['label']}.",
            "Format: {$type['guide']}",
            "Task: {$task->title}",
        ];

        if (filled($task->details)) {
            $parts[] = "What the task involves:\n{$task->details}";
        }

        if (filled($notes)) {
            $parts[] = "The creators' notes for this draft (use these; they take priority):\n{$notes}";
        }

        return implode("\n\n", $parts);
    }
}
