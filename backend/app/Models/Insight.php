<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Insight extends Model
{
    /** @use HasFactory<\Database\Factories\InsightFactory> */
    use HasFactory;

    public const KIND_INSIGHT = 'insight';
    public const KIND_RECOMMENDATION = 'recommendation';

    protected $fillable = [
        'kind',
        'severity',
        'title',
        'body',
        'action',
        'metadata',
        'acknowledged_at',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'acknowledged_at' => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
