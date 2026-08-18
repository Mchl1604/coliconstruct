<?php

namespace App\Services;

use App\Models\Technician;
use Illuminate\Support\Collection;
use Illuminate\Validation\Validator;

/**
 * What a project's team is allowed to be.
 *
 * Three rules, and every screen that builds a team has to apply all three:
 *
 *   - The lead is a Lead Technician. `lead_tech` names a rank, not a slot, and
 *     a project whose "lead" is a plain technician has no lead at all: the
 *     client is shown nobody, and every lead-only action on it - the task
 *     board, the reports, Complete Project - becomes impossible, because those
 *     are gated on the account's role rather than on which id was submitted
 *     here.
 *
 *   - There is only one of them. A project's lead is the member whose account
 *     role says so, so a second lead-role technician on the team is a second
 *     lead, and which of the two is "the" lead comes down to row order.
 *
 *   - Nobody is given work they cannot receive. An account that has been
 *     deactivated or archived cannot sign in, so it cannot open the project,
 *     cannot close a task and cannot read the notification saying it was
 *     assigned one.
 *
 * The last rule applies to people being ADDED. Somebody already on the team
 * stays there when their account is switched off, because taking work off a
 * technician is a decision with consequences - their tasks are unassigned and
 * somebody has to pick them up - and it belongs to a person, not to a side
 * effect. The team editor can therefore still save a project whose crew
 * includes an account that has since been disabled; it simply cannot add
 * another.
 *
 * Extracted for the same reason TechnicianAvailabilityService and
 * ScheduleModeRules were: the wizard and the assigned-team editor both build
 * teams, and a rule enforced by one and not the other is the shape almost
 * every bug in this area has taken.
 */
class ProjectTeamRules
{
    /**
     * Check a submitted team and report what is wrong with it.
     *
     * Errors are added against the field the person is looking at - the lead
     * select or the technician picker - rather than gathered into one message
     * about both.
     *
     * @param  array<int, mixed>  $technicianIds  the supporting technicians
     * @param  array<int, int>  $alreadyAssignedIds  who is on the team now, so
     *                                               an existing member is not
     *                                               refused for a change to
     *                                               their account
     * @param  string  $leadKey  the field name the lead arrived under
     * @param  string  $techniciansKey  the field name the rest arrived under
     */
    public function validate(
        Validator $validator,
        mixed $leadId,
        array $technicianIds,
        array $alreadyAssignedIds = [],
        string $leadKey = 'lead_tech',
        string $techniciansKey = 'technicians'
    ): void {
        $leadId = $leadId === null ? null : (int) $leadId;

        $supporting = collect($technicianIds)
            ->map(fn ($technicianId): int => (int) $technicianId)
            ->filter()
            ->unique()
            ->reject(fn (int $technicianId): bool => $technicianId === $leadId)
            ->values();

        // merge() rather than push(): push mutates, which would put the lead
        // into $supporting and have checkSupporting() report the project's own
        // lead as a second one.
        $technicians = $this->lookup(
            $leadId === null ? $supporting : $supporting->merge([$leadId])
        );

        $this->checkLead($validator, $leadId, $technicians, $alreadyAssignedIds, $leadKey);
        $this->checkSupporting($validator, $supporting, $technicians, $alreadyAssignedIds, $techniciansKey);
    }

    /**
     * The lead has to be a Lead Technician, and one who can still be given
     * work unless they were already carrying this project.
     *
     * @param  Collection<int, Technician>  $technicians
     * @param  array<int, int>  $alreadyAssignedIds
     */
    private function checkLead(
        Validator $validator,
        ?int $leadId,
        Collection $technicians,
        array $alreadyAssignedIds,
        string $leadKey
    ): void {
        if ($leadId === null) {
            return;
        }

        $lead = $technicians->get($leadId);

        // A missing record is the `exists` rule's complaint to make, not this
        // one's - saying it twice only crowds the form.
        if (! $lead) {
            return;
        }

        if (! $lead->isLead()) {
            $validator->errors()->add(
                $leadKey,
                sprintf(
                    '%s is not a Lead Technician, so they cannot lead this project. Choose a Lead Technician.',
                    $lead->name
                )
            );

            return;
        }

        if (! $lead->isAssignable() && ! in_array($leadId, $alreadyAssignedIds, true)) {
            $validator->errors()->add($leadKey, $this->unavailableMessage($lead));
        }
    }

    /**
     * The rest of the crew: no second lead, and nobody who cannot be given
     * the work.
     *
     * @param  Collection<int, int>  $supporting
     * @param  Collection<int, Technician>  $technicians
     * @param  array<int, int>  $alreadyAssignedIds
     */
    private function checkSupporting(
        Validator $validator,
        Collection $supporting,
        Collection $technicians,
        array $alreadyAssignedIds,
        string $techniciansKey
    ): void {
        foreach ($supporting as $technicianId) {
            $technician = $technicians->get($technicianId);

            if (! $technician) {
                continue;
            }

            if ($technician->isLead()) {
                $validator->errors()->add(
                    $techniciansKey,
                    sprintf(
                        '%s is a Lead Technician, and a project can only have one lead. '
                            .'Make them the lead, or choose somebody else.',
                        $technician->name
                    )
                );

                continue;
            }

            if (! $technician->isAssignable() && ! in_array($technicianId, $alreadyAssignedIds, true)) {
                $validator->errors()->add($techniciansKey, $this->unavailableMessage($technician));
            }
        }
    }

    /**
     * Why somebody cannot be given work, in the words the administrator who
     * switched the account off would recognise.
     */
    private function unavailableMessage(Technician $technician): string
    {
        $account = $technician->account;

        $reason = match (true) {
            $account === null => 'has no account',
            (bool) $account->is_archived => 'has been archived',
            ! $account->isActive() => 'has been deactivated',
            default => 'can no longer be given work',
        };

        return sprintf(
            "%s's account %s, so they cannot be assigned to a project.",
            $technician->name,
            $reason
        );
    }

    /**
     * Every technician named in the submission, in one query, keyed by id.
     *
     * @param  Collection<int, int>  $technicianIds
     * @return Collection<int, Technician>
     */
    private function lookup(Collection $technicianIds): Collection
    {
        $technicianIds = $technicianIds->unique()->values();

        if ($technicianIds->isEmpty()) {
            return collect();
        }

        return Technician::query()
            ->with('account')
            ->whereIn('technician_id', $technicianIds->all())
            ->get()
            ->keyBy(fn (Technician $technician): int => (int) $technician->technician_id);
    }
}
