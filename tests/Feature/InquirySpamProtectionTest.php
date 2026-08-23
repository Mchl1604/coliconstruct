<?php

namespace Tests\Feature;

use App\Models\Inquiry;
use App\Services\InquirySpamGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Tests\TestCase;

/**
 * Spam protection on the public Contact form.
 *
 * Three fences, tested as three separate things because they are three
 * separate things: the honeypot, the window on the address a request comes
 * from, and the windows on the address it claims to be from.
 *
 * The point of the last of those is proved here as much as the limit itself.
 * An IP is a poor identity - one office, one school, one phone network, many
 * people - so the tests below insist that two colleagues sharing a connection
 * are delayed rather than shut out, and that one address cannot walk around
 * its own cap by changing case, padding it with spaces or moving to another
 * connection.
 *
 * @see InquirySpamGuard
 */
class InquirySpamProtectionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Rosa Villanueva',
            'email' => 'rosa@example.test',
            'subject' => 'Aircon installation quote',
            'message' => 'We need two split-type units installed at our office in Cavite.',
        ], $overrides);
    }

    /**
     * Submit the form as somebody sitting at a particular address.
     *
     * The address is named on every call rather than left to the default, so
     * "the same connection" and "a different connection" are visible in the
     * test rather than implied by its absence.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function sendFrom(string $ip, array $overrides = []): TestResponse
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->post(route('public.contact.send'), $this->payload($overrides));
    }

    // ==================================================================
    // The window on one connection
    // ==================================================================

    public function test_the_first_inquiry_from_an_address_is_accepted(): void
    {
        Mail::fake();

        $this->sendFrom('203.0.113.10')
            ->assertRedirect()
            ->assertSessionHas('success')
            ->assertSessionHasNoErrors();

        $this->assertSame(1, Inquiry::count());
    }

    public function test_a_second_inquiry_from_the_same_address_is_refused(): void
    {
        Mail::fake();

        $this->sendFrom('203.0.113.10')->assertSessionHas('success');

        $this->sendFrom('203.0.113.10', ['subject' => 'One too many'])
            ->assertRedirect()
            ->assertSessionHas('error', InquirySpamGuard::IP_MESSAGE);

        $this->assertSame(1, Inquiry::count(), 'The second enquiry was never stored.');
    }

    /**
     * What the visitor is shown, and what they are not.
     *
     * A toast raised by the flash the controller sets - the system's existing
     * one, the same container every other message in the application uses -
     * and nothing that names a limit, a count or a number of minutes.
     */
    public function test_a_refused_inquiry_raises_a_toast_and_gives_the_message_back(): void
    {
        Mail::fake();

        $this->sendFrom('203.0.113.10')->assertSessionHas('success');

        $response = $this->sendFrom('203.0.113.10', ['subject' => 'One too many']);

        $response->assertSessionHas('error', InquirySpamGuard::IP_MESSAGE);

        // Nothing they typed is lost while they wait.
        $response->assertSessionHasInput('message');

        $message = mb_strtolower((string) session('error'));

        $this->assertStringNotContainsString('10 minutes', $message);
        $this->assertStringNotContainsString('rate', $message);
        $this->assertStringNotContainsString('limit', $message);
    }

    public function test_the_same_address_may_submit_again_once_the_window_passes(): void
    {
        Mail::fake();

        $this->sendFrom('203.0.113.10')->assertSessionHas('success');
        $this->sendFrom('203.0.113.10')->assertSessionHas('error');

        $this->travel(11)->minutes();

        $this->sendFrom('203.0.113.10', ['subject' => 'Following up'])
            ->assertSessionHas('success');

        $this->assertSame(2, Inquiry::count());
    }

    /**
     * The reason the email windows exist at all.
     *
     * Two colleagues in one office share one address. The second is held for
     * the length of a window and then gets through - never turned away for
     * good because of what the first one did.
     */
    public function test_a_shared_connection_delays_the_next_person_rather_than_blocking_them(): void
    {
        Mail::fake();

        $this->sendFrom('198.51.100.7', ['email' => 'rosa@example.test'])
            ->assertSessionHas('success');

        // A colleague at the same desk bank, moments later.
        $this->sendFrom('198.51.100.7', ['email' => 'daniel@example.test'])
            ->assertSessionHas('error', InquirySpamGuard::IP_MESSAGE);

        $this->travel(11)->minutes();

        $this->sendFrom('198.51.100.7', ['email' => 'daniel@example.test'])
            ->assertSessionHas('success');

        $this->assertSame(2, Inquiry::count());
        $this->assertSame(1, Inquiry::where('email', 'daniel@example.test')->count());
    }

    /**
     * And the other half of it: two people on separate connections never
     * interfere with each other at all.
     */
    public function test_separate_visitors_on_separate_connections_are_unaffected(): void
    {
        Mail::fake();

        $this->sendFrom('203.0.113.1', ['email' => 'rosa@example.test'])->assertSessionHas('success');
        $this->sendFrom('203.0.113.2', ['email' => 'daniel@example.test'])->assertSessionHas('success');
        $this->sendFrom('203.0.113.3', ['email' => 'maria@example.test'])->assertSessionHas('success');

        $this->assertSame(3, Inquiry::count());
    }

    // ==================================================================
    // The windows on one address
    // ==================================================================

    public function test_an_address_may_send_three_inquiries_in_an_hour(): void
    {
        Mail::fake();

        foreach (['203.0.113.1', '203.0.113.2', '203.0.113.3'] as $index => $ip) {
            $this->sendFrom($ip, ['subject' => 'Quote request '.($index + 1)])
                ->assertSessionHas('success');
        }

        $this->assertSame(3, Inquiry::count());
    }

    /**
     * The fourth is refused even though every one of them came from a fresh
     * connection - which is the whole point of counting the address as well.
     */
    public function test_a_fourth_inquiry_from_one_address_within_the_hour_is_refused(): void
    {
        Mail::fake();

        foreach (['203.0.113.1', '203.0.113.2', '203.0.113.3'] as $ip) {
            $this->sendFrom($ip)->assertSessionHas('success');
        }

        $this->sendFrom('203.0.113.4', ['subject' => 'One too many'])
            ->assertSessionHas('error', InquirySpamGuard::EMAIL_MESSAGE);

        $this->assertSame(3, Inquiry::count());
    }

    public function test_the_hourly_window_reopens_once_the_hour_passes(): void
    {
        Mail::fake();

        foreach (['203.0.113.1', '203.0.113.2', '203.0.113.3'] as $ip) {
            $this->sendFrom($ip)->assertSessionHas('success');
        }

        $this->sendFrom('203.0.113.4')->assertSessionHas('error');

        $this->travel(61)->minutes();

        $this->sendFrom('203.0.113.4', ['subject' => 'Following up'])
            ->assertSessionHas('success');

        $this->assertSame(4, Inquiry::count());
    }

    /**
     * The outer fence: ten a day, whatever the hourly window has forgotten.
     *
     * Sent in batches of three an hour apart, each from its own connection,
     * which is the most a patient script could manage. The eleventh is refused
     * with hours of the day still to run.
     */
    public function test_an_address_is_held_to_ten_inquiries_a_day(): void
    {
        Mail::fake();

        $sent = 0;

        for ($batch = 0; $batch < 4; $batch++) {
            for ($withinBatch = 0; $withinBatch < 3 && $sent < 10; $withinBatch++) {
                $sent++;

                $this->sendFrom('203.0.113.'.$sent, ['subject' => 'Quote request '.$sent])
                    ->assertSessionHas('success');
            }

            $this->travel(61)->minutes();
        }

        $this->assertSame(10, Inquiry::count());

        // Room left in the hourly window, and none left in the day's.
        $this->sendFrom('203.0.113.99', ['subject' => 'One too many'])
            ->assertSessionHas('error', InquirySpamGuard::EMAIL_MESSAGE);

        $this->assertSame(10, Inquiry::count());
    }

    // ==================================================================
    // Normalising the address
    // ==================================================================

    public function test_changing_the_case_of_an_address_does_not_buy_another_inquiry(): void
    {
        Mail::fake();

        $this->sendFrom('203.0.113.1', ['email' => 'rosa@example.test'])->assertSessionHas('success');
        $this->sendFrom('203.0.113.2', ['email' => 'Rosa@Example.Test'])->assertSessionHas('success');
        $this->sendFrom('203.0.113.3', ['email' => 'ROSA@EXAMPLE.TEST'])->assertSessionHas('success');

        $this->sendFrom('203.0.113.4', ['email' => 'RoSa@ExAmPlE.tEsT'])
            ->assertSessionHas('error', InquirySpamGuard::EMAIL_MESSAGE);

        $this->assertSame(3, Inquiry::count());
    }

    public function test_padding_an_address_with_spaces_does_not_buy_another_inquiry(): void
    {
        Mail::fake();

        $this->sendFrom('203.0.113.1', ['email' => 'rosa@example.test'])->assertSessionHas('success');
        $this->sendFrom('203.0.113.2', ['email' => '  rosa@example.test'])->assertSessionHas('success');
        $this->sendFrom('203.0.113.3', ['email' => 'rosa@example.test  '])->assertSessionHas('success');

        $this->sendFrom('203.0.113.4', ['email' => '  Rosa@Example.test  '])
            ->assertSessionHas('error', InquirySpamGuard::EMAIL_MESSAGE);

        $this->assertSame(3, Inquiry::count());

        // And what was stored is the address itself, not the padding.
        $this->assertSame(
            3,
            Inquiry::where('email', 'rosa@example.test')->count(),
            'Every one of them was stored against the same address.'
        );
    }

    /**
     * The normalisation on its own, without a request around it.
     */
    public function test_the_guard_counts_one_address_however_it_is_written(): void
    {
        $guard = app(InquirySpamGuard::class);
        $request = Request::create('/contact', 'POST');

        $guard->recordSubmission($request, '  Rosa@Example.Test  ');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(InquirySpamGuard::IP_MESSAGE);

        $guard->guard($request, 'rosa@example.test');
    }

    // ==================================================================
    // The honeypot
    // ==================================================================

    public function test_a_filled_honeypot_is_refused_without_saying_so(): void
    {
        Mail::fake();

        $this->sendFrom('203.0.113.1', ['company_website' => 'http://spam.example'])
            ->assertRedirect()
            ->assertSessionHas('success')
            ->assertSessionHasNoErrors();

        $this->assertSame(0, Inquiry::count());
        Mail::assertNothingQueued();
    }

    /**
     * A caught bot must not be able to spend somebody else's window, or a
     * script would have a way of locking a shared office out of the form.
     */
    public function test_a_caught_bot_costs_nobody_their_own_submission(): void
    {
        Mail::fake();

        $this->sendFrom('198.51.100.7', ['company_website' => 'http://spam.example'])
            ->assertSessionHas('success');

        $this->sendFrom('198.51.100.7')->assertSessionHas('success');

        $this->assertSame(1, Inquiry::count());
    }

    public function test_a_visitor_who_leaves_the_hidden_field_alone_is_unaffected(): void
    {
        Mail::fake();

        // Present and empty, which is what a browser actually submits.
        $this->sendFrom('203.0.113.1', ['company_website' => ''])->assertSessionHas('success');

        $this->assertSame(1, Inquiry::count());
    }

    public function test_the_hidden_field_is_on_the_form_and_out_of_everybodys_way(): void
    {
        $html = $this->get(route('public.contact'))->assertOk()->getContent();

        $this->assertStringContainsString('name="company_website"', $html);
        $this->assertStringContainsString('tabindex="-1"', $html);
        $this->assertStringContainsString('autocomplete="off"', $html);
        $this->assertStringContainsString('aria-hidden="true"', $html);
    }

    // ==================================================================
    // What the form does not say
    // ==================================================================

    /**
     * The protection is invisible until it stops somebody.
     *
     * No notice, no counter, no timer, no cooldown - a visitor reading the
     * Contact page sees an ordinary contact form, and a script reading it
     * learns nothing about what it is up against.
     */
    public function test_the_contact_page_advertises_no_limits(): void
    {
        $html = mb_strtolower($this->get(route('public.contact'))->assertOk()->getContent());

        foreach ([
            '10 minutes',
            'rate limit',
            'rate-limit',
            'per hour',
            'per day',
            'cooldown',
            'you can only submit',
            'please wait before submitting',
            'too many inquiries',
        ] as $phrase) {
            $this->assertStringNotContainsString(
                $phrase,
                $html,
                sprintf('The Contact page must not mention "%s".', $phrase)
            );
        }
    }

    // ==================================================================
    // Nothing else moved
    // ==================================================================

    /**
     * The limits are a property of the deployment, not a setting somebody
     * administers, so they live in config and nowhere near Configuration.
     */
    public function test_the_limits_are_configuration_file_values(): void
    {
        $this->assertSame(1, config('inquiries.per_ip.max'));
        $this->assertSame(600, config('inquiries.per_ip.decay_seconds'));
        $this->assertSame(3, config('inquiries.per_email.hourly.max'));
        $this->assertSame(10, config('inquiries.per_email.daily.max'));
    }

    /**
     * A deployment that raises the cap gets the cap it asked for. Proves the
     * guard reads config rather than carrying the numbers in its own code.
     */
    public function test_a_deployment_may_widen_the_window_from_config(): void
    {
        Mail::fake();

        Config::set('inquiries.per_ip.max', 2);

        $this->sendFrom('203.0.113.10')->assertSessionHas('success');
        $this->sendFrom('203.0.113.10', ['email' => 'daniel@example.test'])->assertSessionHas('success');
        $this->sendFrom('203.0.113.10', ['email' => 'maria@example.test'])
            ->assertSessionHas('error', InquirySpamGuard::IP_MESSAGE);

        $this->assertSame(2, Inquiry::count());
    }

    /**
     * The limits guard the form; they do not change what the form accepts.
     */
    public function test_the_existing_validation_is_untouched(): void
    {
        Mail::fake();

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.1'])
            ->post(route('public.contact.send'), [
                'name' => '',
                'email' => 'not-an-address',
                'subject' => '',
                'message' => 'short',
            ])->assertSessionHasErrors(['name', 'email', 'subject', 'message']);

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.2'])
            ->post(route('public.contact.send'), $this->payload([
                'name' => str_repeat('a', Inquiry::MAX_NAME + 1),
                'subject' => str_repeat('b', Inquiry::MAX_SUBJECT + 1),
                'message' => str_repeat('c', Inquiry::MAX_MESSAGE + 1),
            ]))->assertSessionHasErrors(['name', 'subject', 'message']);

        $this->assertSame(0, Inquiry::count());
    }

    /**
     * A refused submission is not a submission, so it spends nothing.
     *
     * Somebody who mistypes their message and corrects it must not find the
     * form closed to them.
     */
    public function test_a_submission_that_failed_validation_costs_nothing(): void
    {
        Mail::fake();

        for ($attempt = 0; $attempt < 3; $attempt++) {
            $this->sendFrom('203.0.113.10', ['message' => 'short'])
                ->assertSessionHasErrors('message');
        }

        $this->sendFrom('203.0.113.10')->assertSessionHas('success');

        $this->assertSame(1, Inquiry::count());
    }
}
