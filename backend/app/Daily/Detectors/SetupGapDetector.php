<?php

namespace App\Daily\Detectors;

use App\Daily\Detector;
use App\Daily\Signal;
use App\Models\DailyTask;
use App\Models\Project;
use App\Services\Dashboard\SetupChecklist;

/**
 * Missing foundations.
 *
 * These outrank every optimisation, because until they are done most of
 * the other detectors are blind: no Kickstarter page means no follower
 * count, no Meta connection means no spend data, and a list of clever
 * suggestions built on nothing is worse than an empty list.
 *
 * Only ever one at a time, in the order the checklist puts them. A new
 * creator handed six setup tasks does none of them.
 */
class SetupGapDetector implements Detector
{
    public function __construct(private readonly SetupChecklist $checklist) {}

    /**
     * What each gap actually costs, and how long closing it takes. The
     * checklist knows the step; only this knows why it is worth today.
     *
     * @var array<string, array{why: string, action: string, minutes: int, impact: string}>
     */
    private const GAPS = [
        'launch_date' => [
            'why' => 'Without a launch date nothing here can tell you whether your audience is growing fast enough, because there is no deadline to be behind.',
            'action' => 'Set a provisional launch date in Settings. A rough one you move later is far more useful than none.',
            'minutes' => 2,
            'impact' => DailyTask::HIGH,
        ],
        'kickstarter_url' => [
            'why' => 'Your pre-launch page is where followers come from, and followers back at roughly ten times the rate of an email subscriber. Until it is linked here, the most valuable audience you have is invisible.',
            'action' => 'Create the pre-launch page on Kickstarter if you have not, then paste its URL into Settings.',
            'minutes' => 15,
            'impact' => DailyTask::HIGH,
        ],
        'landing_page' => [
            'why' => 'Ads need somewhere to send people. Without a page there is nowhere for interest to turn into an email address.',
            'action' => 'Publish the built-in landing page, or point Settings at your own.',
            'minutes' => 20,
            'impact' => DailyTask::HIGH,
        ],
        'meta' => [
            'why' => 'Meta is not connected, so spend, clicks and cost per signup are all unknown. Every ad recommendation here depends on that data.',
            'action' => 'Connect Meta on the Integrations page with an access token and ad account id.',
            'minutes' => 10,
            'impact' => DailyTask::MEDIUM,
        ],
        'mailerlite' => [
            'why' => 'Your email provider is not connected, so list growth and engagement cannot be read, and new signups will not get a welcome email.',
            'action' => 'Add your MailerLite API key on the Integrations page.',
            'minutes' => 5,
            'impact' => DailyTask::MEDIUM,
        ],
        'first_ad' => [
            'why' => 'No ad has run yet, so every cost figure here is a benchmark rather than yours. A small budget for a few days replaces guesswork with measurement.',
            'action' => 'Start one ad pointing at your Kickstarter pre-launch page, at a budget you would not mind losing.',
            'minutes' => 30,
            'impact' => DailyTask::MEDIUM,
        ],
    ];

    /** @return list<Signal> */
    public function detect(Project $project): array
    {
        foreach ($this->checklist->build($project)['steps'] as $step) {
            if ($step['done'] ?? false) {
                continue;
            }

            $gap = self::GAPS[$step['key']] ?? null;

            if ($gap === null) {
                continue;
            }

            return [new Signal(
                key: 'setup_'.$step['key'],
                title: $step['label'],
                why: $gap['why'],
                action: $gap['action'],
                effortMinutes: $gap['minutes'],
                impact: $gap['impact'],
                // A missing thing is missing. There is nothing to infer.
                confidence: 1.0,
                // Everything downstream is blocked until it is done, and
                // the blocking gets more expensive the longer it lasts.
                urgency: 0.8,
                evidence: ['step' => $step['key']],
            )];
        }

        return [];
    }

    /** @return list<string> */
    public function reassurances(Project $project): array
    {
        return ($this->checklist->build($project)['complete'] ?? false)
            ? ['Setup is complete — every integration and page is connected.']
            : [];
    }
}
