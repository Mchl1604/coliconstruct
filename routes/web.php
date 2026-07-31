<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\TechnicianReportController;
use App\Http\Controllers\TaskController;
use App\Http\Controllers\ScheduleController;
use App\Http\Controllers\TechnicianController;

// Public routes
Route::get('/', function () {
    return view('landing.home');
})->name('landing.home');

Route::get('/login', function () {
    return view('auth.login');
})->name('auth.login');

// Super Admin routes
Route::prefix('super-admin')
    ->name('super-admin.')
    ->group(function () {

        Route::get('/dashboard', function () {
            return view('super-admin.dashboard');
        })->name('dashboard');

        //ROUTE FOR SUPER ADMIN PROJECTS PAGE
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
        Route::put('/projects/{id}/team',[ProjectController::class, 'updateAssignedTeam'])->name('projects.team.update');
        Route::post('/projects/{id}/reports', [TechnicianReportController::class, 'store'])->name('technician.reports.store');
        Route::post('/projects/{id}/task', [TaskController::class, 'store'])->name('task.store');
        Route::patch('/tasks/{task}/complete',[TaskController::class, 'complete'])->name('tasks.complete');
        Route::put('/tasks/{task}',[TaskController::class, 'update'])->name('tasks.update');Route::delete('/tasks/{task}',[TaskController::class, 'destroy'])->name('tasks.destroy');

        //ROUTE FOR SUPER ADMIN SCHEDULES PAGE
        Route::get('/schedules', [ScheduleController::class, 'index'])->name('schedules.index');
        Route::get('/schedules/assignable', [ScheduleController::class, 'assignableProjects'])->name('schedules.assignable');
        Route::post('/schedules/assign', [ScheduleController::class, 'assign'])->name('schedules.assign');
        Route::get('/schedules/date/{date}', [ScheduleController::class, 'dateDetails'])->name('schedules.date');
        Route::put('/schedules/{id}', [ScheduleController::class, 'update'])->name('schedules.update');

        //ROUTE FOR SUPER ADMIN TECHNICIANS PAGE
        Route::get('/technicians', [TechnicianController::class, 'index'])->name('technicians.index');
        Route::get('/technicians/{technician}', [TechnicianController::class, 'show'])->name('technicians.show');
        Route::post('/technicians/{technician}/specialties', [TechnicianController::class, 'addSpecialties'])->name('technicians.specialties.store');
        Route::delete('/technicians/{technician}/specialties/{skill}', [TechnicianController::class, 'removeSpecialty'])->name('technicians.specialties.destroy');
        Route::get('/technicians/{technician}/calendar', [TechnicianController::class, 'calendar'])->name('technicians.calendar');
        Route::get('/technicians/{technician}/assignable-projects', [TechnicianController::class, 'assignableProjects'])->name('technicians.assignable');
        Route::post('/technicians/{technician}/projects', [TechnicianController::class, 'assignToProjects'])->name('technicians.projects.store');
        Route::get('/technicians/{technician}/projects/{project}', [TechnicianController::class, 'assignment'])->name('technicians.assignment');
        Route::delete('/technicians/{technician}/projects/{project}', [TechnicianController::class, 'removeFromProject'])->name('technicians.projects.destroy');

        //ROUTE FOR SUPER ADMIN TASKS PAGE
        Route::get('/tasks', [TaskController::class, 'index'])->name('tasks.index');
Route::get('/projects/{id}/task-form-data', [TaskController::class, 'projectFormData'])->name('projects.task-form-data');
    });