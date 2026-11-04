<?php

namespace App\Http\Middleware;

use App\Models\Project;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A project can be read from the moment it exists and changed only once it has
 * been set up. The pages draw these actions disabled; this refuses them for
 * whoever sends the request anyway. A project unlocked by a Super Admin after
 * setup is still set up - see Project::hasCompletedInitialSetup().
 */
class EnsureProjectIsSetUp
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $project = $request->route('project') ?? $request->route('id');

        if (! $project instanceof Project) {
            $project = Project::query()->find($project);
        }

        if ($project?->awaitingInitialSetup()) {
            if ($request->expectsJson()) {
                return response()->json(['message' => Project::SET_UP_FIRST], Response::HTTP_CONFLICT);
            }

            return back()->with('error', Project::SET_UP_FIRST);
        }

        return $next($request);
    }
}
