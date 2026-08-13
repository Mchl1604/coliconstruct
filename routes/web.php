<?php

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Auth\EmailVerificationController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\ConfigurationController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\PublicSiteController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\ScheduleController;
use App\Http\Controllers\SystemContentController;
use App\Http\Controllers\TaskController;
use App\Http\Controllers\TechnicianController;
use App\Http\Controllers\TechnicianPortalController;
use App\Http\Controllers\TechnicianReportController;
use Illuminate\Support\Facades\Route;

// The public website. Every page here is readable by anyone; My Projects is
// the exception in content rather than in access - a guest reaches it and is
// invited to sign in.
Route::get('/', [PublicSiteController::class, 'home'])->name('landing.home');
Route::get('/about', [PublicSiteController::class, 'about'])->name('public.about');
Route::get('/contact', [PublicSiteController::class, 'contact'])->name('public.contact');
Route::get('/my-projects', [PublicSiteController::class, 'myProjects'])->name('public.projects');
Route::get('/my-projects/{project}', [PublicSiteController::class, 'projectDetails'])
    ->whereNumber('project')
    ->name('public.projects.show');

// Authentication
Route::get('/login', [AuthController::class, 'showLogin'])->name('auth.login');
Route::post('/login', [AuthController::class, 'login'])->name('auth.login.attempt');
Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth')->name('auth.logout');

// Public registration, which only ever opens a client account. Every employee
// account is created by an admin or super admin in Configuration.
Route::get('/register', [AuthController::class, 'showRegister'])->name('auth.register');
Route::post('/register', [AuthController::class, 'register'])->name('auth.register.store');

// Proving a newly registered address is real. Guest routes by necessity: the
// account exists but cannot sign in until the code sent to it comes back.
//
// Throttled on top of the per-address limits OtpService already applies, so a
// script cannot burn through somebody's attempts from one machine.
Route::get('/verify-email', [EmailVerificationController::class, 'show'])->name('auth.verify');
Route::post('/verify-email', [EmailVerificationController::class, 'verify'])
    ->middleware('throttle:10,1')
    ->name('auth.verify.store');
Route::post('/verify-email/resend', [EmailVerificationController::class, 'resend'])
    ->middleware('throttle:6,1')
    ->name('auth.verify.resend');

// Forgotten password. Open to guests by definition - somebody who cannot sign
// in is exactly who needs it.
//
// Three steps: name the address, enter the code emailed to it, choose a new
// password. Nothing that grants access is left sitting in a mailbox.
Route::get('/forgot-password', [PasswordResetController::class, 'request'])->name('auth.password.request');
Route::post('/forgot-password', [PasswordResetController::class, 'sendCode'])
    ->middleware('throttle:6,1')
    ->name('auth.password.email');
Route::get('/forgot-password/verify', [PasswordResetController::class, 'showVerify'])->name('auth.password.verify');
Route::post('/forgot-password/verify', [PasswordResetController::class, 'verify'])
    ->middleware('throttle:10,1')
    ->name('auth.password.verify.store');
Route::post('/forgot-password/resend', [PasswordResetController::class, 'resend'])
    ->middleware('throttle:6,1')
    ->name('auth.password.resend');
Route::get('/reset-password', [PasswordResetController::class, 'showReset'])->name('auth.password.reset');
Route::post('/reset-password', [PasswordResetController::class, 'reset'])->name('auth.password.store');

// The forced first password change. Sits inside `auth` but outside
// `password.changed`, which is the middleware that redirects here.
Route::middleware('auth')->group(function () {
    Route::get('/password/change', [AuthController::class, 'showPasswordChange'])->name('auth.password.change');
    Route::post('/password/change', [AuthController::class, 'updatePassword'])->name('auth.password.update');
});

// The signed-in account's own details, reachable from every header.
//
// Every endpoint acts on the signed-in account and takes no id, which is the
// whole of "a user may edit only their own profile" - there is no other
// profile these routes can reach.
Route::middleware(['auth', 'password.changed'])
    ->prefix('profile')
    ->name('profile.')
    ->group(function () {
        Route::get('/', [ProfileController::class, 'edit'])->name('edit');

        // POST rather than PUT for the picture: it is multipart, which PHP
        // does not parse from a PUT body.
        Route::post('/photo', [ProfileController::class, 'updatePhoto'])->name('photo.update');
        Route::delete('/photo', [ProfileController::class, 'destroyPhoto'])->name('photo.destroy');

        Route::put('/information', [ProfileController::class, 'updateInformation'])->name('information');
        Route::put('/password', [ProfileController::class, 'updatePassword'])->name('password');

        // Changing the address you sign in with. The new one is parked on the
        // account until a code sent to it comes back, so a typo cannot lock
        // somebody out of their own account - see ProfileService.
        Route::post('/email/verify', [ProfileController::class, 'verifyEmailChange'])
            ->middleware('throttle:10,1')
            ->name('email.verify');
        Route::post('/email/resend', [ProfileController::class, 'resendEmailChange'])
            ->middleware('throttle:6,1')
            ->name('email.resend');
        Route::delete('/email', [ProfileController::class, 'cancelEmailChange'])->name('email.cancel');

        // A technician asking for different specialties. The change itself is
        // an administrator's to make - see the Technicians page.
        Route::post('/specialties', [ProfileController::class, 'requestSpecialties'])
            ->middleware('role:technician,lead_technician')
            ->name('specialties.request');
    });

// Notifications. Shared by every portal rather than duplicated per role: a
// notification is addressed to a person, and "mine" is the whole of the
// visibility rule, so one set of routes serves all of them.
Route::middleware(['auth', 'password.changed'])
    ->prefix('notifications')
    ->name('notifications.')
    ->group(function () {
        Route::get('/', [NotificationController::class, 'index'])->name('index');
        Route::get('/feed', [NotificationController::class, 'feed'])->name('feed');
        Route::get('/list', [NotificationController::class, 'list'])->name('list');
        Route::get('/{notification}/open', [NotificationController::class, 'open'])->name('open');
        Route::put('/{notification}/read', [NotificationController::class, 'markAsRead'])->name('read');
        Route::put('/read-all', [NotificationController::class, 'markAllAsRead'])->name('read-all');
        Route::delete('/{notification}', [NotificationController::class, 'destroy'])->name('destroy');
    });

// Super Admin routes. Admin shares this portal entirely - same sidebar, same
// pages - so both roles are admitted here.
Route::prefix('super-admin')
    ->name('super-admin.')
    ->middleware(['auth', 'password.changed', 'role:super_admin,admin'])
    ->group(function () {

        // The landing page after sign-in. Everything it shows arrives with the
        // page; `summary` re-reads only the figures, for a refresh without a
        // reload.
        Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
        Route::get('/dashboard/summary', [DashboardController::class, 'summary'])->name('dashboard.summary');

        // ROUTE FOR SUPER ADMIN PROJECTS PAGE
        Route::get('/projects', [ProjectController::class, 'index'])->name('projects');

        // Archiving is a Super Admin privilege. An Admin creates, reads,
        // edits, activates and deactivates; taking a record out of the active
        // system - and putting it back - belongs to the system's owner.
        //
        // Declared here rather than in a group of its own so /projects/archived
        // keeps being matched before /projects/{id}.
        Route::get('/projects/archived', [ProjectController::class, 'archivedIndex'])
            ->middleware('role:super_admin')
            ->name('projects.archived');
        Route::get('/projects/create', [ProjectController::class, 'create'])->name('projects.create');
        Route::post('/projects/create', [ProjectController::class, 'store'])->name('projects.create.store');
        Route::get('/projects/{id}', [ProjectController::class, 'show'])->name('projects.show');
        Route::put('/projects/{id}/hold', [ProjectController::class, 'putOnHold'])->name('projects.hold');
        Route::put('/projects/{id}/resume', [ProjectController::class, 'resume'])->name('projects.resume');
        Route::post('/projects/{id}/complete', [ProjectController::class, 'complete'])->name('projects.complete');
        Route::post('/projects/{id}/cancel', [ProjectController::class, 'cancel'])->name('projects.cancel');
        Route::post('/projects/{id}/archive', [ProjectController::class, 'archive'])
            ->middleware('role:super_admin')
            ->name('projects.archive');
        Route::put('/projects/{id}/restore', [ProjectController::class, 'restore'])
            ->middleware('role:super_admin')
            ->name('projects.restore');
        Route::get('/projects/{id}/documents/{type}', [ProjectController::class, 'previewDocument'])->name('projects.documents.preview');
        Route::put('/projects/{id}', [ProjectController::class, 'update'])->name('projects.update');
        Route::put('/projects/{id}/team', [ProjectController::class, 'updateAssignedTeam'])->name('projects.team.update');
        Route::post('/projects/{id}/reports', [TechnicianReportController::class, 'store'])->name('technician.reports.store');
        Route::post('/projects/{id}/task', [TaskController::class, 'store'])->name('task.store');
        Route::patch('/tasks/{task}/complete', [TaskController::class, 'complete'])->name('tasks.complete');
        Route::put('/tasks/{task}', [TaskController::class, 'update'])->name('tasks.update');
        Route::delete('/tasks/{task}', [TaskController::class, 'destroy'])->name('tasks.destroy');

        // ROUTE FOR SUPER ADMIN SCHEDULES PAGE
        Route::get('/schedules', [ScheduleController::class, 'index'])->name('schedules.index');
        Route::get('/schedules/assignable', [ScheduleController::class, 'assignableProjects'])->name('schedules.assignable');
        Route::post('/schedules/assign', [ScheduleController::class, 'assign'])->name('schedules.assign');
        Route::get('/schedules/date/{date}', [ScheduleController::class, 'dateDetails'])->name('schedules.date');

        // Taking a single date off one booking, from the panel that opens
        // when a calendar date is clicked. Declared before /schedules/{id} so
        // the longer path is matched first.
        Route::delete('/schedules/{schedule}/dates/{date}', [ScheduleController::class, 'removeDate'])
            ->whereNumber('schedule')
            ->name('schedules.dates.destroy');
        Route::put('/schedules/{id}', [ScheduleController::class, 'update'])->name('schedules.update');

        // ROUTE FOR SUPER ADMIN REPORTS PAGE
        Route::get('/reports', [ReportController::class, 'index'])->name('reports.index');
        Route::get('/reports/technician-reports', [ReportController::class, 'technicianReports'])->name('reports.technician');
        Route::get('/reports/technician-reports/{report}', [ReportController::class, 'showTechnicianReport'])->name('reports.technician.show');
        Route::get('/reports/reportable-projects', [ReportController::class, 'reportableProjects'])->name('reports.reportable');
        Route::get('/reports/system', [ReportController::class, 'systemReports'])->name('reports.system');
        Route::get('/reports/system/chart', [ReportController::class, 'systemChart'])->name('reports.system.chart');
        Route::post('/reports/export', [ReportController::class, 'export'])->name('reports.export');

        // ROUTE FOR SUPER ADMIN TECHNICIANS PAGE
        Route::get('/technicians', [TechnicianController::class, 'index'])->name('technicians.index');
        // The specialty approval queue. Declared before /technicians/{technician}
        // so the literal segment wins.
        Route::put('/technicians/specialty-requests/{specialtyRequest}/approve', [TechnicianController::class, 'approveSpecialtyRequest'])
            ->name('technicians.specialty-requests.approve');
        Route::put('/technicians/specialty-requests/{specialtyRequest}/reject', [TechnicianController::class, 'rejectSpecialtyRequest'])
            ->name('technicians.specialty-requests.reject');

        Route::get('/technicians/{technician}', [TechnicianController::class, 'show'])->name('technicians.show');
        Route::put('/technicians/{technician}/specialties', [TechnicianController::class, 'syncSpecialties'])->name('technicians.specialties.sync');
        Route::get('/technicians/{technician}/calendar', [TechnicianController::class, 'calendar'])->name('technicians.calendar');
        Route::get('/technicians/{technician}/assignable-projects', [TechnicianController::class, 'assignableProjects'])->name('technicians.assignable');
        Route::post('/technicians/{technician}/projects', [TechnicianController::class, 'assignToProjects'])->name('technicians.projects.store');
        Route::get('/technicians/{technician}/projects/{project}', [TechnicianController::class, 'assignment'])->name('technicians.assignment');
        Route::delete('/technicians/{technician}/projects/{project}', [TechnicianController::class, 'removeFromProject'])->name('technicians.projects.destroy');

        // ROUTE FOR SUPER ADMIN TASKS PAGE
        Route::get('/tasks', [TaskController::class, 'index'])->name('tasks.index');
        Route::get('/projects/{id}/task-form-data', [TaskController::class, 'projectFormData'])->name('projects.task-form-data');

        // ROUTE FOR SUPER ADMIN CONFIGURATION PAGE
        Route::prefix('configuration')->name('configuration.')->group(function () {
            Route::get('/', [ConfigurationController::class, 'index'])->name('index');

            // User Management tables.
            Route::get('/users/employees', [ConfigurationController::class, 'employees'])->name('users.employees');
            Route::get('/users/clients', [ConfigurationController::class, 'clients'])->name('users.clients');
            Route::get('/users/generated-password', [ConfigurationController::class, 'generatePassword'])->name('users.password');

            // Archived Accounts. Declared before /users/{user} so the literal
            // segment wins, and Super Admin only for the same reason project
            // archiving is.
            Route::get('/users/archived', [ConfigurationController::class, 'archivedAccounts'])
                ->middleware('role:super_admin')
                ->name('users.archived');

            // Activity Logs.
            Route::get('/activity-logs', [ConfigurationController::class, 'activityLogs'])->name('activity-logs');

            // System Contents: the public website's text and images. Admin
            // reaches this prefix but not these endpoints - the controller
            // turns anyone who is not a Super Admin away.
            Route::prefix('contents')->name('contents.')->group(function () {
                Route::get('/{section}', [SystemContentController::class, 'show'])->name('show');
                Route::put('/{section}', [SystemContentController::class, 'update'])->name('update');
                Route::post('/images/{key}', [SystemContentController::class, 'storeImage'])->name('images.store');
                Route::delete('/images/{key}', [SystemContentController::class, 'destroyImage'])->name('images.destroy');
            });

            // Creation.
            Route::post('/users/employees', [ConfigurationController::class, 'storeEmployee'])->name('users.employees.store');
            Route::post('/users/clients', [ConfigurationController::class, 'storeClient'])->name('users.clients.store');

            // A single account, then the actions available on it. POST rather
            // than PUT for the updates: both carry a multipart profile picture,
            // which PHP does not parse from a PUT body.
            Route::get('/users/{user}', [ConfigurationController::class, 'show'])->name('users.show');
            Route::post('/users/{user}/employee', [ConfigurationController::class, 'updateEmployee'])->name('users.employees.update');
            Route::post('/users/{user}/client', [ConfigurationController::class, 'updateClient'])->name('users.clients.update');
            Route::put('/users/{user}/password', [ConfigurationController::class, 'resetPassword'])->name('users.password.reset');
            Route::put('/users/{user}/status', [ConfigurationController::class, 'setStatus'])->name('users.status');

            Route::middleware('role:super_admin')->group(function () {
                Route::delete('/users/{user}', [ConfigurationController::class, 'archive'])->name('users.archive');
                Route::put('/users/{user}/restore', [ConfigurationController::class, 'restoreAccount'])->name('users.restore');
            });
        });
    });

// Technician portal. Both technician roles share every page here: the same
// calendar, the same DataTables, the same task board. What differs is reach,
// and that is decided by ProjectPolicy / TaskPolicy inside the pages rather
// than by giving the two roles separate screens.
//
// A plain technician reads everything and completes their own tasks. A lead
// additionally runs the task board, files reports and closes projects.
Route::prefix('technician')
    ->name('technician.')
    ->middleware(['auth', 'password.changed', 'role:technician,lead_technician'])
    ->group(function () {
        Route::get('/schedule', [TechnicianPortalController::class, 'schedule'])->name('schedule');
        Route::get('/projects', [TechnicianPortalController::class, 'projects'])->name('projects');
        Route::get('/tasks', [TechnicianPortalController::class, 'tasks'])->name('tasks');

        // Reading a project, and closing your own task. Each is gated by a
        // policy: you only ever see a project you are assigned to, and you
        // only complete a task that is yours (or, for a lead, one on a project
        // they run).
        Route::get('/projects/{project}', [TechnicianPortalController::class, 'showProject'])
            ->name('projects.show');
        Route::get('/projects/{project}/details', [TechnicianPortalController::class, 'projectDetails'])
            ->name('projects.details');
        Route::post('/tasks/{task}/complete', [TechnicianPortalController::class, 'completeTask'])
            ->name('tasks.complete');

        // Lead-only. Each one is additionally gated by a policy, so the
        // middleware here is the outer fence rather than the whole of the
        // permission model.
        Route::middleware('role:lead_technician')->group(function () {
            Route::get('/reports', [TechnicianPortalController::class, 'reports'])->name('reports');

            Route::post('/projects/{project}/complete', [TechnicianPortalController::class, 'completeProject'])
                ->name('projects.complete');
            Route::get('/projects/{project}/task-form-data', [TechnicianPortalController::class, 'taskFormData'])
                ->name('projects.task-form-data');
            Route::post('/projects/{project}/tasks', [TechnicianPortalController::class, 'storeTask'])
                ->name('tasks.store');
            Route::post('/projects/{project}/reports', [TechnicianPortalController::class, 'storeReport'])
                ->name('reports.store');
            Route::post('/tasks/{task}', [TechnicianPortalController::class, 'updateTask'])
                ->name('tasks.update');
            Route::delete('/tasks/{task}', [TechnicianPortalController::class, 'destroyTask'])
                ->name('tasks.destroy');
        });
    });

// A client has no portal of their own. Signing in lands them on the public
// website, and My Projects there shows the work booked under their email -
// see PublicSiteController.
