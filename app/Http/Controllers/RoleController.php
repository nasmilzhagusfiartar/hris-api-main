<?php

namespace App\Http\Controllers;

use App\Helpers\ResponseHelper;
use App\Http\Requests\RoleAssignPermissionRequest;
use App\Http\Requests\RoleStoreRequest;
use App\Http\Requests\RoleUpdateRequest;
use App\Http\Resources\PaginateResource;
use App\Http\Resources\RoleResource;
use App\Interfaces\RoleRepositoryInterface;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Spatie\Permission\Middleware\RoleMiddleware;
use Symfony\Component\HttpKernel\Exception\HttpException;

class RoleController extends Controller implements HasMiddleware
{
    public function __construct(
        private RoleRepositoryInterface $roleRepository
    ) {}

    public static function middleware(): array
    {
        return [
            new Middleware(RoleMiddleware::using('super-admin')),
        ];
    }

    /**
     * Display a listing of roles.
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $roles = $this->roleRepository->getAll(
                $request->search,
                $request->limit,
                true
            );

            return ResponseHelper::jsonResponse(true, 'Roles Retrieved Successfully', RoleResource::collection($roles), 200);
        } catch (\Throwable $e) {
            return ResponseHelper::serverError($e);
        }
    }

    /**
     * Display paginated listing of roles.
     */
    public function getAllPaginated(Request $request): JsonResponse
    {
        try {
            $roles = $this->roleRepository->getAllPaginated(
                $request->search,
                $request->row_per_page ?? 15
            );

            return ResponseHelper::jsonResponse(true, 'Roles Retrieved Successfully', new PaginateResource($roles, RoleResource::class), 200);
        } catch (\Throwable $e) {
            return ResponseHelper::serverError($e);
        }
    }

    /**
     * Store a newly created role in storage.
     */
    public function store(RoleStoreRequest $request): JsonResponse
    {
        try {
            $role = $this->roleRepository->create($request->validated());

            return ResponseHelper::jsonResponse(true, 'Role Created Successfully', new RoleResource($role), 201);
        } catch (\Throwable $e) {
            return ResponseHelper::serverError($e);
        }
    }

    /**
     * Display the specified role.
     */
    public function show(string|int $id): JsonResponse
    {
        try {
            $role = $this->roleRepository->getById($id);

            return ResponseHelper::jsonResponse(true, 'Role Retrieved Successfully', new RoleResource($role), 200);
        } catch (ModelNotFoundException $e) {
            return ResponseHelper::jsonResponse(false, 'Role Not Found', null, 404);
        } catch (\Throwable $e) {
            return ResponseHelper::serverError($e);
        }
    }

    /**
     * Update the specified role in storage.
     */
    public function update(RoleUpdateRequest $request, string|int $id): JsonResponse
    {
        try {
            $role = $this->roleRepository->update($id, $request->validated());

            return ResponseHelper::jsonResponse(true, 'Role Updated Successfully', new RoleResource($role), 200);
        } catch (ModelNotFoundException $e) {
            return ResponseHelper::jsonResponse(false, 'Role Not Found', null, 404);
        } catch (HttpException $e) {
            return ResponseHelper::jsonResponse(false, $e->getMessage(), null, $e->getStatusCode());
        } catch (\Throwable $e) {
            return ResponseHelper::serverError($e);
        }
    }

    /**
     * Remove the specified role from storage.
     */
    public function destroy(string|int $id): JsonResponse
    {
        try {
            $this->roleRepository->delete($id);

            return ResponseHelper::jsonResponse(true, 'Role Deleted Successfully', null, 200);
        } catch (ModelNotFoundException $e) {
            return ResponseHelper::jsonResponse(false, 'Role Not Found', null, 404);
        } catch (HttpException $e) {
            return ResponseHelper::jsonResponse(false, $e->getMessage(), null, $e->getStatusCode());
        } catch (\Throwable $e) {
            return ResponseHelper::serverError($e);
        }
    }

    /**
     * Assign or sync permissions to a role.
     */
    public function assignPermissions(RoleAssignPermissionRequest $request, string|int $id): JsonResponse
    {
        try {
            $role = $this->roleRepository->syncPermissions($id, $request->permissions);

            return ResponseHelper::jsonResponse(true, 'Permissions Assigned Successfully', new RoleResource($role), 200);
        } catch (ModelNotFoundException $e) {
            return ResponseHelper::jsonResponse(false, 'Role Not Found', null, 404);
        } catch (HttpException $e) {
            return ResponseHelper::jsonResponse(false, $e->getMessage(), null, $e->getStatusCode());
        } catch (\Throwable $e) {
            return ResponseHelper::serverError($e);
        }
    }
}
