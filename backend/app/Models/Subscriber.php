<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Subscriber extends Model
{
    /** @use HasFactory<\Database\Factories\SubscriberFactory> */
    use HasFactory;

    protected $fillable = ['email', 'is_vip', 'source', 'external_id', 'fields', 'synced_to_email_at'];

    protected function casts(): array
    {
        return [
            'is_vip' => 'boolean',
            'fields' => 'array',
            'synced_to_email_at' => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
