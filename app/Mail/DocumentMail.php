<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** A document sent as a PDF attachment, with the sender's own words (spec §5.8). */
class DocumentMail extends Mailable
{
    use Queueable;

    public function __construct(
        public string $subjectLine,
        public string $body,
        public string $pdf,
        public string $filename,
        public string $companyName,
        public ?string $replyToEmail = null,
        public ?string $replyToName = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->subjectLine,
            replyTo: $this->replyToEmail ? [new Address($this->replyToEmail, $this->replyToName)] : [],
        );
    }

    public function content(): Content
    {
        return new Content(text: 'mail.document', with: ['body' => $this->body, 'companyName' => $this->companyName]);
    }

    public function attachments(): array
    {
        return [Attachment::fromData(fn () => $this->pdf, $this->filename)->withMime('application/pdf')];
    }
}
