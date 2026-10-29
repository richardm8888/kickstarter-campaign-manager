<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Something the AI wrote for a task. Every attempt is kept, so a better
 * first draft is not lost to a worse second one.
 */
class LaunchTaskDraft extends Model
{
    protected $fillable = ['content', 'notes'];

    public function task(): BelongsTo
    {
        return $this->belongsTo(LaunchTask::class, 'launch_task_id');
    }
}
