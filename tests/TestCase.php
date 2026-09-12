<?php

namespace Tests;

use App\Models\Project;
use App\Models\ProjectPhase;
use App\Models\User;
use App\Services\SystemContentService;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Collection;

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
     * Give a project the finalized phase structure a live project has.
     *
     * Projects are created `pending` phase setup, and a project in that state
     * deliberately accepts no tasks - so a fixture built for a test about
     * something else would be refused at a gate the test is not asking about.
     * In production every project that has work booked against it has been
     * through setup, so this is what a realistic fixture looks like.
     *
     * A test that wants the OTHER case - a project still awaiting setup -
     * simply does not call this. ProjectPhaseSetupTest is built that way
     * throughout.
     *
     * @return Collection<int, ProjectPhase> The phases, in sequence order.
     */
    protected function finalizePhases(Project $project, int $count = 2): Collection
    {
        $phases = collect(range(1, $count))->map(fn (int $sequence): ProjectPhase => ProjectPhase::create([
            'project_id' => $project->project_id,
            'sequence' => $sequence,
            'title' => 'Phase '.$sequence,
            'description' => 'Phase '.$sequence.' of the work.',
        ]));

        $project->forceFill([
            'phase_setup_status' => Project::PHASE_SETUP_FINALIZED,
            'phase_count' => $count,
            'phase_setup_finalized_at' => now(),
        ])->save();

        return $phases;
    }

    /**
     * Tick off every phase the project has, the way a lead working down the
     * panel would have.
     *
     * A lead technician cannot close a project with a phase still open - see
     * ProjectPolicy::blockerDetailsFor() - so a fixture built for a test about
     * something else on the far side of that gate has to have been through it.
     * Written straight onto the rows rather than through
     * ProjectPhaseProgress::complete(), because what those tests need is the
     * state, not the audit trail of reaching it.
     *
     * A test that wants the OTHER case - a phase still open, or no structure
     * at all - simply does not call this. ProjectCompletionRulesTest is built
     * that way throughout.
     */
    protected function completePhases(Project $project): void
    {
        $project->phases()->whereNull('completed_at')->update([
            'completed_at' => now(),
            'updated_at' => now(),
        ]);

        $project->unsetRelation('phases');
    }

    /**
     * The phase a task fixture should be filed under: the first of the
     * project's, finalizing a structure for it if it has none yet.
     */
    protected function defaultPhaseId(Project $project): int
    {
        $phase = $project->phases()->orderBy('sequence')->first()
            ?? $this->finalizePhases($project)->first();

        return (int) $phase->phase_id;
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
