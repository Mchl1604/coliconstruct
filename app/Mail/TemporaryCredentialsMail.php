<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The sign-in details handed to a newly created account, and the same message
 * again after an administrator resets a password.
 *
 * The plain password is passed in rather than read from the model - the stored
 * one is hashed and cannot be read back, which is the point.
 */
class TemporaryCredentialsMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly User $account,
        public readonly string $temporaryPassword,
        public readonly bool $isReset = false,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->isReset
                ? 'Your Coliconstruct password has been reset'
                : 'Your Coliconstruct account is ready',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.temporary-credentials',
            with: [
                'account' => $this->account,
                'temporaryPassword' => $this->temporaryPassword,
                'isReset' => $this->isReset,
                'loginUrl' => route('auth.login'),
            ],
        );
    }
}
