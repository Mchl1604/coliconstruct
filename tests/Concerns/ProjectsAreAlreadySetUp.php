<?php

namespace Tests\Concerns;

use App\Models\Project;

/**
 * For tests about what happens to a project after setup, whose fixtures are not
 * about setup itself: every project they create counts as having been through
 * it once, so EnsureProjectIsSetUp lets their changes through. Its phases stay
 * as the test left them - see Project::hasCompletedInitialSetup().
 */
trait ProjectsAreAlreadySetUp
{
    protected function setUpProjectsAreAlreadySetUp(): void
    {
        // Not the wizard's own: a project it creates really is new, and only
        // a new one takes the wizard's materials.
        Project::creating(function (Project $project): void {
            if (! request()->routeIs('super-admin.projects.create.store')) {
                $project->phase_setup_finalized_at ??= now();
            }
        });
    }
}
