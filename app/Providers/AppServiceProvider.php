<?php

namespace App\Providers;

use App\Services\AssemblyAiClient;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(AssemblyAiClient::class, fn () => new AssemblyAiClient(
            (string) config('services.assemblyai.api_key'),
            rtrim((string) config('services.assemblyai.base_url'), '/'),
            (string) config('services.assemblyai.speech_model'),
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
