<?php

namespace App\Http\Controllers;

use App\Helpers\ResponseHelper;
use App\Http\Requests\PermissionStoreRequest;
use App\Http\Requests\PermissionUpdateRequest;
use App\Http\Resources\PaginateResource;
use App\Http\Resources\PermissionResource;
use App\Interfaces\PermissionRepositoryInterface;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Spatie\Permission\Middleware\RoleMiddleware;

class PermissionController extends Controller implements HasMiddleware
{
    public function __construct(
        private PermissionRepositoryInterface $permissionRepository
    ) {}

    public static function middleware(): array
    {
        return [
            new Middleware(RoleMiddleware::using('super-admin')),
        ];
    }

    /**
     * Display a listing of all permissions.
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $permissions = $this->permissionRepository->getAll(
                $request->search,
                $request->group,
                $request->limit,
                true
            );

            return ResponseHelper::jsonResponse(true, 'Permissions Retrieved Successfully', PermissionResource::collection($permissions), 200);
        } catch (\Throwable $e) {
            return ResponseHelper::jsonResponse(false, 'Internal Server Error: '.$e->getMessage(), null, 500);
        }
    }

    /**
     * Display permissions grouped by module/prefix for UI matrix/checkboxes.
     */
    public function getGrouped(): JsonResponse
    {
        try {
            $grouped = $this->permissionRepository->getAllGrouped();

            return ResponseHelper::jsonResponse(true, 'Grouped Permissions Retrieved Successfully', $grouped, 200);
        } catch (\Throwable $e) {
            return ResponseHelper::jsonResponse(false, 'Internal Server Error: '.$e->getMessage(), null, 500);
        }
    }

    /**
     * Display paginated listing of permissions.
     */
    public function getAllPaginated(Request $request): JsonResponse
    {
        try {
            $permissions = $this->permissionRepository->getAllPaginated(
                $request->search,
                $request->group,
                $request->row_per_page ?? 25
            );

            return ResponseHelper::jsonResponse(true, 'Permissions Retrieved Successfully', new PaginateResource($permissions, PermissionResource::class), 200);
        } catch (\Throwable $e) {
            return ResponseHelper::jsonResponse(false, 'Internal Server Error: '.$e->getMessage(), null, 500);
        }
    }

    /**
     * Store a newly created permission in storage.
     */
    public function store(PermissionStoreRequest $request): JsonResponse
    {
        try {
            $permission = $this->permissionRepository->create($request->validated());

            return ResponseHelper::jsonResponse(true, 'Permission Created Successfully', new PermissionResource($permission), 201);
        } catch (\Throwable $e) {
            return ResponseHelper::jsonResponse(false, 'Failed to create permission: '.$e->getMessage(), null, 500);
        }
    }

    /**
     * Display the specified permission.
     */
    public function show(string|int $id): JsonResponse
    {
        try {
            $permission = $this->permissionRepository->getById($id);

            return ResponseHelper::jsonResponse(true, 'Permission Retrieved Successfully', new PermissionResource($permission), 200);
        } catch (ModelNotFoundException $e) {
            return ResponseHelper::jsonResponse(false, 'Permission Not Found', null, 404);
        } catch (\Throwable $e) {
            return ResponseHelper::jsonResponse(false, 'Internal Server Error: '.$e->getMessage(), null, 500);
        }
    }

    /**
     * Update the specified permission in storage.
     */
    public function update(PermissionUpdateRequest $request, string|int $id): JsonResponse
    {
        try {
            $permission = $this->permissionRepository->update($id, $request->validated());

            return ResponseHelper::jsonResponse(true, 'Permission Updated Successfully', new PermissionResource($permission), 200);
        } catch (ModelNotFoundException $e) {
            return ResponseHelper::jsonResponse(false, 'Permission Not Found', null, 404);
        } catch (\Throwable $e) {
            return ResponseHelper::jsonResponse(false, 'Failed to update permission: '.$e->getMessage(), null, 500);
        }
    }

    /**
     * Remove the specified permission from storage.
     */
    public function destroy(string|int $id): JsonResponse
    {
        try {
            $this->permissionRepository->delete($id);

            return ResponseHelper::jsonResponse(true, 'Permission Deleted Successfully', null, 200);
        } catch (ModelNotFoundException $e) {
            return ResponseHelper::jsonResponse(false, 'Permission Not Found', null, 404);
        } catch (\Throwable $e) {
            return ResponseHelper::jsonResponse(false, 'Failed to delete permission: '.$e->getMessage(), null, 400);
        }
    }
}
