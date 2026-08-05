<?php

namespace App\AI\Providers;

use App\AI\Contracts\AiProvider;

/**
 * Used when no AI key is configured. Rule-based insights still work;
 * only LLM-polished copy is skipped.
 */
class NullAiProvider implements AiProvider
{
    public function isConfigured(): bool
    {
        return false;
    }

    public function complete(string $system, string $prompt, int $maxTokens = 1024): ?string
    {
        return null;
    }
}
