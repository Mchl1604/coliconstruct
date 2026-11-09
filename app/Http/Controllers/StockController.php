<?php

namespace App\Http\Controllers;

use App\Models\Material;
use App\Models\MaterialType;
use App\Models\Project;
use App\Models\StockMovement;
use App\Services\MaterialCatalog;
use App\Services\StockAvailability;
use App\Services\StockLedger;
use App\Support\BusinessTime;
use App\Support\ServerTable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * Inventory -> Stock and Movements: how many of each material are held, and
 * every movement in and out.
 *
 * Behind `area:inventory` like the rest of the Inventory page - see
 * RoleAccess. Every write goes through StockLedger and answers with the
 * page's summary figures, so the cards above the tables follow each change.
 */
class StockController extends Controller
{
    public function __construct(
        private readonly StockLedger $ledger,
        private readonly StockAvailability $availability,
    ) {}

    /**
     * One page of the Stock table, for serverTable.js.
     */
    public function index(Request $request): JsonResponse
    {
        $table = ServerTable::fromRequest($request);
        $typeId = $request->integer('type') ?: null;
        $subtypeId = $request->integer('subtype') ?: null;
        $status = (string) $request->query('stock', '');

        $query = Material::query()
            ->with('type')
            ->withOnHand()
            ->when($typeId, fn (Builder $builder) => $builder->where('material_type_id', $typeId))
            ->when($subtypeId, fn (Builder $builder) => $builder->where('material_subtype_id', $subtypeId))
            ->when(array_key_exists($status, Material::STOCK_STATUSES), fn (Builder $builder) => $builder->inStockStatus($status));

        $page = $table->paginate(
            $query,
            [
                0 => 'name',
                1 => fn (Builder $builder, string $dir) => $builder->orderBy(
                    MaterialType::query()
                        ->select('type_name')
                        ->whereColumn('tbl_material_types.material_type_id', 'tbl_materials.material_type_id'),
                    $dir
                ),
                2 => 'on_hand',
            ],
            fn (Builder $builder, string $word, string $like) => $builder
                ->where('name', 'like', $like)
                ->orWhereHas('brand', fn (Builder $brand) => $brand->where('brand_name', 'like', $like))
                ->orWhere('description', 'like', $like)
                ->orWhereHas('type', fn (Builder $type) => $type->where('type_name', 'like', $like))
                ->orWhereHas('subtype', fn (Builder $subtype) => $subtype->where('subtype_name', 'like', $like)),
            [[0, 'asc']],
        );

        // Assigned and Available for the rows on this page only - the sum
        // runs across every live project's plan.
        $figures = $this->availability->forMaterials($page->items->pluck('material_id')->all());

        return $table->respond(
            $page,
            fn (Material $material): string => view('super-admin.partials.stock-row', [
                'material' => $material,
                'figures' => $figures[(int) $material->material_id] ?? null,
            ])->render(),
        );
    }

    /**
     * One page of the Movements table, newest first.
     */
    public function movements(Request $request): JsonResponse
    {
        $table = ServerTable::fromRequest($request);
        $materialId = $request->integer('material') ?: null;
        $type = (string) $request->query('type', '');

        $query = StockMovement::query()
            ->with(['material', 'project'])
            ->when($materialId, fn (Builder $builder) => $builder->where('material_id', $materialId))
            ->when(array_key_exists($type, StockMovement::TYPES), fn (Builder $builder) => $builder->where('movement_type', $type));

        $page = $table->paginate(
            $query,
            [
                0 => 'movement_date',
                1 => fn (Builder $builder, string $dir) => $builder->orderBy(
                    Material::query()
                        ->select('name')
                        ->whereColumn('tbl_materials.material_id', 'tbl_stock_movements.material_id'),
                    $dir
                ),
                2 => 'movement_type',
                3 => 'quantity',
            ],
            fn (Builder $builder, string $word, string $like) => $builder
                ->where('notes', 'like', $like)
                ->orWhere('recorded_by_name', 'like', $like)
                ->orWhereHas('material', fn (Builder $material) => $material->where('name', 'like', $like))
                ->orWhereHas('project', fn (Builder $project) => $project
                    ->where('reference_no', 'like', $like)
                    ->orWhere('name', 'like', $like)),
            [[0, 'desc']],
        );

        return $table->respond(
            $page,
            fn (StockMovement $movement): string => view('super-admin.partials.stock-movement-row', [
                'movement' => $movement,
            ])->render(),
        );
    }

    /**
     * The dialog's material search: what matches what was typed, each with
     * how many are on hand, assigned to live projects, and available.
     */
    public function options(Request $request, MaterialCatalog $catalog): JsonResponse
    {
        $materials = $catalog->suggest(mb_substr((string) $request->query('q', ''), 0, 100));
        $figures = $this->availability->forMaterials($materials->pluck('material_id')->all());

        return response()->json([
            'materials' => $materials
                ->map(fn (array $material): array => $material + ($figures[$material['material_id']] ?? []))
                ->all(),
        ]);
    }

    /**
     * Stock In for several materials at once, all saved or none.
     */
    public function receiveMany(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), $this->batchRules() + [
            'movement_type' => ['required', Rule::in(StockMovement::INCOMING_TYPES)],
        ], $this->batchMessages());

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()->first()], 422);
        }

        $data = $validator->validated();
        $lines = $this->batchLines($data['items']);

        if ($lines === null) {
            return response()->json(['error' => 'That material is no longer listed.'], 422);
        }

        try {
            $this->ledger->receiveMany($lines, $data['movement_type'], $data['movement_date'], $data, $request->user());
        } catch (RuntimeException $exception) {
            return response()->json(['error' => $exception->getMessage()], 422);
        }

        return $this->done(sprintf('%d %s received.', count($lines), count($lines) === 1 ? 'material' : 'materials'));
    }

    /**
     * Stock Out for several materials at once, all saved or none.
     */
    public function issueMany(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), $this->batchRules(), $this->batchMessages());

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()->first()], 422);
        }

        $data = $validator->validated();
        $lines = $this->batchLines($data['items']);

        if ($lines === null) {
            return response()->json(['error' => 'That material is no longer listed.'], 422);
        }

        if (! $this->canTakeStock($data['project_id'] ?? null)) {
            return response()->json(['error' => 'Choose a project still in progress.'], 422);
        }

        try {
            $this->ledger->issueMany($lines, $data['movement_date'], $data, $request->user());
        } catch (RuntimeException $exception) {
            return response()->json(['error' => $exception->getMessage()], 422);
        }

        return $this->done(sprintf('%d %s issued.', count($lines), count($lines) === 1 ? 'material' : 'materials'));
    }

    /**
     * Stock In: a delivery, or a return from a site.
     */
    public function receive(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'material_id' => ['required', 'integer'],
            'movement_type' => ['required', Rule::in(StockMovement::INCOMING_TYPES)],
            'quantity' => ['required', 'numeric'],
            'movement_date' => $this->dateRules(),
            'reference_no' => ['nullable', 'string', 'max:100'],
            'supplier' => ['nullable', 'string', 'max:150'],
            'project_id' => ['nullable', 'integer'],
            'notes' => ['nullable', 'string', 'max:255'],
        ], $this->messages());

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()->first()], 422);
        }

        $data = $validator->validated();
        $material = Material::query()->find($data['material_id']);

        if ($material === null) {
            return response()->json(['error' => 'That material is no longer listed.'], 422);
        }

        try {
            $this->ledger->receive($material, $data['movement_type'], $data['quantity'], $data['movement_date'], $data, $request->user());
        } catch (RuntimeException $exception) {
            return response()->json(['error' => $exception->getMessage()], 422);
        }

        return $this->done(sprintf('%s of %s received.', $this->amount($material, $data['quantity']), $material->displayName()));
    }

    /**
     * Stock Out: issued, used up, damaged or lost.
     */
    public function issue(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'material_id' => ['required', 'integer'],
            'quantity' => ['required', 'numeric'],
            'movement_date' => $this->dateRules(),
            'reference_no' => ['nullable', 'string', 'max:100'],
            'project_id' => ['nullable', 'integer'],
            'notes' => ['nullable', 'string', 'max:255'],
        ], $this->messages());

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()->first()], 422);
        }

        $data = $validator->validated();
        $material = Material::query()->find($data['material_id']);

        if ($material === null) {
            return response()->json(['error' => 'That material is no longer listed.'], 422);
        }

        if (! $this->canTakeStock($data['project_id'] ?? null)) {
            return response()->json(['error' => 'Choose a project still in progress.'], 422);
        }

        try {
            $this->ledger->issue($material, $data['quantity'], $data['movement_date'], $data, $request->user());
        } catch (RuntimeException $exception) {
            return response()->json(['error' => $exception->getMessage()], 422);
        }

        return $this->done(sprintf('%s of %s issued.', $this->amount($material, $data['quantity']), $material->displayName()));
    }

    /**
     * Adjust: set the record to what a count found.
     */
    public function adjust(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'material_id' => ['required', 'integer'],
            'counted' => ['required', 'numeric', 'min:0'],
            'movement_date' => $this->dateRules(),
            'notes' => ['required', 'string', 'max:255'],
        ], $this->messages() + [
            'counted.required' => 'Enter the count.',
            'counted.numeric' => 'Enter the count.',
            'counted.min' => 'Enter a count of zero or more.',
            'notes.required' => 'Say why the count changed.',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()->first()], 422);
        }

        $data = $validator->validated();
        $material = Material::query()->find($data['material_id']);

        if ($material === null) {
            return response()->json(['error' => 'That material is no longer listed.'], 422);
        }

        try {
            $this->ledger->adjust($material, $data['counted'], $data['movement_date'], $data['notes'], $request->user());
        } catch (RuntimeException $exception) {
            return response()->json(['error' => $exception->getMessage()], 422);
        }

        return $this->done(sprintf('%s set to %s.', $material->displayName(), $this->amount($material, $data['counted'])));
    }

    public function void(Request $request, StockMovement $movement): JsonResponse
    {
        try {
            $this->ledger->void($movement, $request->user());
        } catch (RuntimeException $exception) {
            return response()->json(['error' => $exception->getMessage()], 422);
        }

        return $this->done('Entry voided.');
    }

    /**
     * The figures the cards above the tables show: how many materials are
     * listed, and how many are low or out.
     *
     * @return array{materials: int, low: int, out: int}
     */
    public static function summary(): array
    {
        return [
            'materials' => Material::query()->count(),
            'low' => Material::query()->inStockStatus(Material::STOCK_LOW)->count(),
            'out' => Material::query()->inStockStatus(Material::STOCK_OUT)->count(),
        ];
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    /**
     * Stock goes out to work still being done. A closed or archived project
     * takes nothing more off the shelf.
     */
    private function canTakeStock(mixed $projectId): bool
    {
        if (! filled($projectId)) {
            return true;
        }

        $project = Project::query()->find($projectId);

        return $project !== null && ! $project->isReadOnly() && ! $project->isArchived();
    }

    /**
     * What Stock In and Stock Out for several materials share: the list, and
     * the details that apply to every line of it.
     *
     * @return array<string, array<int, string>>
     */
    private function batchRules(): array
    {
        return [
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.material_id' => ['required', 'integer', 'distinct'],
            'items.*.quantity' => ['required', 'numeric'],
            'movement_date' => $this->dateRules(),
            'project_id' => ['nullable', 'integer'],
            'notes' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    private function batchMessages(): array
    {
        return $this->messages() + [
            'items.required' => 'Add a material.',
            'items.min' => 'Add a material.',
            'items.max' => 'Too many materials at once.',
            'items.*.material_id.required' => 'Choose a material.',
            'items.*.material_id.distinct' => 'A material is listed twice.',
            'items.*.quantity.required' => 'Enter every quantity.',
            'items.*.quantity.numeric' => 'Enter every quantity.',
        ];
    }

    /**
     * The submitted items with their materials, or null when one is gone.
     *
     * @param  array<int, array{material_id: int|string, quantity: float|int|string}>  $items
     * @return array<int, array{material: Material, quantity: float|int|string}>|null
     */
    private function batchLines(array $items): ?array
    {
        $materials = Material::query()
            ->whereIn('material_id', array_column($items, 'material_id'))
            ->get()
            ->keyBy('material_id');

        $lines = [];

        foreach ($items as $item) {
            $material = $materials->get((int) $item['material_id']);

            if ($material === null) {
                return null;
            }

            $lines[] = ['material' => $material, 'quantity' => $item['quantity']];
        }

        return $lines;
    }

    private function done(string $message): JsonResponse
    {
        return response()->json([
            'message' => $message,
            'summary' => self::summary(),
        ]);
    }

    /**
     * A movement is dated the day it happened: today or earlier, read off the
     * office's clock.
     *
     * @return array<int, string>
     */
    private function dateRules(): array
    {
        return ['required', 'date', 'before_or_equal:'.BusinessTime::today()->toDateString()];
    }

    /**
     * @return array<string, string>
     */
    private function messages(): array
    {
        return [
            'material_id.required' => 'Choose a material.',
            'movement_type.required' => 'Choose Delivery or Return.',
            'movement_type.in' => 'Choose Delivery or Return.',
            'quantity.required' => 'Enter a quantity.',
            'quantity.numeric' => 'Enter a quantity.',
            'movement_date.required' => 'Choose a date.',
            'movement_date.date' => 'Choose a date.',
            'movement_date.before_or_equal' => 'The date cannot be in the future.',
            'reference_no.max' => 'Reference is too long.',
            'supplier.max' => 'Supplier is too long.',
            'notes.max' => 'Notes are too long.',
        ];
    }

    private function amount(Material $material, float|int|string $quantity): string
    {
        return $material->unit->format($quantity).' '.$material->unit->value;
    }
}
