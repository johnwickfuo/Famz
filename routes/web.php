<?php

use App\Http\Controllers\CartController;
use App\Http\Controllers\CatalogueController;
use App\Http\Controllers\CheckoutController;
use App\Http\Controllers\DisputeController;
use App\Http\Controllers\OrderController;
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

// The cart works for guests too; it moves into their account when they sign in.
Route::get('/cart', [CartController::class, 'index'])->name('cart.index');
Route::post('/cart', [CartController::class, 'store'])->name('cart.store');
Route::patch('/cart', [CartController::class, 'update'])->name('cart.update');
Route::delete('/cart', [CartController::class, 'destroy'])->name('cart.destroy');

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

    // Checkout. The callback changes nothing — see CheckoutController::callback.
    Route::get('/checkout', [CheckoutController::class, 'show'])->name('checkout.show');
    Route::post('/checkout', [CheckoutController::class, 'store'])->name('checkout.store');
    Route::get('/checkout/callback', [CheckoutController::class, 'callback'])->name('checkout.callback');
    Route::get('/checkout/{order}/status', [CheckoutController::class, 'status'])->name('checkout.status');
    // An order that was never paid for is not a dead end.
    Route::post('/checkout/{order}/pay', [CheckoutController::class, 'pay'])->name('checkout.pay');

    Route::get('/orders', [OrderController::class, 'index'])->name('orders.index');
    Route::get('/orders/{order}', [OrderController::class, 'show'])->name('orders.show');
    Route::post('/orders/parts/{subOrder}/received', [OrderController::class, 'markReceived'])
        ->name('orders.received');

    /*
     * Disputes. Raised against one seller's part of an order, because that is
     * the unit the money is held in.
     */
    Route::get('/disputes', [DisputeController::class, 'index'])->name('disputes.index');
    Route::get('/orders/parts/{subOrder}/dispute', [DisputeController::class, 'create'])
        ->name('disputes.create');
    Route::post('/orders/parts/{subOrder}/dispute', [DisputeController::class, 'store'])
        ->name('disputes.store');
    Route::get('/disputes/{dispute}', [DisputeController::class, 'show'])->name('disputes.show');
    Route::post('/disputes/{dispute}/reply', [DisputeController::class, 'reply'])->name('disputes.reply');
});

require __DIR__.'/auth.php';
