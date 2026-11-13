<?php

namespace App\Interfaces;

use App\Models\BrandScan;
use App\Models\BrandScanResult;

interface BrandScanRepositoryInterface
{
    public function createScan(string $domain): BrandScan;

    public function getScanById(string|int $id): BrandScan;

    public function submitUrlscan(string|int $resultId): BrandScanResult;

    public function getUrlscan(string|int $resultId): BrandScanResult;
}
