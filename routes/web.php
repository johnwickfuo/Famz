<?php

use App\Http\Controllers\CatalogueController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\PublicPageController;
use App\Http\Controllers\SellerApplicationController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', [PublicPageController::class, 'home'])->name('home');
Route::get('/s/{section}', [PublicPageController::class, 'section'])->name('sections.show');

// The catalogue.
Route::get('/market', [CatalogueController::class, 'home'])->name('catalogue.home');
Route::get('/search', [CatalogueController::class, 'search'])->name('search');
Route::get('/category/{category}', [CatalogueController::class, 'category'])->name('catalogue.category');
// Storefronts live under /store: /seller is the Filament seller panel, and a
// seller slug could otherwise collide with one of its routes.
Route::get('/store/{seller}', [CatalogueController::class, 'storefront'])->name('catalogue.storefront');
Route::get('/product/{product}', [ProductController::class, 'show'])->name('catalogue.product');

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
