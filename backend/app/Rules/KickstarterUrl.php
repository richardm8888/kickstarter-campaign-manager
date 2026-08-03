<?php

namespace App\Rules;

use App\Services\Kickstarter\KickstarterFollowers;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * The server fetches this URL on a schedule, so it is restricted to
 * kickstarter.com rather than merely being a valid URL.
 */
class KickstarterUrl implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! KickstarterFollowers::isValidUrl($value)) {
            $fail('Enter the address of your Kickstarter pre-launch page, e.g. https://www.kickstarter.com/projects/you/your-project.');
        }
    }
}
