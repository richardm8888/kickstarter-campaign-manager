<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only observation of a single metric at a point in time.
 * Never update or delete rows; insert new observations instead.
 */
class MetricSnapshot extends Model
{
    /** @use HasFactory<\Database\Factories\MetricSnapshotFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = [
        'source',
        'metric',
        'value',
        'dimensions',
        'recorded_at',
    ];

    protected function casts(): array
    {
        return [
            'value' => 'float',
            'dimensions' => 'array',
            'recorded_at' => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
