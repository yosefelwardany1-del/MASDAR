<?php

namespace App\Services\Documents;

use App\Mail\DocumentMail;
use App\Models\DocumentSend;
use App\Models\Setting;
use App\Models\User;
use App\Services\WhatsApp\PhoneNumber;
use App\Services\WhatsApp\WhatsAppClient;
use App\Support\Audit\AuditTrail;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

/**
 * Sends a document's PDF by email or WhatsApp (spec §5.8) and is the only writer of
 * document_sends: every attempt is logged, sent or failed with the reason, so "did the client
 * get it?" has an answer.
 */
class DocumentSender
{
    public function __construct(private WhatsAppClient $whatsapp) {}

    public function emailReady(): ?string
    {
        $mailer = (string) config('mail.default');
        if (config('documents.email_requires_real_mailer') && in_array($mailer, ['log', 'array'], true)) {
            return __('Email is not set up on this server: set MAIL_MAILER and the MAIL_* settings on Render. The PDF can still be downloaded.');
        }

        return null;
    }

    public function whatsappReady(): ?string
    {
        return $this->whatsapp->configured() ? null : __('WhatsApp is not set up on this server (WHATSAPP_ACCESS_TOKEN, WHATSAPP_PHONE_NUMBER_ID).');
    }

    /**
     * @param  list<string>  $to
     * @param  list<string>  $cc
     */
    public function email(string $type, int $id, string $reference, string $pdf, string $filename, array $to, array $cc, string $subject, string $message, User $by): DocumentSend
    {
        if ($why = $this->emailReady()) {
            throw new DocumentException($why);
        }
        $to = $this->emails($to, 'to', required: true);
        $cc = $this->emails($cc, 'cc');
        if (trim($subject) === '') {
            throw ValidationException::withMessages(['subject' => __('Write a subject.')]);
        }

        $error = null;
        try {
            Mail::to($to)->cc($cc)->send(new DocumentMail(
                $subject, $message, $pdf, $filename, (string) Setting::current()->company_name, $by->email, $by->name,
            ));
        } catch (\Throwable $e) {
            report($e);
            $error = mb_substr($e->getMessage(), 0, 500) ?: 'The mail server refused the message.';
        }

        return $this->log($type, $id, $reference, 'email', implode(', ', array_merge($to, array_map(fn ($c) => 'cc '.$c, $cc))), $subject, $message, $error, $by);
    }

    public function whatsapp(string $type, int $id, string $reference, string $pdf, string $filename, string $phone, User $by): DocumentSend
    {
        if ($why = $this->whatsappReady()) {
            throw new DocumentException($why);
        }
        $number = PhoneNumber::normalise($phone);
        if ($number === null) {
            throw ValidationException::withMessages(['phone' => __('Enter the WhatsApp number with its country code.')]);
        }

        $ok = $this->whatsapp->sendDocumentTemplate(
            $number, $pdf, $filename,
            (string) config('whatsapp.document_template'), (string) config('whatsapp.document_template_language', 'en'),
            [$reference, (string) Setting::current()->company_name],
        );

        return $this->log($type, $id, $reference, 'whatsapp', $number, null, null, $ok ? null : ($this->whatsapp->lastError() ?: 'Not sent.'), $by);
    }

    private function log(string $type, int $id, string $reference, string $channel, string $recipients, ?string $subject, ?string $message, ?string $error, User $by): DocumentSend
    {
        $send = DocumentSend::create([
            'document_type' => $type, 'document_id' => $id, 'reference' => mb_substr($reference, 0, 120),
            'channel' => $channel, 'recipients' => $recipients, 'subject' => $subject !== null ? mb_substr($subject, 0, 255) : null,
            'message' => $message, 'status' => $error === null ? 'sent' : 'failed', 'error' => $error, 'user_id' => $by->id,
        ]);
        AuditTrail::record($error === null ? 'document_sent' : 'document_not_sent', $send, [], [
            'document' => $type.' '.$reference, 'channel' => $channel, 'recipients' => $recipients, 'error' => $error,
        ]);

        return $send;
    }

    /** @return list<string> */
    private function emails(array $values, string $field, bool $required = false): array
    {
        $list = [];
        foreach ($values as $value) {
            foreach (preg_split('/[\s,;]+/', (string) $value) ?: [] as $email) {
                if ($email === '') {
                    continue;
                }
                if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    throw ValidationException::withMessages([$field => __(':email is not an email address.', ['email' => $email])]);
                }
                $list[] = strtolower($email);
            }
        }
        $list = array_values(array_unique($list));
        if ($required && $list === []) {
            throw ValidationException::withMessages([$field => __('Enter at least one email address.')]);
        }
        if (count($list) > 10) {
            throw ValidationException::withMessages([$field => __('Send to ten addresses at most.')]);
        }

        return $list;
    }
}
