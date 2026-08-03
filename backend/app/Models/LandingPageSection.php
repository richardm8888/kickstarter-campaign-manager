<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LandingPageSection extends Model
{
    public const TYPES = [
        'hero',
        'cta',
        'feature_grid',
        'video',
        'testimonials',
        'faq',
        'email_capture',
        'vip_upgrade',
        'footer',
    ];

    protected $fillable = ['type', 'position', 'enabled', 'content'];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'enabled' => 'boolean',
            'content' => 'array',
        ];
    }

    public function landingPage(): BelongsTo
    {
        return $this->belongsTo(LandingPage::class);
    }
}
