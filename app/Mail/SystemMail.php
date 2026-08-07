<?php

namespace App\Mail;

use App\Support\CompanyBranding;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The base every system email is built on.
 *
 * A subclass declares two things - its subject and its template - and gets
 * the rest for free: the company branding, the shared responsive layout, and
 * queued delivery so no user action ever waits on an SMTP round trip.
 *
 * Queued rather than sent inline is deliberate. On the `sync` queue driver
 * Laravel sends immediately anyway, so a deployment without a worker still
 * delivers; with a worker running, the request returns without waiting.
 */
abstract class SystemMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /**
     * How many times the queue may retry a message before giving up.
     *
     * A transient SMTP failure is worth retrying; a permanently rejected
     * address is not worth retrying forever.
     */
    public int $tries = 3;

    /** Seconds between retries. */
    public int $backoff = 30;

    /**
     * The line an inbox shows beside the subject.
     */
    abstract protected function subjectLine(): string;

    /**
     * The Blade template under `resources/views/emails`.
     */
    abstract protected function template(): string;

    /**
     * Everything the template needs beyond the branding.
     *
     * @return array<string, mixed>
     */
    abstract protected function payload(): array;

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine());
    }

    public function content(): Content
    {
        return new Content(
            view: $this->template(),
            with: $this->payload() + ['company' => CompanyBranding::toArray()],
        );
    }
}
