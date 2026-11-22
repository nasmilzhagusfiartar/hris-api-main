<?php

namespace App\Http\Controllers;

use App\Helpers\ResponseHelper;
use App\Http\Requests\StoreStoreRequest;
use App\Http\Requests\StoreUpdateRequest;
use App\Http\Resources\PaginateResource;
use App\Http\Resources\StoreResource;
use App\Interfaces\StoreRepositoryInterface;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Symfony\Component\HttpKernel\Exception\HttpException;

class StoreController extends Controller implements HasMiddleware
{
    public function __construct(
        private StoreRepositoryInterface $storeRepository
    ) {}

    public static function middleware(): array
    {
        return [
            new Middleware(PermissionMiddleware::using(['store-list|store-create|store-edit|store-delete']), only: ['index', 'getAllPaginated', 'show']),
            new Middleware(PermissionMiddleware::using(['store-create']), only: ['store']),
            new Middleware(PermissionMiddleware::using(['store-edit']), only: ['update']),
            new Middleware(PermissionMiddleware::using(['store-delete']), only: ['destroy']),
        ];
    }

    public function index(Request $request): JsonResponse
    {
        try {
            $isActive = $request->has('is_active') && $request->is_active !== '' && $request->is_active !== null
                ? filter_var($request->is_active, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)
                : null;

            $stores = $this->storeRepository->getAll(
                $request->search,
                $request->brand,
                $request->city,
                $isActive,
                $request->limit ? (int) $request->limit : null,
                true
            );

            return ResponseHelper::jsonResponse(true, 'Stores Retrieved Successfully', StoreResource::collection($stores), 200);
        } catch (\Throwable $e) {
            return ResponseHelper::serverError($e);
        }
    }

    public function getAllPaginated(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'search' => 'nullable|string',
            'brand' => 'nullable|string',
            'city' => 'nullable|string',
            'is_active' => 'nullable',
            'row_per_page' => 'nullable|integer|min:1|max:100',
        ]);

        $isActive = array_key_exists('is_active', $validated) && $validated['is_active'] !== '' && $validated['is_active'] !== null
            ? filter_var($validated['is_active'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)
            : null;

        try {
            $stores = $this->storeRepository->getAllPaginated(
                $validated['search'] ?? null,
                $validated['brand'] ?? null,
                $validated['city'] ?? null,
                $isActive,
                (int) ($validated['row_per_page'] ?? 15)
            );

            return ResponseHelper::jsonResponse(true, 'Stores Retrieved Successfully', PaginateResource::make($stores, StoreResource::class), 200);
        } catch (\Throwable $e) {
            return ResponseHelper::serverError($e);
        }
    }

    public function store(StoreStoreRequest $request): JsonResponse
    {
        try {
            $store = $this->storeRepository->create($request->validated());

            return ResponseHelper::jsonResponse(true, 'Store Created Successfully', new StoreResource($store), 201);
        } catch (\Throwable $e) {
            return ResponseHelper::serverError($e);
        }
    }

    public function show(string|int $id): JsonResponse
    {
        try {
            $store = $this->storeRepository->getById($id);

            return ResponseHelper::jsonResponse(true, 'Store Retrieved Successfully', new StoreResource($store), 200);
        } catch (ModelNotFoundException $e) {
            return ResponseHelper::jsonResponse(false, 'Store Not Found', null, 404);
        } catch (HttpException $e) {
            return ResponseHelper::jsonResponse(false, $e->getMessage(), null, $e->getStatusCode());
        } catch (\Throwable $e) {
            return ResponseHelper::serverError($e);
        }
    }

    public function update(StoreUpdateRequest $request, string|int $id): JsonResponse
    {
        try {
            $store = $this->storeRepository->update($id, $request->validated());

            return ResponseHelper::jsonResponse(true, 'Store Updated Successfully', new StoreResource($store), 200);
        } catch (ModelNotFoundException $e) {
            return ResponseHelper::jsonResponse(false, 'Store Not Found', null, 404);
        } catch (HttpException $e) {
            return ResponseHelper::jsonResponse(false, $e->getMessage(), null, $e->getStatusCode());
        } catch (\Throwable $e) {
            return ResponseHelper::serverError($e);
        }
    }

    public function destroy(string|int $id): JsonResponse
    {
        try {
            $this->storeRepository->delete($id);

            return ResponseHelper::jsonResponse(true, 'Store Deleted Successfully', null, 200);
        } catch (ModelNotFoundException $e) {
            return ResponseHelper::jsonResponse(false, 'Store Not Found', null, 404);
        } catch (HttpException $e) {
            return ResponseHelper::jsonResponse(false, $e->getMessage(), null, $e->getStatusCode());
        } catch (\Throwable $e) {
            return ResponseHelper::serverError($e);
        }
    }
}
