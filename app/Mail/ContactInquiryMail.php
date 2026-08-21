<?php

namespace App\Mail;

use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Somebody has written in from the public website's Contact page.
 *
 * The one email in this system that comes from outside it. Everything else is
 * addressed to a person the application already knows; this is addressed to
 * the company, about somebody it does not know yet.
 *
 * Two things follow from that. The sender is the company's own address rather
 * than the enquirer's - a mail server will not accept a message claiming to be
 * from a domain it does not own, and forging one is how a perfectly good
 * enquiry lands in spam. And their address goes on Reply-To instead, so
 * answering the message in a mail client does the obvious thing.
 */
class ContactInquiryMail extends SystemMail
{
    public function __construct(
        public readonly string $senderName,
        public readonly string $senderEmail,
        public readonly string $inquirySubject,
        public readonly string $body,
    ) {}

    protected function subjectLine(): string
    {
        return 'Website inquiry: '.$this->inquirySubject;
    }

    protected function template(): string
    {
        return 'emails.contact-inquiry';
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->subjectLine(),
            // Hitting Reply answers the person who wrote in, not the company's
            // own inbox.
            replyTo: [new Address($this->senderEmail, $this->senderName)],
        );
    }

    /**
     * @return array<string, mixed>
     */
    protected function payload(): array
    {
        return [
            'senderName' => $this->senderName,
            'senderEmail' => $this->senderEmail,
            'inquirySubject' => $this->inquirySubject,
            'body' => $this->body,
        ];
    }
}
