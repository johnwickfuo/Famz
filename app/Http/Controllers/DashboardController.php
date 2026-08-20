<?php

namespace App\Http\Controllers;

use App\Services\Platform\DashboardService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The signed-in home page.
 *
 * Adapts to what somebody actually does here. The assembly lives in
 * DashboardService so that "which sections does this person see" is one
 * testable question rather than a controller full of conditionals.
 */
class DashboardController extends Controller
{
    public function __construct(private readonly DashboardService $dashboard) {}

    public function __invoke(Request $request): Response
    {
        return Inertia::render('Dashboard', $this->dashboard->for($request->user()));
    }
}
