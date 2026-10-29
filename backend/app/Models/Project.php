<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Project extends Model
{
    /** @use HasFactory<\Database\Factories\ProjectFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'description',
        'external_landing_url',
        'kickstarter_url',
        'currency',
        'funding_goal',
        'average_pledge',
        'guaranteed_backers',
        'launch_date',
        'forecast_assumptions',
        'team',
        'content_brief',
        'launch_date_confirmed',
    ];

    protected function casts(): array
    {
        return [
            'funding_goal' => 'integer',
            'average_pledge' => 'integer',
            'guaranteed_backers' => 'integer',
            'launch_date' => 'date',
            'forecast_assumptions' => 'array',
            'team' => 'array',
            'launch_date_confirmed' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** People other than the owner who can work on the project. */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'project_members')->withTimestamps();
    }

    public function invitations(): HasMany
    {
        return $this->hasMany(ProjectInvitation::class);
    }

    public function isOwnedBy(User $user): bool
    {
        return $this->user_id === $user->id;
    }

    /** Owner or member: anyone allowed to see and work on the project. */
    public function hasMember(User $user): bool
    {
        return $this->isOwnedBy($user) || $this->members()->whereKey($user->id)->exists();
    }

    public function integrations(): HasMany
    {
        return $this->hasMany(Integration::class);
    }

    public function metricSnapshots(): HasMany
    {
        return $this->hasMany(MetricSnapshot::class);
    }

    public function landingPage(): HasOne
    {
        return $this->hasOne(LandingPage::class);
    }

    public function subscribers(): HasMany
    {
        return $this->hasMany(Subscriber::class);
    }

    public function insights(): HasMany
    {
        return $this->hasMany(Insight::class);
    }

    public function dailyTasks(): HasMany
    {
        return $this->hasMany(DailyTask::class);
    }

    public function launchTasks(): HasMany
    {
        return $this->hasMany(LaunchTask::class);
    }

    public function landingPageAnalyses(): HasMany
    {
        return $this->hasMany(LandingPageAnalysis::class);
    }

    /** True when the creator uses their own page rather than the built-in one. */
    public function usesExternalLandingPage(): bool
    {
        return filled($this->external_landing_url);
    }

    /** Days until launch; negative once launched, null when no date is set. */
    public function daysToLaunch(): ?int
    {
        return $this->launch_date
            ? (int) now()->startOfDay()->diffInDays($this->launch_date->startOfDay(), false)
            : null;
    }
}
