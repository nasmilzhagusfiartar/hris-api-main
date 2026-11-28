<?php

namespace App\Http\Controllers;

use App\Exceptions\BrandScanConflictException;
use App\Exceptions\UrlscanException;
use App\Helpers\ResponseHelper;
use App\Http\Requests\BrandScanRequest;
use App\Http\Resources\BrandScanResource;
use App\Http\Resources\BrandScanResultResource;
use App\Interfaces\BrandScanRepositoryInterface;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Spatie\Permission\Middleware\PermissionMiddleware;

class BrandScanController extends Controller implements HasMiddleware
{
    private BrandScanRepositoryInterface $brandScanRepository;

    public function __construct(BrandScanRepositoryInterface $brandScanRepository)
    {
        $this->brandScanRepository = $brandScanRepository;
    }

    public static function middleware()
    {
        return [
            new Middleware(PermissionMiddleware::using(['cti.brand-protection.view']), only: ['store', 'latest', 'show', 'submitUrlscan', 'getUrlscan']),
        ];
    }

    /**
     * Get the latest brand scan (optionally filtered by ?domain=...)
     */
    public function latest(\Illuminate\Http\Request $request)
    {
        try {
            $domain = $request->query('domain');
            $brandScan = $this->brandScanRepository->getLatestScan(is_string($domain) ? $domain : null);

            return ResponseHelper::jsonResponse(
                true,
                'Latest brand scan retrieved successfully',
                $brandScan ? new BrandScanResource($brandScan) : null,
                200
            );
        } catch (\Throwable $e) {
            return ResponseHelper::serverError($e);
        }
    }

    /**
     * Initiate a new brand scan (returns 202 without results)
     */
    public function store(BrandScanRequest $request)
    {
        try {
            $brandScan = $this->brandScanRepository->createScan($request->domain);

            return ResponseHelper::jsonResponse(
                true,
                'Brand scan initiated successfully',
                new BrandScanResource($brandScan),
                202
            );
        } catch (BrandScanConflictException $e) {
            return ResponseHelper::jsonResponse(
                false,
                $e->getMessage(),
                new BrandScanResource($e->brandScan),
                409
            );
        } catch (\Throwable $e) {
            return ResponseHelper::serverError($e);
        }
    }

    /**
     * Get brand scan details including results
     */
    public function show(string|int $id)
    {
        try {
            $brandScan = $this->brandScanRepository->getScanById($id);

            return ResponseHelper::jsonResponse(
                true,
                'Brand scan retrieved successfully',
                new BrandScanResource($brandScan),
                200
            );
        } catch (ModelNotFoundException $e) {
            return ResponseHelper::jsonResponse(
                false,
                'Brand Scan Not Found',
                null,
                404
            );
        } catch (\Throwable $e) {
            return ResponseHelper::serverError($e);
        }
    }

    /**
     * Submit brand scan result to urlscan.io (returns 202)
     */
    public function submitUrlscan(string|int $id)
    {
        try {
            $result = $this->brandScanRepository->submitUrlscan($id);

            return ResponseHelper::jsonResponse(
                true,
                'URLScan submitted successfully',
                new BrandScanResultResource($result),
                202
            );
        } catch (ModelNotFoundException $e) {
            return ResponseHelper::jsonResponse(
                false,
                'Brand Scan Result Not Found',
                null,
                404
            );
        } catch (UrlscanException $e) {
            $data = $e->result
                ? new BrandScanResultResource($e->result)
                : ($e->retryAfter !== null ? ['retry_after' => $e->retryAfter] : null);

            return ResponseHelper::jsonResponse(
                false,
                $e->getMessage(),
                $data,
                $e->httpStatus
            );
        } catch (\Throwable $e) {
            return ResponseHelper::serverError($e);
        }
    }

    /**
     * Retrieve or refresh urlscan.io scan results
     */
    public function getUrlscan(string|int $id)
    {
        try {
            $result = $this->brandScanRepository->getUrlscan($id);

            return ResponseHelper::jsonResponse(
                true,
                'URLScan result retrieved successfully',
                new BrandScanResultResource($result),
                200
            );
        } catch (ModelNotFoundException $e) {
            return ResponseHelper::jsonResponse(
                false,
                'Brand Scan Result Not Found',
                null,
                404
            );
        } catch (UrlscanException $e) {
            $data = $e->result
                ? new BrandScanResultResource($e->result)
                : ($e->retryAfter !== null ? ['retry_after' => $e->retryAfter] : null);

            return ResponseHelper::jsonResponse(
                false,
                $e->getMessage(),
                $data,
                $e->httpStatus
            );
        } catch (\Throwable $e) {
            return ResponseHelper::serverError($e);
        }
    }
}
