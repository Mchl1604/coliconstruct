<?php

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\ClientPortalController;
use App\Http\Controllers\ConfigurationController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\ScheduleController;
use App\Http\Controllers\TaskController;
use App\Http\Controllers\TechnicianController;
use App\Http\Controllers\TechnicianPortalController;
use App\Http\Controllers\TechnicianReportController;
use Illuminate\Support\Facades\Route;

// Public routes
Route::get('/', function () {
    return view('landing.home');
})->name('landing.home');

// Authentication
Route::get('/login', [AuthController::class, 'showLogin'])->name('auth.login');
Route::post('/login', [AuthController::class, 'login'])->name('auth.login.attempt');
Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth')->name('auth.logout');

// Public registration, which only ever opens a client account. Every employee
// account is created by an admin or super admin in Configuration.
Route::get('/register', [AuthController::class, 'showRegister'])->name('auth.register');
Route::post('/register', [AuthController::class, 'register'])->name('auth.register.store');

// The forced first password change. Sits inside `auth` but outside
// `password.changed`, which is the middleware that redirects here.
Route::middleware('auth')->group(function () {
    Route::get('/password/change', [AuthController::class, 'showPasswordChange'])->name('auth.password.change');
    Route::post('/password/change', [AuthController::class, 'updatePassword'])->name('auth.password.update');
});

// Super Admin routes. Admin shares this portal entirely - same sidebar, same
// pages - so both roles are admitted here.
Route::prefix('super-admin')
    ->name('super-admin.')
    ->middleware(['auth', 'password.changed', 'role:super_admin,admin'])
    ->group(function () {

        Route::get('/dashboard', function () {
            return view('super-admin.dashboard');
        })->name('dashboard');

        // ROUTE FOR SUPER ADMIN PROJECTS PAGE
        Route::get('/projects', [ProjectController::class, 'index'])->name('projects');
        Route::get('/projects/archived', [ProjectController::class, 'archivedIndex'])->name('projects.archived');
        Route::get('/projects/create', [ProjectController::class, 'create'])->name('projects.create');
        Route::post('/projects/create', [ProjectController::class, 'store'])->name('projects.create.store');
        Route::get('/projects/{id}', [ProjectController::class, 'show'])->name('projects.show');
        Route::put('/projects/{id}/hold', [ProjectController::class, 'putOnHold'])->name('projects.hold');
        Route::put('/projects/{id}/resume', [ProjectController::class, 'resume'])->name('projects.resume');
        Route::post('/projects/{id}/complete', [ProjectController::class, 'complete'])->name('projects.complete');
        Route::post('/projects/{id}/cancel', [ProjectController::class, 'cancel'])->name('projects.cancel');
        Route::post('/projects/{id}/archive', [ProjectController::class, 'archive'])->name('projects.archive');
        Route::put('/projects/{id}/restore', [ProjectController::class, 'restore'])->name('projects.restore');
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
            Route::delete('/users/{user}', [ConfigurationController::class, 'archive'])->name('users.archive');
        });
    });

// Technician portal. Both technician roles share it; My Reports is the one
// page a lead has and a technician does not.
Route::prefix('technician')
    ->name('technician.')
    ->middleware(['auth', 'password.changed', 'role:technician,lead_technician'])
    ->group(function () {
        Route::get('/schedule', [TechnicianPortalController::class, 'schedule'])->name('schedule');
        Route::get('/projects', [TechnicianPortalController::class, 'projects'])->name('projects');
        Route::get('/tasks', [TechnicianPortalController::class, 'tasks'])->name('tasks');

        Route::get('/reports', [TechnicianPortalController::class, 'reports'])
            ->middleware('role:lead_technician')
            ->name('reports');
    });

// Client portal
Route::prefix('client')
    ->name('client.')
    ->middleware(['auth', 'password.changed', 'role:client'])
    ->group(function () {
        Route::get('/dashboard', [ClientPortalController::class, 'dashboard'])->name('dashboard');
    });
