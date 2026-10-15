<?php

namespace App\Providers;

use App\Models\Attachment;
use App\Policies\AttachmentPolicy;
use App\Services\Ai\AiProvider;
use App\Services\Ai\AnthropicClient;
use App\Services\Ai\OpenRouterClient;
use App\Support\Session\LazyDatabaseSessionHandler;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        /*
         * The AI seam. Every feature type-hints OpenRouterClient; when the Claude API is
         * the configured provider (config/ai.php 'provider', or simply an
         * ANTHROPIC_API_KEY being present) they receive AnthropicClient instead — the
         * same chat()/json()/model() surface on the official SDK. Not a singleton, on
         * purpose: each resolution reads the current config.
         */
        $this->app->bind(OpenRouterClient::class, function () {
            return AiProvider::resolve() === AiProvider::ANTHROPIC
                ? new AnthropicClient
                : new OpenRouterClient;
        });
    }

    public function boot(): void
    {
        // The UI is Bootstrap 5, so paginate with Bootstrap's markup.
        Paginator::useBootstrapFive();

        // SESSION_DRIVER=database without the end-of-request rewrite of an unchanged
        // session (see LazyDatabaseSessionHandler).
        Session::extend('database', fn ($app) => new LazyDatabaseSessionHandler(
            $app['db']->connection($app['config']['session.connection']),
            $app['config']['session.table'],
            (int) $app['config']['session.lifetime'],
            $app,
        ));

        Gate::policy(Attachment::class, AttachmentPolicy::class);

        // TLS ends at the hosting proxy; generate https links in production.
        if ($this->app->environment('production')) {
            URL::forceScheme('https');
        }
    }
}
