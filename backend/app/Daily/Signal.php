<?php

namespace App\Daily;

use App\Models\DailyTask;

/**
 * A candidate action, before anything has decided whether it makes the
 * list. Detectors produce these; the brief scores them and keeps the best
 * three.
 *
 * Every field exists to answer one of the four scoring questions — how
 * much would this help, how sure are we, how long will it take, does it
 * have to be today — so a detector cannot raise something without saying
 * what it is worth.
 */
final readonly class Signal
{
    public function __construct(
        /** Stable across days: this is how a task is recognised tomorrow. */
        public string $key,
        public string $title,
        /** The evidence, in one to three sentences. */
        public string $why,
        /** The specific thing to do. Not a topic — an instruction. */
        public string $action,
        public int $effortMinutes,
        /** How much this could move a Kickstarter outcome. */
        public string $impact = DailyTask::MEDIUM,
        /** How strongly the data supports it, 0–1. */
        public float $confidence = 0.7,
        /** Whether it decays if left, 0–1. */
        public float $urgency = 0.5,
        /** @var array<string, mixed> the numbers behind the claim */
        public array $evidence = [],
    ) {}

    /**
     * Impact × confidence, discounted by effort and lifted by urgency.
     *
     * The shape matters more than the constants: a ten-minute job with
     * good evidence should beat a two-hour one with a hunch behind it,
     * which is the whole point of the list.
     */
    public function score(): float
    {
        $impact = match ($this->impact) {
            DailyTask::HIGH => 3.0,
            DailyTask::LOW => 1.0,
            default => 2.0,
        };

        // Half an hour is the reference job. Longer tasks are not
        // excluded, they simply have to be worth more.
        $effort = 1 / (1 + $this->effortMinutes / 30);

        return round($impact * $this->confidence * (0.5 + 0.5 * $this->urgency) * $effort, 3);
    }

    /**
     * Priority is for the reader, and describes consequence rather than
     * rank — the list is already in order, so repeating the order here
     * would tell them nothing.
     */
    public function priority(): string
    {
        return match (true) {
            $this->impact === DailyTask::HIGH && $this->urgency >= 0.6 => DailyTask::HIGH,
            $this->impact === DailyTask::LOW || $this->urgency < 0.3 => DailyTask::LOW,
            default => DailyTask::MEDIUM,
        };
    }

    /** @return array<string, mixed> */
    public function toAttributes(): array
    {
        return [
            'signal_key' => $this->key,
            'priority' => $this->priority(),
            'title' => $this->title,
            'why' => $this->why,
            'action' => $this->action,
            'effort_minutes' => $this->effortMinutes,
            'impact' => $this->impact,
            'evidence' => $this->evidence,
            'score' => $this->score(),
        ];
    }
}
