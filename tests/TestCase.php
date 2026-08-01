<?php

namespace Tests;

use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
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
