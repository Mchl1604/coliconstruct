<?php

use App\Models\DetailForm;
use App\Models\ProjectType;
use App\Models\Skill;
use App\Services\DetailFormCatalog;
use App\Services\PhaseTemplateCatalog;
use App\Support\ServiceReportForms;
use Database\Seeders\DemoDataCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Applies the paper Service Report to the project types already on a site:
 * Aircon Repair becomes Equipment Repair, Equipment Check-up is added for the
 * technicians who do repairs, Heating Ventillation is deleted, and the aircon
 * forms take the paper's fields. Ducting is left alone.
 *
 * Deleting the type also takes it off its projects, its template and its
 * technicians. A site with no types yet is left empty for the seeders.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! ProjectType::query()->exists()) {
            return;
        }

        DB::transaction(function (): void {
            ProjectType::query()->where('type_name', 'Aircon Repair')->update(['type_name' => ServiceReportForms::REPAIR_TYPE]);
            Skill::query()->where('skill_name', 'Aircon Repair')->update(['skill_name' => ServiceReportForms::REPAIR_TYPE]);
            DetailForm::query()->where('name', 'Repair')->update(['name' => 'Equipment Repair']);

            $this->addCheckUp();

            $catalog = app(DetailFormCatalog::class);

            foreach (ServiceReportForms::definitions() as $name => $definition) {
                $form = $catalog->define($name, $definition['fields']);

                ProjectType::query()->whereIn('type_name', $definition['types'])->update(['form_id' => $form->form_id]);
            }

            ProjectType::query()->where('type_name', 'like', 'Heating Ventil%')->delete();
            Skill::query()->where('skill_name', 'like', 'Heating Ventil%')->delete();
            DetailForm::query()->where('name', 'Ventilation')->whereDoesntHave('projectTypes')->delete();
        });
    }

    /**
     * Deleted rows are not recoverable from here.
     */
    public function down(): void {}

    private function addCheckUp(): void
    {
        $type = ProjectType::query()->firstOrCreate(['type_name' => ServiceReportForms::CHECK_UP_TYPE]);
        $skill = Skill::query()->firstOrCreate(['skill_name' => ServiceReportForms::CHECK_UP_TYPE]);
        $repairSkill = Skill::query()->where('skill_name', ServiceReportForms::REPAIR_TYPE)->first();

        if ($repairSkill) {
            DB::table('tbl_skill_map')->insertOrIgnore(
                DB::table('tbl_skill_map')
                    ->where('skill_id', $repairSkill->skill_id)
                    ->pluck('technician_id')
                    ->map(fn (int $technicianId): array => ['technician_id' => $technicianId, 'skill_id' => $skill->skill_id])
                    ->all()
            );
        }

        $stages = DB::table('tbl_phase_stages')->pluck('stage_id', 'name');
        $template = collect(DemoDataCatalog::phaseTemplates()[ServiceReportForms::CHECK_UP_TYPE])
            ->filter(fn (array $tasks, string $stage): bool => $stages->has($stage))
            ->map(fn (array $tasks, string $stage): array => [
                'stage_id' => $stages[$stage],
                'tasks' => array_map(fn (array $task): array => ['title' => $task[0], 'description' => $task[1]], $tasks),
            ])
            ->values()
            ->all();

        if ($template !== []) {
            app(PhaseTemplateCatalog::class)->saveTemplate($type, $template);
        }
    }
};
