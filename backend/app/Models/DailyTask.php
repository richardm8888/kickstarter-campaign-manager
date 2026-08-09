<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One thing worth doing today.
 *
 * Tasks are not notifications. They persist until they are done or
 * dismissed, which is what makes the list trustworthy: an urgent problem
 * ignored on Tuesday is still there on Wednesday, and a job finished on
 * Tuesday does not come back.
 */
class DailyTask extends Model
{
    /** @use HasFactory<\Database\Factories\DailyTaskFactory> */
    use HasFactory;

    public const OPEN = 'open';

    public const DONE = 'done';

    public const DISMISSED = 'dismissed';

    public const HIGH = 'high';

    public const MEDIUM = 'medium';

    public const LOW = 'low';

    /**
     * How long a finished task stays suppressed.
     *
     * Doing the work rarely moves the numbers the same day — a new
     * creative needs a few days of spend before its CPC means anything —
     * so a detector that fires again immediately is describing the old
     * evidence, not a new problem.
     */
    public const DONE_COOLDOWN_DAYS = 7;

    /** Dismissing is a judgement that it does not matter; respect it longer. */
    public const DISMISSED_COOLDOWN_DAYS = 21;

    protected $fillable = [
        'for_date', 'signal_key', 'priority', 'title', 'why', 'action',
        'effort_minutes', 'impact', 'evidence', 'score', 'status', 'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'for_date' => 'date',
            'evidence' => 'array',
            'score' => 'float',
            'effort_minutes' => 'integer',
            'completed_at' => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', self::OPEN);
    }

    /**
     * Tasks whose signal should not be raised again yet, because the
     * creator has already dealt with it.
     */
    public function scopeSuppressing(Builder $query): Builder
    {
        return $query->where(function (Builder $q) {
            $q->where(fn (Builder $done) => $done
                ->where('status', self::DONE)
                ->where('completed_at', '>=', Carbon::now()->subDays(self::DONE_COOLDOWN_DAYS)))
                ->orWhere(fn (Builder $skipped) => $skipped
                    ->where('status', self::DISMISSED)
                    ->where('updated_at', '>=', Carbon::now()->subDays(self::DISMISSED_COOLDOWN_DAYS)));
        });
    }
}
