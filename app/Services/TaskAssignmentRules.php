<?php

namespace App\Services;

use App\Models\Technician;
use Illuminate\Validation\Validator;

/**
 * Who a task may be given to.
 *
 * Being on the project's team is the first half of the question and every task
 * form has always asked it. This is the second half: the person has to be able
 * to receive the work. An account that has been deactivated or archived cannot
 * sign in, so it cannot open the project, cannot close the task, and cannot
 * read the notification saying it has one - handing it a job creates work that
 * nobody is going to do and nobody has been told about.
 *
 * A deactivated technician deliberately stays ON the team, because taking
 * somebody off a project releases their tasks and is a decision for a person
 * to make rather than a side effect of an account being switched off - see
 * ProjectTeamRules, which draws the same line for teams. So they go on being
 * listed everywhere the crew is listed. What changes is only that they can no
 * longer be handed anything new.
 *
 * The one exception is the task they are already holding. Editing its title or
 * its dates re-submits whoever owns it, and refusing that would make an
 * inactive technician's tasks uneditable - which is the opposite of what is
 * wanted, since moving that work to somebody else is exactly what a lead is
 * being asked to do. Keeping the current owner is therefore always allowed;
 * only a change TO an inactive technician is refused.
 *
 * Extracted for the same reason TaskScheduleRules was: the Super Admin board
 * and the technician portal both assign tasks, and a rule enforced by one and
 * not the other is the shape almost every bug in this area has taken.
 */
class TaskAssignmentRules
{
    /**
     * Whether this technician may be handed a task now.
     */
    public function canReceiveWork(?Technician $technician): bool
    {
        return $technician !== null && $technician->isAssignable();
    }

    /**
     * Why they cannot, in the words the administrator who switched the account
     * off would recognise.
     */
    public function refusal(Technician $technician): string
    {
        $account = $technician->account;

        $reason = match (true) {
            $account === null => 'has no account',
            (bool) $account->is_archived => 'has been archived',
            ! $account->isActive() => 'has been deactivated',
            default => 'can no longer be used',
        };

        return sprintf(
            "%s's account %s and cannot be given tasks.",
            $technician->name,
            $reason
        );
    }

    /**
     * Add the check to a task form's validator.
     *
     * Runs after the field's own rules, so a submission with no technician at
     * all - or one who is not on the project - is complained about once, by
     * the rule that actually owns that complaint.
     *
     * @param  int|null  $currentTechnicianId  who holds the task already, so an
     *                                         edit that leaves the owner alone is
     *                                         not refused for their account
     */
    public function attach(
        Validator $validator,
        ?int $currentTechnicianId = null,
        string $key = 'technician_id'
    ): void {
        $validator->after(function (Validator $validator) use ($currentTechnicianId, $key): void {
            if ($validator->errors()->has($key)) {
                return;
            }

            $technicianId = (int) ($validator->getData()[$key] ?? 0);

            if ($technicianId === 0 || $technicianId === $currentTechnicianId) {
                return;
            }

            $technician = Technician::query()
                ->with('account')
                ->find($technicianId);

            // A missing record is the `exists` rule's complaint to make, not
            // this one's - saying it twice only crowds the form.
            if ($technician === null || $this->canReceiveWork($technician)) {
                return;
            }

            $validator->errors()->add($key, $this->refusal($technician));
        });
    }
}
