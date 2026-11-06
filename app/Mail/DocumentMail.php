<?php

namespace App\Mail;

use App\Models\Company;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Attachment;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class DocumentMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $subjectLine,
        public string $bodyText,
        public string $attachmentData,
        public string $attachmentName,
        public Company $fromCompany,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->subjectLine,
            replyTo: $this->fromCompany->email ? [new Address($this->fromCompany->email, $this->fromCompany->name)] : [],
        );
    }

    public function content(): Content
    {
        return new Content(view: 'mail.document', with: ['body' => $this->bodyText, 'company' => $this->fromCompany]);
    }

    public function attachments(): array
    {
        return [Attachment::fromData(fn () => $this->attachmentData, $this->attachmentName)->withMime('application/pdf')];
    }
}
