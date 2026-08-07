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

    protected $description = 'Send a test email to confirm the SMTP settings work.';

    public function handle(): int
    {
        $recipient = (string) $this->argument('email');

        if (filter_var($recipient, FILTER_VALIDATE_EMAIL) === false) {
            $this->error(sprintf('"%s" is not a valid email address.', $recipient));

            return self::FAILURE;
        }

        $this->line('');
        $this->line('  Mailer .......... '.config('mail.default'));
        $this->line('  Host ............ '.config('mail.mailers.smtp.host').':'.config('mail.mailers.smtp.port'));
        $this->line('  Username ........ '.(config('mail.mailers.smtp.username') ?: '(not set)'));
        $this->line('  Password ........ '.(config('mail.mailers.smtp.password') ? '(set)' : '(not set)'));
        $this->line('  Scheme .......... '.(config('mail.mailers.smtp.scheme') ?: '(none - STARTTLS)'));
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
            $this->line('Common causes: a wrong host or port, an account password used where an');
            $this->line('app password is required, or a firewall blocking outbound SMTP.');

            return self::FAILURE;
        }

        $this->info(sprintf('A test message was handed to the mailer for %s.', $recipient));
        $this->line('The code in it is a fixed 123456 and verifies nothing - it is a sample.');

        return self::SUCCESS;
    }
}
