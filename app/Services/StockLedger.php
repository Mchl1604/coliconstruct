<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Material;
use App\Models\Project;
use App\Models\StockMovement;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The company's stock: what came in, what went out, and counts set right.
 *
 *     On Hand = the sum of every movement that is not voided
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

            $movement = $this->write($material, $type, $this->hundredths($quantity), $date, [
                'reference_no' => $details['reference_no'] ?? null,
                // A supplier delivers; a return comes back from a project.
                'supplier' => $type === StockMovement::TYPE_STOCK_IN ? ($details['supplier'] ?? null) : null,
                'project_id' => $type === StockMovement::TYPE_RETURNED ? $project?->project_id : null,
                'notes' => $details['notes'] ?? null,
            ], $by);

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

            $this->alertIfRunningLow($material, $before, $before - $wanted);

            return $movement;
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

            $this->alertIfRunningLow($material, $before, $after);

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

        DB::transaction(function () use ($movement, $by): void {
            $material = $this->lock($movement->material);

            $before = $this->onHandHundredths($material);
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

            $this->alertIfRunningLow($material, $before, $after);
        });
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    /**
     * Tell Purchasing when a change has just taken a material to its reorder
     * level, or to nothing - on the way down only, so a material that stays
     * low is not announced again with every issue.
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

    private function onHandHundredths(Material $material): int
    {
        return $this->hundredths(
            StockMovement::query()
                ->notVoided()
                ->where('material_id', $material->material_id)
                ->sum('quantity')
        );
    }

    /**
     * @param  array{reference_no?: string|null, supplier?: string|null, project_id?: int|null, notes?: string|null}  $details
     */
    private function write(Material $material, string $type, int $hundredths, CarbonInterface|string $date, array $details, User $by): StockMovement
    {
        return StockMovement::create([
            'material_id' => $material->material_id,
            'movement_type' => $type,
            'quantity' => $hundredths / 100,
            'movement_date' => $date instanceof CarbonInterface ? $date->toDateString() : $date,
            'reference_no' => $this->text($details['reference_no'] ?? null, 100),
            'supplier' => $this->text($details['supplier'] ?? null, 150),
            'project_id' => $details['project_id'] ?? null,
            'notes' => $this->text($details['notes'] ?? null, 255),
            'recorded_by' => $by->id,
            'recorded_by_name' => $by->fullName(),
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
