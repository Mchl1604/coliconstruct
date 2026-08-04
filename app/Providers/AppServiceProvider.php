<?php

namespace App\Providers;

use App\Models\Project;
use App\Models\Task;
use App\Policies\ProjectPolicy;
use App\Policies\TaskPolicy;
use App\Services\SystemContentService;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Contracts\View\View as ViewContract;
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

        // The reset notification builds its own link, and would otherwise look
        // for a route called `password.reset`. This app names it `auth.*`.
        ResetPassword::createUrlUsing(fn (object $notifiable, string $token): string => route(
            'auth.password.reset',
            ['token' => $token, 'email' => $notifiable->getEmailForPasswordReset()]
        ));

        // Every public page reads its words from the same place, and a child
        // view's @section is evaluated before its layout - so $content is
        // shared with both rather than assigned inside the layout.
        View::composer(
            ['layouts.publicSite', 'public.*'],
            fn (ViewContract $view) => $view->with('content', app(SystemContentService::class))
        );
    }
}
