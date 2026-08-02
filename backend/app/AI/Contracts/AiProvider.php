<?php

namespace App\AI\Contracts;

/**
 * Thin boundary between business logic and any LLM vendor.
 * Generators depend on this contract only — never on a vendor SDK.
 */
interface AiProvider
{
    public function isConfigured(): bool;

    /** Returns the model's text completion, or null when unavailable. */
    public function complete(string $system, string $prompt): ?string;
}
