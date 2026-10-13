<?php

namespace Database\Seeders;

use App\Models\PhaseStage;
use App\Models\ProjectType;
use App\Services\PhaseTemplateCatalog;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * The default phases of every project type, with their default tasks.
 *
 *     php artisan db:seed --class=PhaseTemplateSeeder --force
 *
 * What Configuration -> Phase Templates holds after this, for each of the six
 * project types: Site Preparation, the work itself (Installation, Fabrication,
 * Repair, Cleaning or Ventilation Works), Testing and Final Inspection - each
 * with its tasks, as listed in DemoDataCatalog::phaseTemplates(). A new
 * project of that type is offered exactly these at phase setup.
 *
 * Written through PhaseTemplateCatalog, the one place the template tables are
 * written. Each type's template is replaced whole; a stage already in the
 * vocabulary keeps its name and takes the default description; stages a
 * site added of its own stay, after these in the order. No project that
 * already exists is touched - a template is only ever copied onto a project
 * at setup. DemoDataSeeder runs this before it adds its projects.
 */
class PhaseTemplateSeeder extends Seeder
{
    public function run(): void
    {
        $catalog = app(PhaseTemplateCatalog::class);

        DB::transaction(function () use ($catalog): void {
            $stageIds = $this->ensureStages($catalog);
            $types = ProjectType::query()->get();

            foreach (DemoDataCatalog::phaseTemplates() as $typeName => $stages) {
                $type = $types->first(fn (ProjectType $type): bool => DemoDataCatalog::sameName($type->type_name, $typeName));

                if ($type === null) {
                    $this->command?->warn(sprintf("There is no '%s' project type yet, so its phases were skipped.", $typeName));

                    continue;
                }

                $catalog->saveTemplate($type, collect($stages)
                    ->map(fn (array $tasks, string $stage): array => [
                        'stage_id' => $stageIds[$stage],
                        'tasks' => array_map(fn (array $task): array => [
                            'title' => $task[0],
                            'description' => $task[1],
                        ], $tasks),
                    ])
                    ->values()
                    ->all());
            }
        });

        $this->command?->info('Phase templates ready for every project type.');
    }

    /**
     * Every stage the templates use, in the order a project works through
     * them, by name.
     *
     * @return array<string, int>
     */
    private function ensureStages(PhaseTemplateCatalog $catalog): array
    {
        $existing = PhaseStage::query()->inOrder()->get();
        $ids = [];

        foreach (DemoDataCatalog::PHASE_STAGES as $name => $description) {
            $stage = $existing->first(fn (PhaseStage $stage): bool => DemoDataCatalog::sameName($stage->name, $name));

            $stage = $stage !== null
                ? $catalog->renameStage($stage, $stage->name, $description)
                : $catalog->addStage($name, $description);

            $ids[$name] = (int) $stage->stage_id;
        }

        // These first, as listed; a stage the site added of its own keeps
        // its place among the others, after them.
        $others = PhaseStage::query()
            ->inOrder()
            ->pluck('stage_id')
            ->map(fn ($id): int => (int) $id)
            ->diff(array_values($ids))
            ->values()
            ->all();

        $catalog->reorderStages([...array_values($ids), ...$others]);

        return $ids;
    }
}
