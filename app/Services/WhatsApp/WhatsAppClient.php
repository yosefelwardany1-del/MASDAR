<?php

namespace App\Services\WhatsApp;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Sends WhatsApp messages through Meta's Cloud API — approved templates only, never free
 * text: a sign-in code (the AUTHENTICATION template with its copy-code button), and
 * sendTemplate() for an approved UTILITY template such as the container update. Meta refuses
 * business-initiated free text, so there is no generic send() here: this class cannot be
 * turned into a marketing channel by accident.
 *
 * Never throws. A failed send returns false and logs the status and Meta's error
 * message, with the number shown only by its last four digits.
 */
class WhatsAppClient
{
    private ?string $lastError = null;

    public function configured(): bool
    {
        return (bool) config('whatsapp.enabled', true)
            && filled(config('whatsapp.phone_number_id'))
            && filled($this->token());
    }

    /**
     * The System User token to send with — from the environment only
     * (WHATSAPP_ACCESS_TOKEN), never the database.
     */
    public function token(): string
    {
        return (string) config('whatsapp.access_token');
    }

    public function lastError(): ?string
    {
        return $this->lastError;
    }

    /**
     * The two shapes an OTP template can take: Meta's authentication template (body
     * parameter + copy-code button, both carrying the code) or a plain template with
     * the code as its one body parameter. Which one `verify_otp` is cannot be read
     * from here without the business-management permission, so the first send tries
     * the configured shape, falls back to the other when Meta says the parameters do
     * not match (#132000 / #132012), and remembers the one that worked for a day.
     */
    private const SHAPES = ['body_and_button', 'body_and_copy_code', 'body_only'];

    private const CACHE_KEY = 'whatsapp:auth_template_shape';

    /** Which shape sent the last message, for whatsapp:test to report. */
    private ?string $lastShape = null;

    public function lastShape(): ?string
    {
        return $this->lastShape;
    }

    public function sendAuthCode(string $phoneE164, string $code): bool
    {
        $this->lastError = null;
        $this->lastShape = null;

        if (! $this->configured()) {
            $this->lastError = 'WhatsApp is not configured.';

            return false;
        }

        $remembered = (string) Cache::get(self::CACHE_KEY, '');
        $shapes = array_values(array_unique(array_filter(array_merge(
            [$remembered, (string) config('whatsapp.auth_template_shape', 'body_and_button')],
            self::SHAPES,
        ))));

        foreach ($shapes as $shape) {
            if (! in_array($shape, self::SHAPES, true)) {
                continue;
            }

            $result = $this->post($phoneE164, $this->template($code, $shape));

            if ($result === true) {
                $this->lastShape = $shape;
                Cache::put(self::CACHE_KEY, $shape, now()->addDay());

                return true;
            }

            // Anything but "the parameters do not fit this template" is final.
            if (! in_array($result, [132000, 132012], true)) {
                return false;
            }
        }

        return false;
    }

    /**
     * One approved template with its body parameters in order ({{1}}, {{2}} …). True when
     * Meta accepted it; false otherwise, with lastError() saying why. Never throws.
     *
     * @param  list<string>  $bodyParams
     */
    public function sendTemplate(string $phoneE164, string $template, string $language, array $bodyParams): bool
    {
        $this->lastError = null;
        if (! $this->configured()) {
            $this->lastError = 'WhatsApp is not configured.';

            return false;
        }

        return $this->post($phoneE164, [
            'name' => $template,
            'language' => ['code' => $language],
            'components' => [['type' => 'body', 'parameters' => array_map(
                fn ($value) => ['type' => 'text', 'text' => (string) $value],
                array_values($bodyParams),
            )]],
        ]) === true;
    }

    /**
     * A document (a PDF) as the header of an approved template, with its body parameters
     * (spec §5.8). The file is uploaded to Meta first; true when the message was accepted,
     * false otherwise with lastError() saying why. Never throws.
     *
     * @param  list<string>  $bodyParams
     */
    public function sendDocumentTemplate(string $phoneE164, string $pdf, string $filename, string $template, string $language, array $bodyParams): bool
    {
        $this->lastError = null;
        if (! $this->configured()) {
            $this->lastError = 'WhatsApp is not configured.';

            return false;
        }

        $base = rtrim((string) config('whatsapp.base_url'), '/').'/'.config('whatsapp.api_version').'/'.config('whatsapp.phone_number_id');
        try {
            $upload = Http::withToken($this->token())
                ->timeout((int) config('whatsapp.timeout', 15) * 2)
                ->attach('file', $pdf, $filename, ['Content-Type' => 'application/pdf'])
                ->post($base.'/media', ['messaging_product' => 'whatsapp', 'type' => 'application/pdf']);
        } catch (\Throwable $e) {
            report($e);
            $this->lastError = 'WhatsApp did not answer.';

            return false;
        }
        $mediaId = $upload->successful() ? (string) $upload->json('id') : '';
        if ($mediaId === '') {
            $this->lastError = 'The file was not uploaded: '.((string) ($upload->json('error.message') ?: Str::limit($upload->body(), 200)));

            return false;
        }

        return $this->post($phoneE164, [
            'name' => $template,
            'language' => ['code' => $language],
            'components' => [
                ['type' => 'header', 'parameters' => [['type' => 'document', 'document' => ['id' => $mediaId, 'filename' => $filename]]]],
                ['type' => 'body', 'parameters' => array_map(fn ($value) => ['type' => 'text', 'text' => (string) $value], array_values($bodyParams))],
            ],
        ]) === true;
    }

    /** @return array<string,mixed> */
    private function template(string $code, string $shape): array
    {
        // The code first, then whatever else the template's body asks for.
        $bodyValues = array_merge([$code], (array) config('whatsapp.auth_template_extra_params', []));
        $components = [['type' => 'body', 'parameters' => array_map(
            fn ($value) => ['type' => 'text', 'text' => (string) $value],
            $bodyValues,
        )]];

        if ($shape === 'body_and_button') {
            // Meta's authentication template: the copy-code button is a URL button
            // whose one parameter is the code.
            $components[] = ['type' => 'button', 'sub_type' => 'url', 'index' => '0', 'parameters' => [['type' => 'text', 'text' => $code]]];
        } elseif ($shape === 'body_and_copy_code') {
            // A copy-code button added to a utility/marketing template is registered
            // as a coupon-code button and wants this shape instead.
            $components[] = ['type' => 'button', 'sub_type' => 'copy_code', 'index' => '0', 'parameters' => [['type' => 'coupon_code', 'coupon_code' => $code]]];
        }

        return [
            'name' => (string) config('whatsapp.auth_template'),
            'language' => ['code' => (string) config('whatsapp.auth_template_language', 'en')],
            'components' => $components,
        ];
    }

    /**
     * One send. True on success; Meta's numeric error code on a refusal (0 when it
     * gave none); false when the request never completed.
     *
     * @param  array<string,mixed>  $template
     */
    private function post(string $phoneE164, array $template): bool|int
    {
        $url = rtrim((string) config('whatsapp.base_url'), '/').'/'.config('whatsapp.api_version')
            .'/'.config('whatsapp.phone_number_id').'/messages';

        try {
            $res = Http::withToken($this->token())
                ->timeout((int) config('whatsapp.timeout', 15))
                ->post($url, [
                    'messaging_product' => 'whatsapp',
                    'to' => ltrim($phoneE164, '+'),
                    'type' => 'template',
                    'template' => $template,
                ]);
        } catch (\Throwable $e) {
            report($e);
            $this->lastError = 'WhatsApp did not answer.';

            return false;
        }

        if ($res->successful()) {
            return true;
        }

        // error_data.details is where Meta says WHICH component it objected to.
        $details = (string) ($res->json('error.error_data.details') ?: '');
        $this->lastError = (string) ($res->json('error.message') ?: Str::limit($res->body(), 200))
            .($details !== '' ? ' — '.$details : '');
        Log::warning('WhatsApp: message not sent', [
            'template' => $template['name'] ?? null,
            'status' => $res->status(),
            'code' => $res->json('error.code'),
            'error' => $this->lastError,
            'to' => '…'.substr($phoneE164, -4),
        ]);

        return (int) ($res->json('error.code') ?? 0);
    }
}
