<?php

namespace App\Http\Middleware;

use App\Helpers\ResponseHelper;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsActive
{
    public const DEACTIVATED_MESSAGE = 'Your account has been deactivated. Please contact HR.';

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user) {
            return $next($request);
        }

        if ($user->is_active === false || $user->trashed()) {
            // Revoke all Sanctum tokens of the deactivated user
            $user->tokens()->delete();

            return ResponseHelper::jsonResponse(
                false,
                self::DEACTIVATED_MESSAGE,
                null,
                403
            );
        }

        return $next($request);
    }
}
