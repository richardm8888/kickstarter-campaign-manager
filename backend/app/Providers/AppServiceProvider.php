<?php

namespace App\Providers;

use App\AI\Contracts\AiProvider;
use App\AI\Providers\AnthropicProvider;
use App\AI\Providers\NullAiProvider;
use App\Services\Analytics\SnapshotCache;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(AiProvider::class, function () {
            $key = config('services.anthropic.key');

            return filled($key)
                ? new AnthropicProvider($key, config('services.anthropic.model'))
                : new NullAiProvider;
        });

        // Scoped, not singleton: one cache per request and per queued
        // job. A queue worker is a process that lives for days, and a
        // singleton would have it answering Friday with Monday's data.
        $this->app->scoped(SnapshotCache::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
