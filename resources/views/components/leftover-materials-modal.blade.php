{{-- Complete Project, first step, when materials are still on site: the
     project's Materials tab, moved into this dialog by leftoverMaterials.js
     so the leftovers can be recorded as used or returned first. The page
     loads the script itself, so a redraw that adds this dialog still has it. Continue
     opens the Service Report form; whatever is still left then goes back to
     stock when the project is completed - see ProjectMaterialLedger::closeOut(). --}}
@props(['continueTarget' => '#editProjectDescriptionModal'])

<div class="modal fade" id="leftoverMaterialsModal" tabindex="-1" aria-hidden="true"
    aria-labelledby="leftoverMaterialsTitle" data-leftover-materials-modal>
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="leftoverMaterialsTitle">Leftover Materials</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-warning small py-2" role="status">
                    Unused materials return to stock.
                </div>
                <div data-leftover-slot></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-success" data-leftover-continue
                    data-continue-target="{{ $continueTarget }}">Continue</button>
            </div>
        </div>
    </div>
</div>
