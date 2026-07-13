<?php

use Illuminate\Support\Facades\Route;

Route::redirect('/', '/admin')->name('home');

// Admin panel (Inertia + React + Polaris). Fortify serves /admin/login and
// /admin/logout on the "admin" guard (the app default) — see config/fortify.php.
Route::middleware(['auth'])->prefix('admin')->group(function () {
    Route::inertia('/', 'admin/dashboard')->name('dashboard');
});
