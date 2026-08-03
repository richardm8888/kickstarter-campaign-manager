<?php

namespace App\Providers;

use App\AI\Contracts\AiProvider;
use App\AI\Providers\AnthropicProvider;
use App\AI\Providers\NullAiProvider;
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
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
