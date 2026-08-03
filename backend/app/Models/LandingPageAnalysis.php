<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LandingPageAnalysis extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['url', 'score', 'checks', 'summary'];

    protected function casts(): array
    {
        return [
            'score' => 'integer',
            'checks' => 'array',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
