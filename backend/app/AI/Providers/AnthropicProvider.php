<?php

namespace App\AI\Providers;

use App\AI\Contracts\AiProvider;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class AnthropicProvider implements AiProvider
{
    public function __construct(
        private readonly ?string $apiKey,
        private readonly string $model = 'claude-sonnet-5',
    ) {}

    public function isConfigured(): bool
    {
        return filled($this->apiKey);
    }

    public function complete(string $system, string $prompt, int $maxTokens = 1024): ?string
    {
        if (! $this->isConfigured()) {
            return null;
        }

        try {
            $response = Http::withHeaders([
                'x-api-key' => $this->apiKey,
                'anthropic-version' => '2023-06-01',
            ])->timeout(60)->post('https://api.anthropic.com/v1/messages', [
                'model' => $this->model,
                'max_tokens' => $maxTokens,
                'system' => $system,
                'messages' => [
                    ['role' => 'user', 'content' => $prompt],
                ],
            ])->throw()->json();

            // Running out of tokens mid-reply is silent otherwise: the call
            // succeeds and returns half a sentence, so the caller blames its
            // own parsing. Say it once, here.
            if (($response['stop_reason'] ?? null) === 'max_tokens') {
                Log::warning('AI completion hit the token ceiling and was cut off', [
                    'model' => $this->model,
                    'max_tokens' => $maxTokens,
                ]);
            }

            return $response['content'][0]['text'] ?? null;
        } catch (Throwable $e) {
            Log::warning('AI completion failed', [
                'model' => $this->model,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
