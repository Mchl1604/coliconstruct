<?php

namespace Tests\Feature;

use App\Console\Commands\RepairUnusablePasswords;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Forgetting a password, and the accounts that could never sign in because
 * what is stored against them is not a hash at all.
 */
class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    private function account(array $overrides = []): User
    {
        $sequence = User::count() + 1;

        return User::create(array_merge([
            'user_code' => 'EMP-'.str_pad((string) $sequence, 4, '0', STR_PAD_LEFT),
            'name' => 'Test Person',
            'first_name' => 'Test',
            'last_name' => 'Person',
            'email' => 'person'.$sequence.'@example.test',
            'role' => 'technician',
            'status' => User::STATUS_ACTIVE,
            'password' => 'correct-password',
        ], $overrides));
    }

    /**
     * Write a value straight to the column, past the model's hashing cast -
     * which is how the broken rows got there in the first place.
     */
    private function writeRawPassword(User $user, string $value): void
    {
        DB::table('users')->where('id', $user->id)->update(['password' => $value]);
    }

    // ------------------------------------------------------------------
    // The sign-in page
    // ------------------------------------------------------------------

    public function test_the_sign_in_page_offers_a_reset_and_no_remember_box(): void
    {
        $response = $this->get(route('auth.login'));

        $response->assertOk();
        $response->assertSee('Forgot password?');
        $response->assertSee(route('auth.password.request'), false);
        $response->assertDontSee('Keep me signed in');
        $response->assertDontSee('name="remember"', false);
    }

    public function test_every_password_field_has_a_show_hide_toggle(): void
    {
        foreach ([route('auth.login'), route('auth.register')] as $url) {
            $response = $this->get($url);

            $response->assertOk();
            $response->assertSee('data-password-toggle', false);
            $response->assertSee('/js/passwordField.js', false);
        }
    }

    /**
     * The two new passwords are compared as they are typed, so a typo shows up
     * before the form is submitted.
     */
    public function test_the_confirm_fields_are_wired_for_a_match_indication(): void
    {
        $user = $this->account(['must_change_password' => true]);

        $response = $this->actingAs($user)->get(route('auth.password.change'));

        $response->assertOk();
        $response->assertSee('data-password-new', false);
        $response->assertSee('data-password-confirm', false);
        $response->assertSee('data-password-match', false);
    }

    // ------------------------------------------------------------------
    // Forgotten password
    // ------------------------------------------------------------------

    public function test_the_forgot_password_page_says_when_email_is_not_configured(): void
    {
        config(['mail.default' => 'log']);

        $response = $this->get(route('auth.password.request'));

        $response->assertOk();
        $response->assertSee('Email is not configured on this system');
    }

    public function test_no_link_is_sent_when_email_is_not_configured(): void
    {
        Notification::fake();
        config(['mail.default' => 'log']);

        $user = $this->account();

        $this->post(route('auth.password.email'), ['email' => $user->email])
            ->assertSessionHas('error');

        Notification::assertNothingSent();
    }

    public function test_a_reset_link_is_sent_when_email_works(): void
    {
        Notification::fake();
        config(['mail.default' => 'smtp']);

        $user = $this->account();

        $this->post(route('auth.password.email'), ['email' => $user->email])
            ->assertSessionHas('success');

        Notification::assertSentTo($user, ResetPassword::class);
    }

    /**
     * The answer is the same either way: this page must not tell a stranger
     * which addresses have accounts.
     */
    public function test_an_unknown_address_gets_the_same_answer(): void
    {
        Notification::fake();
        config(['mail.default' => 'smtp']);

        $known = $this->account();

        $forKnown = $this->post(route('auth.password.email'), ['email' => $known->email]);
        $forUnknown = $this->post(route('auth.password.email'), ['email' => 'nobody@example.test']);

        $this->assertSame(
            $forKnown->getSession()->get('success'),
            $forUnknown->getSession()->get('success')
        );
    }

    public function test_a_reset_link_sets_the_new_password_and_clears_the_forced_change(): void
    {
        Notification::fake();
        config(['mail.default' => 'smtp']);

        $user = $this->account(['must_change_password' => true]);

        $this->post(route('auth.password.email'), ['email' => $user->email]);

        $token = null;
        Notification::assertSentTo($user, ResetPassword::class, function ($notification) use (&$token) {
            $token = $notification->token;

            return true;
        });

        $this->post(route('auth.password.store'), [
            'token' => $token,
            'email' => $user->email,
            'password' => 'a-brand-new-password',
            'password_confirmation' => 'a-brand-new-password',
        ])->assertRedirect(route('auth.login'));

        $user->refresh();
        $this->assertTrue(Hash::check('a-brand-new-password', $user->password));
        $this->assertFalse(Hash::check('correct-password', $user->password));
        $this->assertFalse((bool) $user->must_change_password);
    }

    public function test_a_made_up_token_is_refused(): void
    {
        $user = $this->account();

        $this->post(route('auth.password.store'), [
            'token' => 'not-a-real-token',
            'email' => $user->email,
            'password' => 'a-brand-new-password',
            'password_confirmation' => 'a-brand-new-password',
        ])->assertSessionHasErrors('email');

        $this->assertTrue(Hash::check('correct-password', $user->refresh()->password));
    }

    public function test_the_two_passwords_must_match(): void
    {
        $user = $this->account();

        $this->post(route('auth.password.store'), [
            'token' => 'anything',
            'email' => $user->email,
            'password' => 'a-brand-new-password',
            'password_confirmation' => 'something-else-entirely',
        ])->assertSessionHasErrors('password');
    }

    // ------------------------------------------------------------------
    // Accounts whose stored password is not a hash
    // ------------------------------------------------------------------

    /**
     * The reported bug: a correct password reports "those credentials do not
     * match", because what is stored is a placeholder rather than a hash.
     */
    public function test_an_account_with_a_placeholder_hash_cannot_sign_in(): void
    {
        $user = $this->account();
        $this->writeRawPassword($user, '$2y$12$examplehashedpassword');

        $this->post(route('auth.login.attempt'), [
            'email' => $user->email,
            'password' => 'correct-password',
        ])->assertSessionHasErrors('email');

        $this->assertFalse($user->refresh()->hasUsablePassword());
    }

    public function test_a_real_hash_reads_as_usable(): void
    {
        $this->assertTrue($this->account()->hasUsablePassword());
    }

    public function test_the_repair_command_finds_and_fixes_them(): void
    {
        $broken = $this->account();
        $healthy = $this->account();
        $this->writeRawPassword($broken, '$2y$12$examplehashedpassword');

        $this->assertSame(1, User::query()->withUnusablePassword()->count());

        $this->artisan('users:repair-passwords', [
            '--password' => 'issued-by-the-command',
            '--keep-password' => true,
        ])->assertExitCode(RepairUnusablePasswords::SUCCESS);

        $broken->refresh();
        $this->assertTrue($broken->hasUsablePassword());
        $this->assertTrue(Hash::check('issued-by-the-command', $broken->password));
        $this->assertSame(0, User::query()->withUnusablePassword()->count());

        // A healthy account is left exactly as it was.
        $this->assertTrue(Hash::check('correct-password', $healthy->refresh()->password));

        // And the repaired account can now actually sign in.
        $this->post(route('auth.login.attempt'), [
            'email' => $broken->email,
            'password' => 'issued-by-the-command',
        ])->assertRedirect();
    }

    public function test_the_repair_command_changes_nothing_on_a_dry_run(): void
    {
        $broken = $this->account();
        $this->writeRawPassword($broken, '$2y$12$examplehashedpassword');

        $this->artisan('users:repair-passwords', ['--dry-run' => true])
            ->assertExitCode(RepairUnusablePasswords::SUCCESS);

        $this->assertFalse($broken->refresh()->hasUsablePassword());
    }
}
