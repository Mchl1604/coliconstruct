<?php

namespace App\Console\Commands;

use App\Mail\OtpCodeMail;
use App\Models\OtpVerification;
use App\Services\OtpService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Proves the mail configuration end to end.
 *
 * The one thing an administrator needs after filling in `.env`: something that
 * either lands in an inbox or says exactly why it did not. Sent inline rather
 * than queued, because "the message reached the mail server" is the question
 * being asked, and a queued message would answer it in a worker's log instead
 * of on the screen.
 */
class SendTestEmail extends Command
{
    protected $signature = 'mail:test {email : Where to send the test message}';

    protected $description = 'Send a test email to confirm the mail settings work.';

    public function handle(): int
    {
        $recipient = (string) $this->argument('email');

        if (filter_var($recipient, FILTER_VALIDATE_EMAIL) === false) {
            $this->error(sprintf('"%s" is not a valid email address.', $recipient));

            return self::FAILURE;
        }

        $mailer = (string) config('mail.default');

        $this->line('');
        $this->line('  Mailer .......... '.$mailer);
        $this->reportCredentials($mailer);
        $this->line('  From ............ '.config('mail.from.name').' <'.config('mail.from.address').'>');
        $this->line('  Company ......... '.config('company.name'));
        $this->line('');

        if (in_array(config('mail.default'), ['log', 'array'], true)) {
            $this->warn('MAIL_MAILER is "'.config('mail.default').'", so nothing will actually be delivered.');
            $this->warn('The message will be written to storage/logs instead. Set MAIL_MAILER=smtp to send for real.');
            $this->line('');
        }

        try {
            // A real message from the real templates, so this exercises the
            // branding and the layout as well as the transport.
            // sendNow rather than send: every system mailable is ShouldQueue,
            // and send() would hand it to a worker - whose failure is not this
            // command's answer to give.
            Mail::to($recipient)->sendNow(new OtpCodeMail(
                '123456',
                OtpVerification::PURPOSE_REGISTRATION,
                OtpService::VALID_MINUTES,
                'Administrator'
            ));
        } catch (Throwable $exception) {
            $this->error('The message could not be sent.');
            $this->line('');
            $this->line('  '.$exception->getMessage());
            $this->line('');
            $this->line('Common causes: a key or password that is not set on this environment,');
            $this->line('a sending address whose domain the provider has not verified, or - on');
            $this->line('SMTP - a wrong host or port, or a host that blocks outbound SMTP.');

            return self::FAILURE;
        }

        $this->info(sprintf('A test message was handed to the mailer for %s.', $recipient));
        $this->line('The code in it is a fixed 123456 and verifies nothing - it is a sample.');

        return self::SUCCESS;
    }

    /**
     * Print the credentials the active mailer actually reads.
     *
     * Printing the SMTP block unconditionally is worse than printing nothing:
     * on an API-based mailer it shows a host and port that are never dialled,
     * and stays silent about the one value that decides whether the send
     * works. Secrets are reported as set or not set, never echoed - this
     * command is run on production terminals.
     */
    private function reportCredentials(string $mailer): void
    {
        $state = static fn (mixed $value): string => filled($value) ? '(set)' : '(NOT SET)';

        match ($mailer) {
            'resend' => $this->line('  API key ......... '.$state(config('services.resend.key')).'  [RESEND_API_KEY]'),
            'postmark' => $this->line('  API key ......... '.$state(config('services.postmark.key')).'  [POSTMARK_API_KEY]'),
            'ses' => $this->line('  AWS key ......... '.$state(config('services.ses.key')).'  [AWS_ACCESS_KEY_ID]'),
            'log', 'array' => $this->line('  Delivery ........ none - written to the log'),
            default => $this->reportSmtpCredentials(),
        };
    }

    private function reportSmtpCredentials(): void
    {
        $this->line('  Host ............ '.config('mail.mailers.smtp.host').':'.config('mail.mailers.smtp.port'));
        $this->line('  Username ........ '.(config('mail.mailers.smtp.username') ?: '(not set)'));
        $this->line('  Password ........ '.(config('mail.mailers.smtp.password') ? '(set)' : '(NOT SET)'));
        $this->line('  Scheme .......... '.(config('mail.mailers.smtp.scheme') ?: '(none - STARTTLS)'));
    }
}
