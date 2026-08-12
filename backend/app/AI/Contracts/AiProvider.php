<?php

namespace App\AI\Contracts;

/**
 * Thin boundary between business logic and any LLM vendor.
 * Generators depend on this contract only — never on a vendor SDK.
 */
interface AiProvider
{
    public function isConfigured(): bool;

    /**
     * Returns the model's text completion, or null when unavailable.
     *
     * Callers expecting structured output must size $maxTokens for the
     * whole reply: a truncated completion is not a shorter answer, it is
     * an unparseable one.
     */
    public function complete(string $system, string $prompt, int $maxTokens = 1024): ?string;
}
