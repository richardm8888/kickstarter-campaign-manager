<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
    ];

    protected function casts(): array
    {
        return [
            'funding_goal' => 'integer',
            'average_pledge' => 'integer',
            'guaranteed_backers' => 'integer',
            'launch_date' => 'date',
            'forecast_assumptions' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
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
