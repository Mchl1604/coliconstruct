<?php

namespace Tests;

use App\Models\User;
use App\Services\SystemContentService;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * The Terms and Conditions columns a client fixture needs.
     *
     * A client who has not agreed to the current terms is held outside their
     * portal by EnsureTermsAreAccepted, which is the point of that middleware
     * - but it means a fixture client built for a test about something else
     * would be stopped at the door, and the test would then prove nothing
     * about what it was actually asking. In production every client using the
     * portal has agreed, so this is what a realistic fixture looks like.
     *
     * A test that wants the OTHER case - a client who is behind - simply
     * leaves these off. TermsAcceptanceTest is built that way throughout.
     *
     * @return array<string, mixed>
     */
    protected function acceptedTerms(): array
    {
        return [
            'terms_accepted_version' => app(SystemContentService::class)->termsVersion(),
            'terms_accepted_at' => now(),
        ];
    }

    /**
     * Sign in as a super administrator.
     *
     * Every administrative route now sits behind `auth` and a role check, so
     * a test exercising one has to say who is asking. This is the caller for
     * all of them; who may reach what is covered separately, by the
     * authentication tests.
     */
    protected function actingAsSuperAdmin(): User
    {
        $admin = User::create([
            // The first code in the sequence, so generated codes carry on
            // from it normally instead of jumping to fill a gap.
            'user_code' => 'EMP-0001',
            'name' => 'Test Super Admin',
            'first_name' => 'Test',
            'last_name' => 'Administrator',
            'email' => 'super.admin@coliconstruct.test',
            'role' => 'super_admin',
            'status' => User::STATUS_ACTIVE,
            'password' => 'test-password',
        ]);

        $this->actingAs($admin);

        return $admin;
    }
}
