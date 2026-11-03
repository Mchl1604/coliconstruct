<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Material;
use App\Models\MaterialRequest;
use App\Models\Project;
use App\Models\User;
use App\Support\BusinessTime;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * A lead asks for material; the inventory side decides.
 *
 * The only place a request is written. Approving goes through
 * ProjectMaterialLedger::add(), so it is held to what is available exactly as
 * adding it by hand is, and refused whole when the shelf cannot cover it - the
 * request simply stays pending until stock comes in.
 */
class MaterialRequests
{
    public function __construct(
        private readonly ProjectMaterialRules $rules,
        private readonly ProjectMaterialLedger $ledger,
        private readonly ActivityLogger $activityLogger,
        private readonly NotificationService $notifications,
    ) {}

    /**
     * @throws RuntimeException
     */
    public function request(Project $project, Material $material, float|int|string $quantity, User $by): MaterialRequest
    {
        if (! $material->is_active) {
            throw new RuntimeException(sprintf('%s is inactive.', $material->displayName()));
        }

        $problem = $material->unit->quantityProblem($quantity);

        if ($problem !== null) {
            throw new RuntimeException($problem);
        }

        $materialRequest = MaterialRequest::create([
            'project_id' => $project->project_id,
            'material_id' => $material->material_id,
            'quantity' => round((float) $quantity, 2),
            'status' => MaterialRequest::STATUS_PENDING,
            'requested_by' => $by->id,
        ]);

        $this->activityLogger->record(
            ActivityLog::MATERIAL_REQUESTED,
            null,
            sprintf('Requested %s of %s for %s.', $this->amount($materialRequest), $material->displayName(), $project->reference_no),
            $project
        );

        $this->notifications->materialRequested($materialRequest);

        return $materialRequest;
    }

    /**
     * Add what was asked for to the project, off the shelf.
     *
     * @throws RuntimeException
     */
    public function approve(MaterialRequest $materialRequest, User $by): void
    {
        $project = $materialRequest->project;

        if (! $this->rules->canManage($by, $project)) {
            throw new RuntimeException('This project takes no more materials.');
        }

        DB::transaction(function () use ($materialRequest, $project, $by): void {
            $locked = $this->lockPending($materialRequest);
            $entry = $this->ledger->add($project, $locked->material, $locked->quantity, BusinessTime::today(), null, $by);

            $locked->forceFill([
                'status' => MaterialRequest::STATUS_APPROVED,
                'decided_by' => $by->id,
                'decided_at' => now(),
                'project_material_entry_id' => $entry->material_entry_id,
            ])->save();

            $this->activityLogger->record(
                ActivityLog::MATERIAL_REQUEST_APPROVED,
                null,
                sprintf('Approved %s of %s for %s.', $this->amount($locked), $locked->material->displayName(), $project->reference_no),
                $project
            );
        });

        $this->notifications->materialRequestDecided($materialRequest->refresh());
    }

    /**
     * @throws RuntimeException
     */
    public function reject(MaterialRequest $materialRequest, string $reason, User $by): void
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw new RuntimeException('Say why.');
        }

        DB::transaction(function () use ($materialRequest, $reason, $by): void {
            $locked = $this->lockPending($materialRequest);

            $locked->forceFill([
                'status' => MaterialRequest::STATUS_REJECTED,
                'decided_by' => $by->id,
                'decided_at' => now(),
                'rejection_reason' => mb_substr($reason, 0, 255),
            ])->save();

            $this->activityLogger->record(
                ActivityLog::MATERIAL_REQUEST_REJECTED,
                null,
                sprintf('Rejected %s of %s for %s.', $this->amount($locked), $locked->material->displayName(), $locked->project?->reference_no),
                $locked->project
            );
        });

        $this->notifications->materialRequestDecided($materialRequest->refresh());
    }

    /**
     * Locked so two people deciding at once cannot both take it off the shelf.
     *
     * @throws RuntimeException
     */
    private function lockPending(MaterialRequest $materialRequest): MaterialRequest
    {
        $locked = MaterialRequest::query()
            ->with(['material', 'project'])
            ->whereKey($materialRequest->getKey())
            ->lockForUpdate()
            ->firstOrFail();

        if (! $locked->isPending()) {
            throw new RuntimeException('Already decided.');
        }

        return $locked;
    }

    private function amount(MaterialRequest $materialRequest): string
    {
        $unit = $materialRequest->material->unit;

        return $unit->format($materialRequest->quantity).' '.$unit->value;
    }
}
