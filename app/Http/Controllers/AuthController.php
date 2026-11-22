<?php

namespace App\Http\Controllers;

use App\Exceptions\MfaVerificationException;
use App\Helpers\ResponseHelper;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Requests\LoginStoreRequest;
use App\Http\Resources\UserResource;
use App\Interfaces\AuthRepositoryInterface;
use App\Models\User;
use App\Services\MfaService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    private AuthRepositoryInterface $authRepository;

    private MfaService $mfaService;

    public function __construct(
        AuthRepositoryInterface $authRepository,
        MfaService $mfaService
    ) {
        $this->authRepository = $authRepository;
        $this->mfaService = $mfaService;
    }

    public function login(LoginStoreRequest $request)
    {
        $credentials = $request->validated();

        try {
            $user = User::with(['roles', 'permissions'])
                ->where('email', $credentials['email'])
                ->first();

            if (! $user || ! Hash::check($credentials['password'], $user->password)) {
                return ResponseHelper::jsonResponse(false, 'Unauthorized', null, 401);
            }

            if ($user->is_active === false) {
                return ResponseHelper::jsonResponse(false, EnsureUserIsActive::DEACTIVATED_MESSAGE, null, 403);
            }

            // If MFA is globally enabled, enforce lockout and TOTP challenge
            if ($this->mfaService->isGloballyEnabled()) {
                $lockoutRemaining = $this->mfaService->getLockoutRemainingMinutes($user->id);
                if ($lockoutRemaining > 0) {
                    return ResponseHelper::jsonResponse(
                        false,
                        "Account is temporarily locked due to too many failed MFA attempts. Try again in {$lockoutRemaining} minute(s).",
                        [
                            'locked' => true,
                            'lockout_minutes' => $lockoutRemaining,
                        ],
                        423
                    );
                }

                if ($user->hasMfaEnabled()) {
                    $mfaToken = $this->mfaService->createLoginChallenge($user);

                    return ResponseHelper::jsonResponse(
                        true,
                        'MFA verification required',
                        [
                            'mfa_required' => true,
                            'mfa_token' => $mfaToken,
                        ],
                        200
                    );
                }
            }

            if ($user->hasRole('employee')) {
                $user->load('employeeProfile');
            }
            $user->token = $user->createToken('auth_token')->plainTextToken;

            return ResponseHelper::jsonResponse(true, 'Login Successful', new UserResource($user), 200);
        } catch (\Illuminate\Database\QueryException|\PDOException $e) {
            return ResponseHelper::serverError($e);
        } catch (\Exception $e) {
            $code = (int) $e->getCode();
            if ($code >= 400 && $code < 500) {
                return ResponseHelper::jsonResponse(false, $e->getMessage(), null, $code);
            }

            return ResponseHelper::serverError($e);
        }
    }

    /**
     * POST /api/v1/login/mfa-verify
     */
    public function mfaVerify(Request $request)
    {
        $validated = $request->validate([
            'mfa_token' => ['required', 'string'],
            'code' => ['nullable', 'string', 'required_without:recovery_code'],
            'recovery_code' => ['nullable', 'string', 'required_without:code'],
        ]);

        try {
            $result = $this->mfaService->verifyLoginChallenge(
                $validated['mfa_token'],
                $validated['code'] ?? null,
                $validated['recovery_code'] ?? null,
                $request
            );

            $user = $result['user'];
            if (! $user->is_active || $user->trashed()) {
                $user->tokens()->delete();

                return ResponseHelper::jsonResponse(false, EnsureUserIsActive::DEACTIVATED_MESSAGE, null, 403);
            }
            if ($user->hasRole('employee')) {
                $user->load('employeeProfile');
            }
            $user->token = $user->createToken('auth_token')->plainTextToken;

            return ResponseHelper::jsonResponse(true, 'Login Successful', new UserResource($user), 200);
        } catch (MfaVerificationException $e) {
            return ResponseHelper::jsonResponse(false, $e->getMessage(), $e->mfaData ?: null, $e->getCode() ?: 422);
        } catch (\Throwable $e) {
            return ResponseHelper::serverError($e);
        }
    }

    public function me()
    {
        try {
            $user = $this->authRepository->me();

            return ResponseHelper::jsonResponse(true, 'Profile Retrieved Successfully', new UserResource($user), 200);
        } catch (\Exception $e) {
            return ResponseHelper::serverError($e);
        }
    }

    public function logout()
    {
        try {
            $user = $this->authRepository->logout();

            return ResponseHelper::jsonResponse(true, 'Logout Successful', new UserResource($user), 200);
        } catch (\Exception $e) {
            return ResponseHelper::serverError($e);
        }
    }

    public function updateProfile(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'password' => 'nullable|string|min:8|confirmed',
            'profile_photo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048', new \App\Rules\SafeImageFile],
        ]);

        try {
            $user = $this->authRepository->updateProfile([
                'name' => $request->input('name'),
                'password' => $request->input('password'),
                'profile_photo' => $request->file('profile_photo'),
            ]);

            return ResponseHelper::jsonResponse(true, 'Profile Updated Successfully', new UserResource($user), 200);
        } catch (\Illuminate\Database\QueryException|\PDOException $e) {
            return ResponseHelper::serverError($e);
        } catch (\Exception $e) {
            $code = (int) $e->getCode();
            if ($code >= 400 && $code < 500) {
                return ResponseHelper::jsonResponse(false, $e->getMessage(), null, $code);
            }

            return ResponseHelper::serverError($e);
        }
    }
}
