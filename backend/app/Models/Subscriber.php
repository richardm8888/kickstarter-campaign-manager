<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Subscriber extends Model
{
    /** @use HasFactory<\Database\Factories\SubscriberFactory> */
    use HasFactory;

    protected $fillable = [
        'email', 'is_vip', 'source', 'external_id', 'fields',
        'synced_to_email_at', 'unsubscribed_at',
    ];

    protected function casts(): array
    {
        return [
            'is_vip' => 'boolean',
            'fields' => 'array',
            'synced_to_email_at' => 'datetime',
            'unsubscribed_at' => 'datetime',
        ];
    }

    /**
     * Everyone still on the list.
     *
     * Every count that feeds a forecast, a conversion rate or a health
     * score should go through here. The unfiltered relation stays for
     * imports and lookups, where a departed contact still has to be found
     * so they are not created again as somebody new.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('unsubscribed_at');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
