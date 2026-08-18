<?php

namespace App\Http\Middleware;

use App\Enums\RoleName;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates a Filament panel behind the role that matches it. Registered on each
 * panel's authMiddleware, so it runs after Filament has authenticated the
 * request but before any panel page renders.
 */
class EnsurePanelRole
{
    public function handle(Request $request, Closure $next, string $role): Response
    {
        $user = $request->user();
        $required = RoleName::tryFrom($role);

        abort_if($required === null, 500, 'Unknown panel role: '.$role);
        abort_if($user === null, 403);
        abort_if($user->isSuspended(), 403, __('This account is suspended.'));
        abort_unless($user->holdsRole($required), 403);

        return $next($request);
    }
}
