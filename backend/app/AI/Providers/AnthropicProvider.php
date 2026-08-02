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

    public function complete(string $system, string $prompt): ?string
    {
        if (! $this->isConfigured()) {
            return null;
        }

        try {
            $response = Http::withHeaders([
                'x-api-key' => $this->apiKey,
                'anthropic-version' => '2023-06-01',
            ])->timeout(30)->post('https://api.anthropic.com/v1/messages', [
                'model' => $this->model,
                'max_tokens' => 1024,
                'system' => $system,
                'messages' => [
                    ['role' => 'user', 'content' => $prompt],
                ],
            ])->throw()->json();

            return $response['content'][0]['text'] ?? null;
        } catch (Throwable $e) {
            Log::warning('AI completion failed', ['error' => $e->getMessage()]);

            return null;
        }
    }
}
