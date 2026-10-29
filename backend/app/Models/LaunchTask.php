<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * One step of the launch plan.
 *
 * Unlike a daily task, which a detector raises and retires, these are
 * the creator's own plan: they stay until ticked off or skipped, whatever
 * the numbers do.
 */
class LaunchTask extends Model
{
    /** @use HasFactory<\Database\Factories\LaunchTaskFactory> */
    use HasFactory;

    public const TODO = 'todo';

    public const DONE = 'done';

    public const SKIPPED = 'skipped';

    protected $fillable = [
        'template_key', 'phase', 'category', 'title', 'details', 'assignee',
        'offset_days', 'due_on', 'content_type', 'sort_order', 'status', 'completed_at',
    ];

    protected $appends = ['due_date'];

    /** Set on each task so due dates need no extra queries, but never worth sending back. */
    protected $hidden = ['project'];

    protected function casts(): array
    {
        return [
            'offset_days' => 'integer',
            'due_on' => 'date:Y-m-d',
            'sort_order' => 'integer',
            'completed_at' => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function drafts(): HasMany
    {
        return $this->hasMany(LaunchTaskDraft::class);
    }

    public function latestDraft(): HasOne
    {
        return $this->hasOne(LaunchTaskDraft::class)->latestOfMany();
    }

    /**
     * When it is due: a fixed date if it has one, otherwise counted from
     * launch. Null when it hangs off a launch date that is not set yet —
     * inventing one would put a deadline on the page nobody agreed to.
     */
    protected function dueDate(): Attribute
    {
        return Attribute::get(function (): ?string {
            if ($this->due_on !== null) {
                return $this->due_on->toDateString();
            }

            $launch = $this->project?->launch_date;

            if ($launch === null || $this->offset_days === null) {
                return null;
            }

            return Carbon::parse($launch)->addDays($this->offset_days)->toDateString();
        });
    }
}
