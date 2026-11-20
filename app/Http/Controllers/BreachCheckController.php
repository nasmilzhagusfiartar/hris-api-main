<?php

namespace App\Http\Controllers;

use App\Helpers\ResponseHelper;
use App\Http\Requests\BreachCheckRequest;
use App\Http\Resources\BreachCheckResource;
use App\Http\Resources\PaginateResource;
use App\Interfaces\BreachCheckRepositoryInterface;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Spatie\Permission\Middleware\PermissionMiddleware;

class BreachCheckController extends Controller implements HasMiddleware
{
    private BreachCheckRepositoryInterface $breachCheckRepository;

    public function __construct(BreachCheckRepositoryInterface $breachCheckRepository)
    {
        $this->breachCheckRepository = $breachCheckRepository;
    }

    public static function middleware()
    {
        return [
            new Middleware(
                PermissionMiddleware::using(['cti.email-breach.check-any|cti.email-breach.check-own']),
                only: ['check', 'getAllPaginated']
            ),
            new Middleware(
                PermissionMiddleware::using(['cti.email-breach.check-any']),
                only: ['checkAll']
            ),
        ];
    }

    /**
     * Check single email for breaches
     */
    public function check(BreachCheckRequest $request)
    {
        try {
            $user = $request->user();
            $targetEmail = strtolower(trim((string) $request->email));

            if (! $user->can('cti.email-breach.check-any')) {
                if (strcasecmp($targetEmail, trim((string) $user->email)) !== 0) {
                    return ResponseHelper::jsonResponse(
                        false,
                        'You only have permission to check data breaches for your own email account.',
                        null,
                        403
                    );
                }
            }

            $breachCheck = $this->breachCheckRepository->checkEmail($targetEmail);

            return ResponseHelper::jsonResponse(
                true,
                'Breach check completed successfully',
                new BreachCheckResource($breachCheck),
                200
            );
        } catch (\Throwable $e) {
            return ResponseHelper::jsonResponse(
                false,
                'Internal Server Error: ' . $e->getMessage(),
                null,
                500
            );
        }
    }

    /**
     * Trigger queue job to check all users for breaches
     */
    public function checkAll()
    {
        try {
            $queuedCount = $this->breachCheckRepository->checkAll();

            return ResponseHelper::jsonResponse(
                true,
                'Breach check queued for all users',
                ['queued' => $queuedCount],
                202
            );
        } catch (\Throwable $e) {
            return ResponseHelper::jsonResponse(
                false,
                'Internal Server Error: ' . $e->getMessage(),
                null,
                500
            );
        }
    }

    /**
     * Get all breach checks with pagination
     */
    public function getAllPaginated(Request $request)
    {
        $validated = $request->validate([
            'search' => 'nullable|string',
            'row_per_page' => 'nullable|integer',
            'page' => 'nullable|integer',
        ]);

        try {
            $user = $request->user();
            $onlyEmail = $user->can('cti.email-breach.check-any') ? null : $user->email;

            $breachChecks = $this->breachCheckRepository->getAllPaginated(
                $validated['search'] ?? null,
                $validated['row_per_page'] ?? 10,
                $onlyEmail
            );

            return ResponseHelper::jsonResponse(
                true,
                'Breach checks retrieved successfully',
                PaginateResource::make($breachChecks, BreachCheckResource::class),
                200
            );
        } catch (\Throwable $e) {
            return ResponseHelper::jsonResponse(
                false,
                'Internal Server Error: ' . $e->getMessage(),
                null,
                500
            );
        }
    }
}
