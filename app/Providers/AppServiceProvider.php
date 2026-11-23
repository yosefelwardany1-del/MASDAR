<?php

namespace App\Providers;

use App\Models\Attachment;
use App\Policies\AttachmentPolicy;
use App\Services\Ai\AiProvider;
use App\Services\Ai\AnthropicClient;
use App\Services\Ai\OpenRouterClient;
use App\Support\Session\LazyDatabaseSessionHandler;
use Illuminate\Http\UploadedFile;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Validator;
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

        // B33: a file the server refused says why in words — too big, with the limit — never
        // "The files.0 failed to upload."
        Validator::replacer('uploaded', function (string $message, string $attribute, string $rule, array $parameters, $validator) {
            $file = Arr::get($validator->getData(), $attribute);
            if ($file instanceof UploadedFile && in_array($file->getError(), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
                $mb = rtrim(rtrim(number_format(UploadedFile::getMaxFilesize() / 1048576, 1, '.', ''), '0'), '.');

                return __('This file is bigger than the :max MB limit.', ['max' => $mb]);
            }

            return __('This file could not be uploaded. Try again.');
        });

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
