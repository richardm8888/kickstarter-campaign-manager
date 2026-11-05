<?php

namespace App\Launch;

use App\Models\LaunchTask;
use App\Models\Project;
use Illuminate\Support\Carbon;

/**
 * A prompt that hands one launch task to a coding agent such as Claude
 * Code, so the work behind it can be done rather than just listed.
 *
 * It is a template, not an AI call: the same task always gives the same
 * prompt, it costs nothing, and nothing in it is invented. Everything an
 * agent starting cold would otherwise have to ask for is written in —
 * what the project is, where the code lives, what the task is and why,
 * and how the work should come back.
 */
class HandoffPrompt
{
    public static function for(LaunchTask $task): string
    {
        $project = $task->project;

        $sections = [
            self::intro($task),
            self::about($project),
            self::theTask($task),
            self::draft($task),
            self::howToDeliver($task, $project),
        ];

        return implode("\n\n", array_filter($sections))."\n";
    }

    private static function intro(LaunchTask $task): string
    {
        $what = match ($task->category) {
            'website' => 'a change to our website',
            'outreach', 'previews' => 'some outreach work',
            'email' => 'an email to our mailing list',
            'social' => 'social media content',
            'bgg' => 'something for BoardGameGeek',
            'kickstarter' => 'something for our Kickstarter campaign',
            default => 'a task from our launch plan',
        };

        return "Please help with {$what}. This comes from our Kickstarter launch plan.";
    }

    private static function about(Project $project): string
    {
        $facts = array_filter([
            'Project' => $project->name,
            'What it is' => $project->description,
            'Website' => $project->external_landing_url,
            'Kickstarter page' => $project->kickstarter_url,
            'Website repository' => $project->website_repo,
            'Assets repository (artwork, cards, logos)' => $project->assets_repo,
            'Launch date' => self::launch($project),
            'Team' => $project->team ? implode(', ', $project->team) : null,
        ]);

        $lines = collect($facts)->map(fn ($value, $label) => "- {$label}: {$value}")->implode("\n");

        $brief = filled($project->content_brief) ? "\n\nOur brief (voice, facts, things to avoid):\n{$project->content_brief}" : '';

        return "## About us\n{$lines}{$brief}";
    }

    /** A provisional date is for planning, never for anything public. */
    private static function launch(Project $project): ?string
    {
        if ($project->launch_date === null) {
            return 'not set yet — say "coming soon to Kickstarter"';
        }

        $date = Carbon::parse($project->launch_date)->format('j F Y');

        return $project->launch_date_confirmed
            ? "{$date} (confirmed)"
            : "{$date} is our working date, but it is NOT confirmed — do not publish it anywhere; say \"coming soon to Kickstarter\"";
    }

    private static function theTask(LaunchTask $task): string
    {
        $lines = ["## The task\n**{$task->title}**"];

        if (filled($task->details)) {
            $lines[] = $task->details;
        }

        $meta = array_filter([
            'Due' => $task->due_date ? Carbon::parse($task->due_date)->format('l j F Y') : null,
            'Owner' => $task->assignee,
            'Category' => Playbook::CATEGORIES[$task->category] ?? $task->category,
        ]);

        $lines[] = collect($meta)->map(fn ($value, $label) => "- {$label}: {$value}")->implode("\n");

        return implode("\n\n", $lines);
    }

    /** A draft already written in the app is the starting point, not a fresh blank page. */
    private static function draft(LaunchTask $task): ?string
    {
        $draft = $task->latestDraft;

        if ($draft === null) {
            return null;
        }

        return "## Draft we already have\nWe drafted this in our launch app. Use it as the starting point, check every fact, and fill anything in [square brackets] or flag it to us:\n\n```\n{$draft->content}\n```";
    }

    private static function howToDeliver(LaunchTask $task, Project $project): string
    {
        $steps = [];

        if ($task->category === 'website' && filled($project->website_repo)) {
            $steps[] = "- Work in the {$project->website_repo} repository. Follow its existing conventions (CLAUDE.md, if it has one).";
            $steps[] = '- Make the change on a new branch and open a pull request. Merging deploys the site, so do not merge it yourself.';
            $steps[] = '- Build the site and check the changed pages on desktop and mobile before opening the pull request; include screenshots.';

            if (filled($project->assets_repo)) {
                $steps[] = "- Take any artwork from {$project->assets_repo} rather than recreating it, and keep images small enough for the web.";
            }
        } elseif ($task->content_type !== null) {
            $steps[] = '- Give us the finished text, ready to paste, and a one-line note on anything we should check before it goes out.';
            $steps[] = '- Do not send, post or publish anything yourself.';
        } else {
            $steps[] = '- Tell us what you would do and why before doing anything that cannot be undone or that other people will see.';
        }

        $steps[] = '- Never invent quotes, reviews, prices, dates or numbers. Ask us, or leave a [placeholder].';
        $steps[] = '- When you are done, tell us in a sentence or two what you did, so we can tick this task off in the launch plan.';

        return "## How to deliver it\n".implode("\n", $steps);
    }
}
