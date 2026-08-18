<?php

namespace App\Mail;

use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Envelope;

/**
 * The company's answer to somebody who wrote in from the Contact page.
 *
 * The mirror image of ContactInquiryMail: that one carries a stranger's words
 * into the company's inbox, this one carries the company's words back out. It
 * quotes the original message underneath the reply, because the person reading
 * it wrote days ago and has no ticket number to look anything up with.
 *
 * It always leaves from the company's own address, never the staff member's -
 * see envelope(). Who wrote the reply is the company's business and is kept in
 * the inquiry record; what the enquirer sees is the company answering.
 */
class InquiryReplyMail extends SystemMail
{
    public function __construct(
        public readonly string $recipientName,
        public readonly string $inquirySubject,
        public readonly string $replyBody,
        public readonly string $originalMessage,
        public readonly string $reference,
    ) {}

    protected function subjectLine(): string
    {
        // "Re:" so it threads beside their own message in most mail clients.
        return 'Re: '.$this->inquirySubject;
    }

    protected function template(): string
    {
        return 'emails.inquiry-reply';
    }

    /**
     * One sender for every reply, whichever administrator wrote it.
     *
     * Stated here rather than left to the global default for two reasons. An
     * enquirer should see one company answering rather than a different
     * mailbox each time - and a staff member's own address must never be put
     * in front of a stranger. It is also the only thing that will actually
     * leave the building: an SMTP account will not send a message claiming to
     * be from an address it does not own, so the address the system
     * authenticates as is the address a reply must carry.
     *
     * Reply-To is the company inbox, so an answer to the answer comes back to
     * the same place the enquiry did.
     */
    public function envelope(): Envelope
    {
        $sender = (string) config('mail.from.address');
        $inbox = (string) config('mail.inquiries_to') ?: $sender;

        return new Envelope(
            subject: $this->subjectLine(),
            from: new Address($sender, (string) config('mail.from.name')),
            replyTo: [new Address($inbox)],
        );
    }

    /**
     * @return array<string, mixed>
     */
    protected function payload(): array
    {
        return [
            'recipientName' => $this->recipientName,
            'inquirySubject' => $this->inquirySubject,
            'replyBody' => $this->replyBody,
            'originalMessage' => $this->originalMessage,
            'reference' => $this->reference,
        ];
    }
}
