<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Client;
use App\Models\Project;
use App\Models\SystemContent;
use App\Models\User;
use App\Services\SystemContentService;
use App\Services\UserAccountService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Agreeing to the Terms and Conditions, and being held until you do.
 *
 * The document itself is nothing new: it has always been one editable field in
 * System Settings, read through SystemContentService. What is new is that the
 * system can now tell WHICH version a client agreed to, which is the whole
 * point - a flag saying "accepted" goes on saying so after the Super Admin
 * rewrites the terms, and then there is no way left to tell the clients who
 * have read the new wording from the ones who have not.
 *
 * So the version is a fingerprint of the text (termsVersion), the acceptance
 * is that fingerprint plus a timestamp stored against the account, and the
 * requirement is enforced by middleware rather than by the dialog - because a
 * dialog is markup and markup can be skipped.
 *
 * The scope is deliberately narrow: clients. An employee is bound by their
 * employment rather than by a dialog, and locking the Super Admin out over a
 * document they themselves maintain would lock them away from the page they
 * would need to fix it.
 */
class TermsAcceptanceTest extends TestCase
{
    use RefreshDatabase;

    // ------------------------------------------------------------------
    // Fixtures
    // ------------------------------------------------------------------

    private function account(string $role, array $overrides = []): User
    {
        $sequence = User::count() + 1;

        return User::create(array_merge([
            'user_code' => strtoupper(substr($role, 0, 3)).'-'.str_pad((string) $sequence, 4, '0', STR_PAD_LEFT),
            'name' => 'Test Person '.$sequence,
            'first_name' => 'Test',
            'last_name' => 'Person '.$sequence,
            'email' => $role.$sequence.'@example.test',
            'role' => $role,
            'status' => User::STATUS_ACTIVE,
            'email_verified_at' => now(),
            'password' => 'correct-password',
        ], $overrides));
    }

    /**
     * A client who is up to date with whatever the terms currently say.
     */
    private function acceptedClient(array $overrides = []): User
    {
        $client = $this->account('client', $overrides);
        $client->acceptCurrentTerms();

        return $client->refresh();
    }

    /**
     * Rewrite the terms as the Super Admin's editor does, so the fingerprint
     * moves exactly the way it does in production.
     */
    private function rewriteTerms(string $text): void
    {
        $admin = User::where('role', User::ROLE_SUPER_ADMIN)->first()
            ?? $this->account('super_admin');

        app(SystemContentService::class)->saveText(
            SystemContent::SECTION_LEGAL,
            ['legal.terms_and_conditions' => $text],
            $admin
        );
    }

    /**
     * A project booked under this client's address, so My Projects has
     * something to refuse them.
     */
    private function projectFor(User $client): Project
    {
        $project = Project::create([
            'name' => 'Aircon Retrofit',
            'reference_no' => 'REF-TERMS-'.$client->id,
            'status' => 'ongoing',
            'address' => '1 Test Street',
            'description' => 'Description',
            'quotation' => 100000,
        ]);

        Client::create([
            'project_id' => $project->project_id,
            'user_id' => $client->id,
            'client_type' => 'Residential',
            'firstname' => $client->first_name,
            'surname' => $client->last_name,
            'fullname' => $client->fullName(),
            'email_address' => $client->email,
            'contact_number' => '09171234567',
        ]);

        return $project;
    }

    // ==================================================================
    // 1. What "the current version" is
    // ==================================================================

    /**
     * The version is derived from the words, so saving a rewrite is all it
     * takes for the current version to become a different one. Nobody has to
     * remember to increment anything.
     */
    public function test_rewriting_the_terms_changes_the_current_version(): void
    {
        $content = app(SystemContentService::class);
        $before = $content->termsVersion();

        $this->rewriteTerms('1. A new agreement'."\n\n".'Everything is different now.');

        $this->assertNotSame($before, $content->termsVersion());
    }

    /**
     * The other half, and the reason a hash beats a counter: saving the editor
     * without changing a word leaves the version alone, so nobody is asked to
     * agree to the same document twice.
     */
    public function test_saving_the_same_terms_again_leaves_the_version_alone(): void
    {
        $content = app(SystemContentService::class);
        $terms = $content->terms();
        $before = $content->termsVersion();

        $this->rewriteTerms($terms);

        $this->assertSame($before, $content->termsVersion());
    }

    /**
     * A textarea posts CRLF where the shipped default holds LF. The same
     * document submitted from a different browser is not a new version.
     */
    public function test_line_endings_alone_do_not_make_a_new_version(): void
    {
        $content = app(SystemContentService::class);

        $this->rewriteTerms("1. Payment\nWithin 30 days.");
        $unix = $content->termsVersion();

        $this->rewriteTerms("1. Payment\r\nWithin 30 days.\r\n");

        $this->assertSame($unix, $content->termsVersion());
    }

    // ==================================================================
    // 2. Who is asked, and who is not
    // ==================================================================

    public function test_a_client_who_has_never_accepted_anything_is_asked(): void
    {
        $client = $this->account('client');

        $this->assertTrue($client->requiresTermsAcceptance());
        $this->assertFalse($client->hasAcceptedCurrentTerms());
    }

    public function test_a_client_who_accepted_the_current_version_is_not_asked_again(): void
    {
        $client = $this->acceptedClient();

        $this->assertFalse($client->requiresTermsAcceptance());
        $this->assertNotNull($client->terms_accepted_at);
    }

    /**
     * The requirement in one test: agreeing to last month's wording is not
     * agreeing to this month's.
     */
    public function test_a_client_who_accepted_the_previous_version_is_asked_again(): void
    {
        $client = $this->acceptedClient();

        $this->rewriteTerms('1. A new agreement'."\n\n".'These terms have changed.');

        $this->assertTrue($client->refresh()->requiresTermsAcceptance());
        // And the system can tell this apart from a first request, which is
        // what lets the dialog say the terms have been UPDATED.
        $this->assertTrue($client->hasAcceptedEarlierTerms());
    }

    /**
     * Every role that is not a client carries on regardless. A rewrite of the
     * terms must never be able to shut the office out of the system - least of
     * all the Super Admin, who would then be locked away from the settings
     * page holding the document that did it.
     */
    public function test_no_employee_role_is_ever_asked_to_accept(): void
    {
        foreach (User::EMPLOYEE_ROLES as $role) {
            $this->assertFalse(
                $this->account($role)->requiresTermsAcceptance(),
                sprintf('%s should not be asked to accept the terms.', $role)
            );
        }
    }

    // ==================================================================
    // 3. Registration
    // ==================================================================

    /**
     * A client who has just registered has agreed - the form does not submit
     * without the box ticked - so they are not asked again the moment they
     * arrive.
     */
    public function test_self_registration_records_the_current_version(): void
    {
        $client = app(UserAccountService::class)->registerClient([
            'full_name' => 'New Client',
            'contact_number' => '09171234567',
            'birthdate' => '1990-01-01',
            'email' => 'new.client@example.test',
            'password' => 'a-good-password',
        ]);

        $this->assertSame(
            app(SystemContentService::class)->termsVersion(),
            $client->terms_accepted_version
        );
        $this->assertNotNull($client->terms_accepted_at);
        $this->assertFalse($client->requiresTermsAcceptance());
    }

    // ==================================================================
    // 4. The dialog
    // ==================================================================

    public function test_the_agreement_dialog_is_shown_to_a_client_who_is_behind(): void
    {
        $client = $this->acceptedClient();
        $this->rewriteTerms('1. A new agreement'."\n\n".'Please read this carefully.');

        $response = $this->actingAs($client)->get(route('landing.home'));

        $response->assertOk();
        $response->assertSee('data-terms-agreement', false);
        $response->assertSee('Our Terms and Conditions have been updated');
        $response->assertSee('Please read this carefully.');
        // The two choices, and nothing else: no close button and no backdrop
        // to click away.
        $response->assertSee('Agree');
        $response->assertSee('Log Out');
        $response->assertSee('data-bs-backdrop="static"', false);
    }

    public function test_the_agreement_dialog_is_absent_once_the_client_is_up_to_date(): void
    {
        $response = $this->actingAs($this->acceptedClient())->get(route('landing.home'));

        $response->assertOk();
        $response->assertDontSee('data-terms-agreement', false);
    }

    /**
     * An employee signed in on the public site is never shown it either - the
     * dialog follows the same rule the middleware does.
     */
    public function test_the_agreement_dialog_is_never_shown_to_an_employee(): void
    {
        $this->rewriteTerms('1. A new agreement'."\n\n".'Rewritten.');

        $response = $this->actingAs($this->account('admin'))->get(route('landing.home'));

        $response->assertOk();
        $response->assertDontSee('data-terms-agreement', false);
    }

    // ==================================================================
    // 5. Agreeing
    // ==================================================================

    public function test_agreeing_records_the_version_and_the_moment(): void
    {
        $client = $this->acceptedClient();
        $this->rewriteTerms('1. A new agreement'."\n\n".'Rewritten.');

        $this->actingAs($client)
            ->post(route('terms.accept'))
            ->assertRedirect(route('landing.home'));

        $client->refresh();

        $this->assertSame(
            app(SystemContentService::class)->termsVersion(),
            $client->terms_accepted_version
        );
        $this->assertNotNull($client->terms_accepted_at);
        $this->assertFalse($client->requiresTermsAcceptance());
    }

    public function test_agreeing_is_written_to_the_audit_trail(): void
    {
        $client = $this->account('client');

        $this->actingAs($client)->post(route('terms.accept'))->assertRedirect();

        $this->assertDatabaseHas('tbl_activity_logs', [
            'action' => ActivityLog::TERMS_ACCEPTED,
            'subject_id' => $client->id,
        ]);
    }

    /**
     * The acceptance belongs to the authenticated account and to nothing else.
     * Nothing in the request body names a user, and a body that tries to is
     * simply ignored - so one client can never accept on another's behalf.
     */
    public function test_a_client_cannot_accept_on_another_clients_behalf(): void
    {
        $mine = $this->account('client', ['email' => 'mine@example.test']);
        $theirs = $this->account('client', ['email' => 'theirs@example.test']);

        $this->actingAs($mine)->post(route('terms.accept'), [
            'user_id' => $theirs->id,
            'id' => $theirs->id,
        ])->assertRedirect();

        $this->assertNotNull($mine->refresh()->terms_accepted_version);
        $this->assertNull($theirs->refresh()->terms_accepted_version);
    }

    /**
     * The version is read from the settings, never from the request. A crafted
     * fingerprint cannot mark somebody up to date with a document they were
     * never shown.
     */
    public function test_a_posted_version_is_ignored(): void
    {
        $client = $this->account('client');

        $this->actingAs($client)->post(route('terms.accept'), [
            'version' => 'a-version-of-my-own-invention',
            'terms_accepted_version' => 'a-version-of-my-own-invention',
        ])->assertRedirect();

        $this->assertSame(
            app(SystemContentService::class)->termsVersion(),
            $client->refresh()->terms_accepted_version
        );
    }

    public function test_a_guest_cannot_accept_anything(): void
    {
        $this->post(route('terms.accept'))->assertRedirect(route('auth.login'));
    }

    // ==================================================================
    // 6. Declining
    // ==================================================================

    /**
     * Log Out is the other choice, and it records nothing - so the dialog is
     * waiting again next time.
     */
    public function test_logging_out_instead_of_agreeing_records_nothing(): void
    {
        $client = $this->acceptedClient();
        $accepted = $client->terms_accepted_version;

        $this->rewriteTerms('1. A new agreement'."\n\n".'Rewritten.');

        $this->actingAs($client)->post(route('auth.logout'))->assertRedirect(route('landing.home'));
        $this->assertGuest();

        // Still the OLD fingerprint: nothing was accepted.
        $this->assertSame($accepted, $client->refresh()->terms_accepted_version);
        $this->assertTrue($client->requiresTermsAcceptance());

        // And signing back in meets the dialog again.
        $this->actingAs($client)
            ->get(route('landing.home'))
            ->assertSee('data-terms-agreement', false);
    }

    // ==================================================================
    // 7. The requirement is enforced on the server
    // ==================================================================

    /**
     * The heart of it: typing a portal URL is not a way round the dialog.
     */
    public function test_a_client_who_is_behind_cannot_reach_their_portal_by_url(): void
    {
        $client = $this->acceptedClient();
        $project = $this->projectFor($client);

        $this->rewriteTerms('1. A new agreement'."\n\n".'Rewritten.');

        $this->actingAs($client);

        foreach ([
            route('public.projects'),
            route('public.projects.show', $project->project_id),
            route('profile.edit'),
            route('notifications.index'),
        ] as $url) {
            $this->get($url)->assertRedirect(route('landing.home'));
        }
    }

    /**
     * Writing is refused as firmly as reading. Signing a project off is the
     * one action a client has, and it is not available to somebody who has not
     * agreed to the terms it is taken under.
     */
    public function test_a_client_who_is_behind_cannot_sign_a_project_off(): void
    {
        $client = $this->acceptedClient();
        $project = $this->projectFor($client);

        $this->rewriteTerms('1. A new agreement'."\n\n".'Rewritten.');

        $this->actingAs($client)
            ->post(route('public.projects.confirm', $project->project_id))
            ->assertRedirect(route('landing.home'));
    }

    /**
     * A fetch is answered rather than redirected: a 302 to an HTML page
     * arriving where JSON was expected reads as a broken endpoint, not a rule.
     */
    public function test_a_json_request_from_a_held_client_is_refused_outright(): void
    {
        $client = $this->acceptedClient();
        $this->rewriteTerms('1. A new agreement'."\n\n".'Rewritten.');

        $this->actingAs($client)
            ->getJson(route('notifications.feed'))
            ->assertForbidden();
    }

    /**
     * Three things stay open while somebody is held, because a lock with no
     * way out is a lock on the person rather than on the portal: the public
     * website where the dialog lives, agreeing, and signing out.
     */
    public function test_the_public_website_agreeing_and_signing_out_stay_open(): void
    {
        $client = $this->acceptedClient();
        $this->rewriteTerms('1. A new agreement'."\n\n".'Rewritten.');

        $this->actingAs($client);

        $this->get(route('landing.home'))->assertOk();
        $this->get(route('public.about'))->assertOk();
        $this->get(route('public.contact'))->assertOk();
        $this->get(route('terms.show'))->assertOk();
        $this->post(route('terms.accept'))->assertRedirect(route('landing.home'));
    }

    /**
     * Nobody else is caught by it. An admin whose portal happens to be open
     * when the terms change carries on working.
     */
    public function test_the_requirement_does_not_reach_the_other_roles(): void
    {
        $this->rewriteTerms('1. A new agreement'."\n\n".'Rewritten.');

        $this->actingAs($this->account('super_admin'))
            ->get(route('super-admin.dashboard'))
            ->assertOk();

        $this->actingAs($this->account('admin'))
            ->get(route('super-admin.dashboard'))
            ->assertOk();

        $this->actingAs($this->account('lead_technician'))
            ->get(route('technician.schedule'))
            ->assertOk();

        $this->actingAs($this->account('technician'))
            ->get(route('technician.schedule'))
            ->assertOk();
    }

    /**
     * Once they agree, everything opens - and stays open across a fresh
     * session, because the acceptance is on the account rather than in it.
     */
    public function test_the_portal_opens_the_moment_the_client_agrees(): void
    {
        $client = $this->acceptedClient();
        $this->rewriteTerms('1. A new agreement'."\n\n".'Rewritten.');

        $this->actingAs($client)->post(route('terms.accept'));

        $this->actingAs($client->refresh())
            ->get(route('public.projects'))
            ->assertOk();

        // A new sign-in does not ask again.
        $this->actingAs($client->fresh())
            ->get(route('landing.home'))
            ->assertDontSee('data-terms-agreement', false);
    }

    // ==================================================================
    // 8. The footer
    // ==================================================================

    public function test_the_footer_offers_the_terms_to_anybody_including_a_guest(): void
    {
        $this->rewriteTerms('1. A new agreement'."\n\n".'Read me in the footer.');

        $response = $this->get(route('landing.home'));

        $response->assertOk();
        $response->assertSee('Terms and Conditions');
        // The reading dialog, opened from the footer button.
        $response->assertSee('data-bs-target="#termsModal"', false);
        $response->assertSee('id="termsModal"', false);
        $response->assertSee('Read me in the footer.');
    }

    /**
     * Reading is not agreeing. Nothing about opening the footer dialog - or
     * the endpoint behind the document - records an acceptance.
     */
    public function test_opening_the_terms_records_no_acceptance(): void
    {
        $client = $this->account('client');

        $this->actingAs($client)->get(route('landing.home'))->assertOk();
        $this->actingAs($client)->get(route('terms.show'))->assertOk();

        $this->assertNull($client->refresh()->terms_accepted_version);
        $this->assertTrue($client->requiresTermsAcceptance());
    }

    /**
     * The footer shows what the Super Admin last saved, not a copy in a Blade
     * file - the same field the registration form and the dialog read.
     */
    public function test_the_footer_dialog_shows_the_current_terms(): void
    {
        $this->rewriteTerms('1. The rewritten agreement'."\n\n".'Fees are payable in 30 days.');

        $this->get(route('landing.home'))
            ->assertSee('Fees are payable in 30 days.')
            ->assertDontSee('Please read these terms before opening');
    }

    /**
     * Typed markup is shown as the characters somebody typed. This is the one
     * document a visitor reads before agreeing to anything, and nothing in
     * that box may put markup on the page.
     */
    public function test_the_terms_are_shown_as_text_rather_than_as_markup(): void
    {
        $this->rewriteTerms('1. Fees <b>are payable</b> in 30 days.');

        $response = $this->get(route('landing.home'));

        $response->assertSee('1. Fees &lt;b&gt;are payable&lt;/b&gt; in 30 days.', false);
        $response->assertDontSee('<b>are payable</b>', false);
    }

    /**
     * The endpoint reports the current document and the fingerprint naming it,
     * so a caller never has to compare the displayed text to work out where it
     * stands.
     */
    public function test_the_terms_endpoint_reports_the_current_version(): void
    {
        $this->rewriteTerms('1. A new agreement'."\n\n".'Rewritten.');

        $client = $this->account('client');

        $this->actingAs($client)
            ->getJson(route('terms.show'))
            ->assertOk()
            ->assertJsonPath('version', app(SystemContentService::class)->termsVersion())
            ->assertJsonPath('acceptance_required', true)
            ->assertJsonPath('terms', app(SystemContentService::class)->terms());
    }
}
