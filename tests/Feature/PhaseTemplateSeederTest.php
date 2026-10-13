<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\PhaseStage;
use App\Models\Project;
use App\Models\ProjectType;
use App\Models\ProjectTypeStageTask;
use App\Services\PhaseTemplateCatalog;
use App\Services\PhaseTemplateMerger;
use Database\Seeders\DemoDataCatalog;
use Database\Seeders\PhaseTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Every project type's default phases - Site Preparation, the work, Testing
 * and Final Inspection - each with its default tasks.
 */
class PhaseTemplateSeederTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @var array<string, string>
     */
    private const WORK_STAGE = [
        DemoDataCatalog::AIRCON_INSTALLATION => 'Installation',
        DemoDataCatalog::AIRCON_REPAIR => 'Repair',
        DemoDataCatalog::AIRCON_CLEANING => 'Cleaning',
        DemoDataCatalog::DUCTING_FABRICATION => 'Fabrication',
        DemoDataCatalog::DUCTING_INSTALLATION => 'Installation',
        DemoDataCatalog::HEATING_VENTILATION => 'Ventilation Works',
    ];

    private function seedTypes(): void
    {
        foreach (DemoDataCatalog::PROJECT_TYPES as $name) {
            ProjectType::create(['type_name' => $name]);
        }
    }

    public function test_every_type_gets_its_four_phases_with_tasks(): void
    {
        $this->seedTypes();

        $this->seed(PhaseTemplateSeeder::class);

        foreach (self::WORK_STAGE as $typeName => $work) {
            $template = collect(app(PhaseTemplateCatalog::class)->templateFor(ProjectType::where('type_name', $typeName)->firstOrFail()))
                ->where('selected', true)
                ->values();

            $this->assertSame(
                ['Site Preparation', $work, 'Testing', 'Final Inspection'],
                $template->pluck('name')->all(),
                $typeName.' does not have the default phases in order.'
            );

            foreach ($template as $stage) {
                $this->assertNotEmpty($stage['tasks'], $typeName.' / '.$stage['name'].' has no default tasks.');
            }
        }
    }

    public function test_a_new_project_is_offered_the_template_at_setup(): void
    {
        $this->seedTypes();
        $this->seed(PhaseTemplateSeeder::class);

        $project = Project::create([
            'client_id' => Client::factory()->create()->client_id,
            'name' => 'Duct Job',
            'status' => 'pending',
            'address' => 'Address',
            'description' => 'Description',
        ]);

        $project->projectTypes()->sync(ProjectType::query()
            ->whereIn('type_name', [DemoDataCatalog::DUCTING_FABRICATION, DemoDataCatalog::DUCTING_INSTALLATION])
            ->pluck('type_id'));

        $merged = app(PhaseTemplateMerger::class)->for($project);

        $this->assertTrue($merged['from_templates']);
        // Fabrication comes before Installation, from the stage order.
        $this->assertSame(
            ['Site Preparation', 'Fabrication', 'Installation', 'Testing', 'Final Inspection'],
            array_column($merged['phases'], 'title')
        );
    }

    public function test_a_misspelled_type_on_the_site_still_gets_its_template(): void
    {
        $type = ProjectType::create(['type_name' => 'Heating Ventillation']);

        $this->seed(PhaseTemplateSeeder::class);

        $this->assertSame(4, $type->stages()->count());
        $this->assertTrue($type->stages()->where('name', 'Ventilation Works')->exists());
    }

    public function test_a_stage_the_site_added_itself_stays_after_the_defaults(): void
    {
        $this->seedTypes();
        $own = app(PhaseTemplateCatalog::class)->addStage('Electrical Rough-in', 'Run the conduits.');

        $this->seed(PhaseTemplateSeeder::class);

        $this->assertSame('Electrical Rough-in', PhaseStage::query()->inOrder()->get()->last()->name);
        $this->assertNotNull($own->fresh());
    }

    public function test_running_it_twice_changes_nothing(): void
    {
        $this->seedTypes();

        $this->seed(PhaseTemplateSeeder::class);
        $stages = PhaseStage::query()->count();
        $tasks = ProjectTypeStageTask::query()->count();

        $this->seed(PhaseTemplateSeeder::class);

        $this->assertSame($stages, PhaseStage::query()->count());
        $this->assertSame($tasks, ProjectTypeStageTask::query()->count());
    }
}
