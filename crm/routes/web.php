<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\ActivityController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\FollowUpController;
use App\Http\Controllers\LeadController;
use App\Http\Controllers\LeadManagementController;
use App\Http\Controllers\StatsController;
use Illuminate\Support\Facades\Route;

// Redirección raíz
Route::get('/', function () {
    return redirect()->route('dashboard');
});

// Autenticación Google OAuth2 / OpenID Connect
Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
Route::get('/auth/google', [AuthController::class, 'googleRedirect'])->name('auth.google');
Route::get('/auth/google/callback', [AuthController::class, 'googleCallback'])->name('auth.google.callback');
Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

// Rutas protegidas para usuarios autenticados y autorizados
Route::middleware(['auth', 'auth.crm'])->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    // Leads y ficha de prospecto
    Route::get('/leads', [LeadController::class, 'index'])->name('leads.index');
    Route::get('/leads/{id}', [LeadController::class, 'show'])->name('leads.show')->whereNumber('id');
    Route::post('/leads/{id}/status', [LeadManagementController::class, 'updateStatus'])->name('leads.status')->whereNumber('id');
    Route::post('/leads/{id}/assign', [LeadManagementController::class, 'assignAdvisor'])->name('leads.assign')->whereNumber('id');

    // Gestiones (llamadas, notas, reuniones)
    Route::post('/leads/{id}/activities', [ActivityController::class, 'store'])->name('activities.store')->whereNumber('id');

    // Agenda de seguimientos
    Route::get('/follow-ups', [FollowUpController::class, 'index'])->name('follow_ups.index');
    Route::post('/leads/{id}/follow-ups', [FollowUpController::class, 'store'])->name('follow_ups.store')->whereNumber('id');
    Route::post('/follow-ups/{id}/complete', [FollowUpController::class, 'complete'])->name('follow_ups.complete')->whereNumber('id');

    // Estadísticas calculadas con datos reales
    Route::get('/stats', [StatsController::class, 'index'])->name('stats.index');

    // Panel exclusivo de Administración
    Route::middleware(['role.admin'])->prefix('admin')->name('admin.')->group(function () {
        Route::get('/', [AdminController::class, 'index'])->name('index');
        Route::post('/users', [AdminController::class, 'createUser'])->name('users.create');
        Route::post('/users/{id}/toggle', [AdminController::class, 'toggleUserStatus'])->name('users.toggle')->whereNumber('id');
        Route::post('/sync', [AdminController::class, 'triggerSync'])->name('sync');
    });
});
