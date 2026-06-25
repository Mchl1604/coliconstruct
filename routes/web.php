<?php

use Illuminate\Support\Facades\Route;

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

        Route::get('/projects', function () {
            return view('super-admin.projects');
        })->name('projects');

        Route::get('/projects/create/clientInfo', function () {
            return view('super-admin.createProject.clientInfo');
        })->name('projects.create');

    });