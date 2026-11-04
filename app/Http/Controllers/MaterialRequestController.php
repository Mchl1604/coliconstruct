<?php

namespace App\Http\Controllers;

use App\Models\Material;
use App\Models\MaterialRequest;
use App\Models\Project;
use App\Services\MaterialRequests;
use App\Services\ProjectMaterialRules;
use App\Services\StockAvailability;
use App\Support\ServerTable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Material requests: a lead sends one from their project's Materials tab, and
 * the inventory side decides on its own page.
 *
 * Deciding is behind `area:inventory` - Super Admin, Admin Officer and
 * Purchasing Admin. Everything else is MaterialRequests'.
 */
class MaterialRequestController extends Controller
{
    public function __construct(
        private readonly MaterialRequests $requests,
        private readonly ProjectMaterialRules $rules,
        private readonly StockAvailability $availability,
    ) {}

    public function store(Request $request, Project $project): RedirectResponse
    {
        if (! $this->rules->canRequest($request->user(), $project)) {
            throw new AccessDeniedHttpException('You cannot request materials on this project.');
        }

        $back = route('technician.projects.show', $project->project_id).'#project-materials';

        $validator = Validator::make($request->all(), [
            'material_id' => ['required', 'integer'],
            'quantity' => ['required', 'numeric'],
        ], [
            'material_id.required' => 'Choose a material.',
            'quantity.required' => 'Enter a quantity.',
            'quantity.numeric' => 'Enter a quantity.',
        ]);

        if ($validator->fails()) {
            return redirect()->to($back)->withInput()->with('error', $validator->errors()->first());
        }

        $data = $validator->validated();
        $material = Material::query()->find($data['material_id']);

        if ($material === null) {
            return redirect()->to($back)->with('error', 'That material is no longer listed.');
        }

        try {
            $this->requests->request($project, $material, $data['quantity'], $request->user());
        } catch (RuntimeException $exception) {
            return redirect()->to($back)->withInput()->with('error', $exception->getMessage());
        }

        return redirect()->to($back)->with('success', 'Material requested.');
    }

    public function index(): View
    {
        return view('super-admin.material-requests', [
            'statuses' => MaterialRequest::STATUSES,
        ]);
    }

    /**
     * One page of the requests, for serverTable.js - what is waiting first.
     */
    public function table(Request $request): JsonResponse
    {
        $table = ServerTable::fromRequest($request);
        $status = (string) $request->query('status', '');

        $page = $table->paginate(
            MaterialRequest::query()
                ->with(['material', 'project', 'requester', 'decider'])
                ->when(array_key_exists($status, MaterialRequest::STATUSES), fn (Builder $builder) => $builder->where('status', $status)),
            [
                0 => 'created_at',
                3 => 'quantity',
                6 => 'status',
            ],
            fn (Builder $builder, string $word, string $like) => $builder
                ->whereHas('material', fn (Builder $material) => $material->where('name', 'like', $like))
                ->orWhereHas('project', fn (Builder $project) => $project
                    ->where('reference_no', 'like', $like)
                    ->orWhere('name', 'like', $like)),
            [[0, 'desc']],
        );

        // Available for the rows on this page only, the figure approving is
        // held to.
        $figures = $this->availability->forMaterials($page->items->pluck('material_id')->all());

        return $table->respond(
            $page,
            fn (MaterialRequest $materialRequest): string => view('super-admin.partials.material-request-row', [
                'materialRequest' => $materialRequest,
                'available' => $figures[(int) $materialRequest->material_id]['available'] ?? null,
            ])->render(),
        );
    }

    public function approve(Request $request, MaterialRequest $materialRequest): JsonResponse
    {
        try {
            $this->requests->approve($materialRequest, $request->user());
        } catch (RuntimeException $exception) {
            return response()->json(['error' => $exception->getMessage()], 422);
        }

        return response()->json(['message' => 'Request approved.']);
    }

    public function reject(Request $request, MaterialRequest $materialRequest): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'reason' => ['required', 'string', 'max:255'],
        ], [
            'reason.required' => 'Say why.',
            'reason.max' => 'Reason is too long.',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()->first()], 422);
        }

        try {
            $this->requests->reject($materialRequest, $validator->validated()['reason'], $request->user());
        } catch (RuntimeException $exception) {
            return response()->json(['error' => $exception->getMessage()], 422);
        }

        return response()->json(['message' => 'Request rejected.']);
    }
}
