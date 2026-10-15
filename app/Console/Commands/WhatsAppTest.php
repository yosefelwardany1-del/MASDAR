<?php

namespace App\Console\Commands;

use App\Services\WhatsApp\PhoneNumber;
use App\Services\WhatsApp\WhatsAppClient;
use Illuminate\Console\Command;

/**
 * `php artisan whatsapp:test +2010…` — send one real sign-in code to a number and
 * print exactly what Meta said.
 *
 * The three ways this setup fails (wrong template name, template not approved, token
 * without the messaging permission) all look the same from the sign-in page: no
 * message arrives. Here the reason is printed.
 */
class WhatsAppTest extends Command
{
    protected $signature = 'whatsapp:test {phone : The number to send to, with country code}';

    protected $description = 'Send a test sign-in code over WhatsApp and show the outcome';

    public function handle(WhatsAppClient $whatsapp): int
    {
        if (! $whatsapp->configured()) {
            $this->error('WhatsApp is not configured: set WHATSAPP_PHONE_NUMBER_ID and WHATSAPP_ACCESS_TOKEN.');

            return self::FAILURE;
        }

        $e164 = PhoneNumber::normalise((string) $this->argument('phone'));
        if ($e164 === null) {
            $this->error('That does not look like a phone number. Include the country code, e.g. +20 10 1234 5678.');

            return self::FAILURE;
        }

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        $this->line('Sender id : '.config('whatsapp.phone_number_id'));
        $extra = (array) config('whatsapp.auth_template_extra_params', []);
        $this->line('Template  : '.config('whatsapp.auth_template').' ('.config('whatsapp.auth_template_language').')'
            .($extra !== [] ? ' — extra body params: '.implode(', ', $extra) : ''));
        $this->line('To        : '.PhoneNumber::masked($e164));
        $this->line('Code      : '.$code);
        $this->newLine();

        if ($whatsapp->sendAuthCode($e164, $code)) {
            $this->info('Meta accepted the message (template shape: '.$whatsapp->lastShape().'). If it does not arrive within a minute, the number has never messaged this sender and the template category is wrong for a first contact.');

            return self::SUCCESS;
        }

        $this->error('Meta refused every shape. Last answer: '.($whatsapp->lastError() ?: 'no detail'));
        $this->line('Common causes: the template name or language does not match an APPROVED authentication template; the token lacks whatsapp_business_messaging; the phone-number id belongs to a different WhatsApp Business account.');

        return self::FAILURE;
    }
}
