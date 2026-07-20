<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\TechnicianReportController;
use App\Http\Controllers\TaskController;

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

        Route::get('/projects', [ProjectController::class, 'index'])->name('projects');

        Route::get('/projects/create', [ProjectController::class, 'create'])->name('projects.create');
        Route::post('/projects/create', [ProjectController::class, 'store'])->name('projects.create.store');
        Route::get('/projects/{id}', [ProjectController::class, 'show'])->name('projects.show');
        Route::get('/projects/{id}/documents/{type}', [ProjectController::class, 'previewDocument'])->name('projects.documents.preview');
        Route::put('/projects/{id}', [ProjectController::class, 'update'])->name('projects.update');
        Route::post('/projects/{id}/reports', [TechnicianReportController::class, 'store'])
        ->name('technician.reports.store');

        Route::post('/projects/{id}/task', [TaskController::class, 'store'])
    ->name('task.store');
    Route::patch('/tasks/{task}/complete',
    [TaskController::class, 'complete'])
    ->name('tasks.complete');

Route::put('/tasks/{task}',
    [TaskController::class, 'update'])
    ->name('tasks.update');

Route::delete('/tasks/{task}',
    [TaskController::class, 'destroy'])
    ->name('tasks.destroy');

    });
