<?php

namespace App\Mail;

use App\Models\OtpVerification;

/**
 * A one-time verification code.
 *
 * The code travels here in the clear because an email is the only place it can
 * usefully arrive; what is stored in the database is a hash of it. The
 * mailable is deliberately the only thing in the system that ever holds the
 * digits after they are generated.
 */
class OtpCodeMail extends SystemMail
{
    /**
     * @param  string  $code  The six digits, unhashed.
     * @param  string  $purpose  One of the OtpVerification::PURPOSE_* values.
     * @param  string|null  $recipientName  Null for an address with no account.
     */
    public function __construct(
        public readonly string $code,
        public readonly string $purpose,
        public readonly int $minutesValid,
        public readonly ?string $recipientName = null,
    ) {}

    protected function subjectLine(): string
    {
        return match ($this->purpose) {
            OtpVerification::PURPOSE_REGISTRATION => $this->code.' is your '.config('company.name').' verification code',
            OtpVerification::PURPOSE_FORGOT_PASSWORD => $this->code.' is your password reset code',
            OtpVerification::PURPOSE_EMAIL_CHANGE => $this->code.' is your email change code',
            default => $this->code.' is your '.config('company.name').' security code',
        };
    }

    protected function template(): string
    {
        return 'emails.otp-code';
    }

    /**
     * @return array<string, mixed>
     */
    protected function payload(): array
    {
        return [
            'code' => $this->code,
            'purpose' => $this->purpose,
            'purposeLabel' => OtpVerification::PURPOSE_LABELS[$this->purpose] ?? 'verify your request',
            'minutesValid' => $this->minutesValid,
            'recipientName' => $this->recipientName,
            'heading' => match ($this->purpose) {
                OtpVerification::PURPOSE_REGISTRATION => 'Verify your email address',
                OtpVerification::PURPOSE_FORGOT_PASSWORD => 'Reset your password',
                OtpVerification::PURPOSE_EMAIL_CHANGE => 'Confirm your new email address',
                default => 'Your verification code',
            },
        ];
    }
}
