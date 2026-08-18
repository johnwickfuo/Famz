<?php

use App\Http\Controllers\ProfileController;
use App\Http\Controllers\PublicPageController;
use App\Http\Controllers\SellerApplicationController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', [PublicPageController::class, 'home'])->name('home');
Route::get('/search', [PublicPageController::class, 'search'])->name('search');
Route::get('/s/{section}', [PublicPageController::class, 'section'])->name('sections.show');

Route::get('/dashboard', fn () => Inertia::render('Dashboard'))
    ->middleware(['auth', 'verified'])
    ->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    // Applying to sell. Anyone with an account may apply; an administrator decides.
    Route::get('/sell', [SellerApplicationController::class, 'create'])->name('seller-application.create');
    Route::post('/sell', [SellerApplicationController::class, 'store'])->name('seller-application.store');
});

require __DIR__.'/auth.php';
