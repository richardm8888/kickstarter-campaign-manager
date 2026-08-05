<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LandingPageAnalysis extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['url', 'page_type', 'score', 'checks', 'findings', 'summary'];

    protected function casts(): array
    {
        return [
            'score' => 'integer',
            'checks' => 'array',
            'findings' => 'array',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
