<?php

namespace App\Repositories;

use App\Interfaces\BrandScanRepositoryInterface;
use App\Jobs\RunBrandScanJob;
use App\Models\BrandScan;
use App\Models\BrandScanResult;
use App\Services\UrlscanService;

class BrandScanRepository implements BrandScanRepositoryInterface
{
    protected UrlscanService $urlscanService;

    public function __construct(UrlscanService $urlscanService)
    {
        $this->urlscanService = $urlscanService;
    }

    public function createScan(string $domain): BrandScan
    {
        $brandScan = BrandScan::create([
            'domain' => strtolower(trim($domain)),
            'status' => 'pending',
            'total_found' => 0,
        ]);

        RunBrandScanJob::dispatch($brandScan->id);

        return $brandScan;
    }

    public function getScanById(string|int $id): BrandScan
    {
        return BrandScan::with('results')->findOrFail($id);
    }

    public function submitUrlscan(string|int $resultId): BrandScanResult
    {
        $result = BrandScanResult::findOrFail($resultId);

        $submission = $this->urlscanService->submitScan($result->domain);

        $result->update([
            'urlscan_status' => 'pending',
            'urlscan_uuid' => $submission['uuid'],
            'urlscan_report_url' => $submission['report_url'],
            'urlscan_screenshot' => $submission['screenshot'],
        ]);

        return $result->fresh();
    }

    public function getUrlscan(string|int $resultId): BrandScanResult
    {
        $result = BrandScanResult::findOrFail($resultId);

        if (!$result->urlscan_uuid) {
            throw new \Exception('URLScan has not been submitted yet for this result');
        }

        $res = $this->urlscanService->fetchResult($result->urlscan_uuid);

        if (($res['status'] ?? '') === 'done') {
            $result->update([
                'urlscan_status' => 'done',
                'urlscan_ip' => $res['ip'] ?? null,
                'urlscan_country' => $res['country'] ?? null,
                'urlscan_malicious' => $res['malicious'] ?? false,
                'urlscan_score' => $res['score'] ?? 0,
            ]);
        } elseif (($res['status'] ?? '') === 'pending') {
            $result->update([
                'urlscan_status' => 'pending',
            ]);
        }

        return $result->fresh();
    }
}
