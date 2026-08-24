<?php

namespace App\Providers;

use App\Models\Project;
use App\Models\Schedule;
use App\Models\SpecialtyRequest;
use App\Models\Task;
use App\Models\TechnicianReport;
use App\Models\User;
use App\Policies\ProjectPolicy;
use App\Policies\TaskPolicy;
use App\Policies\TechnicianReportPolicy;
use App\Services\DashboardMetrics;
use App\Services\SystemContentService;
use Illuminate\Contracts\View\View as ViewContract;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Registered by hand rather than left to discovery so the mapping is
        // greppable from one place.
        Gate::policy(Project::class, ProjectPolicy::class);
        Gate::policy(Task::class, TaskPolicy::class);
        Gate::policy(TechnicianReport::class, TechnicianReportPolicy::class);

        // Every public page reads its words from the same place, and a child
        // view's @section is evaluated before its layout - so $content is
        // shared with both rather than assigned inside the layout.
        View::composer(
            ['layouts.publicSite', 'public.*'],
            fn (ViewContract $view) => $view->with('content', app(SystemContentService::class))
        );

        $this->keepDashboardFiguresCurrent();
    }

    /**
     * Drop the dashboard's cached figures whenever something they count
     * changes.
     *
     * The dashboard caches briefly so a burst of readers costs one query set,
     * which would otherwise mean a newly created project taking a minute to
     * appear. Listening on the four models it counts means the invalidation
     * cannot be forgotten at a call site - every create, update and delete
     * goes through Eloquent, wherever it is written.
     */
    private function keepDashboardFiguresCurrent(): void
    {
        $models = [Project::class, User::class, Schedule::class, SpecialtyRequest::class];

        foreach ($models as $model) {
            foreach (['saved', 'deleted'] as $event) {
                $model::{$event}(static fn (Model $record) => DashboardMetrics::flush());
            }
        }
    }
}
