<?php

namespace App\Http\Controllers;

use App\Helpers\ResponseHelper;
use App\Models\User;
use App\Services\MfaService;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Spatie\Permission\Middleware\PermissionMiddleware;

class MfaController extends Controller implements HasMiddleware
{
    public function __construct(
        private MfaService $mfaService
    ) {}

    public static function middleware(): array
    {
        return [
            new Middleware(PermissionMiddleware::using(['mfa.reset-user']), only: ['resetUser']),
        ];
    }

    /**
     * GET /api/v1/mfa/status
     */
    public function status(Request $request)
    {
        $user = $request->user();

        return ResponseHelper::jsonResponse(true, 'MFA status retrieved successfully', [
            'mfa_enabled' => $user->hasMfaEnabled(),
            'mfa_required_by_role' => $user->requiresMfa(),
            'mfa_global_enabled' => $this->mfaService->isGloballyEnabled(),
            'recovery_codes_remaining' => is_array($user->mfa_recovery_codes)
                ? count($user->mfa_recovery_codes)
                : 0,
        ], 200);
    }

    /**
     * POST /api/v1/mfa/setup
     */
    public function setup(Request $request)
    {
        if (! $this->mfaService->isGloballyEnabled()) {
            return ResponseHelper::jsonResponse(false, 'MFA is currently disabled by system configuration.', null, 403);
        }

        try {
            $setupData = $this->mfaService->generateSetup($request->user());

            return ResponseHelper::jsonResponse(true, 'MFA setup initialized successfully', $setupData, 200);
        } catch (\Throwable $e) {
            return ResponseHelper::jsonResponse(false, 'Failed to initialize MFA setup: '.$e->getMessage(), null, 500);
        }
    }

    /**
     * POST /api/v1/mfa/enable
     */
    public function enable(Request $request)
    {
        if (! $this->mfaService->isGloballyEnabled()) {
            return ResponseHelper::jsonResponse(false, 'MFA is currently disabled by system configuration.', null, 403);
        }

        $validated = $request->validate([
            'code' => ['required', 'string', 'digits:6'],
        ]);

        try {
            $recoveryCodes = $this->mfaService->enable($request->user(), $validated['code'], $request);

            return ResponseHelper::jsonResponse(true, 'MFA enabled successfully', [
                'mfa_enabled' => true,
                'recovery_codes' => $recoveryCodes,
            ], 200);
        } catch (\InvalidArgumentException $e) {
            return ResponseHelper::jsonResponse(false, $e->getMessage(), null, 422);
        } catch (\Throwable $e) {
            return ResponseHelper::jsonResponse(false, 'Failed to enable MFA: '.$e->getMessage(), null, 500);
        }
    }

    /**
     * POST /api/v1/mfa/disable
     */
    public function disable(Request $request)
    {
        $validated = $request->validate([
            'password' => ['required', 'string'],
            'code' => ['required', 'string', 'digits:6'],
        ]);

        try {
            $this->mfaService->disable(
                $request->user(),
                $validated['password'],
                $validated['code'],
                $request
            );

            return ResponseHelper::jsonResponse(true, 'MFA disabled successfully', [
                'mfa_enabled' => false,
            ], 200);
        } catch (\RuntimeException $e) {
            return ResponseHelper::jsonResponse(false, $e->getMessage(), null, $e->getCode() ?: 403);
        } catch (\InvalidArgumentException $e) {
            return ResponseHelper::jsonResponse(false, $e->getMessage(), null, 422);
        } catch (\Throwable $e) {
            return ResponseHelper::jsonResponse(false, 'Failed to disable MFA: '.$e->getMessage(), null, 500);
        }
    }

    /**
     * POST /api/v1/mfa/recovery-codes/regenerate
     */
    public function regenerateRecoveryCodes(Request $request)
    {
        $validated = $request->validate([
            'password' => ['required', 'string'],
            'code' => ['required', 'string', 'digits:6'],
        ]);

        try {
            $recoveryCodes = $this->mfaService->regenerateRecoveryCodes(
                $request->user(),
                $validated['password'],
                $validated['code']
            );

            return ResponseHelper::jsonResponse(true, 'Recovery codes regenerated successfully', [
                'recovery_codes' => $recoveryCodes,
            ], 200);
        } catch (\InvalidArgumentException $e) {
            return ResponseHelper::jsonResponse(false, $e->getMessage(), null, 422);
        } catch (\Throwable $e) {
            return ResponseHelper::jsonResponse(false, 'Failed to regenerate recovery codes: '.$e->getMessage(), null, 500);
        }
    }

    /**
     * POST /api/v1/users/{user}/mfa/reset (Admin only)
     */
    public function resetUser(Request $request, User $user)
    {
        try {
            $this->mfaService->resetForUser($user, $request->user(), $request, 'admin_api');

            return ResponseHelper::jsonResponse(true, "MFA has been reset for {$user->name}.", [
                'user_id' => $user->id,
                'mfa_enabled' => false,
            ], 200);
        } catch (\Throwable $e) {
            return ResponseHelper::jsonResponse(false, 'Failed to reset user MFA: '.$e->getMessage(), null, 500);
        }
    }
}
