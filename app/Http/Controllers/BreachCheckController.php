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
            new Middleware(PermissionMiddleware::using(['cti-breach-check']), only: ['check', 'checkAll', 'getAllPaginated']),
        ];
    }

    /**
     * Check single email for breaches
     */
    public function check(BreachCheckRequest $request)
    {
        try {
            $breachCheck = $this->breachCheckRepository->checkEmail($request->email);

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
            $breachChecks = $this->breachCheckRepository->getAllPaginated(
                $validated['search'] ?? null,
                $validated['row_per_page'] ?? 10
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
