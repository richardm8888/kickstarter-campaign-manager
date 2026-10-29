<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A link that lets one person join a project.
 *
 * The token is never sent back after it is created except to the owner,
 * and it works once: accepting it stamps accepted_at, and an accepted or
 * expired invitation is refused rather than quietly re-used.
 */
class ProjectInvitation extends Model
{
    /** Long enough to survive a busy week, short enough that a forgotten link dies. */
    public const VALID_DAYS = 14;

    protected $fillable = ['email', 'token', 'invited_by', 'expires_at', 'accepted_at'];

    protected $hidden = ['token'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->whereNull('accepted_at')->where('expires_at', '>', now());
    }

    public function isUsable(): bool
    {
        return $this->accepted_at === null && $this->expires_at->isFuture();
    }
}
