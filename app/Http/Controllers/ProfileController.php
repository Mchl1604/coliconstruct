<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;

/**
 * The signed-in account's own details.
 *
 * Read only for now: this exists so the Profile entry in the header goes
 * somewhere real rather than nowhere. Editing an account still happens in
 * Configuration, where an administrator does it.
 */
class ProfileController extends Controller
{
    public function edit(Request $request)
    {
        $user = $request->user();

        // Each role reads this inside the shell it already lives in; a client
        // has no portal of their own, so theirs is the public website.
        $layout = match (true) {
            in_array($user->role, User::ADMINISTRATOR_ROLES, true) => 'layouts.superadminNav',
            $user->isClient() => 'layouts.publicSite',
            default => 'layouts.portalNav',
        };

        return view('profile.edit', [
            'account' => $user,
            'layout' => $layout,
        ]);
    }
}
