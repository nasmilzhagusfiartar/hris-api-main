<?php

namespace App\Http\Middleware;

use App\Helpers\ResponseHelper;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureMfaEnrolled
{
    /**
     * Block access to protected endpoints if user's role requires MFA
     * and MFA has not been enrolled yet (except /me, /logout, /mfa/*).
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('mfa.enabled', true)) {
            return $next($request);
        }

        $user = $request->user();
        if (! $user) {
            return $next($request);
        }

        if ($user->requiresMfa() && ! $user->hasMfaEnabled()) {
            return ResponseHelper::jsonResponse(
                false,
                'MFA setup is required for your role before accessing this resource.',
                ['mfa_setup_required' => true],
                403
            );
        }

        return $next($request);
    }
}
