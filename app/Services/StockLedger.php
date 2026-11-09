<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Material;
use App\Models\Project;
use App\Models\ProjectMaterialEntry;
use App\Models\StockMovement;
use App\Models\User;
use App\Support\BusinessTime;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The company's stock: what came in, what went out, and counts set right.
 *
 *     On Hand = the sum of every movement that is not voided
 *
 * A project's plan is a real Stock Out, marked as the project's assignment -
 * see syncAssignments(). Older plans wrote uncounted reservation rows; those
 * stay on the history and never change the count.
 *
 * The only place a stock movement is written. Nothing here keeps a running
 * total - every figure is summed from the movements, the way
 * ProjectMaterialLedger sums a project's entries - and nothing is edited: a
 * wrong movement is voided and stays on the history.
 *
 * Stock never goes below zero. Issuing more than is on hand, counting a
 * negative amount, or voiding a delivery that has since been issued are all
 * refused, so On Hand always describes something that could be on a shelf.
 *
 * Quantities are compared in hundredths - integers - because the column holds
 * two decimals and floating-point sums do not come out even.
 */
class StockLedger
{
    public function __construct(
        private readonly ActivityLogger $activityLogger,
        private readonly NotificationService $notifications,
        private readonly StockAvailability $availability,
    ) {}

    public function onHand(Material $material): float
    {
        return $this->onHandHundredths($material) / 100;
    }

    /**
     * Stock received: delivered by a supplier, or brought back from a site.
     *
     * @param  array{reference_no?: string|null, supplier?: string|null, project_id?: int|null, notes?: string|null}  $details
     *
     * @throws RuntimeException
     */
    public function receive(Material $material, string $type, float|int|string $quantity, CarbonInterface|string $date, array $details, User $by): StockMovement
    {
        if (! in_array($type, StockMovement::INCOMING_TYPES, true)) {
            throw new RuntimeException('Choose Delivery or Return.');
        }

        $this->assertQuantity($material, $quantity);

        $project = $this->project($details['project_id'] ?? null);

        return DB::transaction(function () use ($material, $type, $quantity, $date, $details, $project, $by): StockMovement {
            $this->lock($material);

            $row = [
                'reference_no' => $details['reference_no'] ?? null,
                // A supplier delivers; a return comes back from a project.
                'supplier' => $type === StockMovement::TYPE_STOCK_IN ? ($details['supplier'] ?? null) : null,
                'project_id' => $type === StockMovement::TYPE_RETURNED ? $project?->project_id : null,
                'notes' => $details['notes'] ?? null,
            ];

            $movement = $this->write($material, $type, $this->hundredths($quantity), $date, $row, $by);

            $this->activityLogger->record(
                $type === StockMovement::TYPE_STOCK_IN ? ActivityLog::STOCK_RECEIVED : ActivityLog::STOCK_RETURNED,
                null,
                $type === StockMovement::TYPE_STOCK_IN
                    ? sprintf('Received %s of %s.', $this->amount($material, $quantity), $material->displayName())
                    : sprintf(
                        'Returned %s of %s to stock%s.',
                        $this->amount($material, $quantity),
                        $material->displayName(),
                        $project ? ' from '.$project->reference_no : ''
                    ),
                $project ?? $material
            );

            return $movement;
        });
    }

    /**
     * Stock out: issued to a project, used up, damaged or lost.
     *
     * Refused past what is on hand. The fix for "we needed more" is to
     * receive it first, so the record never says the shelf holds a negative
     * amount.
     *
     * @param  array{reference_no?: string|null, project_id?: int|null, notes?: string|null}  $details
     *
     * @throws RuntimeException
     */
    public function issue(Material $material, float|int|string $quantity, CarbonInterface|string $date, array $details, User $by): StockMovement
    {
        $this->assertQuantity($material, $quantity);

        $project = $this->project($details['project_id'] ?? null);

        return DB::transaction(function () use ($material, $quantity, $date, $details, $project, $by): StockMovement {
            // Locked so two people issuing the last few at once cannot both
            // be told they are there.
            $this->lock($material);

            $before = $this->onHandHundredths($material);
            $availableBefore = $this->availableHundredths($material);
            $wanted = $this->hundredths($quantity);

            if ($wanted > $before) {
                throw new RuntimeException($before <= 0
                    ? sprintf('No %s on hand.', $material->displayName())
                    : sprintf('Only %s on hand.', $this->amount($material, $before / 100)));
            }

            $movement = $this->write($material, StockMovement::TYPE_STOCK_OUT, -$wanted, $date, [
                'reference_no' => $details['reference_no'] ?? null,
                'project_id' => $project?->project_id,
                'notes' => $details['notes'] ?? null,
            ], $by);

            $this->activityLogger->record(
                ActivityLog::STOCK_ISSUED,
                null,
                sprintf(
                    'Issued %s of %s%s.',
                    $this->amount($material, $quantity),
                    $material->displayName(),
                    $project ? ' to '.$project->reference_no : ''
                ),
                $project ?? $material
            );

            $this->alertIfRunningLow($material, $availableBefore, $this->availableHundredths($material));

            return $movement;
        });
    }

    /**
     * Several materials received as one delivery or return - all of them or
     * none of them.
     *
     * @param  array<int, array{material: Material, quantity: float|int|string}>  $lines
     * @param  array{project_id?: int|null, notes?: string|null}  $details
     *
     * @throws RuntimeException
     */
    public function receiveMany(array $lines, string $type, CarbonInterface|string $date, array $details, User $by): void
    {
        $this->assertLineQuantities($lines);

        DB::transaction(function () use ($lines, $type, $date, $details, $by): void {
            foreach ($this->inLockOrder($lines) as $line) {
                $this->receive($line['material'], $type, $line['quantity'], $date, $details, $by);
            }
        });
    }

    /**
     * Several materials issued together - all of them or none of them.
     *
     * Every line is checked before anything is written, so a refused line
     * sends no low stock notice for the lines ahead of it.
     *
     * @param  array<int, array{material: Material, quantity: float|int|string}>  $lines
     * @param  array{project_id?: int|null, notes?: string|null}  $details
     *
     * @throws RuntimeException
     */
    public function issueMany(array $lines, CarbonInterface|string $date, array $details, User $by): void
    {
        $this->assertLineQuantities($lines);

        foreach ($lines as $line) {
            $before = $this->onHandHundredths($line['material']);

            if ($this->hundredths($line['quantity']) > $before) {
                throw new RuntimeException($before <= 0
                    ? sprintf('No %s on hand.', $line['material']->displayName())
                    : sprintf('Only %s of %s on hand.', $this->amount($line['material'], $before / 100), $line['material']->displayName()));
            }
        }

        DB::transaction(function () use ($lines, $date, $details, $by): void {
            foreach ($this->inLockOrder($lines) as $line) {
                $this->issue($line['material'], $line['quantity'], $date, $details, $by);
            }
        });
    }

    /**
     * Set the record to what a count actually found. The difference is
     * written as one adjustment, up or down, with the reason it changed.
     *
     * @throws RuntimeException
     */
    public function adjust(Material $material, float|int|string $counted, CarbonInterface|string $date, string $reason, User $by): StockMovement
    {
        if (! is_numeric($counted) || (float) $counted < 0) {
            throw new RuntimeException('Enter a count of zero or more.');
        }

        if ((float) $counted > 0) {
            $this->assertQuantity($material, $counted);
        }

        $reason = trim($reason);

        if ($reason === '') {
            throw new RuntimeException('Say why the count changed.');
        }

        return DB::transaction(function () use ($material, $counted, $date, $reason, $by): StockMovement {
            $this->lock($material);

            $before = $this->onHandHundredths($material);
            $availableBefore = $this->availableHundredths($material);
            $after = $this->hundredths($counted);
            $change = $after - $before;

            if ($change === 0) {
                throw new RuntimeException('The count matches the record.');
            }

            $movement = $this->write($material, StockMovement::TYPE_ADJUSTMENT, $change, $date, [
                'notes' => $reason,
            ], $by);

            $this->activityLogger->record(
                ActivityLog::STOCK_ADJUSTED,
                null,
                sprintf(
                    'Adjusted %s from %s to %s.',
                    $material->displayName(),
                    $this->amount($material, $before / 100),
                    $this->amount($material, $after / 100)
                ),
                $material
            );

            $this->alertIfRunningLow($material, $availableBefore, $this->availableHundredths($material));

            return $movement;
        });
    }

    /**
     * Strike a movement out. It stays on the history, marked voided, and
     * stops counting toward On Hand.
     *
     * Voiding a delivery that has since been issued would take the shelf
     * below zero, so that is refused until the later movements are voided
     * first.
     *
     * @throws RuntimeException
     */
    public function void(StockMovement $movement, User $by): void
    {
        if ($movement->isVoided()) {
            throw new RuntimeException('Already voided.');
        }

        if ($movement->is_reservation || $movement->is_assignment) {
            throw new RuntimeException('Assigned to the project. Change it there.');
        }

        DB::transaction(function () use ($movement, $by): void {
            $material = $this->lock($movement->material);

            $before = $this->onHandHundredths($material);
            $availableBefore = $this->availableHundredths($material);
            $after = $before - $this->hundredths($movement->quantity);

            if ($after < 0) {
                throw new RuntimeException('Already issued. Void later entries first.');
            }

            $movement->forceFill([
                'voided_at' => now(),
                'voided_by' => $by->id,
            ])->save();

            $this->activityLogger->record(
                ActivityLog::STOCK_MOVEMENT_VOIDED,
                null,
                sprintf(
                    'Voided %s of %s %s.',
                    strtolower($movement->typeLabel()),
                    $this->amount($material, abs((float) $movement->quantity)),
                    $material->displayName()
                ),
                $movement->project ?? $material
            );

            $this->alertIfRunningLow($material, $availableBefore, $this->availableHundredths($material));
        });
    }

    // ------------------------------------------------------------------
    // A project's Materials tab
    //
    // What a lead records on site moves the warehouse too: more brought in
    // comes off the shelf, a return goes back on it. Each movement points at
    // the entry that caused it, so voiding the entry voids the movement.
    // ------------------------------------------------------------------

    /**
     * Take an Added entry's quantity out of stock.
     *
     * Refused past what is on the shelf - the entry is refused with it, so
     * the site and the shelf never disagree. Material bought straight from a supplier is recorded
     * without this - see ProjectMaterialLedger::add().
     *
     * @throws RuntimeException
     */
    public function issueForEntry(ProjectMaterialEntry $entry, Material $material, User $by): StockMovement
    {
        $project = $entry->project;

        return DB::transaction(function () use ($entry, $material, $project, $by): StockMovement {
            $this->lock($material);

            $wanted = $this->hundredths($entry->quantity);
            $available = $this->availableHundredths($material);

            if ($wanted > $available) {
                throw new RuntimeException($available <= 0
                    ? 'None available. Ask Purchasing.'
                    : sprintf('Only %s available. Ask Purchasing.', $this->amount($material, $available / 100)));
            }

            $movement = $this->write($material, StockMovement::TYPE_STOCK_OUT, -$wanted, $entry->entry_date, [
                'project_id' => $project?->project_id,
                'project_material_entry_id' => $entry->material_entry_id,
                'notes' => 'Added on site',
            ], $by);

            $this->activityLogger->record(
                ActivityLog::STOCK_ISSUED,
                null,
                sprintf(
                    'Issued %s of %s to %s from its Materials tab.',
                    $this->amount($material, $entry->quantity),
                    $material->displayName(),
                    $project?->reference_no
                ),
                $project ?? $material
            );

            $this->alertIfRunningLow($material, $available, $this->availableHundredths($material));

            return $movement;
        });
    }

    /**
     * A Returned entry from the project's Materials tab: it goes back on the
     * shelf, since everything assigned to the project already left it.
     */
    public function returnFromSite(ProjectMaterialEntry $entry, Material $material, float|int|string $quantity, User $by): void
    {
        $this->returnForEntry($entry, $material, $quantity, $by);
    }

    /**
     * Put stock back on the shelf for a Returned entry, or for leftovers at
     * close-out - which have nobody to record them, so $by may be null.
     */
    public function returnForEntry(ProjectMaterialEntry $entry, Material $material, float|int|string $quantity, ?User $by): StockMovement
    {
        $project = $entry->project;

        return DB::transaction(function () use ($entry, $material, $quantity, $project, $by): StockMovement {
            $this->lock($material);

            $movement = $this->write($material, StockMovement::TYPE_RETURNED, $this->hundredths($quantity), $entry->entry_date, [
                'project_id' => $project?->project_id,
                'project_material_entry_id' => $entry->material_entry_id,
                'notes' => $by === null ? 'Left over at close-out' : 'Returned from site',
            ], $by);

            $this->activityLogger->record(
                ActivityLog::STOCK_RETURNED,
                null,
                sprintf(
                    'Returned %s of %s to stock from %s.',
                    $this->amount($material, $quantity),
                    $material->displayName(),
                    $project?->reference_no
                ),
                $project ?? $material
            );

            return $movement;
        });
    }

    /**
     * Void the movement an entry caused, if it caused one that still counts.
     *
     * @throws RuntimeException when the warehouse has since issued what the
     *                          movement brought in
     */
    public function voidForEntry(ProjectMaterialEntry $entry, User $by): void
    {
        $movements = StockMovement::query()
            ->notVoided()
            ->where('project_material_entry_id', $entry->material_entry_id)
            ->get();

        foreach ($movements as $movement) {
            if ($movement->is_reservation) {
                // Never counted, so there is no shelf to check.
                $movement->forceFill(['voided_at' => now(), 'voided_by' => $by->id])->save();
            } else {
                $this->void($movement, $by);
            }
        }
    }

    /**
     * Take a new project's plan out of stock: one Stock Out per line, marked
     * as the project's assignment. A line that changed or left has its row
     * voided first, so saving the same plan twice writes nothing. Capped at
     * what is on hand - the wizard already holds the plan to Available.
     */
    public function syncAssignments(Project $project, ?User $by): void
    {
        DB::transaction(function () use ($project, $by): void {
            $plan = $project->materials()->with('material')->get()->keyBy('material_id');
            $current = StockMovement::query()
                ->notVoided()
                ->where('is_assignment', true)
                ->whereNull('project_material_entry_id')
                ->where('project_id', $project->project_id)
                ->get()
                ->keyBy('material_id');

            foreach ($current as $materialId => $movement) {
                $line = $plan->get($materialId);

                if ($line === null || -$this->hundredths($movement->quantity) !== $this->hundredths($line->quantity_needed)) {
                    $movement->forceFill(['voided_at' => now(), 'voided_by' => $by?->id])->save();
                    $current->forget($materialId);
                }
            }

            foreach ($plan as $materialId => $line) {
                if ($current->has($materialId)) {
                    continue;
                }

                $material = $this->lock($line->material);
                $before = $this->onHandHundredths($material);
                $wanted = min($this->hundredths($line->quantity_needed), max(0, $before));

                if ($wanted <= 0) {
                    continue;
                }

                $this->write($material, StockMovement::TYPE_STOCK_OUT, -$wanted, BusinessTime::today(), [
                    'project_id' => $project->project_id,
                    'is_assignment' => true,
                    'notes' => 'Assigned to project',
                ], $by);

                $this->alertIfRunningLow($material, $before, $before - $wanted);
            }
        });
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    /**
     * Tell Purchasing when a change has just taken what is available to the
     * reorder level, or to nothing - on the way down only, so a material that
     * stays low is not announced again with every issue.
     */
    private function alertIfRunningLow(Material $material, int $before, int $after): void
    {
        if ($after >= $before) {
            return;
        }

        $was = $material->stockStatus($before / 100);
        $now = $material->stockStatus($after / 100);

        $worse = [
            Material::STOCK_IN => 0,
            Material::STOCK_LOW => 1,
            Material::STOCK_OUT => 2,
        ];

        if ($worse[$now] > $worse[$was]) {
            $this->notifications->stockRunningLow($material, $now, $after / 100);
        }
    }

    /**
     * The material's row, locked for the rest of the transaction - the one
     * lock every movement of it waits on.
     */
    private function lock(Material $material): Material
    {
        return Material::query()->whereKey($material->getKey())->lockForUpdate()->firstOrFail();
    }

    private function availableHundredths(Material $material): int
    {
        return $this->hundredths($this->availability->forMaterial($material)['available']);
    }

    private function onHandHundredths(Material $material): int
    {
        return $this->hundredths(
            StockMovement::query()
                ->counted()
                ->where('material_id', $material->material_id)
                ->sum('quantity')
        );
    }

    /**
     * @param  array{reference_no?: string|null, supplier?: string|null, project_id?: int|null, project_material_entry_id?: int|null, is_reservation?: bool, is_assignment?: bool, notes?: string|null}  $details
     */
    private function write(Material $material, string $type, int $hundredths, CarbonInterface|string $date, array $details, ?User $by): StockMovement
    {
        return StockMovement::create([
            'material_id' => $material->material_id,
            'movement_type' => $type,
            'quantity' => $hundredths / 100,
            'movement_date' => $date instanceof CarbonInterface ? $date->toDateString() : $date,
            'reference_no' => $this->text($details['reference_no'] ?? null, 100),
            'supplier' => $this->text($details['supplier'] ?? null, 150),
            'project_id' => $details['project_id'] ?? null,
            'project_material_entry_id' => $details['project_material_entry_id'] ?? null,
            'is_reservation' => $details['is_reservation'] ?? false,
            'is_assignment' => $details['is_assignment'] ?? false,
            'notes' => $this->text($details['notes'] ?? null, 255),
            'recorded_by' => $by?->id,
            'recorded_by_name' => $by?->fullName() ?? 'System',
        ]);
    }

    /**
     * @throws RuntimeException
     */
    private function project(mixed $projectId): ?Project
    {
        if (! filled($projectId)) {
            return null;
        }

        return Project::query()->find((int) $projectId)
            ?? throw new RuntimeException('That project no longer exists.');
    }

    /**
     * @throws RuntimeException
     */
    private function assertQuantity(Material $material, float|int|string $quantity): void
    {
        $problem = $material->unit->quantityProblem($quantity);

        if ($problem !== null) {
            throw new RuntimeException($problem);
        }
    }

    /**
     * @param  array<int, array{material: Material, quantity: float|int|string}>  $lines
     *
     * @throws RuntimeException naming the material, among several
     */
    private function assertLineQuantities(array $lines): void
    {
        foreach ($lines as $line) {
            $problem = $line['material']->unit->quantityProblem($line['quantity']);

            if ($problem !== null) {
                throw new RuntimeException(sprintf('%s: %s', $line['material']->displayName(), $problem));
            }
        }
    }

    /**
     * Always the same order, so two batches sharing materials wait for each
     * other's locks rather than deadlocking on them.
     *
     * @param  array<int, array{material: Material, quantity: float|int|string}>  $lines
     * @return array<int, array{material: Material, quantity: float|int|string}>
     */
    private function inLockOrder(array $lines): array
    {
        usort($lines, fn (array $a, array $b): int => $a['material']->material_id <=> $b['material']->material_id);

        return $lines;
    }

    /**
     * "12 pc", "2.5 m" - a quantity as the history prints it.
     */
    private function amount(Material $material, float|int|string $quantity): string
    {
        return $material->unit->format($quantity).' '.$material->unit->value;
    }

    private function text(?string $value, int $limit): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, $limit);
    }

    private function hundredths(mixed $quantity): int
    {
        return (int) round((float) $quantity * 100);
    }
}
