<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Project;
use App\Services\ActivityLogger;
use App\Services\ProjectQuotation;
use App\Services\QuotationChange;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Project Details -> Quotation -> Edit: changing the breakdown after the
 * project was created. A change in the total goes in the quotation history.
 * Reserved stock is left alone: the Materials tab shows any gap.
 */
class ProjectQuotationController extends Controller
{
    /** The error bag the edit dialog reopens on. */
    public const ERROR_BAG = 'quotation';

    public function __construct(
        private readonly ProjectQuotation $quotation,
        private readonly QuotationChange $quotationChange,
        private readonly ActivityLogger $activityLogger
    ) {}

    public function update(Request $request, int $id): RedirectResponse
    {
        $project = Project::query()->findOrFail($id);

        if ($project->isReadOnly()) {
            return redirect()
                ->route('super-admin.projects.show', $id)
                ->with('error', 'This project can no longer be edited.');
        }

        $validated = Validator::make($request->all(), $this->quotation->rules(), $this->quotation->messages())
            ->after(function (\Illuminate\Validation\Validator $validator) use ($request): void {
                if ($validator->errors()->isEmpty()) {
                    foreach ($this->quotation->errors(array_values((array) $request->input('quotation_items'))) as $key => $message) {
                        $validator->errors()->add($key, $message);
                    }
                }
            })
            ->validateWithBag(self::ERROR_BAG);

        $rows = array_values($validated['quotation_items']);
        $total = $this->quotation->total($rows);
        $amountChanged = false;

        DB::transaction(function () use ($project, $rows, $total, $request, &$amountChanged): void {
            $locked = Project::query()->lockForUpdate()->findOrFail($project->project_id);
            $previous = $locked->quotation;

            $this->quotation->save($locked, $rows);

            $amountChanged = ! QuotationChange::sameAmount($previous, $total);

            if ($amountChanged) {
                $locked->update(['quotation' => $total]);
                $this->quotationChange->recordAmountChange($locked, $previous, $total, false, $request->user());
            }
        });

        $this->activityLogger->record(
            ActivityLog::PROJECT_QUOTATION_UPDATED,
            null,
            sprintf("Updated the quotation breakdown of project '%s'.", $project->reference_no),
            $project
        );

        return redirect()
            ->route('super-admin.projects.show', $id)
            ->with('success', $amountChanged ? 'Quotation saved. Upload the new approved file.' : 'Quotation saved.');
    }
}
