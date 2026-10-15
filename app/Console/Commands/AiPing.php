<?php

namespace App\Console\Commands;

use App\Services\Ai\AiProvider;
use App\Services\Ai\OpenRouterClient;
use Illuminate\Console\Command;

/**
 * `php artisan ai:ping` — a one-command health check for the AI connection, whichever
 * provider is configured (the Claude API when ANTHROPIC_API_KEY is set, else OpenRouter).
 *
 * Beginner-friendly: run it after pasting your key into .env. It tells you, in plain
 * language, whether the key is present, whether the provider accepts it, and which model
 * answered — without touching any business data.
 */
class AiPing extends Command
{
    protected $signature = 'ai:ping';

    protected $description = 'Check that the AI connection (Claude API or OpenRouter) is working.';

    public function handle(OpenRouterClient $client): int
    {
        $provider = AiProvider::label();

        if (! $client->enabled()) {
            $this->error('No API key found.');
            $this->line('Set ANTHROPIC_API_KEY (for the Claude API) or OPENROUTER_API_KEY in the environment, then run this again.');

            return self::FAILURE;
        }

        $this->info("Key found. Contacting {$provider} via ".class_basename($client).'…');

        try {
            $reply = $client->chat(
                [['role' => 'user', 'content' => 'Reply with exactly the word: pong']],
                'cheap',
            );

            $this->newLine();
            $this->line('  Model asked: '.$client->model('cheap'));
            if (method_exists($client, 'lastModel') && $client->lastModel()) {
                $this->line('  Answered by: '.$client->lastModel());
            }
            $this->line('  Reply      : '.trim($reply));
            $this->newLine();
            $this->info('AI connection is working. You are ready to go.');

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error("Could not reach {$provider}: ".$e->getMessage());
            $this->line('Common causes: key typo, no credit on the account, or no internet.');

            return self::FAILURE;
        }
    }
}
