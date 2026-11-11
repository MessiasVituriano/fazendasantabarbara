<?php

namespace App\Providers;

use App\Services\ContextoFazenda;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Fazenda ativa: uma por requisição (ou por job/comando).
        $this->app->scoped(ContextoFazenda::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
