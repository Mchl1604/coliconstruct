<?php

namespace App\Exceptions;

use App\Models\Project;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * A role change refused because of the live projects it would decide.
 *
 * The refusal carries the projects, not just a sentence about them. Whoever
 * reads it has to go and fix a team by hand - that is the whole reason the
 * change was stopped - so the Configuration page lists the projects and links
 * straight to the team on each one. See TechnicianRoleChangeRules for why a
 * role change is never allowed to pick a lead by itself.
 */
class RoleChangeBlocked extends RuntimeException
{
    /**
     * @param  string  $headline  what happened, in one short sentence
     * @param  string  $action  what to do about it, in one more
     * @param  Collection<int, Project>  $projects  the projects in the way
     */
    public function __construct(
        private readonly string $headline,
        private readonly string $action,
        private readonly Collection $projects
    ) {
        // The two sentences also stand alone as plain text, for any caller
        // that has nowhere to put the list.
        parent::__construct($headline.' '.$action);
    }

    /**
     * The refusal as the Configuration page renders it.
     *
     * @return array{message: string, action: string, projects: array<int, array{name: string, url: string}>}
     */
    public function payload(): array
    {
        return [
            'message' => $this->headline,
            'action' => $this->action,
            'projects' => $this->projects
                ->map(fn (Project $project): array => [
                    'name' => (string) ($project->name ?: $project->reference_no),
                    // Straight to the team that has to change, rather than to
                    // the top of a long project page.
                    'url' => route('super-admin.projects.show', $project->project_id).'#assigned-team',
                ])
                ->values()
                ->all(),
        ];
    }
}
