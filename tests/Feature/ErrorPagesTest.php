<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\PortalErrorRedirect;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/**
 * The branded error pages in resources/views/errors, and the redirect that
 * keeps a signed-in reader off them.
 *
 * A signed-in reader whose page fails is sent back to their own portal with a
 * toast - see PortalErrorRedirect. The page is the last resort: guests,
 * fetches, maintenance, and a failure on the way out of another one. Each page
 * has to keep its real status code, say one plain sentence, offer a way out
 * the reader is actually allowed to take, and never print anything about the
 * fault itself.
 */
class ErrorPagesTest extends TestCase
{
    use RefreshDatabase;

    private function account(string $role, bool $acceptedTerms = true, string $status = User::STATUS_ACTIVE): User
    {
        return User::create([
            'user_code' => strtoupper(substr($role, 0, 3)).'-'.random_int(1000, 9999),
            'name' => ucfirst($role),
            'first_name' => ucfirst($role),
            'last_name' => 'Person',
            'email' => $role.random_int(1000, 9999).'@example.com',
            'role' => $role,
            'status' => $status,
            'is_archived' => false,
            'must_change_password' => false,
            'password' => 'password',
        ] + ($acceptedTerms ? $this->acceptedTerms() : []));
    }

    /**
     * A throwaway route inside the web group, so the error it raises is
     * rendered the way a real page's would be - with a session.
     */
    private function failingRoute(string $uri, callable $action, string $method = 'get'): void
    {
        Route::middleware('web')->{$method}($uri, $action);
    }

    public function test_an_unknown_address_is_a_real_404_for_a_guest(): void
    {
        $response = $this->get('/this-page-does-not-exist');

        $response->assertNotFound()
            ->assertSee('Page not found.')
            ->assertSee('Back to Home')
            ->assertSee(route('landing.home'), false)
            ->assertSee('Go Back')
            ->assertDontSee('Go to Dashboard');
    }

    public function test_a_missing_record_sends_a_signed_in_reader_home(): void
    {
        $client = $this->account(User::ROLE_CLIENT);

        $this->actingAs($client)
            ->get('/my-projects/999999')
            ->assertRedirect(route('landing.home'))
            ->assertSessionHas('error', 'That page could not be found.');
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function roles(): array
    {
        return [
            'super admin' => ['super_admin', 'Go to Dashboard', 'super-admin.dashboard'],
            'admin' => ['admin', 'Go to Dashboard', 'super-admin.dashboard'],
            'lead technician' => ['lead_technician', 'Go to My Schedule', 'technician.schedule'],
            'technician' => ['technician', 'Go to My Schedule', 'technician.schedule'],
            'client' => ['client', 'Back to Home', 'landing.home'],
        ];
    }

    #[DataProvider('roles')]
    public function test_a_signed_in_reader_is_sent_to_their_own_home(string $role, string $label, string $routeName): void
    {
        $this->actingAs($this->account($role))
            ->get('/no/such/page')
            ->assertRedirect(route($routeName))
            ->assertSessionHas('error', 'That page could not be found.');
    }

    /**
     * The page is still what the request after a redirect gets, so a portal
     * that fails itself cannot send its reader round in a loop.
     */
    #[DataProvider('roles')]
    public function test_a_failure_on_the_way_out_of_another_shows_the_page(string $role, string $label, string $routeName): void
    {
        $this->actingAs($this->account($role))
            ->withSession([PortalErrorRedirect::MARKER => true])
            ->get('/no/such/page')
            ->assertNotFound()
            ->assertSee('Page not found.')
            ->assertSee($label)
            ->assertSee(route($routeName), false);
    }

    public function test_a_technician_is_never_sent_to_the_admin_dashboard(): void
    {
        $this->actingAs($this->account('technician'))
            ->get('/no/such/page')
            ->assertRedirect(route('technician.schedule'));
    }

    public function test_a_client_held_by_the_terms_is_sent_to_a_page_the_terms_allow(): void
    {
        $this->actingAs($this->account(User::ROLE_CLIENT, acceptedTerms: false))
            ->get('/no/such/page')
            ->assertRedirect(route('landing.home'));
    }

    /**
     * A broken picture on a page stays a broken picture. Redirecting it would
     * leave a "could not be found" toast waiting on the reader's next page.
     */
    public function test_a_missing_picture_keeps_its_404_and_leaves_no_toast(): void
    {
        $this->actingAs($this->account('technician'))
            ->withHeader('Sec-Fetch-Dest', 'image')
            ->get('/no/such/picture.jpg')
            ->assertNotFound()
            ->assertSessionMissing('error');
    }

    public function test_a_signed_in_fetch_still_gets_its_error_rather_than_a_redirect(): void
    {
        $this->failingRoute('/_test/fetch-forbidden', fn () => abort(403));

        $this->actingAs($this->account('admin'))
            ->getJson('/_test/fetch-forbidden')
            ->assertForbidden();
    }

    public function test_the_404_page_does_not_let_a_deactivated_account_keep_its_session(): void
    {
        $user = $this->account('technician', status: User::STATUS_DEACTIVATED);

        $this->actingAs($user)
            ->get('/no/such/page')
            ->assertRedirect(route('auth.login'));

        $this->assertGuest();
    }

    public function test_an_unknown_api_address_still_gets_json(): void
    {
        $this->getJson('/api/no-such-endpoint')
            ->assertNotFound()
            ->assertJsonStructure(['message']);
    }

    public function test_forbidden_is_a_403_and_hides_the_reason(): void
    {
        $this->failingRoute('/_test/forbidden', fn () => abort(403, 'Only the owner of project 42 may see this.'));

        $this->get('/_test/forbidden')
            ->assertForbidden()
            ->assertSee('Access denied.')
            ->assertSee('You do not have permission to view this page.')
            ->assertSee('Back to Home')
            ->assertDontSee('project 42');
    }

    public function test_forbidden_sends_a_signed_in_reader_home_without_the_reason(): void
    {
        $this->failingRoute('/_test/forbidden', fn () => abort(403, 'Only the owner of project 42 may see this.'));

        $this->actingAs($this->account('technician'))
            ->get('/_test/forbidden')
            ->assertRedirect(route('technician.schedule'))
            ->assertSessionHas('error', 'You do not have access to that page.');
    }

    /**
     * A technician following a link into the admin portal never reaches an
     * error at all - the role check sends them home first.
     */
    public function test_a_technician_on_an_admin_page_is_sent_to_their_schedule(): void
    {
        $this->actingAs($this->account('technician'))
            ->get(route('super-admin.dashboard'))
            ->assertRedirect(route('technician.schedule'));
    }

    public function test_an_expired_session_is_a_419_that_offers_a_guest_sign_in(): void
    {
        $this->failingRoute('/_test/expired', fn () => throw new TokenMismatchException('CSRF token mismatch.'), 'post');

        $this->post('/_test/expired')
            ->assertStatus(419)
            ->assertSee('Session expired.')
            ->assertSee('Sign In')
            ->assertSee(route('auth.login'), false)
            ->assertDontSee('CSRF token mismatch');
    }

    public function test_an_expired_session_sends_a_signed_in_reader_back_to_the_form(): void
    {
        $this->failingRoute('/_test/expired', fn () => throw new TokenMismatchException, 'post');

        $this->actingAs($this->account('admin'))
            ->from(url('/_test/the-form'))
            ->post('/_test/expired', ['remarks' => 'Kept', 'password' => 'never-kept'])
            ->assertRedirect(url('/_test/the-form'))
            ->assertSessionHas('error', 'Your session expired. Please try again.')
            ->assertSessionHasInput('remarks', 'Kept')
            ->assertSessionMissing('_old_input.password');
    }

    public function test_a_failed_form_with_nowhere_to_go_back_to_goes_home(): void
    {
        $this->failingRoute('/_test/expired', fn () => throw new TokenMismatchException, 'post');

        $this->actingAs($this->account('admin'))
            ->withHeader('referer', 'https://elsewhere.example/form')
            ->post('/_test/expired')
            ->assertRedirect(route('super-admin.dashboard'));
    }

    public function test_too_many_requests_keeps_its_429(): void
    {
        $this->failingRoute('/_test/throttled', fn () => abort(429));

        $this->get('/_test/throttled')
            ->assertTooManyRequests()
            ->assertSee('Too many requests.');
    }

    public function test_a_server_error_is_a_500_with_nothing_internal_on_it(): void
    {
        Exceptions::fake();
        config(['app.debug' => false]);

        $this->failingRoute('/_test/broken', function () {
            throw new RuntimeException('SQLSTATE[42S02]: Base table users_secret not found in /var/www/app/Secret.php');
        });

        $response = $this->get('/_test/broken');

        $response->assertInternalServerError()
            ->assertSee('Something went wrong.')
            ->assertSee('Try Again')
            ->assertSee(url('/_test/broken'), false)
            ->assertSee('Back to Home')
            ->assertDontSee('SQLSTATE')
            ->assertDontSee('users_secret')
            ->assertDontSee('/var/www')
            ->assertDontSee('Secret.php')
            ->assertDontSee('RuntimeException');

        // The detail still reaches the log for whoever has to fix it.
        Exceptions::assertReported(RuntimeException::class);
    }

    public function test_a_server_error_sends_a_signed_in_reader_home_and_is_still_logged(): void
    {
        Exceptions::fake();
        config(['app.debug' => false]);

        $this->failingRoute('/_test/broken', function () {
            throw new RuntimeException('SQLSTATE[42S02]: Base table users_secret not found');
        });

        $this->actingAs($this->account('super_admin'))
            ->get('/_test/broken')
            ->assertRedirect(route('super-admin.dashboard'))
            ->assertSessionHas('error', 'Something went wrong. Please try again.');

        Exceptions::assertReported(RuntimeException::class);
    }

    /**
     * A developer running with debug on needs the stack trace, not a toast.
     */
    public function test_a_server_error_in_debug_mode_is_not_redirected(): void
    {
        Exceptions::fake();
        config(['app.debug' => true]);

        $this->failingRoute('/_test/broken', fn () => throw new RuntimeException('boom'));

        $this->actingAs($this->account('super_admin'))
            ->get('/_test/broken')
            ->assertInternalServerError();
    }

    public function test_a_failed_post_is_not_offered_a_resubmitting_try_again(): void
    {
        Exceptions::fake();
        config(['app.debug' => false]);

        $this->failingRoute('/_test/broken-post', fn () => throw new RuntimeException('boom'), 'post');

        $this->post('/_test/broken-post')
            ->assertInternalServerError()
            ->assertSee('Something went wrong.')
            ->assertDontSee('Try Again')
            ->assertSee('Back to Home');
    }

    public function test_maintenance_mode_is_a_503_with_only_try_again(): void
    {
        $this->app->maintenanceMode()->activate([]);

        try {
            $this->get('/')
                ->assertServiceUnavailable()
                ->assertSee('Service unavailable.')
                ->assertSee('Try Again')
                ->assertDontSee('Back to Home');
        } finally {
            $this->app->maintenanceMode()->deactivate();
        }
    }

    public function test_other_client_errors_use_the_branded_catch_all(): void
    {
        $this->failingRoute('/_test/gone', fn () => abort(410, 'Removed by migration 2024_01_01'));

        $this->get('/_test/gone')
            ->assertStatus(410)
            ->assertSee('410')
            ->assertSee('This request could not be completed.')
            ->assertDontSee('migration');
    }

    public function test_other_server_errors_use_the_branded_catch_all(): void
    {
        $this->failingRoute('/_test/bad-gateway', fn () => abort(502, 'Upstream 10.0.0.4 refused'));

        $this->get('/_test/bad-gateway')
            ->assertStatus(502)
            ->assertSee('502')
            ->assertSee('Something went wrong.')
            ->assertDontSee('10.0.0.4');
    }
}
