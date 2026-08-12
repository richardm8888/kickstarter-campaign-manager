<?php

namespace App\Models;

use App\Services\PageAudit\PageCheck;
use Illuminate\Database\Eloquent\Casts\Attribute;
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
            'findings' => 'array',
        ];
    }

    /**
     * Checks stored before three-state results existed only carry `passed`.
     * They are history — we cannot re-derive a verdict we never recorded —
     * so they are read forward into the current shape rather than migrated,
     * and no client has to know which era a row came from. Every key the
     * API promises is filled in, because a half-shaped check crashes a
     * reader that trusts the contract.
     */
    protected function checks(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value) => array_map(
                fn (array $check) => $check + [
                    'result' => ($check['passed'] ?? false) ? PageCheck::PASS : PageCheck::FAIL,
                    'passed' => false,
                    'weight' => 0,
                    'recommendation' => '',
                    'detail' => null,
                ],
                json_decode($value ?? '[]', true) ?: [],
            ),
            set: fn (array $checks) => json_encode($checks),
        );
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
