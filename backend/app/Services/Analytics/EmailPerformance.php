<?php

namespace App\Services\Analytics;

use App\Models\Project;

/**
 * Broadcasts and automations side by side, counted the same way.
 *
 * These were not comparable before. `email_opens` and friends came from
 * the campaigns endpoint, which holds broadcasts only, while the cohort
 * open rates were built from per-subscriber counters that include every
 * email ever sent. Two numbers on one screen, one counting a third of
 * the programme and the other counting all of it.
 *
 * On the account this was built against, three broadcasts had sent 167
 * emails and a single welcome sequence had sent 227. The tab was
 * describing the smaller half.
 *
 * Automations report running totals rather than a day's activity — a
 * drip has no send date — so they are read latest-wins and presented as
 * "since it was switched on", never mixed into a windowed chart.
 */
class EmailPerformance
{
    /** Rates below this are noise: three opens of four sends is not 75%. */
    private const MINIMUM_SENT = 20;

    public function __construct(private readonly SegmentTotals $segments) {}

    /** @return array<string, mixed> */
    public function build(Project $project, int $days = 30): array
    {
        $automations = $this->automations($project);

        return [
            'automations' => $automations,
            'totals' => $this->totals($automations, $this->broadcasts($project, $days)),
        ];
    }

    /**
     * One row per sequence, with its emails underneath.
     *
     * @return list<array<string, mixed>>
     */
    private function automations(Project $project): array
    {
        // A generous window: these are levels written on every sync, so
        // this only has to reach back far enough to find the last one.
        $rows = $this->segments->latest($project, [
            'email_automation_sent',
            'email_automation_opens',
            'email_automation_clicks',
            'email_automation_unsubscribes',
        ], 'automation_id', 7, 'mailerlite');

        $steps = $this->steps($project);

        $automations = array_map(function (array $row) use ($steps) {
            $sent = $row['totals']['email_automation_sent'];
            $id = (string) $row['dimensions']['automation_id'];

            return [
                'id' => $id,
                'name' => (string) ($row['dimensions']['automation_name'] ?? 'Untitled automation'),
                'enabled' => (bool) ($row['dimensions']['enabled'] ?? false),
                'sent' => (int) $sent,
                'opens' => (int) $row['totals']['email_automation_opens'],
                'clicks' => (int) $row['totals']['email_automation_clicks'],
                'unsubscribes' => (int) $row['totals']['email_automation_unsubscribes'],
                'open_rate' => $this->rate($row['totals']['email_automation_opens'], $sent),
                'click_rate' => $this->rate($row['totals']['email_automation_clicks'], $sent),
                'steps' => $steps[$id] ?? [],
            ];
        }, $rows);

        usort($automations, fn (array $a, array $b) => $b['sent'] <=> $a['sent']);

        return $automations;
    }

    /**
     * Each email in each sequence, keyed by the automation it belongs to.
     *
     * The point of the split is that a sequence's average hides its
     * shape: a first email everybody opens and a fifth nobody does
     * average out to something unremarkable.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    private function steps(Project $project): array
    {
        $rows = $this->segments->latest($project, [
            'email_automation_step_sent',
            'email_automation_step_opens',
            'email_automation_step_clicks',
        ], 'step_id', 7, 'mailerlite');

        $byAutomation = [];

        foreach ($rows as $row) {
            $sent = $row['totals']['email_automation_step_sent'];

            $byAutomation[(string) ($row['dimensions']['automation_id'] ?? '')][] = [
                'name' => (string) ($row['dimensions']['step_name'] ?? 'Untitled email'),
                'subject' => $row['dimensions']['subject'] ?? null,
                'position' => (int) ($row['dimensions']['position'] ?? 0),
                'sent' => (int) $sent,
                'opens' => (int) $row['totals']['email_automation_step_opens'],
                'clicks' => (int) $row['totals']['email_automation_step_clicks'],
                'open_rate' => $this->rate($row['totals']['email_automation_step_opens'], $sent),
                'click_rate' => $this->rate($row['totals']['email_automation_step_clicks'], $sent),
            ];
        }

        foreach ($byAutomation as &$steps) {
            usort($steps, fn (array $a, array $b) => $a['position'] <=> $b['position']);
        }

        return $byAutomation;
    }

    /**
     * Broadcast totals over the window, for the comparison.
     *
     * @return array<string, float>
     */
    private function broadcasts(Project $project, int $days): array
    {
        $sent = 0.0;

        foreach ($this->segments->get(
            $project,
            ['email_campaign_sent'],
            'campaign_id',
            $days,
            'mailerlite',
        ) as $campaign) {
            $sent += $campaign['totals']['email_campaign_sent'];
        }

        return ['sent' => $sent];
    }

    /**
     * The headline: how much of the sending is automated.
     *
     * @param  list<array<string, mixed>>  $automations
     * @param  array<string, float>  $broadcasts
     * @return array<string, mixed>|null
     */
    private function totals(array $automations, array $broadcasts): ?array
    {
        $automated = array_sum(array_column($automations, 'sent'));
        $broadcast = (int) $broadcasts['sent'];

        if ($automated === 0 && $broadcast === 0) {
            return null;
        }

        return [
            'automated_sent' => $automated,
            'broadcast_sent' => $broadcast,
            'automated_share' => $automated + $broadcast > 0
                ? round($automated / ($automated + $broadcast) * 100, 1)
                : null,
        ];
    }

    /** Null below the threshold: a rate off four sends is not a rate. */
    private function rate(float $part, float $of): ?float
    {
        return $of >= self::MINIMUM_SENT ? round($part / $of * 100, 1) : null;
    }
}
